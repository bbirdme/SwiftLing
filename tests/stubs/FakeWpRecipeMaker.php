<?php
/**
 * The class of WP Recipe Maker without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\WpRecipeMaker;

/**
 * WP Recipe Maker, without the plugin itself: active.
 */
class FakeWpRecipeMaker extends WpRecipeMaker {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
