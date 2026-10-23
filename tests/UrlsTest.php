<?php
/**
 * Tests of the addresses of translated pages.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Addresses in the parameter and the directory format, and links kept in the language.
 */
class UrlsTest extends TestCase {

	/**
	 * A network of sites does not use the host formats, whose hosts its sites have for themselves: a
	 * site saved in one shows its languages with the parameter. Run as a network: WP_MULTISITE=1.
	 */
	public function test_host_formats_on_a_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs as a network of sites: WP_MULTISITE=1.' );
		}
		foreach ( array( 'subdomain', 'domain' ) as $format ) {
			$this->set_languages( array( 'de_DE' => 'de' ), $format );
			$this->assertSame( 'parameter', swiftling_url_format(), $format );
			$this->assertSame( 'http://example.org/kontakt/?lang=de', swiftling_language_url( 'http://example.org/kontakt/', 'de' ), $format );
		}
	}

	/**
	 * Language folders need pretty permalinks; without them the parameter is used.
	 */
	public function test_format_needs_pretty_permalinks() {
		$this->assertSame( 'parameter', swiftling_url_format() );

		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->assertSame( 'directory', swiftling_saved_url_format() );
		$this->assertSame( 'parameter', swiftling_url_format(), 'Plain permalinks' );

		$this->set_permalink_structure( '/index.php/%postname%/' );
		$this->assertSame( 'parameter', swiftling_url_format(), 'index.php permalinks' );

		$this->use_pretty_permalinks();
		$this->assertSame( 'directory', swiftling_url_format() );
	}

	/**
	 * In the parameter format a language is ?lang= and the original keeps its address.
	 */
	public function test_parameter_addresses() {
		$this->assertSame( 'http://example.org/kontakt/?lang=de', swiftling_language_url( 'http://example.org/kontakt/', 'de' ) );
		$this->assertSame( 'http://example.org/kontakt/', swiftling_language_url( 'http://example.org/kontakt/', '' ) );
		$this->assertSame( '?lang=de', swiftling_language_address( 'de' ) );
		$this->assertSame( array(), swiftling_folders() );
	}

	/**
	 * In the directory format a language is a folder, and the original gets one when asked.
	 */
	public function test_directory_addresses() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->assertSame( 'http://example.org/de/kontakt/', swiftling_language_url( 'http://example.org/kontakt/', 'de' ) );
		$this->assertSame( 'http://example.org/kontakt/', swiftling_language_url( 'http://example.org/kontakt/', '' ) );
		$this->assertSame( '/de/', swiftling_language_address( 'de' ) );
		$this->assertSame( array( 'de' ), swiftling_folders() );

		$this->set_languages( array( 'de_DE' => 'de' ), 'directory', array( 'source_folder' => true ) );
		$this->assertSame( 'hr', swiftling_source_folder() );
		$this->assertSame( 'http://example.org/hr/kontakt/', swiftling_language_url( 'http://example.org/kontakt/', '' ) );
		$this->assertSame( array( 'hr', 'de' ), swiftling_folders() );
	}

	/**
	 * The folder goes right after the site address, whatever the scheme, and only on this site.
	 *
	 * @dataProvider data_folders
	 *
	 * @param string $url      Address.
	 * @param string $expected Address with the folder.
	 */
	public function test_add_language_directory( $url, $expected ) {
		$this->assertSame( $expected, swiftling_add_language_directory( $url, 'de' ) );
	}

	/**
	 * Addresses and where the folder goes in them.
	 *
	 * @return array
	 */
	public function data_folders() {
		return array(
			'page'               => array( 'http://example.org/kontakt/', 'http://example.org/de/kontakt/' ),
			'other scheme'       => array( 'https://example.org/kontakt/', 'https://example.org/de/kontakt/' ),
			'site address'       => array( 'http://example.org', 'http://example.org/de' ),
			'query on the site'  => array( 'http://example.org?s=a', 'http://example.org/de/?s=a' ),
			'anchor on the site' => array( 'http://example.org#top', 'http://example.org/de/#top' ),
			'without scheme'     => array( '//example.org/kontakt/', '//example.org/de/kontakt/' ),
			'path from the root' => array( '/kontakt/', '/de/kontakt/' ),
			'other site'         => array( 'https://other.org/kontakt/', 'https://other.org/kontakt/' ),
			'longer host'        => array( 'http://example.org.evil/kontakt/', 'http://example.org.evil/kontakt/' ),
			'host as a path'     => array( 'http://example.organic/kontakt/', 'http://example.organic/kontakt/' ),
		);
	}

	/**
	 * The language of a request comes from ?lang= or from the folder, and only for a language the site has.
	 */
	public function test_requested_language() {
		$_GET['lang'] = 'de';
		$this->assertSame( 'de', swiftling_requested_language() );
		$_GET['lang'] = 'it';
		$this->assertSame( '', swiftling_requested_language() );
		$_GET = array();

		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		foreach ( array(
			'/de/kontakt/' => 'de',
			'/de'          => 'de',
			'/de?x=1'      => 'de',
			'/dental/'     => '',
			'/kontakt/de/' => '',
			'/'            => '',
		) as $uri => $expected ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertSame( $expected, swiftling_requested_language(), $uri );
		}
	}

	/**
	 * Links to pages of the site follow the language of the page; everything else stays.
	 *
	 * @dataProvider data_links
	 *
	 * @param string $url      Link.
	 * @param string $expected Link on a German page.
	 */
	public function test_localize_url_in_directory_format( $url, $expected ) {
		$this->use_pretty_permalinks();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);
		$this->show_in( 'de' );
		$this->assertSame( $expected, swiftling_localize_url( $url ) );
	}

	/**
	 * Links and what a German page makes of them.
	 *
	 * @return array
	 */
	public function data_links() {
		return array(
			'absolute'          => array( 'http://example.org/kontakt/', 'http://example.org/de/kontakt/' ),
			'from the root'     => array( '/kontakt/', '/de/kontakt/' ),
			'with a query'      => array( '/kontakt/?a=1', '/de/kontakt/?a=1' ),
			'admin'             => array( 'http://example.org/wp-admin/', 'http://example.org/wp-admin/' ),
			'upload'            => array( '/wp-content/uploads/a.jpg', '/wp-content/uploads/a.jpg' ),
			'file'              => array( '/price-list.pdf', '/price-list.pdf' ),
			'feed'              => array( '/feed/', '/feed/' ),
			'login'             => array( '/wp-login.php', '/wp-login.php' ),
			'rest api'          => array( '/wp-json/wp/v2/posts', '/wp-json/wp/v2/posts' ),
			'other site'        => array( 'https://other.org/kontakt/', 'https://other.org/kontakt/' ),
			'protocol relative' => array( '//other.org/x/', '//other.org/x/' ),
			'mail'              => array( 'mailto:info@example.org', 'mailto:info@example.org' ),
			'anchor'            => array( '#top', '#top' ),
			'relative path'     => array( 'kontakt/', 'kontakt/' ),
			'already german'    => array( '/de/kontakt/', '/de/kontakt/' ),
			'already french'    => array( '/fr/kontakt/', '/fr/kontakt/' ),
			'named language'    => array( '/kontakt/?lang=fr', '/kontakt/?lang=fr' ),
			'other scheme'      => array( 'ftp://example.org/x', 'ftp://example.org/x' ),
		);
	}

	/**
	 * In the parameter format links get ?lang=, and outside a translated page nothing changes.
	 */
	public function test_localize_url_in_parameter_format() {
		$this->assertSame( '/kontakt/', swiftling_localize_url( '/kontakt/' ), 'Not translating' );
		$this->show_in( 'de' );
		$this->assertSame( '/kontakt/?lang=de', swiftling_localize_url( '/kontakt/' ) );
	}

	/**
	 * Only the href of links in stored HTML changes, with entities read and written correctly.
	 */
	public function test_localize_links() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->show_in( 'de' );
		$html = '<p><a href="/kontakt/?a=1&amp;b=2">Kontakt</a> <a href=\'/o-nama/\'>O nama</a> <img src="/slika.jpg"> <a href="https://other.org/">x</a></p>';
		$this->assertSame(
			'<p><a href="/de/kontakt/?a=1&amp;b=2">Kontakt</a> <a href=\'/de/o-nama/\'>O nama</a> <img src="/slika.jpg"> <a href="https://other.org/">x</a></p>',
			swiftling_localize_links( $html )
		);
		$this->assertSame( 'No links here.', swiftling_localize_links( 'No links here.' ) );
	}

	/**
	 * Custom links of a classic menu follow the language; links WordPress builds already do.
	 */
	public function test_localize_menu_items() {
		$this->show_in( 'de' );
		$custom = (object) array(
			'type' => 'custom',
			'url'  => '/kontakt/',
		);
		$page   = (object) array(
			'type' => 'post_type',
			'url'  => '/o-nama/',
		);
		swiftling_localize_menu_items( array( $custom, $page ) );
		$this->assertSame( '/kontakt/?lang=de', $custom->url );
		$this->assertSame( '/o-nama/', $page->url );
	}

	/**
	 * While a folder is kept, WordPress builds every address with it, except the REST API and files.
	 */
	public function test_kept_folder_reaches_addresses_wordpress_builds() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		swiftling_keep_folder( 'de' );

		$this->assertSame( 'http://example.org/de/kontakt/', home_url( '/kontakt/' ) );
		$this->assertSame( 'http://example.org/wp-json/wp/v2/', home_url( '/wp-json/wp/v2/' ) );
		$this->assertSame( 'http://example.org/wp-login.php', home_url( '/wp-login.php' ) );

		$unlocalized = swiftling_unlocalized_url(
			static function () {
				return home_url( '/kontakt/' );
			}
		);
		$this->assertSame( 'http://example.org/kontakt/', $unlocalized );
		$this->assertSame( 'http://example.org/de/a/', home_url( '/a/' ), 'The folder is kept again afterwards' );
	}

	/**
	 * The address of the page being shown loses its language, keeping the rest of the request.
	 */
	public function test_current_original_url() {
		$this->assertSame( 'http://example.org/', swiftling_current_original_url(), 'Without a request' );

		$_SERVER['REQUEST_URI'] = '/kontakt/?lang=de&x=1';
		$this->assertSame( 'http://example.org/kontakt/?x=1', swiftling_current_original_url() );
		$this->assertSame( 'http://example.org/kontakt/?x=1&lang=fr', swiftling_current_url( 'fr' ) );

		$this->use_pretty_permalinks();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);
		$_SERVER['REQUEST_URI'] = '/de/kontakt/?x=1';
		$this->assertSame( 'http://example.org/kontakt/?x=1', swiftling_current_original_url() );
		$this->assertSame( 'http://example.org/fr/kontakt/?x=1', swiftling_current_url( 'fr' ) );
		$_SERVER['REQUEST_URI'] = '/de';
		$this->assertSame( 'http://example.org/', swiftling_current_original_url() );
	}

	/**
	 * An address without a folder goes to the original's folder, temporarily, and only for pages.
	 */
	public function test_redirect_to_the_originals_folder() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory', array( 'source_folder' => true ) );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/kontakt/?a=1';
		$this->assertSame( 'http://example.org/hr/kontakt/?a=1', $this->redirect_of( 'swiftling_redirect_to_source_folder' ) );
		$this->assertSame( 302, $this->redirect_status );

		// Already in a folder, or a form being sent: nothing happens.
		$_SERVER['REQUEST_URI'] = '/hr/kontakt/';
		$this->assert_no_redirect( 'swiftling_redirect_to_source_folder' );
		$_SERVER['REQUEST_URI']    = '/kontakt/';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assert_no_redirect( 'swiftling_redirect_to_source_folder' );
	}
}
