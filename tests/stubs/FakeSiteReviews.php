<?php
/**
 * The class of Site Reviews without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\SiteReviews;

/**
 * Site Reviews, without the plugin itself: active.
 */
class FakeSiteReviews extends SiteReviews {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
