<?php
/**
 * Real Cookie Banner without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\RealCookieBanner;

/**
 * Real Cookie Banner, without the plugin: active, with its types of post and its taxonomy of service
 * groups, and everything else of the real class.
 */
class FakeRealCookieBanner extends RealCookieBanner {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the types of post, the taxonomy and the shortcode of a link that changes the consent as
	 * Real Cookie Banner 5.4 does: none of them is public.
	 */
	public static function install(): void {
		add_shortcode( 'rcb-consent', '__return_empty_string' );
		foreach ( self::POST_TYPES as $type ) {
			register_post_type( $type, array( 'public' => false ) );
		}
		register_taxonomy( self::GROUPS, self::POST_TYPES, array( 'public' => false ) );
	}
}
