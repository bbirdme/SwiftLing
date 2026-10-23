<?php
/**
 * Tests of the words that are never translated.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use SwiftLing\Translation\Services;

/**
 * The list on the Translation service screen, and which words of a text it keeps.
 */
class NeverWordsTest extends TestCase {

	/**
	 * Without a list nothing is kept; a list keeps whole words, with their letters as written.
	 */
	public function test_words_in_a_text() {
		$this->assertSame( array(), Services::neverWords() );
		update_option( 'swiftling_never_words', array( 'Lavanda', 'Apartmani Marija', 'Kaštel Gomilica' ) );
		$this->assertSame( array( 'Lavanda', 'Kaštel Gomilica' ), Services::wordsIn( 'Apartman Lavanda nudi pogled na more u Kaštel Gomilica.', false ) );
		$this->assertSame( array(), Services::wordsIn( 'Miris lavanda i ružmarina.', false ) );
		$this->assertSame( array(), Services::wordsIn( 'Lavandaxyz', false ) );
		$this->assertSame( array( 'Apartmani Marija' ), Services::wordsIn( 'Rezervirajte kod Apartmani Marija!', false ) );
		// Inside a tag, as in an image's alt, a word is left alone: keeping it there would break the tag.
		$this->assertSame( array(), Services::wordsIn( '<img alt="Lavanda" src="a.jpg"> Pogled', true ) );
		$this->assertSame( array( 'Lavanda' ), Services::wordsIn( '<p><strong>Lavanda</strong> uz more</p>', true ) );
	}

	/**
	 * The screen adds words, trimmed and once each, and removes them; only for those who manage the site.
	 */
	public function test_saving_the_list() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_never_words',
			array(
				'word' => '  Lavanda ',
			)
		);
		$this->assertStringContainsString( 'page=swiftling-service', $this->redirect_of( 'swiftling_save_never_words' ) );
		$this->post_form( 'swiftling_never_words', array( 'word' => 'Ružmarin' ) );
		$this->redirect_of( 'swiftling_save_never_words' );
		$this->post_form( 'swiftling_never_words', array( 'word' => 'Lavanda' ) );
		$this->redirect_of( 'swiftling_save_never_words' );
		$this->post_form( 'swiftling_never_words', array( 'word' => '<b></b>' ) );
		$this->redirect_of( 'swiftling_save_never_words' );
		$this->assertSame( array( 'Lavanda', 'Ružmarin' ), Services::neverWords() );

		$this->post_form( 'swiftling_never_words', array( 'remove' => 'Lavanda' ) );
		$this->redirect_of( 'swiftling_save_never_words' );
		$this->assertSame( array( 'Ružmarin' ), Services::neverWords() );

		$this->become( 'editor' );
		$this->post_form( 'swiftling_never_words', array( 'word' => 'Split' ) );
		$this->expectException( \WPDieException::class );
		swiftling_save_never_words();
	}

	/**
	 * Once a service is connected, its screen shows the list with a field to add a word.
	 */
	public function test_screen() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		update_option( 'swiftling_never_words', array( 'Lavanda' ) );
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		ob_start();
		( new Menu() )->renderScreen( 'service' );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );
		$this->assertStringContainsString( 'Words that are never translated', $html );
		$this->assertStringContainsString( 'value="swiftling_save_never_words"', $html );
		$this->assertMatchesRegularExpression( '~name="remove" value="Lavanda"~', $html );
	}

	/**
	 * Starting over, removing everything, removes the list.
	 */
	public function test_start_over_removes_the_list() {
		update_option( 'swiftling_never_words', array( 'Lavanda' ) );
		require_once SWIFTLING_DIR . 'includes/setup.php';
		swiftling_remove_everything();
		$this->assertFalse( get_option( 'swiftling_never_words' ) );
	}
}
