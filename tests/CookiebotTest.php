<?php
/**
 * Tests of the class of Cookiebot.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Cookiebot;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeCookiebot.php';

/**
 * What SwiftLing does for Cookiebot: tells it the language of the page, which the plugin hands to the
 * service's script, so the service shows its banner in that language.
 */
class CookiebotTest extends TestCase {

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		$cookiebot = new FakeCookiebot();
		$builders  = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Cookiebot ? $cookiebot : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$cookiebot->register();
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
	 * The plugin's setting of the language, a fixed one, none (the service picks) or WordPress's, is
	 * the language of the page on a page in a language, and stays as set on the original page.
	 */
	public function test_language_of_the_page() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'pt_BR' => 'br',
			)
		);
		foreach ( array( 'en', '', '_wp' ) as $setting ) {
			update_option( Cookiebot::OPTION, $setting );
			$this->reset_state();
			$this->assertSame( $setting, get_option( Cookiebot::OPTION ), 'The original page' );
			$this->show_in( 'de' );
			$this->assertSame( 'de', get_option( Cookiebot::OPTION ), $setting );
			$this->show_in( 'br' );
			$this->assertSame( 'pt', get_option( Cookiebot::OPTION ), $setting );
		}
	}
}
