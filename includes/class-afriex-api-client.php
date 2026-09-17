<?php
/**
 * Thin REST client over the WordPress HTTP API.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's entire Afriex integration surface.
 *
 * There is no Afriex PHP SDK, so every call goes directly against the REST API
 * using wp_remote_*. Keeping that behind one class means a future PHP SDK can be
 * swapped in without touching gateway, webhook or reconciler code.
 *
 * Every method returns a plain array on success or WP_Error on failure — the
 * WordPress convention — rather than throwing.
 */
class Afriex_Api_Client {

	private const STAGING_BASE_URL    = 'https://sandbox.api.afriex.com/api/v1';
	private const PRODUCTION_BASE_URL = 'https://api.afriex.com/api/v1';
	private const REQUEST_TIMEOUT     = 30;

	/**
	 * API key from the merchant's Afriex Business dashboard.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Either 'staging' or 'production'.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * @param array $options api_key, environment.
	 */
	public function __construct( array $options ) {
		$this->api_key     = isset( $options['api_key'] ) ? (string) $options['api_key'] : '';
		$this->environment = isset( $options['environment'] ) ? (string) $options['environment'] : 'staging';
	}

	/**
	 * Create a customer in Afriex.
	 *
	 * @param array $customer_data fullName, email, phone, countryCode.
	 * @return array|WP_Error
	 */
	public function create_customer( array $customer_data ) {
		return $this->post( '/customer', $customer_data );
	}

	/**
	 * Create a dynamic virtual account scoped to an exact amount.
	 *
	 * @param array $account_data currency, customerId, amount.
	 * @return array|WP_Error
	 */
	public function create_virtual_account( array $account_data ) {
		return $this->post( '/payment-method/virtual-account', $account_data );
	}

	/**
	 * Fetch the pool account for a country.
	 *
	 * @param array $pool_data country, customerId.
	 * @return array|WP_Error
	 */
	public function get_pool_account( array $pool_data ) {
		return $this->get( '/payment-method/pool-account', $pool_data );
	}

	/**
	 * Fetch a transaction by id — used by the reconciliation sweep.
	 *
	 * @param string $transaction_id Afriex transaction id.
	 * @return array|WP_Error
	 */
	public function get_transaction( string $transaction_id ) {
		return $this->get( '/transaction/' . rawurlencode( $transaction_id ), array() );
	}

	/**
	 * List transactions settled against a payment method.
	 *
	 * The sweep needs this because an order that never received a webhook has no
	 * transaction id stored — only the payment method the customer was told to
	 * pay into.
	 *
	 * @param string $payment_method_id Afriex payment method id.
	 * @return array|WP_Error List of transaction arrays.
	 */
	public function get_transactions_for_payment_method( string $payment_method_id ) {
		$result = $this->get(
			'/transaction',
			array(
				'destinationId' => $payment_method_id,
				'limit'         => 10,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The list endpoint may return either a bare array or a paginated
		// envelope; normalise so callers only handle one shape.
		if ( isset( $result['transactions'] ) && is_array( $result['transactions'] ) ) {
			return $result['transactions'];
		}

		if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
			return $result['items'];
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Whether the client has enough configuration to be usable at all.
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	private function base_url(): string {
		$url = 'production' === $this->environment
			? self::PRODUCTION_BASE_URL
			: self::STAGING_BASE_URL;

		/**
		 * Filter the Afriex API base URL.
		 *
		 * The staging host is the one open question in this client that cannot
		 * be settled without the Afriex team; this filter lets a merchant or
		 * integrator point at the right host without patching the plugin.
		 *
		 * @param string $url         Base URL including the /api/v1 prefix.
		 * @param string $environment Either 'staging' or 'production'.
		 */
		return (string) apply_filters( 'woocommerce_afriex_api_base_url', $url, $this->environment );
	}

	private function request_headers(): array {
		return array(
			'x-api-key'    => $this->api_key,
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'User-Agent'   => 'afriex-gateway-for-woocommerce/' . AFRIEX_WC_VERSION,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private function post( string $path, array $body ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured_error();
		}

		Afriex_Logger::debug( 'POST ' . $path );

		$response = wp_remote_post(
			$this->base_url() . $path,
			array(
				'headers' => $this->request_headers(),
				'body'    => wp_json_encode( $body ),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		return $this->parse_response( $response, $path );
	}

	/**
	 * @return array|WP_Error
	 */
	private function get( string $path, array $query ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured_error();
		}

		$url = $this->base_url() . $path;

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		Afriex_Logger::debug( 'GET ' . $path );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->request_headers(),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		return $this->parse_response( $response, $path );
	}

	/**
	 * @param array|WP_Error $response Raw wp_remote_* return value.
	 * @return array|WP_Error
	 */
	private function parse_response( $response, string $path ) {
		if ( is_wp_error( $response ) ) {
			Afriex_Logger::error(
				'Afriex request failed to send',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);

			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code >= 400 ) {
			$message = isset( $body['message'] ) && is_string( $body['message'] )
				? $body['message']
				: __( 'Afriex API request failed.', 'afriex-gateway-for-woocommerce' );

			Afriex_Logger::error(
				'Afriex API error',
				array(
					'path'   => $path,
					'status' => $status_code,
				)
			);

			return new WP_Error( 'afriex_api_error', $message, array( 'status' => $status_code ) );
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'afriex_api_error',
				__( 'Afriex returned an unreadable response.', 'afriex-gateway-for-woocommerce' ),
				array( 'status' => $status_code )
			);
		}

		return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
	}

	private function not_configured_error(): WP_Error {
		return new WP_Error(
			'afriex_not_configured',
			__( 'Afriex is not configured. Add an API key in the payment method settings.', 'afriex-gateway-for-woocommerce' )
		);
	}
}
