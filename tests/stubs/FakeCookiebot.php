<?php
/**
 * Cookiebot without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Cookiebot;

/**
 * Cookiebot, without the plugin: active, and everything else of the real class.
 */
class FakeCookiebot extends Cookiebot {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
