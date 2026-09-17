<?php
/**
 * Cart & Checkout blocks integration.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the gateway to the block checkout's payment method registry.
 *
 * A gateway that implements only WC_Payment_Gateway is completely invisible on
 * block checkout — stores using it see "There are no payment methods available"
 * even with the gateway enabled and correctly configured. There is no automatic
 * bridge between the two checkouts.
 *
 * This class is purely additive: it reads the classic gateway's settings,
 * title, description and availability rather than duplicating them, so the two
 * checkouts can never drift apart.
 */
class Afriex_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Must match the gateway id, and the name registered in the JS module.
	 *
	 * @var string
	 */
	protected $name = AFRIEX_WC_GATEWAY_ID;

	/**
	 * Lazily resolved classic gateway.
	 *
	 * @var Afriex_Gateway|null
	 */
	private $gateway = null;

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . AFRIEX_WC_GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Availability is delegated, so a missing API key hides the method on both
	 * checkouts from one rule.
	 */
	public function is_active(): bool {
		$gateway = $this->get_gateway();

		return null !== $gateway && $gateway->is_available();
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$script_path = 'assets/js/build/afriex-blocks-checkout.js';
		$asset_path  = AFRIEX_WC_PLUGIN_DIR . 'assets/js/build/afriex-blocks-checkout.asset.php';

		if ( ! file_exists( AFRIEX_WC_PLUGIN_DIR . $script_path ) ) {
			// Nothing to register. Returning an empty array leaves the method
			// off block checkout rather than enqueuing a 404 and breaking the
			// whole checkout bundle.
			return array();
		}

		$asset = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => AFRIEX_WC_VERSION,
			);

		wp_register_script(
			'afriex-blocks-checkout',
			plugins_url( $script_path, AFRIEX_WC_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'afriex-blocks-checkout',
				'afriex-gateway-for-woocommerce',
				AFRIEX_WC_PLUGIN_DIR . 'languages'
			);
		}

		return array( 'afriex-blocks-checkout' );
	}

	/**
	 * Data handed to the JS module. Reaches it as getSetting( 'afriex_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$gateway = $this->get_gateway();

		if ( null === $gateway ) {
			return array();
		}

		return array(
			'id'          => AFRIEX_WC_GATEWAY_ID,
			'title'       => $gateway->get_title(),
			'description' => $gateway->get_description(),
			'supports'    => array_filter( $gateway->supports, array( $gateway, 'supports' ) ),
		);
	}

	private function get_gateway(): ?Afriex_Gateway {
		if ( null !== $this->gateway ) {
			return $this->gateway;
		}

		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return null;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		if ( ! isset( $gateways[ AFRIEX_WC_GATEWAY_ID ] ) || ! $gateways[ AFRIEX_WC_GATEWAY_ID ] instanceof Afriex_Gateway ) {
			return null;
		}

		$this->gateway = $gateways[ AFRIEX_WC_GATEWAY_ID ];

		return $this->gateway;
	}
}
