<?php
/**
 * The class of WP Job Manager without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\WpJobManager;

/**
 * WP Job Manager, without the plugin itself: active.
 */
class FakeWpJobManager extends WpJobManager {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
