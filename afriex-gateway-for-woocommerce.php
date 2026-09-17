<?php
/**
 * Plugin Name: Afriex Gateway for WooCommerce
 * Plugin URI: https://github.com/codewithveek/afriex-woocommerce-payment-gateway
 * Description: Accept bank transfer and mobile money payments through Afriex virtual accounts.
 * Version: 1.0.0
 * Author: Afriex
 * Author URI: https://afriex.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: afriex-gateway-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'AFRIEX_WC_VERSION', '1.0.0' );
define( 'AFRIEX_WC_PLUGIN_FILE', __FILE__ );
define( 'AFRIEX_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Gateway id. Declared once so neither PHP nor JS has to repeat the literal.
 */
define( 'AFRIEX_WC_GATEWAY_ID', 'afriex' );

/**
 * Cron hook for the reconciliation sweep.
 */
define( 'AFRIEX_WC_RECONCILE_HOOK', 'afriex_reconcile_pending_orders' );

/**
 * Load the plugin's classes.
 *
 * Kept as explicit requires rather than an autoloader: the file count is small,
 * and the WordPress.org review process reads a flat require list more easily
 * than a Composer autoloader that would also have to be committed.
 */
function afriex_wc_load_classes(): void {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-logger.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-api-client.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-order-meta.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-status-mapper.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-gateway.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-reconciler.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-webhook-handler.php';

	$loaded = true;
}

/**
 * Declare compatibility with HPOS (custom order tables) and Cart & Checkout blocks.
 * Both must fire on before_woocommerce_init.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			AFRIEX_WC_PLUGIN_FILE,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			AFRIEX_WC_PLUGIN_FILE,
			true
		);
	}
);

/**
 * Register the classic checkout gateway.
 */
add_filter(
	'woocommerce_payment_gateways',
	function ( $gateways ) {
		$gateways[] = 'Afriex_Gateway';
		return $gateways;
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		afriex_wc_load_classes();

		( new Afriex_Webhook_Handler() )->register_routes_hook();
	}
);

/**
 * Register the block checkout integration.
 *
 * Purely additive: it bridges to the classic gateway's settings, title,
 * description and availability rather than duplicating them.
 */
add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-blocks-support.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
				$registry->register( new Afriex_Blocks_Support() );
			}
		);
	}
);

/**
 * Settings link on the plugins list table.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_url = admin_url(
			'admin.php?page=wc-settings&tab=checkout&section=' . AFRIEX_WC_GATEWAY_ID
		);

		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'afriex-gateway-for-woocommerce' ) . '</a>'
		);

		return $links;
	}
);

/**
 * The reconciliation sweep.
 *
 * Webhooks are the fast path. This is the backstop that guarantees an order
 * cannot strand at on-hold forever if a webhook is lost: WC_Order::needs_payment()
 * returns false for on-hold orders, so nothing in WooCommerce core will ever
 * come back around to an unpaid Afriex order on its own.
 */
add_action(
	AFRIEX_WC_RECONCILE_HOOK,
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		afriex_wc_load_classes();

		( new Afriex_Reconciler() )->sweep();
	}
);

register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( AFRIEX_WC_RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', AFRIEX_WC_RECONCILE_HOOK );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( AFRIEX_WC_RECONCILE_HOOK );
	}
);
