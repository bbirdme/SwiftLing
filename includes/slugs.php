<?php
/**
 * Translated slugs: the address of a post or a term in each language, such as /de/kontaktieren/
 * for the page /kontakt/.
 *
 * A slug is no text of the page. An empty one means the original's, so neither the state of a page
 * nor whether it counts as translated depends on it. Slugs live in a table of their own, one row
 * per object, language and slug, so a request finds its object by the slug in one indexed query:
 *
 * - object_key: "post:12" or "term:5", or "base:tax:category:0" for the word in front of a list's
 *   addresses, see includes/bases.php;
 * - lang: the locale, as translations are stored, so a language's address can change;
 * - slug: as WordPress writes slugs (sanitize_title()), at most SWIFTLING_SLUG_LENGTH characters;
 * - current: 1 for the address now, 0 for an earlier one, which leads to the address now;
 * - status: SWIFTLING_MACHINE when SwiftLing made it from the translated title, SWIFTLING_REVIEWED
 *   when a person typed it.
 *
 * In its language a translated slug is unique among every post and term, and it is never the
 * original slug of another post or term, nor a word WordPress's addresses use, such as "page" or a
 * language's folder. So a request needs no rewrite rules of its own: before WordPress reads the
 * address, every translated slug in it is put back as the original's, and every permalink
 * structure, page hierarchy and archive works as in the original. The address is put back as asked
 * once WordPress has read it, so the rest of the request sees what the visitor asked for.
 *
 * Links WordPress builds, with get_permalink(), get_term_link() and in menus, carry the slugs of the
 * language the page is shown in, or of the language swiftling_in_link_language() asks for, as the
 * language switcher and hreflang do for the other languages. In the parameter format a page's links
 * have no language and keep the original's slugs, see swiftling_link_locale(). A page in the
 * original language reads nothing.
 *
 * A request in a language that names a post or a term by another of its addresses, the original
 * slug or an earlier translated one, is sent to its address now, permanently, as WordPress sends an
 * old slug to the new one.
 *
 * Translated slugs need pretty permalinks: with plain ones, addresses name posts by their ID. Links
 * written into content, such as a link in a paragraph, keep the slug they were saved with and lead to
 * the translated address through that redirect.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Longest translated slug: what an index of the table holds whole.
 */
const SWIFTLING_SLUG_LENGTH = 191;

/**
 * Returns the caches of the request: whether a language has slugs, and the slug of each object.
 *
 * @return array{has: array<string, bool>, slugs: array<string, array<string, string>>}
 */
function &swiftling_slug_cache() {
	static $cache = array(
		'has'   => array(),
		'slugs' => array(),
	);
	return $cache;
}

/**
 * Forgets what the request read of the slugs, after they changed.
 */
function swiftling_forget_slugs() {
	$cache = &swiftling_slug_cache();
	$cache = array(
		'has'   => array(),
		'slugs' => array(),
	);
	swiftling_forget_object_addresses();
	swiftling_flush_cache();
}

/**
 * Whether a language has any translated slug, so a site without them reads nothing more. Every
 * language is asked at once: a page links to itself in all of them.
 *
 * @param string $locale Locale.
 * @return bool
 */
function swiftling_has_slugs( $locale ) {
	$cache = &swiftling_slug_cache();
	if ( ! isset( $cache['has'][ $locale ] ) ) {
		$key  = 'has-slugs:' . swiftling_cache_version();
		$with = wp_cache_get( $key, 'swiftling' );
		if ( ! is_array( $with ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, cached below; the languages are the first column of its key.
			$with = array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT lang FROM %i', swiftling_table( 'slugs' ) ) ) );
			wp_cache_set( $key, $with, 'swiftling', HOUR_IN_SECONDS );
		}
		$cache['has'] = array_fill_keys( $with, true );
		if ( ! isset( $cache['has'][ $locale ] ) ) {
			$cache['has'][ $locale ] = false;
		}
	}
	return $cache['has'][ $locale ];
}

/**
 * Reads the slugs of several objects at once, so a list of posts or a menu costs one query. They are
 * read in every language that has slugs, as the language switcher and the hreflang links ask for
 * the same objects in each.
 *
 * @param string[] $owners Object keys, e.g. "post:12".
 * @param string   $locale Locale asked for.
 */
function swiftling_prime_slugs( $owners, $locale ) {
	global $wpdb;
	$cache   = &swiftling_slug_cache();
	$missing = array();
	foreach ( array_unique( array_map( 'strval', $owners ) ) as $owner ) {
		if ( '' !== $owner && ! isset( $cache['slugs'][ $locale ][ $owner ] ) ) {
			$missing[] = $owner;
		}
	}
	if ( ! $missing ) {
		return;
	}
	if ( ! swiftling_has_slugs( $locale ) ) {
		foreach ( $missing as $owner ) {
			$cache['slugs'][ $locale ][ $owner ] = '';
		}
		return;
	}
	// Every language with slugs, the one asked for among them.
	$locales = array_keys( array_filter( $cache['has'] ) );
	foreach ( $locales as $each ) {
		foreach ( $missing as $owner ) {
			if ( ! isset( $cache['slugs'][ $each ][ $owner ] ) ) {
				$cache['slugs'][ $each ][ $owner ] = '';
			}
		}
	}
	$in_locales = implode( ',', array_fill( 0, count( $locales ), '%s' ) );
	foreach ( array_chunk( $missing, 500 ) as $chunk ) {
		$sql = 'SELECT object_key, lang, slug FROM %i WHERE current = 1 AND lang IN (' . $in_locales . ') AND object_key IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table, kept for the request; $sql has one placeholder per language and object.
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql, swiftling_table( 'slugs' ), ...array_merge( $locales, $chunk ) ) ) as $row ) {
			$cache['slugs'][ (string) $row->lang ][ $row->object_key ] = (string) $row->slug;
		}
	}
}

/**
 * Returns the translated slug of a post or term.
 *
 * @param string $owner  Object key, e.g. "post:12".
 * @param string $locale Locale.
 * @return string Empty when the language uses the original slug.
 */
function swiftling_get_slug( $owner, $locale ) {
	if ( '' === $locale ) {
		return '';
	}
	swiftling_prime_slugs( array( $owner ), $locale );
	$cache = &swiftling_slug_cache();
	return $cache['slugs'][ $locale ][ $owner ];
}

/**
 * Returns the translated slug of a post or term with how it was made, for the editor.
 *
 * @param string $owner  Object key.
 * @param string $locale Locale.
 * @return array{slug: string, state: string}|null State machine or reviewed; null without one.
 */
function swiftling_slug_entry( $owner, $locale ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read on the editor screen.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT slug, status FROM %i WHERE object_key = %s AND lang = %s AND current = 1', swiftling_table( 'slugs' ), $owner, $locale ) );
	return $row ? array(
		'slug'  => (string) $row->slug,
		'state' => swiftling_status_state( (int) $row->status ),
	) : null;
}

/**
 * Returns the original slug of a post or term, as its addresses carry it.
 *
 * @param string $owner Object key.
 * @return string
 */
function swiftling_original_slug( $owner ) {
	if ( 0 === strpos( $owner, 'post:' ) ) {
		$post = get_post( (int) substr( $owner, 5 ) );
		return $post ? (string) $post->post_name : '';
	}
	if ( 0 === strpos( $owner, 'term:' ) ) {
		$term = get_term( (int) substr( $owner, 5 ) );
		return $term instanceof WP_Term ? (string) $term->slug : '';
	}
	if ( 0 === strpos( $owner, 'base:' ) ) {
		return swiftling_base_original( $owner );
	}
	return '';
}

/**
 * Writes a slug as WordPress writes one, at most SWIFTLING_SLUG_LENGTH characters long, without
 * cutting a character in two.
 *
 * As WordPress writes it on a site in the slug's language: WordPress spells letters with accents by
 * the rules of a language (remove_accents()), as German ü as ue, so "Über uns" is ueber-uns in
 * German, where English makes it uber-uns. Without a language, by the rules of the original
 * language, also on a page in another one, where WordPress's own language is that one's.
 *
 * @param string $text   Slug or text, such as a translated title.
 * @param string $locale Locale of the slug's language, or empty for the original's: required, so no
 *                       caller forgets it.
 * @return string
 */
function swiftling_sanitize_slug( $text, $locale ) {
	$locale = '' !== $locale ? $locale : (string) swiftling_languages()['source']['locale'];
	$slug   = sanitize_title( remove_accents( wp_strip_all_tags( (string) $text ), $locale ) );
	if ( strlen( $slug ) <= SWIFTLING_SLUG_LENGTH ) {
		return $slug;
	}
	return trim( strtolower( utf8_uri_encode( rawurldecode( $slug ), SWIFTLING_SLUG_LENGTH ) ), '-' );
}

/**
 * Whether a slug is a word WordPress's addresses use, which no post or term may take in another
 * language: a number, a page or feed of a list, the base of an archive, or a language's folder.
 *
 * @param string $slug Slug.
 * @return bool
 */
function swiftling_is_reserved_slug( $slug ) {
	if ( '' === $slug || ctype_digit( $slug ) || 1 === preg_match( '/^comment-page-\d+$/', $slug ) ) {
		return true;
	}
	$reserved = array( 'page', 'feed', 'embed', 'trackback', 'attachment', 'comments', 'search', 'author', 'rss', 'rss2', 'atom', 'rdf', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', rest_get_url_prefix() );
	foreach ( array(
		'category_base' => 'category',
		'tag_base'      => 'tag',
	) as $option => $base ) {
		$value      = trim( (string) get_option( $option ), '/' );
		$reserved[] = '' !== $value ? $value : $base;
	}
	foreach ( array_merge( get_post_types( array( 'public' => true ), 'objects' ), get_taxonomies( array( 'public' => true ), 'objects' ) ) as $type ) {
		if ( is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ) {
			$reserved[] = trim( (string) $type->rewrite['slug'], '/' );
		}
	}
	$languages  = swiftling_languages();
	$reserved[] = $languages['source']['slug'];
	foreach ( array_keys( $languages['targets'] ) as $folder ) {
		$reserved[] = (string) $folder;
	}
	return in_array( $slug, $reserved, true );
}

/**
 * Whether a slug cannot be the translated slug of an object in a language: it is reserved, another
 * object's translated slug in that language, or the original slug of another post or term visitors
 * can open.
 *
 * @param string $slug   Slug.
 * @param string $owner  Object key of the object it would be for.
 * @param string $locale Locale.
 * @return bool
 */
function swiftling_slug_taken( $slug, $owner, $locale ) {
	return null !== swiftling_slug_taken_by( $slug, $owner, $locale );
}

/**
 * Returns what has a slug in a language, so it cannot be another object's translated slug there,
 * see swiftling_slug_taken().
 *
 * @param string $slug   Slug.
 * @param string $owner  Object key of the object it would be for.
 * @param string $locale Locale.
 * @return string|null What has it, as a person reads it, such as Page “Kontakt”; null when it is free.
 */
function swiftling_slug_taken_by( $slug, $owner, $locale ) {
	global $wpdb;
	if ( swiftling_is_reserved_slug( $slug ) ) {
		return __( 'a word WordPress uses in addresses, or a language\'s folder', 'swiftling' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read when a slug is saved.
	$other = $wpdb->get_var( $wpdb->prepare( 'SELECT object_key FROM %i WHERE lang = %s AND slug = %s AND current = 1 AND object_key <> %s LIMIT 1', swiftling_table( 'slugs' ), $locale, $slug, $owner ) );
	if ( $other ) {
		return swiftling_slug_owner_name( (string) $other );
	}

	$types = array_values( get_post_types( array( 'public' => true ) ) );
	$post  = 0 === strpos( $owner, 'post:' ) ? (int) substr( $owner, 5 ) : 0;
	$sql   = 'SELECT ID FROM %i WHERE post_name = %s AND ID <> %d AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') LIMIT 1';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read when a slug is saved; post_name is indexed; $sql has one placeholder per type.
	$other = $types ? (int) $wpdb->get_var( $wpdb->prepare( $sql, $wpdb->posts, $slug, $post, ...$types ) ) : 0;
	if ( $other ) {
		return swiftling_slug_owner_name( 'post:' . $other );
	}

	$taxonomies = array_values( get_taxonomies( array( 'public' => true ) ) );
	$term       = 0 === strpos( $owner, 'term:' ) ? (int) substr( $owner, 5 ) : 0;
	$sql        = 'SELECT t.term_id FROM %i t JOIN %i x ON x.term_id = t.term_id WHERE t.slug = %s AND t.term_id <> %d AND x.taxonomy IN (' . implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) ) . ') LIMIT 1';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read when a slug is saved; slug is indexed; $sql has one placeholder per taxonomy.
	$other = $taxonomies ? (int) $wpdb->get_var( $wpdb->prepare( $sql, $wpdb->terms, $wpdb->term_taxonomy, $slug, $term, ...$taxonomies ) ) : 0;
	return $other ? swiftling_slug_owner_name( 'term:' . $other ) : null;
}

/**
 * Returns the name of the object a slug belongs to, as a person reads it.
 *
 * @param string $owner Object key.
 * @return string For example Page “Kontakt”, Category “Vijesti” or the word of Categories.
 */
function swiftling_slug_owner_name( $owner ) {
	/* translators: 1: kind of content, e.g. Page, 2: its title. */
	$named = __( '%1$s “%2$s”', 'swiftling' );
	if ( 0 === strpos( $owner, 'post:' ) ) {
		$post = get_post( (int) substr( $owner, 5 ) );
		$type = $post ? get_post_type_object( $post->post_type ) : null;
		if ( $post && $type ) {
			return sprintf( $named, $type->labels->singular_name, $post->post_title );
		}
	}
	if ( 0 === strpos( $owner, 'term:' ) ) {
		$term     = get_term( (int) substr( $owner, 5 ) );
		$taxonomy = $term instanceof WP_Term ? get_taxonomy( $term->taxonomy ) : null;
		if ( $taxonomy ) {
			return sprintf( $named, $taxonomy->labels->singular_name, $term->name );
		}
	}
	$base   = swiftling_parse_base_owner( $owner );
	$object = $base ? swiftling_base_object( $base['kind'], $base['name'] ) : null;
	if ( $object ) {
		/* translators: %s: name of a list, e.g. Categories. */
		return sprintf( __( 'the word in front of %s', 'swiftling' ), $object->labels->name );
	}
	return __( 'another address', 'swiftling' );
}

/**
 * Returns a slug no other object has in a language, adding -2, -3 and so on as WordPress does.
 *
 * @param string $slug   Slug, already sanitized.
 * @param string $owner  Object key.
 * @param string $locale Locale.
 * @return string
 */
function swiftling_unique_slug( $slug, $owner, $locale ) {
	$base   = $slug;
	$number = 2;
	while ( swiftling_slug_taken( $slug, $owner, $locale ) ) {
		$tail = '-' . $number;
		++$number;
		$slug = trim( strtolower( utf8_uri_encode( rawurldecode( $base ), SWIFTLING_SLUG_LENGTH - strlen( $tail ) ) ), '-' ) . $tail;
	}
	return $slug;
}

/**
 * Saves the translated slug of a post or a term in a language.
 *
 * The slug is written as WordPress writes slugs and made unique, see swiftling_slug_taken(). The
 * slug it replaces stays as an earlier address, which leads to the new one. An empty slug, or the
 * original's, removes the translation: the language uses the original slug again, and the earlier
 * translated ones lead there.
 *
 * @param string $owner  Object key, e.g. "post:12".
 * @param string $locale Locale.
 * @param string $slug   Slug, or a text to make one from.
 * @param int    $status SWIFTLING_REVIEWED when a person typed it, SWIFTLING_MACHINE when made from a translation.
 * @return string The slug saved, or an empty string when the language uses the original slug.
 */
function swiftling_save_slug( $owner, $locale, $slug, $status = SWIFTLING_REVIEWED ) {
	global $wpdb;
	$table    = swiftling_table( 'slugs' );
	$original = swiftling_original_slug( $owner );
	$slug     = swiftling_sanitize_slug( $slug, $locale );
	if ( '' !== $slug && $slug !== $original ) {
		$asked = $slug;
		$slug  = swiftling_unique_slug( $slug, $owner, $locale );
		if ( $slug !== $asked ) {
			$language = swiftling_language_by_locale( $locale );
			swiftling_log(
				'warning',
				'addresses',
				/* translators: 1: address asked for, 2: language name, e.g. Deutsch, 3: address saved instead. */
				sprintf( __( 'The address “%1$s” is taken in %2$s; “%3$s” was saved instead.', 'swiftling' ), rawurldecode( $asked ), $language ? $language['name'] : $locale, rawurldecode( $slug ) ),
				array( 'object' => $owner )
			);
		}
	}

	$now = swiftling_slug_entry( $owner, $locale );
	if ( $now && $now['slug'] === $slug ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table; the cache is flushed below.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %d WHERE object_key = %s AND lang = %s AND current = 1', $table, $status, $owner, $locale ) );
		swiftling_forget_slugs();
		return $slug;
	}

	// The address now becomes an earlier one, which leads to the new one.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table; the cache is flushed below.
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET current = 0 WHERE object_key = %s AND lang = %s AND current = 1', $table, $owner, $locale ) );

	if ( '' === $slug || $slug === $original ) {
		swiftling_forget_slugs();
		return '';
	}

	// An earlier address of another object gives way; an earlier one of this object becomes current again.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table; the cache is flushed below.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE lang = %s AND slug = %s AND object_key <> %s', $table, $locale, $slug, $owner ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table; the cache is flushed below.
	$wpdb->query( $wpdb->prepare( 'REPLACE INTO %i (object_key, lang, slug, current, status) VALUES (%s, %s, %s, 1, %d)', $table, $owner, $locale, $slug, $status ) );

	swiftling_forget_slugs();
	return $slug;
}

/**
 * Makes the translated slug of a post from its translated title, when the title's translation was
 * just saved and the post never had a translated slug in the language: one a person removed stays
 * removed.
 *
 * @param WP_Post $post   Post.
 * @param string  $locale Locale.
 * @param array   $items  Translations just saved: place key => text and status.
 * @return string The slug made, or an empty string.
 */
function swiftling_slug_from_title( $post, $locale, $items ) {
	global $wpdb;
	$owner = 'post:' . $post->ID;
	$maps  = swiftling_get_maps( array( $owner ) );
	$key   = swiftling_path_key( 'field/post_title' );
	if ( ! isset( $maps[ $owner ]['units'][ $key ] ) || '' === $post->post_name ) {
		return '';
	}
	$places = swiftling_map_places( $owner, $maps[ $owner ] );
	$place  = $places[ $key ][0];
	if ( ! isset( $items[ $place ] ) || '' === trim( $items[ $place ]['text'] ) ) {
		return '';
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read when a title is saved.
	if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE object_key = %s AND lang = %s LIMIT 1', swiftling_table( 'slugs' ), $owner, $locale ) ) ) {
		return '';
	}
	return swiftling_save_slug( $owner, $locale, $items[ $place ]['text'], SWIFTLING_MACHINE );
}

/**
 * Removes the slugs of a post or a term that is deleted.
 *
 * @param string $owner Object key.
 */
function swiftling_delete_slugs( $owner ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table; the cache is flushed below.
	if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE object_key = %s', swiftling_table( 'slugs' ), $owner ) ) ) {
		swiftling_forget_slugs();
	}
}

/**
 * Removes the slugs of a deleted post.
 *
 * @param int $post_id Post ID.
 */
function swiftling_delete_post_slugs( $post_id ) {
	swiftling_delete_slugs( 'post:' . (int) $post_id );
}
add_action( 'deleted_post', 'swiftling_delete_post_slugs' );

/**
 * Removes the slugs of a deleted term.
 *
 * @param int $term_id Term ID.
 */
function swiftling_delete_term_slugs( $term_id ) {
	swiftling_delete_slugs( 'term:' . (int) $term_id );
}
add_action( 'delete_term', 'swiftling_delete_term_slugs' );

/* Links: the slugs of the language the link is for. */

/**
 * Returns the language a link asked for, swiftling_in_link_language(), set for the time being.
 *
 * @return string|null Locale, an empty string for the original, or null when nothing asks.
 */
function &swiftling_link_locale_override() {
	static $locale = null;
	return $locale;
}

/**
 * Returns the language of the links WordPress builds now: the one swiftling_in_link_language()
 * asks for, otherwise the language the page is shown in.
 *
 * Only in the directory and the host formats: there a link keeps the page's language through its
 * folder or host, see swiftling_keep_folder() and swiftling_keep_host(). In the parameter format the links WordPress builds have no ?lang= and
 * lead to the original, so they keep its slugs; the switcher, hreflang and sitemaps ask for a
 * language and add it.
 *
 * @return string Locale, or an empty string for the original.
 */
function swiftling_link_locale() {
	$override = &swiftling_link_locale_override();
	if ( null !== $override ) {
		return $override;
	}
	$state = &swiftling_state();
	return 'translate' === $state['mode'] && ( 'directory' === swiftling_url_format() || swiftling_is_host_format() ) ? (string) $state['locale'] : '';
}

/**
 * Runs code that builds links, such as get_permalink(), with the slugs of a language.
 *
 * @param string   $slug  Address slug of a target language, or an empty string for the original.
 * @param callable $build Code that builds the link.
 * @return mixed What the code returns.
 */
function swiftling_in_link_language( $slug, $build ) {
	$language = '' === $slug ? null : swiftling_target_language( $slug );
	$override = &swiftling_link_locale_override();
	$saved    = $override;
	$override = $language ? $language['locale'] : '';
	try {
		return call_user_func( $build );
	} finally {
		$override = $saved;
	}
}

/**
 * Replaces slugs in the path of an address, from its end: each pair once, in order, so a post's
 * own slug is replaced and not the same word earlier in the path, such as its category.
 *
 * @param string $url   Address, or a path such as the one get_page_uri() returns.
 * @param array  $pairs List of array( original slug, translated slug ), the object first, then its parents.
 * @return string
 */
function swiftling_replace_slugs( $url, $pairs ) {
	if ( ! preg_match( '~^((?:[a-z][a-z0-9+.\-]*:)?//[^/?#]*)?([^?#]*)(.*)$~is', $url, $parts ) ) {
		return $url;
	}
	$segments = explode( '/', $parts[2] );
	$index    = count( $segments ) - 1;
	foreach ( $pairs as $pair ) {
		for ( ; $index >= 0; $index-- ) {
			if ( $segments[ $index ] === $pair[0] ) {
				$segments[ $index ] = $pair[1];
				--$index;
				continue 2;
			}
		}
		break;
	}
	return $parts[1] . implode( '/', $segments ) . $parts[3];
}

/**
 * Returns the slugs of an object and its parents as pairs for swiftling_replace_slugs(), each
 * translated into a language or kept.
 *
 * @param array<string, string> $chain  Object key => original slug, the object first.
 * @param string                $locale Locale.
 * @return array
 */
function swiftling_slug_pairs( $chain, $locale ) {
	swiftling_prime_slugs( array_keys( $chain ), $locale );
	$pairs = array();
	foreach ( $chain as $owner => $original ) {
		$slug    = swiftling_get_slug( $owner, $locale );
		$pairs[] = array( $original, '' !== $slug ? $slug : $original );
	}
	return $pairs;
}

/**
 * The address of a post that is no page: its slug in the language of the link, and the word of its
 * content type in front. A page and other hierarchical types get their slugs through get_page_uri(),
 * see swiftling_translate_page_uri(), and the word here.
 *
 * @param string      $link      Address.
 * @param WP_Post|int $post      Post.
 * @param bool        $leavename Whether the address keeps a placeholder for the slug, as the editor asks.
 * @return string
 */
function swiftling_translate_post_link( $link, $post, $leavename = false ) {
	$locale = swiftling_link_locale();
	if ( '' === $locale || $leavename || ! is_string( $link ) || ! swiftling_has_slugs( $locale ) ) {
		return $link;
	}
	$post = get_post( $post );
	if ( ! $post ) {
		return $link;
	}
	if ( '' !== $post->post_name && ! is_post_type_hierarchical( $post->post_type ) ) {
		$link = swiftling_replace_slugs( $link, swiftling_slug_pairs( array( 'post:' . $post->ID => $post->post_name ), $locale ) );
	}
	// The word of its content type in front, for a hierarchical type too, see includes/bases.php.
	return swiftling_replace_base( $link, swiftling_base_pairs( 'type', $post->post_type, $locale ) );
}
add_filter( 'post_link', 'swiftling_translate_post_link', 5, 3 );
add_filter( 'post_type_link', 'swiftling_translate_post_link', 5, 3 );

/**
 * The path of a page and of every hierarchical type, which WordPress builds from the slugs of the
 * page and its parents: each slug in the language of the link.
 *
 * @param string      $uri  Path, such as "o-nama/tim".
 * @param WP_Post|int $page Page.
 * @return string
 */
function swiftling_translate_page_uri( $uri, $page ) {
	$locale = swiftling_link_locale();
	$page   = get_post( $page );
	if ( '' === $locale || ! $page || ! is_string( $uri ) || ! swiftling_has_slugs( $locale ) ) {
		return $uri;
	}
	$chain = array( 'post:' . $page->ID => $page->post_name );
	foreach ( get_post_ancestors( $page ) as $ancestor ) {
		$chain[ 'post:' . $ancestor ] = (string) get_post_field( 'post_name', $ancestor );
	}
	return swiftling_replace_slugs( $uri, swiftling_slug_pairs( $chain, $locale ) );
}
add_filter( 'get_page_uri', 'swiftling_translate_page_uri', 10, 2 );

/**
 * The address of a category, tag or other term: its slug, those of its parents where the address
 * shows them, and the word of its list in front, in the language of the link.
 *
 * @param string  $link     Address.
 * @param WP_Term $term     Term.
 * @param string  $taxonomy Taxonomy.
 * @return string
 */
function swiftling_translate_term_link( $link, $term, $taxonomy ) {
	$locale = swiftling_link_locale();
	if ( '' === $locale || ! $term instanceof WP_Term || ! is_string( $link ) || ! swiftling_has_slugs( $locale ) ) {
		return $link;
	}
	$chain = array( 'term:' . $term->term_id => $term->slug );
	if ( is_taxonomy_hierarchical( $taxonomy ) ) {
		foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestor ) {
			$parent = get_term( $ancestor, $taxonomy );
			if ( $parent instanceof WP_Term ) {
				$chain[ 'term:' . $parent->term_id ] = $parent->slug;
			}
		}
	}
	$link = swiftling_replace_slugs( $link, swiftling_slug_pairs( $chain, $locale ) );
	return swiftling_replace_base( $link, swiftling_base_pairs( 'tax', $taxonomy, $locale ) );
}
add_filter( 'term_link', 'swiftling_translate_term_link', 10, 3 );

/**
 * Reads the slugs of the posts a query found, so their links cost one query together.
 *
 * @param WP_Post[] $posts Posts.
 * @return WP_Post[]
 */
function swiftling_prime_post_slugs( $posts ) {
	$locale = swiftling_link_locale();
	if ( '' !== $locale && is_array( $posts ) && $posts && swiftling_has_slugs( $locale ) ) {
		$owners = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$owners[] = 'post:' . $post->ID;
			}
		}
		swiftling_prime_slugs( $owners, $locale );
	}
	return $posts;
}
add_filter( 'the_posts', 'swiftling_prime_post_slugs' );

/**
 * Reads the slugs of what a menu links to, so the menu's links cost one query together.
 *
 * @param array $items Menu items.
 * @return array
 */
function swiftling_prime_menu_slugs( $items ) {
	$locale = swiftling_link_locale();
	if ( '' !== $locale && is_array( $items ) && $items && swiftling_has_slugs( $locale ) ) {
		$owners = array();
		foreach ( $items as $item ) {
			if ( isset( $item->type, $item->object_id ) && in_array( $item->type, array( 'post_type', 'taxonomy' ), true ) ) {
				$owners[] = ( 'post_type' === $item->type ? 'post:' : 'term:' ) . (int) $item->object_id;
			}
		}
		swiftling_prime_slugs( $owners, $locale );
	}
	return $items;
}
add_filter( 'wp_get_nav_menu_items', 'swiftling_prime_menu_slugs' );

/**
 * Returns the address of a post in a language, with its translated slug, as visitors open it there.
 *
 * @param WP_Post $post Post.
 * @param string  $slug Address slug of a target language, or an empty string for the original.
 * @return string
 */
function swiftling_post_language_url( $post, $slug ) {
	$base = swiftling_in_link_language(
		$slug,
		static function () use ( $post ) {
			return swiftling_unlocalized_url(
				static function () use ( $post ) {
					return (string) get_permalink( $post );
				}
			);
		}
	);
	return swiftling_language_url( $base, $slug );
}

/**
 * Puts the slugs of another language into the address of the page being shown, when it shows one
 * post or the archive of one term: the part of the address that is the object's address in the
 * page's language becomes its address in the other language, and the rest, such as the second
 * page of a list, stays.
 *
 * @param string      $url  Address of the page being shown, without a language, see swiftling_current_original_url().
 * @param string      $slug Address slug of the language, or an empty string for the original.
 * @param string|null $from Address slug of the language whose slugs $url has; the page's language
 *                          when null, as in the address of the request.
 * @return string
 */
function swiftling_current_slugs( $url, $slug, $from = null ) {
	$build = swiftling_current_object_link();
	if ( null === $build ) {
		return $url;
	}
	$state = &swiftling_state();
	$shown = null !== $from ? (string) $from : ( 'translate' === $state['mode'] ? (string) $state['language'] : '' );
	if ( $shown === $slug ) {
		return $url;
	}
	$path = static function ( $language ) use ( $build ) {
		$link = swiftling_in_link_language(
			$language,
			static function () use ( $build ) {
				return swiftling_unlocalized_url( $build );
			}
		);
		return (string) wp_parse_url( $link, PHP_URL_PATH );
	};
	$from = untrailingslashit( $path( $shown ) );
	$to   = untrailingslashit( $path( $slug ) );
	if ( $from === $to || ! preg_match( '~^((?:[a-z][a-z0-9+.\-]*:)?//[^/?#]*)?([^?#]*)(.*)$~is', $url, $parts ) || 0 !== strpos( $parts[2] . '/', $from . '/' ) ) {
		return $url;
	}
	return $parts[1] . $to . substr( $parts[2], strlen( $from ) ) . $parts[3];
}

/**
 * Whether an address, without a language, is the address of the post or term archive the page shows,
 * as WordPress builds it in the original: nothing needs looking up for it.
 *
 * @param string $url Address without a language.
 * @return bool
 */
function swiftling_is_shown_address( $url ) {
	$build = swiftling_current_object_link();
	if ( null === $build ) {
		return false;
	}
	$own  = (string) wp_parse_url(
		swiftling_in_link_language(
			'',
			static function () use ( $build ) {
				return swiftling_unlocalized_url( $build );
			}
		),
		PHP_URL_PATH
	);
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	return '' !== trim( $own, '/' ) && untrailingslashit( swiftling_normalize_address( $own ) ) === untrailingslashit( swiftling_normalize_address( $path ) );
}

/**
 * Returns an address of the site that names another post, page or term than the page shows, such as
 * a canonical address typed into an SEO plugin, with that object's slugs in a language: the address
 * WordPress builds for the object there. It names an object by its own address as WordPress builds
 * it in the original, in any form of percent-encoding, or, with no path, by a query (?p=,
 * ?page_id=, ?cat=). With a path, the path decides and the query is only a query. The rest of the
 * query and the fragment stay as typed. Any other address, as one of a draft or of nothing, stays
 * as it is, and the caller gives it the language's folder only.
 *
 * Remembered for the request, up to 200 addresses, and forgotten when a post, a term or a slug
 * changes, see swiftling_forget_object_addresses().
 *
 * @param string $url  Address without a language, see swiftling_address_without_language().
 * @param string $slug Address slug of a target language, or an empty string for the original.
 * @return string
 */
function swiftling_object_address( $url, $slug ) {
	$state = &swiftling_state();
	$key   = $slug . '|' . $url;
	if ( ! isset( $state['values']['object-addresses'] ) || count( $state['values']['object-addresses'] ) >= 200 ) {
		$state['values']['object-addresses'] = array();
	}
	if ( ! isset( $state['values']['object-addresses'][ $key ] ) ) {
		$state['values']['object-addresses'][ $key ] = swiftling_resolve_object_address( $url, $slug );
	}
	return $state['values']['object-addresses'][ $key ];
}

/**
 * Forgets the addresses swiftling_object_address() remembered, as what they name has changed.
 */
function swiftling_forget_object_addresses() {
	$state = &swiftling_state();
	unset( $state['values']['object-addresses'] );
}
add_action( 'transition_post_status', 'swiftling_forget_object_addresses' );
add_action( 'save_post', 'swiftling_forget_object_addresses' );
add_action( 'created_term', 'swiftling_forget_object_addresses' );
add_action( 'edited_term', 'swiftling_forget_object_addresses' );
add_action( 'delete_term', 'swiftling_forget_object_addresses' );

/**
 * Does the work of swiftling_object_address(), without remembering it.
 *
 * @param string $url  Address without a language.
 * @param string $slug Address slug of a target language, or an empty string for the original.
 * @return string
 */
function swiftling_resolve_object_address( $url, $slug ) {
	if ( ! preg_match( '~^((?:[a-z][a-z0-9+.\-]*:)?//[^/?#]*)?([^?#]*)(\?[^#]*)?(#.*)?$~s', $url, $parts ) ) {
		return $url;
	}
	$host  = $parts[1];
	$path  = $parts[2];
	$query = isset( $parts[3] ) ? substr( $parts[3], 1 ) : '';
	$frag  = isset( $parts[4] ) ? $parts[4] : '';
	$args  = array();
	parse_str( $query, $args );

	// Without a path of its own, a query names the object; with one, the path does. The site's
	// folder, as /blog, is no path of an object.
	$home     = trim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
	$by_query = trim( $path, '/' ) === $home;
	$builds   = swiftling_address_objects( $host . $path, $by_query ? $args : array() );

	// A link an object is built as, in a language: its path and query as WordPress writes them.
	$links = static function ( $build, $language ) {
		$link = swiftling_in_link_language(
			$language,
			static function () use ( $build ) {
				return swiftling_unlocalized_url( $build );
			}
		);
		return array( (string) wp_parse_url( $link, PHP_URL_PATH ), (string) wp_parse_url( $link, PHP_URL_QUERY ) );
	};
	// What was typed after the path, as typed, without the query that named the object.
	$kept = array();
	foreach ( '' === $query ? array() : explode( '&', $query ) as $piece ) {
		$name = urldecode( (string) strstr( $piece . '=', '=', true ) );
		if ( ! $by_query || ! in_array( $name, array( 'p', 'page_id', 'cat' ), true ) ) {
			$kept[] = $piece;
		}
	}

	foreach ( $builds as $build ) {
		list( $own_path ) = $links( $build, '' );
		// By a path, it must be the object's own, as WordPress builds it.
		if ( ! $by_query && untrailingslashit( swiftling_normalize_address( $own_path ) ) !== untrailingslashit( swiftling_normalize_address( $path ) ) ) {
			continue;
		}
		list( $new_path, $new_query ) = $links( $build, $slug );
		$rest                         = array_merge( '' === $new_query ? array() : array( $new_query ), $kept );
		return $host . $new_path . ( $rest ? '?' . implode( '&', $rest ) : '' ) . $frag;
	}
	return $url;
}

/**
 * Returns what builds the address of each post, page or term an address of the site may name, as
 * WordPress reads it: from a path, a post or page (url_to_postid()) and every term whose slug is
 * the last segment, in each taxonomy whose word, with a slash or without, is the run of segments
 * before it; from a query, with no path, ?p= and ?page_id=, or ?cat=. More than one may have the
 * slug, so the caller keeps the one whose own address is the path.
 *
 * @param string $address Address without a language, query or fragment.
 * @param array  $args    Query arguments that name an object, empty where a path does.
 * @return callable[] Each builds the address in the original, or empty when it names nothing.
 */
function swiftling_address_objects( $address, $args ) {
	$builds   = array();
	$path     = (string) wp_parse_url( $address, PHP_URL_PATH );
	$segments = array_values( array_filter( explode( '/', rawurldecode( $path ) ), 'strlen' ) );
	$id       = 0;
	foreach ( array( 'p', 'page_id' ) as $name ) {
		$id = $id ? $id : absint( isset( $args[ $name ] ) && is_scalar( $args[ $name ] ) ? $args[ $name ] : 0 );
	}
	if ( ! $id && $segments ) {
		$id = (int) swiftling_unlocalized_url(
			static function () use ( $address ) {
				return (string) url_to_postid( $address );
			}
		);
	}
	if ( $id && is_post_publicly_viewable( $id ) ) {
		$builds[] = static function () use ( $id ) {
			return (string) get_permalink( $id );
		};
	}

	$term_link = static function ( $term ) {
		return static function () use ( $term ) {
			$link = get_term_link( $term );
			return is_wp_error( $link ) ? '' : (string) $link;
		};
	};
	if ( ! empty( $args['cat'] ) && is_scalar( $args['cat'] ) ) {
		$term = get_term( absint( $args['cat'] ), 'category' );
		if ( $term instanceof WP_Term ) {
			$builds[] = $term_link( $term );
		}
	}
	if ( $segments ) {
		$last   = sanitize_title( (string) end( $segments ) );
		$before = '/' . implode( '/', array_slice( $segments, 0, -1 ) ) . '/';
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$base = is_array( $taxonomy->rewrite ) && ! empty( $taxonomy->rewrite['slug'] ) ? trim( (string) $taxonomy->rewrite['slug'], '/' ) : '';
			if ( '' === $base || '' === $last || false === strpos( $before, '/' . $base . '/' ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'slug'       => $last,
					'hide_empty' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$builds[] = $term_link( $term );
			}
		}
	}
	return $builds;
}

/**
 * Returns what builds the address of the post, term archive or content type archive the page shows,
 * in the language of the link: for a separate copy, its original's.
 *
 * @return callable|null Null on a page that shows neither.
 */
function swiftling_current_object_link() {
	$object = get_queried_object();
	if ( is_singular() && $object instanceof WP_Post ) {
		$original = swiftling_copy_original_id( $object );
		$post     = $original ? get_post( $original ) : $object;
		if ( ! $post || swiftling_real_front_page() === $post->ID ) {
			return null;
		}
		return static function () use ( $post ) {
			return (string) get_permalink( $post );
		};
	}
	if ( ( is_category() || is_tag() || is_tax() ) && $object instanceof WP_Term ) {
		return static function () use ( $object ) {
			$link = get_term_link( $object );
			return is_wp_error( $link ) ? '' : $link;
		};
	}
	// The archive of a content type, whose address is the word of the type, see includes/bases.php.
	if ( is_post_type_archive() && $object instanceof WP_Post_Type ) {
		return static function () use ( $object ) {
			return (string) get_post_type_archive_link( $object->name );
		};
	}
	return null;
}

/* Requests: the original slugs, for WordPress to read. */

/**
 * Returns the address of the request as the visitor asked for it, while WordPress reads one with
 * the original slugs.
 *
 * @return array{asked: string, read: string} Empty while no translated slug was put back.
 */
function &swiftling_slug_request() {
	static $request = array(
		'asked' => '',
		'read'  => '',
	);
	return $request;
}

/**
 * Puts back the original slug for every translated slug in the address of a request in another
 * language, just before WordPress reads it. An earlier translated slug counts too.
 *
 * @param bool $parse Whether WordPress reads the request.
 * @return bool
 */
function swiftling_untranslate_request( $parse ) {
	// A request of its own, also where one process reads several, as WP-CLI and tests do.
	$request = &swiftling_slug_request();
	$request = array(
		'asked' => '',
		'read'  => '',
	);

	$state = &swiftling_state();
	if ( ! $parse || 'translate' !== $state['mode'] || '' === $state['locale'] || ! isset( $_SERVER['REQUEST_URI'] ) || ! swiftling_has_slugs( $state['locale'] ) ) {
		return $parse;
	}
	// Sanitized as a URL, as everywhere else the address is read: esc_url_raw() leaves out line breaks
	// and characters such as < > " { } ^. WordPress reads that address, with the original slugs.
	$asked = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	$read  = swiftling_untranslate_path( $asked, $state['locale'] );
	if ( $read === $asked ) {
		return $parse;
	}
	$request['asked']       = $asked;
	$request['read']        = $read;
	$_SERVER['REQUEST_URI'] = wp_slash( $read );
	if ( isset( $_SERVER['PATH_INFO'] ) ) {
		$_SERVER['PATH_INFO'] = wp_slash( swiftling_untranslate_path( esc_url_raw( wp_unslash( $_SERVER['PATH_INFO'] ) ), $state['locale'] ) );
	}
	return $parse;
}
add_filter( 'do_parse_request', 'swiftling_untranslate_request', 1 );

/**
 * Puts the address back as the visitor asked for it, once WordPress has read it.
 */
function swiftling_restore_request() {
	$request = &swiftling_slug_request();
	if ( '' !== $request['asked'] ) {
		$_SERVER['REQUEST_URI'] = wp_slash( $request['asked'] );
	}
}
add_action( 'parse_request', 'swiftling_restore_request', 0 );

/**
 * Returns a path with the original slug for every translated slug of a language in it, after the
 * site address and its language folder.
 *
 * @param string $uri    Path, with a query.
 * @param string $locale Locale.
 * @return string
 */
function swiftling_untranslate_path( $uri, $locale ) {
	global $wpdb;
	$path  = (string) strtok( $uri, '?#' );
	$rest  = (string) substr( $uri, strlen( $path ) );
	$base  = untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );
	$start = $base;
	foreach ( swiftling_folders() as $folder ) {
		if ( preg_match( '#^' . preg_quote( $base . '/' . $folder, '#' ) . '(/|$)#', $path ) ) {
			$start = $base . '/' . $folder;
			break;
		}
	}
	// Before PHP 8, strpos() warns about an empty needle: a site at the root without a folder has one.
	if ( '' !== $start && 0 !== strpos( $path, $start ) ) {
		return $uri;
	}

	// Each segment as the language writes slugs, and as the original language does: a slug saved
	// before SwiftLing wrote them by the language's rules, as uber-uns for "Über uns" in German, is
	// still found when the address is typed with the letter itself.
	$segments = explode( '/', (string) substr( $path, strlen( $start ) ) );
	$slugs    = array();
	foreach ( $segments as $index => $segment ) {
		if ( '' === $segment ) {
			continue;
		}
		$forms = array_unique( array( swiftling_sanitize_slug( rawurldecode( $segment ), $locale ), swiftling_sanitize_slug( rawurldecode( $segment ), '' ) ) );
		$forms = array_values(
			array_filter(
				$forms,
				static function ( $slug ) {
					return '' !== $slug && ! ctype_digit( $slug );
				}
			)
		);
		if ( $forms ) {
			$slugs[ $index ] = $forms;
		}
	}
	if ( ! $slugs ) {
		return $uri;
	}

	$values = array_values( array_unique( array_merge( ...array_values( $slugs ) ) ) );
	$sql    = 'SELECT slug, object_key FROM %i WHERE lang = %s AND slug IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ') ORDER BY current';
	$owners = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Once per request in another language; $sql has one placeholder per slug.
	foreach ( $wpdb->get_results( $wpdb->prepare( $sql, swiftling_table( 'slugs' ), $locale, ...$values ) ) as $row ) {
		// A slug that is current for one object wins over an earlier one of another.
		$owners[ (string) $row->slug ] = (string) $row->object_key;
	}
	if ( ! $owners ) {
		return $uri;
	}

	$posts = array();
	foreach ( $owners as $owner ) {
		if ( 0 === strpos( $owner, 'post:' ) ) {
			$posts[] = (int) substr( $owner, 5 );
		}
	}
	_prime_post_caches( $posts, false, false );

	foreach ( $slugs as $index => $forms ) {
		foreach ( $forms as $slug ) {
			$original = isset( $owners[ $slug ] ) ? swiftling_original_slug( $owners[ $slug ] ) : '';
			if ( '' !== $original ) {
				$segments[ $index ] = $original;
				break;
			}
		}
	}
	return $start . implode( '/', $segments ) . $rest;
}

/**
 * Sends a request in another language for a post or a term archive by another of its addresses,
 * its original slug or an earlier translated one, to its address now in that language. The rest of
 * the address, such as the second page of a list and the query, stays.
 *
 * Permanent (301), as WordPress sends an old slug to the new one.
 */
function swiftling_redirect_to_translated_slug() {
	$state  = &swiftling_state();
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( 'translate' !== $state['mode'] || ! in_array( $method, array( 'GET', 'HEAD' ), true ) || is_preview() || is_customize_preview() || swiftling_is_probe() || ! swiftling_directories_supported() ) {
		return;
	}
	$object = get_queried_object();
	// A separate copy is sent to its address by swiftling_redirect_copy().
	if ( $object instanceof WP_Post && swiftling_copy_original_id( $object ) ) {
		return;
	}
	$build = swiftling_current_object_link();
	if ( null === $build ) {
		return;
	}

	$request = &swiftling_slug_request();
	$asked   = '' !== $request['asked'] ? $request['asked'] : ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
	$read    = '' !== $request['read'] ? $request['read'] : $asked;
	// Every address in one spelling: a proxy that writes %D0%BE for %d0%be would otherwise get its
	// own address back forever.
	$asked = swiftling_normalize_address( $asked );
	$read  = swiftling_normalize_address( $read );
	$path  = static function ( $address ) {
		return untrailingslashit( (string) wp_parse_url( swiftling_normalize_address( $address ), PHP_URL_PATH ) );
	};

	$original = $path( swiftling_in_link_language( '', $build ) );
	$now      = $path( call_user_func( $build ) );
	$read     = (string) strtok( $read, '?#' );
	if ( $original === $now || 0 !== strpos( $read . '/', $original . '/' ) ) {
		return;
	}
	// The rest of the address as asked, not as read: it can name a post of the object's own by its
	// translated slug, as a lesson inside its course, which is its address in the language already.
	$rest   = trim( substr( $read, strlen( $original ) ), '/' );
	$count  = '' === $rest ? 0 : count( explode( '/', $rest ) );
	$parts  = explode( '/', trim( (string) strtok( $asked, '?#' ), '/' ) );
	$tail   = $count && count( $parts ) >= $count ? '/' . implode( '/', array_slice( $parts, -$count ) ) : substr( $read, strlen( $original ) );
	$target = $now . $tail;
	if ( untrailingslashit( $target ) === $path( $asked ) ) {
		return;
	}
	if ( '/' !== substr( $target, -1 ) && '/' === substr( (string) strtok( $asked, '?#' ), -1 ) ) {
		$target .= '/';
	}
	$query  = (string) wp_parse_url( $asked, PHP_URL_QUERY );
	$origin = (string) preg_replace( '#^([a-z][a-z0-9+.\-]*://[^/?\#]*).*$#is', '$1', home_url() );
	wp_safe_redirect( $origin . $target . ( '' !== $query ? '?' . $query : '' ), 301, 'SwiftLing' );
	exit;
}
add_action( 'template_redirect', 'swiftling_redirect_to_translated_slug', 1 );

/**
 * Returns what the editor shows of a post's address in a language: its translated slug and how it
 * was made, the original slug, the part of the address before the slug, and the address itself.
 *
 * @param WP_Post $post Post.
 * @param string  $slug Address slug of a target language.
 * @return array{slug: string, state: string, original: string, prefix: string, url: string}
 *         State is machine, reviewed or missing, for a post that uses its original slug.
 */
function swiftling_post_address( $post, $slug ) {
	$language = swiftling_target_language( $slug );
	$entry    = $language ? swiftling_slug_entry( 'post:' . $post->ID, $language['locale'] ) : null;
	$url      = 'publish' === $post->post_status ? swiftling_post_language_url( $post, $slug ) : '';
	$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
	$current  = $entry ? $entry['slug'] : (string) $post->post_name;
	// Before the slug: the language's folder and the pages above.
	$at = '' !== $current ? strrpos( untrailingslashit( $path ), '/' . $current ) : false;
	return array(
		'slug'     => $entry ? $entry['slug'] : '',
		'state'    => $entry ? $entry['state'] : 'missing',
		'original' => (string) $post->post_name,
		'prefix'   => false === $at ? '/' : rawurldecode( substr( $path, 0, $at + 1 ) ),
		'url'      => $url,
	);
}
