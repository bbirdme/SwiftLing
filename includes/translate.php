<?php
/**
 * The translated view.
 *
 * A page is shown in another language when its address asks for one, with ?lang=de, with a
 * language folder such as /de/page/ or with the language's host, as urls.php describes, and its slug can be translated too, see
 * slugs.php. The page is built by its theme and builder as always; SwiftLing only replaces the texts the probe
 * found, at the moment they are read. Visitors never start a translation or a database write: a
 * text without a translation simply shows in the original language.
 *
 * WordPress itself runs in the language too: its locale is the language's, so WordPress, the theme
 * and plugins load their own translations from translate.wordpress.org, dates name their months in
 * the language and a right-to-left language turns the page around. A text the user translated in
 * the editor wins over those translations.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the language the current request shows its page in.
 *
 * Decided from the address alone, so it is known before WordPress loads its translations. A
 * language that is not visible yet opens only for people who can edit content; until WordPress
 * knows who is signed in, such a language is not decided yet.
 *
 * @return string Address slug of a target language, or an empty string for the original and for
 *                requests SwiftLing leaves alone: the admin, Ajax, cron, page checks, and requests of
 *                the REST API but those that read, see swiftling_rest_reads().
 *                A form sent through Ajax from a page in another language is in that language, see
 *                swiftling_sent_form_language().
 */
function swiftling_translation_language() {
	$decided        = &swiftling_decided_language();
	static $running = false;

	if ( null !== $decided ) {
		return $decided;
	}
	// The languages are read from an option whose default asks for the locale, which asks here again.
	if ( $running ) {
		return '';
	}
	$running = true;
	$lang    = swiftling_decide_translation_language();
	$running = false;

	if ( null === $lang ) {
		return '';
	}
	$decided = $lang;
	return $decided;
}

/**
 * Returns the language swiftling_translation_language() decided for this request, kept once it is
 * decided; null until then. A reference, so the tests can start a new request.
 *
 * @return string|null
 */
function &swiftling_decided_language() {
	static $decided = null;
	return $decided;
}

/**
 * Decides the language of the request, see swiftling_translation_language().
 *
 * @return string|null Address slug, an empty string for none, or null while it cannot be decided yet.
 */
function swiftling_decide_translation_language() {
	$lang = swiftling_sent_form_language();
	if ( null === $lang ) {
		return null;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether a page check was asked for; probe.php verifies it.
	if ( '' === $lang && ( is_admin() || wp_doing_ajax() || wp_doing_cron() || isset( $_GET['swiftling_probe'] ) || ( swiftling_is_rest_address() && ! swiftling_rest_reads() ) ) ) {
		return '';
	}

	if ( '' === $lang ) {
		$lang = swiftling_requested_language();
		// The REST API also takes ?lang= in every format of addresses, for a client that builds them.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public, read-only choice of language.
		if ( '' === $lang && swiftling_is_rest_address() && isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ) {
			$lang = sanitize_key( wp_unslash( $_GET['lang'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
	$language = '' === $lang ? null : swiftling_target_language( $lang );
	if ( ! $language ) {
		return '';
	}
	if ( ! $language['visible'] ) {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return null;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
	}
	return $lang;
}

/**
 * Returns the language of a form a visitor sends through admin-ajax.php or the REST API: the
 * language of the page it was sent from. Neither is under a language's address, so without it the
 * plugin would answer in the original: the message after sending, the errors it finds, the email
 * to the visitor. Only the sending of a form of a plugin SwiftLing works with counts, see
 * Builder::sendingActions() and Builder::sendingRoutes(); other Ajax and REST requests stay in
 * the original, as they read and save settings that must not take a translation.
 *
 * The page is the one the form names in _wp_http_referer, which a form with a nonce field sends,
 * or else the one the browser names in the Referer header. A site that tells browsers to send no
 * Referer leaves such forms in the original.
 *
 * @return string|null Address slug, an empty string when the request is no such sending or comes
 *                     from a page in the original, or null before the plugins have loaded, when
 *                     it is not known yet which actions send a form.
 */
function swiftling_sent_form_language() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which action the request asks for; the plugin that receives it verifies it.
	$action = wp_doing_ajax() && isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
	$route  = '' === $action ? swiftling_rest_route() : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether a page check was asked for; probe.php verifies it.
	if ( ( '' === $action && '' === $route ) || isset( $_GET['swiftling_probe'] ) ) {
		return '';
	}
	if ( ! did_action( 'plugins_loaded' ) ) {
		return null;
	}
	$sends = '' !== $action ? \SwiftLing\Builders\Registry::sendsForm( $action ) : \SwiftLing\Builders\Registry::sendsFormTo( $route );
	return $sends ? swiftling_address_language( (string) wp_get_raw_referer() ) : '';
}

/**
 * Returns the route of the REST API the request asks for, from the address alone, before
 * WordPress parses the request: the rest_route parameter, or the path after the API's prefix.
 *
 * @return string The route, as "/contact-form-7/v1/contact-forms/7/feedback", or an empty string
 *                when the request does not go to the REST API.
 */
function swiftling_rest_route() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which route the request asks for.
	if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which route the request asks for.
		return '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' );
	}
	$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) strtok( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?#' ) : '';
	$prefix = '/' . rest_get_url_prefix() . '/';
	$at     = strpos( $path, $prefix );
	return false === $at ? '' : '/' . substr( $path, $at + strlen( $prefix ) );
}

/**
 * Whether a request of the REST API only reads, so it may be answered in a language: GET or HEAD,
 * not asking for what an editor edits (context=edit), not to one of SwiftLing's own routes or those
 * of its add-ons (swiftling/v1, swiftling-pro/v1), which work with the originals. A request that
 * writes stays in the original, so no client saves a translation over the original. Known from the
 * address, before WordPress parses the request.
 *
 * @return bool
 */
function swiftling_rest_reads() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which view of the answer is asked for.
	$context = isset( $_GET['context'] ) && is_string( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : '';
	return in_array( $method, array( 'GET', 'HEAD' ), true ) && 'edit' !== $context && 1 !== preg_match( '~^/swiftling(?:-[a-z0-9]+)*/~', swiftling_rest_route() );
}

/**
 * Whether the request goes to the REST API, which reads in a language only, see swiftling_rest_reads(). Known from the address,
 * before WordPress parses the request.
 *
 * @return bool
 */
function swiftling_is_rest_address() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether the parameter is there.
	if ( isset( $_GET['rest_route'] ) ) {
		return true;
	}
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) strtok( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?#' ) : '';
	return false !== strpos( $path, '/' . rest_get_url_prefix() . '/' );
}

/**
 * Starts translate mode when the address asks for a language the site is translated into.
 *
 * Before the theme loads, on setup_theme: a theme can read its settings while it sets itself up
 * and keep them for the rest of the request, so a translation that starts later never reaches
 * them. Divi 5.14 reads all of its settings (the option et_divi) on after_setup_theme and keeps
 * them in a global, so its footer text showed in the original although it was translated. By
 * setup_theme every plugin is loaded, so the builders SwiftLing knows can tell whether they are
 * active, and WordPress has its rewrite rules and roles, which deciding the language needs.
 */
function swiftling_maybe_start_translation() {
	// The admin and Ajax stay in the original, but for the sending of a form from a page in another language.
	if ( '' !== swiftling_mode() || ( ( is_admin() || wp_doing_ajax() ) && '' === (string) swiftling_sent_form_language() ) ) {
		return;
	}

	$lang = swiftling_translation_language();

	// The original's pages say which language they are in, also when WordPress runs in another.
	add_filter( 'language_attributes', 'swiftling_language_attributes' );
	add_action( 'wp_loaded', 'swiftling_set_text_direction' );

	// A page in the original's own folder is the original, with links that keep the folder.
	$source = swiftling_source_folder();
	if ( '' === $lang && '' !== $source && swiftling_requested_folder() === $source ) {
		swiftling_keep_folder( $source );
		return;
	}
	// A request of the REST API that writes, at a language's address, stays in the original but must
	// still reach the API behind the folder.
	if ( '' === $lang && swiftling_is_rest_address() && 'directory' === swiftling_url_format() && null !== swiftling_target_language( swiftling_requested_folder() ) ) {
		swiftling_keep_folder( swiftling_requested_folder() );
		return;
	}
	// A language that is not visible yet means nothing to visitors, so its addresses are not found.
	if ( '' === $lang ) {
		return;
	}

	swiftling_start( 'translate', $lang );

	if ( 'directory' === swiftling_url_format() ) {
		swiftling_keep_folder( $lang );
	} elseif ( swiftling_is_host_format() ) {
		swiftling_keep_host( $lang );
	}
}
// First on setup_theme, before any other code that runs there can read a setting.
add_action( 'setup_theme', 'swiftling_maybe_start_translation', PHP_INT_MIN );

/**
 * Runs WordPress in the language of the page: the locale WordPress, the theme and plugins load
 * their translations for. Late, so the page's language wins over other choices of a locale. Added
 * when the plugin loads, before WordPress loads its own translations.
 *
 * @param string $locale Locale of the site.
 * @return string
 */
function swiftling_filter_locale( $locale ) {
	// Code that switches the locale on purpose, such as for an email in its reader's language, keeps it.
	// The switcher exists only once WordPress has loaded its own translations, which ask here first.
	if ( isset( $GLOBALS['wp_locale_switcher'] ) && is_locale_switched() ) {
		return $locale;
	}
	$lang = swiftling_translation_language();
	if ( '' === $lang ) {
		return $locale;
	}
	$language = swiftling_target_language( $lang );
	// A custom language runs WordPress in the close language it names.
	return $language ? $language['wp_locale'] : $locale;
}
add_filter( 'locale', 'swiftling_filter_locale', 100 );

/**
 * Puts a translated request in the original for a while, until swiftling_resume_translation():
 * texts are read untranslated and WordPress, the theme and plugins run in the site's own locale.
 * For what a request in another language makes for the site's owner, such as the email a form
 * plugin sends the owner when a visitor sends a form. Pauses nest; each needs its resume.
 *
 * The whole state is put aside and back, as swiftling_collect() does: the values read meanwhile
 * are kept in it untranslated and must not stay there for the rest of the request.
 */
function swiftling_pause_translation() {
	$paused   = &swiftling_paused_translations();
	$state    = &swiftling_state();
	$paused[] = array(
		'state'    => $state,
		'switched' => isset( $GLOBALS['wp_locale_switcher'] ) && switch_to_locale( swiftling_site_locale() ),
	);
	$state    = array_merge(
		$state,
		array(
			'mode'     => '',
			'language' => '',
			'locale'   => '',
			'values'   => array(),
		)
	);
}

/**
 * Translates into a language for a while, until swiftling_resume_translation(): stored values, texts
 * from code and links are read in the language, and WordPress, the theme and plugins run in its
 * locale. For what is written outside the reader's page, such as an email to a customer sent from
 * the admin or by a scheduled task in the language of the customer's order. Switches nest with
 * pauses; each needs its resume.
 *
 * The filters that translate are added where the request has none, as in the admin, and taken off
 * again on resume. A slug that is not one of the site's languages is the original, as a pause.
 *
 * @param string $slug Address slug of a target language.
 */
function swiftling_switch_translation( $slug ) {
	$language = '' !== (string) $slug ? swiftling_target_language( (string) $slug ) : null;
	if ( ! $language ) {
		swiftling_pause_translation();
		return;
	}
	$paused = &swiftling_paused_translations();
	$state  = &swiftling_state();
	$added  = array();
	foreach ( swiftling_source_hooks( 'translate' ) as $hook ) {
		if ( false === has_filter( $hook[0], $hook[1] ) ) {
			add_filter( $hook[0], $hook[1], $hook[2], $hook[3] );
			$added[] = $hook;
		}
	}
	$paused[] = array(
		'state'    => $state,
		'switched' => isset( $GLOBALS['wp_locale_switcher'] ) && switch_to_locale( $language['wp_locale'] ),
		'added'    => $added,
	);
	// Read anew in the language: what the request read so far is another language's.
	$state = array_merge(
		$state,
		array(
			'mode'     => 'translate',
			'language' => (string) $slug,
			'locale'   => $language['locale'],
			'hashes'   => array(),
			'strings'  => array(),
			'partial'  => array(),
			'values'   => array(),
		)
	);
}

/**
 * Puts the request back as it was before swiftling_pause_translation() or
 * swiftling_switch_translation().
 */
function swiftling_resume_translation() {
	$paused = &swiftling_paused_translations();
	if ( ! $paused ) {
		return;
	}
	$last  = array_pop( $paused );
	$state = &swiftling_state();
	$state = $last['state'];
	if ( $last['switched'] ) {
		restore_previous_locale();
	}
	foreach ( $last['added'] ?? array() as $hook ) {
		remove_filter( $hook[0], $hook[1], $hook[2] );
	}
}

/**
 * Returns the pauses and switches swiftling_pause_translation() and swiftling_switch_translation()
 * started, the last one last. A reference, so the tests can start a new request.
 *
 * @return array<int, array{state: array, switched: bool, added?: array}>
 */
function &swiftling_paused_translations() {
	static $paused = array();
	return $paused;
}

/**
 * Returns the locale WordPress runs in without SwiftLing: the site's own, which its pages in the
 * original run in. Worked out as get_locale() does, from the site's language setting, but not
 * from the global $locale it keeps it in: wp-settings.php stores there what get_locale() returns
 * once WordPress has loaded, which in a request in another language is that language's locale.
 *
 * @return string
 */
function swiftling_site_locale() {
	global $wp_local_package;

	$locale = isset( $wp_local_package ) ? (string) $wp_local_package : '';
	if ( defined( 'WPLANG' ) ) {
		$locale = (string) WPLANG;
	}
	if ( is_multisite() ) {
		$setting = wp_installing() ? get_site_option( 'WPLANG' ) : get_option( 'WPLANG' );
		if ( false === $setting && ! wp_installing() ) {
			$setting = get_site_option( 'WPLANG' );
		}
	} else {
		$setting = get_option( 'WPLANG' );
	}
	if ( false !== $setting ) {
		$locale = (string) $setting;
	}
	$locale = '' === $locale ? 'en_US' : $locale;

	// Other code may choose the site's locale too; only SwiftLing's choice is left out, and the
	// locale switched to for a while, as for an email, which WordPress's switcher answers with.
	$filtered = remove_filter( 'locale', 'swiftling_filter_locale', 100 );
	$switcher = isset( $GLOBALS['wp_locale_switcher'] ) ? array( $GLOBALS['wp_locale_switcher'], 'filter_locale' ) : null;
	$priority = $switcher ? has_filter( 'locale', $switcher ) : false;
	if ( false !== $priority ) {
		remove_filter( 'locale', $switcher, $priority );
	}
	/** This filter is documented in wp-includes/l10n.php */
	$locale = (string) apply_filters( 'locale', $locale ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as get_locale() applies it.
	if ( false !== $priority ) {
		add_filter( 'locale', $switcher, $priority );
	}
	if ( $filtered ) {
		add_filter( 'locale', 'swiftling_filter_locale', 100 );
	}
	return $locale;
}

/**
 * Returns the language the page is shown in: a target in translate mode, the original otherwise.
 *
 * @return array Language as swiftling_languages() gives it.
 */
function swiftling_page_language() {
	$state    = &swiftling_state();
	$language = 'translate' === swiftling_mode() ? swiftling_target_language( $state['language'] ) : null;
	return $language ? $language : swiftling_languages()['source'];
}

/**
 * Sets the lang attribute of the page to the language it is shown in: the code of a custom
 * language, not the close language WordPress runs in for it.
 *
 * @param string $output Attributes of the html element.
 * @return string
 */
function swiftling_language_attributes( $output ) {
	$tag = str_replace( '_', '-', swiftling_page_language()['locale'] );
	return preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $tag ) . '"', $output );
}

/**
 * Sets the writing direction of a page in a custom language, which its close language does not
 * decide: Persian on the texts of English is still right to left. Other languages keep the
 * direction of their WordPress translation.
 */
function swiftling_set_text_direction() {
	global $wp_locale;
	$language = swiftling_page_language();
	if ( null !== $language['rtl'] && $wp_locale instanceof WP_Locale ) {
		$wp_locale->text_direction = $language['rtl'] ? 'rtl' : 'ltr';
	}
}
