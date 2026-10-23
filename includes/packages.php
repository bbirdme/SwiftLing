<?php
/**
 * Themes & plugins: finding the texts of themes and plugins, and translating them.
 *
 * What a theme or plugin shows visitors comes from two places: its settings, such as a footer text
 * or a tracking script, and its code, such as a button. Both are found only when the user asks:
 *
 * - a recording notes them on the pages the user opens, see SwiftLing\Packages\Recorder and
 *   swiftling_rest_record();
 * - a scan lists the values of a package's settings that could be text, see
 *   SwiftLing\Packages\Settings, or every text in its code, see SwiftLing\Packages\CodeScanner.
 *
 * Only texts of themes and plugins are kept: never the content of posts, values a builder declares
 * (such as ACF fields, translated with their page or options page) or WordPress's own texts.
 *
 * Each package has three lists in the translation editor, see SwiftLing\Packages\Texts.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

use SwiftLing\Packages\Catalogue;
use SwiftLing\Packages\CodeScanner;
use SwiftLing\Packages\Packages;
use SwiftLing\Packages\Recorder;
use SwiftLing\Packages\Settings;
use SwiftLing\Packages\Texts;

/**
 * Returns the address of a place of the Themes & plugins screen, or of a package's list in the
 * translation editor.
 *
 * @param string $id   Package ID, or an empty string for the screen itself.
 * @param string $view choose (its settings to translate), scan (its code, then all its texts), or a
 *                     list of the editor: strings, settings or code.
 * @param string $lang Optional language slug, for the editor.
 * @return string
 */
function swiftling_package_url( $id = '', $view = '', $lang = '' ) {
	if ( in_array( $view, Texts::VIEWS, true ) ) {
		return swiftling_editor_url( $view . ':' . $id, $lang );
	}
	$args = array( 'page' => 'swiftling-themes-plugins' );
	if ( '' !== $id ) {
		$args['package'] = $id;
		$args['view']    = $view;
	}
	return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php' ) );
}

/**
 * Stores the texts of a package's list and returns everything the editor shows, as
 * swiftling_save_probe() does for a page. A text from code is noted with its translation by the
 * theme's or plugin's translators, where it has one.
 *
 * @param array  $subject Subject of the kind "package", see SwiftLing\Packages\Texts::subject().
 * @param string $lang    Address slug of the language shown.
 * @param array  $args    What the list shows: show, search and page, see Texts::forEditor().
 * @return array{sections: array, maps: array, units: array, list: array}
 */
function swiftling_save_package_list( $subject, $lang, $args ) {
	$list   = Texts::forEditor( $subject, $lang, $args );
	$result = swiftling_save_listed_texts( $subject, $list['texts'] );
	swiftling_note_official_translations( $result['units'], $result['maps'] );
	return $result + array( 'list' => $list );
}

/**
 * Registers the routes of the Themes & plugins screen and of recording.
 *
 * - POST swiftling/v1/record: stores what the check of a recorded page found.
 * - POST swiftling/v1/scan-code: runs one step of a scan of a package's code.
 */
function swiftling_register_package_routes() {
	register_rest_route(
		'swiftling/v1',
		'/record',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_record',
			'permission_callback' => 'swiftling_rest_can_edit',
			'args'                => array(
				'subject'   => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'registry'  => array(
					'type'     => 'string',
					'required' => true,
				),
				'signature' => array(
					'type'     => 'string',
					'required' => true,
				),
				'found'     => array(
					'type'     => 'object',
					'required' => true,
				),
				'control'   => array(
					'type'     => 'array',
					'required' => true,
				),
				'title'     => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);

	register_rest_route(
		'swiftling/v1',
		'/scan-code',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_scan_code',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'package' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static function ( $id ) {
						return null !== Packages::get( (string) $id );
					},
				),
				'offset'  => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'swiftling_register_package_routes' );

/**
 * Stores what the check of a recorded page found: the texts of themes and plugins on it, which
 * package reads which option and uses which text domain, and the texts a visitor saw, so a scan of
 * settings can tell which show on the site. Returns what the page showed, by package.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function swiftling_rest_record( $request ) {
	$subject  = swiftling_subject( (string) $request['subject'] );
	$payload  = (string) $request['registry'];
	$registry = json_decode( $payload, true );
	if ( ! $subject || 'record' !== $subject['kind'] || ! hash_equals( swiftling_probe_signature( $payload ), (string) $request['signature'] ) || ! is_array( $registry ) || ! isset( $registry['subject'] ) || $registry['subject'] !== $subject['key'] ) {
		return new WP_Error( 'swiftling_record_invalid', __( 'The page check could not be verified. Reload the page.', 'swiftling' ), array( 'status' => 400 ) );
	}

	$findings = isset( $registry['found_in'] ) && is_array( $registry['found_in'] ) ? $registry['found_in'] : array();
	Settings::learnOwners( isset( $findings['options'] ) && is_array( $findings['options'] ) ? $findings['options'] : array() );
	Packages::learnDomains( isset( $findings['domains'] ) && is_array( $findings['domains'] ) ? $findings['domains'] : array() );

	$control  = swiftling_clean_control( $request['control'] );
	$verdicts = swiftling_probe_verdicts( is_array( $registry['leaves'] ) ? $registry['leaves'] : array(), swiftling_clean_landings( $request['found'] ), $control );
	$old      = swiftling_get_maps( array( 'options' ) );
	$known    = isset( $old['options'] ) ? $old['options']['units'] : array();
	$owners   = Settings::owners();

	$groups  = array();
	$kept    = array();
	$strings = array();
	$found   = array();
	foreach ( $verdicts['units'] as $unit ) {
		foreach ( isset( $unit['link'] ) ? array( $unit, $unit['link'] ) : array( $unit ) as $entry ) {
			$package = swiftling_recorded_package( $entry, $owners );
			if ( '' === $package ) {
				continue;
			}
			unset( $entry['link'] );
			$groups[ $entry['o'] ][] = $entry;
			$kept[]                  = $entry;
			$found[ $package ]       = isset( $found[ $package ] ) ? $found[ $package ] : array(
				'texts' => 0,
				'new'   => 0,
			);
			++$found[ $package ]['texts'];
			if ( ! empty( $entry['g'] ) && is_array( $entry['g'] ) ) {
				$strings[ $package ][] = $entry['g'] + array( 'k' => $entry['kind'] );
			} elseif ( ! isset( $known[ swiftling_unit_key( $entry ) ] ) ) {
				++$found[ $package ]['new'];
			}
		}
	}

	if ( $groups ) {
		swiftling_note_official_translations( $kept, swiftling_save_unit_maps( '', $groups ) );
	}
	foreach ( $strings as $package => $texts ) {
		$found[ $package ]['new'] += swiftling_count_new_strings( $texts );
		Catalogue::add( $package, $texts, 'seen', time() );
	}

	// What visitors read on the page, so a scan of settings can tell which show on the site.
	$visible = array( wp_strip_all_tags( (string) $request['title'] ) );
	foreach ( $control as $entry ) {
		$visible[] = $entry['t'];
	}
	Settings::see( $visible );

	$new = array();
	foreach ( $found as $package => $counts ) {
		$new[ $package ] = $counts['new'];
	}
	Recorder::notePage( $subject['url'], $new );

	$page = array();
	foreach ( $found as $package => $counts ) {
		$page[] = array(
			'package' => $package,
			'name'    => Packages::get( $package )['name'],
			'texts'   => $counts['texts'],
			'new'     => $counts['new'],
		);
	}
	$recording = Recorder::current();
	$total     = 0;
	foreach ( $recording ? (array) $recording['found'] : array() as $count ) {
		$total += (int) $count;
	}
	return array(
		'page'  => $page,
		'total' => array(
			'texts' => $total,
			'pages' => $recording ? count( (array) $recording['checked'] ) : 0,
		),
	);
}

/**
 * Returns the theme or plugin a text a recording found belongs to, or nothing for a text that is
 * not a theme's or plugin's: the content of a post, a field a builder declares, WordPress's own
 * texts and settings.
 *
 * @param array $entry  Text or address the page check found.
 * @param array $owners Option name => package whose code reads it, see Settings::owners().
 * @return string Package ID, or an empty string.
 */
function swiftling_recorded_package( $entry, $owners ) {
	if ( 0 === strpos( $entry['o'], 'gettext:' ) ) {
		$domain = substr( $entry['o'], 8 );
		return 'default' === $domain ? '' : Packages::ofDomain( $domain );
	}
	if ( 'options' !== $entry['o'] ) {
		return '';
	}
	$option = Settings::optionOf( $entry['p'] );
	if ( Settings::isWordPressOption( $option ) || ! isset( $owners[ $option ] ) || ! Packages::get( $owners[ $option ] ) || null !== \SwiftLing\Builders\Registry::fieldHoldsText( 'options', $entry['p'] ) ) {
		return '';
	}
	return $owners[ $option ];
}

/**
 * Counts the texts from code that no page showed before.
 *
 * @param array $texts Texts: d, t, and where they apply c, p and n.
 * @return int
 */
function swiftling_count_new_strings( $texts ) {
	global $wpdb;
	$keys = array();
	foreach ( $texts as $text ) {
		$keys[ $text['d'] . '|' . swiftling_string_path( $text ) ] = array( $text['d'], swiftling_string_path( $text ) );
	}
	$known = 0;
	foreach ( array_chunk( array_values( $keys ), 100 ) as $chunk ) {
		$where = array();
		$args  = array( swiftling_table( 'catalogue' ) );
		foreach ( $chunk as $key ) {
			$where[] = '(domain = %s AND path = %s)';
			array_push( $args, $key[0], $key[1] );
		}
		$sql = 'SELECT COUNT(*) FROM %i WHERE seen > 0 AND (' . implode( ' OR ', $where ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SwiftLing's own table; $sql has one placeholder per value in $args.
		$known += (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}
	return max( 0, count( $keys ) - $known );
}

/**
 * Runs one step of a scan of a package's code, see CodeScanner::step().
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function swiftling_rest_scan_code( $request ) {
	return CodeScanner::step( (string) $request['package'], (int) $request['offset'] );
}

/**
 * Saves which values of a package's settings are translated, from the scan of its settings, and
 * opens their translation.
 */
function swiftling_save_package_settings() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below, with the package in the action.
	$id = isset( $_POST['package'] ) ? sanitize_text_field( wp_unslash( $_POST['package'] ) ) : '';
	if ( ! current_user_can( 'manage_options' ) || ! Packages::get( $id ) ) {
		wp_die( esc_html__( 'You may not translate this theme or plugin.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_package_settings_' . $id );

	$paths = array();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Paths are compared with the scan's own, see Settings::choose().
	foreach ( isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) ? wp_unslash( $_POST['paths'] ) : array() as $path ) {
		if ( is_string( $path ) ) {
			$paths[] = $path;
		}
	}
	Settings::choose( $id, $paths );

	// Stored now, so a translation shows on the site from the first save, before the editor opens.
	$subject = Texts::subject( 'settings:' . $id );
	if ( $subject ) {
		swiftling_save_listed_texts( $subject, Settings::listTexts( $id ) );
	}
	wp_safe_redirect( swiftling_package_url( $id, 'settings' ) );
	exit;
}
add_action( 'admin_post_swiftling_package_settings', 'swiftling_save_package_settings' );
