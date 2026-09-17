/**
 * Registers Afriex as a payment method on the Cart & Checkout blocks.
 *
 * This module only describes the method to the block checkout UI. The `name`
 * below is what tells WooCommerce which server-side gateway class to invoke, so
 * processing still runs through Afriex_Gateway::process_payment() — one path for
 * both checkouts.
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const settings = getSetting( 'afriex_data', {} );

// Read the id from the server rather than repeating the literal. Hard-coded
// gateway ids in JS are a known friction point for extensions that duplicate or
// filter gateways, and the server is the only place the id is authoritative.
const PAYMENT_METHOD_NAME = settings.id || 'afriex';

const defaultLabel = __( 'Bank Transfer', 'afriex-gateway-for-woocommerce' );
const label = decodeEntities( settings.title || '' ) || defaultLabel;

const AfriexDescription = () => {
	return decodeEntities( settings.description || '' );
};

registerPaymentMethod( {
	name: PAYMENT_METHOD_NAME,
	label: <span>{ label }</span>,
	content: <AfriexDescription />,
	edit: <AfriexDescription />,
	// Availability is decided server-side by Afriex_Gateway::is_available(),
	// which is_active() delegates to. If the method reached the client at all,
	// it can be used.
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports ?? [ 'products' ],
	},
} );
