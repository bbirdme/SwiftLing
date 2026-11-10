<?php
/**
 * The class of WP Job Openings without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\WpJobOpenings;

/**
 * WP Job Openings, without the plugin itself: active.
 */
class FakeWpJobOpenings extends WpJobOpenings {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
