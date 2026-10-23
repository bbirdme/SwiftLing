<?php
/**
 * Tests of the languages no longer on the site, whose translations are kept, and of deleting them.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * A removed language keeps its translations, so adding it again brings them back; the Languages
 * screen lists such languages and deletes what one of them kept only after two warnings.
 */
class KeptLanguagesTest extends TestCase {

	/**
	 * Saving the Languages screen downloads WordPress's translations of new languages. The tests
	 * have no internet, so users here may not install languages, as in LanguagesTest.
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
	 * A site with German and French, French translated and given a separate copy, and German
	 * translated too; then French is removed, and both have an address, a state and a row of the
	 * search index.
	 *
	 * @return array{other: int, post: int, copy: int, item: int} Another post, the original, French's
	 *         copy and a menu item.
	 */
	private function site_that_removed_french() {
		$this->set_languages();
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Kontakt' ) );
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'fr', 'Contact' );
		$this->translate( 'post:' . $post->ID, 'field/post_content', 'Tekst', 'fr', 'Texte' );
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontakt DE' );

		$this->become( 'administrator' );
		$copy = swiftling_make_copy( $post, 'fr' );
		$this->assertIsInt( $copy );

		$menu = wp_create_nav_menu( 'Glavni' );
		$item = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Kontakt',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);
		update_post_meta(
			$item,
			SWIFTLING_MENU_LANGUAGES,
			array(
				'de_DE' => 1,
				'fr_FR' => 0,
			)
		);
		$switcher                   = get_option( \SwiftLing\Switcher\Settings::OPTION, array() );
		$switcher['flags']['fr_FR'] = array(
			'country' => 'be',
			'image'   => '',
		);
		update_option( \SwiftLing\Switcher\Settings::OPTION, $switcher );

		$this->set_languages( array( 'de_DE' => 'de' ) );
		// After the languages are saved, which writes the states of posts again.
		global $wpdb;
		$other = self::factory()->post->create( array( 'post_title' => 'Vijesti' ) );
		foreach ( array( 'fr_FR', 'de_DE' ) as $locale ) {
			foreach ( array( $post->ID, $other ) as $post_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- SwiftLing's own tables, as it stores them.
				$wpdb->insert(
					swiftling_table( 'translated' ),
					array(
						'post_id' => $post_id,
						'lang'    => $locale,
					)
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- SwiftLing's own tables, as it stores them.
				$wpdb->insert(
					swiftling_table( 'index' ),
					array(
						'post_id' => $post_id,
						'lang'    => $locale,
						'title'   => 'Kontakt',
						'body'    => 'Tekst',
					)
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- SwiftLing's own tables, as it stores them.
			$wpdb->insert(
				swiftling_table( 'slugs' ),
				array(
					'object_key' => 'post:' . $post->ID,
					'lang'       => $locale,
					'slug'       => 'kontakt-' . strtolower( $locale ),
				)
			);
		}
		return array(
			'other' => $other,
			'post'  => $post->ID,
			'copy'  => $copy,
			'item'  => $item,
		);
	}

	/**
	 * Rows of a table of a language.
	 *
	 * @param string $table  SwiftLing table.
	 * @param string $locale Locale.
	 */
	private function rows( $table, $locale ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE lang = %s', swiftling_table( $table ), $locale ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own tables, as it stores them.
	}

	/**
	 * A language no longer on the site is found by what is stored for it, with how much: never the
	 * original, a language on the site, or one with nothing stored.
	 */
	public function test_kept_languages_are_found_by_their_data() {
		$this->site_that_removed_french();

		$kept = swiftling_kept_languages();
		$this->assertSame( array( 'fr_FR' ), array_keys( $kept ) );
		$this->assertSame( 'Français', $kept['fr_FR']['name'] );
		$this->assertSame( 2, $kept['fr_FR']['texts'] );
		$this->assertSame( 1, $kept['fr_FR']['copies'] );
		$this->assertSame( 1, $kept['fr_FR']['addresses'] );
		$this->assertTrue( $kept['fr_FR']['can_add'] );

		$this->set_languages( array() );
		$this->assertSame( array( 'fr_FR', 'de_DE' ), array_keys( swiftling_kept_languages() ), 'German too, once it is removed; the most texts first' );
	}

	/**
	 * Deleting a language takes everything stored for it, and only for it: its texts, states,
	 * addresses and rows of the search index, what menu items and the switcher kept for it, while
	 * its separate copies go to the Trash. It is noted in the log.
	 */
	public function test_forgetting_deletes_only_that_language() {
		$site = $this->site_that_removed_french();
		foreach ( array( 'strings', 'translated', 'slugs', 'index' ) as $table ) {
			$this->assertGreaterThan( 0, $this->rows( $table, 'fr_FR' ), $table . ' has French before' );
		}

		$done = swiftling_forget_language( 'fr_FR' );
		$this->assertSame(
			array(
				'texts'  => 2,
				'copies' => 1,
			),
			$done
		);
		foreach ( array( 'strings', 'translated', 'slugs', 'index' ) as $table ) {
			$this->assertSame( 0, $this->rows( $table, 'fr_FR' ), $table );
		}
		foreach ( array( 'strings', 'slugs' ) as $table ) {
			$this->assertGreaterThan( 0, $this->rows( $table, 'de_DE' ), $table . ' keeps German' );
		}
		// The states and search index of the original are written again when its copy goes to the
		// Trash, see swiftling_copy_status_changed(), so German is checked on the other post.
		global $wpdb;
		foreach ( array( 'translated', 'index' ) as $table ) {
			$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d AND lang = %s', swiftling_table( $table ), $site['other'], 'de_DE' ) ), $table . ' keeps German' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own tables, as it stores them.
		}
		$this->assertSame( 'trash', get_post_status( $site['copy'] ) );
		$this->assertSame( 'publish', get_post_status( $site['post'] ) );
		$this->assertSame( array( 'de_DE' => 1 ), get_post_meta( $site['item'], SWIFTLING_MENU_LANGUAGES, true ) );
		$this->assertArrayNotHasKey( 'fr_FR', get_option( \SwiftLing\Switcher\Settings::OPTION )['flags'] );
		$this->assertSame( array(), swiftling_kept_languages() );

		global $wpdb;
		$this->assertSame( 'The translations of Français were deleted: 2 texts, and 1 separate copy moved to the Trash.', $wpdb->get_var( $wpdb->prepare( 'SELECT message FROM %i ORDER BY id DESC LIMIT 1', swiftling_table( 'log' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own tables, as it stores them.
	}

	/**
	 * The original and a language on the site are never deleted, whoever asks.
	 */
	public function test_language_on_the_site_is_never_forgotten() {
		$this->site_that_removed_french();
		foreach ( array( 'de_DE', 'hr' ) as $locale ) {
			$this->assertWPError( swiftling_forget_language( $locale ), $locale );
		}
		$this->assertGreaterThan( 0, $this->rows( 'strings', 'de_DE' ) );
	}

	/**
	 * A language with many texts is deleted in batches, to the last one.
	 */
	public function test_forgetting_goes_through_every_batch() {
		$this->set_languages( array( 'de_DE' => 'de' ) );
		global $wpdb;
		$values = array();
		for ( $n = 0; $n < 2500; $n++ ) {
			$values[] = $wpdb->prepare( '(%s, %s, %s)', 'fr_FR', md5( (string) $n, true ), 'Texte ' . $n );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Every row prepared above, into SwiftLing's own table.
		$wpdb->query( 'INSERT INTO ' . swiftling_table( 'strings' ) . ' (lang, source_hash, translation) VALUES ' . implode( ',', $values ) );
		$this->assertSame( 2500, swiftling_kept_languages()['fr_FR']['texts'] );

		$this->assertSame( 2500, swiftling_forget_language( 'fr_FR' )['texts'] );
		$this->assertSame( 0, $this->rows( 'strings', 'fr_FR' ) );
	}

	/**
	 * Nothing goes before both warnings are answered: the first asks to tick that the translations
	 * cannot be brought back, the second is the last chance. Both need the right to manage SwiftLing
	 * and the form's nonce.
	 */
	public function test_deleting_takes_two_warnings() {
		$this->site_that_removed_french();

		$this->post_form( 'swiftling_forget_language', array( 'locale' => 'fr_FR' ) );
		$this->assertStringContainsString( 'swiftling_notice=forget_unticked', $this->redirect_of( 'swiftling_handle_forget_language' ) );
		$this->assertSame( 2, $this->rows( 'strings', 'fr_FR' ), 'Not ticked' );

		$this->post_form(
			'swiftling_forget_language',
			array(
				'locale'     => 'fr_FR',
				'understood' => '1',
			)
		);
		$location = $this->redirect_of( 'swiftling_handle_forget_language' );
		$this->assertStringContainsString( 'swiftling_forget=fr_FR', $location );
		$this->assertStringContainsString( 'swiftling_step=last', $location );
		$this->assertSame( 2, $this->rows( 'strings', 'fr_FR' ), 'Not the last warning yet' );

		$this->post_form(
			'swiftling_forget_language',
			array(
				'locale'     => 'fr_FR',
				'understood' => '1',
				'last'       => '1',
			)
		);
		$location = $this->redirect_of( 'swiftling_handle_forget_language' );
		$this->assertStringContainsString( 'swiftling_notice=forgotten', $location );
		$this->assertStringContainsString( 'swiftling_forgotten=fr_FR', $location );
		$this->assertSame( 0, $this->rows( 'strings', 'fr_FR' ) );
	}

	/**
	 * An editor cannot delete what a language kept.
	 */
	public function test_editor_cannot_delete() {
		$this->site_that_removed_french();
		$this->become( 'editor' );
		$this->post_form(
			'swiftling_forget_language',
			array(
				'locale'     => 'fr_FR',
				'understood' => '1',
				'last'       => '1',
			)
		);
		try {
			swiftling_handle_forget_language();
			$this->fail( 'An editor may not delete translations.' );
		} catch ( \WPDieException $die ) {
			$this->assertSame( 2, $this->rows( 'strings', 'fr_FR' ) );
		}
	}

	/**
	 * Adding a kept language again from its row brings it back with its translations, and keeps
	 * what the form holds for the languages on the site.
	 */
	public function test_add_again_brings_the_translations_back() {
		$this->site_that_removed_french();
		$this->post_form(
			'swiftling_save_languages',
			array(
				'targets'   => array(
					'de_DE' => array(
						'slug'    => 'de',
						'visible' => '1',
						'label'   => 'German',
					),
				),
				'add_again' => 'fr_FR',
			)
		);
		$this->redirect_of( 'swiftling_save_languages' );

		$this->assertNotNull( swiftling_language_by_locale( 'fr_FR' ) );
		$this->assertSame( 'German', swiftling_language_by_locale( 'de_DE' )['label'] );
		$this->assertSame( array(), swiftling_kept_languages() );
		$this->assertSame( 2, $this->rows( 'strings', 'fr_FR' ) );
	}

	/**
	 * The screen lists the kept languages with what they keep, third, before the names of the
	 * languages; Delete opens the first warning, its
	 * answer the last one, each in place without the script.
	 */
	public function test_screen() {
		$this->site_that_removed_french();
		$_GET = array();
		$html = $this->render_screen( 'languages' );
		$this->assertStringContainsString( 'Languages no longer on the site', $html );
		$this->assertGreaterThan( strpos( $html, 'Add a custom language' ), strpos( $html, 'Languages no longer on the site' ), 'Third, after adding a custom language' );
		$this->assertLessThan( strpos( $html, 'Language names' ), strpos( $html, 'Languages no longer on the site' ), 'Before the names' );
		$this->assertStringContainsString( '2 texts · 1 separate copy', $html );
		$this->assertStringContainsString( 'name="add_again" value="fr_FR"', $html );
		$this->assertStringContainsString( 'swiftling_forget=fr_FR', $html );
		$this->assertStringNotContainsString( 'aria-labelledby="swiftling-forget-fr_FR-title" open', $html );

		$_GET = array( 'swiftling_forget' => 'fr_FR' );
		$html = $this->render_screen( 'languages' );
		$this->assertStringContainsString( 'aria-labelledby="swiftling-forget-fr_FR-title" open', $html );
		$this->assertMatchesRegularExpression( '~data-swiftling-step="check"\s*>~', $html, 'The first warning shows' );
		$this->assertMatchesRegularExpression( '~data-swiftling-step="last"\s+hidden~', $html );

		$_GET = array(
			'swiftling_forget' => 'fr_FR',
			'swiftling_step'   => 'last',
		);
		$html = $this->render_screen( 'languages' );
		$this->assertMatchesRegularExpression( '~data-swiftling-step="check"\s+hidden~', $html );
		$this->assertMatchesRegularExpression( '~data-swiftling-step="last"\s*>~', $html, 'The last warning shows' );

		$this->set_languages();
		$_GET = array();
		$this->assertStringNotContainsString( 'Languages no longer on the site', $this->render_screen( 'languages' ), 'Not while every language is on the site' );
	}
}
