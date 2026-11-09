<?php
/**
 * The class of Modula without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Modula;

/**
 * Modula, without the plugin itself: active.
 */
class FakeModula extends Modula {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
