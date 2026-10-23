<?php
/**
 * Tests of content types that are never translated.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Pages set to Never on the Settings screen: visitors see the original in every language, and
 * SwiftLing offers nothing to translate for them.
 */
class NeverTranslatedTest extends TestCase {

	/**
	 * Sets pages to Never.
	 */
	private function never_pages() {
		update_option(
			'swiftling_settings',
			array(
				'untranslated'   => array( 'page' => 'hide' ),
				'never'          => array( 'page' ),
				'hidden_address' => 'original',
			)
		);
	}

	/**
	 * A type set to Never is no content type to translate, and hiding its untranslated posts no
	 * longer applies; the Settings screen still lists it.
	 */
	public function test_never_leaves_the_types_to_translate() {
		$this->assertArrayHasKey( 'page', swiftling_content_types() );
		$this->never_pages();
		$this->assertArrayNotHasKey( 'page', swiftling_content_types() );
		$this->assertArrayHasKey( 'page', swiftling_viewable_types() );
		$this->assertArrayHasKey( 'post', swiftling_content_types() );
		$this->assertNotContains( 'page', swiftling_hidden_types() );
		$this->assertSame( array( 'page' ), swiftling_settings()['never'] );
	}

	/**
	 * A page has no translation editor, and the Translations screen does not offer it.
	 */
	public function test_no_editor_and_not_offered() {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Impressum',
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Novosti iz Splita' ) );
		$this->assertNotNull( swiftling_subject( 'post:' . $page ) );
		$this->never_pages();
		$this->assertNull( swiftling_subject( 'post:' . $page ) );

		$this->become( 'administrator' );
		$html = $this->render_screen( 'content' );
		$this->assertStringContainsString( 'Novosti iz Splita', $html );
		$this->assertStringNotContainsString( 'Impressum', $html );
	}

	/**
	 * Translations made before stay stored, but visitors see the original.
	 */
	public function test_visitors_see_the_original() {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Cjenik',
			)
		);
		$this->check(
			'post:' . $page,
			static function () use ( $page ) {
				get_the_title( $page );
			}
		);
		$this->translate( 'post:' . $page, 'field/post_title', 'Cjenik', 'de', 'Preise' );
		$this->show_in( 'de' );
		$this->assertSame( 'Preise', get_the_title( $page ) );

		$this->never_pages();
		$this->show_in( 'de' );
		$this->assertSame( 'Cjenik', get_the_title( $page ) );
	}

	/**
	 * The sitemaps of a language leave the type out.
	 */
	public function test_language_sitemaps_leave_it_out() {
		global $wpdb;
		$this->never_pages();
		$override = &swiftling_sitemap_language_override();
		$override = 'de';
		$sql      = swiftling_sitemap_language_sql( $wpdb->posts );
		$override = null;
		$this->assertStringContainsString( "post_type NOT IN ('page')", $sql );
		$this->assertSame( '', swiftling_sitemap_language_sql( $wpdb->posts ) );
	}

	/**
	 * The Settings screen: Translate or Never for every type, and each language as a short code
	 * with its count rather than its whole name.
	 */
	public function test_settings_screen() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'it_IT' => 'it',
			)
		);
		$this->never_pages();
		$this->become( 'administrator' );
		$html = $this->render_screen( 'settings' );
		$this->assertMatchesRegularExpression( '~name="translated\[post\]" value="yes" checked~', $html );
		$this->assertMatchesRegularExpression( '~name="translated\[page\]" value="never" checked~', $html );
		$this->assertStringContainsString( 'Visitors see the original in every language.', $html );
		$this->assertStringContainsString( 'swiftling-untranslated__count', $html );
		$this->assertStringNotContainsString( 'Deutsch 0 ·', $html );
		$this->assertStringContainsString( 'title="Deutsch"', $html );
	}

	/**
	 * Saving keeps Never only for types the screen lists.
	 */
	public function test_saving() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_settings',
			array(
				'translated'     => array(
					'post'       => 'yes',
					'page'       => 'never',
					'attachment' => 'never',
				),
				'untranslated'   => array( 'post' => 'hide' ),
				'hidden_address' => 'original',
			)
		);
		$this->redirect_of( 'swiftling_save_settings' );
		$this->assertSame( array( 'page' ), swiftling_settings()['never'] );
		$this->assertSame( array( 'post' => 'hide' ), swiftling_settings()['untranslated'] );
	}
}
