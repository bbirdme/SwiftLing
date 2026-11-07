<?php
/**
 * A stand-in for the part of WP-Members that SwiftLing calls: the settings of an email, the text the
 * site's owner saved or the plugin's own, as WP-Members 3.5 returns them.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting -- The plugin's own names, for the tests.

if ( ! function_exists( 'wpmem_get_email_settings' ) ) {
	function wpmem_get_email_settings( $tag ) {
		$saved    = get_option( $tag );
		$defaults = array(
			'wpmembers_email_newreg' => array(
				'subj' => 'Your registration for [blogname]',
				'body' => "Thank you for registering.\n[reglink]",
			),
			'wpmembers_email_footer' => "----\nAn automated message from [blogname]",
		);
		return $saved ? $saved : ( $defaults[ $tag ] ?? array() );
	}
}
