<?php
/**
 * TablePress without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\TablePress;

require_once __DIR__ . '/tablepress.php';

/**
 * TablePress, without the plugin: active, with everything else of the real class.
 */
class FakeTablePress extends TablePress {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
