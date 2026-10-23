<?php
/**
 * Tests of the first setup and starting over.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WPDieException;

/**
 * The first setup, which asks for the original language, and starting over.
 */
class SetupTest extends TestCase {

	/**
	 * The setup is answered once the original language is saved.
	 */
	public function test_is_set_up() {
		$this->assertTrue( swiftling_is_set_up() );
		delete_option( 'swiftling_languages' );
		$this->assertFalse( swiftling_is_set_up() );
	}

	/**
	 * Right after activation the setup opens once, for someone who may answer it.
	 */
	public function test_setup_opens_once_after_activation() {
		delete_option( 'swiftling_languages' );
		swiftling_note_activation();
		$this->become( 'administrator' );
		$this->assertSame( swiftling_setup_url(), $this->redirect_of( 'swiftling_open_setup_after_activation' ) );
		$this->assertFalse( get_option( 'swiftling_setup_redirect' ) );
		$this->assert_no_redirect( 'swiftling_open_setup_after_activation' );

		swiftling_note_activation();
		$_GET['activate-multi'] = 'true';
		$this->assert_no_redirect( 'swiftling_open_setup_after_activation' );
	}

	/**
	 * A request in the background, as an Ajax request a plugin runs after the activation, or a screen
	 * of someone who may not set SwiftLing up, leaves the first setup to open on the next screen of
	 * someone who may (#262).
	 */
	public function test_setup_waits_for_a_screen_of_a_manager() {
		delete_option( 'swiftling_languages' );
		swiftling_note_activation();

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->become( 'administrator' );
		$this->assert_no_redirect( 'swiftling_open_setup_after_activation' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertSame( '1', (string) get_option( 'swiftling_setup_redirect' ), 'Kept after an Ajax request' );

		$this->become( 'editor' );
		$this->assert_no_redirect( 'swiftling_open_setup_after_activation' );
		$this->assertSame( '1', (string) get_option( 'swiftling_setup_redirect' ), 'Kept after an editor\'s screen' );

		$this->become( 'administrator' );
		$this->assertSame( swiftling_setup_url(), $this->redirect_of( 'swiftling_open_setup_after_activation' ) );
		$this->assertFalse( get_option( 'swiftling_setup_redirect' ) );
	}

	/**
	 * The original can be a language from WordPress's list.
	 */
	public function test_choosing_the_original() {
		delete_option( 'swiftling_languages' );
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_setup', array( 'source' => 'de_DE' ) );
		$this->assertStringContainsString( 'swiftling_notice=setup', $this->redirect_of( 'swiftling_save_setup' ) );

		$languages = swiftling_languages();
		$this->assertSame( 'de_DE', $languages['source']['locale'] );
		$this->assertSame( 'de', $languages['source']['slug'] );
		$this->assertSame( array(), $languages['targets'] );
		$this->assertSame( 'parameter', swiftling_saved_url_format() );
	}

	/**
	 * The original can be a custom language, with a name and a code.
	 */
	public function test_custom_original() {
		delete_option( 'swiftling_languages' );
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_setup',
			array(
				'custom' => array(
					'name'      => 'Crnogorski',
					'locale'    => 'CNR',
					'wp_locale' => 'hr',
					'rtl'       => '',
				),
			)
		);
		$this->redirect_of( 'swiftling_save_setup' );
		$source = swiftling_languages()['source'];
		$this->assertSame( array( 'cnr', 'Crnogorski', 'cnr', true, 'hr' ), array( $source['locale'], $source['name'], $source['slug'], $source['custom'], $source['wp_locale'] ) );
	}

	/**
	 * A language not in the list, or a custom one without a code, sends back to the setup.
	 */
	public function test_invalid_originals() {
		delete_option( 'swiftling_languages' );
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_setup', array( 'source' => 'xx_XX' ) );
		$this->assertStringContainsString( 'swiftling_notice=choose', $this->redirect_of( 'swiftling_save_setup' ) );
		$this->post_form( 'swiftling_setup', array( 'custom' => array( 'name' => 'Klingon' ) ) );
		$this->assertStringContainsString( 'swiftling_notice=custom_invalid', $this->redirect_of( 'swiftling_save_setup' ) );
		$this->assertFalse( swiftling_is_set_up() );
	}

	/**
	 * Once chosen, the original changes only by starting over.
	 */
	public function test_original_is_fixed() {
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_setup', array( 'source' => 'de_DE' ) );
		$this->assertStringContainsString( 'page=swiftling-languages', $this->redirect_of( 'swiftling_save_setup' ) );
		$this->assertSame( 'hr', swiftling_languages()['source']['locale'] );
	}

	/**
	 * Until the setup is answered, every screen leads to it, but one that says it opens before it, as
	 * an import that sets the languages up from another plugin's.
	 */
	public function test_screen_that_opens_before_setup() {
		delete_option( 'swiftling_languages' );
		$this->become( 'administrator' );
		$menu = new \SwiftLing\Admin\Menu();
		$this->assertSame(
			swiftling_setup_url(),
			$this->redirect_of(
				static function () use ( $menu ) {
					$menu->loadScreen(
						array(
							'menu' => 'Languages',
							'view' => 'languages',
							'slug' => 'swiftling-languages',
						)
					);
				}
			)
		);
		$this->assert_no_redirect(
			static function () use ( $menu ) {
				$menu->loadScreen(
					array(
						'menu'         => 'Import',
						'view'         => 'import',
						'slug'         => 'swiftling-import',
						'before_setup' => true,
					)
				);
			}
		);

		// A translator still waits for whoever manages the site.
		$this->become( 'editor' );
		$this->expectException( WPDieException::class );
		$menu->loadScreen(
			array(
				'menu' => 'Languages',
				'view' => 'languages',
				'slug' => 'swiftling-languages',
			)
		);
	}

	/**
	 * The setup prints what another plugin adds beside it, as SwiftLing Pro's offer to import.
	 */
	public function test_setup_aside() {
		delete_option( 'swiftling_languages' );
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$print = static function () {
			echo '<p class="pro-offer">Import from Polylang</p>';
		};
		add_action( 'swiftling_setup_aside', $print );
		$html = $this->render_screen( 'setup' );
		remove_action( 'swiftling_setup_aside', $print );
		set_current_screen( 'front' );
		$this->assertStringContainsString( '<p class="pro-offer">Import from Polylang</p>', $html );
		// After the form, not inside it.
		$this->assertGreaterThan( strpos( $html, '</form>' ), strpos( $html, 'pro-offer' ) );
	}

	/**
	 * What another plugin offers above the question, as SwiftLing Pro's import, comes first: the
	 * question follows under "or set SwiftLing up by hand", its title one level down and its button
	 * secondary. An offer that prints nothing, or none, leaves the setup as it was.
	 */
	public function test_setup_offer() {
		delete_option( 'swiftling_languages' );
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$html = $this->render_screen( 'setup' );
		$this->assertStringContainsString( '<h1 class="swiftling-title">What language is your site written in?</h1>', $html );
		$this->assertStringContainsString( 'class="swiftling-button is-primary is-large"', $html );
		$this->assertStringNotContainsString( 'swiftling-setup__or', $html );

		$silent = static function () {
			echo " \n ";
		};
		add_action( 'swiftling_setup_offer', $silent );
		$html = $this->render_screen( 'setup' );
		remove_action( 'swiftling_setup_offer', $silent );
		$this->assertStringNotContainsString( 'swiftling-setup__or', $html, 'Nothing printed, nothing changes' );
		$this->assertStringContainsString( '<h1 class="swiftling-title">', $html );

		$print = static function () {
			echo '<section class="pro-offer"><h1>Bring your translations along</h1></section>';
		};
		add_action( 'swiftling_setup_offer', $print );
		$html = $this->render_screen( 'setup' );
		remove_action( 'swiftling_setup_offer', $print );
		set_current_screen( 'front' );
		$offer = strpos( $html, '<section class="pro-offer">' );
		$or    = strpos( $html, 'or set SwiftLing up by hand' );
		$form  = strpos( $html, '<form' );
		$this->assertNotFalse( $offer );
		$this->assertNotFalse( $or );
		$this->assertLessThan( $or, $offer, 'The offer comes first' );
		$this->assertLessThan( $form, $or, 'Then the question' );
		$this->assertStringContainsString( 'swiftling-setup__card is-second', $html );
		$this->assertStringContainsString( '<h2 class="swiftling-title">What language is your site written in?</h2>', $html );
		$this->assertStringContainsString( 'class="swiftling-button is-secondary is-large"', $html );
	}

	/**
	 * Only people who manage the site answer the setup or start over.
	 */
	public function test_rights() {
		$this->become( 'editor' );
		$this->post_form( 'swiftling_setup', array( 'source' => 'de_DE' ) );
		try {
			swiftling_save_setup();
			$this->fail( 'An editor set SwiftLing up.' );
		} catch ( WPDieException $refused ) {
			$this->assertSame( 'hr', swiftling_languages()['source']['locale'] );
		}
		$this->post_form( 'swiftling_start_over', array( 'confirm' => 'START OVER' ) );
		$this->expectException( WPDieException::class );
		swiftling_start_over();
	}

	/**
	 * Fills SwiftLing's tables and options, as a site in use has them.
	 */
	private function fill() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontakt' );
		swiftling_save_rules( array( 'r' => SWIFTLING_RULE_TEXT ) );
		swiftling_save_slug( 'post:' . $post->ID, 'de_DE', 'kontaktieren' );
		swiftling_refresh_translated( array( $post->ID ) );
		update_option( 'swiftling_switcher', array( 'show' => array( 'flag' => true ) ) );
		update_option( 'swiftling_settings', array( 'hidden_address' => '404' ) );
		return $post;
	}

	/**
	 * Stores what the Themes & plugins screen finds and chooses.
	 */
	private function fill_packages() {
		\SwiftLing\Packages\Catalogue::add(
			'theme/zz',
			array(
				array(
					'd' => 'zz',
					't' => 'Read more',
				),
			),
			'seen',
			time()
		);
		update_option( 'swiftling_package_settings', array( 'theme/zz' => array( 'chosen' => array( 'option/zz/footer' => 'text' ) ) ) );
		update_option( 'swiftling_option_owners', array( 'zz' => 'theme/zz' ) );
		update_option( 'swiftling_seen_texts', array( 'abc' ) );
	}

	/**
	 * Returns the number of rows of a SwiftLing table.
	 *
	 * @param string $table Table.
	 * @return int
	 */
	private function rows( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting rows in a test.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', swiftling_table( $table ) ) );
	}

	/**
	 * Starting over needs the confirmation word.
	 */
	public function test_start_over_needs_the_word() {
		$this->fill();
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_start_over', array( 'confirm' => 'yes' ) );
		$this->assertStringContainsString( 'swiftling_notice=confirm', $this->redirect_of( 'swiftling_start_over' ) );
		$this->assertTrue( swiftling_is_set_up() );
		$this->assertSame( 1, $this->rows( 'strings' ) );
	}

	/**
	 * Starting over removes everything SwiftLing stored, but no post, and opens the setup: every
	 * table, every option of SwiftLing's name, also one added later or a plugin's built on it, as
	 * SwiftLing Pro's, with the translation service and its key, the users' SwiftLing preferences,
	 * which languages menu items show in, files kept between the steps of a scan. The tables
	 * themselves and their version stay, and the widgets placed. A plugin hears of it before, while
	 * everything is there, and after. The log keeps one entry: that it happened.
	 */
	public function test_start_over_everything() {
		global $wpdb;
		$post   = $this->fill();
		$scopes = array();
		add_action(
			'swiftling_starting_over',
			static function ( $given ) use ( &$scopes ) {
				$scopes[] = array( 'before', $given, get_option( 'swiftling_service' ) );
			}
		);
		add_action(
			'swiftling_started_over',
			static function ( $given ) use ( &$scopes ) {
				$scopes[] = array( 'after', $given, get_option( 'swiftling_service' ) );
			}
		);
		$this->fill_packages();
		$user = self::factory()->user->create();
		update_user_meta( $user, 'swiftling_recording', array( 'until' => time() + 60 ) );
		update_user_meta( $user, 'swiftling_menu_help_hidden', 1 );
		$item = self::factory()->post->create( array( 'post_type' => 'nav_menu_item' ) );
		update_post_meta( $item, '_swiftling_show_in', array( 'de_DE' ) );
		$options = array( 'swiftling_service', 'swiftling_service_usage', 'swiftling_service_choice', 'swiftling_never_words', 'swiftling_log_keep', 'swiftling_term_fields', 'swiftling_translate_all', 'swiftling_address_checks', 'swiftling_ping_secret', 'swiftling_setup_redirect', 'swiftling_some_later_option', 'swiftling_pro_currency' );
		foreach ( $options as $option ) {
			update_option( $option, array( 'x' ) );
		}
		update_option( 'widget_swiftling_switcher', array( 2 => array() ) );
		set_transient( 'swiftling_scan_files_abc', array( 'a.php' ), HOUR_IN_SECONDS );
		swiftling_log( 'event', 'languages', 'Deutsch was added.' );
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_start_over', array( 'confirm' => ' start over ' ) );
		$this->assertStringContainsString( 'swiftling_notice=started_over_everything', $this->redirect_of( 'swiftling_start_over' ) );

		$this->assertSame(
			array(
				array( 'before', 'everything', array( 'x' ) ),
				array( 'after', 'everything', false ),
			),
			$scopes
		);
		foreach ( SWIFTLING_TABLES as $table ) {
			$this->assertSame( 'log' === $table ? 1 : 0, $this->rows( $table ), $table );
		}
		$left = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'swiftling_' ) . '%', $wpdb->esc_like( '_transient_swiftling_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading what is left in a test.
		$this->assertSame( array( 'swiftling_db_version' ), $left, 'Nothing of SwiftLing\'s name is left but the version of its tables' );
		foreach ( $options as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertFalse( get_transient( 'swiftling_scan_files_abc' ) );
		$this->assertSame( '', get_user_meta( $user, 'swiftling_recording', true ) );
		$this->assertSame( '', get_user_meta( $user, 'swiftling_menu_help_hidden', true ) );
		$this->assertSame( '', get_post_meta( $item, '_swiftling_show_in', true ) );
		$this->assertNotFalse( get_option( 'widget_swiftling_switcher' ), 'Widgets placed stay' );
		$this->assertSame( 'Kontakt', get_post( $post->ID )->post_title );
	}

	/**
	 * Starting over can keep the translations: adding the language again brings them back.
	 */
	public function test_start_over_keeping_translations() {
		$post = $this->fill();
		$this->fill_packages();
		update_option( 'swiftling_service', array( 'x' ) );
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_start_over',
			array(
				'confirm' => 'START OVER',
				'scope'   => 'keep',
			)
		);
		$this->assertStringContainsString( 'started_over_keep', $this->redirect_of( 'swiftling_start_over' ) );
		$this->assertSame( 1, $this->rows( 'strings' ) );
		$this->assertSame( 1, $this->rows( 'objects' ) );
		$this->assertSame( 1, $this->rows( 'slugs' ) );
		$this->assertSame( 0, $this->rows( 'translated' ) );
		// What the Themes & plugins screen found and chose stays with the translations.
		$this->assertSame( 1, $this->rows( 'catalogue' ) );
		$this->assertNotFalse( get_option( 'swiftling_package_settings' ) );
		$this->assertNotFalse( get_option( 'swiftling_service' ), 'The translation service stays with them' );

		$this->set_languages();
		swiftling_refresh_all_translated();
		$this->assertSame( 'reviewed', swiftling_language_states( $post->ID )['de'] );
	}

	/**
	 * The confirmation counts translated pages per language as the Overview does, and the site's own translations.
	 */
	public function test_start_over_counts() {
		$this->fill();
		$this->assertSame(
			array(
				'pages' => array( 'de' => 1 ),
				'own'   => 1,
			),
			swiftling_start_over_counts()
		);
	}
}
