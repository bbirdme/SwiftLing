<?php
/**
 * The log: what failed, and what SwiftLing did that a person may want to know later.
 *
 * Until now a failure showed once on the screen, or not at all: a refused key of the translation
 * service, a page check that did not load, an address taken in a language. The log keeps it, on
 * the Logs screen, see admin/views/log.php, where a person can copy it for support.
 *
 * - Levels: error (something did not happen), warning (it happened differently than asked) and
 *   event (it happened, and is worth knowing, as the end of a run of Translate everything).
 * - Areas say where, see swiftling_log_areas(); SwiftLing Pro adds its own through the filter there
 *   and writes into the same log with swiftling_log().
 * - Never written: the content of pages or forms, keys, passwords, visitors' data. An entry names
 *   the object it is about (post:1571), not its text. swiftling_log_redact() takes out of every
 *   entry what looks like a key, an email address or an IP address, should a message carry one.
 * - Kept for 30 days by default (7 or 90 on the screen), deleted once a day by WordPress's cron, and
 *   never more than SWIFTLING_LOG_LIMIT entries: the oldest go as soon as a new one comes.
 * - The same entry again within ten minutes counts as a repeat of the last one instead of a new
 *   one, so a failure that happens for every text of a page is one entry.
 *
 * A visitor's request writes nothing unless something fails, and reads nothing of the log.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Most entries the log keeps.
 */
const SWIFTLING_LOG_LIMIT = 1000;

/**
 * Option with the days entries are kept.
 */
const SWIFTLING_LOG_KEEP_OPTION = 'swiftling_log_keep';

/**
 * The days a person can choose to keep entries for, the first the default.
 */
const SWIFTLING_LOG_KEEP_CHOICES = array( 30, 7, 90 );

/**
 * The cron event that deletes old entries once a day.
 */
const SWIFTLING_LOG_CRON = 'swiftling_log_cleanup';

/**
 * Seconds within which the same entry counts as a repeat of the last one.
 */
const SWIFTLING_LOG_REPEAT_WINDOW = 600;

/**
 * Returns the levels of entries with their names.
 *
 * @return array<string, string>
 */
function swiftling_log_levels() {
	return array(
		'error'   => __( 'Error', 'swiftling' ),
		'warning' => __( 'Warning', 'swiftling' ),
		'event'   => __( 'Event', 'swiftling' ),
	);
}

/**
 * Returns the levels of entries as the filters of the Logs screen name them, in the plural.
 *
 * @return array<string, string>
 */
function swiftling_log_level_filters() {
	return array(
		'error'   => __( 'Errors', 'swiftling' ),
		'warning' => __( 'Warnings', 'swiftling' ),
		'event'   => __( 'Events', 'swiftling' ),
	);
}

/**
 * Returns the areas of entries with their names.
 *
 * @return array<string, string>
 */
function swiftling_log_areas() {
	$areas = array(
		'service'   => __( 'Translation service', 'swiftling' ),
		'check'     => __( 'Page check', 'swiftling' ),
		'all'       => __( 'Translate everything', 'swiftling' ),
		'addresses' => __( 'URLs', 'swiftling' ),
		'cache'     => __( 'Cache', 'swiftling' ),
		'database'  => __( 'Database', 'swiftling' ),
		'languages' => __( 'Languages', 'swiftling' ),
		'copies'    => __( 'Separate copies', 'swiftling' ),
		'support'   => __( 'Support requests', 'swiftling' ),
	);

	/**
	 * Filters the areas of the log, so another plugin, as SwiftLing Pro, can name its own.
	 *
	 * @param array<string, string> $areas Area => name.
	 */
	return (array) apply_filters( 'swiftling_log_areas', $areas );
}

/**
 * Writes an entry into the log.
 *
 * @param string $level   error, warning or event.
 * @param string $area    Area, see swiftling_log_areas().
 * @param string $message What happened, for a person, in a sentence or two.
 * @param array  $args    detail (a technical line, such as an error code) and object (the key of
 *                        the object it is about, as "post:1571").
 * @return bool Whether it was written.
 */
function swiftling_log( $level, $area, $message, $args = array() ) {
	global $wpdb;
	if ( ! isset( swiftling_log_levels()[ $level ] ) || '' === trim( (string) $message ) || ! swiftling_log_ready() ) {
		return false;
	}
	$area    = substr( sanitize_key( (string) $area ), 0, 32 );
	$message = swiftling_log_redact( wp_strip_all_tags( (string) $message ) );
	$detail  = swiftling_log_redact( wp_strip_all_tags( isset( $args['detail'] ) ? (string) $args['detail'] : '' ) );
	$object  = isset( $args['object'] ) ? substr( (string) $args['object'], 0, 64 ) : '';
	$table   = swiftling_table( 'log' );
	$now     = time();

	// The same entry again soon after: one entry, counted.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, written only when something happens.
	$last = $wpdb->get_row( $wpdb->prepare( 'SELECT id, level, area, message, detail, object_key, logged FROM %i ORDER BY id DESC LIMIT 1', $table ) );
	if ( $last && $last->level === $level && $last->area === $area && $last->message === $message && $last->detail === $detail && $last->object_key === $object && $now - (int) $last->logged <= SWIFTLING_LOG_REPEAT_WINDOW ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table.
		return false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET repeats = repeats + 1, logged = %d WHERE id = %d', $table, $now, (int) $last->id ) );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- SwiftLing's own table.
	$written = $wpdb->insert(
		$table,
		array(
			'logged'     => $now,
			'level'      => $level,
			'area'       => $area,
			'message'    => $message,
			'detail'     => $detail,
			'object_key' => $object,
			'repeats'    => 1,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
	);
	if ( ! $written ) {
		return false;
	}
	// Never more than the limit: the oldest go as soon as a new one comes.
	$id = (int) $wpdb->insert_id;
	if ( $id > SWIFTLING_LOG_LIMIT ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, $id - SWIFTLING_LOG_LIMIT ) );
	}
	return true;
}

/**
 * Whether the log's table is there: the tables are made or updated when SwiftLing starts, and a
 * failure while that happens is written once they are.
 *
 * @return bool
 */
function swiftling_log_ready() {
	return (int) get_option( 'swiftling_db_version' ) >= 10;
}

/**
 * Takes out of a text of the log what must never be kept: keys of services, tokens, email
 * addresses and IP addresses, should a message from a service carry one.
 *
 * @param string $text Text.
 * @return string
 */
function swiftling_log_redact( $text ) {
	$patterns = array(
		'/AIza[0-9A-Za-z_\-]{35}/'             => '[key]',
		'/\b(?:sk|pk|rk)-[A-Za-z0-9_\-]{16,}/' => '[key]',
		'/\bBearer\s+[A-Za-z0-9._\-]+/i'       => 'Bearer [token]',
		'/([?&](?:key|api_key|apikey|token|auth)=)[^&\s]+/i' => '$1[key]',
		'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
		'/\b(?:\d{1,3}\.){3}\d{1,3}\b/'        => '[ip]',
	);
	return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
}

/**
 * Returns the days entries are kept.
 *
 * @return int
 */
function swiftling_log_keep_days() {
	$days = (int) get_option( SWIFTLING_LOG_KEEP_OPTION, SWIFTLING_LOG_KEEP_CHOICES[0] );
	return in_array( $days, SWIFTLING_LOG_KEEP_CHOICES, true ) ? $days : SWIFTLING_LOG_KEEP_CHOICES[0];
}

/**
 * Deletes the entries older than the days they are kept, and those over the limit. Runs once a day.
 */
function swiftling_log_cleanup() {
	global $wpdb;
	if ( ! swiftling_log_ready() ) {
		return;
	}
	$table = swiftling_table( 'log' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, once a day.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE logged < %d', $table, time() - swiftling_log_keep_days() * DAY_IN_SECONDS ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, once a day.
	$newest = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) );
	if ( $newest > SWIFTLING_LOG_LIMIT ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, once a day.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, $newest - SWIFTLING_LOG_LIMIT ) );
	}
}
add_action( SWIFTLING_LOG_CRON, 'swiftling_log_cleanup' );

/**
 * Schedules the daily deletion of old entries, once, on an admin screen.
 */
function swiftling_log_schedule() {
	if ( ! wp_next_scheduled( SWIFTLING_LOG_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SWIFTLING_LOG_CRON );
	}
}
add_action( 'admin_init', 'swiftling_log_schedule' );

/**
 * Empties the log.
 */
function swiftling_log_clear() {
	global $wpdb;
	if ( swiftling_log_ready() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, as the user asked.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', swiftling_table( 'log' ) ) );
	}
}

/**
 * Returns entries of the log, the newest first, with how many there are of each level.
 *
 * @param array $args level, area, search, page and per_page.
 * @return array{entries: object[], shown: int, counts: array<string, int>, page: int, pages: int}
 */
function swiftling_log_entries( $args = array() ) {
	global $wpdb;
	$args   = wp_parse_args(
		$args,
		array(
			'level'    => '',
			'area'     => '',
			'search'   => '',
			'page'     => 1,
			'per_page' => 50,
		)
	);
	$empty  = array(
		'entries' => array(),
		'shown'   => 0,
		'counts'  => array_fill_keys( array_keys( swiftling_log_levels() ), 0 ),
		'page'    => 1,
		'pages'   => 1,
	);
	$table  = swiftling_table( 'log' );
	$counts = $empty['counts'];
	if ( ! swiftling_log_ready() ) {
		return $empty;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, on its screen.
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT level, COUNT(*) AS n FROM %i GROUP BY level', $table ) ) as $row ) {
		$counts[ $row->level ] = (int) $row->n;
	}

	$where  = '1 = 1';
	$values = array( $table );
	if ( isset( $counts[ $args['level'] ] ) ) {
		$where   .= ' AND level = %s';
		$values[] = $args['level'];
	}
	if ( '' !== $args['area'] ) {
		$where   .= ' AND area = %s';
		$values[] = $args['area'];
	}
	if ( '' !== trim( $args['search'] ) ) {
		$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
		$where   .= ' AND ( message LIKE %s OR detail LIKE %s OR object_key LIKE %s )';
		$values[] = $like;
		$values[] = $like;
		$values[] = $like;
	}
	$per_page = max( 1, (int) $args['per_page'] );
	$query    = 'SELECT COUNT(*) FROM %i WHERE ' . $where;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table; $query has one placeholder per value.
	$shown = (int) $wpdb->get_var( $wpdb->prepare( $query, $values ) );
	$pages = max( 1, (int) ceil( $shown / $per_page ) );
	$page  = min( $pages, max( 1, (int) $args['page'] ) );
	array_push( $values, $per_page, ( $page - 1 ) * $per_page );
	$query = 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table; $query has one placeholder per value.
	$entries = $wpdb->get_results( $wpdb->prepare( $query, $values ) );

	return array(
		'entries' => (array) $entries,
		'shown'   => $shown,
		'counts'  => $counts,
		'page'    => $page,
		'pages'   => $pages,
	);
}

/**
 * Returns the report "Copy for support" copies: the versions and setup of the site, and the latest
 * errors and warnings. Nothing a visitor wrote, no content, keys, passwords or email addresses;
 * the person sees it before copying.
 *
 * @return string Plain text.
 */
function swiftling_log_report() {
	global $wpdb;
	$languages = swiftling_languages();
	$theme     = wp_get_theme();
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all     = get_plugins();
	$plugins = array();
	foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
		if ( isset( $all[ $file ] ) ) {
			$plugins[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
		}
	}
	$formats = array(
		'parameter' => 'parameter (?lang=)',
		'directory' => 'language folders',
		'subdomain' => 'subdomains',
		'domain'    => 'domains',
	);
	$format  = swiftling_url_format();
	$targets = wp_list_pluck( $languages['targets'], 'name' );
	$service = \SwiftLing\Translation\Services::current();
	$caches  = swiftling_active_cache_plugins();

	$lines = array(
		'SwiftLing support report · ' . gmdate( 'j M Y H:i' ) . ' UTC',
		sprintf( 'WordPress %s · PHP %s · %s · Multisite: %s', get_bloginfo( 'version' ), PHP_VERSION, $wpdb->db_server_info(), is_multisite() ? 'yes' : 'no' ),
		sprintf( 'SwiftLing %s (database %d) · SwiftLing Pro: %s', SWIFTLING_VERSION, (int) get_option( 'swiftling_db_version' ), defined( 'SWIFTLING_PRO_VERSION' ) ? SWIFTLING_PRO_VERSION : 'not active' ),
		sprintf( 'Theme: %s %s', $theme->get( 'Name' ), $theme->get( 'Version' ) ),
		sprintf( 'Active plugins (%d): %s', count( $plugins ), implode( ', ', $plugins ) ),
		sprintf( 'Languages: %s (original) → %s · Addresses: %s', $languages['source']['name'], $targets ? implode( ', ', $targets ) : 'none yet', isset( $formats[ $format ] ) ? $formats[ $format ] : $format ),
		sprintf( 'Translation service: %s', $service ? $service->name() . ' (key saved)' : 'none' ),
		sprintf( 'Object cache: %s · Page cache plugin: %s', wp_using_ext_object_cache() ? 'persistent' : 'none', $caches ? implode( ', ', $caches ) : 'none found' ),
		'',
		sprintf( 'Last errors and warnings (up to 20, last %d days):', swiftling_log_keep_days() ),
	);

	$found = 0;
	if ( swiftling_log_ready() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, on its screen.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE level IN ('error', 'warning') ORDER BY id DESC LIMIT 20", swiftling_table( 'log' ) ) );
		$levels = swiftling_log_levels();
		$areas  = swiftling_log_areas();
		foreach ( (array) $rows as $row ) {
			$lines[] = sprintf(
				'%s · %s · %s · %s%s%s',
				gmdate( 'j M H:i', (int) $row->logged ),
				isset( $levels[ $row->level ] ) ? $levels[ $row->level ] : $row->level,
				isset( $areas[ $row->area ] ) ? $areas[ $row->area ] : $row->area,
				$row->message,
				'' !== $row->detail || '' !== $row->object_key ? ' [' . trim( $row->object_key . ' · ' . $row->detail, ' ·' ) . ']' : '',
				(int) $row->repeats > 1 ? ' ×' . (int) $row->repeats : ''
			);
			++$found;
		}
	}
	if ( ! $found ) {
		$lines[] = 'None.';
	}
	return implode( "\n", $lines );
}

/**
 * Saves the days entries are kept, from the Log screen.
 */
function swiftling_save_log_keep() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the settings of SwiftLing.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_save_log_keep' );
	$days = isset( $_POST['keep'] ) ? absint( $_POST['keep'] ) : 0;
	if ( in_array( $days, SWIFTLING_LOG_KEEP_CHOICES, true ) ) {
		update_option( SWIFTLING_LOG_KEEP_OPTION, $days, false );
		swiftling_log_cleanup();
	}
	wp_safe_redirect( add_query_arg( 'swiftling_notice', 'saved', admin_url( 'admin.php?page=swiftling-log' ) ) );
	exit;
}
add_action( 'admin_post_swiftling_save_log_keep', 'swiftling_save_log_keep' );

/**
 * Empties the log, as the person confirmed on the Log screen.
 */
function swiftling_handle_clear_log() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the settings of SwiftLing.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_clear_log' );
	swiftling_log_clear();
	wp_safe_redirect( add_query_arg( 'swiftling_notice', 'cleared', admin_url( 'admin.php?page=swiftling-log' ) ) );
	exit;
}
add_action( 'admin_post_swiftling_clear_log', 'swiftling_handle_clear_log' );
