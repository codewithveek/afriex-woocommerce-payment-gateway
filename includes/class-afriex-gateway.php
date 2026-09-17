<?php
/**
 * Classic checkout gateway.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The WC_Payment_Gateway subclass.
 *
 * This is the single processing path for both checkouts: the block checkout
 * integration registers a client-side payment method whose name routes back to
 * this class's process_payment().
 */
class Afriex_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = AFRIEX_WC_GATEWAY_ID;
		$this->method_title       = __( 'Afriex', 'afriex-gateway-for-woocommerce' );
		$this->method_description = __(
			'Accept bank transfer payments through Afriex virtual accounts. Orders are confirmed automatically when payment is received.',
			'afriex-gateway-for-woocommerce'
		);
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		// Show the account details on the order-received page and in emails.
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'render_payment_instructions' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'render_instructions_in_email' ), 10, 3 );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable/Disable', 'afriex-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Afriex', 'afriex-gateway-for-woocommerce' ),
				'default' => 'no',
			),
			'title'              => array(
				'title'       => __( 'Title', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the customer sees at checkout.', 'afriex-gateway-for-woocommerce' ),
				'default'     => __( 'Bank Transfer', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'   => __( 'Description', 'afriex-gateway-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __(
					'Pay by bank transfer. You will receive account details after placing your order.',
					'afriex-gateway-for-woocommerce'
				),
			),
			'instructions'       => array(
				'title'       => __( 'Instructions', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown above the account details on the order-received page and in order emails.', 'afriex-gateway-for-woocommerce' ),
				'default'     => __(
					'Transfer the exact order total to the account below. Your order is confirmed automatically once the payment arrives.',
					'afriex-gateway-for-woocommerce'
				),
				'desc_tip'    => true,
			),
			'environment'        => array(
				'title'   => __( 'Environment', 'afriex-gateway-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'staging'    => __( 'Sandbox', 'afriex-gateway-for-woocommerce' ),
					'production' => __( 'Production', 'afriex-gateway-for-woocommerce' ),
				),
				'default' => 'staging',
			),
			'api_key'            => array(
				'title'       => __( 'API Key', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'From Settings > API Keys in your Afriex Business dashboard.', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'webhook_public_key' => array(
				'title'       => __( 'Webhook Public Key', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => sprintf(
					/* translators: %s: webhook endpoint URL */
					__( 'From Developers > Webhooks in your Afriex Business dashboard. Point the webhook at: %s', 'afriex-gateway-for-woocommerce' ),
					'<code>' . esc_html( self::get_webhook_url() ) . '</code>'
				),
			),
			'collection_method'  => array(
				'title'       => __( 'Collection method', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'dedicated' => __( 'Dedicated virtual account (unique account per order)', 'afriex-gateway-for-woocommerce' ),
					'pool'      => __( 'Pool account (shared account, reference per order)', 'afriex-gateway-for-woocommerce' ),
				),
				'default'     => 'dedicated',
				'description' => __( 'Dedicated accounts need no reference from the customer. Pool accounts require the customer to include a reference.', 'afriex-gateway-for-woocommerce' ),
			),
			'logging'            => array(
				'title'       => __( 'Debug logging', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log Afriex API requests and webhook events', 'afriex-gateway-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Written to WooCommerce > Status > Logs. Errors are always logged; this adds request-level detail.', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * The URL Afriex should send webhooks to.
	 */
	public static function get_webhook_url(): string {
		return rest_url( 'afriex/v1/webhook' );
	}

	/**
	 * Hide the gateway at checkout when it cannot actually take a payment.
	 *
	 * Without the API key every order would reach process_payment() only to
	 * fail there, after the customer has filled in the whole form.
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( '' === (string) $this->get_option( 'api_key' ) ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Warn the merchant about incomplete configuration above the settings form.
	 */
	public function admin_options(): void {
		$missing = array();

		if ( '' === (string) $this->get_option( 'api_key' ) ) {
			$missing[] = __( 'API Key', 'afriex-gateway-for-woocommerce' );
		}

		if ( '' === (string) $this->get_option( 'webhook_public_key' ) ) {
			$missing[] = __( 'Webhook Public Key', 'afriex-gateway-for-woocommerce' );
		}

		if ( ! empty( $missing ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: comma-separated list of setting names */
					__( 'Afriex is not ready to take payments. Missing: %s. Without the webhook public key, payment confirmations are rejected and orders stay on hold.', 'afriex-gateway-for-woocommerce' ),
					implode( ', ', $missing )
				)
			);
			echo '</p></div>';
		}

		parent::admin_options();
	}

	/**
	 * Persist settings, then drop the logger's cached preference.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		Afriex_Logger::reset();

		return $saved;
	}

	/**
	 * Called when the customer places the order.
	 *
	 * No payment happens here — this generates the account to pay into. The
	 * order goes to on-hold and the webhook is what promotes it, exactly as
	 * WooCommerce's own BACS gateway behaves for bank transfers.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Order could not be loaded. Please try again.', 'afriex-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$client            = $this->build_api_client();
		$collection_method = (string) $this->get_option( 'collection_method', 'dedicated' );

		$customer_result = $client->create_customer(
			array(
				'fullName'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'       => $order->get_billing_email(),
				'phone'       => $order->get_billing_phone(),
				'countryCode' => $order->get_billing_country(),
			)
		);

		if ( is_wp_error( $customer_result ) ) {
			wc_add_notice( $customer_result->get_error_message(), 'error' );
			Afriex_Logger::error(
				'Customer creation failed',
				array(
					'order_id' => $order_id,
					'error'    => $customer_result->get_error_message(),
				)
			);
			return array( 'result' => 'failure' );
		}

		$customer_id = $this->extract_customer_id( $customer_result );

		if ( '' === $customer_id ) {
			wc_add_notice(
				__( 'Afriex did not return a customer reference. Please choose another payment method or try again.', 'afriex-gateway-for-woocommerce' ),
				'error'
			);
			Afriex_Logger::error( 'Customer creation returned no id', array( 'order_id' => $order_id ) );
			return array( 'result' => 'failure' );
		}

		$account_result = 'pool' === $collection_method
			? $client->get_pool_account(
				array(
					'country'    => $order->get_billing_country(),
					'customerId' => $customer_id,
				)
			)
			: $client->create_virtual_account(
				array(
					'currency'   => $order->get_currency(),
					'customerId' => $customer_id,
					'amount'     => (float) $order->get_total(),
				)
			);

		if ( is_wp_error( $account_result ) ) {
			wc_add_notice( $account_result->get_error_message(), 'error' );
			Afriex_Logger::error(
				'Account creation failed',
				array(
					'order_id' => $order_id,
					'method'   => $collection_method,
					'error'    => $account_result->get_error_message(),
				)
			);
			return array( 'result' => 'failure' );
		}

		if ( '' === Afriex_Order_Meta::extract_payment_method_id( $account_result ) ) {
			// Without a payment method id there is nothing for the webhook to
			// match the deposit against, so the order would strand on-hold.
			wc_add_notice(
				__( 'Afriex did not return usable account details. Please choose another payment method or try again.', 'afriex-gateway-for-woocommerce' ),
				'error'
			);
			Afriex_Logger::error( 'Account payload had no payment method id', array( 'order_id' => $order_id ) );
			return array( 'result' => 'failure' );
		}

		Afriex_Order_Meta::store_payment_details( $order, $account_result, $customer_id, $collection_method );

		// Mark on-hold: we are awaiting an external bank transfer.
		// Filterable, mirroring the BACS gateway convention, so existing store
		// customisations behave the way merchants expect.
		$order->update_status(
			apply_filters( 'woocommerce_afriex_process_payment_order_status', 'on-hold', $order ),
			__( 'Awaiting Afriex bank transfer.', 'afriex-gateway-for-woocommerce' )
		);

		wc_reduce_stock_levels( $order_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		Afriex_Logger::info(
			'Afriex account issued for order',
			array(
				'order_id' => $order_id,
				'method'   => $collection_method,
			)
		);

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Account details on the order-received page.
	 *
	 * @param int $order_id Order id.
	 */
	public function render_payment_instructions( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || $this->id !== $order->get_payment_method() ) {
			return;
		}

		$this->render_instructions_markup( $order );
	}

	/**
	 * Account details in order emails.
	 *
	 * Only in emails sent to the customer, and only while the order is still
	 * awaiting payment — there is no reason to repeat bank details on a
	 * completed order.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether this email goes to the admin.
	 * @param bool     $plain_text    Whether this is the plain-text email.
	 */
	public function render_instructions_in_email( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( ! $order instanceof WC_Order || $sent_to_admin ) {
			return;
		}

		if ( $this->id !== $order->get_payment_method() || ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		$this->render_instructions_markup( $order, (bool) $plain_text );
	}

	/**
	 * @param WC_Order $order      Order.
	 * @param bool     $plain_text Render without markup.
	 */
	private function render_instructions_markup( WC_Order $order, bool $plain_text = false ): void {
		$rows = Afriex_Order_Meta::get_payment_instructions( $order );

		if ( empty( $rows ) ) {
			return;
		}

		$instructions = (string) $this->get_option( 'instructions' );

		if ( $plain_text ) {
			if ( '' !== $instructions ) {
				echo esc_html( wp_strip_all_tags( $instructions ) ) . "\n\n";
			}

			echo esc_html__( 'Afriex bank transfer details', 'afriex-gateway-for-woocommerce' ) . "\n";

			foreach ( $rows as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . "\n";
			}

			echo "\n";
			return;
		}

		if ( '' !== $instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $instructions ) ) );
		}

		echo '<section class="woocommerce-afriex-instructions">';
		echo '<h2>' . esc_html__( 'Afriex bank transfer details', 'afriex-gateway-for-woocommerce' ) . '</h2>';
		echo '<ul class="wc-afriex-account-details order_details">';

		foreach ( $rows as $label => $value ) {
			echo '<li>' . esc_html( $label ) . ': <strong>' . esc_html( $value ) . '</strong></li>';
		}

		echo '</ul>';
		echo '</section>';
	}

	private function build_api_client(): Afriex_Api_Client {
		return new Afriex_Api_Client(
			array(
				'api_key'     => (string) $this->get_option( 'api_key' ),
				'environment' => (string) $this->get_option( 'environment', 'staging' ),
			)
		);
	}

	/**
	 * @param array $customer_result Customer payload from Afriex.
	 */
	private function extract_customer_id( array $customer_result ): string {
		foreach ( array( 'customerId', 'id', '_id' ) as $key ) {
			if ( ! empty( $customer_result[ $key ] ) && is_scalar( $customer_result[ $key ] ) ) {
				return (string) $customer_result[ $key ];
			}
		}

		return '';
	}
}
