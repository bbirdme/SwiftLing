<?php
/**
 * Tests of the Addresses screen.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use WPDieException;

/**
 * How the addresses of the site read in each language: the format, chosen on the Addresses screen.
 */
class AddressesTest extends TestCase {

	/**
	 * Addresses is a tab of Settings, right after Languages.
	 */
	public function test_addresses_follow_languages_in_the_menu() {
		$slugs = array_keys( Menu::screens() );
		$this->assertSame( array_search( 'swiftling-languages', $slugs, true ) + 1, array_search( 'swiftling-addresses', $slugs, true ) );
		$this->assertSame( 'addresses', Menu::screens()['swiftling-addresses']['view'] );
	}

	/**
	 * The format is chosen on Addresses, and no longer on Languages.
	 */
	public function test_format_card_is_on_addresses() {
		$this->become( 'administrator' );
		$this->assertStringContainsString( 'name="format"', $this->render_screen( 'addresses' ) );
		$this->assertStringContainsString( 'value="swiftling_save_url_format"', $this->render_screen( 'addresses' ) );
		$this->assertStringNotContainsString( 'name="format"', $this->render_screen( 'languages' ) );
	}

	/**
	 * Saving the Languages screen keeps the format and the original's folder as they are.
	 */
	public function test_saving_languages_keeps_the_format() {
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory', array( 'source_folder' => true ) );
		$this->become( 'administrator' );
		add_filter(
			'user_has_cap',
			static function ( $caps ) {
				unset( $caps['install_languages'] );
				return $caps;
			}
		);
		$this->post_form(
			'swiftling_save_languages',
			array(
				'targets' => array(
					'de_DE' => array(
						'slug'    => 'de',
						'visible' => '1',
					),
				),
			)
		);
		$this->redirect_of( 'swiftling_save_languages' );

		$saved = get_option( 'swiftling_languages' );
		$this->assertSame( 'directory', $saved['format'] );
		$this->assertTrue( $saved['source_folder'] );
	}

	/**
	 * The Addresses screen saves the format and the original's folder, and nothing else.
	 */
	public function test_saving_the_format() {
		$before = get_option( 'swiftling_languages' );
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_url_format',
			array(
				'format'        => 'directory',
				'source_folder' => '1',
			)
		);
		$location = $this->redirect_of( 'swiftling_save_url_format' );

		$this->assertStringContainsString( 'page=swiftling-addresses', $location );
		$this->assertStringContainsString( 'swiftling_notice=saved', $location );
		$saved = get_option( 'swiftling_languages' );
		$this->assertSame( 'directory', $saved['format'] );
		$this->assertTrue( $saved['source_folder'] );
		unset( $saved['format'], $saved['source_folder'], $before['format'], $before['source_folder'] );
		$this->assertSame( $before, $saved );
	}

	/**
	 * A format the form does not offer is the parameter, and the original's folder is off unless asked.
	 */
	public function test_unknown_format_is_the_parameter() {
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory', array( 'source_folder' => true ) );
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_save_url_format', array( 'format' => 'ftp' ) );
		$this->redirect_of( 'swiftling_save_url_format' );

		$saved = get_option( 'swiftling_languages' );
		$this->assertSame( 'parameter', $saved['format'] );
		$this->assertFalse( $saved['source_folder'] );
	}

	/**
	 * Language folders that are a page's address are reported where the folders are switched on.
	 */
	public function test_saving_the_format_reports_taken_folders() {
		$this->use_pretty_permalinks();
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'de',
			)
		);
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_save_url_format', array( 'format' => 'directory' ) );
		$location = $this->redirect_of( 'swiftling_save_url_format' );

		$this->assertStringContainsString( 'swiftling_taken=de', $location );
		$this->assertStringContainsString( 'page=swiftling-addresses', $location );
	}

	/**
	 * The screen is called URLs, the word WordPress users look for, everywhere it is named: the
	 * menu, its title, and the links that lead to it. Its page keeps its slug, so links to it work.
	 */
	public function test_screen_is_called_urls() {
		$this->assertSame( 'URLs', Menu::screens()['swiftling-addresses']['menu'] );

		$this->become( 'administrator' );
		$html = $this->render_screen( 'addresses' );
		$this->assertStringContainsString( '<h2 class="swiftling-title">URLs</h2>', $html );
		$this->assertStringContainsString( 'Language in the URL', $html );

		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$note = swiftling_base_note( 'tax', 'category', 'de' );
		$this->assertSame( 'Translate it on URLs', $note['link'] );
		$this->assertStringContainsString( 'page=swiftling-addresses', $note['url'] );

		// No screen still names it Addresses, or sends people "on Addresses".
		foreach ( array( 'admin/views', 'includes', 'src' ) as $folder ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( SWIFTLING_DIR . $folder ) );
			foreach ( $files as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$this->assertDoesNotMatchRegularExpression( "/__\\( '(?:Addresses'|[^']* on Addresses\\b)/", (string) file_get_contents( $file->getPathname() ), $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file of the plugin.
				}
			}
		}
	}

	/**
	 * Only people who manage the site change its addresses.
	 */
	public function test_saving_the_format_needs_manage_options() {
		$this->become( 'editor' );
		$this->post_form( 'swiftling_save_url_format', array( 'format' => 'directory' ) );
		$this->expectException( WPDieException::class );
		swiftling_save_url_format();
	}

	/**
	 * The languages of the fixed words in addresses show with their flags, as the switcher shows them.
	 */
	public function test_language_tabs_show_flags() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->become( 'administrator' );
		$_GET = array();
		$html = $this->render_screen( 'addresses' );
		$this->assertStringContainsString( 'Fixed words in addresses', $html );
		$this->assertMatchesRegularExpression( '~<nav class="swiftling-segmented swiftling-bases__languages".*?<img class="swiftling-segmented__flag" src="' . preg_quote( esc_url( \SwiftLing\Switcher\Flags::countryUrl( 'de' ) ), '~' ) . '"~s', $html );
	}

	/**
	 * Choosing a format must not move the options of "Language in the URL": a tester saw Subdomain
	 * jump up and the "How to set it up" box push the save buttons off the screen. The layout is
	 * CSS alone, so these two tests read the rules the layout comes from; the click-through that
	 * measures the options in a browser is in the MR description.
	 *
	 * First, the column of help: when the screen has help at all it is there for every format, so
	 * the options keep their width when a host format opens it.
	 */
	public function test_help_column_is_there_for_every_format_when_there_is_help() {
		$this->use_pretty_permalinks();
		$this->become( 'administrator' );
		$_GET = array();

		foreach ( array( 'parameter', 'directory' ) as $format ) {
			$this->set_languages( array( 'de_DE' => 'de' ), $format );
			$this->assertMatchesRegularExpression( '~class="swiftling-format-layout[^"]*\bhas-help\b~', $this->render_screen( 'addresses' ), "Help column with the format $format" );
		}

		$this->set_languages( array(), 'parameter' );
		$this->assertDoesNotMatchRegularExpression( '~\bhas-help\b~', $this->render_screen( 'addresses' ), 'No help without a language to set up' );
	}

	/**
	 * Second, the rules: nothing about the layout depends on which format is chosen, except what
	 * shows in the column of help and the table of hosts, both below or beside the options.
	 */
	public function test_layout_of_the_options_does_not_depend_on_the_chosen_format() {
		$css = (string) file_get_contents( SWIFTLING_DIR . 'assets/css/admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin.
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );

		$sticky_foot = false;
		foreach ( $rules as $rule ) {
			list( , $selectors, $body ) = $rule;

			// What the hosts and the help add must not push the buttons off the screen.
			if ( false !== strpos( $selectors, 'swiftling-format-form .swiftling-card__foot' ) && preg_match( '/position:\s*sticky/', $body ) ) {
				$sticky_foot = true;
			}

			if ( false !== strpos( $selectors, 'swiftling-format__source' ) ) {
				$this->assertDoesNotMatchRegularExpression( '/display:\s*none/', $body, 'The "in a folder too" row stays in place for every format, muted when it does not apply: ' . trim( $selectors ) );
			}
			if ( false !== strpos( $selectors, 'swiftling-format-layout' ) && false !== strpos( $body, 'grid-template-columns' ) ) {
				$this->assertStringNotContainsString( ':checked', $selectors, 'The columns do not depend on the chosen format: ' . trim( $selectors ) );
			}
		}
		$this->assertTrue( $sticky_foot, 'The foot with Save changes sticks to the bottom of the screen.' );
	}
}
