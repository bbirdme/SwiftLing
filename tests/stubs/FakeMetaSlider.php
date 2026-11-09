<?php
/**
 * The class of MetaSlider without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\MetaSlider;

/**
 * MetaSlider, without the plugin itself: active.
 */
class FakeMetaSlider extends MetaSlider {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
