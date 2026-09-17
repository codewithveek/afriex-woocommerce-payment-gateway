<?php
/**
 * Removes plugin data on uninstall.
 *
 * Deliberately narrow: gateway settings and the scheduled sweep go, order meta
 * stays. Order meta is a record of how a real order was paid, and a store that
 * removes the plugin still needs it for accounting and disputes.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_afriex_settings' );

wp_clear_scheduled_hook( 'afriex_reconcile_pending_orders' );
