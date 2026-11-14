<?php
/**
 * The class of SportsPress without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\SportsPress;

/**
 * SportsPress, without the plugin itself: active.
 */
class FakeSportsPress extends SportsPress {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
