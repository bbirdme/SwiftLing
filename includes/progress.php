<?php
/**
 * How far the translation of the site and of each page has come, from the stored maps and
 * translations. A page that was never opened in the translation editor has no map yet: its texts
 * are not known, and none of them is translated, so it is not translated.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the state of the translation of several posts in every target language.
 *
 * The texts the translation editor shows for the post count: its own texts and shared texts
 * inside its content, such as a widget's default text, but not the header or the footer. The
 * worst state wins: outdated, then missing, then partly translated, then machine translated, then
 * reviewed. WordPress's own translation of a text from code counts as reviewed.
 *
 * @param int[] $post_ids Post IDs.
 * @return array<int, array<string, string>> Post ID => language slug => reviewed, machine, outdated, partial, missing or duplicate.
 */
function swiftling_post_states( $post_ids ) {
	$keys = array();
	foreach ( $post_ids as $post_id ) {
		$keys[ 'post:' . (int) $post_id ] = (int) $post_id;
	}
	$states = array();
	foreach ( swiftling_object_states( array_keys( $keys ) ) as $key => $languages ) {
		$states[ $keys[ $key ] ] = $languages;
	}
	return $states;
}

/**
 * Returns the state of the translation of several subjects of the editor in every target language:
 * posts, templates or the terms of a taxonomy, see swiftling_subject(). A subject counts the texts
 * its editor shows. A post whose page in a language is a published separate copy has the state of
 * that copy there: reviewed, or outdated once the original changed after the copy was last edited.
 * A post that is a duplicate in a language is in the state duplicate there.
 *
 * @param string[] $keys Subject keys.
 * @return array<string, array<string, string>> Subject key => language slug => reviewed, machine, outdated, partial, missing or duplicate.
 */
function swiftling_object_states( $keys ) {
	list( $maps, $places, $translations ) = swiftling_object_places( $keys );

	$languages = swiftling_languages();

	$states = array();
	foreach ( $keys as $key ) {
		$copies     = 0 === strpos( $key, 'post:' ) ? swiftling_copies_of( (int) substr( $key, 5 ) ) : array();
		$duplicates = 0 === strpos( $key, 'post:' ) ? swiftling_duplicate_locales( (int) substr( $key, 5 ) ) : array();
		foreach ( $languages['targets'] as $slug => $language ) {
			if ( isset( $copies[ $language['locale'] ] ) && 'publish' === $copies[ $language['locale'] ]->post_status ) {
				$states[ $key ][ $slug ] = swiftling_copy_state( $copies[ $language['locale'] ] );
				continue;
			}
			if ( in_array( $language['locale'], $duplicates, true ) ) {
				$states[ $key ][ $slug ] = 'duplicate';
				continue;
			}
			// A page never opened in the translation editor has no texts known yet: nothing is translated.
			$states[ $key ][ $slug ] = isset( $maps[ $key ] ) ? swiftling_units_state( swiftling_places_in( $key, $places[ $key ], $language['locale'] ), $translations[ $slug ] ) : 'missing';
		}
	}
	return $states;
}

/**
 * Returns the maps of some subjects, the places of their texts and the translations of those places
 * into every target language: what their states and counts are read from.
 *
 * @param string[] $keys Subject keys.
 * @return array{0: array, 1: array, 2: array} Maps by subject key; places by subject key, see
 *         swiftling_map_places(), a place with the place it falls back to as a third entry where it
 *         has one; translations by language slug, keyed by place.
 */
function swiftling_object_places( $keys ) {
	$maps   = swiftling_get_maps( $keys );
	$places = array();
	$lookup = array();
	foreach ( $maps as $key => $map ) {
		$places[ $key ] = swiftling_map_places( $key, $map );
		// A menu's label of a page's title falls back to the page's translated title, see includes/menus.php.
		if ( 0 === strpos( $key, 'menu:' ) ) {
			foreach ( swiftling_menu_fallbacks( $key ) as $unit_key => $fallback ) {
				if ( isset( $places[ $key ][ $unit_key ] ) ) {
					$places[ $key ][ $unit_key ][2] = $fallback;
				}
			}
		}
		foreach ( $places[ $key ] as $place ) {
			array_push( $lookup, ...array_filter( $place ) );
		}
	}

	$translations = array();
	foreach ( swiftling_languages()['targets'] as $slug => $language ) {
		$translations[ $slug ] = $lookup ? swiftling_get_translations( $language['locale'], $lookup ) : array();
	}
	return array( $maps, $places, $translations );
}

/**
 * Returns the places of a subject's texts that count in a language: all of them, except in a menu
 * the texts of items left out there, see swiftling_menu_places_in().
 *
 * @param string $key    Subject key.
 * @param array  $places Places of its texts, see swiftling_object_places().
 * @param string $locale Locale.
 * @return array
 */
function swiftling_places_in( $key, $places, $locale ) {
	return 0 === strpos( $key, 'menu:' ) ? swiftling_menu_places_in( $key, $places, $locale ) : $places;
}

/**
 * Returns how many texts of a subject are translated into each language, counted as its state is:
 * every text except link addresses and, in a menu, the texts of items left out in the language; a
 * text whose original changed counts as not translated yet.
 *
 * @param string $key Subject key, see swiftling_subject().
 * @return array<string, array{translated: int, total: int}> Language slug => counts; empty for a
 *         subject that was never checked.
 */
function swiftling_subject_counts( $key ) {
	list( $maps, $places, $translations ) = swiftling_object_places( array( $key ) );
	if ( ! isset( $maps[ $key ] ) ) {
		return array();
	}
	$counts  = array();
	$targets = swiftling_languages()['targets'];
	foreach ( $translations as $slug => $translated ) {
		$counts[ $slug ] = array(
			'translated' => 0,
			'total'      => 0,
		);
		foreach ( swiftling_places_in( $key, $places[ $key ], $targets[ $slug ]['locale'] ) as $unit_key => $place ) {
			if ( swiftling_is_link_key( $unit_key ) ) {
				continue;
			}
			++$counts[ $slug ]['total'];
			if ( isset( $translated[ $place[0] ] ) || ( isset( $place[2] ) && isset( $translated[ $place[2] ] ) ) ) {
				++$counts[ $slug ]['translated'];
			}
		}
	}
	return $counts;
}

/**
 * Returns the state of one post in every target language.
 *
 * @param int $post_id Post ID.
 * @return array<string, string> Language slug => state.
 */
function swiftling_language_states( $post_id ) {
	return swiftling_subject_states( 'post:' . (int) $post_id );
}

/**
 * Returns the state of one subject of the editor in every target language.
 *
 * @param string $key Subject key, see swiftling_subject().
 * @return array<string, string> Language slug => state.
 */
function swiftling_subject_states( $key ) {
	$states = swiftling_object_states( array( $key ) );
	return $states[ $key ];
}

/**
 * Returns the state of a set of texts in one language.
 *
 * A text with a place to fall back to, such as a menu's label of a page's title, counts as
 * translated when that place is, unless its own translation is outdated.
 *
 * @param array $places       Map key => place key now, of the previous version, and optionally of
 *                            the place it falls back to, see swiftling_object_places().
 * @param array $translations Translations into the language, keyed by place.
 * @return string reviewed, machine, outdated, partial (some texts translated, others not yet) or
 *                missing (none translated).
 */
function swiftling_units_state( $places, $translations ) {
	$machine    = false;
	$translated = false;
	$missing    = false;
	foreach ( $places as $key => $place ) {
		if ( swiftling_is_link_key( $key ) ) {
			continue;
		}
		if ( isset( $translations[ $place[0] ] ) ) {
			$translated = true;
			$machine    = $machine || SWIFTLING_MACHINE === $translations[ $place[0] ]['status'];
			continue;
		}
		if ( '' !== $place[1] && isset( $translations[ $place[1] ] ) ) {
			return 'outdated';
		}
		if ( isset( $place[2] ) && isset( $translations[ $place[2] ] ) ) {
			$translated = true;
			$machine    = $machine || SWIFTLING_MACHINE === $translations[ $place[2] ]['status'];
			continue;
		}
		$missing = true;
	}
	if ( $missing ) {
		return $translated ? 'partial' : 'missing';
	}
	return $machine ? 'machine' : 'reviewed';
}

/**
 * Whether every text of a set has a translation in one language, had one before its original
 * changed, or falls back to a translated place. Only then does a page count as translated, see
 * includes/visibility.php. Asked of the texts themselves, not of swiftling_units_state(), whose
 * state names an outdated text first also where another text was never translated.
 *
 * @param array $places       Map key => place key now, of the previous version, and optionally of
 *                            the place it falls back to, see swiftling_object_places().
 * @param array $translations Translations into the language, keyed by place.
 * @return bool
 */
function swiftling_units_translated( $places, $translations ) {
	foreach ( $places as $key => $place ) {
		if ( swiftling_is_link_key( $key ) ) {
			continue;
		}
		foreach ( array_filter( $place ) as $at ) {
			if ( isset( $translations[ $at ] ) ) {
				continue 2;
			}
		}
		return false;
	}
	return true;
}

/**
 * Returns the pages and posts visitors can open on their own: published, of the post types the
 * Settings screen lists, separate copies left out, as they are the translation of their original.
 * The Overview counts them, and the Translate everything screen works through them.
 *
 * @return int[] Post IDs.
 */
function swiftling_site_pages() {
	global $wpdb;

	$types = array_keys( swiftling_content_types() );
	if ( ! $types ) {
		return array();
	}

	$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$sql = "SELECT p.ID FROM %i p LEFT JOIN %i c ON c.post_id = p.ID AND c.meta_key = %s WHERE p.post_status = 'publish' AND p.post_type IN ({$in}) AND c.post_id IS NULL";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read once per screen; $in has one placeholder per type.
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $wpdb->posts, $wpdb->postmeta, SWIFTLING_COPY_OF, ...$types ) ) );
}

/**
 * Returns how far each language has come across the pages and posts visitors can open: published,
 * of the post types the Settings screen lists, separate copies not counted (they are the
 * translation of their original). Each one counts once per language, in the state the Translations
 * screen shows for it; a page never opened in the translation editor is not translated.
 *
 * Only pages that have been worked on get a state of their own: those with texts found in the
 * editor, a separate copy or a duplicate. All others are not translated without reading anything,
 * so the screen stays quick on a large site.
 *
 * @return array{total: int, languages: array<string, array{reviewed: int, machine: int, duplicate: int, partial: int, outdated: int, missing: int}>, outdated: int[]}
 *         Number of pages, counts per language slug, and the pages whose original changed since
 *         they were translated, in any language.
 */
function swiftling_site_page_progress() {
	global $wpdb;
	$languages = swiftling_languages();
	$progress  = array(
		'total'     => 0,
		'languages' => array(),
		'outdated'  => array(),
	);
	foreach ( array_keys( $languages['targets'] ) as $slug ) {
		$progress['languages'][ $slug ] = array(
			'reviewed'  => 0,
			'machine'   => 0,
			'duplicate' => 0,
			'partial'   => 0,
			'outdated'  => 0,
			'missing'   => 0,
		);
	}

	$ids               = swiftling_site_pages();
	$progress['total'] = count( $ids );
	if ( ! $ids ) {
		return $progress;
	}

	// The pages worked on: texts found in the editor, a separate copy or a duplicate.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read once.
	$checked = $wpdb->get_col( $wpdb->prepare( 'SELECT object_key FROM %i WHERE object_key LIKE %s', swiftling_table( 'objects' ), $wpdb->esc_like( 'post:' ) . '%' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One small query; meta_key is indexed.
	$duplicates = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, SWIFTLING_DUPLICATE ) );
	$worked     = array_flip(
		array_merge(
			array_map(
				static function ( $key ) {
					return (int) substr( $key, 5 );
				},
				$checked
			),
			array_map( 'intval', $duplicates ),
			array_values( swiftling_copy_map() )
		)
	);
	$worked     = array_values(
		array_filter(
			$ids,
			static function ( $id ) use ( $worked ) {
				return isset( $worked[ $id ] );
			}
		)
	);

	$outdated = array();
	foreach ( array_chunk( $worked, 100 ) as $chunk ) {
		foreach ( swiftling_post_states( $chunk ) as $post_id => $states ) {
			foreach ( $states as $slug => $state ) {
				if ( 'outdated' === $state ) {
					$outdated[ $post_id ] = true;
				}
				// WordPress's own translation of a text counts as reviewed: people made it.
				$state = 'official' === $state ? 'reviewed' : $state;
				++$progress['languages'][ $slug ][ isset( $progress['languages'][ $slug ][ $state ] ) ? $state : 'missing' ];
			}
		}
	}
	foreach ( $progress['languages'] as $slug => $counts ) {
		$progress['languages'][ $slug ]['missing'] += $progress['total'] - count( $worked );
	}
	$progress['outdated'] = array_keys( $outdated );

	return $progress;
}
