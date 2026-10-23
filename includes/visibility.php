<?php
/**
 * Untranslated content: shown in the original language, or hidden in a language it is not
 * translated into, chosen per post type on the Settings screen.
 *
 * A post counts as translated into a language once every text of its own has a translation there,
 * machine or reviewed, or had one before the text changed. Shared texts, such as the header or a
 * synced pattern, do not count: they belong to their own objects. A post that was never opened in
 * the translation editor has no texts SwiftLing knows of, so it is not translated.
 *
 * Which posts are translated is kept in the swiftling_translated table, one row per post and
 * language, so a query can ask for it. It is written when the editor saves translations or checks a
 * page, and in full when the languages change. A post changed since its last check keeps its row
 * until it is checked again.
 *
 * A hidden post leaves every list of posts, the menus, the Navigation block, the previous and next
 * links and the switcher in that language, for everyone. Its own address opens it in the original
 * language or is not found, as the Settings screen says.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the settings of the Settings screen.
 *
 * Saved in the swiftling_settings option:
 *
 *     array(
 *         'untranslated'   => array( 'post' => 'hide' ), // Post types not named show the original.
 *         'never'          => array( 'project' ),        // Post types never translated.
 *         'hidden_address' => 'original',                // Or '404'.
 *         'search'         => true,                      // Search also reads translations.
 *     )
 *
 * @return array{untranslated: array<string, string>, never: string[], hidden_address: string, search: bool}
 */
function swiftling_settings() {
	return swiftling_memo( 'settings', 'swiftling_build_settings' );
}

/**
 * Builds what swiftling_settings() returns, from the option.
 *
 * @return array
 */
function swiftling_build_settings() {
	$saved = get_option( 'swiftling_settings', array() );
	$saved = is_array( $saved ) ? $saved : array();
	return array(
		'untranslated'   => isset( $saved['untranslated'] ) && is_array( $saved['untranslated'] ) ? $saved['untranslated'] : array(),
		'never'          => isset( $saved['never'] ) && is_array( $saved['never'] ) ? array_values( array_map( 'strval', $saved['never'] ) ) : array(),
		'hidden_address' => isset( $saved['hidden_address'] ) && '404' === $saved['hidden_address'] ? '404' : 'original',
		// Sites that saved their settings before the index existed search translations too.
		'search'         => ! isset( $saved['search'] ) || (bool) $saved['search'],
	);
}

/**
 * Returns the post types SwiftLing translates: those visitors can open on their own, except the
 * ones the Settings screen sets to Never. A post of a type set to Never shows the original in every
 * language, has no translation editor and is left out of the lists, counts and sitemaps.
 *
 * @return array<string, WP_Post_Type> Keyed by name.
 */
function swiftling_content_types() {
	return array_diff_key( swiftling_viewable_types(), array_flip( swiftling_settings()['never'] ) );
}

/**
 * Whether the Settings screen sets a post type to Never.
 *
 * @param string $type Post type.
 * @return bool
 */
function swiftling_is_never_translated( $type ) {
	return in_array( (string) $type, swiftling_settings()['never'], true );
}

/**
 * Whether an object is a post of a type set to Never, or a comment on one: visitors see its original
 * in every language.
 *
 * @param string $owner Object key, e.g. "post:12" or "comment:7".
 * @return bool
 */
function swiftling_is_never_translated_owner( $owner ) {
	$never = swiftling_settings()['never'];
	if ( ! $never ) {
		return false;
	}
	if ( 0 === strpos( (string) $owner, 'comment:' ) ) {
		$comment = swiftling_owner_comment( $owner );
		return ! $comment || in_array( (string) get_post_type( (int) $comment->comment_post_ID ), $never, true );
	}
	return 0 === strpos( (string) $owner, 'post:' ) && in_array( (string) get_post_type( (int) substr( (string) $owner, 5 ) ), $never, true );
}

/**
 * Returns the post types visitors can open on their own, which the Settings screen lists.
 *
 * @return array<string, WP_Post_Type> Keyed by name.
 */
function swiftling_viewable_types() {
	$types = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $type ) {
		// Media files open as files, and builders keep their templates and parts in types that are
		// left out of search or of menus.
		if ( 'attachment' !== $name && ! $type->exclude_from_search && $type->show_in_nav_menus ) {
			$types[ $name ] = $type;
		}
	}
	return $types;
}

/**
 * Returns the post types whose untranslated posts are hidden.
 *
 * @return string[]
 */
function swiftling_hidden_types() {
	$hidden   = array();
	$settings = swiftling_settings();
	foreach ( $settings['untranslated'] as $type => $choice ) {
		if ( 'hide' === $choice && post_type_exists( $type ) && ! in_array( (string) $type, $settings['never'], true ) ) {
			$hidden[] = (string) $type;
		}
	}
	return $hidden;
}

/**
 * Returns the locale whose untranslated posts the current request hides.
 *
 * @return string Locale of the page's language, or an empty string when nothing is hidden.
 */
function swiftling_hiding_locale() {
	if ( 'translate' !== swiftling_mode() || ! swiftling_hidden_types() ) {
		return '';
	}
	$state = &swiftling_state();
	return (string) $state['locale'];
}

/**
 * Returns which of some posts are translated into a language.
 *
 * @param int[]  $post_ids Post IDs.
 * @param string $locale   Locale.
 * @return int[] The translated ones.
 */
function swiftling_translated_ids( $post_ids, $locale ) {
	global $wpdb;
	$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
	if ( ! $post_ids ) {
		return array();
	}
	$sql = 'SELECT post_id FROM %i WHERE lang = %s AND post_id IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table, read once per list; $sql has one placeholder per value.
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, swiftling_table( 'translated' ), $locale, ...$post_ids ) ) );
}

/**
 * Returns the languages a post is translated into.
 *
 * @param int $post_id Post ID.
 * @return string[] Locales.
 */
function swiftling_translated_locales( $post_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read once per page.
	return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT lang FROM %i WHERE post_id = %d', swiftling_table( 'translated' ), $post_id ) ) );
}

/**
 * Whether a post is hidden on the page being shown.
 *
 * @param WP_Post|int $post   Post.
 * @param string      $locale Locale to check; the page's language when empty.
 * @return bool
 */
function swiftling_is_hidden_post( $post, $locale = '' ) {
	$post   = get_post( $post );
	$locale = '' !== $locale ? $locale : swiftling_hiding_locale();
	// A separate copy is the translation itself.
	if ( ! $post || '' === $locale || ! in_array( $post->post_type, swiftling_hidden_types(), true ) || swiftling_copy_original_id( $post ) ) {
		return false;
	}
	return ! swiftling_translated_ids( array( $post->ID ), $locale );
}

/**
 * Returns the condition that keeps hidden posts out of a query.
 *
 * @param string $alias  Alias of the posts table in the query.
 * @param string $locale Locale.
 * @return string SQL starting with AND.
 */
function swiftling_hidden_posts_sql( $alias, $locale ) {
	global $wpdb;
	$types = swiftling_hidden_types();
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The alias is a fixed name from the caller; $in has one placeholder per type.
	return $wpdb->prepare( " AND ( {$alias}.post_type NOT IN ({$in}) OR {$alias}.ID IN ( SELECT post_id FROM %i WHERE lang = %s ) )", ...array_merge( $types, array( swiftling_table( 'translated' ), $locale ) ) );
}

/**
 * Keeps hidden posts out of the lists of posts on a page: archives, search, feeds, widgets and
 * blocks that list posts. The post a page shows is left to swiftling_open_hidden_post().
 *
 * @param string   $where WHERE clause of the query.
 * @param WP_Query $query Query.
 * @return string
 */
function swiftling_filter_hidden_where( $where, $query ) {
	global $wpdb;
	$locale = swiftling_hiding_locale();
	if ( '' === $locale || ( $query->is_main_query() && $query->is_singular() ) ) {
		return $where;
	}
	return $where . swiftling_hidden_posts_sql( $wpdb->posts, $locale );
}
add_filter( 'posts_where', 'swiftling_filter_hidden_where', 10, 2 );

/**
 * Keeps hidden posts out of queries that skip the filters of their SQL, such as get_posts() and
 * the Latest Posts block, by naming them.
 *
 * @param WP_Query $query Query.
 */
function swiftling_exclude_hidden_posts( $query ) {
	$locale = swiftling_hiding_locale();
	if ( '' === $locale || ! $query->get( 'suppress_filters' ) ) {
		return;
	}
	$hidden = swiftling_hidden_ids( $locale );
	if ( $hidden ) {
		$query->set( 'post__not_in', array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $hidden ) );
	}
}
add_action( 'pre_get_posts', 'swiftling_exclude_hidden_posts' );

/**
 * Returns every hidden post in a language.
 *
 * @param string $locale Locale.
 * @return int[]
 */
function swiftling_hidden_ids( $locale ) {
	global $wpdb;
	static $cache = array();
	if ( ! isset( $cache[ $locale ] ) ) {
		$types = swiftling_hidden_types();
		$sql   = 'SELECT ID FROM %i WHERE post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND ID NOT IN ( SELECT post_id FROM %i WHERE lang = %s )';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read once per request; $sql has one placeholder per value.
		$cache[ $locale ] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $wpdb->posts, ...array_merge( $types, array( swiftling_table( 'translated' ), $locale ) ) ) ) );
	}
	return $cache[ $locale ];
}

/**
 * Keeps hidden pages out of lists built with get_pages(), such as the page list of a menu
 * fallback, wp_list_pages() and the Page List block.
 *
 * @param WP_Post[] $pages Pages.
 * @return WP_Post[]
 */
function swiftling_filter_hidden_pages( $pages ) {
	$locale = swiftling_hiding_locale();
	if ( '' === $locale || ! is_array( $pages ) || ! $pages ) {
		return $pages;
	}
	$shown = array_flip( swiftling_translated_ids( wp_list_pluck( $pages, 'ID' ), $locale ) );
	$types = swiftling_hidden_types();
	return array_values(
		array_filter(
			$pages,
			static function ( $page ) use ( $shown, $types ) {
				return ! in_array( $page->post_type, $types, true ) || isset( $shown[ $page->ID ] );
			}
		)
	);
}
add_filter( 'get_pages', 'swiftling_filter_hidden_pages' );

/**
 * Takes links to hidden posts out of menus. Their submenu items move up a level, so a translated
 * page under a hidden one stays in the menu.
 *
 * @param array<int, WP_Post> $items Menu items.
 * @return array<int, WP_Post>
 */
function swiftling_filter_hidden_menu_items( $items ) {
	$locale = swiftling_hiding_locale();
	if ( '' === $locale || ! is_array( $items ) ) {
		return $items;
	}

	$types   = swiftling_hidden_types();
	$targets = array();
	foreach ( $items as $item ) {
		if ( isset( $item->type, $item->object ) && 'post_type' === $item->type && in_array( $item->object, $types, true ) ) {
			$targets[] = (int) $item->object_id;
		}
	}
	$shown = array_flip( swiftling_translated_ids( $targets, $locale ) );

	$removed = array();
	foreach ( $items as $item ) {
		if ( isset( $item->type, $item->object ) && 'post_type' === $item->type && in_array( $item->object, $types, true ) && ! isset( $shown[ (int) $item->object_id ] ) ) {
			$removed[ (string) $item->db_id ] = (string) $item->menu_item_parent;
		}
	}
	if ( ! $removed ) {
		return $items;
	}

	$kept = array();
	foreach ( $items as $item ) {
		if ( isset( $removed[ (string) $item->db_id ] ) ) {
			continue;
		}
		$parent = (string) $item->menu_item_parent;
		while ( isset( $removed[ $parent ] ) ) {
			$parent = $removed[ $parent ];
		}
		$item->menu_item_parent = $parent;
		$kept[]                 = $item;
	}
	return $kept;
}
add_filter( 'wp_nav_menu_objects', 'swiftling_filter_hidden_menu_items', 5 );

/**
 * Takes links to hidden posts out of the Navigation block of block themes.
 *
 * @param string $content Rendered link.
 * @param array  $block   Parsed block.
 * @return string
 */
function swiftling_filter_hidden_navigation_link( $content, $block ) {
	$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	if ( empty( $attrs['id'] ) || ( isset( $attrs['kind'] ) && 'post-type' !== $attrs['kind'] ) ) {
		return $content;
	}
	return swiftling_is_hidden_post( (int) $attrs['id'] ) ? '' : $content;
}
add_filter( 'render_block_core/navigation-link', 'swiftling_filter_hidden_navigation_link', 10, 2 );

/**
 * Skips hidden posts in the links to the previous and the next post.
 *
 * @param string $where WHERE clause of the adjacent post query; the posts table is "p".
 * @return string
 */
function swiftling_filter_hidden_adjacent( $where ) {
	$locale = swiftling_hiding_locale();
	return '' === $locale ? $where : $where . swiftling_hidden_posts_sql( 'p', $locale );
}
add_filter( 'get_previous_post_where', 'swiftling_filter_hidden_adjacent' );
add_filter( 'get_next_post_where', 'swiftling_filter_hidden_adjacent' );

/**
 * Opens a hidden post asked for by its address in the original language, or answers that it is
 * not found, as the Settings screen says.
 */
function swiftling_open_hidden_post() {
	if ( ! is_singular() || ! swiftling_is_hidden_post( get_queried_object() ) ) {
		return;
	}
	if ( '404' === swiftling_settings()['hidden_address'] ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		return;
	}
	// Temporary: the post may be translated tomorrow.
	wp_safe_redirect( swiftling_current_url( '' ), 302 );
	exit;
}
add_action( 'template_redirect', 'swiftling_open_hidden_post', 2 );

/**
 * Writes which languages some posts are translated into, from their stored texts and translations.
 * A published separate copy translates its original into its language, and so does a duplicate:
 * the language shows the post as it is, on purpose.
 *
 * Running it twice gives the same rows: each post's rows are removed and written again.
 *
 * @param int[] $post_ids Post IDs.
 */
function swiftling_refresh_translated( $post_ids ) {
	global $wpdb;
	require_once SWIFTLING_DIR . 'includes/progress.php';

	$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
	if ( ! $post_ids ) {
		return;
	}

	$keys = array();
	foreach ( $post_ids as $post_id ) {
		$keys[] = 'post:' . $post_id;
	}
	$maps   = swiftling_get_maps( $keys );
	$places = array();
	$lookup = array();
	foreach ( $maps as $key => $map ) {
		// The post's own texts: shared ones belong to their own objects.
		$places[ $key ] = array_filter(
			swiftling_map_places( $key, $map ),
			static function ( $unit_key ) {
				return ! swiftling_is_shared_key( (string) $unit_key );
			},
			ARRAY_FILTER_USE_KEY
		);
		foreach ( $places[ $key ] as $place ) {
			array_push( $lookup, ...array_filter( $place ) );
		}
	}

	$rows = array();
	foreach ( swiftling_languages()['targets'] as $language ) {
		$translations = $lookup ? swiftling_get_translations( $language['locale'], $lookup ) : array();
		foreach ( $places as $key => $own ) {
			if ( swiftling_units_translated( $own, $translations ) ) {
				$rows[] = array( (int) substr( $key, 5 ), $language['locale'] );
			}
		}
		$copies     = swiftling_published_copies( $language['locale'], true );
		$duplicates = swiftling_duplicate_ids( $language['locale'], true );
		foreach ( $post_ids as $post_id ) {
			if ( isset( $copies[ $post_id ] ) || isset( $duplicates[ $post_id ] ) ) {
				$rows[] = array( $post_id, $language['locale'] );
			}
		}
	}

	$table = swiftling_table( 'translated' );
	$sql   = 'DELETE FROM %i WHERE post_id IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table; $sql has one placeholder per post.
	$wpdb->query( $wpdb->prepare( $sql, $table, ...$post_ids ) );
	foreach ( array_chunk( $rows, 200 ) as $chunk ) {
		$values = array();
		$args   = array( $table );
		foreach ( $chunk as $row ) {
			$values[] = '(%d, %s)';
			array_push( $args, $row[0], $row[1] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table; one placeholder pair per row.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (post_id, lang) VALUES ' . implode( ',', $values ), ...$args ) );
	}

	/**
	 * Fires once the languages some posts are translated into were written again.
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	do_action( 'swiftling_translated_changed', $post_ids );
}

/**
 * Writes again which languages every checked post is translated into, as after the languages
 * change. Posts are read in batches, so a large site does not hold every map at once.
 */
function swiftling_refresh_all_translated() {
	global $wpdb;
	$table = swiftling_table( 'translated' );
	// Rows of languages the site no longer has.
	// Keyed by address slug; before PHP 8.1 only a list can be unpacked below.
	$locales = array_values( wp_list_pluck( swiftling_languages()['targets'], 'locale' ) );
	if ( $locales ) {
		$sql = 'DELETE FROM %i WHERE lang NOT IN (' . implode( ',', array_fill( 0, count( $locales ), '%s' ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table; $sql has one placeholder per locale.
		$wpdb->query( $wpdb->prepare( $sql, $table, ...$locales ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		return;
	}

	$after = '';
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read in keyed batches.
		$keys  = $wpdb->get_col( $wpdb->prepare( 'SELECT object_key FROM %i WHERE object_key LIKE %s AND object_key > %s ORDER BY object_key LIMIT 100', swiftling_table( 'objects' ), $wpdb->esc_like( 'post:' ) . '%', $after ) );
		$batch = count( $keys );
		if ( $keys ) {
			swiftling_refresh_translated(
				array_map(
					static function ( $key ) {
						return (int) substr( $key, 5 );
					},
					$keys
				)
			);
			$after = (string) end( $keys );
		}
	} while ( 100 === $batch );
}

/**
 * Forgets a deleted post's languages.
 *
 * @param int $post_id Post ID.
 */
function swiftling_forget_translated( $post_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', swiftling_table( 'translated' ), $post_id ) );
}
add_action( 'deleted_post', 'swiftling_forget_translated' );

/**
 * Returns how many published posts of each type are not translated into each language.
 *
 * @param string[] $types Post types.
 * @return array<string, array<string, int>> Post type => language slug => number.
 */
function swiftling_untranslated_counts( $types ) {
	global $wpdb;
	$counts = array();
	if ( ! $types ) {
		return $counts;
	}
	$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	foreach ( swiftling_languages()['targets'] as $slug => $language ) {
		$sql = "SELECT post_type, COUNT(*) AS n FROM %i WHERE post_status = 'publish' AND post_type IN ({$in}) AND ID NOT IN ( SELECT post_id FROM %i WHERE lang = %s ) GROUP BY post_type";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Counted on the Settings screen only; $in has one placeholder per type.
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $wpdb->posts, ...array_merge( $types, array( swiftling_table( 'translated' ), $language['locale'] ) ) ) ) as $row ) {
			$counts[ $row->post_type ][ $slug ] = (int) $row->n;
		}
	}
	return $counts;
}

/**
 * Saves the untranslated content part of the Settings screen.
 */
function swiftling_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the settings of SwiftLing.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_save_settings' );

	$settings   = swiftling_settings();
	$posted     = isset( $_POST['untranslated'] ) && is_array( $_POST['untranslated'] ) ? map_deep( wp_unslash( $_POST['untranslated'] ), 'sanitize_key' ) : array();
	$translated = isset( $_POST['translated'] ) && is_array( $_POST['translated'] ) ? map_deep( wp_unslash( $_POST['translated'] ), 'sanitize_key' ) : array();

	$settings['untranslated'] = array();
	$settings['never']        = array();
	foreach ( array_keys( swiftling_viewable_types() ) as $type ) {
		if ( isset( $translated[ $type ] ) && 'never' === $translated[ $type ] ) {
			$settings['never'][] = $type;
		} elseif ( isset( $posted[ $type ] ) && 'hide' === $posted[ $type ] ) {
			$settings['untranslated'][ $type ] = 'hide';
		}
	}
	$settings['hidden_address'] = isset( $_POST['hidden_address'] ) && '404' === sanitize_key( wp_unslash( $_POST['hidden_address'] ) ) ? '404' : 'original';
	$settings['search']         = isset( $_POST['search'] );

	update_option( 'swiftling_settings', $settings, true );

	wp_safe_redirect( add_query_arg( 'swiftling_notice', 'saved', admin_url( 'admin.php?page=swiftling-settings' ) ) );
	exit;
}
add_action( 'admin_post_swiftling_save_settings', 'swiftling_save_settings' );
