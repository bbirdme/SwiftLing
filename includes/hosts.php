<?php
/**
 * A subdomain or an own domain per language.
 *
 * Two formats of urls.php tell the language by the host of the address instead of its path:
 *
 * - subdomain: de.example.com/page/, the language's address slug in front of the site's host, a
 *   www. at its start left out;
 * - domain: example.de/page/, a domain per language, given on the Addresses screen and kept by the
 *   language's locale, so a changed address slug keeps its domain.
 *
 * Every host shows the same WordPress: the site's owner adds the hosts to this site at their host,
 * and SwiftLing tells the language from the host of the request. A check opens each host the way a
 * visitor would and knows this site by a signature only it can give (swiftling_ping_signature()), so
 * the format can only be switched on once every host leads here, and a language whose host has not
 * led here in a check is not on the site.
 *
 * On a language's host every address WordPress builds for visitors is on that host too: pages, and
 * the theme's fonts, files of plugins, uploads, the REST API and admin-ajax.php, which browsers
 * refuse from another host. The dashboard and signing in stay at the site address, where the
 * user's login is.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the site tells the language by the host of the address.
 *
 * @return bool
 */
function swiftling_is_host_format() {
	return in_array( swiftling_url_format(), array( 'subdomain', 'domain' ), true );
}

/**
 * Returns the host of the site address, in lower case.
 *
 * @return string For example "example.com".
 */
function swiftling_home_host() {
	return strtolower( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) );
}

/**
 * Returns the host a language's subdomain goes in front of: the site's, without a www. at its start.
 *
 * @return string For example "example.com" for www.example.com.
 */
function swiftling_subdomain_base() {
	return (string) preg_replace( '/^www\./', '', swiftling_home_host() );
}

/**
 * Returns the host of a target language in a format, from its saved settings. See
 * swiftling_languages(), which gives every target its host.
 *
 * @param string $format  Saved format.
 * @param string $slug    Address slug of the language.
 * @param string $locale  Locale of the language.
 * @param array  $domains Saved domains, locale => domain.
 * @return string Host, or an empty string outside the host formats and for a language without a domain.
 */
function swiftling_target_host( $format, $slug, $locale, $domains ) {
	if ( 'subdomain' === $format ) {
		return strtolower( $slug ) . '.' . swiftling_subdomain_base();
	}
	if ( 'domain' === $format && ! empty( $domains[ $locale ] ) && is_string( $domains[ $locale ] ) ) {
		return strtolower( $domains[ $locale ] );
	}
	return '';
}

/**
 * Returns the host of a language.
 *
 * @param string $slug Address slug of a target language, or an empty string for the original.
 * @return string Host. The original's is the site's in a host format; empty otherwise, and for a
 *                target without a host.
 */
function swiftling_language_host( $slug ) {
	if ( '' === $slug ) {
		return swiftling_is_host_format() ? swiftling_home_host() : '';
	}
	$targets = swiftling_languages()['targets'];
	return isset( $targets[ $slug ] ) ? (string) $targets[ $slug ]['host'] : '';
}

/**
 * Returns the hosts of the site: its own and every language's, whether or not it led here yet.
 *
 * @return string[] Hosts in lower case.
 */
function swiftling_own_hosts() {
	$hosts = array( swiftling_home_host(), strtolower( (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_HOST ) ) );
	foreach ( swiftling_languages()['targets'] as $target ) {
		$hosts[] = (string) $target['host'];
	}
	return array_values( array_unique( array_filter( $hosts ) ) );
}

/**
 * Returns the host of the current request, in lower case and without its port.
 *
 * @return string
 */
function swiftling_request_host() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	return (string) preg_replace( '/:\d+$/', '', $host );
}

/**
 * Returns the language whose host the request is on. Only a language whose host led here in a
 * check counts.
 *
 * @return string Address slug, or an empty string.
 */
function swiftling_host_language() {
	return swiftling_language_of_host( swiftling_request_host() );
}

/**
 * Returns the language whose host a host is. Only a language whose host led here in a check counts.
 *
 * @param string $host Host, in lower case and without its port.
 * @return string Address slug, or an empty string.
 */
function swiftling_language_of_host( $host ) {
	if ( '' === $host ) {
		return '';
	}
	foreach ( swiftling_languages()['targets'] as $slug => $target ) {
		if ( $target['reachable'] && $host === $target['host'] ) {
			return (string) $slug;
		}
	}
	return '';
}

/**
 * Returns the host of the request when it is a language's, whether or not that language is on the
 * site now.
 *
 * @return string Address slug of the language, or an empty string.
 */
function swiftling_requested_host_language() {
	$host = swiftling_request_host();
	foreach ( swiftling_languages()['targets'] as $slug => $target ) {
		if ( '' !== $host && $host === $target['host'] ) {
			return (string) $slug;
		}
	}
	return '';
}

/**
 * Puts another host in an address of this site, on any of its hosts. Other sites' addresses and
 * addresses without a host come back unchanged.
 *
 * @param string $url  Address.
 * @param string $host Host to put in.
 * @return string
 */
function swiftling_swap_host( $url, $host ) {
	if ( ! is_string( $url ) || '' === $host || ! preg_match( '#^((?:[a-z][a-z0-9+.\-]*:)?//)([^/?\#:@]+)(.*)$#is', $url, $parts ) ) {
		return $url;
	}
	if ( ! in_array( strtolower( $parts[2] ), swiftling_own_hosts(), true ) ) {
		return $url;
	}
	return $parts[1] . $host . $parts[3];
}

/* The request on a language's host. */

/**
 * Returns the language whose host this request keeps, see swiftling_keep_host().
 *
 * @return string Address slug, or an empty string.
 */
function &swiftling_kept_host() {
	static $slug = '';
	return $slug;
}

/**
 * Makes the addresses WordPress builds for visitors carry the host of a language for the rest of
 * the request: links, so they stay in the language, and files and calls, which browsers refuse
 * from another host. Fonts are the reason: a theme's @font-face from the site's host does not load
 * on de.example.com.
 *
 * @param string $slug Address slug of the language, e.g. "de".
 */
function swiftling_keep_host( $slug ) {
	$kept = &swiftling_kept_host();
	$kept = $slug;
	// Early, so filters after it see the address on the language's host. See swiftling_filter_home_url().
	add_filter( 'home_url', 'swiftling_filter_home_url', 1, 4 );
	foreach ( array( 'content_url', 'plugins_url', 'includes_url', 'rest_url', 'script_loader_src', 'style_loader_src' ) as $hook ) {
		add_filter( $hook, 'swiftling_to_kept_host', 1 );
	}
	add_filter( 'upload_dir', 'swiftling_uploads_on_kept_host', 1 );
	add_filter( 'admin_url', 'swiftling_ajax_on_kept_host', 1, 2 );
}

/**
 * Puts the kept language's host in an address of this site.
 *
 * @param string $url Address.
 * @return string
 */
function swiftling_to_kept_host( $url ) {
	$slug = swiftling_kept_host();
	return '' === $slug ? $url : swiftling_swap_host( $url, swiftling_language_host( $slug ) );
}

/**
 * Puts the stylesheet Yoast SEO names in its sitemaps on the kept language's host. Yoast SEO sets
 * it while it loads, before the request knows its language, and browsers refuse a stylesheet from
 * another host, so a language's sitemap showed an empty page.
 *
 * @param string $stylesheet The xml-stylesheet declaration.
 * @return string
 */
function swiftling_sitemap_stylesheet_on_kept_host( $stylesheet ) {
	if ( '' === swiftling_kept_host() || ! is_string( $stylesheet ) ) {
		return $stylesheet;
	}
	return (string) preg_replace_callback(
		'~href="([^"]*)"~',
		static function ( $href ) {
			return 'href="' . swiftling_to_kept_host( $href[1] ) . '"';
		},
		$stylesheet
	);
}
add_filter( 'wpseo_stylesheet_url', 'swiftling_sitemap_stylesheet_on_kept_host' );

/**
 * Puts the uploads on the kept language's host, so images and fonts in them load from it.
 *
 * @param array $uploads What wp_upload_dir() returns.
 * @return array
 */
function swiftling_uploads_on_kept_host( $uploads ) {
	foreach ( array( 'url', 'baseurl' ) as $key ) {
		if ( isset( $uploads[ $key ] ) ) {
			$uploads[ $key ] = swiftling_to_kept_host( $uploads[ $key ] );
		}
	}
	return $uploads;
}

/**
 * Puts admin-ajax.php on the kept language's host, where the forms and carts of the page call it.
 * The rest of the dashboard stays at the site address.
 *
 * @param string $url  Address.
 * @param string $path Path asked for.
 * @return string
 */
function swiftling_ajax_on_kept_host( $url, $path ) {
	return 0 === strpos( ltrim( (string) $path, '/' ), 'admin-ajax.php' ) ? swiftling_to_kept_host( $url ) : $url;
}

/**
 * Lets WordPress's safe redirects lead to every host of the site: the languages', as the switcher
 * and signing in on a language's host need, and the site's own. WordPress allows the host of
 * home_url(), which on a language's host is the language's, so a redirect from there to the site
 * address, such as of a language's sitemap index to the one that lists every language, went to
 * the dashboard instead.
 *
 * @param string[] $hosts Hosts WordPress allows.
 * @return string[]
 */
function swiftling_allowed_redirect_hosts( $hosts ) {
	if ( ! swiftling_is_host_format() ) {
		return $hosts;
	}
	return array_values( array_unique( array_merge( $hosts, swiftling_own_hosts() ) ) );
}
add_filter( 'allowed_redirect_hosts', 'swiftling_allowed_redirect_hosts' );

/**
 * Sends a visitor to a language's host from its old address: its folder, /de/page/, or ?lang=de,
 * as the site had them before it used hosts. And from the host of a language visitors do not see,
 * to the same page at the site address.
 *
 * In the other formats, a language's former host, as the subdomain or the domain format gave it,
 * leads to the same page in the language: after going back to folders, the host still shows the
 * site, and would show the original under an address search engines know as the language's.
 *
 * The redirect is temporary (302) on purpose, as swiftling_redirect_to_source_folder() explains:
 * browsers keep a permanent one, so going back to folders would send visitors who saw it back and
 * forth. The canonical link of every page names its address on the language's host.
 */
function swiftling_redirect_to_language_host() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || is_robots() || is_favicon() || is_customize_preview() || swiftling_is_probe() ) {
		return;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? '/' . ltrim( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/' ) : '/';
	$home = untrailingslashit( (string) get_option( 'home' ) );
	$base = untrailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) );

	if ( ! swiftling_is_host_format() ) {
		$former = swiftling_former_host_language();
		if ( '' !== $former ) {
			wp_safe_redirect( swiftling_language_url( $home . substr( $uri, strlen( $base ) ), $former ), 302, 'SwiftLing' );
			exit;
		}
		return;
	}

	// On the host of a language that is not on the site for this visitor: the original.
	$on_host = swiftling_requested_host_language();
	if ( '' !== $on_host ) {
		if ( '' === swiftling_translation_language() ) {
			wp_safe_redirect( (string) preg_replace( '#^([a-z][a-z0-9+.\-]*://[^/?\#]*).*$#is', '$1', $home ) . $uri, 302, 'SwiftLing' );
			exit;
		}
		return;
	}

	$path  = (string) strtok( $uri, '?#' );
	$after = (string) substr( $uri, strlen( $path ) );
	foreach ( swiftling_languages()['targets'] as $slug => $target ) {
		if ( ! $target['reachable'] ) {
			continue;
		}
		$folder = $base . '/' . $slug;
		if ( preg_match( '#^' . preg_quote( $folder, '#' ) . '(/|$)#', $path ) && ! url_to_postid( $home . '/' . $slug . '/' ) ) {
			$rest = (string) substr( $path, strlen( $folder ) );
			wp_safe_redirect( swiftling_language_url( $home . ( '' === $rest ? '/' : $rest ) . $after, (string) $slug ), 302, 'SwiftLing' );
			exit;
		}
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only choice of language.
	$lang      = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
	$languages = swiftling_languages()['targets'];
	if ( '' !== $lang && isset( $languages[ $lang ] ) && $languages[ $lang ]['reachable'] ) {
		wp_safe_redirect( swiftling_language_url( remove_query_arg( 'lang', $home . substr( $uri, strlen( $base ) ) ), $lang ), 302, 'SwiftLing' );
		exit;
	}
}
add_action( 'template_redirect', 'swiftling_redirect_to_language_host', 1 );

/**
 * Returns the language whose host the request is on, as the subdomain or the domain format gave
 * it, while the site uses another format.
 *
 * @return string Address slug of a language visitors see, or an empty string.
 */
function swiftling_former_host_language() {
	$host = swiftling_request_host();
	if ( '' === $host || swiftling_home_host() === $host ) {
		return '';
	}
	$domains = swiftling_saved_domains();
	foreach ( swiftling_languages()['targets'] as $slug => $target ) {
		$hosts = array( swiftling_target_host( 'subdomain', (string) $slug, $target['locale'], $domains ), swiftling_target_host( 'domain', (string) $slug, $target['locale'], $domains ) );
		if ( $target['visible'] && in_array( $host, $hosts, true ) ) {
			return (string) $slug;
		}
	}
	return '';
}

/* The check of the hosts. */

/**
 * Returns what the last checks found, by host.
 *
 * @return array<string, array{state: string, at: int}> States: ok, no_answer, no_https, other_site.
 *         Under "#fonts", whether fonts at the site address load on the other hosts: ok or blocked,
 *         see swiftling_check_fonts().
 */
function swiftling_address_checks() {
	$checks = get_option( 'swiftling_address_checks', array() );
	return is_array( $checks ) ? $checks : array();
}

/**
 * Returns the signature this site gives a check for a token: an HMAC with a secret of its own, so
 * another site, or a host's default page, cannot give it.
 *
 * @param string $token Token of the check.
 * @return string
 */
function swiftling_ping_signature( $token ) {
	$secret = (string) get_option( 'swiftling_ping_secret', '' );
	if ( '' === $secret ) {
		$secret = wp_generate_password( 64, true, true );
		add_option( 'swiftling_ping_secret', $secret, '', false );
		$secret = (string) get_option( 'swiftling_ping_secret', $secret );
	}
	return hash_hmac( 'sha256', 'swiftling-ping|' . $token, $secret );
}

/**
 * Answers a check of a host with the signature of its token, before any redirect or page.
 */
function swiftling_answer_ping() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public check; the answer proves the site, nothing changes.
	$token = isset( $_GET['swiftling_ping'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_ping'] ) ) : '';
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		return;
	}
	$signature = swiftling_ping_signature( $token );
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-SwiftLing-Ping: ' . $signature );
	echo esc_html( $signature );
	exit;
}
add_action( 'plugins_loaded', 'swiftling_answer_ping', 0 );

/**
 * Opens a host the way a visitor would and tells whether it leads to this site.
 *
 * Over https, without following redirects: a host that sends visitors on is not this site, even
 * when it sends them here.
 *
 * @param string $host Host.
 * @return string ok, no_answer, no_https or other_site.
 */
function swiftling_check_host( $host ) {
	$token    = bin2hex( random_bytes( 16 ) );
	$response = wp_remote_get(
		'https://' . $host . '/?swiftling_ping=' . $token,
		array(
			'timeout'     => 5,
			'redirection' => 0,
			'sslverify'   => true,
		)
	);
	if ( is_wp_error( $response ) ) {
		// cURL's errors of the secure connection: 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91.
		return preg_match( '/\b(?:SSL|TLS|certificate)\b|cURL error (?:35|51|53|54|58|59|60|64|66|77|80|82|83|90|91):/i', $response->get_error_message() ) ? 'no_https' : 'no_answer';
	}
	$answer = (string) wp_remote_retrieve_header( $response, 'x-swiftling-ping' );
	return 200 === (int) wp_remote_retrieve_response_code( $response ) && hash_equals( swiftling_ping_signature( $token ), $answer ) ? 'ok' : 'other_site';
}

/**
 * Tells whether the site's server lets the pages on a language's host load fonts from the site
 * address. Themes such as Divi write the address of their fonts into a stylesheet they generate
 * once and share between every host, so on de.example.com the browser asks example.com for the
 * font, and refuses it unless the server allows that origin. The server alone can: the font is a
 * file WordPress does not serve. A font WordPress itself has stands in for every font.
 *
 * @param string $host Host of a language that leads here.
 * @return string ok, blocked, or empty when the font did not load at all.
 */
function swiftling_check_fonts( $host ) {
	$response = wp_remote_get(
		set_url_scheme( includes_url( 'fonts/dashicons.woff2' ), 'https' ),
		array(
			'timeout'             => 5,
			'redirection'         => 0,
			'headers'             => array( 'Origin' => 'https://' . $host ),
			'limit_response_size' => 1024,
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	$allowed = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'access-control-allow-origin' ) ) );
	return in_array( $allowed, array( '*', 'https://' . $host ), true ) ? 'ok' : 'blocked';
}

/**
 * Checks hosts and keeps what it found, with what earlier checks found for other hosts. A host
 * that does not answer takes up to five seconds, so after twenty the rest wait for the next check.
 *
 * @param string[] $hosts Hosts.
 * @return array<string, array{state: string, at: int}> What the checks found, by host.
 */
function swiftling_check_hosts( $hosts ) {
	$checks = swiftling_address_checks();
	$start  = microtime( true );
	foreach ( array_unique( array_filter( $hosts ) ) as $host ) {
		if ( microtime( true ) - $start > 20 ) {
			unset( $checks[ $host ] );
			continue;
		}
		$checks[ $host ] = array(
			'state' => swiftling_check_host( $host ),
			'at'    => time(),
		);
		$problems        = array(
			/* translators: %s: host name, e.g. de.example.com. */
			'no_answer'  => __( '%s does not answer. Pages in its language stay at the original address until it does.', 'swiftling' ),
			/* translators: %s: host name, e.g. de.example.com. */
			'no_https'   => __( '%s answers without a valid HTTPS certificate. Pages in its language stay at the original address until it has one.', 'swiftling' ),
			/* translators: %s: host name, e.g. de.example.com. */
			'other_site' => __( '%s answers, but it is not this site. Pages in its language stay at the original address until it leads here.', 'swiftling' ),
		);
		if ( isset( $problems[ $checks[ $host ]['state'] ] ) ) {
			swiftling_log( 'error', 'addresses', sprintf( $problems[ $checks[ $host ]['state'] ], $host ), array( 'detail' => 'host check · ' . $checks[ $host ]['state'] ) );
		}
	}
	update_option( 'swiftling_address_checks', $checks, true );
	return $checks;
}

/**
 * Returns a domain as the Addresses screen keeps it: its host alone, in lower case, without a
 * scheme, port or path.
 *
 * @param string $typed Domain as typed, e.g. "https://Example.de/".
 * @return string For example "example.de"; empty when it is not a domain with at least one dot.
 */
function swiftling_normalize_domain( $typed ) {
	$typed = trim( (string) $typed );
	if ( '' === $typed || preg_match( '/\s/', $typed ) ) {
		return '';
	}
	if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $typed ) ) {
		$typed = 'http://' . $typed;
	}
	$host = strtolower( (string) wp_parse_url( $typed, PHP_URL_HOST ) );
	if ( preg_match( '/[^\x00-\x7f]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
		$host = strtolower( (string) idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) );
	}
	$label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
	return strlen( $host ) <= 253 && preg_match( '/^(?:' . $label . '\.)+[a-z0-9-]{2,63}$/', $host ) && ! preg_match( '/\.[0-9]+$/', $host ) ? $host : '';
}

/**
 * Returns the line of wp-config.php that keeps users signed in on every subdomain of the site.
 *
 * @return string
 */
function swiftling_cookie_domain_line() {
	return "define( 'COOKIE_DOMAIN', '." . swiftling_subdomain_base() . "' );";
}
