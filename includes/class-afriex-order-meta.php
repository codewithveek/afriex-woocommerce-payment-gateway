<?php
/**
 * HPOS-safe order meta read/write helpers.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single place in the plugin that touches order meta.
 *
 * High-Performance Order Storage moves orders out of wp_posts/wp_postmeta into
 * dedicated tables. get_post_meta()/update_post_meta() on an order id is not
 * HPOS compatible, is flagged in the WooCommerce admin, and blocks merchants
 * from enabling HPOS at all. Everything here goes through the CRUD API instead,
 * so that discipline only has to hold in one file.
 */
class Afriex_Order_Meta {

	public const KEY_PAYMENT_METHOD_ID = '_afriex_payment_method_id';
	public const KEY_CUSTOMER_ID       = '_afriex_customer_id';
	public const KEY_ACCOUNT_NUMBER    = '_afriex_account_number';
	public const KEY_ACCOUNT_NAME      = '_afriex_account_name';
	public const KEY_BANK_NAME         = '_afriex_bank_name';
	public const KEY_REFERENCE         = '_afriex_reference';
	public const KEY_TRANSACTION_ID    = '_afriex_transaction_id';
	public const KEY_LAST_STATUS       = '_afriex_last_status';
	public const KEY_EXPIRES_AT        = '_afriex_expires_at';
	public const KEY_COLLECTION_METHOD = '_afriex_collection_method';

	/**
	 * Persist Afriex payment details against the order.
	 *
	 * @param WC_Order $order             Order being paid.
	 * @param array    $account_data      Payment method payload from Afriex.
	 * @param string   $customer_id       Afriex customer id.
	 * @param string   $collection_method Either 'dedicated' or 'pool'.
	 */
	public static function store_payment_details(
		WC_Order $order,
		array $account_data,
		string $customer_id,
		string $collection_method = 'dedicated'
	): void {
		$order->update_meta_data( self::KEY_PAYMENT_METHOD_ID, self::extract_payment_method_id( $account_data ) );
		$order->update_meta_data( self::KEY_CUSTOMER_ID, $customer_id );
		$order->update_meta_data( self::KEY_ACCOUNT_NUMBER, (string) ( $account_data['accountNumber'] ?? '' ) );
		$order->update_meta_data( self::KEY_ACCOUNT_NAME, (string) ( $account_data['accountName'] ?? '' ) );
		$order->update_meta_data( self::KEY_BANK_NAME, (string) ( $account_data['institution']['institutionName'] ?? '' ) );
		$order->update_meta_data( self::KEY_REFERENCE, (string) ( $account_data['reference'] ?? $order->get_order_number() ) );
		$order->update_meta_data( self::KEY_LAST_STATUS, 'PENDING' );
		$order->update_meta_data( self::KEY_COLLECTION_METHOD, $collection_method );

		if ( ! empty( $account_data['expiresAt'] ) ) {
			$order->update_meta_data( self::KEY_EXPIRES_AT, (string) $account_data['expiresAt'] );
		}

		$order->save();
	}

	/**
	 * Afriex returns the payment method id under more than one key depending on
	 * whether the account was created or fetched. Accept either, so the webhook
	 * lookup does not depend on which endpoint produced the account.
	 *
	 * @param array $account_data Payment method payload.
	 */
	public static function extract_payment_method_id( array $account_data ): string {
		foreach ( array( 'paymentMethodId', 'id', '_id' ) as $key ) {
			if ( ! empty( $account_data[ $key ] ) && is_scalar( $account_data[ $key ] ) ) {
				return (string) $account_data[ $key ];
			}
		}

		return '';
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function get_payment_method_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_PAYMENT_METHOD_ID );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function get_customer_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_CUSTOMER_ID );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function get_reference( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_REFERENCE );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function get_transaction_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_TRANSACTION_ID );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function get_last_status( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_LAST_STATUS );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function get_collection_method( WC_Order $order ): string {
		$method = (string) $order->get_meta( self::KEY_COLLECTION_METHOD );

		return '' !== $method ? $method : 'dedicated';
	}

	/**
	 * Expiry timestamp of a dynamic virtual account, or 0 when open-ended.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_expires_at( WC_Order $order ): int {
		$raw = (string) $order->get_meta( self::KEY_EXPIRES_AT );

		if ( '' === $raw ) {
			return 0;
		}

		$timestamp = is_numeric( $raw ) ? (int) $raw : strtotime( $raw );

		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param string   $status Afriex transaction status.
	 */
	public static function record_status( WC_Order $order, string $status ): void {
		$order->update_meta_data( self::KEY_LAST_STATUS, $status );
		$order->save();
	}

	/**
	 * @param WC_Order $order          Order.
	 * @param string   $transaction_id Afriex transaction id.
	 */
	public static function record_transaction_id( WC_Order $order, string $transaction_id ): void {
		if ( '' === $transaction_id ) {
			return;
		}

		$order->update_meta_data( self::KEY_TRANSACTION_ID, $transaction_id );
		$order->save();
	}

	/**
	 * The account details shown to the customer, as label => value pairs.
	 *
	 * Returned raw: callers escape at the point of output.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	public static function get_payment_instructions( WC_Order $order ): array {
		$rows = array();

		$bank_name = (string) $order->get_meta( self::KEY_BANK_NAME );
		if ( '' !== $bank_name ) {
			$rows[ __( 'Bank', 'afriex-gateway-for-woocommerce' ) ] = $bank_name;
		}

		$account_name = (string) $order->get_meta( self::KEY_ACCOUNT_NAME );
		if ( '' !== $account_name ) {
			$rows[ __( 'Account name', 'afriex-gateway-for-woocommerce' ) ] = $account_name;
		}

		$account_number = (string) $order->get_meta( self::KEY_ACCOUNT_NUMBER );
		if ( '' !== $account_number ) {
			$rows[ __( 'Account number', 'afriex-gateway-for-woocommerce' ) ] = $account_number;
		}

		// A dedicated account is scoped to this order, so no reference is needed.
		// A pool account is shared, and the reference is the only thing tying the
		// deposit back to this order — so it is always shown for pool.
		if ( 'pool' === self::get_collection_method( $order ) ) {
			$reference = self::get_reference( $order );
			if ( '' !== $reference ) {
				$rows[ __( 'Payment reference', 'afriex-gateway-for-woocommerce' ) ] = $reference;
			}
		}

		$rows[ __( 'Amount', 'afriex-gateway-for-woocommerce' ) ] = self::format_amount(
			(float) $order->get_total(),
			$order->get_currency()
		);

		return $rows;
	}

	/**
	 * A formatted amount as plain text, safe to hand to esc_html().
	 *
	 * wc_price() returns markup containing entities such as &nbsp; in the
	 * currency separator. Stripping the tags alone leaves those entities in
	 * place, and escaping the result would then render a literal "&nbsp;" to
	 * the customer. Decoding after stripping is what makes the string genuinely
	 * plain text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 */
	public static function format_amount( float $amount, string $currency ): string {
		$formatted = wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) );

		return html_entity_decode( $formatted, ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * Find the order matching an Afriex payment method id.
	 *
	 * wc_get_orders() is the HPOS-safe way to query orders by meta. A direct
	 * WP_Query against post_type => 'shop_order' returns nothing once HPOS is on.
	 *
	 * @param string $payment_method_id Afriex payment method id.
	 */
	public static function find_order_by_payment_method_id( string $payment_method_id ): ?WC_Order {
		if ( '' === $payment_method_id ) {
			return null;
		}

		return self::find_order_by_meta( self::KEY_PAYMENT_METHOD_ID, $payment_method_id );
	}

	/**
	 * Fallback lookup for pool collection, where a deposit is matched by the
	 * reference the customer typed rather than by a per-order account.
	 *
	 * @param string $reference Payment reference.
	 */
	public static function find_order_by_reference( string $reference ): ?WC_Order {
		if ( '' === $reference ) {
			return null;
		}

		return self::find_order_by_meta( self::KEY_REFERENCE, $reference );
	}

	/**
	 * @param string $meta_key   Meta key to match.
	 * @param string $meta_value Meta value to match.
	 */
	private static function find_order_by_meta( string $meta_key, string $meta_value ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'status'     => 'any',
				'meta_key'   => $meta_key,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( empty( $orders ) || ! $orders[0] instanceof WC_Order ) {
			return null;
		}

		return $orders[0];
	}
}
