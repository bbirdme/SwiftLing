<?php
/**
 * Search in the visitor's language.
 *
 * WordPress searches the posts table, which holds the originals, while translations are put in
 * when a page is rendered. A visitor searching in German would find a page only by its Croatian
 * words. SwiftLing keeps the translated text of every post in a table of its own, one row per post
 * and language, and widens WordPress's search clause with the posts that match there:
 *
 *     WHERE ( <what WordPress searched for in the originals> OR ID IN ( <what matched here> ) )
 *
 * Nothing is taken away, so the original words keep finding the same pages, and every other rule
 * of the query stays as it is: post types, statuses, the password of a post, and the untranslated
 * posts that are hidden in this language, see includes/visibility.php.
 *
 * The index is written whenever the translations of a post change, on the same action that keeps
 * the table of translated posts current, so there is no second moment to remember. Only the post's
 * own texts go in: a shared text such as the site title or the footer belongs to its own object and
 * would otherwise match every page, an address is not text, and a text whose original changed since
 * it was translated is left out because visitors read the new original.
 *
 * Matching uses the full-text index where MySQL can: every word at least four letters long, no
 * phrase, nothing the index does not split into words, such as Chinese, Japanese or Korean.
 * Everything else is matched with LIKE, which is slower but finds the same words.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the state of the index is kept: when it was last built in full, and whether the table
 * really has its full-text index, which needs InnoDB on MySQL 5.6 or MariaDB 10.0.5 and up.
 */
define( 'SWIFTLING_SEARCH_OPTION', 'swiftling_search' );

/**
 * Posts one search may take from the index at most. A search that matches more than this takes the
 * first of them; the originals WordPress found are never cut off.
 */
define( 'SWIFTLING_SEARCH_MAX_POSTS', 500 );

/**
 * Posts read in one batch while the index is built again.
 */
define( 'SWIFTLING_SEARCH_BATCH', 100 );

/**
 * Whether the site searches translations, as the Settings screen says.
 *
 * @return bool
 */
function swiftling_search_enabled() {
	return (bool) swiftling_settings()['search'];
}

/**
 * Returns the locale of the language the page is being shown in, which its search reads.
 *
 * @return string Locale, or an empty string outside a translated page.
 */
function swiftling_search_locale() {
	if ( 'translate' !== swiftling_mode() ) {
		return '';
	}
	$state = &swiftling_state();
	return (string) $state['locale'];
}

/**
 * Returns a translation as the index holds it: the words a visitor reads, without tags,
 * shortcodes or entities. A text that is only code, such as a shortcode of its own, has no words
 * and is left out.
 *
 * @param string $text Translation.
 * @return string Plain text, or an empty string when there are no words in it.
 */
function swiftling_search_text( $text ) {
	$text = (string) preg_replace( '~<(script|style|noscript|template|svg)\b.*?</\1\s*>|<!--.*?-->~is', ' ', (string) $text );
	$text = wp_strip_all_tags( $text );
	$text = (string) preg_replace( '~\[/?[a-z][\w-]*(?:\s[^\]]*)?\]~i', ' ', $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = trim( (string) preg_replace( '~\s+~u', ' ', $text ) );
	return 1 === preg_match( '~[\p{L}\p{N}]~u', $text ) ? $text : '';
}

/**
 * Writes the index of some posts again, in every language: their translated title apart from their
 * other texts. Runs whenever the translations of a post change, see swiftling_refresh_translated().
 *
 * @param int[] $post_ids Post IDs.
 */
function swiftling_refresh_index( $post_ids ) {
	global $wpdb;

	$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
	if ( ! $post_ids ) {
		return;
	}

	$owners = array();
	foreach ( $post_ids as $post_id ) {
		// A type set to Never is not translated at all, so it is not searched by translation either.
		if ( ! swiftling_is_never_translated( (string) get_post_type( $post_id ) ) ) {
			$owners[ 'post:' . $post_id ] = $post_id;
		}
	}

	$places = array();
	$lookup = array();
	foreach ( $owners ? swiftling_get_maps( array_keys( $owners ) ) : array() as $key => $map ) {
		foreach ( swiftling_map_places( $key, $map ) as $unit_key => $place ) {
			// A shared text belongs to its own object, and an address is not text.
			if ( swiftling_is_shared_key( $unit_key ) || swiftling_is_link_key( $unit_key ) ) {
				continue;
			}
			$places[ $key ][ $unit_key ] = $place;
			$lookup[]                    = $place[0];
		}
	}

	$title_key = swiftling_path_key( 'field/post_title' );
	$rows      = array();
	foreach ( swiftling_languages()['targets'] as $language ) {
		$translations = $lookup ? swiftling_get_translations( $language['locale'], $lookup ) : array();
		foreach ( $places as $key => $own ) {
			$title = '';
			$body  = array();
			foreach ( $own as $unit_key => $place ) {
				// A text whose original changed has nothing stored under its place now: it waits
				// for the translation editor, and visitors read the new original meanwhile.
				if ( ! isset( $translations[ $place[0] ] ) ) {
					continue;
				}
				$words = swiftling_search_text( $translations[ $place[0] ]['text'] );
				if ( '' === $words ) {
					continue;
				}
				if ( $unit_key === $title_key ) {
					$title = $words;
				} else {
					$body[] = $words;
				}
			}
			if ( '' !== $title || $body ) {
				$rows[] = array( $owners[ $key ], $language['locale'], $title, implode( "\n", $body ) );
			}
		}
	}

	$table = swiftling_table( 'index' );
	$sql   = 'DELETE FROM %i WHERE post_id IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table; $sql has one placeholder per post.
	$wpdb->query( $wpdb->prepare( $sql, $table, ...$post_ids ) );

	// REPLACE, not INSERT: two runs writing the same post at once (languages translated in parallel from
	// the command line) both delete its rows first and would then both insert them.
	foreach ( array_chunk( $rows, 50 ) as $chunk ) {
		$values = array();
		$args   = array( $table );
		foreach ( $chunk as $row ) {
			$values[] = '(%d, %s, %s, %s)';
			array_push( $args, $row[0], $row[1], $row[2], $row[3] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table; one placeholder group per row.
		$wpdb->query( $wpdb->prepare( 'REPLACE INTO %i (post_id, lang, title, body) VALUES ' . implode( ',', $values ), ...$args ) );
	}
}
add_action( 'swiftling_translated_changed', 'swiftling_refresh_index' );

/**
 * Forgets a deleted post's translated text.
 *
 * @param int $post_id Post ID.
 */
function swiftling_forget_index( $post_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', swiftling_table( 'index' ), (int) $post_id ) );
}
add_action( 'deleted_post', 'swiftling_forget_index' );

/**
 * Writes the index of one batch of checked posts again.
 *
 * @param string $after Object key the batch starts after; empty for the first batch.
 * @return array{done: int, after: string, finished: bool} Posts written, where the next batch
 *         starts, and whether this was the last one.
 */
function swiftling_index_batch( $after = '' ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read in keyed batches.
	$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT object_key FROM %i WHERE object_key LIKE %s AND object_key > %s ORDER BY object_key LIMIT %d', swiftling_table( 'objects' ), $wpdb->esc_like( 'post:' ) . '%', (string) $after, SWIFTLING_SEARCH_BATCH ) );

	if ( $keys ) {
		swiftling_refresh_index(
			array_map(
				static function ( $key ) {
					return (int) substr( (string) $key, 5 );
				},
				$keys
			)
		);
	}

	return array(
		'done'     => count( $keys ),
		'after'    => $keys ? (string) end( $keys ) : (string) $after,
		'finished' => count( $keys ) < SWIFTLING_SEARCH_BATCH,
	);
}

/**
 * Drops the index of languages the site no longer has.
 */
function swiftling_forget_old_index_languages() {
	global $wpdb;

	$table = swiftling_table( 'index' );
	// Keyed by address slug; before PHP 8.1 only a list can be unpacked below.
	$locales = array_values( wp_list_pluck( swiftling_languages()['targets'], 'locale' ) );
	if ( ! $locales ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		return;
	}

	$sql = 'DELETE FROM %i WHERE lang NOT IN (' . implode( ',', array_fill( 0, count( $locales ), '%s' ) ) . ')';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table; $sql has one placeholder per locale.
	$wpdb->query( $wpdb->prepare( $sql, $table, ...$locales ) );
}

/**
 * Writes the index of every checked post again, batch by batch. The Settings screen does this from
 * the browser; the plugin does it once when the index is added.
 */
function swiftling_refresh_all_index() {
	swiftling_forget_old_index_languages();

	$after = '';
	do {
		$batch = swiftling_index_batch( $after );
		$after = $batch['after'];
	} while ( ! $batch['finished'] );

	swiftling_note_search_state( array( 'built' => time() ) );
}

/**
 * Whether the table has its full-text index, as noted when it was made. Asked of MySQL only the
 * first time, since every search reads this.
 *
 * @return bool
 */
function swiftling_search_fulltext() {
	$saved = get_option( SWIFTLING_SEARCH_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();
	if ( ! isset( $saved['fulltext'] ) ) {
		$saved = swiftling_note_search_state( array( 'fulltext' => swiftling_search_has_fulltext() ) );
	}
	return (bool) $saved['fulltext'];
}

/**
 * Returns what the Settings screen says about the index: how many pages and languages are in it,
 * when it was last built in full, and whether MySQL gave the table its full-text index. Counted
 * on the screen only, never while a visitor searches.
 *
 * @return array{pages: int, languages: int, built: int, fulltext: bool}
 */
function swiftling_search_state() {
	global $wpdb;

	$saved = get_option( SWIFTLING_SEARCH_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read on the Settings screen.
	$counts = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(DISTINCT post_id) AS pages, COUNT(DISTINCT lang) AS languages FROM %i', swiftling_table( 'index' ) ) );

	return array(
		'pages'     => $counts ? (int) $counts->pages : 0,
		'languages' => $counts ? (int) $counts->languages : 0,
		'built'     => isset( $saved['built'] ) ? (int) $saved['built'] : 0,
		'fulltext'  => swiftling_search_fulltext(),
	);
}

/**
 * Keeps some of the state of the index.
 *
 * @param array<string, mixed> $values Values to write, see swiftling_search_state().
 * @return array<string, mixed> The state as it is now.
 */
function swiftling_note_search_state( $values ) {
	$saved = get_option( SWIFTLING_SEARCH_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$saved = array_merge( $saved, $values );
	update_option( SWIFTLING_SEARCH_OPTION, $saved, true );
	return $saved;
}

/**
 * Whether the index table really has its full-text key. dbDelta asks for one, but a table engine
 * without full-text support simply does not make it.
 *
 * @return bool
 */
function swiftling_search_has_fulltext() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the schema of SwiftLing's own table, noted in an option.
	$keys = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', swiftling_table( 'index' ) ), 2 );

	return in_array( 'words', array_map( 'strval', (array) $keys ), true );
}

/**
 * Returns the words a search looks for, as WordPress parsed them.
 *
 * @param WP_Query $query Query.
 * @return array{0: string[], 1: string[], 2: bool} Words that must be there, words that must not,
 *         and whether the search is one piece of text: a phrase, or the exact words of a field.
 */
function swiftling_search_words( $query ) {
	$terms = isset( $query->query_vars['search_terms'] ) && is_array( $query->query_vars['search_terms'] ) ? $query->query_vars['search_terms'] : array();
	if ( ! $terms ) {
		$terms = array( (string) $query->get( 's' ) );
	}

	/** This filter is documented in wp-includes/class-wp-query.php */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, asked again so the words are read as WordPress read them.
	$prefix   = (string) apply_filters( 'wp_query_search_exclusion_prefix', '-' );
	$wanted   = array();
	$unwanted = array();
	foreach ( $terms as $term ) {
		$term = (string) $term;
		if ( '' !== $prefix && 0 === strpos( $term, $prefix ) ) {
			$term = substr( $term, strlen( $prefix ) );
			if ( '' !== trim( $term ) ) {
				$unwanted[] = trim( $term );
			}
			continue;
		}
		if ( '' !== trim( $term ) ) {
			$wanted[] = trim( $term );
		}
	}

	return array( $wanted, $unwanted, ! empty( $query->query_vars['sentence'] ) || ! empty( $query->query_vars['exact'] ) );
}

/**
 * Whether the full-text index can answer this search. It splits text into words of its own, so it
 * is used only for whole words of at least four letters, and not for writing it does not split,
 * such as Chinese, Japanese, Korean or Thai. Everything else is matched with LIKE.
 *
 * @param string[] $wanted   Words that must be there.
 * @param string[] $unwanted Words that must not.
 * @param bool     $sentence Whether the search is one piece of text.
 * @return bool
 */
function swiftling_search_uses_fulltext( $wanted, $unwanted, $sentence ) {
	if ( $sentence || ! swiftling_search_fulltext() ) {
		return false;
	}
	foreach ( array_merge( $wanted, $unwanted ) as $word ) {
		// One word, of letters and digits, that MySQL counts as a word, and not in a writing it
		// does not split by itself.
		if ( 1 !== preg_match( '~^[\p{L}\p{N}_\'’-]+$~u', $word ) || 1 === preg_match( '~[\x{0e00}-\x{0e7f}\x{3000}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}]~u', $word ) ) {
			return false;
		}
	}
	foreach ( $wanted as $word ) {
		if ( mb_strlen( $word ) < 4 ) {
			return false;
		}
	}
	return true;
}

/**
 * Returns the posts whose translation into a language matches a search.
 *
 * @param string   $locale Locale.
 * @param WP_Query $query  Query.
 * @return int[] Post IDs.
 */
function swiftling_search_ids( $locale, $query ) {
	global $wpdb;

	list( $wanted, $unwanted, $sentence ) = swiftling_search_words( $query );
	if ( ! $wanted ) {
		return array();
	}

	/**
	 * Filters how many posts one search takes from the index of translations at most.
	 *
	 * @param int      $limit Number of posts.
	 * @param WP_Query $query Query.
	 */
	$limit = max( 1, (int) apply_filters( 'swiftling_search_limit', SWIFTLING_SEARCH_MAX_POSTS, $query ) );
	$table = swiftling_table( 'index' );

	if ( swiftling_search_uses_fulltext( $wanted, $unwanted, $sentence ) ) {
		$against = '';
		foreach ( $wanted as $word ) {
			$against .= '+' . $word . '* ';
		}
		foreach ( $unwanted as $word ) {
			$against .= '-' . $word . ' ';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read once per search.
		$found = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE lang = %s AND MATCH(title, body) AGAINST (%s IN BOOLEAN MODE) LIMIT %d', $table, $locale, trim( $against ), $limit ) ) );
		if ( $found ) {
			return $found;
		}
		// The full-text index keeps a word list of its own: it skips the words MySQL counts as too
		// common, splits text its own way, and takes in a row once it is written for good. A search
		// it answers with nothing is asked again below, where the same rows are read word by word.
	}

	// A query that asks for the text exactly, as WordPress's "exact" does, compares the whole text.
	$exact = ! empty( $query->query_vars['exact'] );
	$where = array();
	$args  = array( $table, $locale );
	foreach ( $wanted as $word ) {
		$like    = $exact ? $word : '%' . $wpdb->esc_like( $word ) . '%';
		$where[] = $exact ? '(title = %s OR body = %s)' : '(title LIKE %s OR body LIKE %s)';
		array_push( $args, $like, $like );
	}
	foreach ( $unwanted as $word ) {
		$like    = $exact ? $word : '%' . $wpdb->esc_like( $word ) . '%';
		$where[] = $exact ? '(title <> %s AND body <> %s)' : '(title NOT LIKE %s AND body NOT LIKE %s)';
		array_push( $args, $like, $like );
	}
	$args[] = $limit;
	$sql    = 'SELECT post_id FROM %i WHERE lang = %s AND ' . implode( ' AND ', $where ) . ' LIMIT %d';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table, read once per search; $sql has one placeholder per value.
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) ) );
}

/**
 * Widens the search of a page in a language with the posts whose translation matches.
 *
 * WordPress writes " AND (<words>)", and for a visitor who is not signed in " AND (post_password =
 * '')" after it. Only the group of words is widened, so the rest keeps its meaning; a clause of
 * another shape, which another plugin wrote, is left as it is.
 *
 * @param string   $search Search clause of the query.
 * @param WP_Query $query  Query.
 * @return string
 */
function swiftling_filter_search( $search, $query ) {
	global $wpdb;

	$locale = swiftling_search_locale();
	if ( '' === $locale || '' === trim( (string) $search ) || ! swiftling_search_enabled() ) {
		return $search;
	}

	$ids = swiftling_search_ids( $locale, $query );
	if ( ! $ids ) {
		return $search;
	}

	$rest = rtrim( (string) $search );
	$tail = '';
	if ( preg_match( '~\s+AND\s+\(\s*' . preg_quote( $wpdb->posts, '~' ) . '\.post_password\s*=\s*\'\'\s*\)$~', $rest, $found ) ) {
		$tail = $found[0];
		$rest = substr( $rest, 0, -strlen( $found[0] ) );
	}

	$words = trim( $rest );
	if ( 0 !== stripos( $words, 'AND (' ) || ')' !== substr( $words, -1 ) ) {
		return $search;
	}

	return ' AND ( ' . substr( $words, 4 ) . ' OR ' . $wpdb->posts . '.ID IN (' . implode( ',', $ids ) . ') ) ' . $tail . ' ';
}
add_filter( 'posts_search', 'swiftling_filter_search', 10, 2 );

/**
 * Registers the route that builds the index again, one batch at a time, for the Settings screen.
 *
 * - POST swiftling/v1/search-index: writes one batch and says how far it came.
 */
function swiftling_register_search_route() {
	register_rest_route(
		'swiftling/v1',
		'/search-index',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_search_index',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'after' => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'swiftling_register_search_route' );

/**
 * Builds one batch of the index and answers with how far it came and what the index holds now.
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function swiftling_rest_search_index( $request ) {
	$after = (string) $request['after'];
	if ( '' === $after ) {
		// The languages of the site may have changed since the index was written.
		swiftling_forget_old_index_languages();
		swiftling_note_search_state( array( 'fulltext' => swiftling_search_has_fulltext() ) );
	}

	$batch = swiftling_index_batch( $after );
	if ( $batch['finished'] ) {
		swiftling_note_search_state( array( 'built' => time() ) );
	}

	return $batch + swiftling_search_state();
}
