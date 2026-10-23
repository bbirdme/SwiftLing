<?php
/**
 * The word in front of a list's addresses in each language: category in /de/kategorie/vijesti/,
 * product in /de/produkt/sandala/.
 *
 * A list with pages of its own (a category, a tag, a product category …) and a content type with a
 * word of its own (products, projects …) start every address with the same word, the slug WordPress
 * registered them with. Some words have several parts (shop/product); a placeholder in one, such as
 * %product_cat%, is a term's own slug and is translated as one.
 *
 * A word is a translated slug like any other, see includes/slugs.php: a row of the slugs table, with
 * the object key "base:<tax|type>:<name>:<part>", so it is unique among every slug of its language,
 * an earlier word leads to the address now, and a request puts the original back before WordPress
 * reads it. The translation belongs to the list and the part, not to the word: when the original
 * word changes on the Permalinks screen, the translation stays.
 *
 * Unlike a slug, a word taken in its language is refused, not made unique with a number: a person
 * types it on the Addresses screen and chooses another. Otherwise a word works as a slug does, also
 * with ?lang=: the links a page builds keep the original words there, and the address of a language,
 * as the switcher, hreflang and sitemaps give it, has its word. Words need pretty permalinks.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the parts of the word a list or a content type registered for its addresses that are
 * words, by their place in it.
 *
 * @param mixed $rewrite The rewrite argument of a taxonomy or post type.
 * @return array<int, string> Place => word, e.g. array( 0 => 'shop', 1 => 'product' ).
 */
function swiftling_base_parts( $rewrite ) {
	if ( ! is_array( $rewrite ) || empty( $rewrite['slug'] ) ) {
		return array();
	}
	$parts = array();
	foreach ( explode( '/', trim( (string) $rewrite['slug'], '/' ) ) as $index => $part ) {
		if ( '' !== $part && false === strpos( $part, '%' ) ) {
			$parts[ $index ] = $part;
		}
	}
	return $parts;
}

/**
 * Returns the taxonomy or post type a list key or object key names.
 *
 * @param string $kind tax or type.
 * @param string $name Taxonomy or post type.
 * @return WP_Taxonomy|WP_Post_Type|null
 */
function swiftling_base_object( $kind, $name ) {
	$object = 'tax' === $kind ? get_taxonomy( $name ) : ( 'type' === $kind ? get_post_type_object( $name ) : null );
	return $object ? $object : null;
}

/**
 * Returns the lists whose addresses start with a word of their own, for the Addresses screen: the
 * lists of terms SwiftLing translates that have pages of their own, and the content types visitors
 * open that have a word. Posts and pages have none.
 *
 * @return array<string, array{kind: string, name: string, label: string, group: string, parts: array<int, string>}>
 *         List key ("tax:category", "type:product") => the list, its name, the content type it
 *         belongs to and the parts of its word.
 */
function swiftling_base_lists() {
	$types = swiftling_content_types();
	$lists = array();
	foreach ( $types as $name => $type ) {
		$parts = swiftling_base_parts( $type->rewrite );
		if ( $parts ) {
			$lists[ 'type:' . $name ] = array(
				'kind'  => 'type',
				'name'  => (string) $name,
				'label' => (string) $type->labels->name,
				'group' => (string) $name,
				'parts' => $parts,
			);
		}
	}
	foreach ( swiftling_term_taxonomies() as $name => $taxonomy ) {
		$parts = $taxonomy->public && $taxonomy->publicly_queryable ? swiftling_base_parts( $taxonomy->rewrite ) : array();
		if ( $parts ) {
			$lists[ 'tax:' . $name ] = array(
				'kind'  => 'tax',
				'name'  => (string) $name,
				'label' => (string) $taxonomy->labels->name,
				'group' => (string) current( array_intersect( (array) $taxonomy->object_type, array_keys( $types ) ) ),
				'parts' => $parts,
			);
		}
	}
	return $lists;
}

/**
 * Returns the object key of a part of a list's word.
 *
 * @param string $key   List key, e.g. "tax:category".
 * @param int    $index Place of the part in the word.
 * @return string For example "base:tax:category:0".
 */
function swiftling_base_owner( $key, $index ) {
	return 'base:' . $key . ':' . (int) $index;
}

/**
 * Reads an object key of a list's word.
 *
 * @param string $owner Object key, e.g. "base:tax:category:0".
 * @return array{kind: string, name: string, index: int}|null Null for another key.
 */
function swiftling_parse_base_owner( $owner ) {
	if ( ! preg_match( '/^base:(tax|type):([^:]+):(\d+)$/', (string) $owner, $match ) ) {
		return null;
	}
	return array(
		'kind'  => $match[1],
		'name'  => $match[2],
		'index' => (int) $match[3],
	);
}

/**
 * Returns the original of a part of a list's word, as its addresses carry it now.
 *
 * @param string $owner Object key, e.g. "base:tax:category:0".
 * @return string Empty when the list or the part is gone.
 */
function swiftling_base_original( $owner ) {
	$base   = swiftling_parse_base_owner( $owner );
	$object = $base ? swiftling_base_object( $base['kind'], $base['name'] ) : null;
	if ( ! $object ) {
		return '';
	}
	$parts = swiftling_base_parts( $object->rewrite );
	return isset( $parts[ $base['index'] ] ) ? $parts[ $base['index'] ] : '';
}

/**
 * Returns the parts of a list's word as pairs for swiftling_replace_base(), each translated into a
 * language or kept.
 *
 * @param string $kind   tax or type.
 * @param string $name   Taxonomy or post type.
 * @param string $locale Locale.
 * @return array List of array( original, translated ), in the order of the word; empty when no part is translated.
 */
function swiftling_base_pairs( $kind, $name, $locale ) {
	$object = swiftling_base_object( $kind, $name );
	$parts  = $object ? swiftling_base_parts( $object->rewrite ) : array();
	if ( ! $parts ) {
		return array();
	}
	$owners = array();
	foreach ( array_keys( $parts ) as $index ) {
		$owners[ $index ] = swiftling_base_owner( $kind . ':' . $name, $index );
	}
	swiftling_prime_slugs( $owners, $locale );
	$pairs      = array();
	$translated = false;
	foreach ( $parts as $index => $part ) {
		$slug       = swiftling_get_slug( $owners[ $index ], $locale );
		$translated = $translated || '' !== $slug;
		$pairs[]    = array( $part, '' !== $slug ? $slug : $part );
	}
	return $translated ? $pairs : array();
}

/**
 * Replaces the parts of a list's word in the path of an address, from its start: the word comes
 * before the slugs of the term or post, which may be the same word.
 *
 * @param string $url   Address.
 * @param array  $pairs List of array( original, translated ), see swiftling_base_pairs().
 * @return string
 */
function swiftling_replace_base( $url, $pairs ) {
	if ( ! $pairs || ! preg_match( '~^((?:[a-z][a-z0-9+.\-]*:)?//[^/?#]*)?([^?#]*)(.*)$~is', $url, $parts ) ) {
		return $url;
	}
	$segments = explode( '/', $parts[2] );
	// After the site's own folder, when WordPress is in one.
	$home  = trim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
	$index = '' === $home ? 0 : count( explode( '/', $home ) ) + 1;
	$count = count( $segments );
	foreach ( $pairs as $pair ) {
		for ( ; $index < $count; $index++ ) {
			if ( $segments[ $index ] === $pair[0] ) {
				$segments[ $index ] = $pair[1];
				++$index;
				continue 2;
			}
		}
		break;
	}
	return $parts[1] . implode( '/', $segments ) . $parts[3];
}

/**
 * The address of a content type's archive, when it starts with the type's word: the word in the
 * language of the link. An archive with a word of its own, such as WooCommerce's shop page, keeps it.
 *
 * @param string $link      Address.
 * @param string $post_type Post type.
 * @return string
 */
function swiftling_translate_archive_link( $link, $post_type ) {
	$locale = swiftling_link_locale();
	$type   = get_post_type_object( $post_type );
	if ( '' === $locale || ! is_string( $link ) || ! $type || ! swiftling_has_slugs( $locale ) ) {
		return $link;
	}
	$own = is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ? trim( (string) $type->rewrite['slug'], '/' ) : '';
	if ( true !== $type->has_archive && ( '' === $own || trim( (string) $type->has_archive, '/' ) !== $own ) ) {
		return $link;
	}
	return swiftling_replace_base( $link, swiftling_base_pairs( 'type', $post_type, $locale ) );
}
add_filter( 'post_type_archive_link', 'swiftling_translate_archive_link', 10, 2 );

/**
 * Returns the name of the language of a locale.
 *
 * @param string $locale Locale.
 * @return string
 */
function swiftling_base_language_name( $locale ) {
	foreach ( swiftling_languages()['targets'] as $language ) {
		if ( $language['locale'] === $locale ) {
			return (string) $language['name'];
		}
	}
	return $locale;
}

/**
 * Saves the translation of a part of a list's word in a language.
 *
 * Empty, or the original, removes it: the language uses the original word again, and the earlier
 * translation leads there.
 *
 * @param string $owner  Object key, e.g. "base:tax:category:0".
 * @param string $locale Locale.
 * @param string $word   Word, or a text to make one from.
 * @return string|WP_Error The word saved, an empty string for the original, or why it was refused.
 */
function swiftling_save_base( $owner, $locale, $word ) {
	$original = swiftling_base_original( $owner );
	if ( '' === $original ) {
		return new WP_Error( 'swiftling_base_list', __( 'This list has no such word in its addresses.', 'swiftling' ) );
	}
	$word = swiftling_sanitize_slug( $word, $locale );
	if ( '' === $word || $word === $original ) {
		return null === swiftling_slug_entry( $owner, $locale ) ? '' : swiftling_save_slug( $owner, $locale, '' );
	}
	$taken = swiftling_base_taken( $owner, $locale, $word );
	if ( '' !== $taken ) {
		return new WP_Error( 'swiftling_base_taken', $taken );
	}
	return swiftling_save_slug( $owner, $locale, $word );
}

/**
 * Says why a word cannot be the translation of a part of a list's word in a language.
 *
 * @param string $owner  Object key, e.g. "base:tax:category:0".
 * @param string $locale Locale.
 * @param string $word   Word, as WordPress writes slugs.
 * @return string Empty when the word is free.
 */
function swiftling_base_taken( $owner, $locale, $word ) {
	$taken = swiftling_slug_taken_by( $word, $owner, $locale );
	if ( null === $taken ) {
		return '';
	}
	/* translators: 1: a word, e.g. kategorie, 2: language name, e.g. Deutsch, 3: what has it, e.g. Page “Kontakt”. */
	return sprintf( __( '“%1$s” is already taken in %2$s, by %3$s. Choose another word.', 'swiftling' ), rawurldecode( $word ), swiftling_base_language_name( $locale ), $taken );
}

/**
 * Saves the words of a language from the Addresses screen. A word that is taken is not saved; the
 * screen shows it again as typed, with what has it. Every page's address can change, so every page
 * is emptied from caches.
 */
function swiftling_save_bases() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the addresses of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_save_bases' );

	$lang     = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
	$language = swiftling_target_language( $lang );
	$args     = array( 'lang' => $lang );
	if ( ! $language || ! swiftling_directories_supported() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=swiftling-addresses' ) ) );
		exit;
	}

	$posted  = isset( $_POST['bases'] ) && is_array( $_POST['bases'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['bases'] ) ) : array();
	$changed = false;
	foreach ( swiftling_base_lists() as $key => $list ) {
		foreach ( $list['parts'] as $index => $part ) {
			$owner = swiftling_base_owner( $key, $index );
			if ( ! isset( $posted[ $owner ] ) ) {
				continue;
			}
			$word = swiftling_sanitize_slug( $posted[ $owner ], $language['locale'] );
			$want = $word === $part ? '' : $word;
			if ( swiftling_get_slug( $owner, $language['locale'] ) === $want ) {
				continue;
			}
			$saved = swiftling_save_base( $owner, $language['locale'], $word );
			if ( is_wp_error( $saved ) ) {
				$args[ 'swiftling_refused[' . rawurlencode( $owner ) . ']' ] = rawurlencode( rawurldecode( $word ) );
				continue;
			}
			$changed = true;
		}
	}
	if ( $changed ) {
		swiftling_purge_all_later();
	}
	$args['swiftling_notice'] = 'bases_saved';
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=swiftling-addresses' ) ) );
	exit;
}
add_action( 'admin_post_swiftling_save_bases', 'swiftling_save_bases' );

/**
 * Returns what the editor says under the address of a term or post about the word in front of it,
 * in a language: the word, whether it is translated, and where to change it.
 *
 * @param string $kind tax or type.
 * @param string $name Taxonomy or post type.
 * @param string $lang Address slug of the language.
 * @return array{text: string, link: string, url: string}|null Null without a word, or with plain permalinks.
 */
function swiftling_base_note( $kind, $name, $lang ) {
	$language = swiftling_target_language( $lang );
	$lists    = swiftling_base_lists();
	$key      = $kind . ':' . $name;
	if ( ! $language || ! swiftling_directories_supported() || ! isset( $lists[ $key ] ) ) {
		return null;
	}
	$words      = array();
	$translated = false;
	foreach ( $lists[ $key ]['parts'] as $index => $part ) {
		$slug       = swiftling_get_slug( swiftling_base_owner( $key, $index ), $language['locale'] );
		$translated = $translated || '' !== $slug;
		$words[]    = rawurldecode( '' !== $slug ? $slug : $part );
	}
	$url = add_query_arg( 'lang', $lang, admin_url( 'admin.php?page=swiftling-addresses' ) );
	if ( $translated ) {
		return array(
			/* translators: 1: a word, e.g. kategorie, 2: name of a list, e.g. Categories, 3: language name, e.g. Deutsch. */
			'text' => sprintf( __( '“%1$s” starts every address of %2$s in %3$s.', 'swiftling' ), implode( '/', $words ), $lists[ $key ]['label'], $language['name'] ),
			'link' => __( 'Change it on URLs', 'swiftling' ),
			'url'  => $url,
		);
	}
	return array(
		/* translators: 1: a word, e.g. category, 2: language name, e.g. Deutsch. */
		'text' => sprintf( __( '“%1$s” is still the original word in %2$s.', 'swiftling' ), implode( '/', $words ), $language['name'] ),
		'link' => __( 'Translate it on URLs', 'swiftling' ),
		'url'  => $url,
	);
}

/**
 * Prints what swiftling_base_note() says, as a line under the address field.
 *
 * @param string $kind tax or type.
 * @param string $name Taxonomy or post type.
 * @param string $lang Address slug of the language.
 */
function swiftling_print_base_note( $kind, $name, $lang ) {
	$note = swiftling_base_note( $kind, $name, $lang );
	if ( null === $note ) {
		return;
	}
	if ( ! \SwiftLing\Admin\Ui::opens( $note['url'] ) ) {
		printf( '<p class="swiftling-muted swiftling-base-note">%s</p>', esc_html( $note['text'] ) );
		return;
	}
	printf(
		'<p class="swiftling-muted swiftling-base-note">%s <a href="%s">%s</a></p>',
		esc_html( $note['text'] ),
		esc_url( $note['url'] ),
		esc_html( $note['link'] )
	);
}
