<?php
/**
 * Creates an on-hold Afriex order to drive the webhook tests against.
 *
 * Run through WP-CLI: `npm run env:order`.
 *
 * This stands in for a real checkout. process_payment() needs live Afriex
 * credentials to issue an account, so without them there is no way to reach the
 * on-hold state through the store — and the on-hold state is exactly what the
 * webhook tests need. The meta written here is what store_payment_details()
 * would have written.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$payment_method_id = 'pm_test_' . wp_rand( 1000, 9999 );
$total             = 49.99;

$order = wc_create_order();
$order->set_payment_method( 'afriex' );
$order->set_payment_method_title( 'Bank Transfer' );
$order->set_currency( 'NGN' );
$order->set_billing_email( 'test@example.com' );
$order->set_billing_first_name( 'Test' );
$order->set_billing_last_name( 'Customer' );
$order->set_billing_country( 'NG' );

$products = wc_get_products(
	array(
		'limit'  => 1,
		'status' => 'publish',
	)
);

if ( ! empty( $products ) ) {
	$order->add_product( $products[0], 1 );
}

$order->set_total( $total );

$order->update_meta_data( '_afriex_payment_method_id', $payment_method_id );
$order->update_meta_data( '_afriex_customer_id', 'cus_test_1' );
$order->update_meta_data( '_afriex_account_number', '0123456789' );
$order->update_meta_data( '_afriex_account_name', 'Afriex Test Store' );
$order->update_meta_data( '_afriex_bank_name', 'Test Bank' );
$order->update_meta_data( '_afriex_reference', 'AFX-' . $order->get_id() );
$order->update_meta_data( '_afriex_last_status', 'PENDING' );
$order->update_meta_data( '_afriex_collection_method', 'dedicated' );

$order->update_status( 'on-hold', 'Awaiting Afriex bank transfer.' );
$order->save();

WP_CLI::log( 'Order id:          ' . $order->get_id() );
WP_CLI::log( 'Status:            ' . $order->get_status() );
WP_CLI::log( 'Total:             ' . $order->get_total() );
WP_CLI::log( 'Payment method id: ' . $payment_method_id );
WP_CLI::log( '' );
WP_CLI::log( 'Drive the webhook tests with:' );
WP_CLI::log( '  npm run webhook:send -- --payment-method-id ' . $payment_method_id . ' --status COMPLETED --amount ' . $total );
