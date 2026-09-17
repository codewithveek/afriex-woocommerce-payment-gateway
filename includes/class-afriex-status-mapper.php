<?php
/**
 * Afriex transaction status to plugin action mapping.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates an Afriex transaction status into the action the plugin takes.
 *
 * Deliberately exhaustive: every known Afriex status is handled explicitly, and
 * anything unrecognised is flagged for a human rather than falling through to a
 * default that would quietly misrepresent an unknown state as safe.
 */
class Afriex_Status_Mapper {

	public const ACTION_COMPLETE        = 'complete';
	public const ACTION_FAIL            = 'fail';
	public const ACTION_REFUND          = 'refund';
	public const ACTION_CANCEL          = 'cancel';
	public const ACTION_NOTE_ONLY       = 'note_only';
	public const ACTION_FLAG_FOR_REVIEW = 'flag_for_review';

	/**
	 * Map an Afriex transaction status to the action the plugin should take.
	 *
	 * @param string $afriex_status Status string from the Afriex API.
	 */
	public static function resolve_action( string $afriex_status ): string {
		switch ( strtoupper( trim( $afriex_status ) ) ) {
			case 'COMPLETED':
			case 'SUCCESS':
				return self::ACTION_COMPLETE;

			case 'FAILED':
			case 'REJECTED':
				return self::ACTION_FAIL;

			case 'REFUNDED':
				return self::ACTION_REFUND;

			case 'CANCELLED':
			case 'CANCELED':
				return self::ACTION_CANCEL;

			case 'PENDING':
			case 'PROCESSING':
			case 'RETRY':
			case 'IN_REVIEW':
			case 'SCHEDULED':
			case 'CUSTOMER_ACTION_REQUIRED':
				return self::ACTION_NOTE_ONLY;

			case 'DISPUTED':
			case 'DISPUTE_RESOLVED':
			case 'DISPUTE_WON':
			case 'DISPUTE_LOST':
			case 'DISPUTE_EVIDENCE_SUBMITTED':
				return self::ACTION_NOTE_ONLY;

			case 'UNKNOWN':
			default:
				return self::ACTION_FLAG_FOR_REVIEW;
		}
	}

	/**
	 * Whether the status is one Afriex will not move away from.
	 *
	 * Used by the reconciliation sweep to stop polling an order that has
	 * already settled one way or the other.
	 *
	 * @param string $afriex_status Status string from the Afriex API.
	 */
	public static function is_terminal( string $afriex_status ): bool {
		return in_array(
			self::resolve_action( $afriex_status ),
			array( self::ACTION_COMPLETE, self::ACTION_FAIL, self::ACTION_REFUND, self::ACTION_CANCEL ),
			true
		);
	}
}
