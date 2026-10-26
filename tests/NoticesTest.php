<?php
/**
 * Tests of the notices of the SwiftLing screens.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use SwiftLing\Admin\Ui;

/**
 * Every notice comes from Ui::notice(), in one look.
 */
class NoticesTest extends TestCase {

	/**
	 * Prints a notice and returns it.
	 *
	 * @param string $kind Kind.
	 * @param array  $args Arguments.
	 * @return string
	 */
	private function notice( $kind, $args ) {
		ob_start();
		Ui::notice( $kind, $args );
		return (string) ob_get_clean();
	}

	/**
	 * A notice has its kind, an icon, a bold first line and its text, escaped.
	 */
	public function test_notice() {
		$html = $this->notice(
			'success',
			array(
				'title' => 'Saved',
				'text'  => 'Visitors see <b>this</b> & that.',
			)
		);
		$this->assertStringContainsString( 'class="swiftling-notice is-success"', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'swiftling-notice__icon', $html );
		$this->assertMatchesRegularExpression( '~<p class="swiftling-notice__title">\s*Saved\s*</p>~', $html );
		$this->assertStringContainsString( 'Visitors see &lt;b&gt;this&lt;/b&gt; &amp; that.', $html );
	}

	/**
	 * A tip carries the SwiftLing bird; an error is announced at once.
	 */
	public function test_kinds() {
		// wp_kses() writes attribute names in lower case, as a browser reads them in HTML anyway.
		$this->assertStringContainsString( 'viewbox="0 0 64.86 36.57"', $this->notice( 'info', array( 'text' => 'A tip.' ) ) );
		$this->assertStringContainsString( '<svg viewbox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3.5 8.4l2.9 2.9L12.5 5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>', $this->notice( 'success', array( 'text' => 'Saved.' ) ), 'Every part of an icon' );
		$this->assertStringContainsString( '<circle cx="8" cy="12.3" r="1.3" fill="currentColor" />', $this->notice( 'warning', array( 'text' => 'Careful.' ) ) );
		$this->assertStringContainsString( 'role="alert"', $this->notice( 'error', array( 'text' => 'Failed.' ) ) );
		$this->assertStringContainsString( 'is-warning', $this->notice( 'warning', array( 'text' => 'Careful.' ) ) );
		// An unknown kind is a tip.
		$this->assertStringContainsString( 'class="swiftling-notice is-info"', $this->notice( 'other', array( 'text' => 'A tip.' ) ) );
	}

	/**
	 * Text with a link keeps the link and loses anything else.
	 */
	public function test_html_text() {
		$html = $this->notice( 'info', array( 'html' => 'Go to <a href="https://example.org/x">Permalinks</a><script>alert(1)</script>.' ) );
		$this->assertStringContainsString( '<a href="https://example.org/x">Permalinks</a>', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * A notice that leads somewhere has buttons; a link joins the text; a notice in a card is smaller.
	 */
	public function test_actions_links_and_compact() {
		$html = $this->notice(
			'warning',
			array(
				'text'    => 'Check it.',
				'link'    => array( 'Permalink settings', 'https://example.org/wp-admin/options-permalink.php' ),
				'actions' => array( array( 'Check the menu', 'https://example.org/menus' ) ),
				'compact' => true,
			)
		);
		$this->assertStringContainsString( 'class="swiftling-notice is-warning is-compact"', $html );
		$this->assertStringContainsString( 'href="https://example.org/menus"', $html );
		$this->assertStringContainsString( 'Check the menu', $html );
		$this->assertMatchesRegularExpression( '~Check it\.\s*<a href="https://example.org/wp-admin/options-permalink.php">Permalink settings</a>~', $html );
	}

	/**
	 * A screen shows its notice in the new look.
	 */
	public function test_screen_notice() {
		$this->become( 'administrator' );
		$_GET = array( 'swiftling_notice' => 'saved' );
		set_current_screen( 'toplevel_page_swiftling' );
		ob_start();
		( new Menu() )->renderScreen( 'settings' );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );
		$this->assertStringContainsString( 'class="swiftling-notice is-success"', $html );
		$this->assertStringContainsString( 'Settings saved.', $html );
	}

	/**
	 * No screen writes a notice by hand: every one comes from Ui::notice(), so they all look the same.
	 */
	public function test_every_notice_comes_from_the_helper() {
		$found = array();
		foreach ( array( 'admin', 'src', 'includes' ) as $folder ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( SWIFTLING_DIR . $folder, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() || 'Ui.php' === $file->getFilename() ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file of the plugin.
				if ( false !== strpos( (string) file_get_contents( $file->getPathname() ), 'swiftling-notice' ) ) {
					$found[] = substr( $file->getPathname(), strlen( SWIFTLING_DIR ) );
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
