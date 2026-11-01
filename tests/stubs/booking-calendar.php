<?php
/**
 * A stand-in for the functions of Booking Calendar that SwiftLing calls: the meta of a booking, and
 * the summary of a booking, which the plugin writes from its form as its loader hands it out.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore, Generic.CodeAnalysis.UnusedFunctionParameter -- The plugin's own names and arguments.

if ( ! function_exists( 'wpbc_save_booking_meta_option' ) ) {
	/**
	 * Saves options of a booking's meta.
	 *
	 * @param int   $booking_id The booking.
	 * @param array $options    Option => value.
	 * @return bool
	 */
	function wpbc_save_booking_meta_option( $booking_id, $options ) {
		$GLOBALS['swiftling_test_wpbc_meta'][ $booking_id ] = array_merge( $GLOBALS['swiftling_test_wpbc_meta'][ $booking_id ] ?? array(), $options );
		return true;
	}

	/**
	 * Returns an option of a booking's meta.
	 *
	 * @param int    $booking_id The booking.
	 * @param string $option     The option.
	 * @return mixed
	 */
	function wpbc_get_booking_meta_option( $booking_id, $option = false ) {
		return $GLOBALS['swiftling_test_wpbc_meta'][ $booking_id ][ $option ] ?? false;
	}

	/**
	 * Returns the summary of a booking: the summary of the standard form, as the plugin's loader hands
	 * it out, its fields filled.
	 *
	 * @param string $data    The booking's data.
	 * @param int    $type    The resource.
	 * @param string $form    The form.
	 * @param array  $replace The values of the email's placeholders.
	 * @return array
	 */
	function wpbc__legacy__get_form_content_arr( $data, $type, $form = '', $replace = array() ) {
		$pair = apply_filters(
			'wpbc_bfb_form_loader_from_builder',
			array(
				'form'      => '',
				'content'   => $GLOBALS['swiftling_test_wpbc_summary'] ?? '',
				'form_slug' => 'standard',
			),
			array()
		);
		return array( 'content' => str_replace( '[name]', (string) $data, $pair['content'] ) );
	}
}
