<?php
/**
 * The tables SwiftLing keeps, named once. Creating them (swiftling_maybe_install_tables()),
 * emptying them when starting over (swiftling_remove_everything()) and deleting a site of a network
 * (swiftling_drop_site_tables()) all use this list; tests/UninstallTest.php checks that creating
 * them makes exactly these. Deleting the plugin keeps them.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SWIFTLING_TABLES' ) ) {
	define( 'SWIFTLING_TABLES', array( 'strings', 'objects', 'rules', 'translated', 'slugs', 'catalogue', 'index', 'log' ) );
}
