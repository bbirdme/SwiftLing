<?php
/**
 * Tests of the languages of the site.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WPDieException;

/**
 * The languages the site is written in and translated into, and the Languages screen.
 */
class LanguagesTest extends TestCase {

	/**
	 * Saving the Languages screen downloads WordPress's translations of new languages. The tests
	 * have no internet, so users here may not install languages: the screen then names them.
	 */
	public function set_up() {
		parent::set_up();
		add_filter(
			'user_has_cap',
			static function ( $caps ) {
				unset( $caps['install_languages'] );
				return $caps;
			}
		);
	}

	/**
	 * Before the first setup, the original is the site's locale and there is nothing to translate into.
	 */
	public function test_without_setup_the_original_is_the_site_locale() {
		delete_option( 'swiftling_languages' );
		$languages = swiftling_languages();
		$this->assertSame( 'en_US', $languages['source']['locale'] );
		$this->assertSame( 'en', $languages['source']['slug'] );
		$this->assertSame( 'EN', $languages['source']['code'] );
		$this->assertSame( array(), $languages['targets'] );
		$this->assertSame( array( 'en_US' ), $languages['order'] );
		$this->assertSame( 'native', $languages['names'] );
	}

	/**
	 * Targets are keyed by their address slug and carry what every language carries.
	 */
	public function test_targets_are_keyed_by_slug() {
		$languages = swiftling_languages();
		$this->assertSame( array( 'de', 'fr' ), array_keys( $languages['targets'] ) );
		$this->assertSame( 'de_DE', $languages['targets']['de']['locale'] );
		$this->assertSame( 'DE', $languages['targets']['de']['code'] );
		$this->assertSame( 'de_DE', $languages['targets']['de']['wp_locale'] );
		$this->assertTrue( $languages['targets']['de']['visible'] );
		$this->assertFalse( $languages['targets']['de']['custom'] );
		$this->assertNull( $languages['targets']['de']['rtl'] );
	}

	/**
	 * The saved order wins; a locale it does not know is dropped and a language it misses comes last.
	 */
	public function test_order_follows_the_saved_order() {
		$saved          = get_option( 'swiftling_languages' );
		$saved['order'] = array( 'fr_FR', 'xx_XX', 'hr' );
		update_option( 'swiftling_languages', $saved );

		$languages = swiftling_languages();
		$this->assertSame( array( 'fr_FR', 'hr', 'de_DE' ), $languages['order'] );
		$this->assertSame( array( 'fr', 'de' ), array_keys( $languages['targets'] ) );

		$ordered = swiftling_ordered_languages();
		$this->assertSame( array( 'fr_FR', 'hr', 'de_DE' ), wp_list_pluck( $ordered, 'locale' ) );
		$this->assertSame( array( false, true, false ), wp_list_pluck( $ordered, 'source' ) );
	}

	/**
	 * A custom language runs WordPress in the close language it names and keeps its own direction.
	 */
	public function test_custom_language_runs_in_its_close_language() {
		$this->set_languages(
			array(
				'cnr' => array(
					'slug'   => 'cnr',
					'name'   => 'Crnogorski',
					'custom' => array(
						'wp_locale' => 'hr',
						'rtl'       => false,
					),
				),
			)
		);
		$language = swiftling_target_language( 'cnr' );
		$this->assertTrue( $language['custom'] );
		$this->assertSame( 'hr', $language['wp_locale'] );
		$this->assertFalse( $language['rtl'] );
		$this->assertSame( 'Crnogorski', $language['name'] );
	}

	/**
	 * A language the site is not translated into has no entry.
	 */
	public function test_unknown_target_is_null() {
		$this->assertNull( swiftling_target_language( 'it' ) );
		$this->assertNull( swiftling_target_language( '' ) );
	}

	/**
	 * Language codes are written the way locales are, or refused.
	 *
	 * @dataProvider data_locales
	 *
	 * @param string $typed    What a person typed.
	 * @param string $expected The locale, or empty when it is no language code.
	 */
	public function test_normalize_locale( $typed, $expected ) {
		$this->assertSame( $expected, swiftling_normalize_locale( $typed ) );
	}

	/**
	 * Language codes as people type them.
	 *
	 * @return array
	 */
	public function data_locales() {
		return array(
			'region with dash'     => array( 'es-mx', 'es_MX' ),
			'capitals'             => array( 'ES_MX', 'es_MX' ),
			'three letters'        => array( 'cnr', 'cnr' ),
			'script and region'    => array( 'sr-latn-rs', 'sr_Latn_RS' ),
			'wordpress variant'    => array( 'de_DE_formal', 'de_DE_formal' ),
			'numeric region'       => array( 'es-419', 'es_419' ),
			'spaces around'        => array( '  de  ', 'de' ),
			'empty'                => array( '', '' ),
			'one letter'           => array( 'x', '' ),
			'a word'               => array( 'german', '' ),
			'space inside'         => array( 'de DE', '' ),
			'longer than a column' => array( 'en_US_POSIX_x', '' ),
		);
	}

	/**
	 * The short code on chips is the language part of a slug or locale.
	 */
	public function test_language_code() {
		$this->assertSame( 'PT', swiftling_language_code( 'pt-br' ) );
		$this->assertSame( 'EN', swiftling_language_code( 'en_US' ) );
		$this->assertSame( 'DE', swiftling_language_code( 'de' ) );
	}

	/**
	 * The list of languages has WordPress.org's languages, English and the chosen custom ones, by English name.
	 */
	public function test_available_languages() {
		$this->set_languages(
			array(
				'cnr' => array(
					'slug'   => 'cnr',
					'name'   => 'Crnogorski',
					'custom' => array( 'wp_locale' => 'hr' ),
				),
			)
		);
		$all = swiftling_available_languages();
		$this->assertArrayHasKey( 'en_US', $all );
		$this->assertArrayHasKey( 'de_DE', $all );
		$this->assertArrayHasKey( 'cnr', $all );
		$this->assertSame( 'de', $all['de_DE']['slug'] );

		$english = wp_list_pluck( $all, 'english' );
		$sorted  = $english;
		uasort( $sorted, 'strcasecmp' );
		$this->assertSame( array_keys( $sorted ), array_keys( $english ) );

		$this->assertArrayNotHasKey( 'cnr', swiftling_available_languages( false ) );
	}

	/**
	 * A language is listed with its English name after its own, when they differ.
	 */
	public function test_option_label() {
		$this->assertSame(
			'Deutsch (German)',
			swiftling_language_option_label(
				array(
					'name'    => 'Deutsch',
					'english' => 'German',
				)
			)
		);
		$this->assertSame(
			'English (UK)',
			swiftling_language_option_label(
				array(
					'name'    => 'English (UK)',
					'english' => 'English (UK)',
				)
			)
		);
	}

	/**
	 * Languages are named in another language from PHP's language data, when the host has it.
	 */
	public function test_translated_language_name() {
		if ( ! class_exists( 'Locale' ) ) {
			$this->markTestSkipped( 'PHP has no intl extension.' );
		}
		$this->assertSame( 'Njemački', swiftling_translated_language_name( 'de_DE', 'hr', false ) );
		$this->assertStringContainsString( '(', swiftling_translated_language_name( 'es_MX', 'en_US', true ) );
		$this->assertSame( '', swiftling_translated_language_name( 'xyz', 'en_US', false ), 'A code intl does not know' );
	}

	/**
	 * Only people who manage the site may change its languages.
	 */
	public function test_saving_needs_manage_options() {
		$this->become( 'editor' );
		$this->post_form( 'swiftling_save_languages', array() );
		$this->expectException( WPDieException::class );
		swiftling_save_languages();
	}

	/**
	 * Adding a language from the list keeps the others, hides the new one from visitors and gives it its slug.
	 */
	public function test_adding_a_language() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_languages',
			$this->rows()
			+ array(
				'add'          => '1',
				'new_language' => 'it_IT',
				'names'        => 'both',
			)
		);
		$location = $this->redirect_of( 'swiftling_save_languages' );

		$this->assertStringContainsString( 'page=swiftling-languages', $location );
		$this->assertStringContainsString( 'swiftling_notice=saved', $location );
		$saved = get_option( 'swiftling_languages' );
		$this->assertSame( array( 'de_DE', 'fr_FR', 'it_IT' ), wp_list_pluck( $saved['targets'], 'locale' ) );
		$this->assertSame( 'it', $saved['targets'][2]['slug'] );
		$this->assertFalse( $saved['targets'][2]['visible'] );
		$this->assertSame( 'parameter', $saved['format'], 'The Addresses screen chooses the format.' );
		$this->assertSame( 'both', $saved['names'] );
		$this->assertSame( array( 'hr', 'de_DE', 'fr_FR', 'it_IT' ), $saved['order'] );
		// WordPress's own texts for the languages not installed yet could not be installed, and the
		// screen says so. The test data of WordPress has German already.
		$this->assertStringContainsString( 'swiftling_wp_missing=fr_FR,it_IT', $location );
	}

	/**
	 * A site translates into as many languages as it wants: a fifth and a sixth are added like the first.
	 */
	public function test_languages_have_no_limit() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'it_IT' => 'it',
				'es_ES' => 'es',
			)
		);
		$this->become( 'administrator' );
		foreach ( array( 'pt_PT', 'en_GB' ) as $locale ) {
			$this->post_form(
				'swiftling_save_languages',
				$this->rows()
				+ array(
					'add'          => '1',
					'new_language' => $locale,
				)
			);
			$this->assertStringNotContainsString( 'swiftling_notice=limit', $this->redirect_of( 'swiftling_save_languages' ) );
		}
		$this->post_form(
			'swiftling_save_languages',
			$this->rows()
			+ array(
				'add_custom' => '1',
				'custom'     => array(
					'name'      => 'Crnogorski',
					'locale'    => 'cnr',
					'wp_locale' => 'hr',
				),
			)
		);
		$this->redirect_of( 'swiftling_save_languages' );

		$this->assertSame( array( 'de_DE', 'fr_FR', 'it_IT', 'es_ES', 'pt_PT', 'en_GB', 'cnr' ), wp_list_pluck( get_option( 'swiftling_languages' )['targets'], 'locale' ) );

		// Saving the screen again keeps all seven.
		$this->post_form( 'swiftling_save_languages', $this->rows() );
		$this->redirect_of( 'swiftling_save_languages' );
		$this->assertCount( 7, swiftling_languages()['targets'] );
	}

	/**
	 * With many languages the screen still offers to add one, and counts nothing against a limit.
	 */
	public function test_screen_offers_a_language_beyond_four() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'it_IT' => 'it',
				'es_ES' => 'es',
			)
		);
		$this->become( 'administrator' );
		ob_start();
		( new \SwiftLing\Admin\Menu() )->renderScreen( 'languages' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="swiftling-new-language"', $html );
		$this->assertStringContainsString( 'swiftling-custom-language', $html );
		$this->assertStringNotContainsString( '4 of 4', $html );
	}

	/**
	 * The Languages screen has a Flag column with the choice the Switcher screen has. Each row posts
	 * flags[locale][country|image], and saving keeps what the Switcher screen saved for a language
	 * the form does not name, and every other setting of the switcher.
	 */
	public function test_flag_chosen_on_the_languages_screen() {
		$this->become( 'administrator' );
		update_option(
			'swiftling_switcher',
			array(
				'footer' => array(
					'enabled'  => true,
					'position' => 'end',
					'style'    => 'horizontal',
				),
				'flags'  => array(
					'en_GB' => array(
						'country' => 'au',
						'image'   => '',
					),
				),
			)
		);

		ob_start();
		( new \SwiftLing\Admin\Menu() )->renderScreen( 'languages' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="flags[de_DE][country]"', $html );
		$this->assertStringContainsString( 'name="flags[hr][image]"', $html, 'The original has its flag too' );

		$this->post_form(
			'swiftling_save_languages',
			$this->rows()
			+ array(
				'flags' => array(
					'de_DE' => array(
						'country' => 'at',
						'image'   => '',
					),
					'fr_FR' => array(
						'country' => '',
						'image'   => 'https://example.org/fr.png',
					),
					'xx_XX' => array(
						'country' => 'de',
						'image'   => '',
					),
				),
			)
		);
		$this->redirect_of( 'swiftling_save_languages' );

		$flags = get_option( 'swiftling_switcher' )['flags'];
		$this->assertSame( 'at', $flags['de_DE']['country'] );
		$this->assertSame( 'https://example.org/fr.png', $flags['fr_FR']['image'] );
		$this->assertArrayNotHasKey( 'xx_XX', $flags, 'A language the site does not have gets no flag' );
		$this->assertSame( 'au', $flags['en_GB']['country'], 'A language the form does not name keeps its flag' );
		$this->assertTrue( get_option( 'swiftling_switcher' )['footer']['enabled'], 'The other settings of the switcher stay' );
		$this->assertStringEndsWith( 'assets/flags/at.svg', \SwiftLing\Switcher\Flags::url( 'de_DE' ) );

		// Back on automatic: the choice goes. A form without the column leaves the flags alone.
		$this->post_form(
			'swiftling_save_languages',
			$this->rows() + array( 'flags' => array( 'de_DE' => array( 'country' => '' ) ) )
		);
		$this->redirect_of( 'swiftling_save_languages' );
		$this->assertArrayNotHasKey( 'de_DE', get_option( 'swiftling_switcher' )['flags'] );
		$this->post_form( 'swiftling_save_languages', $this->rows() );
		$this->redirect_of( 'swiftling_save_languages' );
		$this->assertSame( 'https://example.org/fr.png', get_option( 'swiftling_switcher' )['flags']['fr_FR']['image'] );
	}

	/**
	 * Removing a language is a button of the same form.
	 */
	public function test_removing_a_language() {
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_save_languages', $this->rows() + array( 'remove' => 'fr_FR' ) );
		$this->redirect_of( 'swiftling_save_languages' );
		$this->assertSame( array( 'de_DE' ), wp_list_pluck( get_option( 'swiftling_languages' )['targets'], 'locale' ) );
	}

	/**
	 * Two languages cannot share an address: the second gets its full locale.
	 */
	public function test_taken_slug_becomes_the_locale() {
		$this->become( 'administrator' );
		$rows                             = $this->rows();
		$rows['targets']['fr_FR']['slug'] = 'de';
		$this->post_form( 'swiftling_save_languages', $rows );
		$location = $this->redirect_of( 'swiftling_save_languages' );

		$this->assertStringContainsString( 'swiftling_notice=slug', $location );
		$this->assertSame( array( 'de', 'fr-fr' ), wp_list_pluck( get_option( 'swiftling_languages' )['targets'], 'slug' ) );
	}

	/**
	 * The original's own folder counts as taken too.
	 */
	public function test_target_cannot_take_the_originals_slug() {
		$this->become( 'administrator' );
		$rows                             = $this->rows();
		$rows['targets']['de_DE']['slug'] = 'hr';
		$this->post_form( 'swiftling_save_languages', $rows );
		$this->redirect_of( 'swiftling_save_languages' );
		$this->assertSame( 'de-de', get_option( 'swiftling_languages' )['targets'][0]['slug'] );
	}

	/**
	 * The original is fixed in the first setup: the form cannot add it as a target.
	 */
	public function test_original_is_never_a_target() {
		$this->become( 'administrator' );
		$rows                  = $this->rows();
		$rows['targets']['hr'] = array( 'slug' => 'hrv' );
		$this->post_form( 'swiftling_save_languages', $rows + array( 'source_slug' => '' ) );
		$this->redirect_of( 'swiftling_save_languages' );

		$saved = get_option( 'swiftling_languages' );
		$this->assertNotContains( 'hr', wp_list_pluck( $saved['targets'], 'locale' ) );
		$this->assertSame( 'hr', $saved['source']['locale'] );
		$this->assertSame( 'hr', $saved['source']['slug'] );
	}

	/**
	 * A custom language needs a name and a code; its close language falls back to English.
	 */
	public function test_adding_a_custom_language() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_languages',
			$this->rows() + array(
				'add_custom' => '1',
				'custom'     => array(
					'name'      => 'Crnogorski',
					'locale'    => 'CNR',
					'wp_locale' => 'hr',
					'rtl'       => '',
				),
			)
		);
		$this->redirect_of( 'swiftling_save_languages' );

		$language = swiftling_target_language( 'cnr' );
		$this->assertNotNull( $language );
		$this->assertTrue( $language['custom'] );
		$this->assertSame( 'hr', $language['wp_locale'] );
		$this->assertSame( 'Crnogorski', $language['name'] );
	}

	/**
	 * A custom language without a valid code is refused, and one the site has already too.
	 */
	public function test_invalid_custom_languages_are_refused() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_languages',
			$this->rows() + array(
				'add_custom' => '1',
				'custom'     => array(
					'name'   => 'Klingon',
					'locale' => 'not a code',
				),
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=custom_invalid', $this->redirect_of( 'swiftling_save_languages' ) );

		$this->post_form(
			'swiftling_save_languages',
			$this->rows() + array(
				'add_custom' => '1',
				'custom'     => array(
					'name'   => 'Deutsch again',
					'locale' => 'de-de',
				),
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=custom_exists', $this->redirect_of( 'swiftling_save_languages' ) );
		$this->assertCount( 2, get_option( 'swiftling_languages' )['targets'] );
	}

	/**
	 * A setting the form does not offer falls back to the default.
	 */
	public function test_unknown_choices_fall_back() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_languages',
			$this->rows() + array( 'names' => 'shouting' )
		);
		$this->redirect_of( 'swiftling_save_languages' );
		$saved = get_option( 'swiftling_languages' );
		$this->assertSame( 'native', $saved['names'] );
	}

	/**
	 * In the directory format, a page with a language's slug is reported, because the language takes its address.
	 */
	public function test_taken_directories() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'de',
			)
		);
		$this->assertSame( array( 'de' ), swiftling_taken_directories() );

		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->assertSame( array(), swiftling_taken_directories() );
	}

	/**
	 * The names of the languages written for each page language are saved by locale, trimmed and
	 * without tags. An empty one is left out, so the automatic name shows; so is one for a
	 * language the site does not have, also when the language was removed in the same save.
	 */
	public function test_names_in_each_language_are_saved() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_languages',
			$this->rows()
			+ array(
				'names'             => 'page',
				'remove'            => 'fr_FR',
				'name_translations' => array(
					'de_DE' => array(
						'hr'    => ' Njemački <b>jezik</b> ',
						'fr_FR' => 'Allemand',
						'xx_XX' => 'Nobody',
					),
					'hr'    => array(
						'de_DE' => 'Kroatisch',
						'hr'    => 'Hrvatski jezik',
					),
					'fr_FR' => array( 'hr' => 'Francuski' ),
					'it_IT' => array( 'hr' => 'Talijanski' ),
				),
			)
		);
		$this->redirect_of( 'swiftling_save_languages' );

		$saved = get_option( 'swiftling_languages' );
		$this->assertSame(
			array(
				'de_DE' => array( 'hr' => 'Njemački jezik' ),
				'hr'    => array( 'de_DE' => 'Kroatisch' ),
			),
			$saved['name_translations'],
			'A language is not named in itself here; its own name is in the table above'
		);
		$this->assertSame( array( 'de_DE' => array( 'hr' => 'Njemački jezik' ) ), array_intersect_key( swiftling_languages()['name_translations'], array( 'de_DE' => 1 ) ) );
	}

	/**
	 * Returns the rows of the Languages form for the languages the site has now.
	 *
	 * @return array
	 */
	private function rows() {
		$rows = array();
		foreach ( swiftling_languages()['targets'] as $slug => $language ) {
			$rows[ $language['locale'] ] = array(
				'slug'    => $slug,
				'visible' => $language['visible'] ? '1' : '',
				'label'   => $language['label'],
			);
		}
		return array( 'targets' => $rows );
	}

	/**
	 * The way to change the original language, by starting over, is below the table of languages:
	 * the name column is too narrow for a sentence and wrapped it in three lines.
	 */
	public function test_change_original_language_is_below_the_table() {
		$this->become( 'administrator' );
		$html = $this->render_screen( 'languages' );
		$this->assertSame( 1, substr_count( $html, 'Change the original language' ) );
		$this->assertGreaterThan( (int) strpos( $html, '</table>' ), (int) strpos( $html, 'Change the original language' ), 'After the table, not in a row' );
		$this->assertStringContainsString( 'admin.php?page=swiftling-settings#start-over', $html );
	}
}
