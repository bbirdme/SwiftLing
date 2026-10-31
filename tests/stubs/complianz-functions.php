<?php
/**
 * A stand-in for the function of Complianz that SwiftLing calls: it empties the plugin's own
 * transients by a prefix of their names, as Complianz 7.5.6 does (functions.php).
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The plugin's own name.

if ( ! function_exists( 'cmplz_delete_transients_by_prefix' ) ) {
	/**
	 * Deletes the plugin's transients whose names start with a prefix.
	 *
	 * @param string $prefix Prefix of the names.
	 */
	function cmplz_delete_transients_by_prefix( string $prefix ): void {
		$transients = get_option( 'cmplz_transients', array() );
		foreach ( array_keys( $transients ) as $name ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				unset( $transients[ $name ] );
			}
		}
		update_option( 'cmplz_transients', $transients, false );
	}
}
