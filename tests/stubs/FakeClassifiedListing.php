<?php
/**
 * The class of Classified Listing without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\ClassifiedListing;

/**
 * Classified Listing, without the plugin itself: active.
 */
class FakeClassifiedListing extends ClassifiedListing {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
