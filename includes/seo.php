<?php
/**
 * What search engines and social networks read in the head of a page in another language: its
 * canonical address, og:locale, and the texts of Yoast SEO, Rank Math, SEOPress and All in One SEO.
 *
 * - The canonical address of a page in a language is the page itself, with its language and its
 *   translated slugs, never the original: WordPress's own (get_canonical_url), Rank Math's
 *   (rank_math/frontend/canonical), from which it also takes og:url and the addresses of its
 *   structured data, and Yoast SEO's, see below. hreflang links the languages, see hreflang.php.
 * - og:locale, and inLanguage in Yoast SEO's structured data, name the language of the page, also
 *   where WordPress runs in another one: the original on a site whose WordPress speaks another
 *   language, and a custom language, which runs WordPress in its close language. Yoast SEO:
 *   wpseo_locale, before it makes a locale Facebook knows of it, and wpseo_schema_piece_language;
 *   Rank Math: rank_math/opengraph/facebook/og_locale.
 * - Rank Math reads its title, description and social texts with get_post_meta() while the page
 *   renders, so a page check finds them like any other text of the page, see sources.php.
 * - The title variable of both, %%title%% and %title%, gives the post's title as SwiftLing reads
 *   it; both take it from the post object, past the filters that translate it.
 * - The theme Hello Elementor prints a page's excerpt as its meta description, from the post object,
 *   past get_the_excerpt; SwiftLing prints it instead, see swiftling_hello_elementor_description().
 * - SEOPress reads its texts of a post or a term with get_post_meta() and get_term_meta() while the
 *   page renders, as Rank Math, and declares them as text, see SwiftLing\Builders\SeoPress. Its
 *   canonical address, a whole tag (seopress_titles_canonical), is the page in its language, also
 *   one typed into its field. Its variables of a post read the post as stored, the title and the
 *   excerpt it cuts from the content; they get them as SwiftLing reads them, see
 *   swiftling_seopress_variables(). og:locale, og:url and the texts of the site it takes from
 *   WordPress, in the page's language already.
 * - All in One SEO reads its texts of a post from a table of its own: SwiftLing reads them there as
 *   their copy in the post's meta, see swiftling_aioseo_post(). Its tags that read the post as
 *   stored get it as SwiftLing reads it, see swiftling_aioseo_tag_values(). Its canonical address,
 *   also one typed into its field, is the page in its language (aioseo_canonical_url); og:locale,
 *   og:url, the site in its structured data and the titles and descriptions of terms it reads
 *   through WordPress, in the page's language already.
 *
 * Yoast SEO keeps each post's address and texts in a table of its own, its indexables, written
 * when a post is saved or first shown. A page in a language would write its own address and texts
 * there, which the original's pages would then show, and a page check its markers. So while
 * SwiftLing renders a page, Yoast SEO writes nothing there (wpseo_should_save_indexable), and the
 * indexables it shows, the page's (wpseo_frontend_presentation) and those of its breadcrumbs
 * (wpseo_breadcrumb_indexables), get for that request only:
 *
 * - their address in the page's language, from which Yoast SEO builds the canonical address,
 *   og:url, the breadcrumbs and the addresses of its structured data, and a canonical address
 *   typed into its field, in the language too;
 * - the post's SEO title, meta description and social texts, read with get_post_meta(), so a page
 *   check finds them and a page in a language shows their translations. Yoast SEO's variables,
 *   such as %%sep%%, stay in the text, and a translation keeps them;
 * - the title of a post or the name of a term in the breadcrumbs, unless it has a breadcrumb title;
 * - the home page's description, when Yoast SEO has none of its own: the tagline, which Yoast SEO
 *   stores in the original when it builds the home page's indexable.
 *
 * The site in Yoast SEO's structured data, the WebSite and who runs it, gets the address of the
 * page's language too (wpseo_schema_graph).
 *
 * Checked in Yoast SEO 28.6, Rank Math 1.0.280, SEOPress 10.3 and All in One SEO 5.0.3. Not
 * translated: the focus keyword and the other fields of the SEO analysis, and Yoast SEO's texts of
 * terms, which it keeps in an option.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns an address of the site in a language: with the language and the translated slugs of the
 * page being shown, or, for an address an SEO plugin stored for another post, page or term, as a
 * canonical address typed in for another page, that object's, so it does not send on to them with
 * a redirect. An address that names no public object, as a draft's or one of nothing, gets the
 * language only, as it did. Addresses of other sites stay as they are.
 *
 * @param string $url  Address of the page, with the slugs of the page's links, see
 *                     swiftling_link_locale(), or the original's, as an SEO plugin stored it.
 * @param string $slug Address slug of a target language, or an empty string for the original.
 * @return string
 */
function swiftling_page_address( $url, $slug ) {
	if ( ! is_string( $url ) || '' === $url || ! swiftling_is_own_address( $url ) ) {
		return $url;
	}
	$url = swiftling_address_without_language( $url );
	// An address that is another object's own first: the page's own slugs would move only the part
	// it shares with the page shown, as a parent's. The page's own address looks nothing up. The
	// rest as the page's links are.
	$moved = swiftling_is_shown_address( $url ) ? $url : swiftling_object_address( $url, $slug );
	if ( $moved === $url ) {
		foreach ( array_unique( array( swiftling_locale_slug( swiftling_link_locale() ), '' ) ) as $from ) {
			$moved = swiftling_current_slugs( $url, $slug, $from );
			if ( $moved !== $url ) {
				break;
			}
		}
	}
	return swiftling_language_url( $moved, $slug );
}

/**
 * Gives the canonical address of a page in a language: the page itself.
 *
 * @param string $url Canonical address.
 * @return string
 */
function swiftling_translated_canonical( $url ) {
	$state = &swiftling_state();
	return 'translate' === $state['mode'] ? swiftling_page_address( $url, (string) $state['language'] ) : $url;
}
add_filter( 'get_canonical_url', 'swiftling_translated_canonical' );
add_filter( 'rank_math/frontend/canonical', 'swiftling_translated_canonical' );

/**
 * Gives Yoast SEO the locale of the page's language for og:locale.
 *
 * @return string
 */
function swiftling_yoast_locale() {
	return (string) swiftling_page_language()['locale'];
}
add_filter( 'wpseo_locale', 'swiftling_yoast_locale' );

/**
 * Gives Yoast SEO's structured data the language of the page (inLanguage), written as its lang
 * attribute, see swiftling_language_attributes(). Yoast SEO takes it from WordPress, whose German
 * texts give "de", and which runs a custom language in its close language.
 *
 * @return string For example "de-DE".
 */
function swiftling_yoast_schema_language() {
	return str_replace( '_', '-', (string) swiftling_page_language()['locale'] );
}
add_filter( 'wpseo_schema_piece_language', 'swiftling_yoast_schema_language' );

/**
 * Gives Rank Math's og:locale the page's language, where WordPress runs in another.
 *
 * @param string $locale Locale Rank Math made of WordPress's locale.
 * @return string
 */
function swiftling_rank_math_locale( $locale ) {
	$page = (string) swiftling_page_language()['locale'];
	if ( get_locale() === $page || ! class_exists( 'RankMath\\OpenGraph\\Facebook_Locale' ) ) {
		return $locale;
	}
	return \RankMath\OpenGraph\Facebook_Locale::validate( \RankMath\OpenGraph\Facebook_Locale::sanitize( $page ) );
}
add_filter( 'rank_math/opengraph/facebook/og_locale', 'swiftling_rank_math_locale' );

/**
 * Keeps Yoast SEO from writing an indexable while SwiftLing renders a page: a page check or a
 * page in a language.
 *
 * @param bool $save Whether Yoast SEO saves it.
 * @return bool
 */
function swiftling_yoast_save_indexable( $save ) {
	return '' === swiftling_mode() && $save;
}
add_filter( 'wpseo_should_save_indexable', 'swiftling_yoast_save_indexable', 20 );

/**
 * Gives the indexable Yoast SEO shows the page's address in its language and the post's texts as
 * they are read now, see the top of this file. Yoast SEO asks twice, for the title and for the rest
 * of the head; both times give the same.
 *
 * @param object $presentation Yoast SEO's presentation of the page, with the indexable in model.
 * @return object
 */
function swiftling_yoast_presentation( $presentation ) {
	if ( '' !== swiftling_mode() && is_object( $presentation ) && isset( $presentation->model ) ) {
		swiftling_yoast_localize( $presentation->model );
	}
	return $presentation;
}
add_filter( 'wpseo_frontend_presentation', 'swiftling_yoast_presentation' );

/**
 * Does the same for the indexables of Yoast SEO's breadcrumbs, the page's own and its ancestors',
 * which it prints and puts in its structured data.
 *
 * @param array $indexables Indexables, the home page first.
 * @return array
 */
function swiftling_yoast_breadcrumb_indexables( $indexables ) {
	if ( '' !== swiftling_mode() && is_array( $indexables ) ) {
		array_map( 'swiftling_yoast_localize', $indexables );
	}
	return $indexables;
}
add_filter( 'wpseo_breadcrumb_indexables', 'swiftling_yoast_breadcrumb_indexables' );

/**
 * Gives one of Yoast SEO's indexables, for this request only, its address in the page's language and
 * the texts of its post or term as they are read now.
 *
 * @param object $indexable Indexable.
 */
function swiftling_yoast_localize( $indexable ) {
	if ( ! is_object( $indexable ) ) {
		return;
	}
	$state = &swiftling_state();
	if ( 'translate' === $state['mode'] && is_string( $indexable->permalink ) && '' !== $indexable->permalink ) {
		$indexable->permalink = swiftling_yoast_address( $indexable, (string) $state['language'] );
	}
	// A canonical address typed into its field, which Yoast SEO prints instead of the permalink.
	if ( 'translate' === $state['mode'] && isset( $indexable->canonical ) && is_string( $indexable->canonical ) && '' !== $indexable->canonical ) {
		$indexable->canonical = swiftling_page_address( $indexable->canonical, (string) $state['language'] );
	}

	$id = (int) $indexable->object_id;
	if ( 'post' === $indexable->object_type && $id ) {
		$fields = array(
			'title'                  => '_yoast_wpseo_title',
			'description'            => '_yoast_wpseo_metadesc',
			'open_graph_title'       => '_yoast_wpseo_opengraph-title',
			'open_graph_description' => '_yoast_wpseo_opengraph-description',
			'twitter_title'          => '_yoast_wpseo_twitter-title',
			'twitter_description'    => '_yoast_wpseo_twitter-description',
		);
		foreach ( $fields as $field => $key ) {
			$value = get_post_meta( $id, $key, true );
			if ( is_string( $value ) && '' !== $value ) {
				$indexable->$field = $value;
			}
		}
		// The breadcrumb shows the post's title, unless it has a breadcrumb title of its own.
		$crumb                       = get_post_meta( $id, '_yoast_wpseo_bctitle', true );
		$indexable->breadcrumb_title = is_string( $crumb ) && '' !== $crumb ? $crumb : swiftling_filter_title( (string) get_post_field( 'post_title', $id, 'raw' ), $id );
	} elseif ( 'home-page' === $indexable->object_type ) {
		// Without a description of its own, Yoast SEO stores the tagline as it was when it built the
		// indexable, in the original; read again, it is the page's language's, as in the title.
		$titles = get_option( 'wpseo_titles' );
		if ( ! is_array( $titles ) || empty( $titles['metadesc-home-wpseo'] ) ) {
			$indexable->description = get_bloginfo( 'description' );
		}
	} elseif ( 'term' === $indexable->object_type && $id && class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
		$term = get_term( $id );
		if ( $term instanceof WP_Term && '' === (string) WPSEO_Taxonomy_Meta::get_term_meta( $term, $term->taxonomy, 'bctitle' ) ) {
			$indexable->breadcrumb_title = $term->name;
		}
	}
}

/**
 * Returns the address of one of Yoast SEO's indexables in a language, with its translated slugs.
 *
 * @param object $indexable Indexable.
 * @param string $slug      Address slug of a target language.
 * @return string
 */
function swiftling_yoast_address( $indexable, $slug ) {
	$home  = static function () {
		return home_url( '/' );
	};
	$build = null;
	if ( 'home-page' === $indexable->object_type ) {
		$build = $home;
	} elseif ( 'post' === $indexable->object_type ) {
		// A copy's address is its original's.
		$post_id  = (int) $indexable->object_id;
		$original = swiftling_copy_original_id( $post_id );
		$post_id  = $original ? $original : $post_id;
		$build    = swiftling_real_front_page() === $post_id ? $home : static function () use ( $post_id ) {
			return get_permalink( $post_id );
		};
	} elseif ( 'term' === $indexable->object_type ) {
		$term = get_term( (int) $indexable->object_id );
		if ( $term instanceof WP_Term ) {
			$build = static function () use ( $term ) {
				$link = get_term_link( $term );
				return is_wp_error( $link ) ? '' : $link;
			};
		}
	}

	$url = null === $build ? '' : (string) swiftling_in_link_language(
		$slug,
		static function () use ( $build ) {
			return swiftling_unlocalized_url( $build );
		}
	);
	// Archives of post types and authors, dates and the search have no slugs of their own.
	return swiftling_language_url( '' !== $url ? $url : swiftling_address_without_language( (string) $indexable->permalink ), $slug );
}

/**
 * Gives the site in Yoast SEO's structured data the address of the page's language, as the page
 * and its breadcrumbs have: the WebSite, and the organization or person behind it.
 *
 * @param array $graph Pieces of the structured data.
 * @return array
 */
function swiftling_yoast_schema_graph( $graph ) {
	$state = &swiftling_state();
	if ( 'translate' !== $state['mode'] || ! is_array( $graph ) ) {
		return $graph;
	}
	$from = swiftling_unlocalized_url(
		static function () {
			return home_url( '/' );
		}
	);
	$to   = swiftling_language_url( $from, (string) $state['language'] );
	array_walk_recursive(
		$graph,
		static function ( &$value ) use ( $from, $to ) {
			if ( is_string( $value ) && ( $value === $from || 0 === strpos( $value, $from . '#' ) ) ) {
				$value = $to . substr( $value, strlen( $from ) );
			}
		}
	);
	return $graph;
}
add_filter( 'wpseo_schema_graph', 'swiftling_yoast_schema_graph' );

/**
 * Gives the title variable of Yoast SEO and Rank Math, %%title%% and %title%, the post's title as
 * SwiftLing reads it: marked in a page check, translated in a language. Both take the title from
 * the post object, past the filters that translate it.
 *
 * @param array  $replacements Variable => value.
 * @param object $args         Post, term or other object the values come from.
 * @return array
 */
function swiftling_seo_title_variable( $replacements, $args ) {
	if ( '' === swiftling_mode() || ! is_array( $replacements ) || ! is_object( $args ) || empty( $args->ID ) || ! isset( $args->post_title ) ) {
		return $replacements;
	}
	foreach ( array( '%%title%%', '%title%' ) as $variable ) {
		if ( isset( $replacements[ $variable ] ) && is_string( $replacements[ $variable ] ) ) {
			$replacements[ $variable ] = swiftling_filter_title( (string) $args->post_title, (int) $args->ID );
		}
	}
	return $replacements;
}
add_filter( 'wpseo_replacements', 'swiftling_seo_title_variable', 10, 2 );
add_filter( 'rank_math/replacements', 'swiftling_seo_title_variable', 10, 2 );

/**
 * Hello Elementor names a singular page in the head with its excerpt, which it reads from the post
 * object, past get_the_excerpt where SwiftLing translates an excerpt
 * (hello_elementor_add_description_meta_tag(), checked in Hello Elementor 3.5.1): a page in a
 * language named itself in the original. The theme asks its filter whether to print it just before
 * it does, on wp_head; SwiftLing prints it there itself, with the excerpt as SwiftLing reads it,
 * translated on a page in a language and marked in a page check, and tells the theme not to. A page
 * in the original, and one without an excerpt, are left to the theme.
 *
 * @param mixed $show Whether the theme prints its description.
 * @return mixed
 */
function swiftling_hello_elementor_description( $show ) {
	if ( ! $show || '' === swiftling_mode() || ! is_singular() ) {
		return $show;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || '' === (string) $post->post_excerpt ) {
		return $show;
	}
	echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( (string) swiftling_filter_excerpt( $post->post_excerpt, $post ) ) ) . '">' . "\n";
	return false;
}
// Last, so it prints only where nothing else turned the theme's description off.
add_filter( 'hello_elementor_description_meta_tag', 'swiftling_hello_elementor_description', PHP_INT_MAX );

/*
 * SEOPress.
 */

/**
 * Gives SEOPress's variables of a post the post's texts as SwiftLing reads them. SEOPress reads them
 * from the post as stored, past the filters that translate them (seopress_get_dynamic_variables(),
 * checked in SEOPress 10.3):
 *
 * - the title, %%title%% and %%post_title%%, read with get_post_field(): marked in a page check,
 *   translated in a language, as the title variable of Yoast SEO and Rank Math;
 * - the excerpt of a post without one of its own, %%post_excerpt%%, %%excerpt%% and
 *   %%wc_single_short_desc%%, which SEOPress also prints as the meta description where none is
 *   set, and the content, %%post_content%%: both cut from the content as stored. In a language
 *   they are cut, as SEOPress cuts them, from the content in the language. A page check leaves
 *   them as they are: it finds the content's texts where the page shows them.
 *
 * An excerpt of the post's own SEOPress reads with get_the_excerpt(), in the language already. A
 * variable SEOPress left empty, as the title outside the post's own page, stays empty.
 *
 * @param array $variables The post, the variables and their values in two lists beside each
 *                         other, the words of an excerpt, and the excerpt SEOPress made.
 * @return array
 */
function swiftling_seopress_variables( $variables ) {
	$mode = swiftling_mode();
	if ( '' === $mode || ! is_array( $variables ) || ! isset( $variables['post'] ) || ! $variables['post'] instanceof WP_Post
		|| ! isset( $variables['seopress_titles_template_variables_array'], $variables['seopress_titles_template_replace_array'] )
		|| ! is_array( $variables['seopress_titles_template_variables_array'] ) || ! is_array( $variables['seopress_titles_template_replace_array'] ) ) {
		return $variables;
	}

	$post   = $variables['post'];
	$title  = esc_attr( wp_strip_all_tags( str_replace( '<br>', ' ', (string) swiftling_filter_title( (string) $post->post_title, (int) $post->ID ) ) ) );
	$values = array(
		'%%title%%'      => $title,
		'%%post_title%%' => $title,
	);
	if ( 'translate' === $mode && '' !== (string) $post->post_content ) {
		// SEOPress builds its variables again for each tag it prints, the content is translated once.
		$state  = &swiftling_state();
		$length = isset( $variables['seopress_excerpt_length'] ) ? (int) $variables['seopress_excerpt_length'] : 50;
		$cached = 'seopress|' . $post->ID . '|' . $length;
		if ( ! isset( $state['values'][ $cached ] ) ) {
			$state['values'][ $cached ] = swiftling_seopress_cut( swiftling_translate_post_content( swiftling_post_owner( $post->ID ), (string) $post->post_content ), $length );
		}
		$content                    = $state['values'][ $cached ];
		$values['%%post_content%%'] = $content;
		if ( '' === (string) $post->post_excerpt ) {
			$values += array_fill_keys( array( '%%post_excerpt%%', '%%excerpt%%', '%%wc_single_short_desc%%' ), $content );
			if ( isset( $variables['seopress_get_the_excerpt'] ) && is_string( $variables['seopress_get_the_excerpt'] ) && '' !== $variables['seopress_get_the_excerpt'] ) {
				$variables['seopress_get_the_excerpt'] = $content;
			}
		}
	}

	foreach ( $variables['seopress_titles_template_variables_array'] as $index => $variable ) {
		$value = isset( $variables['seopress_titles_template_replace_array'][ $index ] ) ? $variables['seopress_titles_template_replace_array'][ $index ] : null;
		if ( is_string( $variable ) && isset( $values[ $variable ] ) && is_string( $value ) && '' !== $value ) {
			$variables['seopress_titles_template_replace_array'][ $index ] = $values[ $variable ];
		}
	}
	return $variables;
}
add_filter( 'seopress_titles_template_variables', 'swiftling_seopress_variables' );

/**
 * Cuts the excerpt of a post from its content as SEOPress cuts it: without shortcodes, tags and
 * line breaks of HTML, the first words.
 *
 * @param string $content Content of the post.
 * @param int    $length  Words.
 * @return string
 */
function swiftling_seopress_cut( $content, $length ) {
	// SEOPress's own, which leaves out shortcodes that are not registered too.
	$content = is_callable( array( '\SEOPress\Helpers\Shortcodes', 'stripTags' ) ) ? \SEOPress\Helpers\Shortcodes::stripTags( $content ) : strip_shortcodes( $content );
	return wp_trim_words( esc_attr( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( (string) $content ) ) ) ), $length );
}

/**
 * Gives SEOPress's canonical address, which it prints as a whole tag, the page in its language: its
 * own, built from the original's address while the page renders in the language, and one typed
 * into its field of a post or a term, which names the original's page.
 *
 * @param mixed $tag The link tag.
 * @return mixed
 */
function swiftling_seopress_canonical( $tag ) {
	if ( ! is_string( $tag ) || 'translate' !== swiftling_mode() ) {
		return $tag;
	}
	return (string) preg_replace_callback(
		'~(\shref=")([^"]*)"~',
		static function ( $found ) {
			$url = html_entity_decode( $found[2], ENT_QUOTES | ENT_HTML401, 'UTF-8' );
			$new = swiftling_translated_canonical( $url );
			// Escaped as SEOPress escapes it.
			return $new === $url ? $found[0] : $found[1] . htmlspecialchars( urldecode( $new ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '"';
		},
		$tag,
		1
	);
}
add_filter( 'seopress_titles_canonical', 'swiftling_seopress_canonical' );

/*
 * All in One SEO.
 */

/**
 * Gives All in One SEO's texts of a post, its SEO title, meta description and Facebook and X
 * texts, which it reads from a table of its own (Models\Post::getPost(), checked in AIOSEO 5.0.3),
 * as SwiftLing reads them: as the copy AIOSEO keeps in the post's meta for translation plugins
 * (_aioseo_title, …) would be read, marked in a page check, translated in a language. The value
 * comes from the table: AIOSEO writes that copy when the post is saved in its editor, not when it
 * imports another SEO plugin's texts.
 *
 * In AIOSEO's code a post's row is written when the post is saved, by its scheduled scans and
 * upgrades, and while a page renders only for a post without a row yet, with the texts of AIOSEO's
 * earlier version, which this leaves alone: never the texts this gives.
 *
 * @param mixed $post AIOSEO's row of a post.
 * @return mixed
 */
function swiftling_aioseo_post( $post ) {
	if ( '' === swiftling_mode() || ! is_object( $post ) || empty( $post->post_id ) ) {
		return $post;
	}
	$owner = swiftling_post_owner( (int) $post->post_id );
	foreach ( array( 'title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description' ) as $field ) {
		if ( isset( $post->$field ) && is_string( $post->$field ) && '' !== $post->$field ) {
			$key          = '_aioseo_' . $field;
			$post->$field = swiftling_transform( $owner, swiftling_meta_path( $key, 0 ), 'meta:' . $key, $post->$field );
		}
	}
	return $post;
}
add_filter( 'aioseo_get_post', 'swiftling_aioseo_post' );

/**
 * Gives All in One SEO's tags that read the post a page shows as stored, past the filters that
 * translate it, the post as SwiftLing reads it, in every title and description AIOSEO prints for
 * the page: its own, those of Facebook and X, and those of its structured data
 * (Utils\Tags::getTagValue(), checked in AIOSEO 5.0.3). AIOSEO puts the tags' values in before its
 * filters aioseo_title and aioseo_description, so the values are replaced there, each where it
 * stands as a whole, not as part of a longer word or next to a marker:
 *
 * - #post_excerpt and #post_excerpt_only: the post's own excerpt, marked in a page check,
 *   translated in a language;
 * - #parent_title: the title of the post's parent, the same;
 * - #post_content, and #post_excerpt of a post without an excerpt of its own: the description
 *   AIOSEO cuts from the content, cut in a language from the content in the language. A page check
 *   leaves it: it finds the content's texts where the page shows them. Where AIOSEO cuts it from
 *   anything else as well, such as a page builder's output or custom fields, it stays.
 *
 * #post_title reads the title with get_the_title(), which SwiftLing translates already.
 *
 * @param mixed $value Title or description, with the values of its tags.
 * @return mixed
 */
function swiftling_aioseo_tag_values( $value ) {
	$mode = swiftling_mode();
	if ( '' === $mode || ! is_string( $value ) || '' === $value || ! function_exists( 'aioseo' ) || ! isset( aioseo()->helpers ) ) {
		return $value;
	}
	$post = aioseo()->helpers->getPost();
	if ( ! $post instanceof WP_Post ) {
		return $value;
	}
	$values = swiftling_aioseo_post_values( $post, $mode );
	if ( ! $values ) {
		return $value;
	}
	$stored = array_keys( $values );
	usort(
		$stored,
		static function ( $a, $b ) {
			return strlen( (string) $b ) - strlen( (string) $a );
		}
	);
	$pattern = '~(?<![\p{L}\p{N}])(' . implode(
		'|',
		array_map(
			static function ( $text ) {
				return preg_quote( (string) $text, '~' );
			},
			$stored
		)
	) . ')(?![\p{L}\p{N}])~u';
	$new     = preg_replace_callback(
		$pattern,
		static function ( $found ) use ( $values ) {
			return $values[ $found[1] ];
		},
		$value
	);
	return null === $new ? $value : $new;
}
add_filter( 'aioseo_title', 'swiftling_aioseo_tag_values' );
add_filter( 'aioseo_description', 'swiftling_aioseo_tag_values' );

/**
 * Returns what AIOSEO's tags give of a post as stored, with what they give as SwiftLing reads it,
 * see swiftling_aioseo_tag_values(); once a request, as AIOSEO asks for each tag it prints.
 *
 * @param WP_Post $post The post the page shows.
 * @param string  $mode probe or translate.
 * @return array<string, string> As stored => as read, only where they differ.
 */
function swiftling_aioseo_post_values( $post, $mode ) {
	$state  = &swiftling_state();
	$cached = 'aioseo|' . $post->ID;
	if ( isset( $state['values'][ $cached ] ) ) {
		return $state['values'][ $cached ];
	}

	$values = array();
	if ( '' !== (string) $post->post_excerpt ) {
		$values[ (string) $post->post_excerpt ] = (string) swiftling_filter_excerpt( (string) $post->post_excerpt, $post );
	}
	$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
	if ( $parent instanceof WP_Post && '' !== (string) $parent->post_title ) {
		$values[ (string) $parent->post_title ] = (string) swiftling_filter_title( (string) $parent->post_title, $parent->ID );
	}
	if ( 'translate' === $mode && '' !== (string) $post->post_content ) {
		$cut = (string) aioseo()->helpers->getDescriptionFromContent( $post );
		if ( '' !== $cut && swiftling_aioseo_cut( $post->post_content ) === $cut ) {
			$values[ $cut ] = swiftling_aioseo_cut( swiftling_translate_post_content( swiftling_post_owner( $post->ID ), (string) $post->post_content ) );
		}
	}
	foreach ( $values as $stored => $read ) {
		if ( '' === $read || (string) $stored === $read ) {
			unset( $values[ $stored ] );
		}
	}
	$state['values'][ $cached ] = $values;
	return $values;
}

/**
 * Cuts a description from a post's content as AIOSEO cuts it (getDescriptionFromContent(),
 * AIOSEO 5.0.3): the content as AIOSEO renders it, without images, captions, embeds, tags and
 * shortcodes, its first 55 words.
 *
 * @param string $content Content of the post.
 * @return string
 */
function swiftling_aioseo_cut( $content ) {
	$content = (string) aioseo()->helpers->theContent( (string) $content );
	$content = (string) preg_replace( '/(<figure.*?\/figure>|<img.*?\/>|<div.*?class="wp-block-embed__wrapper".*?>.*?<\/div>)/s', '', $content );
	$content = str_replace( ']]>', ']]&gt;', $content );
	return wp_trim_words( trim( wp_strip_all_tags( strip_shortcodes( $content ) ) ), 55, '' );
}

// AIOSEO's canonical address, its own or typed into its field, is the page in its language.
add_filter( 'aioseo_canonical_url', 'swiftling_translated_canonical' );

/*
 * The variables of SEO plugins.
 */

/**
 * Returns the variables of SEO plugins a text holds: the plugin's syntax, which it replaces with a
 * value as the page renders, not words. Machine translation keeps them as written, see
 * swiftling_machine_translate(). Read as the plugins read them, checked in Yoast SEO 28.6, Rank Math
 * 1.0.280, SEOPress 10.3 and All in One SEO 5.0.3:
 *
 * - Yoast SEO and SEOPress: %%name%%;
 * - Rank Math: %name% and %name(arguments)%, as %customfield(subtitle)%;
 * - All in One SEO: #name, only the tags it registers and only while it is active, as an ordinary
 *   hashtag looks the same; also #custom_field-name and #tax_name-name.
 *
 * @param string $text Text.
 * @return string[] The variables, each once.
 */
function swiftling_seo_variables( $text ) {
	$text = (string) $text;
	if ( false === strpos( $text, '%' ) && false === strpos( $text, '#' ) ) {
		return array();
	}
	$found = array();
	// Yoast SEO's and SEOPress's first, so Rank Math's pattern takes no part of one.
	$rest = (string) preg_replace_callback(
		'/%%[^%\s]+%%/u',
		static function ( $variable ) use ( &$found ) {
			$found[] = $variable[0];
			return ' ';
		},
		$text
	);
	if ( preg_match_all( '/%[a-z0-9_-]+(?:\([^()%]*\))?%/iu', $rest, $variables ) ) {
		array_push( $found, ...$variables[0] );
	}
	$tags = swiftling_aioseo_tag_ids();
	if ( $tags ) {
		usort(
			$tags,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$names = implode(
			'|',
			array_map(
				static function ( $tag ) {
					return preg_quote( $tag, '/' );
				},
				$tags
			)
		);
		if ( preg_match_all( '/#(?:(?:custom_field|tax_name)-[a-zA-Z0-9_-]+|(?:' . $names . '))(?![a-zA-Z0-9_])/i', $text, $variables ) ) {
			array_push( $found, ...$variables[0] );
		}
	}
	return array_values( array_unique( $found ) );
}

/**
 * Returns the IDs of the tags All in One SEO registers, as post_title of #post_title, while it is
 * active (Utils\Tags::$tags, AIOSEO 5.0.3).
 *
 * @return string[]
 */
function swiftling_aioseo_tag_ids() {
	if ( ! function_exists( 'aioseo' ) || ! isset( aioseo()->tags->tags ) || ! is_array( aioseo()->tags->tags ) ) {
		return array();
	}
	$ids = array();
	foreach ( aioseo()->tags->tags as $tag ) {
		if ( is_array( $tag ) && isset( $tag['id'] ) && is_string( $tag['id'] ) && '' !== $tag['id'] ) {
			$ids[] = $tag['id'];
		}
	}
	return $ids;
}
