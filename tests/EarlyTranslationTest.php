<?php
/**
 * Tests of when a translated view starts.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * A translated view starts before the theme loads, so a theme that reads its settings while it
 * sets itself up, and keeps them, shows them translated. Divi 5.14 reads all its settings (et_divi)
 * on after_setup_theme and keeps them in a global; its footer text showed in the original.
 */
class EarlyTranslationTest extends TestCase {

	/**
	 * A setting a theme reads on after_setup_theme and keeps shows translated.
	 */
	public function test_settings_a_theme_keeps_from_its_setup_are_translated() {
		update_option( 'zz_theme_settings', array( 'footer_credits' => 'Izrađeno s ljubavlju u Zagrebu' ) );
		$post = self::factory()->post->create_and_get();
		$this->check(
			'post:' . $post->ID,
			static function () {
				get_option( 'zz_theme_settings' );
			}
		);
		$this->translate( 'options', 'option/zz_theme_settings/footer_credits', 'Izrađeno s ljubavlju u Zagrebu', 'de', 'Mit Liebe in Zagreb gemacht' );

		// The theme reads its settings once, while it sets itself up, as Divi does.
		$kept  = null;
		$theme = static function () use ( &$kept ) {
			$kept = get_option( 'zz_theme_settings' );
		};

		// A request for the page in German, from the moment WordPress sets the theme up.
		$this->reset_state();
		$_GET['lang'] = 'de';
		do_action( 'setup_theme' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own action, as WordPress fires it.
		$theme();

		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'Mit Liebe in Zagreb gemacht', $kept['footer_credits'] );
	}

	/**
	 * The original keeps its settings as they are: without a language in the address nothing starts.
	 */
	public function test_original_is_left_alone() {
		update_option( 'zz_theme_settings', array( 'footer_credits' => 'Izrađeno u Zagrebu' ) );
		$this->reset_state();
		do_action( 'setup_theme' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own action, as WordPress fires it.
		$this->assertSame( '', swiftling_mode() );
		$this->assertSame( 'Izrađeno u Zagrebu', get_option( 'zz_theme_settings' )['footer_credits'] );
	}
}
