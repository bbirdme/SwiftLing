<?php
/**
 * Categories, tags and other lists of terms visitors read, and the fields themes and plugins keep
 * with a term.
 *
 * A list is translated when a person manages its terms in the admin and it belongs to content
 * visitors open, see swiftling_term_taxonomies(): WooCommerce's attributes are, also without
 * archives of their own; Divi Library's categories are not.
 *
 * The texts of a term are its name, its description and its fields (term meta), such as Rank Math's
 * SEO title of a category. Nothing is copied or kept in sync: a field that is not translated shows
 * the original in every language. Which fields are texts is decided as for the fields of a page:
 *
 * - a field the page of a term shows as text is translated with the name. The editor of a list
 *   checks the page of its most used term once, and again when a field appears that was not there
 *   at that check; what the check learns about each field is kept in the rules, as for any page;
 * - a field the page does not show, or a list without pages of its own, is offered with Translate
 *   and Keep original. It stays the original until the user chooses;
 * - a plugin can declare a field as text or as no text, as Advanced Custom Fields does;
 * - the user can keep the original of any field, also of one the page shows.
 *
 * Where a field comes from is what the page check sees: the theme or plugin whose code read it,
 * see swiftling_note_term_meta_caller(). A field the page does not show is named after the plugin
 * that declares it, as Advanced Custom Fields declares its own; otherwise nothing is said.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Most terms the editor of a list reads at once; the most used come first.
 */
const SWIFTLING_TERM_LIST_LIMIT = 500;

/**
 * Terms the editor of a list shows on one page.
 */
const SWIFTLING_TERMS_PER_PAGE = 20;

/**
 * Returns the lists of terms that are translated: those a person manages in the admin (show_ui)
 * that belong to content visitors open, see swiftling_content_types(). Post formats are words of
 * WordPress itself. Archives do not matter: WooCommerce's attributes have none unless they are
 * switched on, and their values still show on every product.
 *
 * @return array<string, WP_Taxonomy> Taxonomy name => taxonomy, in the order WordPress registered them.
 */
function swiftling_term_taxonomies() {
	$types = array_keys( swiftling_content_types() );
	$lists = array();
	foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $name => $taxonomy ) {
		if ( 'post_format' !== $name && array_intersect( (array) $taxonomy->object_type, $types ) ) {
			$lists[ $name ] = $taxonomy;
		}
	}
	return $lists;
}

/**
 * Returns the taxonomy of a term, read without SwiftLing.
 *
 * @param int $term_id Term ID.
 * @return string Empty when the term does not exist.
 */
function swiftling_term_taxonomy_of( $term_id ) {
	$state          = &swiftling_state();
	$guard          = $state['guard'];
	$state['guard'] = true;
	try {
		$term = get_term( (int) $term_id );
	} finally {
		$state['guard'] = $guard;
	}
	return $term instanceof WP_Term ? $term->taxonomy : '';
}

/**
 * Returns the page the editor of a list checks for the fields of its terms: the page of its most
 * used term. A list without pages of its own, as WooCommerce's attributes without archives, has none.
 *
 * @param WP_Taxonomy $taxonomy Taxonomy.
 * @return string Address, or an empty string.
 */
function swiftling_term_check_url( $taxonomy ) {
	if ( ! is_taxonomy_viewable( $taxonomy ) ) {
		return '';
	}
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy->name,
			'hide_empty' => false,
			'number'     => 1,
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	$link = get_term_link( $terms[0] );
	return is_wp_error( $link ) ? '' : (string) $link;
}

/**
 * Returns what is saved about the fields of terms: the user's choices, the fields the page of each
 * list was checked for, and the theme or plugin the check saw reading each field.
 *
 * @return array{choices: array<string, array<string, string>>, checked: array<string, array<string, true>>, sources: array<string, array<string, string>>}
 */
function swiftling_term_field_settings() {
	$saved = get_option( 'swiftling_term_fields', array() );
	$saved = is_array( $saved ) ? $saved : array();
	$found = array();
	foreach ( array( 'choices', 'checked', 'sources' ) as $part ) {
		$found[ $part ] = isset( $saved[ $part ] ) && is_array( $saved[ $part ] ) ? $saved[ $part ] : array();
	}
	return $found;
}

/**
 * Returns the theme or plugin whose code read each field of terms in this request, by meta key.
 *
 * @return array<string, string> Meta key => package ID, see SwiftLing\Packages\Packages::caller().
 */
function &swiftling_term_meta_callers() {
	static $callers = array();
	return $callers;
}

/**
 * Notes the theme or plugin that reads a field of terms in a page check: the first file on the call
 * stack that is not WordPress's or SwiftLing's, once per field. A field WordPress or SwiftLing reads
 * by itself names no one.
 *
 * @param string $key Meta key.
 */
function swiftling_note_term_meta_caller( $key ) {
	$callers = &swiftling_term_meta_callers();
	if ( ! isset( $callers[ $key ] ) ) {
		$caller = \SwiftLing\Packages\Packages::caller();
		if ( '' !== $caller ) {
			$callers[ $key ] = $caller;
		}
	}
}

/**
 * Keeps which theme or plugin the check of a list's page saw reading each field of its terms. Only
 * active themes and plugins are kept.
 *
 * @param string $taxonomy Taxonomy.
 * @param mixed  $callers  Meta key => package ID, as the check reported it.
 */
function swiftling_note_term_field_sources( $taxonomy, $callers ) {
	$settings = swiftling_term_field_settings();
	$sources  = isset( $settings['sources'][ $taxonomy ] ) ? $settings['sources'][ $taxonomy ] : array();
	foreach ( (array) $callers as $key => $package ) {
		if ( is_string( $package ) && '' !== (string) $key && \SwiftLing\Packages\Packages::get( $package ) ) {
			$sources[ (string) $key ] = $package;
		}
	}
	if ( ! isset( $settings['sources'][ $taxonomy ] ) || $sources !== $settings['sources'][ $taxonomy ] ) {
		$settings['sources'][ $taxonomy ] = $sources;
		update_option( 'swiftling_term_fields', $settings, true );
	}
}

/**
 * Returns where a field of terms comes from, for the editor: the theme or plugin the check of the
 * list's page saw reading it, or the plugin that declares it, such as Advanced Custom Fields.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $key      Meta key.
 * @param array  $text     One of the field's values: o and p.
 * @return string Name of the theme or plugin, or an empty string when that is not known.
 */
function swiftling_term_field_source( $taxonomy, $key, $text ) {
	$sources = swiftling_term_field_settings()['sources'];
	if ( isset( $sources[ $taxonomy ][ $key ] ) ) {
		$package = \SwiftLing\Packages\Packages::get( (string) $sources[ $taxonomy ][ $key ] );
		if ( $package ) {
			return (string) $package['name'];
		}
	}
	return \SwiftLing\Builders\Registry::fieldSource( $text['o'], $text['p'] );
}

/**
 * Returns the user's choice for a field of the terms of a list.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $key      Meta key.
 * @return string translate, keep, or empty when the user did not choose.
 */
function swiftling_term_field_choice( $taxonomy, $key ) {
	$choices = swiftling_term_field_settings()['choices'];
	return isset( $choices[ $taxonomy ][ $key ] ) ? (string) $choices[ $taxonomy ][ $key ] : '';
}

/**
 * Saves the user's choice for a field of the terms of a list: translate it, or keep the original in
 * every language. Anything else is ignored.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $key      Meta key.
 * @param string $choice   translate or keep.
 * @return bool Whether the choice was saved.
 */
function swiftling_choose_term_field( $taxonomy, $key, $choice ) {
	if ( ! in_array( $choice, array( 'translate', 'keep' ), true ) || '' === $taxonomy || '' === $key ) {
		return false;
	}
	$settings                                 = swiftling_term_field_settings();
	$settings['choices'][ $taxonomy ][ $key ] = $choice;
	// Read on every page in a language whose terms show fields, so it loads with the other options.
	update_option( 'swiftling_term_fields', $settings, true );
	// Pages in every language show the field differently now, wherever the term's field shows.
	swiftling_flush_cache();
	swiftling_purge_all_later();
	return true;
}

/**
 * Notes the fields a check of a list's page looked for, so the editor checks again only when a
 * field appears that was not there then.
 *
 * @param string   $taxonomy Taxonomy.
 * @param string[] $keys     Meta keys.
 */
function swiftling_note_term_fields_checked( $taxonomy, $keys ) {
	$settings = swiftling_term_field_settings();
	$checked  = isset( $settings['checked'][ $taxonomy ] ) ? $settings['checked'][ $taxonomy ] : array();
	foreach ( $keys as $key ) {
		$checked[ (string) $key ] = true;
	}
	if ( ! isset( $settings['checked'][ $taxonomy ] ) || $checked !== $settings['checked'][ $taxonomy ] ) {
		$settings['checked'][ $taxonomy ] = $checked;
		update_option( 'swiftling_term_fields', $settings, true );
	}
}

/**
 * Returns the terms of a list the editor lists: the most used first, at most
 * SWIFTLING_TERM_LIST_LIMIT, and how many there are in all.
 *
 * @param string $taxonomy Taxonomy.
 * @return array{terms: WP_Term[], total: int}
 */
function swiftling_listed_terms( $taxonomy ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => SWIFTLING_TERM_LIST_LIMIT,
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);
	return array(
		'terms' => is_wp_error( $terms ) ? array() : $terms,
		'total' => (int) wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		),
	);
}

/**
 * Returns where a term sits in its list, by the names of its parents, as "Sport › Football".
 *
 * @param WP_Term $term Term.
 * @return string[] Names of the parents, the outermost first.
 */
function swiftling_term_parents( $term ) {
	$names = array();
	foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor ) {
		$parent = get_term( $ancestor, $term->taxonomy );
		if ( $parent instanceof WP_Term ) {
			$names[] = $parent->name;
		}
	}
	return $names;
}

/**
 * Returns the fields themes and plugins keep with the terms of a list that may hold text, by meta
 * key, read as a page check reads them, see swiftling_collect(). Settings such as the order or the
 * image of a category are left out, see swiftling_skip_term_meta_key(), and so is a field a check of
 * the list's page found to be no text.
 *
 * - shown: true when the page of a term showed it as text or a plugin declares it as text; null
 *   when that is not known: the page did not show it, or was not checked.
 * - checked: whether the page of the list was checked since the field appeared.
 * - source: the theme or plugin it comes from, see swiftling_term_field_source(); empty when not known.
 * - choice: the user's choice, see swiftling_choose_term_field().
 * - translated: whether the editor lists its texts: shown and not kept, or chosen.
 *
 * @param array $subject Subject "terms:<taxonomy>", see swiftling_subject().
 * @param array $terms   Optional terms, as swiftling_listed_terms() returns them; read when not given.
 * @return array<string, array{key: string, label: string, source: string, example: string, shown: bool|null, checked: bool, choice: string, translated: bool, texts: array}>
 */
function swiftling_term_fields( $subject, $terms = null ) {
	$taxonomy = substr( $subject['key'], 6 );
	$terms    = null === $terms ? swiftling_listed_terms( $taxonomy )['terms'] : $terms;
	if ( ! $terms ) {
		return array();
	}

	$leaves = swiftling_collect(
		static function () use ( $terms ) {
			foreach ( $terms as $term ) {
				get_term_meta( $term->term_id );
			}
		}
	);

	$by_key = array();
	foreach ( $leaves as $leaf ) {
		$parts = explode( '/', $leaf['p'], 3 );
		if ( 'meta' !== $parts[0] || ! isset( $parts[1] ) ) {
			continue;
		}
		$by_key[ strtr(
			$parts[1],
			array(
				'%2F' => '/',
				'%25' => '%',
			)
		) ][] = $leaf;
	}

	$rules    = swiftling_get_rules( array_values( array_unique( wp_list_pluck( $leaves, 'r' ) ) ) );
	$settings = swiftling_term_field_settings();
	$checked  = isset( $settings['checked'][ $taxonomy ] ) ? $settings['checked'][ $taxonomy ] : array();
	$fields   = array();
	foreach ( $by_key as $key => $texts ) {
		$key      = (string) $key;
		$verdicts = array();
		foreach ( $texts as $text ) {
			$declared   = swiftling_field_holds_text( $text['o'], $text['p'] );
			$verdicts[] = true === $declared ? SWIFTLING_RULE_TEXT : ( isset( $rules[ $text['r'] ] ) ? $rules[ $text['r'] ] : 0 );
		}
		$shown = null;
		if ( in_array( SWIFTLING_RULE_TEXT, $verdicts, true ) ) {
			$shown = true;
		} elseif ( array( SWIFTLING_RULE_NOT_TEXT ) === array_values( array_unique( $verdicts ) ) ) {
			$shown = false;
		}
		$choice = isset( $settings['choices'][ $taxonomy ][ $key ] ) ? (string) $settings['choices'][ $taxonomy ][ $key ] : '';
		// A field the check found to be no text, such as one shown only inside a class, is a setting.
		if ( false === $shown && 'translate' !== $choice ) {
			continue;
		}
		$label          = \SwiftLing\Builders\Registry::fieldLabel( $texts[0]['o'], $texts[0]['p'] );
		$fields[ $key ] = array(
			'key'        => $key,
			'label'      => '' !== $label ? $label : ucfirst( trim( str_replace( array( '_', '-' ), ' ', $key ) ) ),
			'source'     => swiftling_term_field_source( $taxonomy, $key, $texts[0] ),
			'example'    => wp_html_excerpt( wp_strip_all_tags( $texts[0]['v'] ), 80, '…' ),
			'shown'      => $shown,
			'checked'    => isset( $checked[ $key ] ),
			'choice'     => $choice,
			'translated' => 'translate' === $choice || ( true === $shown && 'keep' !== $choice ),
			'texts'      => $texts,
		);
	}
	ksort( $fields );
	return $fields;
}

/**
 * Whether the editor of a list checks the page of its most used term before it shows the texts:
 * when the list has pages and some field appeared since the last check, or was never checked.
 *
 * @param array $subject Subject "terms:<taxonomy>".
 * @param array $fields  Its fields, see swiftling_term_fields().
 * @return bool
 */
function swiftling_term_list_needs_check( $subject, $fields ) {
	if ( empty( $subject['probe'] ) ) {
		return false;
	}
	foreach ( $fields as $field ) {
		if ( null === $field['shown'] && ! $field['checked'] && '' === $field['choice'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Returns one page of the editor's list of terms, found by a search or not: the texts of the terms
 * on it, as the editor renders them. The maps stay those of the whole list, so its state in each
 * language counts every term.
 *
 * @param array $result What swiftling_save_term_list() returns.
 * @param array $args   page (from 1) and search (words in a text of the term).
 * @return array The result with only the page's texts, and list: page, pages, shown (terms found) and search.
 */
function swiftling_term_list_page( $result, $args ) {
	$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
	$needle = remove_accents( $search );

	// The texts of each term, in the order of the list.
	$terms = array();
	foreach ( $result['sections']['page'] as $unit ) {
		$terms[ $unit['o'] ][] = $unit;
	}
	if ( '' !== $needle ) {
		$terms = array_filter(
			$terms,
			static function ( $units ) use ( $needle ) {
				foreach ( $units as $unit ) {
					if ( false !== stripos( remove_accents( wp_strip_all_tags( $unit['v'] ) ), $needle ) ) {
						return true;
					}
				}
				return false;
			}
		);
	}

	$shown = count( $terms );
	$pages = max( 1, (int) ceil( $shown / SWIFTLING_TERMS_PER_PAGE ) );
	$page  = min( $pages, max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ) );
	$units = array();
	foreach ( array_slice( $terms, ( $page - 1 ) * SWIFTLING_TERMS_PER_PAGE, SWIFTLING_TERMS_PER_PAGE ) as $term_units ) {
		array_push( $units, ...$term_units );
	}

	$result['sections'] = array( 'page' => $units );
	$result['units']    = $units;
	$result['list']     = array(
		'page'   => $page,
		'pages'  => $pages,
		'shown'  => $shown,
		'search' => $search,
	);
	return $result;
}

/**
 * Saves the user's choice for a field of a list's terms, from the editor's form, and goes back to
 * the editor.
 */
function swiftling_handle_term_field_choice() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is verified below, once the list is known.
	$key     = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
	$field   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$choice  = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
	$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
	$subject = 0 === strpos( $key, 'terms:' ) ? swiftling_subject( $key ) : null;
	// phpcs:enable

	if ( ! $subject || ! swiftling_can_translate_subject( $subject ) ) {
		wp_die( esc_html__( 'You may not edit this content, so you cannot translate it either.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	$taxonomy = substr( $subject['key'], 6 );
	check_admin_referer( 'swiftling_term_field_' . $taxonomy );

	// Only a field the list's terms have; nothing else is saved.
	if ( isset( swiftling_term_fields( $subject )[ $field ] ) ) {
		swiftling_choose_term_field( $taxonomy, $field, $choice );
	}

	wp_safe_redirect( swiftling_editor_url( $subject['key'], swiftling_target_language( $lang ) ? $lang : '' ) );
	exit;
}
add_action( 'admin_post_swiftling_term_field', 'swiftling_handle_term_field_choice' );

/**
 * Returns the address of a term in a language, with its translated slugs, see includes/slugs.php.
 *
 * @param WP_Term $term Term.
 * @param string  $slug Address slug of the language.
 * @return string Empty when the term has no page of its own.
 */
function swiftling_term_language_url( $term, $slug ) {
	$base = swiftling_in_link_language(
		$slug,
		static function () use ( $term ) {
			return swiftling_unlocalized_url(
				static function () use ( $term ) {
					$link = get_term_link( $term );
					return is_wp_error( $link ) ? '' : (string) $link;
				}
			);
		}
	);
	return '' === (string) $base ? '' : swiftling_language_url( $base, $slug );
}

/**
 * Returns the translated slugs of terms in a language, read at once for a page of the editor.
 *
 * @param string[] $owners Object keys of terms, "term:5".
 * @param string   $locale Locale.
 * @return array<string, array{slug: string, state: string}> Object key => slug and how it was made.
 */
function swiftling_term_slug_entries( $owners, $locale ) {
	global $wpdb;
	$owners = array_values( array_filter( array_map( 'strval', $owners ) ) );
	if ( ! $owners ) {
		return array();
	}
	$sql = 'SELECT object_key, slug, status FROM %i WHERE lang = %s AND current = 1 AND object_key IN (' . implode( ',', array_fill( 0, count( $owners ), '%s' ) ) . ')';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table, read once for a page of the editor; $sql has one placeholder per term.
	$rows    = $wpdb->get_results( $wpdb->prepare( $sql, swiftling_table( 'slugs' ), $locale, ...$owners ) );
	$entries = array();
	foreach ( (array) $rows as $row ) {
		$entries[ (string) $row->object_key ] = array(
			'slug'  => (string) $row->slug,
			'state' => swiftling_status_state( (int) $row->status ),
		);
	}
	return $entries;
}

/**
 * Returns the address of a term in a language as the editor shows it, as swiftling_post_address()
 * does for a post: its translated slug, how it was made, its original slug, what comes before the
 * slug, and the whole address.
 *
 * @param WP_Term     $term  Term.
 * @param string      $slug  Address slug of the language.
 * @param array|false $entry Its translated slug, see swiftling_term_slug_entries(); read when false.
 * @return array{slug: string, state: string, original: string, prefix: string, url: string}
 */
function swiftling_term_address( $term, $slug, $entry = false ) {
	$language = swiftling_target_language( $slug );
	if ( false === $entry ) {
		$entry = $language ? swiftling_slug_entry( 'term:' . $term->term_id, $language['locale'] ) : null;
	}
	$url     = swiftling_term_language_url( $term, $slug );
	$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
	$current = $entry ? $entry['slug'] : (string) $term->slug;
	// Before the slug: the language's folder, the base of the list and the terms above.
	$at = '' !== $current ? strrpos( untrailingslashit( $path ), '/' . $current ) : false;
	return array(
		'slug'     => $entry ? $entry['slug'] : '',
		'state'    => $entry ? $entry['state'] : 'missing',
		'original' => (string) $term->slug,
		'prefix'   => false === $at ? '/' : rawurldecode( substr( $path, 0, $at + 1 ) ),
		'url'      => $url,
	);
}
