<?php
/**
 * Tests of the suggestion of the browser's language.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Switcher\Settings;
use SwiftLing\Switcher\Suggestion;

/**
 * What a page carries for the note, its texts in each language, and its settings.
 */
class SuggestionTest extends TestCase {

	/**
	 * A post translated into German, not into French.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Makes the post, on a site with language folders.
	 */
	public function set_up() {
		parent::set_up();
		$this->use_pretty_permalinks();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);
		Suggestion::forget();
		$this->post = self::factory()->post->create_and_get(
			array(
				'post_name'    => 'kontakt',
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		$post       = $this->post;
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );
		swiftling_refresh_translated( array( $post->ID ) );
	}

	/**
	 * Saves the note's settings.
	 *
	 * @param array $suggest Settings of the note.
	 */
	private function suggest( $suggest ) {
		update_option( Settings::OPTION, array( 'suggest' => $suggest ) );
		Settings::forget();
	}

	/**
	 * Returns what the page prints for the script, decoded.
	 *
	 * @return array|null
	 */
	private function data() {
		ob_start();
		( new Suggestion() )->printData();
		$html = (string) ob_get_clean();
		if ( '' === $html ) {
			return null;
		}
		$this->assertSame( 1, preg_match( '~^<script type="application/json" id="swiftling-suggestion-data">(.*)</script>$~s', trim( $html ), $json ) );
		return json_decode( $json[1], true );
	}

	/**
	 * The note is off until the Switcher screen turns it on: no script, no data.
	 */
	public function test_off_by_default() {
		$this->go_to( get_permalink( $this->post ) );
		$this->assertSame( array(), Suggestion::offers() );
		$this->assertNull( $this->data() );
		( new Suggestion() )->enqueue();
		$this->assertFalse( wp_script_is( 'swiftling-suggestion' ) );
	}

	/**
	 * A page offers the other languages it is in, with the texts of the note in each, keyed like
	 * its hreflang links.
	 */
	public function test_page_offers_its_languages() {
		$this->suggest(
			array(
				'enabled'  => true,
				'position' => 'right',
			)
		);
		$this->go_to( get_permalink( $this->post ) );
		$this->assertArrayHasKey( 'de-DE', swiftling_hreflangs() );
		$this->assertSame(
			array(
				'position' => 'right',
				'shown'    => 'hr',
				'offers'   => array(
					'de-DE' => array(
						'text'  => 'This page is also in Deutsch.',
						'show'  => 'Show',
						'close' => 'Close',
						'label' => 'Language suggestion',
						'lang'  => 'de-DE',
						'dir'   => 'ltr',
					),
				),
			),
			$this->data()
		);
		( new Suggestion() )->enqueue();
		$this->assertTrue( wp_script_is( 'swiftling-suggestion' ) );
		$this->assertTrue( wp_style_is( 'swiftling-suggestion' ) );

		// In German, the original is offered.
		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );
		$this->go_to( 'http://example.org/de/kontakt/' );
		$data = $this->data();
		$this->assertSame( 'de-DE', $data['shown'] );
		$this->assertSame( array( 'hr' ), array_keys( $data['offers'] ) );
	}

	/**
	 * A page in no other language offers nothing.
	 */
	public function test_untranslated_page() {
		$this->suggest( array( 'enabled' => true ) );
		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_excerpt' => '' ) ) ) );
		$this->assertNull( $this->data() );
	}

	/**
	 * Texts typed on the Switcher screen replace the defaults; an empty one keeps its default.
	 */
	public function test_own_texts() {
		$this->suggest(
			array(
				'enabled' => true,
				'texts'   => array(
					'de_DE' => array(
						'text' => 'Auch auf Deutsch!',
						'show' => '',
					),
				),
			)
		);
		$texts = Suggestion::texts( swiftling_target_language( 'de' ) );
		$this->assertSame( array( 'Auch auf Deutsch!', 'Show' ), array( $texts['text'], $texts['show'] ) );
	}

	/**
	 * A right-to-left custom language gets its direction; the defaults are kept until the languages
	 * or the switcher change.
	 */
	public function test_defaults() {
		$this->set_languages(
			array(
				'fa_AF' => array(
					'slug'   => 'prs',
					'name'   => 'دری',
					'custom' => array(
						'wp_locale' => 'en_US',
						'rtl'       => true,
					),
				),
			)
		);
		Suggestion::forget();
		$texts = Suggestion::defaults( swiftling_target_language( 'prs' ) );
		$this->assertSame( array( 'This page is also in دری.', 'fa-AF', 'rtl' ), array( $texts['text'], $texts['lang'], $texts['dir'] ) );
		$this->assertArrayHasKey( 'fa_AF', get_transient( 'swiftling_suggestion_texts' ) );

		update_option( Settings::OPTION, array( 'suggest' => array( 'enabled' => true ) ) );
		$this->assertFalse( get_transient( 'swiftling_suggestion_texts' ) );
	}

	/**
	 * The Switcher screen saves the note: on or off, a known position, and texts of known languages.
	 */
	public function test_saving() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_switcher',
			array(
				'suggest' => array(
					'mode'     => 'open',
					'position' => 'top',
					'texts'    => array(
						'de_DE' => array(
							'text' => '<b>Auch</b> auf Deutsch',
							'show' => '',
						),
						'fr_FR' => array(
							'text' => '',
							'show' => '',
						),
						'it_IT' => array( 'text' => 'Anche in italiano' ),
					),
				),
			)
		);
		$this->redirect_of(
			static function () {
				( new Settings() )->save();
			}
		);
		Settings::forget();
		$this->assertSame(
			array(
				'mode'     => 'open',
				'enabled'  => true,
				'position' => 'bar',
				'texts'    => array(
					'de_DE' => array(
						'text' => 'Auch auf Deutsch',
						'show' => '',
					),
				),
			),
			Settings::get()['suggest']
		);
	}

	/**
	 * What happens for a visitor whose browser prefers another language is one of three: nothing,
	 * the note, or the home page opened in it. A note saved before the choice existed stays on.
	 */
	public function test_modes() {
		$this->assertSame( 'off', Settings::get()['suggest']['mode'] );
		$this->suggest( array( 'enabled' => true ) );
		$this->assertSame( 'suggest', Settings::get()['suggest']['mode'] );
		$this->assertTrue( Settings::get()['suggest']['enabled'] );
		$this->suggest( array( 'mode' => 'open' ) );
		$this->assertSame( 'open', Settings::get()['suggest']['mode'] );
		$this->assertTrue( Settings::get()['suggest']['enabled'], 'Other pages still get the note' );
		$this->suggest( array( 'mode' => 'teleport' ) );
		$this->assertSame( 'off', Settings::get()['suggest']['mode'] );
		$this->assertFalse( Settings::get()['suggest']['enabled'] );
	}

	/**
	 * Opening the home page in the browser's language is a small script in the head, after the
	 * hreflang links it reads, on the home page alone and only in that mode. It knows the hosts of
	 * the site, so a visitor coming from the site itself stays, and it skips search engines.
	 */
	public function test_opening_script_on_the_home_page_only() {
		$print = static function () {
			ob_start();
			( new Suggestion() )->printOpening();
			return (string) ob_get_clean();
		};

		$this->suggest( array( 'mode' => 'open' ) );
		$this->go_to( home_url( '/' ) );
		$this->assertArrayHasKey( 'de-DE', swiftling_hreflangs() );
		$script = $print();
		$this->assertStringStartsWith( '<script', trim( $script ) );
		$this->assertStringContainsString( 'location.replace', $script );
		$this->assertStringContainsString( '"example.org"', $script );
		$this->assertMatchesRegularExpression( '/bot\|crawl/i', $script );
		$this->assertLessThan( 1536, strlen( $script ), 'Small, without its comments' );
		$this->assertStringNotContainsString( '/**', $script );
		$this->assertGreaterThan( has_action( 'wp_head', 'swiftling_print_hreflangs' ), has_action( 'wp_head', array( Suggestion::class, 'printOpening' ) ) );

		$this->go_to( get_permalink( $this->post ) );
		$this->assertSame( '', $print(), 'Another page gets the note instead' );

		$this->suggest( array( 'mode' => 'suggest' ) );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', $print(), 'The note alone' );
	}

	/**
	 * The Switcher screen offers the three choices for the visitor's language, the saved one
	 * chosen, in the fourth of its steps.
	 */
	public function test_screen_offers_the_three_choices() {
		$this->suggest( array( 'mode' => 'open' ) );
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		ob_start();
		( new \SwiftLing\Admin\Menu() )->renderScreen( 'switcher' );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );

		foreach ( array( 'off', 'suggest', 'open' ) as $mode ) {
			$this->assertStringContainsString( 'name="suggest[mode]" value="' . $mode . '"', $html );
		}
		$this->assertMatchesRegularExpression( '~name="suggest\[mode\]" value="open"\s+checked~', $html );
		$this->assertStringNotContainsString( 'suggest[enabled]', $html );
		foreach ( array( 'Where visitors find it', 'How it looks', 'Flags', 'The visitor&#039;s language' ) as $step ) {
			$this->assertStringContainsString( $step, $html );
		}

		// The preview has the menu, with the switcher in each style a menu can have.
		foreach ( array( 'dropdown', 'list', 'globe' ) as $style ) {
			$this->assertStringContainsString( 'data-swiftling-menu-style="' . $style . '"', $html );
		}
		$this->assertStringContainsString( 'swiftling-switcher__globe', $html );
	}

	/**
	 * The script stays small, without jQuery: under 2.5 KB, now that it also notes the language a
	 * visitor chose. The script that opens the home page is printed into it, see
	 * test_opening_script_on_the_home_page_only().
	 */
	public function test_script_is_small() {
		$script = (string) file_get_contents( SWIFTLING_DIR . 'assets/js/suggestion.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin.
		$this->assertLessThan( 2560, strlen( $script ) );
		$this->assertStringNotContainsString( 'jQuery', $script );
	}
}
