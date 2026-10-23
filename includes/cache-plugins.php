<?php
/**
 * The cache plugins SwiftLing empties itself, through the functions and actions each offers for it,
 * checked in the version named. Each runs only while its plugin is active.
 *
 * | Plugin                          | One address                           | Everything                       |
 * |---------------------------------|---------------------------------------|----------------------------------|
 * | WP Rocket 3.23                  | rocket_clean_files(), rocket_clean_home() | rocket_clean_domain()        |
 * | LiteSpeed Cache 7.9             | litespeed_purge_post, by post          | litespeed_purge_all              |
 * | W3 Total Cache 2.10             | w3tc_flush_url()                       | w3tc_flush_all()                 |
 * | WP Super Cache 3.1              | wpsc_delete_url_cache()                | wp_cache_clear_cache()           |
 * | WP Fastest Cache 1.5            | wpfc_clear_cache_by_url                | wpfc_clear_all_cache             |
 * | SiteGround Speed Optimizer 7.8  | sg_cachepress_purge_cache()            | sg_cachepress_purge_everything() |
 * | Breeze 2.6                      | its purge of a post, with the addresses added | breeze_clear_all_cache    |
 * | Nginx Helper 2.4                | $nginx_purger->purge_url()             | $nginx_purger->purge_all()       |
 * | Proxy Cache Purge 5.12          | VarnishPurger::purge_url()             | the same, with vhp-regex         |
 * | Cloudflare 4.14                 | its purge of a post, with the addresses added | purgeCacheEverything()    |
 *
 * WP Rocket and SiteGround empty an address with every address below it, so the front page of a
 * language goes to their purge of the front page alone: emptying the site address would empty
 * the whole site. LiteSpeed tags each page with the posts it shows, in every language, so emptying
 * a post empties all its pages. WP Super Cache keeps an address with a query, such as ?lang=de,
 * next to the one without, and refuses to empty it by itself, so it empties the address without.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the names of the cache plugins SwiftLing empties that are active, for the log and the
 * support report: the same ones swiftling_purge_cache_plugins() reaches.
 *
 * @return string[]
 */
function swiftling_active_cache_plugins() {
	$known = array(
		'WP Rocket'            => function_exists( 'rocket_clean_files' ),
		'LiteSpeed Cache'      => has_action( 'litespeed_purge_post' ),
		'W3 Total Cache'       => function_exists( 'w3tc_flush_url' ),
		'WP Super Cache'       => function_exists( 'wpsc_delete_url_cache' ),
		'WP Fastest Cache'     => has_action( 'wpfc_clear_cache_by_url' ),
		'SiteGround Optimizer' => function_exists( 'sg_cachepress_purge_cache' ),
		'Breeze'               => class_exists( 'Breeze_PurgeCache' ),
		'Varnish HTTP Purge'   => class_exists( 'VarnishPurger' ),
	);
	return array_keys( array_filter( $known ) );
}

/**
 * Empties addresses in every known cache plugin that is active.
 *
 * @param string[] $urls     Addresses.
 * @param int[]    $post_ids Posts they belong to.
 */
function swiftling_purge_cache_plugins( $urls, $post_ids ) {
	$urls     = array_values( array_filter( (array) $urls, 'is_string' ) );
	$post_ids = array_map( 'intval', (array) $post_ids );
	$homes    = array_values( array_filter( $urls, 'swiftling_is_home_address' ) );
	$pages    = array_values( array_diff( $urls, $homes ) );

	if ( function_exists( 'rocket_clean_files' ) && function_exists( 'rocket_clean_home' ) ) {
		rocket_clean_files( $pages );
		foreach ( $homes as $home ) {
			swiftling_rocket_clean_home( $home );
		}
	}

	if ( has_action( 'litespeed_purge_post' ) ) {
		foreach ( $post_ids as $post_id ) {
			do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
		}
	}

	if ( function_exists( 'w3tc_flush_url' ) ) {
		foreach ( $urls as $url ) {
			w3tc_flush_url( $url );
		}
	}

	if ( function_exists( 'wpsc_delete_url_cache' ) ) {
		// It keeps an address with a query in the folder of its path, and empties the whole folder.
		foreach ( array_unique( array_map( 'swiftling_address_path', $urls ) ) as $url ) {
			wpsc_delete_url_cache( $url );
		}
	}

	if ( has_action( 'wpfc_clear_cache_by_url' ) ) {
		foreach ( $urls as $url ) {
			do_action( 'wpfc_clear_cache_by_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Fastest Cache's own action.
		}
	}

	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		array_map( 'sg_cachepress_purge_cache', $pages );
		if ( class_exists( 'SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
			foreach ( $homes as $home ) {
				\SiteGround_Optimizer\Supercacher\Supercacher::purge_cache_request( $home, false );
			}
		}
	}

	if ( class_exists( 'Breeze_PurgeCache' ) && $post_ids ) {
		swiftling_purge_with_post_urls( 'breeze_collect_urls_for_cache_purge', $urls, array( Breeze_PurgeCache::factory(), 'purge_post_cache' ), $post_ids[0] );
	}

	if ( isset( $GLOBALS['nginx_purger'] ) && is_object( $GLOBALS['nginx_purger'] ) && method_exists( $GLOBALS['nginx_purger'], 'purge_url' ) ) {
		foreach ( $urls as $url ) {
			$GLOBALS['nginx_purger']->purge_url( $url, false );
		}
	}

	if ( class_exists( 'VarnishPurger' ) && method_exists( 'VarnishPurger', 'purge_url' ) ) {
		array_map( array( 'VarnishPurger', 'purge_url' ), $urls );
	}

	if ( isset( $GLOBALS['cloudflareHooks'] ) && is_object( $GLOBALS['cloudflareHooks'] ) && method_exists( $GLOBALS['cloudflareHooks'], 'purgeCacheByRelevantURLs' ) && $post_ids ) {
		swiftling_purge_with_post_urls( 'cloudflare_purge_by_url', $urls, array( $GLOBALS['cloudflareHooks'], 'purgeCacheByRelevantURLs' ), $post_ids );
	}
}
add_action( 'swiftling_purge_urls', 'swiftling_purge_cache_plugins', 10, 2 );

/**
 * Empties everything in every known cache plugin that is active.
 */
function swiftling_purge_cache_plugins_all() {
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	if ( has_action( 'wpfc_clear_all_cache' ) ) {
		do_action( 'wpfc_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Fastest Cache's own action.
	}
	if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
		sg_cachepress_purge_everything();
	}
	if ( has_action( 'breeze_clear_all_cache' ) ) {
		do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own action.
	}
	if ( isset( $GLOBALS['nginx_purger'] ) && is_object( $GLOBALS['nginx_purger'] ) && method_exists( $GLOBALS['nginx_purger'], 'purge_all' ) ) {
		$GLOBALS['nginx_purger']->purge_all();
	}
	if ( class_exists( 'VarnishPurger' ) && method_exists( 'VarnishPurger', 'purge_url' ) ) {
		VarnishPurger::purge_url( untrailingslashit( home_url() ) . '/?vhp-regex' );
	}
	if ( isset( $GLOBALS['cloudflareHooks'] ) && is_object( $GLOBALS['cloudflareHooks'] ) && method_exists( $GLOBALS['cloudflareHooks'], 'purgeCacheEverything' ) ) {
		$GLOBALS['cloudflareHooks']->purgeCacheEverything();
	}
}
add_action( 'swiftling_purge_all', 'swiftling_purge_cache_plugins_all' );

/**
 * Whether an address is the front page of a language: the site address, with or without a
 * language.
 *
 * @param string $url Address.
 * @return bool
 */
function swiftling_is_home_address( $url ) {
	$url  = swiftling_address_without_language( (string) $url );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	return null === wp_parse_url( $url, PHP_URL_QUERY ) && untrailingslashit( $path ) === untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );
}

/**
 * Returns an address without its query.
 *
 * @param string $url Address.
 * @return string
 */
function swiftling_address_path( $url ) {
	return (string) strtok( $url, '?#' );
}

/**
 * Empties a front page in WP Rocket: its files alone, not the pages below it. In a language's
 * folder, or under a language's host, through rocket_clean_home_root; addresses with ?lang= are
 * kept with the site address.
 *
 * @param string $url Front page of a language.
 */
function swiftling_rocket_clean_home( $url ) {
	$folder = '';
	foreach ( swiftling_folders() as $slug ) {
		if ( swiftling_remove_language_directory( $url, $slug ) !== $url ) {
			$folder = $slug;
		}
	}
	// WP Rocket keeps a host's pages under a folder named after it.
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$host = swiftling_is_host_format() && '' !== $host && swiftling_home_host() !== $host ? $host : '';
	$root = static function ( $root, $home = '' ) use ( $folder, $host ) {
		if ( '' !== $host && '' !== $home ) {
			$root = str_replace( $home . '*', $host . '*', $root );
		}
		return '' !== $folder ? $root . '/' . $folder : $root;
	};
	if ( '' !== $folder || '' !== $host ) {
		add_filter( 'rocket_clean_home_root', $root, 10, 2 );
	}
	try {
		rocket_clean_home();
	} finally {
		remove_filter( 'rocket_clean_home_root', $root );
	}
}

/**
 * Empties addresses with a plugin that empties only a post's: its own purge of a post runs with the
 * addresses added to the ones it collects for that post.
 *
 * @param string   $filter   The plugin's filter of the addresses of a post.
 * @param string[] $urls     Addresses.
 * @param callable $purge    The plugin's purge of a post.
 * @param mixed    $argument What the purge takes: a post ID, or a list of them.
 */
function swiftling_purge_with_post_urls( $filter, $urls, $purge, $argument ) {
	$add = static function ( $collected ) use ( $urls ) {
		return array_values( array_unique( array_merge( (array) $collected, $urls ) ) );
	};
	add_filter( $filter, $add );
	try {
		call_user_func( $purge, $argument );
	} finally {
		remove_filter( $filter, $add );
	}
}
