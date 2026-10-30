<?php
/**
 * Plugin Name:       SwiftLing
 * Plugin URI:        https://swiftling.pro/
 * Description:       Lightweight but powerful and fast multilingual plugin: translate your site with your own translation API key.
 * Version:           1.0.6
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Bruno Kos
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       swiftling
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing;

defined( 'ABSPATH' ) || exit;

define( 'SWIFTLING_VERSION', '1.0.6' );
define( 'SWIFTLING_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWIFTLING_URL', plugin_dir_url( __FILE__ ) );
// The version of the hooks SwiftLing offers other plugins, see docs/hooks.md. It grows when a hook
// is added or changes, so a plugin built on them, as SwiftLing Pro, can tell what it may use.
define( 'SWIFTLING_HOOKS', 26 );

// PSR-4 autoloader for the SwiftLing\ namespace, so the plugin needs no Composer at runtime.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = SWIFTLING_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

( new Plugin() )->boot();
