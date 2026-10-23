<?php
/**
 * Tests of machine translation in the translation editor.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Translation\Services;

/**
 * The editor offers machine translation with the service in use, and says clearly when the service
 * does not translate the language shown.
 */
class EditorMachineTest extends TestCase {

	/**
	 * The editor of a page in a language.
	 *
	 * @param string   $lang        Address slug of the language.
	 * @param string[] $unsupported Locales the service does not translate.
	 * @return string
	 */
	private function editor( $lang, $unsupported ) {
		$this->become( 'administrator' );
		$this->set_languages();
		FakeService::connect();
		FakeService::$unsupported = $unsupported;
		self::reset_static( Services::class, 'services' );
		$post = self::factory()->post->create( array( 'post_title' => 'Kontakt' ) );
		$_GET = array(
			'post' => (string) $post,
			'lang' => $lang,
		);
		return $this->render_screen( 'editor' );
	}

	/**
	 * When the service does not translate the language, the button is off, a tooltip over it says
	 * why, and a warning says it too, with the way to the Translation service screen.
	 */
	public function test_unsupported_language() {
		$html = $this->editor( 'de', array( 'de_DE' ) );

		$this->assertMatchesRegularExpression( '~<span class="swiftling-tip is-above[^"]*">\s*<button[^>]*id="swiftling-live-machine"[^>]*disabled~', $html );
		$this->assertMatchesRegularExpression( '~<span class="swiftling-tip__text" role="tooltip"[^>]*>Fake does not translate Deutsch\.~', $html );
		$this->assertMatchesRegularExpression( '~class="swiftling-notice is-warning[^"]*"[^>]*>.*?Fake does not translate Deutsch, so machine translation is off for this language\..*?href="[^"]*page=swiftling-service"~s', $html );
		$this->assertStringContainsString( 'aria-describedby="swiftling-live-machine-note"', $html );
	}

	/**
	 * When it translates the language, there is no warning and no tooltip.
	 */
	public function test_supported_language() {
		$html = $this->editor( 'fr', array( 'de_DE' ) );
		$this->assertStringContainsString( 'Translate empty fields with Fake', $html );
		$this->assertStringNotContainsString( 'does not translate', $html );
	}
}
