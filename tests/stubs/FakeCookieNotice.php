<?php
/**
 * Cookie Notice without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\CookieNotice;

/**
 * Cookie Notice, without the plugin: active, with the plugin's main object keeping the copy of its
 * settings it read when it loaded, and everything else of the real class.
 */
class FakeCookieNotice extends CookieNotice {
	/**
	 * The plugin's main object.
	 *
	 * @var object|null
	 */
	public $main = null;

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Loads the plugin as Cookie Notice 3.1 does: it reads its settings once, into its main object.
	 */
	public function load(): void {
		$this->main = (object) array( 'options' => array( 'general' => get_option( self::OPTION ) ) );
	}

	/**
	 * Returns the plugin's main object.
	 *
	 * @return object|null
	 */
	protected function plugin() {
		return $this->main;
	}
}
