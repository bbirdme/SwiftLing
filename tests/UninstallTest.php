<?php
/**
 * Tests of what deleting the plugin keeps.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Deleting the plugin keeps everything it stored: tables, options, translations. Removing them is
 * Start over's "Remove everything", see swiftling_remove_everything() and SetupTest. Deleting a site
 * of a network is not deleting the plugin: its tables go with it.
 */
class UninstallTest extends TestCase {

	/**
	 * The plugin has no uninstall.php and registers no uninstall hook, in its code or in WordPress's
	 * list, so WordPress runs nothing of it when it is deleted.
	 */
	public function test_deleting_the_plugin_keeps_the_data() {
		$this->assertFileDoesNotExist( SWIFTLING_DIR . 'uninstall.php' );
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( SWIFTLING_DIR, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( '.php' !== substr( $path, -4 ) || preg_match( '~/(vendor|node_modules|tests)/~', $path ) ) {
				continue;
			}
			$this->assertStringNotContainsString( 'register_uninstall_hook', (string) file_get_contents( $path ), $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own code.
		}
		\SwiftLing\Plugin::activate();
		$this->assertArrayNotHasKey( plugin_basename( SWIFTLING_DIR . 'swiftling.php' ), (array) get_option( 'uninstall_plugins', array() ) );
	}

	/**
	 * Deleting a site of a network drops its SwiftLing tables with its own: WordPress drops only the
	 * tables it is told of.
	 */
	public function test_deleting_a_site_drops_its_tables() {
		global $wpdb;
		$tables = apply_filters( 'wpmu_drop_tables', array( $wpdb->get_blog_prefix( 7 ) . 'posts' ), 7 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as it applies it when it deletes a site.
		foreach ( SWIFTLING_TABLES as $table ) {
			$this->assertContains( $wpdb->get_blog_prefix( 7 ) . 'swiftling_' . $table, $tables, $table );
		}
		$this->assertContains( $wpdb->get_blog_prefix( 7 ) . 'posts', $tables, 'WordPress\'s own stay in the list' );
	}

	/**
	 * The list of tables is the one place they are named: creating them makes exactly these.
	 */
	public function test_the_list_of_tables_is_what_is_created() {
		global $wpdb;
		$created = array();
		$catch   = static function ( $queries ) use ( &$created ) {
			$created = array_keys( $queries );
			return array();
		};
		add_filter( 'dbdelta_create_queries', $catch );
		delete_option( 'swiftling_db_version' );
		swiftling_maybe_install_tables();
		remove_filter( 'dbdelta_create_queries', $catch );

		$expected = array();
		foreach ( SWIFTLING_TABLES as $table ) {
			$expected[] = $wpdb->prefix . 'swiftling_' . $table;
		}
		sort( $created );
		sort( $expected );
		$this->assertSame( $expected, $created );
	}
}
