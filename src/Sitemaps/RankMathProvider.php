<?php
/**
 * The sitemaps of every language in Rank Math's sitemap index.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Sitemaps;

use RankMath\Sitemap\Providers\Post_Type;
use RankMath\Sitemap\Providers\Provider;
use RankMath\Sitemap\Providers\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the sitemaps of post types and taxonomies of every language in Rank Math's sitemap index,
 * see sitemaps.php. It builds no sitemap itself: each language's sitemaps are Rank Math's own, asked
 * for in that language.
 */
final class RankMathProvider implements Provider {
	/**
	 * Builds no sitemap.
	 *
	 * @param string $type Type of sitemap.
	 * @return bool
	 */
	public function handles_type( $type ) {
		return false;
	}

	/**
	 * Returns the sitemaps of every language for the index: those of Rank Math's providers of post
	 * types and taxonomies, counted in each language. Rank Math passes each through
	 * rank_math/sitemap/index/entry, which gives it the language's address.
	 *
	 * @param int $max_entries Addresses per sitemap.
	 * @return array[] Links with the address in loc and the last change in lastmod.
	 */
	public function get_index_links( $max_entries ) {
		if ( '' !== swiftling_sitemap_language() ) {
			return array();
		}

		// Rank Math keeps its providers to itself; these have no state of their own.
		$providers = array( new Post_Type(), new Taxonomy() );
		$links     = array();
		foreach ( swiftling_sitemap_languages() as $slug ) {
			foreach ( $providers as $provider ) {
				$found = swiftling_in_sitemap_language(
					$slug,
					static function () use ( $provider, $max_entries ) {
						return $provider->get_index_links( $max_entries );
					}
				);
				array_push( $links, ...array_values( (array) $found ) );
			}
		}
		return $links;
	}

	/**
	 * Builds no sitemap.
	 *
	 * @param string $type         Type of sitemap.
	 * @param int    $max_entries  Addresses per sitemap.
	 * @param int    $current_page Page of the sitemap.
	 * @return array
	 */
	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		return array();
	}
}
