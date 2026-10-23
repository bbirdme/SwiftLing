<?php
/**
 * The first setup and starting over.
 *
 * The first setup asks one thing, the language the site's content is written in. Until it is
 * answered, every SwiftLing screen opens it; right after activation it opens by itself. The
 * original language is fixed after that and changes only by starting over, because translations
 * are made from it.
 *
 * Starting over removes the languages and settings, and with them, if asked, everything else
 * SwiftLing stored: translations and translated slugs, what it learned about where the texts of
 * each page sit, and which posts are translated. Translations are stored by locale, so when they are kept, adding
 * the same language again brings them back. Pages, posts, media, menus and switchers placed in
 * them are never touched. Then the first setup opens again.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the address of the first setup.
 *
 * @return string
 */
function swiftling_setup_url() {
	return admin_url( 'admin.php?page=swiftling-setup' );
}

/**
 * Remembers on activation that the first setup should open, unless it was answered before.
 * Called by SwiftLing\Plugin::activate().
 */
function swiftling_note_activation() {
	if ( ! swiftling_is_set_up() ) {
		update_option( 'swiftling_setup_redirect', 1, false );
	}
}

/**
 * Opens the first setup once, right after the plugin was activated on its own. Activating several
 * plugins at once leaves the user where they were. A request in the background, as one a plugin
 * runs through admin-ajax.php after the activation, and a screen of someone who may not set
 * SwiftLing up, leave it for the next screen of someone who may.
 */
function swiftling_open_setup_after_activation() {
	if ( ! get_option( 'swiftling_setup_redirect' ) || wp_doing_ajax() || wp_doing_cron() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	delete_option( 'swiftling_setup_redirect' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether WordPress activated several plugins.
	if ( isset( $_GET['activate-multi'] ) || swiftling_is_set_up() ) {
		return;
	}
	wp_safe_redirect( swiftling_setup_url() );
	exit;
}
add_action( 'admin_init', 'swiftling_open_setup_after_activation' );

/**
 * Saves the first setup: the original language, from WordPress's list or a custom one.
 */
function swiftling_save_setup() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not set up SwiftLing.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_setup' );

	// The original is fixed once chosen; changing it means starting over.
	if ( swiftling_is_set_up() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=swiftling-languages' ) );
		exit;
	}

	$wordpress = swiftling_available_languages( false );
	$custom    = isset( $_POST['custom'] ) && is_array( $_POST['custom'] ) ? map_deep( wp_unslash( $_POST['custom'] ), 'sanitize_text_field' ) : array();
	$name      = isset( $custom['name'] ) ? trim( $custom['name'] ) : '';
	$code      = isset( $custom['locale'] ) ? trim( $custom['locale'] ) : '';

	if ( '' !== $name || '' !== $code ) {
		// A custom language: both a name and a code are needed.
		$locale = swiftling_normalize_locale( $code );
		if ( '' === $name || '' === $locale ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'swiftling_notice' => 'custom_invalid',
						'swiftling_custom' => rawurlencode( $name ),
					),
					swiftling_setup_url()
				)
			);
			exit;
		}
		$wp_locale = isset( $custom['wp_locale'] ) && isset( $wordpress[ $custom['wp_locale'] ] ) ? $custom['wp_locale'] : ( isset( $wordpress[ $locale ] ) ? $locale : 'en_US' );
		$source    = array(
			'locale' => $locale,
			'name'   => $name,
			'slug'   => sanitize_title( strtolower( (string) strtok( $locale, '_' ) ) ),
			'label'  => '',
			'custom' => array(
				'wp_locale' => $wp_locale,
				'rtl'       => ! empty( $custom['rtl'] ),
			),
		);
	} else {
		$locale = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		if ( ! isset( $wordpress[ $locale ] ) ) {
			wp_safe_redirect( add_query_arg( 'swiftling_notice', 'choose', swiftling_setup_url() ) );
			exit;
		}
		$source = array(
			'locale' => $locale,
			'name'   => $wordpress[ $locale ]['name'],
			'slug'   => sanitize_title( $wordpress[ $locale ]['slug'] ),
			'label'  => '',
		);
	}

	update_option(
		'swiftling_languages',
		array(
			'source'        => $source,
			'targets'       => array(),
			'order'         => array( $source['locale'] ),
			'names'         => 'native',
			'format'        => 'parameter',
			'source_folder' => false,
		),
		true
	);
	swiftling_refresh_all_translated();

	wp_safe_redirect( add_query_arg( 'swiftling_notice', 'setup', admin_url( 'admin.php?page=swiftling-languages' ) ) );
	exit;
}
add_action( 'admin_post_swiftling_setup', 'swiftling_save_setup' );

/**
 * Returns the word the user types to confirm starting over, in the language of the admin.
 *
 * @return string
 */
function swiftling_start_over_word() {
	/* translators: What the user types to confirm removing SwiftLing's data. Keep it short and in capitals. */
	return __( 'START OVER', 'swiftling' );
}

/**
 * Returns what starting over would remove, counted as the Overview counts: the pages and posts
 * that have a translation, per language. WordPress's own translations of WordPress, themes and
 * plugins are left out of the counts: SwiftLing only notes them, and they come back by
 * themselves.
 *
 * @return array{pages: array<string, int>, own: int} Pages and posts with a translation, keyed by
 *         the language's slug, in the order of the languages, only languages with any; and the
 *         number of the site's own translations of anything, pages or not.
 */
function swiftling_start_over_counts() {
	global $wpdb;
	require_once SWIFTLING_DIR . 'includes/progress.php';

	$counts   = array(
		'pages' => array(),
		'own'   => 0,
	);
	$progress = swiftling_site_page_progress();
	foreach ( $progress['languages'] as $slug => $states ) {
		$translated = $states['reviewed'] + $states['machine'] + $states['partial'] + $states['outdated'];
		if ( $translated ) {
			$counts['pages'][ $slug ] = $translated;
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counted on the Settings screen only.
	$counts['own'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status <> %d', swiftling_table( 'strings' ), SWIFTLING_OFFICIAL ) );
	return $counts;
}

/**
 * Removes the languages and settings, and keeps the translations: added again, a language finds
 * them, and what the Themes & plugins screen found and chose, and the translation service.
 */
function swiftling_remove_languages_and_settings() {
	global $wpdb;
	foreach ( array( 'translated', 'index' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emptying SwiftLing's own tables, as the user confirmed.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', swiftling_table( $table ) ) );
	}
	foreach ( array( 'swiftling_languages', 'swiftling_switcher', 'swiftling_settings', SWIFTLING_SEARCH_OPTION ) as $option ) {
		delete_option( $option );
	}
}

/**
 * Removes everything SwiftLing keeps on this site, as Start over's "Remove everything" promises.
 * Deleting the plugin removes nothing, so this is the one way to it.
 *
 * - Every table is emptied; the tables stay, with their version, for SwiftLing still runs.
 * - Every option and transient of SwiftLing's name goes: also one added later, so none is ever
 *   left behind, and those of plugins built on it that carry its name, as SwiftLing Pro's. The
 *   translation service and its key go with them.
 * - The users' SwiftLing preferences and which languages menu items show in go.
 * - Pages, posts, media and menus stay, also a separate copy of a page for a language, and the
 *   switchers placed in widgets, blocks and shortcodes, which show nothing until a language is
 *   added again.
 *
 * The log keeps one entry, that it happened.
 */
function swiftling_remove_everything() {
	global $wpdb;
	foreach ( SWIFTLING_TABLES as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emptying SwiftLing's own tables, as the user confirmed.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', swiftling_table( $table ) ) );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Finding every option of SwiftLing's name, once, as the user confirmed.
	$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'swiftling_' ) . '%', $wpdb->esc_like( '_transient_swiftling_' ) . '%', $wpdb->esc_like( '_transient_timeout_swiftling_' ) . '%', $wpdb->esc_like( '_site_transient_swiftling_' ) . '%' ) );
	foreach ( $names as $name ) {
		if ( 'swiftling_db_version' !== $name ) {
			delete_option( $name );
		}
	}
	wp_cache_delete( 'alloptions', 'options' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Finding the users' SwiftLing preferences, once, as the user confirmed.
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( 'swiftling_' ) . '%' ) ) as $key ) {
		delete_metadata( 'user', 0, (string) $key, '', true );
	}
	// Which languages each menu item shows in, see includes/menu-languages.php.
	delete_post_meta_by_key( '_swiftling_show_in' );
	// The daily deletion of old log entries starts again from an admin screen.
	wp_clear_scheduled_hook( SWIFTLING_LOG_CRON );

	swiftling_log( 'event', 'languages', __( 'SwiftLing started over: everything it kept was removed.', 'swiftling' ) );
}

/**
 * Starts over, as the confirmation on the Settings screen asks: removes everything SwiftLing
 * stored, or only the languages and settings, and opens the first setup.
 */
function swiftling_start_over() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not start SwiftLing over.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_start_over' );

	$typed = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
	// Letters of any case, also beyond ASCII, as the script compares them.
	if ( mb_strtoupper( trim( $typed ) ) !== mb_strtoupper( swiftling_start_over_word() ) ) {
		wp_safe_redirect( add_query_arg( 'swiftling_notice', 'confirm', admin_url( 'admin.php?page=swiftling-settings' ) ) . '#start-over' );
		exit;
	}
	$scope = isset( $_POST['scope'] ) && 'keep' === sanitize_key( wp_unslash( $_POST['scope'] ) ) ? 'keep' : 'everything';

	/**
	 * Fires before SwiftLing starts over, while everything is still there, so a plugin built on it
	 * can remove what it keeps, using what SwiftLing removes next, such as a translation service's
	 * key. See docs/hooks.md.
	 *
	 * @param string $scope everything, or keep when the translations are kept.
	 */
	do_action( 'swiftling_starting_over', $scope );

	if ( 'everything' === $scope ) {
		swiftling_remove_everything();
	} else {
		swiftling_remove_languages_and_settings();
	}
	swiftling_flush_cache();

	/**
	 * Fires after SwiftLing started over.
	 *
	 * @param string $scope everything, or keep when the translations were kept.
	 */
	do_action( 'swiftling_started_over', $scope );

	wp_safe_redirect( add_query_arg( 'swiftling_notice', 'started_over_' . $scope, swiftling_setup_url() ) );
	exit;
}
add_action( 'admin_post_swiftling_start_over', 'swiftling_start_over' );
