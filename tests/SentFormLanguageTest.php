<?php
/**
 * Tests of the language of a form sent through admin-ajax.php.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Forms\Formidable;

require_once __DIR__ . '/stubs/FakeFormidable.php';
require_once __DIR__ . '/stubs/FakeSendingBuilder.php';

/**
 * A form a visitor sends from a page in another language through admin-ajax.php is answered in
 * that language: the language of the page it was sent from. Other Ajax requests stay in the
 * original.
 */
class SentFormLanguageTest extends TestCase {

	/**
	 * Gives the registry back its own builders, which a test replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		remove_all_filters( 'wp_doing_ajax' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Puts an active form plugin on the site: Formidable Forms, without the plugin.
	 */
	private function with_form_plugin() {
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Formidable ? new FakeFormidable() : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
	}

	/**
	 * Makes the request one to admin-ajax.php, as the browser sends it from a page.
	 *
	 * @param string $action  Action of the request.
	 * @param string $referer Page the browser names, or an empty string for none.
	 */
	private function ajax( $action, $referer ) {
		add_filter( 'wp_doing_ajax', '__return_true' );
		set_current_screen( 'admin-ajax' );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
		$_POST['action']        = $action;
		$_REQUEST['action']     = $action;
		unset( $_SERVER['HTTP_REFERER'] );
		if ( '' !== $referer ) {
			$_SERVER['HTTP_REFERER'] = $referer;
		}
		$decided = &swiftling_decided_language();
		$decided = null;
	}

	/**
	 * The language of an address, in each format: as the request for it would decide it.
	 */
	public function test_language_of_an_address() {
		foreach ( array(
			'http://example.org/kontakt/?lang=de'   => 'de',
			'/kontakt/?lang=fr&x=1'                 => 'fr',
			'http://example.org/kontakt/?lang=it'   => '',
			'http://example.org/kontakt/'           => '',
			'http://example.org/de/kontakt/'        => '',
			'http://other.example/kontakt/?lang=de' => '',
			''                                      => '',
		) as $url => $expected ) {
			$this->assertSame( $expected, swiftling_address_language( $url ), 'Parameter: ' . $url );
		}

		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		foreach ( array(
			'http://example.org/de/kontakt/'      => 'de',
			'/de/kontakt/?nc=1'                   => 'de',
			'http://example.org/de'               => 'de',
			'http://example.org/kontakt/?lang=de' => '',
			'http://example.org/deutsch/'         => '',
			'http://example.org/fr/kontakt/'      => '',
			'http://other.example/de/kontakt/'    => '',
		) as $url => $expected ) {
			$this->assertSame( $expected, swiftling_address_language( $url ), 'Directory: ' . $url );
		}

		if ( is_multisite() ) {
			return; // A network of sites does not use the host formats.
		}
		$this->set_languages( array( 'de_DE' => 'de' ), 'subdomain' );
		update_option(
			'swiftling_address_checks',
			array(
				'de.example.org' => array(
					'state' => 'ok',
					'at'    => time(),
				),
			)
		);
		$_SERVER['HTTP_HOST'] = 'example.org';
		$this->assertSame( 'de', swiftling_address_language( 'http://de.example.org/kontakt/' ) );
		$this->assertSame( '', swiftling_address_language( 'http://example.org/kontakt/?lang=de' ) );
		$this->assertSame( '', swiftling_address_language( '/kontakt/' ), 'A path is on the host of the request' );
		$_SERVER['HTTP_HOST'] = 'de.example.org';
		$this->assertSame( 'de', swiftling_address_language( '/kontakt/' ) );
	}

	/**
	 * A form sent through admin-ajax.php from a page in another language runs in that language,
	 * from the page the form or the browser names, and the translation starts for it.
	 */
	public function test_form_sent_from_a_page_in_another_language() {
		$this->with_form_plugin();

		$this->ajax( 'frm_entries_create', 'http://example.org/kontakt/?lang=de' );
		$this->assertSame( 'de', swiftling_decide_translation_language() );
		swiftling_maybe_start_translation();
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'de_DE', get_locale(), 'WordPress and the plugin run in the language too' );
		$this->reset_state();

		// The form names its page itself, as one with a nonce field does; that wins over the browser's.
		$this->ajax( 'frm_entries_create', 'http://example.org/kontakt/' );
		$_REQUEST['_wp_http_referer'] = '/kontakt/?lang=fr';
		$this->assertSame( 'fr', swiftling_decide_translation_language() );
		unset( $_REQUEST['_wp_http_referer'] );

		$this->ajax( 'frm_entries_create', 'http://example.org/kontakt/' );
		$this->assertSame( '', swiftling_decide_translation_language(), 'Sent from the original' );
		swiftling_maybe_start_translation();
		$this->assertSame( '', swiftling_mode() );

		$this->ajax( 'frm_entries_create', '' );
		$this->assertSame( '', swiftling_decide_translation_language(), 'No page named: the original' );

		$this->ajax( 'frm_entries_create', 'http://other.example/kontakt/?lang=de' );
		$this->assertSame( '', swiftling_decide_translation_language(), 'Another site\'s page' );
	}

	/**
	 * Other Ajax requests stay in the original, from any page: they read and save settings.
	 */
	public function test_other_ajax_requests_stay_in_the_original() {
		$this->with_form_plugin();
		foreach ( array( 'heartbeat', 'frm_forms_preview', 'save-widget', '' ) as $action ) {
			$this->ajax( $action, 'http://example.org/kontakt/?lang=de' );
			$this->assertSame( '', swiftling_decide_translation_language(), $action );
			swiftling_maybe_start_translation();
			$this->assertSame( '', swiftling_mode(), $action );
		}

		// Formidable not on the site: its action means nothing.
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		$this->ajax( 'frm_entries_create', 'http://example.org/kontakt/?lang=de' );
		$this->assertSame( '', swiftling_decide_translation_language() );
	}

	/**
	 * A form sent to the REST API, as Contact Form 7 sends one, runs in the language of its page
	 * when the route is one a form plugin names; other REST requests stay in the original.
	 */
	public function test_form_sent_to_the_rest_api() {
		self::set_static( Registry::class, 'builders', Registry::all() + array( 'fakesending' => new FakeSendingBuilder() ) );
		self::reset_static( Registry::class, 'active' );
		$_SERVER['HTTP_REFERER'] = 'http://example.org/kontakt/?lang=de';
		foreach ( array(
			'/wp-json/contact-form-7/v1/contact-forms/1512/feedback' => 'de',
			'/wp-json/contact-form-7/v1/contact-forms/1512/feedback/' => 'de',
			'/wp-json/contact-form-7/v1/contact-forms/1512/refill' => '',
			'/wp-json/wp/v2/posts' => '',
		) as $uri => $expected ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertSame( $expected, swiftling_decide_translation_language(), $uri );
		}

		$_SERVER['REQUEST_URI'] = '/';
		$_GET['rest_route']     = '/contact-form-7/v1/contact-forms/1512/feedback';
		$this->assertSame( 'de', swiftling_decide_translation_language(), 'The route as a parameter' );
		swiftling_maybe_start_translation();
		$this->assertSame( 'translate', swiftling_mode() );

		$this->reset_state();
		$_SERVER['HTTP_REFERER'] = 'http://example.org/kontakt/';
		$decided                 = &swiftling_decided_language();
		$decided                 = null;
		$this->assertSame( '', swiftling_decide_translation_language(), 'Sent from the original' );
	}

	/**
	 * A language hidden from visitors opens for a sent form only for people who can edit content,
	 * as its pages do.
	 */
	public function test_hidden_language() {
		$this->set_languages(
			array(
				'de_DE' => array(
					'slug'    => 'de',
					'visible' => false,
				),
			)
		);
		$this->with_form_plugin();
		$this->ajax( 'frm_entries_create', 'http://example.org/kontakt/?lang=de' );
		$this->assertSame( '', swiftling_decide_translation_language() );
		$this->become( 'contributor' );
		$this->ajax( 'frm_entries_create', 'http://example.org/kontakt/?lang=de' );
		$this->assertSame( 'de', swiftling_decide_translation_language() );
	}

	/**
	 * The classes of the form plugins send the owner's emails in the original and read whom an email
	 * writes to only through SentForms, so every plugin follows the same rule and a fix reaches all.
	 */
	public function test_form_plugins_share_the_rules_of_a_form_sent() {
		$dir = dirname( __DIR__ ) . '/src/Forms/';
		foreach ( (array) glob( $dir . '*.php' ) as $file ) {
			if ( 'SentForms.php' === basename( $file ) ) {
				continue;
			}
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin itself.
			foreach ( array( 'swiftling_pause_translation(', 'swiftling_resume_translation(', "preg_split( '/[,;]+/'" ) as $own ) {
				$this->assertStringNotContainsString( $own, $code, basename( $file ) . ' uses SentForms instead of its own ' . $own );
			}
		}
	}

	/**
	 * Before the plugins load, which actions send a form is not known yet: the language waits.
	 */
	public function test_waits_for_the_plugins() {
		$this->with_form_plugin();
		$this->ajax( 'frm_entries_create', 'http://example.org/kontakt/?lang=de' );
		$loaded = $GLOBALS['wp_actions']['plugins_loaded'];
		unset( $GLOBALS['wp_actions']['plugins_loaded'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As if the plugins had not loaded yet; put back below.
		$this->assertNull( swiftling_decide_translation_language() );
		$this->assertSame( '', swiftling_translation_language(), 'Not decided yet: the original, and not kept' );
		$GLOBALS['wp_actions']['plugins_loaded'] = $loaded; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Put back.
		$this->assertSame( 'de', swiftling_translation_language() );
	}
}
