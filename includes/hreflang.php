<?php
/**
 * Alternate addresses for search engines: links with hreflang in the head of a page, one for every
 * language the page is translated into, as WPML writes them (WPML_SEO_HeadLangs).
 *
 *     <link rel="alternate" hreflang="hr" href="https://example.com/kontakt/" />
 *     <link rel="alternate" hreflang="de-DE" href="https://example.com/de/kontakt/" />
 *     <link rel="alternate" hreflang="x-default" href="https://example.com/kontakt/" />
 *
 * - A post or page names the original and every language it counts as translated into, see
 *   visibility.php: its texts are translated, it has a published separate copy there, or it is a
 *   duplicate there. A language that would show it untranslated is left out, as WPML leaves out a
 *   language without a translation.
 * - The front page, the page of posts and the archives of a category, tag, other taxonomy or post
 *   type name every language: each lists the posts of its language.
 * - The page itself is among them, and x-default names the original.
 * - Each address carries the translated slugs of its language, see slugs.php.
 * - Nothing on the second page of a list or of a post, in previews, on a page shown in a language
 *   it is not translated into, and on pages that show no single thing, such as search results and
 *   date archives. Languages hidden from visitors are never named.
 *
 * The canonical address of each page is the page itself, in its language, see seo.php.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints the alternate addresses of the page being shown. Early in the head, as WPML does.
 */
function swiftling_print_hreflangs() {
	foreach ( swiftling_hreflangs() as $code => $url ) {
		printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( $code ), esc_url( $url ) );
	}
}
add_action( 'wp_head', 'swiftling_print_hreflangs', 1 );

/**
 * Returns the alternate addresses of the page being shown, in the order the Languages screen sets,
 * then x-default.
 *
 * @return array<string, string> Language tag, e.g. "de-DE", or "x-default" => address. Empty when the
 *                               page gets none.
 */
function swiftling_hreflangs() {
	$page = swiftling_hreflang_page();
	if ( null === $page ) {
		return array();
	}

	$translated = $page['post'] ? swiftling_translated_locales( $page['post'] ) : null;
	$shown      = swiftling_page_language()['locale'];
	$links      = array();
	$listed     = false;
	foreach ( swiftling_ordered_languages() as $language ) {
		if ( ! $language['source'] && ( ! $language['visible'] || ! $language['reachable'] || ( null !== $translated && ! in_array( $language['locale'], $translated, true ) ) ) ) {
			continue;
		}
		$code = swiftling_hreflang_code( $language['locale'] );
		// Two languages with one tag, such as de_DE and de_DE_formal: the first in the order keeps it.
		if ( '' === $code || isset( $links[ $code ] ) ) {
			continue;
		}
		$links[ $code ] = swiftling_hreflang_url( $page, $language['source'] ? '' : (string) $language['slug'] );
		$listed         = $listed || $language['locale'] === $shown;
	}

	// A page shown in a language it is not translated into is no alternate of the others.
	if ( ! $listed || count( $links ) < 2 ) {
		return array();
	}
	$links['x-default'] = swiftling_hreflang_url( $page, '' );

	/**
	 * Filters the alternate addresses of the page being shown.
	 *
	 * @param array<string, string> $links Language tag or "x-default" => address.
	 * @param array                 $page  The address of the page in the original language, without a
	 *                                     language (url), the post whose translations decide its
	 *                                     languages (post, 0 for the front page of posts and archives),
	 *                                     and what builds its address (build).
	 */
	return (array) apply_filters( 'swiftling_hreflangs', $links, $page );
}

/**
 * Returns the address of the page being shown in a language, with the slugs of that language.
 *
 * @param array  $page Page, see swiftling_hreflang_page().
 * @param string $slug Address slug of a target language, or an empty string for the original.
 * @return string
 */
function swiftling_hreflang_url( $page, $slug ) {
	$url = swiftling_in_link_language(
		$slug,
		static function () use ( $page ) {
			return swiftling_unlocalized_url( $page['build'] );
		}
	);
	return swiftling_language_url( $url, $slug );
}

/**
 * Returns the page being shown, when it gets alternate addresses.
 *
 * @return array{url: string, post: int, build: callable}|null The address in the original language,
 *         without a language; the post whose translations decide its languages: the original of a
 *         copy, or 0 when every language has the page; and what builds the address, for the
 *         addresses in every language. Null when the page gets none.
 */
function swiftling_hreflang_page() {
	if ( is_paged() || (int) get_query_var( 'page' ) > 1 || is_preview() || is_customize_preview() || swiftling_is_probe() || \SwiftLing\Builders\Registry::isEditorPreview() ) {
		return null;
	}
	$object = get_queried_object();

	if ( is_singular() ) {
		if ( ! $object instanceof WP_Post || 'publish' !== $object->post_status ) {
			return null;
		}
		// A separate copy shows its original in its language.
		$original_id = swiftling_copy_original_id( $object );
		$original    = $original_id ? get_post( $original_id ) : $object;
		if ( ! $original || 'publish' !== $original->post_status || ! isset( swiftling_content_types()[ $original->post_type ] ) ) {
			return null;
		}
		$front = swiftling_real_front_page() === $original->ID;
		$build = static function () use ( $original, $front ) {
			return $front ? home_url( '/' ) : get_permalink( $original );
		};
		return swiftling_hreflang_entry( $build, $original->ID );
	}

	if ( is_home() ) {
		// The page of posts, or the site address when the front page lists the posts.
		$build = static function () use ( $object ) {
			return $object instanceof WP_Post ? get_permalink( $object ) : home_url( '/' );
		};
	} elseif ( ( is_category() || is_tag() || is_tax() ) && $object instanceof WP_Term && is_taxonomy_viewable( $object->taxonomy ) ) {
		$build = static function () use ( $object ) {
			$link = get_term_link( $object );
			return is_wp_error( $link ) ? '' : $link;
		};
	} elseif ( is_post_type_archive() && $object instanceof WP_Post ) {
		// A page that shows the archive of a kind of content, as WooCommerce's shop page shows the
		// products: WooCommerce makes the page what the archive shows. Its alternates are the page's,
		// at its own translated addresses, in the languages it is translated into.
		if ( 'publish' !== $object->post_status || ! isset( swiftling_content_types()[ $object->post_type ] ) ) {
			return null;
		}
		return swiftling_hreflang_entry(
			static function () use ( $object ) {
				return get_permalink( $object );
			},
			$object->ID
		);
	} elseif ( is_post_type_archive() && $object instanceof WP_Post_Type && isset( swiftling_content_types()[ $object->name ] ) ) {
		$build = static function () use ( $object ) {
			return get_post_type_archive_link( $object->name );
		};
	} else {
		return null;
	}

	return swiftling_hreflang_entry( $build, 0 );
}

/**
 * Returns the page as swiftling_hreflang_page() describes it.
 *
 * @param callable $build Builds the address of the page.
 * @param int      $post  Post whose translations decide the languages, or 0.
 * @return array{url: string, post: int, build: callable}|null Null when the page has no address.
 */
function swiftling_hreflang_entry( $build, $post ) {
	$url = (string) swiftling_in_link_language(
		'',
		static function () use ( $build ) {
			return swiftling_unlocalized_url( $build );
		}
	);
	return '' === $url ? null : array(
		'url'   => $url,
		'post'  => $post,
		'build' => $build,
	);
}

/**
 * Returns the tag that names a language in hreflang: its language, script and country, without the
 * variants some WordPress locales add, such as formal.
 *
 * @param string $locale Locale, e.g. "de_DE_formal", "sr_Latn_RS" or "cnr".
 * @return string For example "de-DE", "sr-Latn-RS" or "cnr"; empty when it names no language.
 */
function swiftling_hreflang_code( $locale ) {
	$parts    = explode( '_', str_replace( '-', '_', (string) $locale ) );
	$language = strtolower( (string) array_shift( $parts ) );
	if ( ! preg_match( '/^[a-z]{2,3}$/', $language ) ) {
		return '';
	}
	$code = $language;
	if ( $parts && preg_match( '/^[a-z]{4}$/i', $parts[0] ) ) {
		$code .= '-' . ucfirst( strtolower( (string) array_shift( $parts ) ) );
	}
	if ( $parts && preg_match( '/^(?:[a-z]{2}|[0-9]{3})$/i', $parts[0] ) ) {
		$code .= '-' . strtoupper( (string) array_shift( $parts ) );
	}
	return $code;
}
