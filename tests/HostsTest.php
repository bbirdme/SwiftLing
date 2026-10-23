<?php
/**
 * Tests of a subdomain or an own domain per language.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use SwiftLing\Switcher\Switcher;
use WP_Error;

/**
 * Addresses whose host tells the language: de.example.org/page/ or example.de/page/.
 */
class HostsTest extends TestCase {

	/**
	 * Skips on a network of sites, which does not use the host formats: its sites have hosts of
	 * their own, see swiftling_url_format() and UrlsTest::test_host_formats_on_a_network().
	 */
	public function set_up() {
		parent::set_up();
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A network of sites does not use the host formats.' );
		}
	}

	/**
	 * Hosts that answer a check as this site does, in the test that adds them.
	 *
	 * @var string[]
	 */
	private $answering = array();

	/**
	 * Puts the site in a host format, every host checked and leading here.
	 *
	 * @param string $format  subdomain or domain.
	 * @param array  $domains Locale => domain, in the domain format.
	 */
	private function use_hosts( $format = 'subdomain', $domains = array() ) {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			$format,
			array( 'domains' => $domains )
		);
		$checks = array();
		foreach ( swiftling_languages()['targets'] as $target ) {
			if ( '' !== $target['host'] ) {
				$checks[ $target['host'] ] = array(
					'state' => 'ok',
					'at'    => time(),
				);
			}
		}
		update_option( 'swiftling_address_checks', $checks );
	}

	/**
	 * Answers a check of a host as this site does: with the signature of the check's token.
	 *
	 * @param mixed  $pre  Answer from an earlier filter, or false.
	 * @param array  $args Request arguments.
	 * @param string $url  Address.
	 * @return mixed
	 */
	public function answer_as_this_site( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, $this->answering, true ) ) {
			return $pre;
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return array(
			'headers'  => array( 'x-swiftling-ping' => swiftling_ping_signature( (string) $query['swiftling_ping'] ) ),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Lets hosts answer checks as this site does.
	 *
	 * @param string ...$hosts Hosts.
	 */
	private function answer( ...$hosts ) {
		$this->answering = $hosts;
		add_filter( 'pre_http_request', array( $this, 'answer_as_this_site' ), 10, 3 );
	}

	/**
	 * Renders the Addresses screen.
	 *
	 * @return string
	 */
	private function render_addresses() {
		set_current_screen( 'toplevel_page_swiftling' );
		ob_start();
		( new Menu() )->renderScreen( 'addresses' );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );
		return $html;
	}

	/* The format and the host of each language. */

	/**
	 * A host format needs no pretty permalinks: the language is not in the path.
	 */
	public function test_host_formats_need_no_pretty_permalinks() {
		$this->use_hosts( 'subdomain' );
		$this->assertSame( 'subdomain', swiftling_url_format() );
		$this->assertTrue( swiftling_is_host_format() );

		$this->use_hosts( 'domain', array( 'de_DE' => 'example.de' ) );
		$this->assertSame( 'domain', swiftling_url_format() );
		$this->assertTrue( swiftling_is_host_format() );

		$this->set_languages();
		$this->assertFalse( swiftling_is_host_format() );
	}

	/**
	 * A subdomain is the language's address in front of the site's host, without its www.
	 */
	public function test_subdomain_hosts() {
		$this->use_hosts( 'subdomain' );
		$this->assertSame( 'de.example.org', swiftling_language_host( 'de' ) );
		$this->assertSame( 'example.org', swiftling_language_host( '' ), 'The original stays at the site address' );
		$this->assertSame( 'de.example.org', swiftling_language_address( 'de' ) );

		update_option( 'home', 'http://www.example.org' );
		$this->assertSame( 'de.example.org', swiftling_language_host( 'de' ) );
		$this->assertSame( 'www.example.org', swiftling_language_host( '' ) );
	}

	/**
	 * In the domain format each language has the domain the Addresses screen gave it, kept by its
	 * locale, so a changed address on Languages keeps the domain.
	 */
	public function test_domain_hosts() {
		$this->use_hosts( 'domain', array( 'de_DE' => 'example.de' ) );
		$this->assertSame( 'example.de', swiftling_language_host( 'de' ) );
		$this->assertSame( '', swiftling_language_host( 'fr' ), 'No domain yet' );
	}

	/**
	 * A language whose address has not led here in a check is not on the site: visitors, and
	 * editors too, would land on an address that does not work.
	 */
	public function test_language_without_a_working_host_is_not_on_the_site() {
		$this->use_hosts( 'domain', array( 'de_DE' => 'example.de' ) );
		$languages = swiftling_languages()['targets'];
		$this->assertTrue( $languages['de']['reachable'] );
		$this->assertFalse( $languages['fr']['reachable'], 'No domain' );

		$this->become( 'editor' );
		$html = Switcher::render( array( 'style' => 'horizontal' ) );
		$this->assertStringContainsString( 'example.de', $html );
		$this->assertStringNotContainsString( 'Français', $html );

		update_option( 'swiftling_address_checks', array() );
		$this->assertFalse( swiftling_languages()['targets']['de']['reachable'], 'Never checked' );
	}

	/**
	 * In the parameter and the folder format every language is on the site.
	 */
	public function test_other_formats_reach_every_language() {
		$this->assertTrue( swiftling_languages()['targets']['de']['reachable'] );
		$this->assertSame( '', swiftling_languages()['targets']['de']['host'] );
	}

	/* Requests and addresses. */

	/**
	 * The language of a request comes from its host, whatever the case or the port.
	 */
	public function test_requested_language_from_the_host() {
		$this->use_hosts( 'subdomain' );
		foreach ( array(
			'de.example.org'      => 'de',
			'DE.Example.org'      => 'de',
			'de.example.org:8080' => 'de',
			'fr.example.org'      => 'fr',
			'example.org'         => '',
			'it.example.org'      => '',
			'de.example.org.evil' => '',
		) as $host => $expected ) {
			$_SERVER['HTTP_HOST'] = $host;
			$this->assertSame( $expected, swiftling_requested_language(), $host );
		}

		// A folder or ?lang= means nothing in a host format.
		$_SERVER['HTTP_HOST']   = 'example.org';
		$_SERVER['REQUEST_URI'] = '/de/kontakt/';
		$_GET['lang']           = 'de';
		$this->assertSame( '', swiftling_requested_language() );
	}

	/**
	 * A language's address is the same address on its host; other sites and paths stay as they are.
	 */
	public function test_language_url_swaps_the_host() {
		$this->use_hosts( 'subdomain' );
		$this->assertSame( 'http://de.example.org/kontakt/?a=1', swiftling_language_url( 'http://example.org/kontakt/?a=1', 'de' ) );
		$this->assertSame( 'https://de.example.org/', swiftling_language_url( 'https://example.org/', 'de' ) );
		$this->assertSame( 'http://fr.example.org/kontakt/', swiftling_language_url( 'http://de.example.org/kontakt/', 'fr' ) );
		$this->assertSame( 'http://example.org/kontakt/', swiftling_language_url( 'http://de.example.org/kontakt/', '' ) );
		$this->assertSame( 'https://other.org/kontakt/', swiftling_language_url( 'https://other.org/kontakt/', 'de' ) );
		$this->assertSame( 'http://example.org.evil/', swiftling_language_url( 'http://example.org.evil/', 'de' ) );

		$this->assertSame( 'http://example.org/kontakt/', swiftling_address_without_language( 'http://de.example.org/kontakt/' ) );
		$this->assertTrue( swiftling_is_own_address( 'http://de.example.org/kontakt/' ) );
		$this->assertTrue( swiftling_is_own_address( 'http://example.org/kontakt/' ) );
		$this->assertFalse( swiftling_is_own_address( 'http://it.example.org/kontakt/' ) );
	}

	/**
	 * The address of the page being shown, and of it in another language, from the request's host.
	 */
	public function test_current_url_on_a_language_host() {
		$this->use_hosts( 'subdomain' );
		$_SERVER['HTTP_HOST']   = 'de.example.org';
		$_SERVER['REQUEST_URI'] = '/kontakt/?x=1';
		$this->assertSame( 'http://example.org/kontakt/?x=1', swiftling_current_original_url() );
		$this->assertSame( 'http://fr.example.org/kontakt/?x=1', swiftling_current_url( 'fr' ) );
		$this->assertSame( 'http://example.org/kontakt/?x=1', swiftling_current_url( '' ) );
	}

	/**
	 * On a language's host every address WordPress builds for visitors is on that host: pages, and
	 * the files and calls browsers refuse from another host, such as fonts and admin-ajax.php. The
	 * dashboard and signing in stay at the site address.
	 */
	public function test_kept_host_reaches_addresses_wordpress_builds() {
		$this->use_hosts( 'subdomain' );
		swiftling_keep_host( 'de' );

		$this->assertSame( 'http://de.example.org/kontakt/', home_url( '/kontakt/' ) );
		$this->assertStringStartsWith( 'http://de.example.org/', rest_url( 'wp/v2/' ) );
		$this->assertStringStartsWith( 'http://de.example.org/wp-content/', content_url( 'themes/x/font.woff2' ) );
		$this->assertStringStartsWith( 'http://de.example.org/wp-content/plugins/', plugins_url( 'x.js', WP_PLUGIN_DIR . '/x/x.php' ) );
		$this->assertStringStartsWith( 'http://de.example.org/wp-includes/', includes_url( 'css/dashicons.css' ) );
		$this->assertStringStartsWith( 'http://de.example.org/', wp_upload_dir()['baseurl'] );
		$this->assertSame( 'http://de.example.org/wp-admin/admin-ajax.php', admin_url( 'admin-ajax.php' ) );
		$this->assertSame( 'http://example.org/wp-admin/options.php', admin_url( 'options.php' ) );
		$this->assertSame( 'http://example.org/wp-login.php', site_url( 'wp-login.php' ) );
		$this->assertSame( 'http://de.example.org/wp-includes/js/x.js', apply_filters( 'script_loader_src', 'http://example.org/wp-includes/js/x.js', 'x' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as wp_enqueue_script() runs it.
		$this->assertSame( 'https://cdn.other.org/x.css', apply_filters( 'style_loader_src', 'https://cdn.other.org/x.css', 'x' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as wp_enqueue_script() runs it.

		$unlocalized = swiftling_unlocalized_url(
			static function () {
				return home_url( '/kontakt/' );
			}
		);
		$this->assertSame( 'http://example.org/kontakt/', $unlocalized );
		$this->assertSame( 'http://de.example.org/a/', home_url( '/a/' ), 'The host is kept again afterwards' );
	}

	/**
	 * Yoast SEO's sitemaps name their stylesheet for browsers at the site address, set before the
	 * request knows its language; browsers refuse a stylesheet from another host and show nothing.
	 * On a language's host it is on that host.
	 */
	public function test_sitemap_stylesheet_on_the_languages_host() {
		$this->use_hosts( 'subdomain' );
		$stylesheet = '<?xml-stylesheet type="text/xsl" href="//example.org/wp-content/plugins/wordpress-seo/css/main-sitemap.xsl"?>';
		$this->assertSame( $stylesheet, apply_filters( 'wpseo_stylesheet_url', $stylesheet ), 'At the site address' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own filter.

		swiftling_keep_host( 'de' );
		$this->assertSame(
			'<?xml-stylesheet type="text/xsl" href="//de.example.org/wp-content/plugins/wordpress-seo/css/main-sitemap.xsl"?>',
			apply_filters( 'wpseo_stylesheet_url', $stylesheet ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own filter.
		);
	}

	/**
	 * Links stored in content follow the host of the page; paths from the root already do.
	 */
	public function test_localize_url_on_a_language_host() {
		$this->use_hosts( 'subdomain' );
		swiftling_start( 'translate', 'de' );
		$this->assertSame( 'http://de.example.org/kontakt/', swiftling_localize_url( 'http://example.org/kontakt/' ) );
		$this->assertSame( '/kontakt/', swiftling_localize_url( '/kontakt/' ) );
		$this->assertSame( 'http://fr.example.org/kontakt/', swiftling_localize_url( 'http://fr.example.org/kontakt/' ) );
		$this->assertSame( 'https://other.org/', swiftling_localize_url( 'https://other.org/' ) );
		$this->assertSame( 'http://example.org/wp-content/uploads/a.jpg', swiftling_localize_url( 'http://example.org/wp-content/uploads/a.jpg' ) );
	}

	/**
	 * On a language's host, links WordPress builds carry the language's translated slugs, as in folders.
	 */
	public function test_links_use_the_languages_slugs() {
		$this->use_hosts( 'subdomain' );
		swiftling_start( 'translate', 'de' );
		$this->assertSame( 'de_DE', swiftling_link_locale() );
	}

	/**
	 * A visit to de.example.org starts the translated view in German and keeps the host.
	 */
	public function test_request_on_a_language_host_starts_the_language() {
		$this->use_hosts( 'subdomain' );
		$_SERVER['HTTP_HOST']   = 'de.example.org';
		$_SERVER['REQUEST_URI'] = '/';
		swiftling_maybe_start_translation();

		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'http://de.example.org/kontakt/', home_url( '/kontakt/' ) );
	}

	/* Redirects. */

	/**
	 * Old addresses of a language, in its folder or with ?lang=, lead to its host, temporarily, as
	 * the original's folder does.
	 */
	public function test_old_addresses_lead_to_the_host() {
		$this->use_pretty_permalinks();
		$this->use_hosts( 'subdomain' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.org';

		$_SERVER['REQUEST_URI'] = '/de/kontakt/?a=1';
		$this->assertSame( 'http://de.example.org/kontakt/?a=1', $this->redirect_of( 'swiftling_redirect_to_language_host' ) );
		$this->assertSame( 302, $this->redirect_status );

		$_SERVER['REQUEST_URI'] = '/de';
		$this->assertSame( 'http://de.example.org/', $this->redirect_of( 'swiftling_redirect_to_language_host' ) );

		$_SERVER['REQUEST_URI'] = '/kontakt/?lang=fr&a=1';
		$_GET                   = array(
			'lang' => 'fr',
			'a'    => '1',
		);
		$this->assertSame( 'http://fr.example.org/kontakt/?a=1', $this->redirect_of( 'swiftling_redirect_to_language_host' ) );
		$_GET = array();

		// Pages of the original, folders that are no language, and forms stay where they are.
		$_SERVER['REQUEST_URI'] = '/kontakt/';
		$this->assert_no_redirect( 'swiftling_redirect_to_language_host' );
		$_SERVER['REQUEST_URI'] = '/dental/';
		$this->assert_no_redirect( 'swiftling_redirect_to_language_host' );
		$_SERVER['REQUEST_URI']    = '/de/kontakt/';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assert_no_redirect( 'swiftling_redirect_to_language_host' );
	}

	/**
	 * A page whose own address is a language's old folder keeps its address.
	 */
	public function test_page_named_like_a_language_keeps_its_address() {
		$this->use_pretty_permalinks();
		$this->use_hosts( 'subdomain' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'de',
			)
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.org';
		$_SERVER['REQUEST_URI']    = '/de/';
		$this->assert_no_redirect( 'swiftling_redirect_to_language_host' );
	}

	/**
	 * The host of a language visitors do not see leads to the same page at the site address.
	 */
	public function test_host_of_a_hidden_language_leads_to_the_original() {
		$this->use_hosts( 'subdomain' );
		$this->set_languages(
			array(
				'de_DE' => array(
					'slug'    => 'de',
					'visible' => false,
				),
			),
			'subdomain'
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'de.example.org';
		$_SERVER['REQUEST_URI']    = '/kontakt/?a=1';
		$this->assertSame( 'http://example.org/kontakt/?a=1', $this->redirect_of( 'swiftling_redirect_to_language_host' ) );
	}

	/**
	 * After going back from hosts to folders, a language's former host still shows the site, so it
	 * leads to the same page in the language's folder instead of showing the original there.
	 */
	public function test_former_host_leads_to_the_folder() {
		$this->use_pretty_permalinks();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory',
			array( 'domains' => array( 'fr_FR' => 'example.fr' ) )
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/kontakt/?a=1';

		$_SERVER['HTTP_HOST'] = 'de.example.org';
		$this->assertSame( 'http://example.org/de/kontakt/?a=1', $this->redirect_of( 'swiftling_redirect_to_language_host' ) );
		$_SERVER['HTTP_HOST'] = 'example.fr';
		$this->assertSame( 'http://example.org/fr/kontakt/?a=1', $this->redirect_of( 'swiftling_redirect_to_language_host' ) );

		// The site's own host, and hosts that never were a language's, stay as they are.
		$_SERVER['HTTP_HOST'] = 'example.org';
		$this->assert_no_redirect( 'swiftling_redirect_to_language_host' );
		$_SERVER['HTTP_HOST'] = 'www.example.org';
		$this->assert_no_redirect( 'swiftling_redirect_to_language_host' );
	}

	/**
	 * WordPress's safe redirects may lead to the hosts of the languages.
	 */
	public function test_safe_redirects_reach_the_language_hosts() {
		$this->use_hosts( 'subdomain' );
		$this->assertSame( 'http://de.example.org/kontakt/', wp_validate_redirect( 'http://de.example.org/kontakt/', 'fallback' ) );
		$this->assertSame( 'fallback', wp_validate_redirect( 'http://it.example.org/', 'fallback' ) );
	}

	/**
	 * WP Rocket keeps a language's pages under its host, so its front page is emptied there.
	 */
	public function test_rocket_empties_the_front_page_on_the_languages_host() {
		require_once __DIR__ . '/stubs/cache-plugins.php';
		$this->use_hosts( 'subdomain' );
		$GLOBALS['swiftling_tests_purged'] = array();
		swiftling_purge_cache_plugins( array( 'http://de.example.org/' ), array() );

		$this->assertContains( array( 'rocket_clean_home', 'cache/de.example.org*' ), $GLOBALS['swiftling_tests_purged'] );
		$this->assertFalse( has_filter( 'rocket_clean_home_root' ) );
	}

	/**
	 * On a language's host, safe redirects may still lead to the site address, as a post hidden in
	 * the language sends visitors to the original. WordPress allows only the host of home_url(),
	 * which is the language's there, so it sent them to the dashboard instead.
	 */
	public function test_redirects_from_a_language_host_reach_the_site_address() {
		$this->use_hosts( 'subdomain' );
		$_SERVER['HTTP_HOST']   = 'de.example.org';
		$_SERVER['REQUEST_URI'] = '/wp-sitemap.xml';
		swiftling_maybe_start_translation();
		$this->assertSame( 'http://de.example.org/', home_url( '/' ), 'On the language\'s host' );

		$this->assertSame( 'http://example.org/kontakt/', wp_validate_redirect( 'http://example.org/kontakt/', 'fallback' ) );
		$this->assertSame( 'http://fr.example.org/', wp_validate_redirect( 'http://fr.example.org/', 'fallback' ) );
		$this->assertSame( 'fallback', wp_validate_redirect( 'http://other.org/', 'fallback' ) );
	}

	/* The check of the hosts. */

	/**
	 * A check opens the host over https, without following redirects, and knows this site by the
	 * signature only this site can give.
	 */
	public function test_check_of_a_host() {
		$asked = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$asked ) {
				$asked = array( $url, $args['redirection'] );
				return $pre;
			},
			5,
			3
		);
		$this->answer( 'de.example.org' );
		$this->assertSame( 'ok', swiftling_check_host( 'de.example.org' ) );
		$this->assertStringStartsWith( 'https://de.example.org/?swiftling_ping=', $asked[0] );
		$this->assertSame( 0, $asked[1] );
	}

	/**
	 * What a check tells from the answer.
	 *
	 * @dataProvider data_answers
	 *
	 * @param array|WP_Error $answer   Answer.
	 * @param string         $expected State.
	 */
	public function test_states_of_a_check( $answer, $expected ) {
		add_filter(
			'pre_http_request',
			static function () use ( $answer ) {
				return $answer;
			},
			10
		);
		$this->assertSame( $expected, swiftling_check_host( 'de.example.org' ) );
	}

	/**
	 * Answers and the state of the check each gives.
	 *
	 * @return array
	 */
	public function data_answers() {
		$answer = static function ( $code, $headers ) {
			return array(
				'headers'  => $headers,
				'body'     => '',
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		return array(
			'no such host'      => array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: de.example.org' ), 'no_answer' ),
			'timeout'           => array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), 'no_answer' ),
			'bad certificate'   => array( new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ), 'no_https' ),
			'no tls'            => array( new WP_Error( 'http_request_failed', 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL' ), 'no_https' ),
			'another site'      => array( $answer( 200, array() ), 'other_site' ),
			'a redirect'        => array( $answer( 301, array( 'location' => 'https://example.org/' ) ), 'other_site' ),
			'a wrong signature' => array( $answer( 200, array( 'x-swiftling-ping' => 'abc' ) ), 'other_site' ),
		);
	}

	/**
	 * The signature depends on the token and on a secret of this site, so another site cannot give it.
	 * The token has letters that are no hex digits, so the signature, hex, cannot hold it by chance.
	 */
	public function test_ping_signature() {
		$one = swiftling_ping_signature( 'token-xyz' );
		$this->assertSame( $one, swiftling_ping_signature( 'token-xyz' ) );
		$this->assertNotSame( $one, swiftling_ping_signature( 'token-xyw' ) );
		$this->assertStringNotContainsString( 'token-xyz', $one );

		delete_option( 'swiftling_ping_secret' );
		$this->assertNotSame( $one, swiftling_ping_signature( 'token-xyz' ), 'Another secret' );
	}

	/**
	 * Fonts at the site address must be allowed on the languages' hosts: themes such as Divi keep
	 * the address of their fonts in a stylesheet shared by every host. The check asks for a font
	 * of WordPress with a language's host as the origin.
	 *
	 * @dataProvider data_font_answers
	 *
	 * @param array  $headers  Headers of the answer.
	 * @param string $expected State.
	 */
	public function test_check_of_fonts( $headers, $expected ) {
		$this->use_hosts( 'subdomain' );
		$asked = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$asked, $headers ) {
				$asked = array( $url, $args['headers'] );
				return array(
					'headers'  => $headers,
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
		$this->assertSame( $expected, swiftling_check_fonts( 'de.example.org' ) );
		$this->assertSame( 'https://example.org/wp-includes/fonts/dashicons.woff2', $asked[0] );
		$this->assertSame( 'https://de.example.org', $asked[1]['Origin'] );
	}

	/**
	 * Answers to the check of fonts.
	 *
	 * @return array
	 */
	public function data_font_answers() {
		return array(
			'allowed for all'  => array( array( 'access-control-allow-origin' => '*' ), 'ok' ),
			'allowed for one'  => array( array( 'access-control-allow-origin' => 'https://de.example.org' ), 'ok' ),
			'allowed for none' => array( array(), 'blocked' ),
			'another origin'   => array( array( 'access-control-allow-origin' => 'https://other.org' ), 'blocked' ),
		);
	}

	/**
	 * Fonts that are not allowed on the other hosts get the lines for the server on the screen;
	 * allowed ones get nothing.
	 */
	public function test_screen_says_how_to_allow_fonts() {
		$this->use_hosts( 'subdomain' );
		$this->become( 'administrator' );
		$this->assertStringNotContainsString( 'Access-Control-Allow-Origin', $this->render_addresses() );

		$checks           = get_option( 'swiftling_address_checks' );
		$checks['#fonts'] = array(
			'state' => 'blocked',
			'at'    => time(),
		);
		update_option( 'swiftling_address_checks', $checks );
		$html = $this->render_addresses();
		$this->assertStringContainsString( 'Header set Access-Control-Allow-Origin', $html );
		$this->assertStringContainsString( 'add_header Access-Control-Allow-Origin', $html );
	}

	/**
	 * Saving a host format checks the fonts too, with the first language's host. Blocked fonts do
	 * not stop the switch: pages work, only some icons are missing.
	 */
	public function test_saving_checks_the_fonts() {
		$this->become( 'administrator' );
		$this->answer( 'de.example.org', 'fr.example.org' );
		// The site's server answers the font without allowing it elsewhere.
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				return false !== strpos( $url, 'dashicons' ) ? array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				) : $pre;
			},
			10,
			3
		);
		$this->post_form(
			'swiftling_save_url_format',
			array(
				'format'       => 'subdomain',
				'swiftling_do' => 'save',
			)
		);
		$this->redirect_of( 'swiftling_save_url_format' );
		$this->assertSame( 'subdomain', swiftling_saved_url_format() );
		$this->assertSame( 'blocked', get_option( 'swiftling_address_checks' )['#fonts']['state'] );
	}

	/* The Addresses screen. */

	/**
	 * Saving a host format checks every host first and switches only when all lead here.
	 */
	public function test_saving_a_host_format_needs_every_host_to_lead_here() {
		$this->become( 'administrator' );
		$this->answer( 'de.example.org' );
		$this->post_form(
			'swiftling_save_url_format',
			array(
				'format'       => 'subdomain',
				'swiftling_do' => 'save',
			)
		);
		$location = $this->redirect_of( 'swiftling_save_url_format' );

		$this->assertSame( 'parameter', swiftling_saved_url_format(), 'fr.example.org does not answer' );
		$this->assertStringContainsString( 'swiftling_notice=not_ready', $location );
		$this->assertStringContainsString( 'format=subdomain', $location );
		$checks = get_option( 'swiftling_address_checks' );
		$this->assertSame( 'ok', $checks['de.example.org']['state'] );
		$this->assertSame( 'no_answer', $checks['fr.example.org']['state'] );

		$this->answer( 'de.example.org', 'fr.example.org' );
		$this->post_form(
			'swiftling_save_url_format',
			array(
				'format'       => 'subdomain',
				'swiftling_do' => 'save',
			)
		);
		$location = $this->redirect_of( 'swiftling_save_url_format' );
		$this->assertSame( 'subdomain', swiftling_saved_url_format() );
		$this->assertStringContainsString( 'swiftling_notice=saved', $location );
	}

	/**
	 * Check again checks and keeps the domains, and changes nothing on the site.
	 */
	public function test_check_again_changes_nothing_on_the_site() {
		$this->become( 'administrator' );
		$this->answer( 'example.de', 'example.fr' );
		$this->post_form(
			'swiftling_save_url_format',
			array(
				'format'       => 'domain',
				'domains'      => array(
					'de_DE' => ' HTTPS://Example.DE/page/ ',
					'fr_FR' => 'example.fr',
				),
				'swiftling_do' => 'check',
			)
		);
		$location = $this->redirect_of( 'swiftling_save_url_format' );

		$this->assertSame( 'parameter', swiftling_saved_url_format() );
		$this->assertStringContainsString( 'format=domain', $location );
		$this->assertSame(
			array(
				'de_DE' => 'example.de',
				'fr_FR' => 'example.fr',
			),
			get_option( 'swiftling_languages' )['domains']
		);
		$this->assertSame( 'ok', get_option( 'swiftling_address_checks' )['example.de']['state'] );
	}

	/**
	 * A domain must be a domain, one of its own, and not the site's.
	 */
	public function test_domains_are_checked_as_typed() {
		$this->assertSame( 'example.de', swiftling_normalize_domain( 'https://Example.de:443/a/' ) );
		$this->assertSame( 'xn--bcher-kva.de', swiftling_normalize_domain( 'xn--bcher-kva.de' ) );
		$this->assertSame( '', swiftling_normalize_domain( 'not a domain' ) );
		$this->assertSame( '', swiftling_normalize_domain( 'localhost' ) );
		$this->assertSame( '', swiftling_normalize_domain( '' ) );

		$this->become( 'administrator' );
		$this->answer( 'example.de' );
		$this->post_form(
			'swiftling_save_url_format',
			array(
				'format'       => 'domain',
				'domains'      => array(
					'de_DE' => 'example.de',
					'fr_FR' => 'EXAMPLE.de',
				),
				'swiftling_do' => 'save',
			)
		);
		$location = $this->redirect_of( 'swiftling_save_url_format' );
		$this->assertSame( 'parameter', swiftling_saved_url_format() );
		$this->assertStringContainsString( 'swiftling_domain_error', $location );
		$this->assertStringContainsString( 'swiftling_typed%5Bfr_FR%5D=EXAMPLE.de', $location, 'What was typed shows again' );
		$this->assertArrayNotHasKey( 'fr_FR', get_option( 'swiftling_languages' )['domains'], 'Taken by Deutsch' );

		$this->post_form(
			'swiftling_save_url_format',
			array(
				'format'       => 'domain',
				'domains'      => array( 'de_DE' => 'example.org' ),
				'swiftling_do' => 'save',
			)
		);
		$this->redirect_of( 'swiftling_save_url_format' );
		$this->assertArrayNotHasKey( 'de_DE', get_option( 'swiftling_languages' )['domains'], 'The site address' );
	}

	/**
	 * Going back from a host format to folders needs no check.
	 */
	public function test_leaving_a_host_format_needs_no_check() {
		$this->use_hosts( 'subdomain' );
		$this->use_pretty_permalinks();
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_save_url_format', array( 'format' => 'directory' ) );
		$this->redirect_of( 'swiftling_save_url_format' );
		$this->assertSame( 'directory', swiftling_saved_url_format() );
	}

	/**
	 * Saving the Languages screen keeps the domains.
	 */
	public function test_saving_languages_keeps_the_domains() {
		$this->use_hosts( 'domain', array( 'de_DE' => 'example.de' ) );
		$this->become( 'administrator' );
		add_filter(
			'user_has_cap',
			static function ( $caps ) {
				unset( $caps['install_languages'] );
				return $caps;
			}
		);
		$this->post_form(
			'swiftling_save_languages',
			array(
				'targets' => array(
					'de_DE' => array(
						'slug'    => 'de',
						'visible' => '1',
					),
				),
			)
		);
		$this->redirect_of( 'swiftling_save_languages' );
		$this->assertSame( array( 'de_DE' => 'example.de' ), get_option( 'swiftling_languages' )['domains'] );
		$this->assertSame( 'domain', get_option( 'swiftling_languages' )['format'] );
	}

	/**
	 * Languages shows a language's address as its subdomain, and says which languages are not on
	 * the site because their host has not led here.
	 */
	public function test_languages_screen_in_the_subdomain_format() {
		$this->use_hosts( 'subdomain' );
		update_option(
			'swiftling_address_checks',
			array(
				'de.example.org' => array(
					'state' => 'ok',
					'at'    => time(),
				),
			)
		);
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		ob_start();
		( new Menu() )->renderScreen( 'languages' );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );

		$this->assertStringContainsString( '<code>.example.org</code>', $html );
		$this->assertStringNotContainsString( '<code>?lang=</code>', $html );
		$this->assertStringContainsString( 'Français is not on the site until its address leads here.', $html );
	}

	/**
	 * A domain with something wrong with it shows again as typed, next to what is wrong, also with
	 * the words in front of lists on the same screen.
	 */
	public function test_screen_shows_a_wrong_domain_as_typed() {
		$this->use_pretty_permalinks();
		$this->become( 'administrator' );
		$_GET = array(
			'format'                 => 'domain',
			'swiftling_domain_error' => array( 'fr_FR' => 'invalid' ),
			'swiftling_typed'        => array( 'fr_FR' => 'not a domain' ),
		);
		$this->assertNotEmpty( swiftling_base_lists(), 'The screen has words in front of lists too' );
		$html = $this->render_addresses();
		$this->assertStringContainsString( 'value="not a domain"', $html );
		$this->assertStringContainsString( 'This is not a domain.', $html );
	}

	/**
	 * The screen offers both host formats, the host of each language with its last check, and how
	 * to set it up.
	 */
	public function test_addresses_screen_shows_the_hosts() {
		$this->use_hosts( 'subdomain' );
		update_option(
			'swiftling_address_checks',
			array(
				'de.example.org' => array(
					'state' => 'ok',
					'at'    => time(),
				),
				'fr.example.org' => array(
					'state' => 'no_https',
					'at'    => time(),
				),
			)
		);
		$this->become( 'administrator' );
		$html = $this->render_addresses();

		$this->assertStringContainsString( 'value="subdomain"', $html );
		$this->assertStringContainsString( 'value="domain"', $html );
		$this->assertStringContainsString( 'de.example.org', $html );
		$this->assertStringContainsString( 'Leads here', $html );
		$this->assertStringContainsString( 'No secure certificate', $html );
		$this->assertStringContainsString( 'How to set it up', $html );
		$this->assertSame( "define( 'COOKIE_DOMAIN', '.example.org' );", swiftling_cookie_domain_line() );
		$this->assertStringContainsString( esc_html( swiftling_cookie_domain_line() ), $html );
		$this->assertStringContainsString( 'name="domains[de_DE]"', $html );

		// The addresses must show this site itself; a redirect at the host does not count.
		$this->assertStringContainsString( '<strong>Not a redirect: each address has to show this site itself.</strong>', $html );
		foreach ( array(
			'https://docs.cpanel.net/cpanel/domains/domains/create-a-new-domain/',
			'https://docs.plesk.com/en-US/obsidian/customer-guide/websites-and-domains/domains-and-dns/adding-domain-aliases.65286/',
			'https://www.siteground.com/kb/parked-domains/',
			'https://www.hostinger.com/support/1583404-how-to-park-a-domain-at-hostinger/',
		) as $guide ) {
			$this->assertStringContainsString( 'href="' . $guide . '"', $html );
		}
		// Save checks first, so it is never locked.
		$this->assertStringContainsString( 'Save changes checks every address first', $html );
		$this->assertStringNotContainsString( 'data-swiftling-ready', $html );
	}
}
