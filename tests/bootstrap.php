<?php
/**
 * Starts WordPress's test suite with SwiftLing active.
 *
 * The suite installs WordPress into the database named in tests/wp-tests-config.php, loads
 * SwiftLing as a must-use plugin would, and then runs each test in a transaction it rolls back.
 *
 * @package SwiftLing
 */

$swiftling_root = dirname( __DIR__ );

// The PHPUnit Polyfills, which WordPress's suite needs, and the test classes.
require_once $swiftling_root . '/vendor/autoload.php';

if ( false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Where wp-phpunit looks for the configuration.
}

$swiftling_wp_tests = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : $swiftling_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $swiftling_wp_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $swiftling_root ) {
		require $swiftling_root . '/swiftling.php';
	}
);

// What the admin and the editor's REST routes load, so tests can call it from anywhere.
tests_add_filter(
	'plugins_loaded',
	static function () {
		require_once SWIFTLING_DIR . 'includes/setup.php';
		require_once SWIFTLING_DIR . 'includes/service.php';
		\SwiftLing\Plugin::loadEditor();
	},
	20
);

require $swiftling_wp_tests . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/FakeService.php';
