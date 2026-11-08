<?php
/**
 * Donations via PayPal without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\PayPalDonations;

/**
 * Donations via PayPal, without the plugin: active, with everything else of the real class.
 */
class FakePayPalDonations extends PayPalDonations {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
