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
}
