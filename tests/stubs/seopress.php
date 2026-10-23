<?php
/**
 * A stand-in for the function of SEOPress that builds its sitemap index, so SwiftLing's index of
 * every language can be tested without the plugin. It builds the index as SEOPress 10.3 does in
 * inc/functions/sitemap/template-xml-sitemaps.php: a sitemap of each post type and taxonomy its
 * settings include, also of a post type without posts, 1000 posts each, counted as its
 * SitemapPostCount counts them, with the filters suppressed unless the arguments say otherwise; the
 * last change of each from SQL of its own; the author sitemap; and the sitemaps other plugins add
 * after seopress_sitemaps_xml_index_item.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery -- The plugin's own names, hooks and SQL.

/**
 * Returns SEOPress's sitemap index.
 *
 * @return string XML.
 */
function seopress_xml_sitemap_index() {
	global $wpdb;
	$home_url = apply_filters( 'seopress_sitemaps_home_url', home_url() . '/' );
	$options  = (array) get_option( 'seopress_xml_sitemap_option_name', array() );
	$xml      = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

	$types = isset( $options['seopress_xml_sitemap_post_types_list'] ) ? (array) $options['seopress_xml_sitemap_post_types_list'] : array();
	foreach ( $types as $type => $flags ) {
		if ( ! post_type_exists( $type ) || ! isset( $flags['include'] ) || '1' !== $flags['include'] ) {
			continue;
		}
		$args                   = array(
			'posts_per_page' => -1,
			'post_type'      => $type,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'lang'           => '',
			'has_password'   => false,
		);
		$args                   = wp_parse_args( apply_filters( 'seopress_sitemaps_index_post_types_query', $args, $type ), array( 'suppress_filters' => true ) );
		$args['posts_per_page'] = 1;
		$args['no_found_rows']  = false;
		$count                  = ( new WP_Query( $args ) )->found_posts;
		$max                    = apply_filters( 'seopress_sitemaps_max_posts_per_sitemap', 1000 );
		$pages                  = $count >= $max ? $count / $max : 1;
		$lastmod                = $wpdb->get_var( $wpdb->prepare( "SELECT MAX( post_modified ) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $type ) );
		for ( $i = 0; $i < $pages; ++$i ) {
			$xml .= "\n<sitemap>\n<loc>" . $home_url . $type . '-sitemap' . ( $i + 1 ) . ".xml</loc>\n";
			$xml .= 0 === $i && $lastmod ? '<lastmod>' . esc_html( mysql2date( 'c', $lastmod ) ) . "</lastmod>\n" : '';
			$xml .= '</sitemap>';
		}
	}

	$taxonomies = isset( $options['seopress_xml_sitemap_taxonomies_list'] ) ? (array) $options['seopress_xml_sitemap_taxonomies_list'] : array();
	foreach ( $taxonomies as $taxonomy => $flags ) {
		if ( ! taxonomy_exists( $taxonomy ) || ! isset( $flags['include'] ) || '1' !== $flags['include'] ) {
			continue;
		}
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'lang'       => '',
			'fields'     => 'ids',
		);
		$args = apply_filters( 'seopress_sitemaps_index_tax_query', $args, $taxonomy );
		get_terms( $args );
		$xml .= "\n<sitemap>\n<loc>" . $home_url . $taxonomy . "-sitemap1.xml</loc>\n</sitemap>";
	}

	if ( isset( $options['seopress_xml_sitemap_author_enable'] ) && '1' === $options['seopress_xml_sitemap_author_enable'] ) {
		$xml .= "\n<sitemap>\n<loc>" . $home_url . "author.xml</loc>\n</sitemap>";
	}

	$xml = apply_filters( 'seopress_sitemaps_xml_index_item', $xml, $home_url );

	foreach ( (array) apply_filters( 'seopress_sitemaps_external_link', null ) as $sitemap ) {
		$xml .= "\n<sitemap>\n<loc>" . $sitemap['sitemap_url'] . "</loc>\n</sitemap>";
	}
	$xml .= "\n</sitemapindex>";

	return apply_filters( 'seopress_sitemaps_xml_index', $xml );
}
