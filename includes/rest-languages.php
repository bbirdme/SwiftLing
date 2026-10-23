<?php
/**
 * The languages of the site in the REST API, for a site or app that shows the content elsewhere.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the public route of the languages, GET swiftling/v1/languages, and the field
 * "swiftling" on the posts and terms SwiftLing translates. Content in a language comes from the
 * same routes WordPress has, asked in it: /de/wp-json/wp/v2/posts or ?lang=de, see
 * swiftling_rest_reads().
 */
function swiftling_register_language_routes() {
	register_rest_route(
		'swiftling/v1',
		'/languages',
		array(
			'methods'             => 'GET',
			'callback'            => 'swiftling_rest_languages',
			'permission_callback' => '__return_true',
		)
	);

	$schema = array(
		'description' => __( 'The language of the answer, and the address and state of the translation in each language visitors see.', 'swiftling' ),
		'type'        => 'object',
		'context'     => array( 'view' ),
		'readonly'    => true,
	);
	foreach ( array_keys( swiftling_content_types() ) as $type ) {
		register_rest_field(
			$type,
			'swiftling',
			array(
				'get_callback' => static function ( $item ) {
					return swiftling_rest_post_languages( (int) $item['id'] );
				},
				'schema'       => $schema,
			)
		);
	}
	foreach ( array_keys( swiftling_term_taxonomies() ) as $taxonomy ) {
		register_rest_field(
			$taxonomy,
			'swiftling',
			array(
				'get_callback' => static function ( $item ) {
					return swiftling_rest_term_languages( (int) $item['id'] );
				},
				'schema'       => $schema,
			)
		);
	}
}
add_action( 'rest_api_init', 'swiftling_register_language_routes' );

/**
 * Returns the languages visitors see, in the order of the Languages screen: the original first
 * among them as the screen orders it, each with what a switcher needs.
 *
 * @return array<int, array{code: string, locale: string, slug: string, name: string, original: bool, current: bool, direction: string, flag: string, url: string}>
 */
function swiftling_rest_languages() {
	$current = swiftling_page_language()['locale'];
	$list    = array();
	foreach ( swiftling_rest_visible_languages() as $language ) {
		$slug   = $language['source'] ? '' : (string) $language['slug'];
		$list[] = array(
			'code'      => swiftling_hreflang_code( (string) $language['locale'] ),
			'locale'    => (string) $language['locale'],
			'slug'      => (string) $language['slug'],
			'name'      => wp_strip_all_tags( (string) $language['name'] ),
			'original'  => (bool) $language['source'],
			'current'   => $language['locale'] === $current,
			'direction' => swiftling_language_is_rtl( $language ) ? 'rtl' : 'ltr',
			'flag'      => \SwiftLing\Switcher\Flags::url( (string) $language['locale'] ),
			'url'       => swiftling_language_url(
				swiftling_unlocalized_url(
					static function () {
						return home_url( '/' );
					}
				),
				$slug
			),
		);
	}
	return $list;
}

/**
 * Returns the languages visitors see: the original, and each target that is visible and reachable.
 *
 * @return array<int, array> Languages as swiftling_ordered_languages() gives them.
 */
function swiftling_rest_visible_languages() {
	return array_values(
		array_filter(
			swiftling_ordered_languages(),
			static function ( $language ) {
				return $language['source'] || ( $language['visible'] && $language['reachable'] );
			}
		)
	);
}

/**
 * Returns the field "swiftling" of a post: the language of the answer, and for each language
 * visitors see the post's address there and the state of its translation: original, reviewed,
 * machine, partial, outdated or missing. A language the post is hidden in is left out.
 *
 * @param int $post_id Post ID.
 * @return array{language: string, translations: array<int, array{code: string, locale: string, url: string, state: string}>}
 */
function swiftling_rest_post_languages( $post_id ) {
	$post   = get_post( $post_id );
	$states = $post ? swiftling_language_states( $post_id ) : array();
	$list   = array();
	foreach ( $post ? swiftling_rest_visible_languages() : array() as $language ) {
		$slug = $language['source'] ? '' : (string) $language['slug'];
		if ( '' !== $slug && swiftling_is_hidden_post( $post, (string) $language['locale'] ) ) {
			continue;
		}
		$list[] = array(
			'code'   => swiftling_hreflang_code( (string) $language['locale'] ),
			'locale' => (string) $language['locale'],
			'url'    => swiftling_post_language_url( $post, $slug ),
			'state'  => '' === $slug ? 'original' : (string) ( $states[ $slug ] ?? 'missing' ),
		);
	}
	return array(
		'language'     => swiftling_hreflang_code( swiftling_page_language()['locale'] ),
		'translations' => $list,
	);
}

/**
 * Returns the field "swiftling" of a term: the language of the answer, and the term's address in
 * each language visitors see.
 *
 * @param int $term_id Term ID.
 * @return array{language: string, translations: array<int, array{code: string, locale: string, url: string}>}
 */
function swiftling_rest_term_languages( $term_id ) {
	$term = get_term( $term_id );
	$list = array();
	foreach ( $term instanceof WP_Term ? swiftling_rest_visible_languages() : array() as $language ) {
		$slug   = $language['source'] ? '' : (string) $language['slug'];
		$list[] = array(
			'code'   => swiftling_hreflang_code( (string) $language['locale'] ),
			'locale' => (string) $language['locale'],
			'url'    => swiftling_term_language_url( $term, $slug ),
		);
	}
	return array(
		'language'     => swiftling_hreflang_code( swiftling_page_language()['locale'] ),
		'translations' => $list,
	);
}

/**
 * Whether a language is written right to left: as set for a custom language; otherwise by its
 * language, as WordPress's own translations of it are (Arabic, Hebrew, Persian, Urdu and the
 * others of the list, from the Unicode CLDR's right-to-left languages).
 *
 * @param array $language Language as swiftling_languages() gives it.
 */
function swiftling_language_is_rtl( $language ) {
	if ( isset( $language['rtl'] ) && null !== $language['rtl'] ) {
		return (bool) $language['rtl'];
	}
	$code = strtolower( (string) strtok( (string) $language['locale'], '_' ) );
	return in_array( $code, array( 'ar', 'arc', 'ary', 'azb', 'ckb', 'dv', 'fa', 'haz', 'he', 'ps', 'sd', 'skr', 'ug', 'ur', 'yi' ), true );
}
