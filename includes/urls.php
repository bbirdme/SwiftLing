<?php
/**
 * Addresses of the translated pages.
 *
 * Four formats, chosen on the Addresses screen:
 *
 * - parameter: the original address with ?lang=de at the end;
 * - directory: the original address with a language folder after the site address, /de/page/;
 * - subdomain and domain: the original address on the language's own host, de.example.com/page/
 *   or example.de/page/, see hosts.php.
 *
 * In the directory format, SwiftLing adds the folder to every address WordPress builds from the
 * site address while a page is shown in another language, so menus, links between posts, the
 * search form and pagination stay in that language. WordPress removes the site address from the
 * front of a request before it looks up the page, and the site address then ends in the folder,
 * so /de/page/ finds the same page as /page/ with no rewrite rules of its own.
 *
 * The original language stays at the site address itself, unless the Addresses screen puts it in
 * a folder too: then its pages open at /hr/page/, and an address without a folder sends the
 * visitor to the same page in the original's folder.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the address format the site uses.
 *
 * Language folders need pretty permalinks; on a site with plain or index.php permalinks the
 * parameter is used until the permalinks change. The host formats need none, but are not for a
 * network of sites, whose sites have hosts of their own.
 *
 * @return string parameter, directory, subdomain or domain.
 */
function swiftling_url_format() {
	return swiftling_memo( 'url_format', 'swiftling_build_url_format' );
}

/**
 * Builds what swiftling_url_format() returns.
 *
 * @return string
 */
function swiftling_build_url_format() {
	$format = swiftling_saved_url_format();
	if ( 'directory' === $format ) {
		return swiftling_directories_supported() ? 'directory' : 'parameter';
	}
	if ( in_array( $format, array( 'subdomain', 'domain' ), true ) ) {
		return is_multisite() ? 'parameter' : $format;
	}
	return 'parameter';
}

/**
 * Returns the address format chosen on the Addresses screen, whether or not the site supports it.
 *
 * @return string parameter, directory, subdomain or domain.
 */
function swiftling_saved_url_format() {
	$saved = get_option( 'swiftling_languages', array() );
	return is_array( $saved ) && isset( $saved['format'] ) && in_array( $saved['format'], array( 'directory', 'subdomain', 'domain' ), true ) ? $saved['format'] : 'parameter';
}

/**
 * Returns the domains the Addresses screen gave the languages, whatever the format.
 *
 * @return array<string, string> Locale => domain.
 */
function swiftling_saved_domains() {
	$saved = get_option( 'swiftling_languages', array() );
	return is_array( $saved ) && isset( $saved['domains'] ) && is_array( $saved['domains'] ) ? array_map( 'strval', $saved['domains'] ) : array();
}

/**
 * Whether the Addresses screen puts the original in a folder too, whether or not the site uses
 * language folders now.
 *
 * @return bool
 */
function swiftling_saved_source_folder() {
	$saved = get_option( 'swiftling_languages', array() );
	return is_array( $saved ) && ! empty( $saved['source_folder'] );
}

/**
 * Saves the Addresses screen's choice of how an address tells its language: the format, whether
 * the original has a folder too, and the domains of the languages. The languages themselves stay
 * as they are.
 *
 * A host format is switched on only once the host of every language leads here: the hosts are
 * checked first, see hosts.php, and until all of them lead here the site keeps its addresses.
 * "Check again" checks and keeps the domains without switching anything.
 */
function swiftling_save_url_format() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the addresses of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_save_url_format' );

	$saved  = get_option( 'swiftling_languages', array() );
	$saved  = is_array( $saved ) ? $saved : array();
	$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';
	$format = in_array( $format, array( 'directory', 'subdomain', 'domain' ), true ) ? $format : 'parameter';
	$hosted = in_array( $format, array( 'subdomain', 'domain' ), true );
	if ( $hosted && is_multisite() ) {
		$format = 'parameter';
		$hosted = false;
	}
	$args = array();

	if ( isset( $_POST['domains'] ) && is_array( $_POST['domains'] ) ) {
		$typed            = array_map( 'sanitize_text_field', wp_unslash( $_POST['domains'] ) );
		$domains          = swiftling_posted_domains( $typed );
		$saved['domains'] = $domains['domains'];
		if ( 'domain' === $format && $domains['errors'] ) {
			$args['swiftling_domain_error'] = $domains['errors'];
			// What was typed shows again in its field, next to what is wrong with it.
			$args['swiftling_typed'] = array_map( 'rawurlencode', array_intersect_key( array_map( 'trim', $typed ), $domains['errors'] ) );
		}
	}

	if ( ! $hosted ) {
		$saved['format']        = $format;
		$saved['source_folder'] = ! empty( $_POST['source_folder'] );
		update_option( 'swiftling_languages', $saved, true );
		$args['swiftling_notice'] = 'saved';
		$taken                    = swiftling_taken_directories();
		if ( $taken ) {
			$args['swiftling_taken'] = implode( ',', $taken );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=swiftling-addresses' ) ) );
		exit;
	}

	// Every language's host in the chosen format, checked before anything changes on the site.
	$hosts = array();
	foreach ( swiftling_languages()['targets'] as $target ) {
		$hosts[ $target['locale'] ] = swiftling_target_host( $format, (string) $target['slug'], $target['locale'], isset( $saved['domains'] ) ? $saved['domains'] : array() );
	}
	$checks = swiftling_check_hosts( array_values( $hosts ) );
	$ready  = (bool) $hosts;
	$fonts  = false;
	foreach ( $hosts as $host ) {
		$leads = '' !== $host && isset( $checks[ $host ]['state'] ) && 'ok' === $checks[ $host ]['state'];
		$ready = $ready && $leads;
		// Fonts, from the first host that leads here; they never stop the switch, see the screen.
		if ( $leads && ! $fonts ) {
			$fonts = true;
			$state = swiftling_check_fonts( $host );
			if ( '' !== $state ) {
				$checks['#fonts'] = array(
					'state' => $state,
					'at'    => time(),
				);
				update_option( 'swiftling_address_checks', $checks, true );
			}
		}
	}

	$do = isset( $_POST['swiftling_do'] ) && 'check' === sanitize_key( wp_unslash( $_POST['swiftling_do'] ) ) ? 'check' : 'save';
	if ( 'save' === $do && $ready ) {
		$saved['format']          = $format;
		$args['swiftling_notice'] = 'saved';
	} else {
		$args['format']           = $format;
		$args['swiftling_notice'] = 'save' === $do ? 'not_ready' : 'checked';
	}
	update_option( 'swiftling_languages', $saved, true );
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=swiftling-addresses' ) ) . '#swiftling-format' );
	exit;
}

/**
 * Returns the domains as the Addresses screen keeps them, and what is wrong with the others: not a
 * domain, the site's own, or one another language has.
 *
 * @param array $typed Locale => domain as typed.
 * @return array{domains: array<string, string>, errors: array<string, string>} Errors are by
 *         locale: empty, invalid or taken.
 */
function swiftling_posted_domains( $typed ) {
	$targets = array();
	foreach ( swiftling_languages()['targets'] as $target ) {
		$targets[ $target['locale'] ] = true;
	}
	$domains = array();
	$errors  = array();
	$taken   = array( swiftling_home_host() => true );
	foreach ( $typed as $locale => $value ) {
		$locale = (string) $locale;
		if ( ! isset( $targets[ $locale ] ) ) {
			continue;
		}
		$domain = swiftling_normalize_domain( (string) $value );
		if ( '' === $domain ) {
			$errors[ $locale ] = '' === trim( (string) $value ) ? 'empty' : 'invalid';
		} elseif ( isset( $taken[ $domain ] ) ) {
			$errors[ $locale ] = 'taken';
		} else {
			$domains[ $locale ] = $domain;
			$taken[ $domain ]   = true;
		}
	}
	return array(
		'domains' => $domains,
		'errors'  => $errors,
	);
}
add_action( 'admin_post_swiftling_save_url_format', 'swiftling_save_url_format' );

/**
 * Whether the permalinks of the site allow language folders.
 *
 * @return bool
 */
function swiftling_directories_supported() {
	return swiftling_memo(
		'directories',
		static function () {
			$structure = (string) get_option( 'permalink_structure' );
			return '' !== $structure && 0 !== strpos( ltrim( $structure, '/' ), 'index.php' );
		}
	);
}

/**
 * Returns the folder of the original language: its address slug when the site uses language
 * folders and the Addresses screen puts the original in one too.
 *
 * @return string For example "hr", or an empty string when the original stays at the site address.
 */
function swiftling_source_folder() {
	if ( 'directory' !== swiftling_url_format() || ! swiftling_saved_source_folder() ) {
		return '';
	}
	return swiftling_languages()['source']['slug'];
}

/**
 * Returns every language folder of the site: the original's, when it has one, and the targets'.
 *
 * @return string[] Address slugs, e.g. array( 'hr', 'de' ). Empty in the parameter format.
 */
function swiftling_folders() {
	if ( 'directory' !== swiftling_url_format() ) {
		return array();
	}
	$folders = array_map( 'strval', array_keys( swiftling_languages()['targets'] ) );
	$source  = swiftling_source_folder();
	return '' === $source ? $folders : array_merge( array( $source ), $folders );
}

/**
 * Returns the language folder the current request's address starts with.
 *
 * @return string Address slug, or an empty string when the address has no folder.
 */
function swiftling_requested_folder() {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) strtok( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?#' ) : '';
	return swiftling_folder_of_path( $path );
}

/**
 * Returns the language folder an address path starts with.
 *
 * @param string $path Path of an address, without its query.
 * @return string Address slug, or an empty string when the path has no folder.
 */
function swiftling_folder_of_path( $path ) {
	$home = untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );
	foreach ( swiftling_folders() as $slug ) {
		if ( preg_match( '#^' . preg_quote( $home . '/' . $slug, '#' ) . '(/|$)#', $path ) ) {
			return $slug;
		}
	}
	return '';
}

/**
 * Returns the language of the current request, from its address.
 *
 * @return string Address slug of a target language, or an empty string for the original.
 */
function swiftling_requested_language() {
	if ( swiftling_is_host_format() ) {
		return swiftling_host_language();
	}
	if ( 'directory' === swiftling_url_format() ) {
		$folder = swiftling_requested_folder();
		return null !== swiftling_target_language( $folder ) ? $folder : '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only choice of language.
	$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
	return null !== swiftling_target_language( $lang ) ? $lang : '';
}

/**
 * Returns the language of an address of this site, as the request for it would decide it: its
 * host, its folder or its lang parameter, by the format of the addresses. An address without a
 * host, such as a path, is on the host of the current request.
 *
 * @param string $url Address, e.g. the page a form was sent from.
 * @return string Address slug of a target language, or an empty string for the original and for
 *                other sites' addresses.
 */
function swiftling_address_language( $url ) {
	if ( '' === $url || ! swiftling_is_own_address( $url ) ) {
		return '';
	}
	if ( swiftling_is_host_format() ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return swiftling_language_of_host( is_string( $host ) ? strtolower( $host ) : swiftling_request_host() );
	}
	if ( 'directory' === swiftling_url_format() ) {
		$folder = swiftling_folder_of_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return null !== swiftling_target_language( $folder ) ? $folder : '';
	}

	wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$lang = isset( $query['lang'] ) && is_string( $query['lang'] ) ? sanitize_key( $query['lang'] ) : '';
	return null !== swiftling_target_language( $lang ) ? $lang : '';
}

/**
 * Returns the address of a page of this site in a language.
 *
 * @param string $url  Address without a language, e.g. a permalink.
 * @param string $slug Address slug of a target language, e.g. "de", or an empty string for the
 *                     original, which gets its folder when it has one.
 * @return string
 */
function swiftling_language_url( $url, $slug ) {
	if ( swiftling_is_host_format() ) {
		return swiftling_swap_host( $url, swiftling_language_host( $slug ) );
	}
	if ( '' === $slug ) {
		$folder = swiftling_source_folder();
		return '' === $folder ? $url : swiftling_add_language_directory( $url, $folder );
	}
	if ( 'directory' === swiftling_url_format() ) {
		return swiftling_add_language_directory( $url, $slug );
	}
	return add_query_arg( 'lang', $slug, $url );
}

/**
 * Returns how the address of a language is written, for showing it on the screens.
 *
 * @param string $slug Address slug of the language, e.g. "de".
 * @return string For example "?lang=de", "/de/" or "de.example.com".
 */
function swiftling_language_address( $slug ) {
	if ( swiftling_is_host_format() ) {
		return swiftling_language_host( $slug );
	}
	return 'directory' === swiftling_url_format() ? '/' . $slug . '/' : '?lang=' . $slug;
}

/**
 * Puts a language folder right after the site address in an address of this site.
 *
 * The scheme does not matter, so http and https addresses both work. An address without scheme
 * and host, as home_url() builds it for the relative scheme, is matched against the path of the
 * site address. Other sites' addresses come back unchanged.
 *
 * @param string $url  Address.
 * @param string $slug Address slug of the language, e.g. "de".
 * @return string For example https://example.com/de/page/ for https://example.com/page/.
 */
function swiftling_add_language_directory( $url, $slug ) {
	$home = untrailingslashit( (string) get_option( 'home' ) );

	if ( preg_match( '#^([a-z][a-z0-9+.\-]*:)?(//.*)$#is', $url, $match ) ) {
		$scheme = $match[1];
		$rest   = $match[2];
		$home   = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*:#i', '', $home );
	} else {
		$scheme = '';
		$rest   = $url;
		$home   = untrailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) );
	}

	$length = strlen( $home );
	if ( 0 !== strncasecmp( $rest, $home, $length ) ) {
		return $url;
	}
	$after = (string) substr( $rest, $length );
	// https://example.com.evil/ starts with https://example.com too.
	if ( '' !== $after && ! in_array( $after[0], array( '/', '?', '#' ), true ) ) {
		return $url;
	}
	if ( '' !== $after && '/' !== $after[0] ) {
		$after = '/' . $after;
	}

	return $scheme . substr( $rest, 0, $length ) . '/' . $slug . $after;
}

/**
 * Takes a language folder from the front of an address of this site again, as
 * swiftling_add_language_directory() put it there. Other addresses come back unchanged.
 *
 * @param string $url  Address.
 * @param string $slug Address slug of the language, e.g. "de".
 * @return string For example https://example.com/page/ for https://example.com/de/page/.
 */
function swiftling_remove_language_directory( $url, $slug ) {
	$home = untrailingslashit( (string) get_option( 'home' ) );

	if ( preg_match( '#^([a-z][a-z0-9+.\-]*:)?(//.*)$#is', $url, $match ) ) {
		$scheme = $match[1];
		$rest   = $match[2];
		$home   = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*:#i', '', $home );
	} else {
		$scheme = '';
		$rest   = $url;
		$home   = untrailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) );
	}

	$folder = $home . '/' . $slug;
	if ( '' === $slug || 0 !== strncasecmp( $rest, $folder, strlen( $folder ) ) ) {
		return $url;
	}
	$after = (string) substr( $rest, strlen( $folder ) );
	// /de-at/ starts with /de too.
	if ( '' !== $after && ! in_array( $after[0], array( '/', '?', '#' ), true ) ) {
		return $url;
	}
	if ( '' !== $after && '/' !== $after[0] ) {
		$after = '/' . $after;
	}

	return $scheme . substr( $rest, 0, strlen( $home ) ) . $after;
}

/**
 * Returns an address of this site without the language it names: no language folder at the
 * front, the original's included, and no ?lang= in the parameter format.
 *
 * @param string $url Address.
 * @return string
 */
function swiftling_address_without_language( $url ) {
	if ( swiftling_is_host_format() ) {
		return swiftling_swap_host( $url, swiftling_home_host() );
	}
	foreach ( swiftling_folders() as $folder ) {
		$without = swiftling_remove_language_directory( $url, $folder );
		if ( $without !== $url ) {
			return $without;
		}
	}
	return 'parameter' === swiftling_url_format() && false !== strpos( $url, '?' ) ? remove_query_arg( 'lang', $url ) : $url;
}

/**
 * Whether an address leads to this site: it has the host of the site address, or of a language in
 * the host formats, or none.
 *
 * @param string $url Address.
 * @return bool
 */
function swiftling_is_own_address( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( null === $host ) {
		return true;
	}
	$hosts = swiftling_is_host_format() ? swiftling_own_hosts() : array( swiftling_home_host() );
	return is_string( $host ) && in_array( strtolower( $host ), $hosts, true );
}

/**
 * Makes the addresses WordPress builds from the site address carry a language folder for the rest
 * of the request, so links stay in the language and WordPress finds the page behind the folder.
 *
 * @param string $slug Address slug of the folder, e.g. "de".
 */
function swiftling_keep_folder( $slug ) {
	$folder = &swiftling_kept_folder();
	$folder = $slug;
	// Early, so filters after it see the address with the folder.
	add_filter( 'home_url', 'swiftling_filter_home_url', 1, 4 );
}

/**
 * Returns the folder swiftling_keep_folder() keeps for this request.
 *
 * @return string Address slug, or an empty string.
 */
function &swiftling_kept_folder() {
	static $folder = '';
	return $folder;
}

/**
 * Sends a request for an address without a folder to the same address in the original's folder,
 * when the original has one. Only pages of the site are sent on: robots.txt, the favicon and the
 * sitemaps stay at the site address, form submissions are left alone, and page checks and the
 * Customizer preview stay where they are.
 *
 * The redirect is temporary (302) on purpose. Browsers keep a permanent one for good, so turning
 * the option off again would send visitors who saw it on back and forth between the two addresses.
 * The canonical link of every page names its address with the folder for search engines.
 */
function swiftling_redirect_to_source_folder() {
	$folder = swiftling_source_folder();
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( '' === $folder || '' !== swiftling_requested_folder() || ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		return;
	}
	if ( is_robots() || is_favicon() || get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) || is_customize_preview() || swiftling_is_probe() ) {
		return;
	}

	// The path of the request, with the scheme and host of the site address, never the request's.
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? '/' . ltrim( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/' ) : '/';
	$target = swiftling_add_language_directory( $uri, $folder );
	if ( $target !== $uri ) {
		$origin = (string) preg_replace( '#^([a-z][a-z0-9+.\-]*://[^/?\#]*).*$#is', '$1', home_url() );
		wp_safe_redirect( $origin . $target, 302, 'SwiftLing' );
		exit;
	}
}
add_action( 'template_redirect', 'swiftling_redirect_to_source_folder', 1 );

/**
 * Adds the language folder to the addresses WordPress builds from the site address. Hooked by
 * swiftling_keep_folder() for a request whose address has a folder, and by swiftling_keep_host()
 * for one on a language's host, where every address WordPress builds goes on that host.
 *
 * Left unchanged with a folder: addresses of another site of a network, the addresses of the REST
 * API, which a client asks in a language itself (/de/wp-json/… or ?lang=de), and files that exist
 * on disk, which the web server serves without WordPress.
 *
 * @param string      $url     Address built by home_url().
 * @param string      $path    Path asked for.
 * @param string|null $scheme  Scheme asked for.
 * @param int|null    $blog_id Site of the network.
 * @return string
 */
function swiftling_filter_home_url( $url, $path, $scheme, $blog_id ) {
	if ( ( $blog_id && get_current_blog_id() !== (int) $blog_id ) || ( is_multisite() && ms_is_switched() ) ) {
		return $url;
	}
	if ( '' !== swiftling_kept_host() ) {
		return swiftling_to_kept_host( $url );
	}

	$path   = ltrim( is_string( $path ) ? $path : '', '/' );
	$prefix = rest_get_url_prefix();
	if ( 0 === strpos( $path . '/', $prefix . '/' ) ) {
		return $url;
	}

	$file = (string) strtok( $path, '?#' );
	if ( preg_match( '/\.[a-z0-9]{1,8}$/i', $file ) && is_file( ABSPATH . $file ) ) {
		return $url;
	}

	return swiftling_add_language_directory( $url, swiftling_kept_folder() );
}

/**
 * Returns an address WordPress builds, such as a permalink, without the language folder of the page
 * being shown, so swiftling_language_url() can put any language on it.
 *
 * @param callable $build Builds the address, e.g. a call to get_permalink().
 * @return string
 */
function swiftling_unlocalized_url( $build ) {
	$keeps = has_filter( 'home_url', 'swiftling_filter_home_url' );
	if ( false !== $keeps ) {
		remove_filter( 'home_url', 'swiftling_filter_home_url', 1 );
	}
	try {
		$url = (string) call_user_func( $build );
	} finally {
		if ( false !== $keeps ) {
			add_filter( 'home_url', 'swiftling_filter_home_url', 1, 4 );
		}
	}
	return $url;
}

/**
 * Returns the address of the page being shown, in a language. The language switcher links to it.
 *
 * Built from the request, so every kind of page keeps its place: a post, an archive, a search,
 * the second page of a list. A post or a term archive gets its slug in that language, see
 * swiftling_current_slugs(), and the page of a builder that writes words of its own into its
 * addresses gets that language's words, see Builder::currentAddress(). The scheme and host come from the site address, never from the
 * request. Outside a page of the site, in the admin and in the REST API, it is the site address.
 *
 * @param string $slug Address slug of a target language, or an empty string for the original.
 * @return string
 */
function swiftling_current_url( $slug ) {
	return \SwiftLing\Builders\Registry::currentAddress( swiftling_language_url( swiftling_current_slugs( swiftling_current_original_url(), $slug ), $slug ), (string) $slug );
}

/**
 * Returns an address in one spelling of its characters outside ASCII: percent-encoded in lower case,
 * as WordPress writes slugs. %D0%BE, %d0%be and an unencoded "о" are the same address, and proxies
 * such as Traefik rewrite one into another, so SwiftLing compares addresses in this spelling, as
 * WordPress's redirect_canonical() does.
 *
 * @param string $url Address or path.
 * @return string
 */
function swiftling_normalize_address( $url ) {
	$url = (string) preg_replace_callback(
		'/%[0-9a-f]{2}/i',
		static function ( $octet ) {
			return strtolower( $octet[0] );
		},
		(string) $url
	);
	return (string) preg_replace_callback(
		'/[\x80-\xff]/',
		static function ( $byte ) {
			return strtolower( rawurlencode( $byte[0] ) );
		},
		$url
	);
}

/**
 * Returns the address of the page being shown without a language: no language folder, also not
 * the original's, and no ?lang=. swiftling_language_url() adds the language back.
 *
 * @return string
 */
function swiftling_current_original_url() {
	$home = (string) get_option( 'home' );
	if ( is_admin() || wp_doing_ajax() || wp_is_serving_rest_request() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return trailingslashit( $home );
	}

	$site   = wp_parse_url( set_url_scheme( $home ) );
	$origin = $site['scheme'] . '://' . $site['host'] . ( isset( $site['port'] ) ? ':' . $site['port'] : '' );
	// In one spelling, so it compares with the addresses SwiftLing builds, see swiftling_current_slugs().
	$uri = '/' . ltrim( swiftling_normalize_address( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ), '/' );

	if ( 'directory' === swiftling_url_format() ) {
		$path  = (string) strtok( $uri, '?#' );
		$after = (string) substr( $uri, strlen( $path ) );
		$base  = untrailingslashit( isset( $site['path'] ) ? $site['path'] : '' );
		foreach ( swiftling_folders() as $language ) {
			$folder = $base . '/' . $language;
			if ( preg_match( '#^' . preg_quote( $folder, '#' ) . '(/|$)#', $path ) ) {
				$path = $base . substr( $path, strlen( $folder ) );
				break;
			}
		}
		return $origin . ( '' !== $path ? $path : '/' ) . $after;
	}

	return remove_query_arg( 'lang', $origin . $uri );
}

/**
 * Returns a link address in the language of the page being shown, when it leads to a page of this
 * site: /kontakt/ becomes /de/kontakt/. Links in stored content, such as a menu item or a link in
 * a paragraph, keep the address they were saved with; the addresses WordPress builds itself carry
 * the language already, see swiftling_keep_folder().
 *
 * Left unchanged: other sites, files, the admin, the REST API, feeds, anchors on the same page and
 * addresses that already name a language.
 *
 * @param string $url Address.
 * @return string
 */
function swiftling_localize_url( $url ) {
	$state = &swiftling_state();
	if ( 'translate' !== $state['mode'] || '' === $state['language'] || ! is_string( $url ) || '' === $url ) {
		return $url;
	}

	$home = wp_parse_url( (string) get_option( 'home' ) );
	$link = wp_parse_url( $url );
	if ( ! is_array( $home ) || ! is_array( $link ) ) {
		return $url;
	}
	// Only absolute addresses of this site and paths from its root.
	$own_site  = isset( $link['host'], $home['host'] ) && strtolower( $link['host'] ) === strtolower( $home['host'] );
	$root_path = ! isset( $link['host'] ) && 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' );
	// On a language's host a path from the root already stays on it.
	if ( ( ! $own_site && ! $root_path ) || ( $root_path && swiftling_is_host_format() ) ) {
		return $url;
	}
	if ( isset( $link['scheme'] ) && ! in_array( strtolower( $link['scheme'] ), array( 'http', 'https' ), true ) ) {
		return $url;
	}

	$base = untrailingslashit( isset( $home['path'] ) ? $home['path'] : '' );
	$path = isset( $link['path'] ) ? $link['path'] : '/';
	if ( '' !== $base && 0 !== strpos( $path . '/', $base . '/' ) ) {
		return $url;
	}
	$rest = '/' . ltrim( (string) substr( $path, strlen( $base ) ), '/' );

	// Files and the parts of WordPress that are no pages.
	if ( preg_match( '#^/(?:wp-admin|wp-content|wp-includes|wp-json|feed)(?:/|$)|^/wp-[a-z-]+\.php|\.[a-z0-9]{1,8}$#i', $rest ) ) {
		return $url;
	}
	// Already in a language.
	foreach ( swiftling_folders() as $folder ) {
		if ( preg_match( '#^/' . preg_quote( $folder, '#' ) . '(?:/|$)#', $rest ) ) {
			return $url;
		}
	}
	if ( isset( $link['query'] ) && preg_match( '/(?:^|&)lang=/', $link['query'] ) ) {
		return $url;
	}

	return swiftling_language_url( $url, $state['language'] );
}

/**
 * Puts every link of HTML stored as content into the language of the page, see
 * swiftling_localize_url(). Only the href of links is touched.
 *
 * @param string $html HTML.
 * @return string
 */
function swiftling_localize_links( $html ) {
	if ( ! is_string( $html ) || false === stripos( $html, 'href' ) ) {
		return $html;
	}
	return (string) preg_replace_callback(
		'~(\shref=)(["\'])([^"\']*)\2~i',
		function ( $found ) {
			$url = html_entity_decode( $found[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$new = swiftling_localize_url( $url );
			return $new === $url ? $found[0] : $found[1] . $found[2] . esc_attr( $new ) . $found[2];
		},
		$html
	);
}

/**
 * Puts the custom links of a classic menu into the language of the page. Menu items that lead to
 * a post or a category already do, because WordPress builds their address.
 *
 * @param array $items Menu items.
 * @return array
 */
function swiftling_localize_menu_items( $items ) {
	foreach ( $items as $item ) {
		if ( isset( $item->type, $item->url ) && 'custom' === $item->type ) {
			$item->url = swiftling_localize_url( $item->url );
		}
	}
	return $items;
}
