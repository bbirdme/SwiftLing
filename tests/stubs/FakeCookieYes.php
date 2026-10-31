<?php
/**
 * CookieYes without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\CookieYes;

/**
 * CookieYes, without the plugin: active, with the languages it has its banner in and the version it
 * runs, and everything else of the real class.
 */
class FakeCookieYes extends CookieYes {
	/**
	 * The plugin's codes of the languages it has its banner in.
	 *
	 * @var string[]
	 */
	public $languages = array( 'en', 'de', 'pt-br' );

	/**
	 * Whether the site runs the plugin's earlier version.
	 *
	 * @var bool
	 */
	public $legacy = false;

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Whether the plugin has its banner in a language.
	 *
	 * @param string $code The plugin's code of the language.
	 */
	protected function hasLanguage( string $code ): bool {
		return in_array( $code, $this->languages, true );
	}

	/**
	 * Whether the site runs the plugin's earlier version.
	 */
	protected function isLegacy(): bool {
		return $this->legacy;
	}

	/**
	 * Registers the taxonomy of the earlier version's cookie categories, as CookieYes 3.5 does.
	 */
	public static function install(): void {
		register_taxonomy( self::LEGACY_CATEGORIES, array(), array( 'public' => false ) );
	}
}
