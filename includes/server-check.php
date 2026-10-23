<?php
/**
 * Checking a page on the server, without a browser.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seconds a token of a server's page check stays good.
 */
const SWIFTLING_CHECK_TOKEN_LIFE = 300;

/**
 * Checks a page on the server, as the translation editor checks it in the browser: asks the site
 * for the page with a marker on every value and for the page as visitors see it, through the
 * loopback as WordPress's Site Health does, reads both as assets/js/page-check.js does (see
 * SwiftLing\PageCheck\Reader) and stores what it found, see swiftling_store_page_check(). For
 * translating without a browser: in the background, on publishing, through the REST API or WP-CLI.
 *
 * The pages render for a user who may translate the subject, so drafts and private pages render
 * as for them. Scripts do not run: a text a script builds after the page loads is found only by
 * the check in the browser.
 *
 * @param string   $key     Subject key, e.g. "post:12", see swiftling_subject().
 * @param int|null $user_id The user the pages render for; the current user when null.
 * @return array{result: array}|WP_Error What was stored, see swiftling_store_page_check().
 */
function swiftling_server_check( $key, $user_id = null ) {
	require_once SWIFTLING_DIR . 'includes/probe-results.php';
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	$subject = swiftling_subject( (string) $key );
	if ( ! $subject || ! $subject['probe'] || '' === (string) $subject['url'] ) {
		return new WP_Error( 'swiftling_check_subject', __( 'This has no page to check.', 'swiftling' ), array( 'status' => 404 ) );
	}

	$previous = get_current_user_id();
	wp_set_current_user( $user_id );
	$can = swiftling_can_translate_subject( $subject );
	wp_set_current_user( $previous );
	if ( ! $can ) {
		return new WP_Error( 'swiftling_check_rights', __( 'This user may not translate this.', 'swiftling' ), array( 'status' => 403 ) );
	}

	$pages = array();
	foreach ( array( 'marked', 'control' ) as $variant ) {
		// The example of the "Page not found" kind of page is not found.
		$page = swiftling_fetch_check_page( swiftling_check_page_url( $subject, $variant, $user_id ), swiftling_not_found_url() === $subject['url'] ? 404 : 200 );
		if ( is_wp_error( $page ) ) {
			swiftling_log(
				'warning',
				'check',
				/* translators: %s: what went wrong. */
				sprintf( __( 'The server could not check a page: %s', 'swiftling' ), $page->get_error_message() ),
				array( 'object' => $subject['key'] )
			);
			return $page;
		}
		$pages[ $variant ] = $page;
	}

	$reader = new \SwiftLing\PageCheck\Reader();
	$marked = $reader->marked( $pages['marked'] );
	if ( null === $marked ) {
		swiftling_log( 'warning', 'check', __( 'The server checked a page, but the page did not list its values. A theme or plugin may have stopped it before its end.', 'swiftling' ), array( 'object' => $subject['key'] ) );
		return new WP_Error( 'swiftling_check_list', __( 'The checked page did not list its values.', 'swiftling' ), array( 'status' => 502 ) );
	}
	$control = $reader->control( $pages['control'] );
	return swiftling_store_page_check( $subject, $marked['registry'], $marked['signature'], $marked['found'], $control['texts'], $marked['scoped'] );
}

/**
 * Returns the address of one page of a server's page check: the probe's parameters, with a token
 * instead of the editor's nonce.
 *
 * @param array  $subject Subject, see swiftling_subject().
 * @param string $variant marked or control.
 * @param int    $user_id User the page renders for.
 * @return string
 */
function swiftling_check_page_url( $subject, $variant, $user_id ) {
	return add_query_arg(
		array(
			'swiftling_probe'   => $variant,
			'swiftling_subject' => rawurlencode( $subject['key'] ),
			'swiftling_token'   => swiftling_check_token( $subject['key'], $variant, $user_id ),
		),
		$subject['url']
	);
}

/**
 * Asks the site for a page of a check, through the loopback.
 *
 * @param string $url    Address.
 * @param int    $status The status the page answers with: 200, or 404 for one that is not found.
 * @return string|WP_Error The page.
 */
function swiftling_fetch_check_page( $url, $status = 200 ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 45,
			'redirection' => 3,
			// As WordPress asks itself for its own pages: a local certificate need not be signed.
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $status !== $code ) {
		/* translators: %d: HTTP status code. */
		return new WP_Error( 'swiftling_check_status', sprintf( __( 'the site answered with %d.', 'swiftling' ), $code ), array( 'status' => 502 ) );
	}
	return (string) wp_remote_retrieve_body( $response );
}

/**
 * Returns a token that lets the server's own request check one page of one subject, for a few
 * minutes, rendered for one user: their ID, when it runs out, and a signature of both with the
 * subject and variant.
 *
 * @param string $key     Subject key.
 * @param string $variant marked or control.
 * @param int    $user_id User the page renders for.
 * @return string
 */
function swiftling_check_token( $key, $variant, $user_id ) {
	$expires = time() + SWIFTLING_CHECK_TOKEN_LIFE;
	return (int) $user_id . '.' . $expires . '.' . swiftling_check_token_signature( $key, $variant, (int) $user_id, $expires );
}

/**
 * Returns the user a token of a server's page check renders for, or null when the token is not
 * one SwiftLing signed for this subject and variant, or has run out.
 *
 * @param string $token   Token.
 * @param string $key     Subject key.
 * @param string $variant marked or control.
 * @return int|null
 */
function swiftling_verify_check_token( $token, $key, $variant ) {
	$parts = explode( '.', (string) $token );
	if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || (int) $parts[1] < time() ) {
		return null;
	}
	return hash_equals( swiftling_check_token_signature( (string) $key, (string) $variant, (int) $parts[0], (int) $parts[1] ), $parts[2] ) ? (int) $parts[0] : null;
}

/**
 * Signs a token of a server's page check.
 *
 * @param string $key     Subject key.
 * @param string $variant marked or control.
 * @param int    $user_id User.
 * @param int    $expires When it runs out.
 * @return string
 */
function swiftling_check_token_signature( $key, $variant, $user_id, $expires ) {
	return hash_hmac( 'sha256', $key . '|' . $variant . '|' . $user_id . '|' . $expires, wp_salt( 'auth' ) . 'swiftling-check' );
}
