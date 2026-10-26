<?php
/**
 * Tests of what the plugin directory's review asks of the code.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Input is sanitized where it is read and output escaped where it is printed, with no PHPCS rule
 * switched off for it; JSON printed into a page keeps every escape.
 */
class DirectoryRulesTest extends TestCase {

	/**
	 * Lines of the plugin's PHP files, as file:line => text. The tests and Composer's files are not
	 * in the plugin.
	 *
	 * @return array<string, string>
	 */
	private function plugin_lines() {
		$lines = array();
		$files = array_merge(
			array( SWIFTLING_DIR . 'swiftling.php' ),
			glob( SWIFTLING_DIR . 'includes/*.php' ),
			glob( SWIFTLING_DIR . 'admin/*.php' ),
			glob( SWIFTLING_DIR . 'admin/views/*.php' )
		);
		$tree  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( SWIFTLING_DIR . 'src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $tree as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		foreach ( $files as $file ) {
			foreach ( file( $file ) as $number => $text ) {
				$lines[ substr( $file, strlen( SWIFTLING_DIR ) ) . ':' . ( $number + 1 ) ] = $text;
			}
		}
		return $lines;
	}

	/**
	 * Lines that contain a text.
	 *
	 * @param string $text Text.
	 * @return string[] file:line of each.
	 */
	private function lines_with( $text ) {
		return array_keys(
			array_filter(
				$this->plugin_lines(),
				static function ( $line ) use ( $text ) {
					return false !== strpos( $line, $text );
				}
			)
		);
	}

	/**
	 * Every input is sanitized as it is read; none passes on with the check switched off.
	 */
	public function test_input_is_sanitized_as_it_is_read() {
		$this->assertSame( array(), $this->lines_with( 'ValidatedSanitizedInput.InputNotSanitized' ) );
	}

	/**
	 * Every output is escaped as it is printed. The one exception is JSON in a JSON script element,
	 * which wp_json_encode() escapes itself: no WordPress function escapes it further.
	 */
	public function test_output_is_escaped_as_it_is_printed() {
		$json = 'OutputNotEscaped -- JSON with <, > and & escaped, inside a JSON script element.';
		$not  = array();
		foreach ( $this->lines_with( 'EscapeOutput.OutputNotEscaped' ) as $at ) {
			if ( false === strpos( $this->plugin_lines()[ $at ], $json ) ) {
				$not[] = $at;
			}
		}
		$this->assertSame( array(), $not );
	}

	/**
	 * JSON printed into a page keeps its escapes: < > & as \u codes, and / and letters outside ASCII
	 * escaped too. Only JSON stored in the database is written without them, to keep it small.
	 */
	public function test_json_in_a_page_keeps_its_escapes() {
		$stored = array();
		foreach ( $this->lines_with( 'JSON_UNESCAPED_' ) as $at ) {
			if ( ! in_array( strtok( $at, ':' ), array( 'includes/storage.php', 'src/Packages/Catalogue.php' ), true ) ) {
				$stored[] = $at;
			}
		}
		$this->assertSame( array(), $stored );
	}

	/**
	 * Every text is translated in the plugin's own text domain, never WordPress's.
	 */
	public function test_texts_are_in_the_plugins_domain() {
		$this->assertSame( array(), $this->lines_with( 'WordPress.WP.I18n.MissingArgDomain' ) );
		$this->assertSame( array(), $this->lines_with( 'WordPress.WP.I18n.TextDomainMismatch' ) );
	}
}
