<?php
/**
 * WpTableBuilder without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\WpTableBuilder;

/**
 * WP Table Builder, without the plugin: active.
 */
class FakeWpTableBuilder extends WpTableBuilder {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
