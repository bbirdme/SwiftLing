<?php
/**
 * Tests of the class of CookieYes.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\CookieYes;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeCookieYes.php';

/**
 * What SwiftLing does for CookieYes: tells it the language of the page, when it has its banner in
 * that language, and translates the texts of its earlier version, values of its settings.
 */
class CookieYesTest extends TestCase {

	/**
	 * The class, with the plugin's languages.
	 *
	 * @var FakeCookieYes
	 */
	private $cky;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		FakeCookieYes::install();
		$this->cky = new FakeCookieYes();
		$builders  = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof CookieYes ? $this->cky : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->cky->register();
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Asks the language of the page, as the plugin does: it answers its default language itself.
	 *
	 * @return mixed
	 */
	private static function asked() {
		return apply_filters( 'cky_current_language', 'en' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
	}

	/**
	 * A page in a language the plugin has its banner in shows it in that language, by the plugin's
	 * code: the whole locale where the plugin has it, as "pt-br", or else the language, as "de" for
	 * Swiss German. A language the plugin has not, as French here, and the original page show its
	 * default language, without an error.
	 */
	public function test_banner_in_the_language_of_the_page() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'de_CH' => 'ch',
				'pt_BR' => 'br',
				'fr_FR' => 'fr',
			)
		);
		$this->assertSame( 'en', self::asked(), 'The original page' );
		foreach ( array(
			'de' => 'de',
			'ch' => 'de',
			'br' => 'pt-br',
			'fr' => 'en',
		) as $slug => $code ) {
			$this->show_in( $slug );
			$this->assertSame( $code, self::asked(), $slug );
		}

		// A language added on the plugin's screen.
		$this->cky->languages[] = 'fr';
		$this->assertSame( 'fr', self::asked() );
	}

	/**
	 * The banner the plugin made for each language is its own to translate: none of its values is
	 * text.
	 */
	public function test_banner_templates_hold_no_text() {
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/' . CookieYes::TEMPLATE_OPTION ) );
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/' . CookieYes::TEMPLATE_OPTION . '/de/notice' ) );
		$this->assertNull( Registry::fieldHoldsText( 'options', 'option/cky_banner_templates' ), 'Another option' );
	}

	/**
	 * Only a site that runs the earlier version has the subject of its settings. It offers the texts of
	 * the banner, named, and the names and descriptions of the cookie categories, and no colour or
	 * link of a button. Translated, they show in the language; a link of a button is left to SwiftLing
	 * as any address.
	 */
	public function test_texts_of_the_earlier_version() {
		$this->assertSame( array(), Registry::subjects() );
		$this->assertNull( swiftling_subject( 'cookieyes-settings' ) );

		$this->cky->legacy = true;
		update_option(
			CookieYes::LEGACY_OPTION,
			array(
				'bar_heading_text' => 'Kolačići',
				'notify_message'   => 'Ova stranica koristi kolačiće. [cookie_button]',
				'button_1_text'    => 'Prihvati',
				'button_1_url'     => '#',
				'button_2_url'     => 'https://example.org/privatnost/',
				'button_6_text'    => 'Ne prodajte moje podatke',
				'background'       => '#FFF',
				'button_1_action'  => '#cookie_action_close_header',
			)
		);
		$term = self::factory()->term->create(
			array(
				'taxonomy'    => CookieYes::LEGACY_CATEGORIES,
				'name'        => 'Nužni',
				'description' => 'Nužni kolačići potrebni su za rad stranice.',
			)
		);

		$subject = swiftling_subject( 'cookieyes-settings' );
		$this->assertSame( 'CookieYes settings', $subject['title'] );
		$units = array();
		foreach ( swiftling_save_field_list( $subject )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		foreach ( array( 'Kolačići', 'Prihvati', 'Ne prodajte moje podatke', 'Nužni', 'Nužni kolačići potrebni su za rad stranice.' ) as $text ) {
			$this->assertArrayHasKey( $text, $units, $text );
		}
		$this->assertArrayNotHasKey( '#FFF', $units );
		$this->assertArrayNotHasKey( '#cookie_action_close_header', $units );
		$this->assertSame( 'Do not sell link (CCPA)', Registry::fieldLabel( 'options', $units['Ne prodajte moje podatke']['p'] ) );
		$this->assertSame( 'term:' . $term, $units['Nužni']['o'] );
		$this->assertNull( Registry::fieldHoldsText( 'options', 'option/' . CookieYes::LEGACY_OPTION . '/button_2_url' ) );
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/' . CookieYes::LEGACY_OPTION . '/background' ) );

		$this->translate( 'options', $units['Ne prodajte moje podatke']['p'], 'Ne prodajte moje podatke', 'de', 'Meine Daten nicht verkaufen' );
		$this->translate( 'options', $units['Kolačići']['p'], 'Kolačići', 'de', 'Cookies' );
		$this->show_in( 'de' );
		$shown = get_option( CookieYes::LEGACY_OPTION );
		$this->assertSame( 'Meine Daten nicht verkaufen', $shown['button_6_text'] );
		$this->assertSame( 'Cookies', $shown['bar_heading_text'] );
		$this->assertSame( '#FFF', $shown['background'] );
	}
}
