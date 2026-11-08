<?php
/**
 * Charitable without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Charitable;

/**
 * Charitable, without the plugin: active, with everything else of the real class.
 */
class FakeCharitable extends Charitable {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
