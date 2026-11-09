<?php
/**
 * The class of FooGallery without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\FooGallery;

/**
 * FooGallery, without the plugin itself: active.
 */
class FakeFooGallery extends FooGallery {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
