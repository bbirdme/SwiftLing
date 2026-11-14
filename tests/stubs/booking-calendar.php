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

	/**
	 * Answers WordPress's locale with the language of the page the plugin's request came from, as the
	 * plugin does in its own requests.
	 *
	 * @return string
	 */
	function wpbc_get_maybe_reloaded_booking_locale() {
		return 'de_DE';
	}

	/**
	 * Returns the values of the placeholders of the email to the site's owner: the dates of the booking
	 * as the plugin formats them, in the language WordPress runs in, which its own locale filter decides
	 * while it is on.
	 *
	 * @param int    $booking_id The booking.
	 * @param int    $type       The resource.
	 * @param string $data       The booking's data.
	 * @return array
	 */
	function wpbc__get_replace_shortcodes__email_new_admin( $booking_id, $type, $data ) {
		$german  = false !== has_filter( 'locale', 'wpbc_get_maybe_reloaded_booking_locale' ) || '' !== swiftling_writing_language();
		$date    = $german ? '11 Dez. 2026' : '11 Dec 2026';
		$replace = apply_filters(
			'wpbc_replace_params_for_booking',
			array(
				'dates'         => $date . ' - 12 ' . ( $german ? 'Dez.' : 'Dec' ) . ' 2026',
				'check_in_date' => $date,
			),
			$booking_id,
			$type,
			$data
		);
		return $replace;
	}
}
