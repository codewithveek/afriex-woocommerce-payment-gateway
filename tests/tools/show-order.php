<?php
/**
 * Prints an order's current status, Afriex meta and notes.
 *
 * Run through WP-CLI: `npm run env:show -- <order-id>`.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$order_id = isset( $args[0] ) ? (int) $args[0] : 0;

if ( ! $order_id ) {
	$orders = wc_get_orders(
		array(
			'limit'          => 1,
			'payment_method' => 'afriex',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'status'         => 'any',
		)
	);

	if ( empty( $orders ) ) {
		WP_CLI::error( 'No Afriex orders found.' );
	}

	$order = $orders[0];
} else {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		WP_CLI::error( 'No order ' . $order_id );
	}
}

WP_CLI::log( 'Order:          #' . $order->get_id() );
WP_CLI::log( 'Status:         ' . $order->get_status() );
WP_CLI::log( 'Paid:           ' . ( $order->is_paid() ? 'yes' : 'no' ) );
WP_CLI::log( 'Total:          ' . $order->get_total() );
WP_CLI::log( 'Transaction id: ' . ( $order->get_transaction_id() ? $order->get_transaction_id() : '(none)' ) );
WP_CLI::log( 'Afriex status:  ' . $order->get_meta( '_afriex_last_status' ) );
WP_CLI::log( 'Payment method: ' . $order->get_meta( '_afriex_payment_method_id' ) );
WP_CLI::log( '' );
WP_CLI::log( 'Notes:' );

foreach ( array_reverse( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) as $note ) {
	WP_CLI::log( '  - ' . $note->content );
}
