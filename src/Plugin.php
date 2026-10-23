<?php
/**
 * Plugin bootstrap.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing;

use SwiftLing\Admin\EditorPanel;
use SwiftLing\Admin\Menu;
use SwiftLing\Admin\ProOffer;
use SwiftLing\Admin\TranslationsColumn;
use SwiftLing\Builders\Registry;
use SwiftLing\Packages\Recorder;
use SwiftLing\Switcher\Block;
use SwiftLing\Switcher\Footer;
use SwiftLing\Switcher\NavMenu;
use SwiftLing\Switcher\Settings;
use SwiftLing\Switcher\Shortcode;
use SwiftLing\Switcher\Suggestion;
use SwiftLing\Switcher\Switcher;
use SwiftLing\Switcher\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Wires SwiftLing into WordPress.
 */
final class Plugin {
	/**
	 * The files of includes/ loaded on every request: the probe and the translated view run on the
	 * front end, and the functions of includes/api.php from the REST API, WP-CLI and WP-Cron.
	 */
	public const INCLUDES = array( 'tables', 'rights', 'languages', 'kept-languages', 'urls', 'hosts', 'slugs', 'bases', 'storage', 'log', 'requests', 'segmenter', 'sources', 'subjects', 'terms', 'comments', 'menus', 'menu-languages', 'probe', 'server-check', 'translate', 'visibility', 'copies', 'hreflang', 'seo', 'search', 'sitemaps', 'cache', 'cache-plugins', 'admin-bar', 'rest', 'rest-languages', 'api', 'packages', 'switcher' );

	/**
	 * The files of includes/ the translation editor renders with, see loadEditor().
	 */
	public const EDITOR_INCLUDES = array( 'probe-results', 'progress', 'live-editor', 'translate-all' );

	/**
	 * Registers the hooks of every part of the plugin.
	 */
	public function boot(): void {
		foreach ( self::INCLUDES as $file ) {
			require_once SWIFTLING_DIR . 'includes/' . $file . '.php';
		}

		// Recording the texts of themes and plugins, when the user records: as early as SwiftLing
		// loads, so the settings themes and plugins read while they start up are noted too.
		Recorder::register();

		add_action( 'rest_api_init', array( self::class, 'loadEditor' ), 0 );

		// What builders need on every request, once every plugin is loaded, as a page check starts.
		add_action( 'init', array( Registry::class, 'register' ), 0 );

		// The language switcher in every place a site can put one.
		( new Switcher() )->register();
		( new Settings() )->register();
		( new Block() )->register();
		( new Shortcode() )->register();
		( new NavMenu() )->register();
		( new Footer() )->register();
		( new Suggestion() )->register();
		add_action( 'widgets_init', array( Widget::class, 'registerWidget' ) );

		if ( is_admin() ) {
			require_once SWIFTLING_DIR . 'includes/setup.php';
			require_once SWIFTLING_DIR . 'includes/service.php';
			self::loadEditor();
			( new Menu() )->register();
			( new ProOffer() )->register();
			( new EditorPanel() )->register();
			( new TranslationsColumn() )->register();
		}
	}

	/**
	 * Runs when the plugin is activated: the first setup opens next, unless it was answered before.
	 */
	public static function activate(): void {
		require_once SWIFTLING_DIR . 'includes/setup.php';
		swiftling_note_activation();
	}

	/**
	 * Runs when the plugin is deactivated: the daily deletion of old log entries stops, and starts
	 * again from an admin screen once the plugin is active again.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( SWIFTLING_LOG_CRON );
	}

	/**
	 * Loads what the translation editor renders. The admin screens and the editor's REST routes use it.
	 */
	public static function loadEditor(): void {
		foreach ( self::EDITOR_INCLUDES as $file ) {
			require_once SWIFTLING_DIR . 'includes/' . $file . '.php';
		}
	}
}
