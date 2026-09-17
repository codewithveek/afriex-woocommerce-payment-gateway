<?php
/**
 * Order reconciliation: the one place an Afriex transaction changes an order.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies Afriex transaction state to a WooCommerce order.
 *
 * Both entry points — the webhook (fast path) and the scheduled sweep
 * (backstop) — funnel through apply_transaction(), so a payment is settled the
 * same way regardless of which one saw it first. That matters because the sweep
 * exists precisely for the cases the webhook missed; if the two diverged, the
 * backstop would be settling orders on rules nobody tested.
 */
class Afriex_Reconciler {

	/**
	 * How long an order must have been on-hold before the sweep looks at it.
	 * Short enough to catch a lost webhook the same day, long enough not to
	 * race a webhook that is merely in flight.
	 */
	private const SWEEP_MIN_AGE = HOUR_IN_SECONDS;

	/**
	 * Orders examined per sweep run.
	 */
	private const SWEEP_BATCH_SIZE = 50;

	/**
	 * Stop sweeping an order after this long. Past it the deposit is not
	 * coming, and an unbounded sweep would query Afriex for dead orders forever.
	 */
	private const SWEEP_MAX_AGE = 14 * DAY_IN_SECONDS;

	/**
	 * Tolerance when comparing the deposited amount to the order total.
	 */
	private const AMOUNT_TOLERANCE = 0.01;

	/**
	 * The scheduled backstop.
	 *
	 * WC_Order::needs_payment() returns true only for pending and failed orders.
	 * An Afriex order sits at on-hold from the moment it is created, so it
	 * reports needs_payment() === false immediately, and any WooCommerce logic
	 * gated behind that check silently never runs against it. Nothing in core
	 * will ever come back around to an unpaid Afriex order. This will.
	 */
	public function sweep(): void {
		$orders = wc_get_orders(
			array(
				'limit'          => self::SWEEP_BATCH_SIZE,
				'status'         => array( 'on-hold' ),
				'payment_method' => AFRIEX_WC_GATEWAY_ID,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'date_created'   => ( time() - self::SWEEP_MAX_AGE ) . '...' . ( time() - self::SWEEP_MIN_AGE ),
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		Afriex_Logger::info( 'Reconciliation sweep started', array( 'orders' => count( $orders ) ) );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$this->reconcile( $order );
		}
	}

	/**
	 * Ask Afriex directly what happened to one order, and apply the answer.
	 *
	 * @param WC_Order $order Order to reconcile.
	 */
	public function reconcile( WC_Order $order ): void {
		$payment_method_id = Afriex_Order_Meta::get_payment_method_id( $order );

		if ( '' === $payment_method_id ) {
			return;
		}

		$client = $this->build_api_client();

		if ( ! $client->is_configured() ) {
			return;
		}

		$transaction_id = Afriex_Order_Meta::get_transaction_id( $order );

		// If a webhook already told us which transaction this is, ask about that
		// one. Otherwise look for any deposit settled against the account the
		// customer was told to pay into.
		if ( '' !== $transaction_id ) {
			$transaction = $client->get_transaction( $transaction_id );

			if ( is_wp_error( $transaction ) ) {
				return;
			}

			$this->apply_transaction( $order, $transaction, 'sweep' );
			return;
		}

		$transactions = $client->get_transactions_for_payment_method( $payment_method_id );

		if ( is_wp_error( $transactions ) ) {
			return;
		}

		if ( empty( $transactions ) ) {
			$this->handle_missing_deposit( $order );
			return;
		}

		foreach ( $transactions as $transaction ) {
			if ( is_array( $transaction ) ) {
				$this->apply_transaction( $order, $transaction, 'sweep' );
			}
		}
	}

	/**
	 * Apply one Afriex transaction payload to an order.
	 *
	 * @param WC_Order $order            Order the transaction belongs to.
	 * @param array    $transaction_data Transaction payload from Afriex.
	 * @param string   $source           'webhook' or 'sweep', for the log trail.
	 */
	public function apply_transaction( WC_Order $order, array $transaction_data, string $source = 'webhook' ): void {
		$afriex_status = (string) ( $transaction_data['status'] ?? 'UNKNOWN' );
		$action        = Afriex_Status_Mapper::resolve_action( $afriex_status );

		Afriex_Logger::info(
			'Applying Afriex transaction',
			array(
				'order_id' => $order->get_id(),
				'status'   => $afriex_status,
				'action'   => $action,
				'source'   => $source,
			)
		);

		Afriex_Order_Meta::record_status( $order, $afriex_status );
		Afriex_Order_Meta::record_transaction_id( $order, (string) ( $transaction_data['transactionId'] ?? $transaction_data['id'] ?? '' ) );

		// An order that is already paid must not be walked back by a later or
		// out-of-order delivery of an earlier event.
		if ( $order->is_paid() && Afriex_Status_Mapper::ACTION_COMPLETE !== $action ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Afriex transaction status */
					__( 'Afriex reported status %s after this order was already paid. No status change was made.', 'afriex-gateway-for-woocommerce' ),
					$afriex_status
				)
			);
			return;
		}

		switch ( $action ) {
			case Afriex_Status_Mapper::ACTION_COMPLETE:
				$this->handle_successful_payment( $order, $transaction_data );
				break;

			case Afriex_Status_Mapper::ACTION_FAIL:
				$this->update_status_once(
					$order,
					'failed',
					sprintf(
						/* translators: %s: Afriex transaction status */
						__( 'Afriex reported payment %s.', 'afriex-gateway-for-woocommerce' ),
						$afriex_status
					)
				);
				break;

			case Afriex_Status_Mapper::ACTION_CANCEL:
				$this->update_status_once(
					$order,
					'cancelled',
					__( 'Afriex reported the payment was cancelled.', 'afriex-gateway-for-woocommerce' )
				);
				break;

			case Afriex_Status_Mapper::ACTION_REFUND:
				// Recorded as a note rather than a status change: WooCommerce
				// treats refunded as a money movement with its own records, and
				// inventing one from a webhook would put the store's refund
				// totals out of step with what actually happened.
				$order->add_order_note(
					__( 'Afriex reported this payment as refunded. Record the refund in WooCommerce if the money has left your account.', 'afriex-gateway-for-woocommerce' )
				);
				break;

			case Afriex_Status_Mapper::ACTION_FLAG_FOR_REVIEW:
				$order->add_order_note(
					sprintf(
						/* translators: %s: Afriex transaction status */
						__( 'Afriex reported an unrecognised payment status: %s. Manual review required.', 'afriex-gateway-for-woocommerce' ),
						$afriex_status
					)
				);
				Afriex_Logger::warning(
					'Unrecognised Afriex status',
					array(
						'order_id' => $order->get_id(),
						'status'   => $afriex_status,
					)
				);
				break;

			case Afriex_Status_Mapper::ACTION_NOTE_ONLY:
			default:
				// IN_REVIEW, RETRY, PROCESSING and friends are not failures and
				// must not move the order out of on-hold.
				$order->add_order_note(
					sprintf(
						/* translators: %s: Afriex transaction status */
						__( 'Afriex payment status: %s.', 'afriex-gateway-for-woocommerce' ),
						$afriex_status
					)
				);
				break;
		}
	}

	/**
	 * @param WC_Order $order            Order.
	 * @param array    $transaction_data Transaction payload from Afriex.
	 */
	private function handle_successful_payment( WC_Order $order, array $transaction_data ): void {
		if ( $order->is_paid() ) {
			return;
		}

		$received_amount = (float) ( $transaction_data['destinationAmount'] ?? $transaction_data['amount'] ?? 0 );
		$expected_amount = (float) $order->get_total();

		// Never auto-complete on a mismatch. Flag it and stop: an underpayment
		// silently fulfilled is a loss, and an overpayment silently kept is a
		// dispute.
		if ( abs( $received_amount - $expected_amount ) > self::AMOUNT_TOLERANCE ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: received amount, 2: expected amount */
					__( 'Afriex payment amount mismatch. Received %1$s, expected %2$s. Manual review required.', 'afriex-gateway-for-woocommerce' ),
					Afriex_Order_Meta::format_amount( $received_amount, $order->get_currency() ),
					Afriex_Order_Meta::format_amount( $expected_amount, $order->get_currency() )
				)
			);

			Afriex_Logger::warning(
				'Amount mismatch',
				array(
					'order_id' => $order->get_id(),
					'received' => $received_amount,
					'expected' => $expected_amount,
				)
			);

			return;
		}

		// payment_complete() rather than update_status('processing'): it records
		// the transaction id, sets the paid date, fires woocommerce_payment_complete
		// and picks processing or completed based on whether the order needs
		// shipping. Setting the status directly skips all of that.
		$order->payment_complete( (string) ( $transaction_data['transactionId'] ?? $transaction_data['id'] ?? '' ) );
		$order->add_order_note( __( 'Afriex payment confirmed.', 'afriex-gateway-for-woocommerce' ) );
	}

	/**
	 * No deposit has landed against this order's account.
	 *
	 * Expiry is decided here from the account's own expiry window, never
	 * inferred from WooCommerce's needs_payment() heuristics, which report
	 * false for on-hold orders and would leave this order stranded.
	 *
	 * @param WC_Order $order Order.
	 */
	private function handle_missing_deposit( WC_Order $order ): void {
		$expires_at = Afriex_Order_Meta::get_expires_at( $order );

		if ( 0 === $expires_at || $expires_at > time() ) {
			// Still within the window the customer was given. Nothing to do.
			return;
		}

		$this->update_status_once(
			$order,
			'cancelled',
			__( 'The Afriex virtual account for this order expired before a payment arrived.', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Change status only when it is actually a change, so a repeated sweep does
	 * not stack identical notes on the same order.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Target WooCommerce status, without the wc- prefix.
	 * @param string   $note   Order note.
	 */
	private function update_status_once( WC_Order $order, string $status, string $note ): void {
		if ( $order->has_status( $status ) ) {
			return;
		}

		$order->update_status( $status, $note );
	}

	private function build_api_client(): Afriex_Api_Client {
		$settings = get_option( 'woocommerce_' . AFRIEX_WC_GATEWAY_ID . '_settings', array() );

		return new Afriex_Api_Client(
			array(
				'api_key'     => (string) ( $settings['api_key'] ?? '' ),
				'environment' => (string) ( $settings['environment'] ?? 'staging' ),
			)
		);
	}
}
