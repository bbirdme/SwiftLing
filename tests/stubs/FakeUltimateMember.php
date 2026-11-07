<?php
/**
 * Ultimate Member without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\UltimateMember;

require_once __DIR__ . '/ultimate-member.php';

/**
 * Ultimate Member, without the plugin: active, with everything else of the real class.
 */
class FakeUltimateMember extends UltimateMember {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
