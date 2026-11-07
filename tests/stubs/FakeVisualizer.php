<?php
/**
 * Visualizer without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Visualizer;

/**
 * Visualizer, without the plugin: active.
 */
class FakeVisualizer extends Visualizer {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
