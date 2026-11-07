<?php
/**
 * User Registration & Membership without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\UserRegistration;

/**
 * User Registration & Membership, without the plugin: active, with everything else of the real class.
 */
class FakeUserRegistration extends UserRegistration {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
