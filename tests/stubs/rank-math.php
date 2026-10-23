<?php
/**
 * Stand-ins for the sitemap classes of Rank Math that SwiftLing uses, so its own provider can be
 * tested without the plugin. The provider of post types counts published pages through Rank
 * Math's filter and lists one sitemap, as Rank Math 1.0.280 does.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidHookName.UseUnderscores, Generic.Files.OneObjectStructurePerFile, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- The plugin's own names and hooks, in one file for the tests.

namespace RankMath\Sitemap\Providers;

/**
 * Rank Math's interface of a sitemap provider.
 */
interface Provider {
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
 * Rank Math's provider of post type sitemaps: pages only.
 */
class Post_Type implements Provider {
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
	 * Lists the sitemap of pages when there are pages, counted with
	 * rank_math/sitemap/post_count/where, each link passed through rank_math/sitemap/index/entry.
	 *
	 * @param int $max_entries Addresses per sitemap.
	 * @return array[]
	 */
	public function get_index_links( $max_entries ) {
		global $wpdb;
		$where = apply_filters( 'rank_math/sitemap/post_count/where', '', 'page' );
		$count = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} as p WHERE p.post_type = 'page' AND p.post_status = 'publish' {$where}" );
		return $count ? array( apply_filters( 'rank_math/sitemap/index/entry', array( 'loc' => home_url( '/page-sitemap.xml' ) ), 'post', 'page' ) ) : array();
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
 * Rank Math's provider of taxonomy sitemaps: categories only.
 */
class Taxonomy extends Post_Type {
	/**
	 * Lists the sitemap of categories.
	 *
	 * @param int $max_entries Addresses per sitemap.
	 * @return array[]
	 */
	public function get_index_links( $max_entries ) {
		return array( apply_filters( 'rank_math/sitemap/index/entry', array( 'loc' => home_url( '/category-sitemap.xml' ) ), 'term', 'category' ) );
	}
}
