<?php
/**
 * Stand-ins for the sitemap classes of Yoast SEO that SwiftLing uses, so its own provider can be
 * tested without the plugin. The provider of post types counts published pages through Yoast
 * SEO's filter and lists one sitemap, as Yoast SEO 28.6 does.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidHookName.UseUnderscores, Generic.Files.OneObjectStructurePerFile, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- The plugin's own names and hooks, in one file for the tests.

/**
 * Yoast SEO's interface of a sitemap provider.
 */
interface WPSEO_Sitemap_Provider {
	/**
	 * Whether it builds a type of sitemap.
	 *
	 * @param string $type Type.
	 */
	public function handles_type( $type );

	/**
	 * Returns its sitemaps for the index.
	 *
	 * @param int $max_entries Addresses per sitemap.
	 */
	public function get_index_links( $max_entries );

	/**
	 * Returns the addresses of a sitemap.
	 *
	 * @param string $type         Type.
	 * @param int    $max_entries  Addresses per sitemap.
	 * @param int    $current_page Page.
	 */
	public function get_sitemap_links( $type, $max_entries, $current_page );
}

/**
 * Yoast SEO's sitemaps, with their providers.
 */
class WPSEO_Sitemaps {
	/**
	 * Providers.
	 *
	 * @var WPSEO_Sitemap_Provider[]
	 */
	public $providers = array();
}

/**
 * Yoast SEO's provider of post type sitemaps: pages only.
 */
class WPSEO_Post_Type_Sitemap_Provider implements WPSEO_Sitemap_Provider {
	/**
	 * Builds pages.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public function handles_type( $type ) {
		return 'page' === $type;
	}

	/**
	 * Lists the sitemap of pages when there are pages, counted with wpseo_typecount_where.
	 *
	 * @param int $max_entries Addresses per sitemap.
	 * @return array[]
	 */
	public function get_index_links( $max_entries ) {
		global $wpdb;
		$where = apply_filters( 'wpseo_typecount_where', '', 'page' );
		$count = (int) $wpdb->get_var( "SELECT COUNT({$wpdb->posts}.ID) FROM {$wpdb->posts} WHERE {$wpdb->posts}.post_type = 'page' AND {$wpdb->posts}.post_status = 'publish' {$where}" );
		return $count ? array(
			array(
				'loc'     => home_url( '/page-sitemap.xml' ),
				'lastmod' => '2026-10-01 00:00:00',
			),
		) : array();
	}

	/**
	 * Builds nothing here.
	 *
	 * @param string $type         Type.
	 * @param int    $max_entries  Addresses per sitemap.
	 * @param int    $current_page Page.
	 * @return array
	 */
	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		return array();
	}
}

/**
 * Yoast SEO's provider of taxonomy sitemaps: categories only.
 */
class WPSEO_Taxonomy_Sitemap_Provider extends WPSEO_Post_Type_Sitemap_Provider {
	/**
	 * Lists the sitemap of categories.
	 *
	 * @param int $max_entries Addresses per sitemap.
	 * @return array[]
	 */
	public function get_index_links( $max_entries ) {
		return array( array( 'loc' => home_url( '/category-sitemap.xml' ) ) );
	}
}

/**
 * Yoast SEO's provider of author sitemaps, which is no provider of post types.
 */
class WPSEO_Author_Sitemap_Provider implements WPSEO_Sitemap_Provider {
	/**
	 * Builds authors.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public function handles_type( $type ) {
		return 'author' === $type;
	}

	/**
	 * Lists the sitemap of authors.
	 *
	 * @param int $max_entries Addresses per sitemap.
	 * @return array[]
	 */
	public function get_index_links( $max_entries ) {
		return array( array( 'loc' => home_url( '/author-sitemap.xml' ) ) );
	}

	/**
	 * Builds nothing here.
	 *
	 * @param string $type         Type.
	 * @param int    $max_entries  Addresses per sitemap.
	 * @param int    $current_page Page.
	 * @return array
	 */
	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		return array();
	}
}
