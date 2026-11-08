<?php
/**
 * A stand-in for the part of GiveWP that SwiftLing calls: the meta of a donation and its form, as GiveWP
 * 4.19 keeps them.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting, Generic.CodeAnalysis.UnusedFunctionParameter -- The plugin's own names, for the tests.

if ( ! function_exists( 'give_get_meta' ) ) {
	function give_get_meta( $id, $key = '', $single = false ) {
		$value = $GLOBALS['swiftling_test_give_meta'][ $id ][ $key ] ?? '';
		return $single ? $value : array( $value );
	}

	function give_update_meta( $id, $key, $value ) {
		$GLOBALS['swiftling_test_give_meta'][ $id ][ $key ] = $value;
		return true;
	}

	function give_get_payment_form_id( $id ) {
		return (int) ( $GLOBALS['swiftling_test_give_meta'][ $id ]['_give_payment_form_id'] ?? 0 );
	}
}
