<?php
/**
 * NinjaTables without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\NinjaTables;

/**
 * Ninja Tables, without the plugin: active.
 */
class FakeNinjaTables extends NinjaTables {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
