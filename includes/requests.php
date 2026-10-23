<?php
/**
 * Requests for support of a plugin, theme or form SwiftLing does not translate yet. Translations
 * offers them under its list of screens, see Menu::rail(); the request is written on its own screen,
 * see admin/views/request.php, and sent to swiftling.pro, where it becomes a ticket of the support
 * desk.
 *
 * Nothing leaves the site before Send is pressed, and the screen says what is sent: what the person
 * wrote, their email and, when ticked, the report for support of the Logs screen, which holds no
 * content, keys or passwords. When swiftling.pro cannot be reached, the request waits on the screen,
 * ready to email to support@swiftling.pro.
 *
 * What is sent, as JSON: kind (plugin, theme, form, builder or other), name and version of what it
 * is about, slug (its folder, or its address on wordpress.org when written), what (what stays
 * untranslated), page (an address where it shows, or ''), email, report (or ''), swiftling (the
 * version) and locale (the person's language, to answer in).
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where requests are sent. The swiftling_request_url filter changes it, as for a test receiver.
 */
const SWIFTLING_REQUEST_URL = 'https://swiftling.pro/wp-json/swiftling/v1/requests';

/**
 * Where a request that could not be sent can be emailed.
 */
const SWIFTLING_REQUEST_EMAIL = 'support@swiftling.pro';

/**
 * Requests one person may send in an hour.
 */
const SWIFTLING_REQUEST_LIMIT = 5;

/**
 * Returns what a request can be about.
 *
 * @return array<string, string> Names, keyed by kind.
 */
function swiftling_request_kinds() {
	return array(
		'plugin'  => __( 'Plugin', 'swiftling' ),
		'theme'   => __( 'Theme', 'swiftling' ),
		'form'    => __( 'Form', 'swiftling' ),
		'builder' => __( 'Page builder', 'swiftling' ),
		'other'   => __( 'Something else', 'swiftling' ),
	);
}

/**
 * Returns the theme and plugins of the site a request can name: the active theme and its parent,
 * and the active plugins, also those active on the whole network; never SwiftLing itself.
 *
 * @return array<string, array{label: string, name: string, version: string, slug: string}>
 *         Keyed by "theme:<folder>" or "plugin:<file>".
 */
function swiftling_request_choices() {
	$choices = array();
	$theme   = wp_get_theme();
	foreach ( array_filter( array( $theme, $theme->parent() ) ) as $one ) {
		$choices[ 'theme:' . $one->get_stylesheet() ] = array(
			'label'   => trim( $one->get( 'Name' ) . ' ' . $one->get( 'Version' ) ),
			'name'    => (string) $one->get( 'Name' ),
			'version' => (string) $one->get( 'Version' ),
			'slug'    => $one->get_stylesheet(),
		);
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all    = get_plugins();
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	foreach ( array_unique( $active ) as $file ) {
		if ( ! isset( $all[ $file ] ) || 0 === strpos( dirname( $file ), 'swiftling' ) ) {
			continue;
		}
		$choices[ 'plugin:' . $file ] = array(
			'label'   => trim( $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'] ),
			'name'    => (string) $all[ $file ]['Name'],
			'version' => (string) $all[ $file ]['Version'],
			'slug'    => '.' !== dirname( $file ) ? dirname( $file ) : basename( $file, '.php' ),
		);
	}
	uasort(
		$choices,
		static function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		}
	);
	return $choices;
}

/**
 * Returns the request a form asks to send, or what is missing from it.
 *
 * @param array $fields Fields of the form, unslashed: kind, which, name, what, page, email, report.
 * @return array|WP_Error The request, as it is sent; see the top of this file.
 */
function swiftling_request_payload( $fields ) {
	$kind    = isset( $fields['kind'] ) ? sanitize_key( $fields['kind'] ) : '';
	$which   = isset( $fields['which'] ) ? sanitize_text_field( $fields['which'] ) : '';
	$choices = swiftling_request_choices();
	$what    = isset( $fields['what'] ) ? trim( sanitize_textarea_field( $fields['what'] ) ) : '';
	$email   = isset( $fields['email'] ) ? sanitize_email( $fields['email'] ) : '';

	if ( isset( $choices[ $which ] ) ) {
		$name    = $choices[ $which ]['name'];
		$version = $choices[ $which ]['version'];
		$slug    = $choices[ $which ]['slug'];
	} else {
		$name    = isset( $fields['name'] ) ? trim( sanitize_text_field( $fields['name'] ) ) : '';
		$version = '';
		$slug    = '';
	}
	if ( '' === $name || '' === $what || ! is_email( $email ) ) {
		return new WP_Error( 'swiftling_request_incomplete', __( 'Name what it is about, what stays untranslated and an email to answer.', 'swiftling' ) );
	}

	return array(
		'kind'      => isset( swiftling_request_kinds()[ $kind ] ) ? $kind : 'other',
		'name'      => mb_substr( $name, 0, 200 ),
		'version'   => $version,
		'slug'      => $slug,
		'what'      => mb_substr( $what, 0, 4000 ),
		'page'      => isset( $fields['page'] ) ? esc_url_raw( trim( $fields['page'] ), array( 'http', 'https' ) ) : '',
		'email'     => $email,
		'report'    => ! empty( $fields['report'] ) ? swiftling_log_report() : '',
		'swiftling' => SWIFTLING_VERSION,
		'locale'    => get_user_locale(),
	);
}

/**
 * Returns a request as plain text, to email it when it could not be sent.
 *
 * @param array $request A request from swiftling_request_payload().
 */
function swiftling_request_text( $request ) {
	$kinds = swiftling_request_kinds();
	$lines = array(
		trim( $kinds[ $request['kind'] ] . ': ' . $request['name'] . ' ' . $request['version'] ),
		'',
		$request['what'],
	);
	if ( '' !== $request['page'] ) {
		array_push( $lines, '', $request['page'] );
	}
	if ( '' !== $request['report'] ) {
		array_push( $lines, '', $request['report'] );
	}
	return implode( "\n", $lines );
}

/**
 * Sends a request to swiftling.pro.
 *
 * @param array $request A request from swiftling_request_payload().
 * @return true|WP_Error
 */
function swiftling_send_request( $request ) {
	/**
	 * Filters where requests for support are sent.
	 *
	 * @param string $url Address of the receiver.
	 */
	$url      = (string) apply_filters( 'swiftling_request_url', SWIFTLING_REQUEST_URL );
	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $request ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code > 299 ) {
		/* translators: %d: HTTP status, e.g. 404. */
		return new WP_Error( 'swiftling_request_refused', sprintf( __( 'swiftling.pro answered with status %d.', 'swiftling' ), $code ) );
	}
	return true;
}

/**
 * Sends the request of the request screen. An incomplete request, or one that cannot be sent, waits
 * on the screen with what was written; one person sends at most SWIFTLING_REQUEST_LIMIT an hour.
 */
function swiftling_handle_request() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not send requests from this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_send_request' );

	$user   = get_current_user_id();
	$fields = array();
	foreach ( array( 'kind', 'which', 'name', 'what', 'page', 'email', 'report' ) as $field ) {
		$fields[ $field ] = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in swiftling_request_payload(), and as the screen shows it again.
	}
	$screen = admin_url( 'admin.php?page=swiftling-request' );
	$draft  = 'swiftling_request_draft_' . $user;
	$sent   = 'swiftling_requests_sent_' . $user;

	$request = swiftling_request_payload( $fields );
	if ( is_wp_error( $request ) ) {
		set_transient( $draft, $fields, HOUR_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'swiftling_notice', 'request_incomplete', $screen ) );
		exit;
	}
	$count = (int) get_transient( $sent );
	if ( $count >= SWIFTLING_REQUEST_LIMIT ) {
		set_transient( $draft, $fields, HOUR_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'swiftling_notice', 'request_limit', $screen ) );
		exit;
	}

	$result = swiftling_send_request( $request );
	if ( is_wp_error( $result ) ) {
		set_transient( $draft, $fields + array( 'text' => swiftling_request_text( $request ) ), HOUR_IN_SECONDS );
		swiftling_log(
			'error',
			'support',
			/* translators: %s: name of a plugin or theme. */
			sprintf( __( 'The request for support of %s could not be sent.', 'swiftling' ), $request['name'] ),
			array( 'detail' => $result->get_error_message() )
		);
		wp_safe_redirect( add_query_arg( 'swiftling_notice', 'request_failed', $screen ) );
		exit;
	}

	set_transient( $sent, $count + 1, HOUR_IN_SECONDS );
	delete_transient( $draft );
	/* translators: %s: name of a plugin or theme. */
	swiftling_log( 'event', 'support', sprintf( __( 'A request for support of %s was sent.', 'swiftling' ), $request['name'] ) );
	wp_safe_redirect(
		add_query_arg(
			array(
				'swiftling_notice' => 'request_sent',
				'swiftling_which'  => rawurlencode( $request['name'] ),
			),
			$screen
		)
	);
	exit;
}
add_action( 'admin_post_swiftling_send_request', 'swiftling_handle_request' );
