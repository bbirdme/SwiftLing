<?php
/**
 * Stand-in for SiteGround Speed Optimizer's class that empties one address, see
 * includes/cache-plugins.php.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The plugin's own names.

namespace SiteGround_Optimizer\Supercacher;

/**
 * SiteGround Speed Optimizer's dynamic cache.
 */
class Supercacher {
	/**
	 * Empties an address, and the pages below it when asked.
	 *
	 * @param string $url                 Address.
	 * @param bool   $include_child_paths Whether the pages below go too.
	 */
	public static function purge_cache_request( $url, $include_child_paths = true ) {
		swiftling_tests_purged( 'Supercacher::purge_cache_request', array( $url, $include_child_paths ) );
	}
}
