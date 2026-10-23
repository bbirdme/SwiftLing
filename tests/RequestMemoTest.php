<?php
/**
 * Tests of what SwiftLing remembers during a request.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * The settings, the languages and the address format are built once a request, not each time a
 * text asks for them, and are built again as soon as what they are made from changes.
 */
class RequestMemoTest extends TestCase {

	/**
	 * Counts how often an option is read from WordPress.
	 *
	 * @param string $option Option name.
	 * @return \stdClass Counter: ->reads.
	 */
	private function count_reads( $option ) {
		$counter        = new \stdClass();
		$counter->reads = 0;
		add_filter(
			'option_' . $option,
			static function ( $value ) use ( $counter ) {
				++$counter->reads;
				return $value;
			}
		);
		return $counter;
	}

	/**
	 * Asked a hundred times, the settings and the languages are read once.
	 */
	public function test_read_once_a_request() {
		update_option( 'swiftling_settings', array( 'never' => array() ) );
		swiftling_forget_memo();
		$settings  = $this->count_reads( 'swiftling_settings' );
		$languages = $this->count_reads( 'swiftling_languages' );
		for ( $i = 0; $i < 100; $i++ ) {
			swiftling_settings();
			swiftling_languages();
			swiftling_url_format();
			swiftling_content_types();
		}
		$this->assertSame( 1, $settings->reads, 'The settings' );
		$this->assertLessThanOrEqual( 2, $languages->reads, 'The languages and the address format' );
	}

	/**
	 * A saved option is read again: the settings, the languages and the format follow it at once.
	 */
	public function test_saved_options_are_read_again() {
		$this->assertArrayHasKey( 'page', swiftling_content_types() );
		update_option( 'swiftling_settings', array( 'never' => array( 'page' ) ) );
		$this->assertArrayNotHasKey( 'page', swiftling_content_types(), 'Never, at once' );
		delete_option( 'swiftling_settings' );
		$this->assertArrayHasKey( 'page', swiftling_content_types(), 'Deleted, at once' );

		$this->assertArrayHasKey( 'de', swiftling_languages()['targets'] );
		$this->set_languages( array( 'it_IT' => 'it' ) );
		$this->assertSame( array( 'it' ), array_keys( swiftling_languages()['targets'] ) );

		$this->assertSame( 'parameter', swiftling_url_format() );
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		$this->assertSame( 'directory', swiftling_url_format() );
		$this->set_permalink_structure( '' );
		$this->assertSame( 'parameter', swiftling_url_format(), 'Folders need pretty permalinks' );
	}

	/**
	 * Another site of a network has its own settings.
	 */
	public function test_another_site_of_a_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Sites of a network only.' );
		}
		$other = self::factory()->blog->create();
		update_option( 'swiftling_settings', array( 'never' => array( 'page' ) ) );
		$this->assertSame( array( 'page' ), swiftling_settings()['never'] );
		switch_to_blog( $other );
		try {
			$this->assertSame( array(), swiftling_settings()['never'], 'The other site\'s own' );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( array( 'page' ), swiftling_settings()['never'], 'Back on the first site' );
	}

	/**
	 * A text from code of a theme or plugin with no translation stays as it is, and one with a
	 * translation is translated, as before; an option keeps showing its translation however often
	 * it is read, and its new value once it is saved.
	 */
	public function test_texts_and_options_as_before() {
		update_option( 'blogname', 'Apartmani Marija' );
		$page = self::factory()->post->create();
		$this->check(
			'post:' . $page,
			static function () {
				get_option( 'blogname' );
			}
		);
		$this->translate( 'options', 'option/blogname', 'Apartmani Marija', 'de', 'Ferienwohnungen Marija' );
		$this->show_in( 'de' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 'Ferienwohnungen Marija', get_option( 'blogname' ) );
		}
		update_option( 'blogname', 'Apartmani Ana' );
		$this->assertSame( 'Apartmani Ana', get_option( 'blogname' ), 'Changed: the original, as it has no translation' );
		$this->assertSame( 'Read more', swiftling_filter_gettext( 'Read more', 'Read more', 'some-theme-without-translations' ) );
	}

	/**
	 * A text from code asked for many times on a page shows the same each time: the user's
	 * translation, the text as the code wrote it when it has none, and never a translation whose
	 * placeholders would stop the page.
	 */
	public function test_texts_from_code_asked_again() {
		$page  = self::factory()->post->create();
		$texts = array( 'Add to cart', 'Not translated yet', '%s items' );
		$this->check(
			'post:' . $page,
			static function () use ( $texts ) {
				foreach ( $texts as $text ) {
					__( $text, 'zz-shop' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch -- A plugin's text, as the plugin writes it.
				}
			},
			array_fill_keys(
				array_map(
					static function ( $text ) {
						return swiftling_string_path( array( 't' => $text ) ); }, $texts ), 'text' ) // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing,PEAR.Functions.FunctionCallSignature,WordPress.WhiteSpace.OperatorSpacing
		);
		$this->translate( 'gettext:zz-shop', swiftling_string_path( array( 't' => 'Add to cart' ) ), 'Add to cart', 'de', 'In den Warenkorb' );
		$this->translate( 'gettext:zz-shop', swiftling_string_path( array( 't' => '%s items' ) ), '%s items', 'de', '%s Artikel %d' );

		$this->show_in( 'de' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 'In den Warenkorb', __( 'Add to cart', 'zz-shop' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- A plugin's text.
			$this->assertSame( 'Not translated yet', __( 'Not translated yet', 'zz-shop' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- A plugin's text.
			$this->assertSame( '%s items', __( '%s items', 'zz-shop' ), 'A translation that would stop the page' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment -- A plugin's text.
		}
	}
}
