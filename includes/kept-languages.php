<?php
/**
 * Languages no longer on the site. Removing a language keeps what was made for it, stored by its
 * locale, so adding it again brings its translations back. The Languages screen lists these
 * languages, see admin/views/languages.php, with Add again and Delete; deleting takes two
 * warnings, as nothing brings the translations back afterwards.
 *
 * What a language keeps, and what deleting it does:
 * - Its texts (strings), the states of its posts (translated), its translated addresses (slugs)
 *   and its rows of the search index (index): deleted, in batches, so a large table is not held
 *   for long.
 * - Its separate copies: moved to the Trash, from where they can still be restored, or deleted
 *   where the site keeps no Trash.
 * - What menu items and the switcher remember for it: which items show in it, and its flag.
 *
 * A language is found by this data, not by a list of past languages, so it also finds languages
 * removed before this screen existed.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rows deleted in one statement.
 */
const SWIFTLING_FORGET_BATCH = 1000;

/**
 * Tables that hold rows by the locale of a language, in their column lang.
 */
const SWIFTLING_LANGUAGE_TABLES = array( 'strings', 'translated', 'slugs', 'index' );

/**
 * Returns the languages that are no longer on the site but whose data is kept: never the
 * original or a language on the site.
 *
 * @return array<string, array{name: string, texts: int, addresses: int, copies: int, can_add: bool}>
 *         Keyed by locale, the languages with the most texts first. can_add is whether WordPress
 *         knows the language, so it can be added again from its row; a custom language is added
 *         again as it was added first.
 */
function swiftling_kept_languages() {
	global $wpdb;
	$languages = swiftling_languages();
	$on_site   = array_merge( array( $languages['source']['locale'] ), wp_list_pluck( $languages['targets'], 'locale' ) );

	$counts = array();
	foreach ( SWIFTLING_LANGUAGE_TABLES as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own tables, on the Languages screen.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT lang, COUNT(*) AS n FROM %i GROUP BY lang', swiftling_table( $table ) ) ) as $row ) {
			$counts[ (string) $row->lang ][ $table ] = (int) $row->n;
		}
	}
	foreach ( swiftling_kept_copies() as $locale => $ids ) {
		$counts[ $locale ]['copies'] = count( $ids );
	}

	$wordpress = swiftling_available_languages( false );
	$kept      = array();
	foreach ( array_diff_key( $counts, array_flip( $on_site ) ) as $locale => $found ) {
		$locale          = (string) $locale;
		$kept[ $locale ] = array(
			'name'      => swiftling_kept_language_name( $locale, $wordpress ),
			'texts'     => isset( $found['strings'] ) ? $found['strings'] : 0,
			'addresses' => isset( $found['slugs'] ) ? $found['slugs'] : 0,
			'copies'    => isset( $found['copies'] ) ? $found['copies'] : 0,
			'can_add'   => isset( $wordpress[ $locale ] ),
		);
	}
	uasort(
		$kept,
		static function ( $a, $b ) {
			return $b['texts'] !== $a['texts'] ? $b['texts'] - $a['texts'] : strcasecmp( $a['name'], $b['name'] );
		}
	);
	return $kept;
}

/**
 * Returns the separate copies of the languages no longer on the site and of every other one, by
 * locale. Copies in the Trash are left out: they are on their way out.
 *
 * @return array<string, int[]> Post IDs, by locale.
 */
function swiftling_kept_copies() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counted on the Languages screen only.
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS lang, p.ID AS id FROM %i m JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status <> 'trash'", $wpdb->postmeta, $wpdb->posts, SWIFTLING_COPY_LANG ) );
	$copies = array();
	foreach ( (array) $rows as $row ) {
		$copies[ (string) $row->lang ][] = (int) $row->id;
	}
	return $copies;
}

/**
 * Returns the name of a language no longer on the site: its own name as WordPress lists it, or
 * from PHP's language data, or its locale.
 *
 * @param string     $locale    Locale.
 * @param array|null $wordpress Languages WordPress knows, from swiftling_available_languages( false ).
 */
function swiftling_kept_language_name( $locale, $wordpress = null ) {
	$wordpress = null === $wordpress ? swiftling_available_languages( false ) : $wordpress;
	if ( isset( $wordpress[ $locale ] ) ) {
		return (string) $wordpress[ $locale ]['name'];
	}
	$name = swiftling_translated_language_name( $locale, $locale, true );
	return '' !== $name ? $name : $locale;
}

/**
 * Returns what a language no longer on the site keeps, in a few words.
 *
 * @param array $kept A language from swiftling_kept_languages().
 * @return string For example "251 texts · 4 separate copies · 3 translated addresses".
 */
function swiftling_kept_language_summary( $kept ) {
	$parts = array();
	if ( $kept['texts'] ) {
		/* translators: %s: number of texts. */
		$parts[] = sprintf( _n( '%s text', '%s texts', $kept['texts'], 'swiftling' ), number_format_i18n( $kept['texts'] ) );
	}
	if ( $kept['copies'] ) {
		/* translators: %s: number of separate copies. */
		$parts[] = sprintf( _n( '%s separate copy', '%s separate copies', $kept['copies'], 'swiftling' ), number_format_i18n( $kept['copies'] ) );
	}
	if ( $kept['addresses'] ) {
		/* translators: %s: number of translated addresses. */
		$parts[] = sprintf( _n( '%s translated address', '%s translated addresses', $kept['addresses'], 'swiftling' ), number_format_i18n( $kept['addresses'] ) );
	}
	return $parts ? implode( ' · ', $parts ) : __( 'Only records of which pages were translated', 'swiftling' );
}

/**
 * Returns what deleting a language no longer on the site does to each thing it keeps, as the
 * warning lists it.
 *
 * @param array $kept A language from swiftling_kept_languages().
 * @return array<int, array{0: string, 1: string}> What, and what happens to it.
 */
function swiftling_kept_language_parts( $kept ) {
	$deleted = __( 'Deleted', 'swiftling' );
	$parts   = array();
	if ( $kept['texts'] ) {
		/* translators: %s: number of texts. */
		$parts[] = array( sprintf( _n( '%s translated text', '%s translated texts', $kept['texts'], 'swiftling' ), number_format_i18n( $kept['texts'] ) ), $deleted );
	}
	if ( $kept['copies'] ) {
		/* translators: %s: number of separate copies. */
		$parts[] = array( sprintf( _n( '%s separate copy of a page', '%s separate copies of pages', $kept['copies'], 'swiftling' ), number_format_i18n( $kept['copies'] ) ), EMPTY_TRASH_DAYS ? __( 'Moved to the Trash', 'swiftling' ) : $deleted );
	}
	if ( $kept['addresses'] ) {
		/* translators: %s: number of translated addresses. */
		$parts[] = array( sprintf( _n( '%s translated address', '%s translated addresses', $kept['addresses'], 'swiftling' ), number_format_i18n( $kept['addresses'] ) ), $deleted );
	}
	$parts[] = array( __( 'Its rows of the search index, and which pages were translated', 'swiftling' ), $deleted );
	return $parts;
}

/**
 * Deletes what a language no longer on the site kept: its texts, states, addresses and rows of the
 * search index, and what menu items and the switcher remember for it; its separate copies go to
 * the Trash. Notes it in the log.
 *
 * @param string $locale Locale of the language.
 * @return array{texts: int, copies: int}|WP_Error What was deleted, or why nothing was.
 */
function swiftling_forget_language( $locale ) {
	global $wpdb;
	$languages = swiftling_languages();
	$locale    = (string) $locale;
	if ( '' === $locale || $languages['source']['locale'] === $locale || swiftling_language_by_locale( $locale ) ) {
		return new WP_Error( 'swiftling_language_on_site', __( 'This language is on the site: remove it first.', 'swiftling' ) );
	}
	$name = swiftling_kept_language_name( $locale );

	$done = array(
		'texts'  => 0,
		'copies' => 0,
	);
	foreach ( SWIFTLING_LANGUAGE_TABLES as $table ) {
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from SwiftLing's own tables, as the user confirmed twice.
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE lang = %s LIMIT %d', swiftling_table( $table ), $locale, SWIFTLING_FORGET_BATCH ) );
			if ( 'strings' === $table ) {
				$done['texts'] += $deleted;
			}
		} while ( SWIFTLING_FORGET_BATCH === $deleted );
	}

	$copies = swiftling_kept_copies();
	foreach ( isset( $copies[ $locale ] ) ? $copies[ $locale ] : array() as $copy ) {
		if ( wp_trash_post( $copy ) ) {
			++$done['copies'];
		}
	}

	// Which menu items show in the language: a language added later starts from the original again.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Menu items with a choice per language, a few at most.
	$items = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, SWIFTLING_MENU_LANGUAGES ) );
	foreach ( (array) $items as $item ) {
		$shows = get_post_meta( (int) $item, SWIFTLING_MENU_LANGUAGES, true );
		if ( is_array( $shows ) && array_key_exists( $locale, $shows ) ) {
			unset( $shows[ $locale ] );
			update_post_meta( (int) $item, SWIFTLING_MENU_LANGUAGES, $shows );
		}
	}

	// Its flag and the texts of the suggestion to switch, in the switcher's settings.
	$switcher = get_option( \SwiftLing\Switcher\Settings::OPTION );
	if ( is_array( $switcher ) && ( isset( $switcher['flags'][ $locale ] ) || isset( $switcher['suggest']['texts'][ $locale ] ) ) ) {
		unset( $switcher['flags'][ $locale ], $switcher['suggest']['texts'][ $locale ] );
		update_option( \SwiftLing\Switcher\Settings::OPTION, $switcher, true );
	}

	/* translators: %s: number of texts. */
	$texts = sprintf( _n( '%s text', '%s texts', $done['texts'], 'swiftling' ), number_format_i18n( $done['texts'] ) );
	if ( $done['copies'] ) {
		$message = sprintf(
			/* translators: 1: language name, 2: number of texts, e.g. 3 texts, 3: number of separate copies, e.g. 1 separate copy. */
			EMPTY_TRASH_DAYS ? __( 'The translations of %1$s were deleted: %2$s, and %3$s moved to the Trash.', 'swiftling' ) : __( 'The translations of %1$s were deleted: %2$s, and %3$s.', 'swiftling' ),
			$name,
			$texts,
			/* translators: %s: number of separate copies. */
			sprintf( _n( '%s separate copy', '%s separate copies', $done['copies'], 'swiftling' ), number_format_i18n( $done['copies'] ) )
		);
	} else {
		/* translators: 1: language name, 2: number of texts, e.g. 3 texts. */
		$message = sprintf( __( 'The translations of %1$s were deleted: %2$s.', 'swiftling' ), $name, $texts );
	}
	swiftling_log( 'event', 'languages', $message );

	/**
	 * Fires after what a language no longer on the site kept was deleted, so SwiftLing Pro can
	 * delete what it keeps for the language too.
	 *
	 * @param string $locale Locale of the language.
	 * @param array  $done   What was deleted: texts and copies.
	 */
	do_action( 'swiftling_language_forgotten', $locale, $done );

	return $done;
}

/**
 * Deletes what a language no longer on the site kept, as the Languages screen asks: only once the
 * first warning is ticked and the last one answered, each asked again in place when it is not.
 */
function swiftling_handle_forget_language() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the languages of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_forget_language' );

	$locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '';
	$screen = admin_url( 'admin.php?page=swiftling-languages' );
	$args   = array( 'swiftling_forget' => $locale );
	if ( empty( $_POST['understood'] ) ) {
		$args['swiftling_notice'] = 'forget_unticked';
	} elseif ( empty( $_POST['last'] ) ) {
		$args['swiftling_step'] = 'last';
	} else {
		$done = swiftling_forget_language( $locale );
		$args = is_wp_error( $done ) ? array( 'swiftling_notice' => 'forget_refused' ) : array(
			'swiftling_notice'    => 'forgotten',
			'swiftling_forgotten' => $locale,
			'swiftling_copies'    => $done['copies'],
		);
	}
	wp_safe_redirect( add_query_arg( $args, $screen ) . '#kept-languages' );
	exit;
}
add_action( 'admin_post_swiftling_forget_language', 'swiftling_handle_forget_language' );
