<?php
/**
 * Webhook REST route, RSA signature verification and dispatch.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives Afriex webhook deliveries.
 *
 * Verification happens in the route's permission_callback, so a forged or
 * tampered request is rejected by WordPress before the callback runs — no order
 * lookup, no database write, no side effects of any kind.
 */
class Afriex_Webhook_Handler {

	private const REST_NAMESPACE = 'afriex/v1';
	private const REST_ROUTE     = '/webhook';

	private const SIGNATURE_HEADER = 'x-webhook-signature';

	/**
	 * Event keys are kept for a week — comfortably beyond Afriex's retry
	 * window of up to 12 attempts over roughly 16 hours.
	 */
	private const EVENT_KEY_TTL = WEEK_IN_SECONDS;

	/**
	 * Events this handler acts on. Anything else is acknowledged and ignored,
	 * so Afriex does not retry a delivery the plugin has no use for.
	 */
	private const HANDLED_EVENTS = array(
		'TRANSACTION.CREATED',
		'TRANSACTION.UPDATED',
		'TRANSACTION.COMPLETED',
		'TRANSACTION.FAILED',
	);

	public function register_routes_hook(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				// Signature verification IS the permission check.
				// A forged request never reaches the callback.
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);
	}

	/**
	 * Verify the RSA-SHA256 signature against the raw request body.
	 *
	 * Afriex signs with its private key; the plugin verifies with the public key
	 * from the merchant's Afriex dashboard. Note this is RSA, not HMAC — there
	 * is no shared secret and nothing here can forge a signature.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function verify_signature( WP_REST_Request $request ): bool {
		if ( ! function_exists( 'openssl_verify' ) ) {
			Afriex_Logger::error( 'Webhook rejected: the PHP openssl extension is not available' );
			return false;
		}

		$signature = (string) $request->get_header( self::SIGNATURE_HEADER );

		if ( '' === $signature ) {
			Afriex_Logger::warning( 'Webhook rejected: missing signature header' );
			return false;
		}

		$public_key = $this->get_public_key();

		if ( '' === $public_key ) {
			Afriex_Logger::error( 'Webhook rejected: no public key configured' );
			return false;
		}

		$decoded_signature = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a binary signature, not obfuscating code.

		if ( false === $decoded_signature || '' === $decoded_signature ) {
			Afriex_Logger::warning( 'Webhook rejected: signature was not valid base64' );
			return false;
		}

		// Must verify against the raw body, not a re-encoded parsed payload:
		// json_decode() followed by wp_json_encode() will not reproduce the
		// exact bytes Afriex signed.
		$raw_body = $request->get_body();

		if ( '' === $raw_body ) {
			Afriex_Logger::warning( 'Webhook rejected: empty body' );
			return false;
		}

		$verification_result = openssl_verify(
			$raw_body,
			$decoded_signature,
			$public_key,
			OPENSSL_ALGO_SHA256
		);

		if ( 1 !== $verification_result ) {
			Afriex_Logger::warning( 'Webhook rejected: signature verification failed' );
			return false;
		}

		return true;
	}

	/**
	 * Handle a verified delivery.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$payload = json_decode( $request->get_body(), true );

		if ( ! is_array( $payload ) || ! isset( $payload['event'], $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Malformed payload' ), 400 );
		}

		$event = (string) $payload['event'];

		if ( ! in_array( $event, self::HANDLED_EVENTS, true ) ) {
			Afriex_Logger::debug( 'Webhook event ignored', array( 'event' => $event ) );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$event_id = $this->build_event_id( $payload );

		// Claim the event before doing the work, not after: Afriex retries up to
		// 12 times and two deliveries can be in flight at once. Claiming first
		// closes the window where both copies would pass the check.
		//
		// The claim is a fast-path dedupe, not the correctness guarantee — that
		// comes from the state checks in Afriex_Reconciler, which refuse to
		// re-settle an order that is already paid or already in the target
		// status. So a claim lost to a race costs a duplicate note at worst.
		if ( ! $this->claim_event( $event_id ) ) {
			Afriex_Logger::debug( 'Duplicate webhook acknowledged', array( 'event' => $event ) );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$this->reconcile_order( $payload['data'] );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * A stable key for one logical event.
	 *
	 * Includes updatedAt so a genuine status change on the same transaction is
	 * treated as a new event rather than swallowed as a duplicate.
	 *
	 * @param array $payload Decoded webhook payload.
	 */
	private function build_event_id( array $payload ): string {
		$data = $payload['data'];

		return md5(
			(string) $payload['event']
			. (string) ( $data['transactionId'] ?? $data['id'] ?? $data['paymentMethodId'] ?? '' )
			. (string) ( $data['status'] ?? '' )
			. (string) ( $data['updatedAt'] ?? '' )
		);
	}

	/**
	 * Transients rather than a custom table: a custom table means activation
	 * migrations, version management and an uninstall routine for the merchant
	 * to carry. Transients are built in and expire on their own. The cost is
	 * that a site without object caching stores these in the options table,
	 * which is fine at webhook volumes.
	 *
	 * @param string $event_id Event key.
	 * @return bool False when this event has been seen already.
	 */
	private function claim_event( string $event_id ): bool {
		$key = 'afriex_webhook_' . $event_id;

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, self::EVENT_KEY_TTL );

		return true;
	}

	/**
	 * Match the transaction to an order and hand it to the reconciler.
	 *
	 * @param array $transaction_data Transaction payload from the webhook.
	 */
	private function reconcile_order( array $transaction_data ): void {
		$order = $this->find_order( $transaction_data );

		if ( null === $order ) {
			// Common and not an error: the same Afriex account can receive
			// deposits that have nothing to do with this store.
			Afriex_Logger::info(
				'Webhook received for unknown payment method',
				array(
					'payment_method_id' => (string) ( $transaction_data['destinationId'] ?? '' ),
				)
			);
			return;
		}

		if ( AFRIEX_WC_GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		( new Afriex_Reconciler() )->apply_transaction( $order, $transaction_data, 'webhook' );
	}

	/**
	 * @param array $transaction_data Transaction payload from the webhook.
	 */
	private function find_order( array $transaction_data ): ?WC_Order {
		$payment_method_id = (string) ( $transaction_data['destinationId'] ?? $transaction_data['paymentMethodId'] ?? '' );

		$order = Afriex_Order_Meta::find_order_by_payment_method_id( $payment_method_id );

		if ( null !== $order ) {
			return $order;
		}

		// Pool collection shares one account across orders, so the reference the
		// customer typed is the only thing that identifies which order was paid.
		$reference = (string) ( $transaction_data['reference'] ?? $transaction_data['narration'] ?? '' );

		return Afriex_Order_Meta::find_order_by_reference( $reference );
	}

	/**
	 * Read the configured public key, tolerating the two ways merchants paste
	 * one in: with real newlines, or with the newlines escaped by whatever
	 * copied it out of the dashboard.
	 */
	private function get_public_key(): string {
		$settings   = get_option( 'woocommerce_' . AFRIEX_WC_GATEWAY_ID . '_settings', array() );
		$public_key = trim( (string) ( $settings['webhook_public_key'] ?? '' ) );

		if ( '' === $public_key ) {
			return '';
		}

		return str_replace( array( '\r\n', '\n' ), "\n", $public_key );
	}
}
