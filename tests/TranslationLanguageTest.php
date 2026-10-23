<?php
/**
 * Tests of the language a request shows its page in.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Deciding the language of a request, and the page's lang and direction.
 */
class TranslationLanguageTest extends TestCase {

	/**
	 * A request is translated only when its address names a language the site has.
	 */
	public function test_language_from_the_address() {
		$this->assertSame( '', swiftling_decide_translation_language() );
		$_GET['lang'] = 'de';
		$this->assertSame( 'de', swiftling_decide_translation_language() );
		$_GET['lang'] = 'it';
		$this->assertSame( '', swiftling_decide_translation_language() );
	}

	/**
	 * The admin, page checks and the REST API are never translated.
	 */
	public function test_requests_left_alone() {
		$_GET['lang'] = 'de';

		$_GET['swiftling_probe'] = 'marked';
		$this->assertSame( '', swiftling_decide_translation_language() );
		unset( $_GET['swiftling_probe'] );

		$_SERVER['REQUEST_URI'] = '/wp-json/swiftling/v1/probe?lang=de';
		$this->assertTrue( swiftling_is_rest_address() );
		$this->assertSame( '', swiftling_decide_translation_language(), 'SwiftLing\'s own routes never' );
		$_SERVER['REQUEST_URI'] = '/kontakt/?lang=de';
		$this->assertFalse( swiftling_is_rest_address() );

		$_GET['rest_route'] = '/wp/v2/posts';
		$this->assertTrue( swiftling_is_rest_address() );
		unset( $_GET['rest_route'] );

		set_current_screen( 'edit.php' );
		$this->assertSame( '', swiftling_decide_translation_language() );
		set_current_screen( 'front' );
	}

	/**
	 * A request of the REST API that reads is answered in the language it asks for, by the address
	 * of the language or by ?lang=; one that writes, or asks for what an editor edits, stays in the
	 * original, so no client saves a translation over the original.
	 */
	public function test_rest_reads_in_a_language() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$_SERVER['REQUEST_URI'] = '/de/wp-json/wp/v2/posts';
		$this->assertSame( 'de', swiftling_decide_translation_language() );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$_GET                   = array( 'lang' => 'de' );
		$this->assertSame( 'de', swiftling_decide_translation_language(), '?lang= in every address format' );
		$_GET                   = array( 'rest_route' => '/wp/v2/pages' );
		$_SERVER['REQUEST_URI'] = '/de/?rest_route=/wp/v2/pages';
		$this->assertSame( 'de', swiftling_decide_translation_language() );

		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = '/de/wp-json/wp/v2/posts/7?context=edit';
		$_GET['context']        = 'edit';
		$this->assertSame( '', swiftling_decide_translation_language(), 'What an editor edits' );
		$_GET                      = array();
		$_SERVER['REQUEST_URI']    = '/de/wp-json/wp/v2/posts/7';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( '', swiftling_decide_translation_language(), 'Writing' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/de/wp-json/swiftling/v1/translations';
		$this->assertSame( '', swiftling_decide_translation_language(), 'SwiftLing\'s own routes never' );
		$_SERVER['REQUEST_URI'] = '/de/wp-json/swiftling-pro/v1/texts';
		$this->assertSame( '', swiftling_decide_translation_language(), 'Nor those of its add-ons' );
		$_SERVER['REQUEST_URI'] = '/de/wp-json/swiftlingx/v1/posts';
		$this->assertSame( 'de', swiftling_decide_translation_language(), 'Another plugin\'s, whose name only starts so' );
	}

	/**
	 * A request of the REST API that writes, sent to a language's address, still reaches the API:
	 * the folder is kept so WordPress finds the route, and nothing is translated.
	 */
	public function test_rest_write_at_a_language_address_reaches_the_api() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/de/wp-json/wp/v2/comments';
		swiftling_maybe_start_translation();
		$this->assertSame( '', swiftling_mode() );
		$this->assertSame( 'de', swiftling_kept_folder(), 'WordPress finds the route behind the folder' );
		$folder = &swiftling_kept_folder();
		$folder = '';
		remove_filter( 'home_url', 'swiftling_filter_home_url', 1 );
	}

	/**
	 * A language hidden from visitors opens only for people who can edit content.
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
		$_GET['lang'] = 'de';
		$this->assertSame( '', swiftling_decide_translation_language() );
		$this->become( 'contributor' );
		$this->assertSame( 'de', swiftling_decide_translation_language() );
	}

	/**
	 * A pause puts a translated request in the original, texts and locale, until it resumes; pauses
	 * nest, and the values read meanwhile do not stay for the rest of the request.
	 */
	public function test_pause_puts_the_request_in_the_original() {
		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();
		$state                       = &swiftling_state();
		$state['values']['option|x'] = 'übersetzt';
		$this->assertSame( 'de_DE', get_locale() );
		// As wp-settings.php does once WordPress has loaded: it keeps what get_locale() returns.
		$GLOBALS['locale'] = get_locale(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As WordPress does; put back below.
		$site              = swiftling_site_locale();
		$this->assertSame( 'en_US', $site, 'The site\'s own locale, not the one WordPress keeps' );

		swiftling_pause_translation();
		$this->assertSame( '', swiftling_mode() );
		$this->assertSame( $site, get_locale() );
		$this->assertSame( array(), $state['values'] );
		$state['values']['option|x'] = 'original';

		swiftling_pause_translation();
		swiftling_resume_translation();
		$this->assertSame( '', swiftling_mode(), 'The outer pause still holds' );
		$this->assertSame( $site, get_locale() );

		swiftling_resume_translation();
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'de', $state['language'] );
		$this->assertSame( 'de_DE', get_locale() );
		$this->assertSame( 'übersetzt', $state['values']['option|x'] );
		$this->assertFalse( is_locale_switched() );

		swiftling_resume_translation();
		$this->assertSame( 'translate', swiftling_mode(), 'A resume without a pause changes nothing' );
		unset( $GLOBALS['locale'] );
	}

	/**
	 * A switch translates into a language outside a page, as for an email written in the admin:
	 * texts, the locale and the filters that translate, until it resumes; a pause nests inside it;
	 * the filters it added go again, those a translated page has stay; an unknown language is the
	 * original.
	 */
	public function test_switch_translates_into_a_language_until_resume() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Kontakt' ) );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktseite' );
		$this->reset_state();
		remove_filter( 'the_title', 'swiftling_filter_title', 1 );
		$site = swiftling_site_locale();

		swiftling_switch_translation( 'de' );
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'de_DE', get_locale() );
		$this->assertSame( 'Kontaktseite', get_the_title( $post ) );

		swiftling_pause_translation();
		$this->assertSame( 'Kontakt', get_the_title( $post ), 'A pause inside a switch: the original' );
		$this->assertSame( $site, get_locale(), 'In the site\'s locale, not the one switched to' );
		$this->assertSame( $site, swiftling_site_locale() );
		swiftling_resume_translation();
		$this->assertSame( 'de_DE', get_locale() );
		$this->assertSame( 'Kontaktseite', get_the_title( $post ) );

		swiftling_resume_translation();
		$this->assertSame( '', swiftling_mode() );
		$this->assertSame( $site, get_locale() );
		$this->assertFalse( is_locale_switched() );
		$this->assertFalse( has_filter( 'the_title', 'swiftling_filter_title' ), 'The filter it added goes again' );
		$this->assertSame( 'Kontakt', get_the_title( $post ) );

		// On a page in another language the page's filters stay.
		$this->show_in( 'de' );
		swiftling_switch_translation( 'de' );
		swiftling_resume_translation();
		$this->assertNotFalse( has_filter( 'the_title', 'swiftling_filter_title' ) );
		$this->assertSame( 'translate', swiftling_mode() );

		swiftling_switch_translation( 'it' );
		$this->assertSame( '', swiftling_mode(), 'Not a language of the site: the original' );
		swiftling_resume_translation();
		$this->assertSame( 'de', swiftling_state()['language'] );
	}

	/**
	 * The page says which language it is in: the target in a translated view, the original otherwise.
	 */
	public function test_lang_attribute() {
		$this->assertSame( 'hr', swiftling_page_language()['locale'] );
		$this->assertSame( 'lang="hr" dir="ltr"', swiftling_language_attributes( 'lang="en-US" dir="ltr"' ) );
		$this->show_in( 'de' );
		$this->assertSame( 'de_DE', swiftling_page_language()['locale'] );
		$this->assertSame( 'lang="de-DE"', swiftling_language_attributes( 'lang="en-US"' ) );
	}

	/**
	 * A custom language keeps its own code and direction, though WordPress runs in its close language.
	 */
	public function test_custom_language() {
		global $wp_locale;
		$this->set_languages(
			array(
				'fa_AF' => array(
					'slug'   => 'prs',
					'name'   => 'Dari',
					'custom' => array(
						'wp_locale' => 'en_US',
						'rtl'       => true,
					),
				),
			)
		);
		$this->show_in( 'prs' );
		$this->assertSame( 'lang="fa-AF"', swiftling_language_attributes( 'lang="en-US"' ) );

		$direction = $wp_locale->text_direction;
		swiftling_set_text_direction();
		$this->assertSame( 'rtl', $wp_locale->text_direction );
		$wp_locale->text_direction = $direction;
	}
}
