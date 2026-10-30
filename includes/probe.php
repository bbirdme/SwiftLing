<?php
/**
 * The probe: the page rendered once with a marker on every value that looks like text.
 *
 * The translation editor loads the page in a hidden frame, in the browser of the signed-in
 * editor, and reads where each marker ended up. Two variants exist:
 *
 * - marked: values carry markers; the list of marked values is printed into the page, signed;
 * - control: the page as visitors see it, rendered under the same conditions.
 *
 * A probe request must never leave a trace. Every database write is held in a transaction that
 * is rolled back at the end, meta and option writes are refused outright so a persistent object
 * cache never sees them, caching is switched off, the page is not indexed, and the browser may
 * not send anything to other sites.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the address of a probe for a subject of the editor: a post, or the example page of a
 * template or kind of page.
 *
 * @param array  $subject Subject, see swiftling_subject().
 * @param string $variant marked or control.
 * @return string
 */
function swiftling_probe_url( $subject, $variant ) {
	$args = array(
		'swiftling_probe'   => $variant,
		// add_query_arg() writes a value as it is: a subject such as "record:/quiz/?a=1&b=2" would end at its "&".
		'swiftling_subject' => rawurlencode( $subject['key'] ),
		'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_' . $subject['key'] ),
	);
	return add_query_arg( $args, $subject['url'] );
}

/**
 * Starts a probe when the request asks for one and the editor may translate the subject.
 */
function swiftling_maybe_start_probe() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified right below.
	if ( ! isset( $_GET['swiftling_probe'] ) ) {
		return;
	}
	$variant = sanitize_key( wp_unslash( $_GET['swiftling_probe'] ) );
	$key     = isset( $_GET['swiftling_subject'] ) ? sanitize_text_field( wp_unslash( $_GET['swiftling_subject'] ) ) : '';
	$nonce   = isset( $_GET['swiftling_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['swiftling_nonce'] ) ) : '';
	// phpcs:enable

	if ( ! in_array( $variant, array( 'marked', 'control' ), true ) ) {
		return;
	}

	// A check the server asks for itself carries a signed token instead of the editor's nonce,
	// naming the user the page renders for, see swiftling_check_token().
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is verified right below.
	$token = isset( $_GET['swiftling_token'] ) ? sanitize_text_field( wp_unslash( $_GET['swiftling_token'] ) ) : '';
	if ( '' !== $token ) {
		$user = '' !== $key ? swiftling_verify_check_token( $token, $key, $variant ) : null;
		if ( null === $user ) {
			swiftling_refuse_probe();
		}
		wp_set_current_user( $user );
	} elseif ( '' === $key || ! wp_verify_nonce( $nonce, 'swiftling_probe_' . $key ) ) {
		swiftling_refuse_probe();
	}

	// A plugin registers its post types and taxonomies on init, later than this: WooCommerce its
	// products and their categories at priority 5. A subject not known yet is looked up again once
	// every type is registered, before the page renders, see swiftling_check_late_probe(). The
	// check starts now all the same, so nothing read in between is missed.
	$subject = swiftling_subject( $key );
	if ( $subject ) {
		swiftling_check_probe_subject( $subject );
	} else {
		add_action( 'wp_loaded', 'swiftling_check_late_probe', 0 );
	}

	swiftling_protect_probe();

	$state = &swiftling_state();
	if ( $subject ) {
		$state['probe']   = 'post' === $subject['kind'] ? $subject['post']->ID : -1;
		$state['subject'] = $subject['key'];
	} else {
		// Until the subject is known: a post by the number in its key, anything else as a template.
		$state['probe']   = 0 === strpos( $key, 'post:' ) ? (int) substr( $key, 5 ) : -1;
		$state['subject'] = $key;
	}

	// Mark where the content of posts starts and ends on the page. For a post, the editor tells
	// its text from the header, the footer and the rest of the site around it; for a template,
	// it tells the template's text from the content of the posts the page shows.
	add_filter( 'the_content', 'swiftling_mark_content_area', PHP_INT_MAX );

	if ( 'marked' === $variant ) {
		swiftling_start( 'probe' );
		add_action( 'wp_footer', 'swiftling_print_probe_registry', PHP_INT_MAX );
	} else {
		swiftling_start( 'control' );
	}
}
add_action( 'init', 'swiftling_maybe_start_probe', 0 );

/**
 * Looks up the subject of a page check that was not known when the check started, once every
 * post type and taxonomy is registered, and refuses the check when it still is not, or when the
 * user may not translate it.
 */
function swiftling_check_late_probe() {
	// The kinds of pages were listed when the check started, before the types of plugins were
	// registered, as WooCommerce's products: listed again, a kind of page of theirs is found.
	$store = &swiftling_memo_store();
	unset( $store['site_views'] );

	$state   = &swiftling_state();
	$subject = swiftling_subject( $state['subject'] );
	if ( ! $subject ) {
		swiftling_refuse_probe();
	}
	swiftling_check_probe_subject( $subject );
	$state['probe']   = 'post' === $subject['kind'] ? $subject['post']->ID : -1;
	$state['subject'] = $subject['key'];
}

/**
 * Refuses a page check of a subject that has no page check, or that the user may not translate.
 *
 * @param array $subject Subject, see swiftling_subject().
 */
function swiftling_check_probe_subject( $subject ) {
	if ( ! $subject['probe'] || ! swiftling_can_translate_subject( $subject ) ) {
		swiftling_refuse_probe();
	}
}

/**
 * Refuses a page check: the editor that asked for it is out of date, or was never allowed to.
 */
function swiftling_refuse_probe() {
	wp_die( esc_html__( 'This page check has expired. Reload the translation editor.', 'swiftling' ), '', array( 'response' => 403 ) );
}

/**
 * Whether the request is a page check, which must leave no trace.
 *
 * @return bool
 */
function swiftling_is_probe() {
	$state = &swiftling_state();
	return 0 !== $state['probe'];
}

/**
 * Makes sure a probe request leaves no trace and sends nothing anywhere.
 */
function swiftling_protect_probe() {
	global $wpdb;

	// Page and object caching plugins skip requests that define these.
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Names agreed between caching plugins.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
		define( 'DONOTCACHEOBJECT', true );
	}
	if ( ! defined( 'DONOTCACHEDB' ) ) {
		define( 'DONOTCACHEDB', true );
	}
	// phpcs:enable

	show_admin_bar( false );
	remove_action( 'init', 'wp_cron' );
	add_action( 'send_headers', 'swiftling_probe_headers' );

	// Hold every write and roll it back once the page is done.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control, not a data query.
	$wpdb->query( 'START TRANSACTION' );
	add_action( 'shutdown', 'swiftling_end_probe', PHP_INT_MAX );

	// Refuse meta and option writes outright, so a persistent object cache never sees them either.
	foreach ( array( 'post', 'term', 'user', 'comment' ) as $type ) {
		add_filter( "add_{$type}_metadata", 'swiftling_refuse_write', 1 );
		add_filter( "update_{$type}_metadata", 'swiftling_refuse_write', 1 );
		add_filter( "delete_{$type}_metadata", 'swiftling_refuse_write', 1 );
	}
	add_filter( 'pre_update_option', 'swiftling_keep_option', 1, 3 );
}

/**
 * Wraps the content of the checked post in two HTML comments; for a template, the content of every
 * post the page shows.
 *
 * A widget can ask for the post's excerpt while the content renders, and WordPress builds the
 * excerpt by running the_content once more, inside the first run; Royal Addons' sharing buttons
 * do. Only an outer run prints the content, so only an outer run is wrapped. Every outer run is,
 * because an excerpt built outside the content loses its comments with its other tags.
 *
 * @param string $content Rendered content.
 * @return string
 */
function swiftling_mark_content_area( $content ) {
	global $wp_current_filter;
	$state = &swiftling_state();

	$nested = count( array_keys( (array) $wp_current_filter, 'the_content', true ) ) > 1;
	if ( $nested || ( $state['probe'] > 0 && get_the_ID() !== $state['probe'] ) ) {
		return $content;
	}
	return '<!--swiftling:content-->' . $content . '<!--/swiftling:content-->';
}

/**
 * Sends the headers of a probe response.
 */
function swiftling_probe_headers() {
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	// Scripts may run, so text that scripts build is seen, but nothing may leave the site:
	// no requests, tracking pixels, embedded pages or form submissions.
	header( "Content-Security-Policy: connect-src 'self'; img-src 'self' data: blob:; media-src 'self' data: blob:; frame-src 'none'; form-action 'none'; frame-ancestors 'self'" );
}

/**
 * Rolls back whatever the probe request wrote.
 */
function swiftling_end_probe() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control, not a data query.
	$wpdb->query( 'ROLLBACK' );
}

/**
 * Refuses a meta write during a probe.
 *
 * @return false
 */
function swiftling_refuse_write() {
	return false;
}

/**
 * Keeps an option unchanged during a probe.
 *
 * @param mixed  $value     New value.
 * @param string $option    Option name.
 * @param mixed  $old_value Current value.
 * @return mixed
 */
function swiftling_keep_option( $value, $option, $old_value ) {
	return $old_value;
}

/**
 * Prints the values the probe marked, so the editor can match markers to values. The list is
 * signed: the editor sends it back to the server, which accepts only a list it printed itself.
 */
function swiftling_print_probe_registry() {
	global $_wp_current_template_id;
	$state = &swiftling_state();
	$list  = array(
		'subject'  => $state['subject'],
		// The block template WordPress made the page from, so the editor of a template can
		// tell that its example page really shows it.
		'template' => (string) $_wp_current_template_id,
		'leaves'   => (object) $state['registry'],
	);
	// A recorded page also tells which theme or plugin read which option and used which text domain.
	if ( 0 === strpos( $state['subject'], 'record:' ) ) {
		$list['found_in'] = \SwiftLing\Packages\Recorder::findings();
	}
	// The page of a list of terms also tells which theme or plugin read each field of its terms.
	if ( 0 === strpos( $state['subject'], 'terms:' ) ) {
		$list['term_fields'] = (object) swiftling_term_meta_callers();
	}
	$payload = wp_json_encode( $list, JSON_HEX_TAG | JSON_HEX_AMP );

	printf(
		'<script type="application/json" id="swiftling-probe" data-signature="%s">%s</script>',
		esc_attr( swiftling_probe_signature( $payload ) ),
		$payload // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & escaped, inside a JSON script element.
	);
}

/**
 * Signs the list of marked values.
 *
 * @param string $payload JSON list.
 * @return string
 */
function swiftling_probe_signature( $payload ) {
	return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'swiftling-probe' );
}
