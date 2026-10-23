<?php
/**
 * Sitemaps in every language: WordPress's own (wp-sitemap.xml), and Yoast SEO's, Rank Math's,
 * SEOPress's and All in One SEO's, which replace it while they are active.
 *
 * A language's sitemaps are the site's sitemaps asked for in that language, such as
 * /de/wp-sitemap-posts-page-1.xml or /page-sitemap.xml?lang=de. They are built like every page in
 * the language, so their addresses carry the language and its translated slugs, see slugs.php.
 * The sitemap index lists the sitemaps of every language next to the original's, so search engines
 * need only the one index. Asked for in a language, the index sends the visitor to that one.
 *
 * With a host per language, the subdomain and domain formats, each host has an index of its own:
 * Search Console takes each host as a site of its own, and a sitemap only of addresses of its host.
 * The site address lists the original's sitemaps, a language's host its own, without an author
 * sitemap, and its robots.txt names it, as WordPress and the SEO plugins build it there.
 *
 * A language's sitemaps list what hreflang names in it, see hreflang.php:
 *
 * - the posts translated into it, as the swiftling_translated table says: their texts are
 *   translated, or they have a published separate copy or a duplicate there, see visibility.php;
 * - the page of posts and the front page that lists the posts, which every language has;
 * - the archives of categories, tags and other taxonomies, and of post types where the SEO plugin
 *   lists them;
 * - no separate copies, whose address is their original's in their language, and no author
 *   archives, which hreflang does not name either: a language has no author sitemap.
 *
 * Languages hidden from visitors have no sitemaps.
 *
 * Yoast SEO and Rank Math read posts with SQL of their own, which the query filters of
 * visibility.php and copies.php do not reach, so their sitemaps get the same conditions through
 * their own filters, checked in Yoast SEO 28.6 and Rank Math 1.0.280:
 *
 * - posts: wpseo_posts_where and wpseo_typecount_where; rank_math/sitemap/get_posts/where and
 *   rank_math/sitemap/post_count/where. This also keeps copies out of the original's sitemaps;
 * - addresses: wpseo_sitemap_url and rank_math/sitemap/url, for each address a sitemap prints,
 *   since some, such as the site address in the first sitemap of posts, pass no other filter;
 * - the index: an extra provider (wpseo_sitemaps_providers, rank_math/sitemap/providers) that lists
 *   the sitemaps of every language;
 * - caching: both keep a built sitemap by its type and page, not by language, so a language's
 *   sitemaps are built every time. The cached index is cleared when the languages of a post change,
 *   and every cached sitemap when the languages or their address format do.
 *
 * SEOPress reads posts with WordPress's queries, with their filters suppressed, and builds its index
 * in one function, checked in SEOPress 10.3:
 *
 * - posts: seopress_sitemaps_single_query and seopress_sitemaps_index_post_types_query let the
 *   filters of the query run, and mark it as a sitemap's, as WordPress's own;
 * - requests: its own query variables, see swiftling_sitemap_request();
 * - addresses: seopress_sitemaps_url, for each address a sitemap prints, as for the others, and
 *   seopress_sitemaps_single_url for the front page;
 * - the index: seopress_sitemaps_xml_index_item, see swiftling_seopress_sitemap_index();
 * - caching: it keeps no sitemap, only the last change of each, which is the original's.
 *
 * All in One SEO reads posts with SQL of its own and names its sitemaps by the address, checked in
 * AIOSEO 5.0.3:
 *
 * - posts: aioseo_sitemap_exclude_posts, the only way into its SQL, names the posts to leave out;
 * - requests: by the address, see swiftling_aioseo_requested_sitemap();
 * - addresses: the filters of each entry, as aioseo_sitemap_post;
 * - the index: aioseo_sitemap_indexes, see swiftling_aioseo_sitemap_index();
 * - caching: it keeps no sitemap. What its stylesheet shows of a sitemap, such as how many
 *   addresses it lists, it keeps by the sitemap's name, so a browser may show a language's there.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the language of the sitemap being built: the page's language in a sitemap request, or the
 * language swiftling_in_sitemap_language() asks for while the index lists that language's sitemaps.
 *
 * @return string Address slug of a target language, or an empty string for the original.
 */
function swiftling_sitemap_language() {
	$override = &swiftling_sitemap_language_override();
	if ( null !== $override ) {
		return $override;
	}
	$state = &swiftling_state();
	return 'translate' === $state['mode'] ? (string) $state['language'] : '';
}

/**
 * Returns the language swiftling_in_sitemap_language() sets for a moment.
 *
 * @return string|null Address slug, or null when none is set.
 */
function &swiftling_sitemap_language_override() {
	static $slug = null;
	return $slug;
}

/**
 * Builds part of a sitemap in a language: its posts, and its addresses.
 *
 * @param string   $slug  Address slug of a target language, or an empty string for the original.
 * @param callable $build Builds it.
 * @return mixed What $build returns.
 */
function swiftling_in_sitemap_language( $slug, $build ) {
	$override = &swiftling_sitemap_language_override();
	$previous = $override;
	$override = (string) $slug;
	try {
		return call_user_func( $build );
	} finally {
		$override = $previous;
	}
}

/**
 * Returns the locale of the sitemap being built.
 *
 * @return string Locale of a target language, or an empty string for the original.
 */
function swiftling_sitemap_locale() {
	$slug     = swiftling_sitemap_language();
	$language = '' === $slug ? null : swiftling_target_language( $slug );
	return $language ? (string) $language['locale'] : '';
}

/**
 * Returns the languages that have sitemaps: the targets visitors see, in the order the Languages
 * screen sets.
 *
 * @return string[] Address slugs.
 */
function swiftling_sitemap_languages() {
	// With a host per language, each host lists its own sitemaps alone.
	if ( swiftling_is_host_format() ) {
		return array();
	}
	$slugs = array();
	foreach ( swiftling_ordered_languages() as $language ) {
		if ( ! $language['source'] && $language['visible'] && $language['reachable'] ) {
			$slugs[] = (string) $language['slug'];
		}
	}
	return $slugs;
}

/**
 * Returns an address of this site in the language of the sitemap being built: without the
 * language it was built with, if any, and with the sitemap's language. So the parameter
 * format gets its ?lang=, and in an original that has a folder of its own, the addresses of the
 * sitemaps at the site address get that folder. Asking twice changes nothing.
 *
 * @param string $url Address.
 * @return string
 */
function swiftling_sitemap_url( $url ) {
	if ( ! is_string( $url ) || '' === $url || ! swiftling_is_own_address( $url ) ) {
		return $url;
	}
	return swiftling_language_url( swiftling_address_without_language( $url ), swiftling_sitemap_language() );
}

/**
 * Returns the condition that keeps a language's sitemaps to the posts it lists, and every sitemap
 * free of separate copies.
 *
 * @param string $alias Alias of the posts table in the query.
 * @return string SQL starting with AND, or an empty string.
 */
function swiftling_sitemap_posts_sql( $alias ) {
	global $wpdb;
	$sql = '';
	if ( swiftling_copy_map() ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The alias is a fixed name from the caller.
		$sql .= $wpdb->prepare( " AND {$alias}.ID NOT IN ( SELECT post_id FROM %i WHERE meta_key = %s )", $wpdb->postmeta, SWIFTLING_COPY_OF );
	}
	return $sql . swiftling_sitemap_language_sql( $alias );
}

/**
 * Returns the condition that keeps a language's sitemaps to the posts it lists.
 *
 * @param string $alias Alias of the posts table in the query.
 * @return string SQL starting with AND, or an empty string in the original's sitemaps.
 */
function swiftling_sitemap_language_sql( $alias ) {
	global $wpdb;
	$locale = swiftling_sitemap_locale();
	if ( '' === $locale ) {
		return '';
	}
	// The page of posts lists the posts of every language, as hreflang says.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The alias is a fixed name from the caller.
	$sql = $wpdb->prepare( " AND ( {$alias}.ID IN ( SELECT post_id FROM %i WHERE lang = %s ) OR {$alias}.ID = %d )", swiftling_table( 'translated' ), $locale, (int) get_option( 'page_for_posts' ) );
	// A type set to Never shows only its original, which the original's sitemaps list.
	$never = swiftling_settings()['never'];
	if ( $never ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The alias is a fixed name; one placeholder per type.
		$sql .= $wpdb->prepare( " AND {$alias}.post_type NOT IN (" . implode( ',', array_fill( 0, count( $never ), '%s' ) ) . ')', ...$never );
	}
	return $sql;
}

/**
 * Sends a request for a sitemap index in a language to the index itself, which lists every
 * language, and finds no author sitemap in a language. A language's other sitemaps build their
 * links with its slugs, also in the parameter format, where the links of a page keep the
 * original's, see swiftling_link_locale(). Run once WordPress has read the address, before Yoast
 * SEO, Rank Math and SEOPress answer a sitemap request, on pre_get_posts and parse_query.
 *
 * WordPress names its sitemaps with the sitemap query variable (index, posts, taxonomies, users),
 * and Yoast SEO and Rank Math use the same variable (1 for the index, author, a post type or a
 * taxonomy). SEOPress has variables of its own: seopress_sitemap for the index, seopress_author,
 * and seopress_cpt for a post type or a taxonomy. All in One SEO reads the address, see
 * swiftling_aioseo_requested_sitemap().
 *
 * @param WP $wp Request.
 */
function swiftling_sitemap_request( $wp ) {
	$locale  = swiftling_sitemap_locale();
	$request = isset( $wp->request ) ? (string) $wp->request : '';
	$kind    = swiftling_requested_sitemap( (array) $wp->query_vars, $request );
	if ( '' === $locale || '' === $kind ) {
		return;
	}
	if ( '' !== swiftling_aioseo_requested_sitemap( $request ) ) {
		add_filter( 'wp_redirect', 'swiftling_aioseo_sitemap_redirect' );
	}
	if ( 'index' === $kind ) {
		// On a language's host the index is its own.
		if ( swiftling_is_host_format() ) {
			$links = &swiftling_link_locale_override();
			$links = $locale;
			return;
		}
		wp_safe_redirect( swiftling_current_original_url(), 301, 'SwiftLing' );
		exit;
	}
	if ( 'author' === $kind ) {
		$wp->query_vars = array( 'error' => '404' );
		return;
	}
	$links = &swiftling_link_locale_override();
	$links = $locale;
}
add_action( 'parse_request', 'swiftling_sitemap_request', 1 );

/**
 * Returns what kind of sitemap a request asks for, see swiftling_sitemap_request().
 *
 * @param array  $vars    Query variables.
 * @param string $request Path of the request, as WordPress read it, without the language.
 * @return string index, author, sitemap for any other, or an empty string for no sitemap.
 */
function swiftling_requested_sitemap( $vars, $request = '' ) {
	$named   = static function ( $name ) use ( $vars ) {
		return isset( $vars[ $name ] ) && is_string( $vars[ $name ] ) ? $vars[ $name ] : '';
	};
	$sitemap = $named( 'sitemap' );
	if ( '' !== $sitemap ) {
		if ( in_array( $sitemap, array( 'index', '1' ), true ) ) {
			return 'index';
		}
		return in_array( $sitemap, array( 'users', 'author' ), true ) ? 'author' : 'sitemap';
	}
	if ( '1' === $named( 'seopress_sitemap' ) ) {
		return 'index';
	}
	if ( '1' === $named( 'seopress_author' ) ) {
		return 'author';
	}
	if ( '' !== $named( 'seopress_cpt' ) ) {
		return 'sitemap';
	}
	return swiftling_aioseo_requested_sitemap( (string) $request );
}

/**
 * Returns what kind of All in One SEO's sitemaps a request asks for. AIOSEO names them by the
 * address, not by a query variable (Sitemap\RequestParser, checked in AIOSEO 5.0.3): its index,
 * named in its settings (sitemap.xml), the sitemaps of a post type, a taxonomy or the authors
 * (page-sitemap.xml, author-sitemap2.xml), and that of its latest posts (sitemap.rss). Where its
 * index lists every address itself, it is a sitemap like any other.
 *
 * @param string $request Path of the request, without the language.
 * @return string index, author, sitemap, or an empty string for none of AIOSEO's.
 */
function swiftling_aioseo_requested_sitemap( $request ) {
	if ( '' === $request || ! function_exists( 'aioseo' ) || ! isset( aioseo()->sitemap->helpers ) ) {
		return '';
	}
	if ( preg_match( '/^sitemap(\.latest)?\.rss$/i', $request ) ) {
		return 'sitemap';
	}
	$name = preg_quote( (string) aioseo()->sitemap->helpers->filename( 'general' ), '/' );
	if ( '' === $name || ! aioseo()->options->sitemap->general->enable ) {
		return '';
	}
	if ( preg_match( "/^{$name}\.xml(\.gz)?$/i", $request ) ) {
		return aioseo()->options->sitemap->general->indexes ? 'index' : 'sitemap';
	}
	if ( preg_match( "/^(.+)-{$name}\d*\.xml(\.gz)?$/i", $request, $found ) ) {
		return 'author' === strtolower( $found[1] ) ? 'author' : 'sitemap';
	}
	return '';
}

/*
 * WordPress's sitemaps.
 */

/**
 * Lists the sitemaps of every language in WordPress's sitemap index.
 *
 * @param WP_Sitemaps $sitemaps WordPress's sitemaps.
 */
function swiftling_register_sitemap_provider( $sitemaps ) {
	if ( '' !== swiftling_sitemap_language() || ! $sitemaps->sitemaps_enabled() || ! swiftling_sitemap_languages() ) {
		return;
	}
	$sitemaps->registry->add_provider( 'swiftling', new \SwiftLing\Sitemaps\CoreProvider() );
}
add_action( 'wp_sitemaps_init', 'swiftling_register_sitemap_provider' );

/**
 * Leaves the sitemap of users out of WordPress's sitemaps in a language, whose index a language's
 * host shows: a language has no author archives.
 *
 * @param WP_Sitemaps_Provider|false $provider Provider.
 * @param string                     $name     Its name.
 * @return WP_Sitemaps_Provider|false
 */
function swiftling_sitemap_users_provider( $provider, $name ) {
	return 'users' === $name && '' !== swiftling_sitemap_language() ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'swiftling_sitemap_users_provider', 10, 2 );

/**
 * Leaves the author sitemaps out of Yoast SEO's index in a language, as above.
 *
 * @param array $links Links of the index, with the address in loc.
 * @return array
 */
function swiftling_yoast_sitemap_index_links( $links ) {
	if ( '' === swiftling_sitemap_language() || ! is_array( $links ) ) {
		return $links;
	}
	return array_values(
		array_filter(
			$links,
			static function ( $link ) {
				return ! isset( $link['loc'] ) || ! preg_match( '~/author-sitemap\d*\.xml~', (string) $link['loc'] );
			}
		)
	);
}
add_filter( 'wpseo_sitemap_index_links', 'swiftling_yoast_sitemap_index_links' );

/**
 * Marks the queries of WordPress's sitemaps of posts, see swiftling_filter_sitemap_where().
 *
 * @param array $args Arguments of the query.
 * @return array
 */
function swiftling_sitemap_query_args( $args ) {
	$args['swiftling_sitemap'] = true;
	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'swiftling_sitemap_query_args' );

/**
 * Adds the condition of swiftling_sitemap_language_sql() to the queries of WordPress's sitemaps.
 * Copies are already left out, see swiftling_filter_copies_where().
 *
 * @param string   $where WHERE clause of the query.
 * @param WP_Query $query Query.
 * @return string
 */
function swiftling_filter_sitemap_where( $where, $query ) {
	global $wpdb;
	return $query->get( 'swiftling_sitemap' ) ? $where . swiftling_sitemap_language_sql( $wpdb->posts ) : $where;
}
add_filter( 'posts_where', 'swiftling_filter_sitemap_where', 10, 2 );

/**
 * Puts a post's address in WordPress's sitemaps into the sitemap's language.
 *
 * @param array   $entry Entry, with the address in loc.
 * @param WP_Post $post  Post.
 * @return array
 */
function swiftling_sitemap_post_entry( $entry, $post ) {
	if ( is_array( $entry ) && isset( $entry['loc'] ) ) {
		$entry['loc'] = swiftling_sitemap_url( swiftling_sitemap_front_page_url( $entry['loc'], $post ) );
	}
	return $entry;
}
add_filter( 'wp_sitemaps_posts_entry', 'swiftling_sitemap_post_entry', 10, 2 );

/**
 * Puts an address in WordPress's sitemaps or sitemap index into the sitemap's language.
 *
 * @param array $entry Entry, with the address in loc.
 * @return array
 */
function swiftling_sitemap_entry( $entry ) {
	if ( is_array( $entry ) && isset( $entry['loc'] ) ) {
		$entry['loc'] = swiftling_sitemap_url( $entry['loc'] );
	}
	return $entry;
}
add_filter( 'wp_sitemaps_posts_show_on_front_entry', 'swiftling_sitemap_entry' );
add_filter( 'wp_sitemaps_taxonomies_entry', 'swiftling_sitemap_entry' );
add_filter( 'wp_sitemaps_index_entry', 'swiftling_sitemap_entry' );

/**
 * Gives the front page the site address. Where a separate copy is the front page of a language,
 * WordPress takes the copy for the front page there and would give the original its own slug.
 *
 * @param string       $url  Address of the post.
 * @param WP_Post|null $post Post.
 * @return string
 */
function swiftling_sitemap_front_page_url( $url, $post ) {
	return $post instanceof WP_Post && swiftling_real_front_page() === $post->ID ? home_url( '/' ) : $url;
}

/*
 * Yoast SEO and Rank Math.
 */

/**
 * Adds the condition of swiftling_sitemap_posts_sql() to the queries of Yoast SEO's sitemaps.
 *
 * @param string|false $where Condition from other filters, false when there is none.
 * @return string
 */
function swiftling_filter_yoast_sitemap_where( $where ) {
	global $wpdb;
	return ( is_string( $where ) ? $where : '' ) . swiftling_sitemap_posts_sql( $wpdb->posts );
}
add_filter( 'wpseo_posts_where', 'swiftling_filter_yoast_sitemap_where' );
add_filter( 'wpseo_typecount_where', 'swiftling_filter_yoast_sitemap_where' );

/**
 * Adds the condition of swiftling_sitemap_posts_sql() to the queries of Rank Math's sitemaps, which
 * name the posts table p.
 *
 * @param string $where Condition from other filters.
 * @return string
 */
function swiftling_filter_rank_math_sitemap_where( $where ) {
	return ( is_string( $where ) ? $where : '' ) . swiftling_sitemap_posts_sql( 'p' );
}
add_filter( 'rank_math/sitemap/get_posts/where', 'swiftling_filter_rank_math_sitemap_where' );
add_filter( 'rank_math/sitemap/post_count/where', 'swiftling_filter_rank_math_sitemap_where' );

add_filter( 'wpseo_xml_sitemap_post_url', 'swiftling_sitemap_front_page_url', 10, 2 );
add_filter( 'rank_math/sitemap/xml_post_url', 'swiftling_sitemap_front_page_url', 10, 2 );

/**
 * Puts the addresses of an entry of a Yoast SEO or Rank Math sitemap into the sitemap's language,
 * as it is printed: the loc of the page, not of its images.
 *
 * @param string $output XML of the entry.
 * @return string
 */
function swiftling_sitemap_output_url( $output ) {
	if ( ! is_string( $output ) || false === strpos( $output, '<loc>' ) ) {
		return $output;
	}
	return (string) preg_replace_callback(
		'~<loc>([^<]*)</loc>~',
		static function ( $found ) {
			$url = html_entity_decode( $found[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
			$new = swiftling_sitemap_url( $url );
			return $new === $url ? $found[0] : '<loc>' . htmlspecialchars( $new, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</loc>';
		},
		$output
	);
}
add_filter( 'wpseo_sitemap_url', 'swiftling_sitemap_output_url' );
add_filter( 'rank_math/sitemap/url', 'swiftling_sitemap_output_url' );
add_filter( 'rank_math/sitemap/index/entry', 'swiftling_sitemap_entry' );

/**
 * Lists the sitemaps of every language in Yoast SEO's sitemap index.
 *
 * @param array $providers Providers of other plugins.
 * @return array
 */
function swiftling_yoast_sitemap_providers( $providers ) {
	$providers   = is_array( $providers ) ? $providers : array();
	$providers[] = new \SwiftLing\Sitemaps\YoastProvider();
	return $providers;
}
add_filter( 'wpseo_sitemaps_providers', 'swiftling_yoast_sitemap_providers' );

/**
 * Lists the sitemaps of every language in Rank Math's sitemap index.
 *
 * @param array $providers Providers of other plugins.
 * @return array
 */
function swiftling_rank_math_sitemap_providers( $providers ) {
	$providers   = is_array( $providers ) ? $providers : array();
	$providers[] = new \SwiftLing\Sitemaps\RankMathProvider();
	return $providers;
}
add_filter( 'rank_math/sitemap/providers', 'swiftling_rank_math_sitemap_providers' );

/*
 * SEOPress.
 */

/**
 * Lets SEOPress's queries of the posts of a sitemap, and of their count in its index, through the
 * filters of a query, which SEOPress suppresses, and marks them, see swiftling_filter_sitemap_where():
 * so they leave copies out, and a language's keep to the posts it lists.
 *
 * @param array $args Arguments of the query.
 * @return array
 */
function swiftling_seopress_sitemap_query( $args ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}
	$args['suppress_filters']  = false;
	$args['swiftling_sitemap'] = true;
	return $args;
}
add_filter( 'seopress_sitemaps_single_query', 'swiftling_seopress_sitemap_query' );
add_filter( 'seopress_sitemaps_index_post_types_query', 'swiftling_seopress_sitemap_query' );

/**
 * Lists the sitemaps of every language in SEOPress's sitemap index, after the original's, as
 * SEOPress lists them in each language. SEOPress builds its index in one function,
 * seopress_xml_sitemap_index(), with no part to ask for alone; SwiftLing builds it again in each
 * language and takes the sitemaps this filter gets there, before the sitemaps other plugins add,
 * with the language's addresses. In a language, as on a language's host, the author sitemap is
 * left out.
 *
 * SEOPress lists a sitemap of each post type its settings include, also of one without posts, and
 * gives the first of each the last change of the original's posts, from SQL of its own; so does
 * each language.
 *
 * @param string $xml      The entries of the index so far.
 * @param string $home_url Site address the entries start with.
 * @return string
 */
function swiftling_seopress_sitemap_index( $xml, $home_url ) {
	unset( $home_url );
	if ( ! is_string( $xml ) ) {
		return $xml;
	}
	$built = &swiftling_seopress_language_index();
	if ( '' !== swiftling_sitemap_language() ) {
		$xml   = (string) preg_replace( '~\s*<sitemap>\s*<loc>[^<]*/author\.xml</loc>.*?</sitemap>~s', '', $xml );
		$built = $xml;
		return $xml;
	}
	if ( ! function_exists( 'seopress_xml_sitemap_index' ) ) {
		return $xml;
	}
	foreach ( swiftling_sitemap_languages() as $slug ) {
		$built = null;
		try {
			swiftling_in_sitemap_language( $slug, 'seopress_xml_sitemap_index' );
			$entries = (string) $built;
		} finally {
			$built = null;
		}
		preg_match_all( '~<sitemap>.*?</sitemap>~s', $entries, $found );
		foreach ( $found[0] as $entry ) {
			$xml .= "\n" . swiftling_in_sitemap_language(
				$slug,
				static function () use ( $entry ) {
					return swiftling_sitemap_output_url( $entry );
				}
			);
		}
	}
	return $xml;
}
add_filter( 'seopress_sitemaps_xml_index_item', 'swiftling_seopress_sitemap_index', 10, 2 );

/**
 * Returns the entries SEOPress's index listed in a language, while
 * swiftling_seopress_sitemap_index() builds it there.
 *
 * @return string|null Null while it builds none.
 */
function &swiftling_seopress_language_index() {
	static $built = null;
	return $built;
}

/**
 * Gives the front page the site address in SEOPress's sitemaps, see
 * swiftling_sitemap_front_page_url(), escaped as SEOPress escapes it.
 *
 * @param array   $url  Entry, with the address in loc.
 * @param WP_Post $post Post.
 * @return array
 */
function swiftling_seopress_sitemap_post_url( $url, $post ) {
	$front = swiftling_sitemap_front_page_url( '', $post );
	if ( is_array( $url ) && isset( $url['loc'] ) && '' !== $front ) {
		$url['loc'] = htmlspecialchars( urldecode( $front ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
	}
	return $url;
}
add_filter( 'seopress_sitemaps_single_url', 'swiftling_seopress_sitemap_post_url', 10, 2 );
add_filter( 'seopress_sitemaps_url', 'swiftling_sitemap_output_url' );

/*
 * All in One SEO.
 */

/**
 * Leaves out of All in One SEO's sitemaps the posts SwiftLing leaves out, see
 * swiftling_sitemap_posts_sql(): separate copies, and in a language's sitemaps the posts not
 * translated into it. AIOSEO reads posts with SQL of its own, which no query filter reaches; it
 * leaves out the posts this filter names, in its sitemaps and in the counts of its index, read once
 * a request (Sitemap\Helpers::excludedPosts(), checked in AIOSEO 5.0.3). Only posts of the post
 * types its sitemaps include are named.
 *
 * @param mixed $ids IDs of posts AIOSEO's settings leave out.
 * @return mixed
 */
function swiftling_aioseo_sitemap_excluded_posts( $ids ) {
	global $wpdb;
	$sql = swiftling_sitemap_posts_sql( 'p' );
	if ( ! is_array( $ids ) || '' === $sql || ! function_exists( 'aioseo' ) || ! isset( aioseo()->sitemap->helpers ) ) {
		return $ids;
	}
	$types = array_values( array_filter( (array) aioseo()->sitemap->helpers->includedPostTypes(), 'is_string' ) );
	if ( ! $types ) {
		return $ids;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The condition is prepared already; one placeholder per type.
	$found = $wpdb->get_col( $wpdb->prepare( 'SELECT p.ID FROM %i AS p WHERE p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status IN ( 'publish', 'inherit' ) AND NOT ( 1 = 1 {$sql} )", $wpdb->posts, ...$types ) );
	return array_merge( $ids, array_map( 'intval', $found ) );
}
add_filter( 'aioseo_sitemap_exclude_posts', 'swiftling_aioseo_sitemap_excluded_posts' );

/**
 * Lists the sitemaps of every language in All in One SEO's index, after the original's
 * (Sitemap\Root::indexes(), checked in AIOSEO 5.0.3). AIOSEO counts the posts of its sitemaps with
 * SQL of its own and the posts it leaves out, which it reads once a request, see
 * swiftling_aioseo_sitemap_excluded_posts(); so the posts of each language are counted here. A post
 * type has as many sitemaps in a language as its posts translated into it take, each with the last
 * change among them, and none without them; a taxonomy and the archives of post types have the
 * original's, as every term and archive is in every language. Authors, dates and the additional
 * pages of AIOSEO's settings a language has none of, so a language's index, as on its host, leaves
 * them out.
 *
 * A post AIOSEO leaves out as noindex is counted, so in a language the last sitemap of a post type
 * may list fewer addresses than counted.
 *
 * @param mixed $indexes The index's sitemaps, each with its address in loc.
 * @return mixed
 */
function swiftling_aioseo_sitemap_index( $indexes ) {
	if ( ! is_array( $indexes ) || ! function_exists( 'aioseo' ) || ! isset( aioseo()->sitemap->helpers ) ) {
		return $indexes;
	}
	$name = (string) aioseo()->sitemap->filename;
	if ( '' !== swiftling_sitemap_language() ) {
		return array_values(
			array_filter(
				$indexes,
				static function ( $entry ) use ( $name ) {
					return 'other' !== swiftling_aioseo_index_kind( $entry, $name )[0];
				}
			)
		);
	}
	$languages = swiftling_sitemap_languages();
	if ( ! $languages ) {
		return $indexes;
	}

	$types  = array();
	$shared = array();
	foreach ( $indexes as $entry ) {
		list( $kind, $object ) = swiftling_aioseo_index_kind( $entry, $name );
		if ( 'posts' === $kind ) {
			$types[ $object ] = true;
		} elseif ( 'other' !== $kind ) {
			$shared[] = $entry;
		}
	}
	$per_page = max( 1, (int) aioseo()->sitemap->linksPerIndex );
	foreach ( $languages as $slug ) {
		$added = swiftling_in_sitemap_language(
			$slug,
			static function () use ( $types, $shared, $name, $per_page ) {
				$added = array();
				foreach ( swiftling_aioseo_language_posts( array_keys( $types ) ) as $type => $posts ) {
					$pages = (int) ceil( $posts['total'] / $per_page );
					for ( $page = 0; $page < $pages; ++$page ) {
						$added[] = array(
							'loc'     => swiftling_sitemap_url( home_url( '/' . $type . '-' . $name . ( $page ? $page + 1 : '' ) . '.xml' ) ),
							'lastmod' => $posts['modified'],
							'count'   => min( $per_page, $posts['total'] - $page * $per_page ),
						);
					}
				}
				foreach ( $shared as $entry ) {
					$entry['loc'] = swiftling_sitemap_url( $entry['loc'] );
					$added[]      = $entry;
				}
				return $added;
			}
		);
		array_push( $indexes, ...$added );
	}
	return $indexes;
}
add_filter( 'aioseo_sitemap_indexes', 'swiftling_aioseo_sitemap_index' );

/**
 * Returns what one of All in One SEO's sitemaps lists, by its address, such as
 * /page-sitemap2.xml: posts of a post type, terms of a taxonomy, the archives of post types
 * (post-archive), or anything else: authors, dates, the additional pages of its settings.
 *
 * @param mixed  $entry The sitemap, with its address in loc.
 * @param string $name  The name AIOSEO's settings give the sitemaps, "sitemap".
 * @return array{0: string, 1: string} posts, terms, archives or other; and the post type or taxonomy.
 */
function swiftling_aioseo_index_kind( $entry, $name ) {
	$loc = is_array( $entry ) && isset( $entry['loc'] ) ? (string) $entry['loc'] : '';
	if ( '' === $name || ! preg_match( '~/([^/?#]+)-' . preg_quote( $name, '~' ) . '\d*\.xml(?:\.gz)?(?:[?#]|$)~i', $loc, $found ) ) {
		return array( 'other', '' );
	}
	if ( 'post-archive' === $found[1] ) {
		return array( 'archives', $found[1] );
	}
	if ( post_type_exists( $found[1] ) ) {
		return array( 'posts', $found[1] );
	}
	return taxonomy_exists( $found[1] ) || 'product_attributes' === $found[1] ? array( 'terms', $found[1] ) : array( 'other', $found[1] );
}

/**
 * Returns how many posts of each post type the language of the sitemap being built lists, and the
 * last change among them, besides the posts AIOSEO's settings leave out.
 *
 * @param string[] $types Post types.
 * @return array<string, array{total: int, modified: string}> By post type, only those with posts.
 */
function swiftling_aioseo_language_posts( $types ) {
	global $wpdb;
	if ( ! $types ) {
		return array();
	}
	$excluded = array_filter( array_map( 'intval', explode( ',', (string) aioseo()->sitemap->helpers->excludedPosts() ) ) );
	$sql      = swiftling_sitemap_posts_sql( 'p' ) . ( $excluded ? ' AND p.ID NOT IN ( ' . implode( ',', $excluded ) . ' )' : '' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The condition is prepared already and the IDs are integers; one placeholder per type.
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT p.post_type, COUNT(*) AS total, MAX( p.post_modified_gmt ) AS modified FROM %i AS p WHERE p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status IN ( 'publish', 'inherit' ) AND p.post_password = '' {$sql} GROUP BY p.post_type", $wpdb->posts, ...$types ) );
	$posts = array();
	foreach ( $types as $type ) {
		foreach ( (array) $rows as $row ) {
			if ( $type === $row->post_type && (int) $row->total > 0 ) {
				$posts[ $type ] = array(
					'total'    => (int) $row->total,
					'modified' => gmdate( 'Y-m-d\TH:i:s+00:00', (int) strtotime( $row->modified . ' UTC' ) ),
				);
			}
		}
	}
	return $posts;
}

/**
 * Puts an address in All in One SEO's sitemaps into the sitemap's language, and, in a language,
 * gives the front page the site address, see swiftling_sitemap_front_page_url(). Images keep
 * their addresses.
 *
 * @param mixed    $entry Entry, with the address in loc.
 * @param int|null $id    Post or term ID.
 * @param string   $type  Post type or taxonomy.
 * @param string   $kind  post, term, archive or another.
 * @return mixed
 */
function swiftling_aioseo_sitemap_entry( $entry, $id = null, $type = '', $kind = '' ) {
	unset( $type );
	if ( ! is_array( $entry ) || ! isset( $entry['loc'] ) || ! is_string( $entry['loc'] ) ) {
		return $entry;
	}
	$loc = $entry['loc'];
	if ( 'post' === $kind && $id && '' !== swiftling_sitemap_language() && untrailingslashit( $loc ) !== untrailingslashit( home_url( '/' ) ) ) {
		$loc = swiftling_sitemap_front_page_url( $loc, get_post( (int) $id ) );
	}
	$entry['loc'] = swiftling_sitemap_url( $loc );
	return $entry;
}
add_filter( 'aioseo_sitemap_post', 'swiftling_aioseo_sitemap_entry', 10, 4 );
add_filter( 'aioseo_sitemap_term', 'swiftling_aioseo_sitemap_entry', 10, 4 );
add_filter( 'aioseo_sitemap_archive_entry', 'swiftling_aioseo_sitemap_entry', 10, 4 );
add_filter( 'aioseo_sitemap_date_entry', 'swiftling_aioseo_sitemap_entry' );
add_filter( 'aioseo_sitemap_product_attributes', 'swiftling_aioseo_sitemap_entry' );

/**
 * Lists no authors in All in One SEO's author sitemap of a language: a language has no author
 * archives. AIOSEO serves its sitemaps by the address, past the query variables
 * swiftling_sitemap_request() sets for a page not found; an author sitemap without entries it
 * answers as not found itself (Sitemap::generate(), checked in AIOSEO 5.0.3).
 *
 * @param mixed $entries Entries of the author sitemap.
 * @return mixed
 */
function swiftling_aioseo_sitemap_authors( $entries ) {
	return '' !== swiftling_sitemap_language() ? array() : $entries;
}
add_filter( 'aioseo_sitemap_author_archives', 'swiftling_aioseo_sitemap_authors' );

/**
 * Keeps the language's folder once in the address All in One SEO sends a request for one of its
 * sitemaps on to, from another of its addresses, as /de/page-sitemap1.xml or one with a trailing
 * slash. AIOSEO builds it from the site address, which has the folder in a language, and the
 * address asked for, which has it too (RequestParser::maybeRedirect(), checked in AIOSEO 5.0.3).
 * Added for the request of one of AIOSEO's sitemaps in a language.
 *
 * @param mixed $location Address the request is sent to.
 * @return mixed
 */
function swiftling_aioseo_sitemap_redirect( $location ) {
	if ( ! is_string( $location ) ) {
		return $location;
	}
	$home   = trailingslashit( home_url() );
	$folder = (string) substr( $home, strlen( trailingslashit( swiftling_address_without_language( $home ) ) ) );
	if ( '' === $folder || 0 !== strpos( $location, $home . $folder ) ) {
		return $location;
	}
	return $home . substr( $location, strlen( $home . $folder ) );
}

/**
 * Keeps the sitemaps of a language out of Yoast SEO's and Rank Math's cache, which keeps them by
 * type and page only. Decided from the address, as the cache can be asked before the language is.
 *
 * @param bool $enabled Whether sitemaps are cached.
 * @return bool
 */
function swiftling_sitemap_caching( $enabled ) {
	return '' === swiftling_requested_language() && $enabled;
}
add_filter( 'wpseo_enable_xml_sitemap_transient_caching', 'swiftling_sitemap_caching', 20 );
add_filter( 'rank_math/sitemap/enable_caching', 'swiftling_sitemap_caching', 20 );

/**
 * Clears sitemaps Yoast SEO or Rank Math cached. Yoast SEO clears at the end of the request; Rank
 * Math's own queue is only emptied in the admin, so its cache is cleared at once.
 *
 * @param string[] $types Types of sitemaps, "1" for the index; all when empty.
 */
function swiftling_clear_seo_sitemaps( $types = array() ) {
	if ( class_exists( 'WPSEO_Sitemaps_Cache' ) ) {
		WPSEO_Sitemaps_Cache::clear( $types );
	}
	if ( class_exists( 'RankMath\Sitemap\Cache' ) && class_exists( 'RankMath\Sitemap\Sitemap' ) && \RankMath\Sitemap\Sitemap::is_cache_enabled() ) {
		foreach ( $types ? $types : array( null ) as $type ) {
			\RankMath\Sitemap\Cache::invalidate_storage( $type );
		}
	}
}

/**
 * Clears the cached sitemap index once the languages of posts were written again: it counts the
 * pages of each language's sitemaps.
 */
function swiftling_clear_sitemap_index() {
	swiftling_clear_seo_sitemaps( array( '1' ) );
}
add_action( 'swiftling_translated_changed', 'swiftling_clear_sitemap_index' );

/**
 * Clears every cached sitemap once the languages or their address format change.
 */
function swiftling_clear_all_sitemaps() {
	swiftling_clear_seo_sitemaps();
}
add_action( 'add_option_swiftling_languages', 'swiftling_clear_all_sitemaps' );
add_action( 'update_option_swiftling_languages', 'swiftling_clear_all_sitemaps' );
