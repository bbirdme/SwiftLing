<?php
/**
 * WP-Members without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\WpMembers;

require_once __DIR__ . '/wp-members.php';

/**
 * WP-Members, without the plugin: active, with everything else of the real class.
 */
class FakeWpMembers extends WpMembers {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
