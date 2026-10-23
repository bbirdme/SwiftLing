<?php
/**
 * Tests that the plugin loads and installs what it needs.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * The plugin as WordPress loads it.
 */
class PluginTest extends TestCase {

	/**
	 * The plugin's constants and classes are there once WordPress has loaded it.
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'SWIFTLING_VERSION' ) );
		$this->assertTrue( class_exists( \SwiftLing\Plugin::class ) );
		$this->assertTrue( function_exists( 'swiftling_languages' ) );
	}

	/**
	 * The version in the header and in the code agree, and readme.txt names it as the stable tag.
	 */
	public function test_versions_agree() {
		$header = get_file_data( SWIFTLING_DIR . 'swiftling.php', array( 'Version' => 'Version' ) );
		$readme = get_file_data( SWIFTLING_DIR . 'readme.txt', array( 'Stable' => 'Stable tag' ) );
		$this->assertSame( SWIFTLING_VERSION, $header['Version'] );
		$this->assertSame( SWIFTLING_VERSION, $readme['Stable'] );
	}

	/**
	 * The six tables exist with the columns the code uses, and the schema version is current.
	 */
	public function test_tables_are_installed() {
		global $wpdb;
		$columns = array(
			'strings'    => array( 'lang', 'source_hash', 'translation', 'status' ),
			'objects'    => array( 'object_key', 'units', 'sources', 'refs', 'changed' ),
			'rules'      => array( 'rule_key', 'verdict' ),
			'translated' => array( 'post_id', 'lang' ),
			'slugs'      => array( 'object_key', 'lang', 'slug', 'current', 'status' ),
			'catalogue'  => array( 'domain', 'path', 'package', 'source', 'kind', 'seen', 'scanned' ),
			'index'      => array( 'post_id', 'lang', 'title', 'body' ),
		);
		foreach ( $columns as $table => $expected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the schema in a test.
			$found = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', swiftling_table( $table ) ) );
			$this->assertSame( $expected, $found, $table );
		}
		$this->assertSame( SWIFTLING_DB_VERSION, (int) get_option( 'swiftling_db_version' ) );
	}

	/**
	 * Installing again when the schema is current changes nothing and runs no query.
	 */
	public function test_install_is_skipped_when_current() {
		$queries = 0;
		$count   = static function ( $query ) use ( &$queries ) {
			++$queries;
			return $query;
		};
		add_filter( 'query', $count );
		swiftling_maybe_install_tables();
		remove_filter( 'query', $count );
		$this->assertSame( 0, $queries );
	}

	/**
	 * Every function of SwiftLing that includes/api.php calls is in a file loaded on every request,
	 * or in what the translation editor loads, which the functions that need it load first: the API
	 * runs from the REST API, WP-CLI and WP-Cron, where the admin's own files, as includes/setup.php,
	 * are not loaded. The test suite loads those for every test, so only this tells.
	 */
	public function test_api_calls_only_what_every_request_loads() {
		$loaded = array_merge( \SwiftLing\Plugin::INCLUDES, \SwiftLing\Plugin::EDITOR_INCLUDES );
		$tokens = array_values(
			array_filter(
				token_get_all( (string) file_get_contents( SWIFTLING_DIR . 'includes/api.php' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own source.
				static function ( $token ) {
					return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);
		$called = array();
		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 0 !== strpos( $token[1], 'swiftling_' ) || '(' !== ( $tokens[ $index + 1 ] ?? '' ) ) {
				continue;
			}
			$before = $tokens[ $index - 1 ] ?? '';
			if ( is_array( $before ) && in_array( $before[0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
				continue;
			}
			$called[ $token[1] ] = true;
		}
		$this->assertNotEmpty( $called );
		foreach ( array_keys( $called ) as $function ) {
			$this->assertTrue( function_exists( $function ), $function );
			$file = (string) ( new \ReflectionFunction( $function ) )->getFileName();
			$this->assertSame( realpath( SWIFTLING_DIR . 'includes' ), realpath( dirname( $file ) ), $function . ' is in ' . $file );
			$this->assertContains( basename( $file, '.php' ), $loaded, $function . ' is in ' . $file . ', which not every request loads' );
		}
	}

	/**
	 * Activation asks for the first setup only while the site has no original language.
	 */
	public function test_activation_opens_setup_only_before_it_is_answered() {
		\SwiftLing\Plugin::activate();
		$this->assertFalse( get_option( 'swiftling_setup_redirect' ) );

		delete_option( 'swiftling_languages' );
		\SwiftLing\Plugin::activate();
		$this->assertSame( '1', (string) get_option( 'swiftling_setup_redirect' ) );
	}
}
