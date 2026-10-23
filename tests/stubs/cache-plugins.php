<?php
/**
 * Stand-ins for the functions and classes of the cache plugins SwiftLing empties, see
 * includes/cache-plugins.php. Each notes how it was called in $GLOBALS['swiftling_tests_purged'].
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The plugins' own names, in one file for the tests.

/**
 * Notes a call.
 *
 * @param string $name What was called.
 * @param mixed  $args With what.
 */
function swiftling_tests_purged( $name, $args = null ) {
	$GLOBALS['swiftling_tests_purged'][] = array( $name, $args );
}

/**
 * WP Rocket: empties addresses and the pages below them.
 *
 * @param string[] $urls Addresses.
 */
function rocket_clean_files( $urls ) {
	swiftling_tests_purged( 'rocket_clean_files', $urls );
}

/**
 * WP Rocket: empties the front page, with the root the filter gives.
 */
function rocket_clean_home() {
	swiftling_tests_purged( 'rocket_clean_home', apply_filters( 'rocket_clean_home_root', 'cache/example.org*', 'example.org', '' ) );
}

/**
 * WP Rocket: empties everything.
 */
function rocket_clean_domain() {
	swiftling_tests_purged( 'rocket_clean_domain' );
}

/**
 * W3 Total Cache: empties an address.
 *
 * @param string $url Address.
 */
function w3tc_flush_url( $url ) {
	swiftling_tests_purged( 'w3tc_flush_url', $url );
}

/**
 * W3 Total Cache: empties everything.
 */
function w3tc_flush_all() {
	swiftling_tests_purged( 'w3tc_flush_all' );
}

/**
 * WP Super Cache: empties an address.
 *
 * @param string $url Address.
 */
function wpsc_delete_url_cache( $url ) {
	swiftling_tests_purged( 'wpsc_delete_url_cache', $url );
}

/**
 * WP Super Cache: empties everything.
 */
function wp_cache_clear_cache() {
	swiftling_tests_purged( 'wp_cache_clear_cache' );
}

/**
 * SiteGround Speed Optimizer: empties an address and the pages below it.
 *
 * @param string $url Address.
 */
function sg_cachepress_purge_cache( $url ) {
	swiftling_tests_purged( 'sg_cachepress_purge_cache', $url );
}

/**
 * SiteGround Speed Optimizer: empties everything.
 */
function sg_cachepress_purge_everything() {
	swiftling_tests_purged( 'sg_cachepress_purge_everything' );
}

/**
 * Proxy Cache Purge.
 */
class VarnishPurger {
	/**
	 * Empties an address.
	 *
	 * @param string $url Address.
	 */
	public static function purge_url( $url ) {
		swiftling_tests_purged( 'VarnishPurger::purge_url', $url );
	}
}

/**
 * Breeze.
 */
class Breeze_PurgeCache {
	/**
	 * Returns the instance.
	 *
	 * @return self
	 */
	public static function factory() {
		return new self();
	}

	/**
	 * Empties a post's addresses, as its filter collects them.
	 *
	 * @param int $post_id Post ID.
	 */
	public function purge_post_cache( $post_id ) {
		swiftling_tests_purged( 'Breeze_PurgeCache::purge_post_cache', apply_filters( 'breeze_collect_urls_for_cache_purge', array( 'http://example.org/?p=' . $post_id ), $post_id ) );
	}
}
