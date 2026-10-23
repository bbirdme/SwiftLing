<?php
/**
 * The sitemaps of every language in WordPress's sitemap index.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Sitemaps;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the sitemaps of posts and taxonomies of every language in WordPress's sitemap index, see
 * sitemaps.php. It builds no sitemap itself: each language's sitemaps are WordPress's own, asked for
 * in that language.
 */
final class CoreProvider extends \WP_Sitemaps_Provider {
	/**
	 * Names the provider.
	 */
	public function __construct() {
		$this->name        = 'swiftling';
		$this->object_type = 'swiftling';
	}

	/**
	 * Returns the sitemaps of every language for the index: those of WordPress's providers of posts
	 * and taxonomies, counted and addressed in each language.
	 *
	 * @return array[] Entries with the address in loc.
	 */
	public function get_sitemap_entries() {
		$entries   = array();
		$providers = wp_get_sitemap_providers();
		foreach ( swiftling_sitemap_languages() as $slug ) {
			foreach ( array( 'posts', 'taxonomies' ) as $name ) {
				if ( isset( $providers[ $name ] ) ) {
					array_push( $entries, ...swiftling_in_sitemap_language( $slug, array( $providers[ $name ], 'get_sitemap_entries' ) ) );
				}
			}
		}
		return $entries;
	}

	/**
	 * Lists no addresses: an address of this provider is not found.
	 *
	 * @param int    $page_num       Page of the sitemap.
	 * @param string $object_subtype Subtype.
	 * @return array[]
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		return array();
	}

	/**
	 * Has no pages of its own.
	 *
	 * @param string $object_subtype Subtype.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return 0;
	}
}
