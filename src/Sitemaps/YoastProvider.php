<?php
/**
 * The sitemaps of every language in Yoast SEO's sitemap index.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Sitemaps;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the sitemaps of post types and taxonomies of every language in Yoast SEO's sitemap index,
 * see sitemaps.php. It builds no sitemap itself: each language's sitemaps are Yoast SEO's own, asked
 * for in that language.
 */
final class YoastProvider implements \WPSEO_Sitemap_Provider {
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
	 * Returns the sitemaps of every language for the index: those of Yoast SEO's providers of post
	 * types and taxonomies, counted and addressed in each language.
	 *
	 * @param int $max_entries Addresses per sitemap.
	 * @return array[] Links with the address in loc and the last change in lastmod.
	 */
	public function get_index_links( $max_entries ) {
		$sitemaps = isset( $GLOBALS['wpseo_sitemaps'] ) ? $GLOBALS['wpseo_sitemaps'] : null;
		if ( '' !== swiftling_sitemap_language() || ! $sitemaps instanceof \WPSEO_Sitemaps || ! is_array( $sitemaps->providers ) ) {
			return array();
		}

		$links = array();
		foreach ( swiftling_sitemap_languages() as $slug ) {
			foreach ( $sitemaps->providers as $provider ) {
				if ( ! $provider instanceof \WPSEO_Post_Type_Sitemap_Provider && ! $provider instanceof \WPSEO_Taxonomy_Sitemap_Provider ) {
					continue;
				}
				swiftling_in_sitemap_language(
					$slug,
					static function () use ( $provider, $max_entries, &$links ) {
						foreach ( $provider->get_index_links( $max_entries ) as $link ) {
							$link['loc'] = swiftling_sitemap_url( $link['loc'] );
							$links[]     = $link;
						}
					}
				);
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
