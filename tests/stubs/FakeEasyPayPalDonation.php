<?php
/**
 * Accept Donations with PayPal & Stripe without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\EasyPayPalDonation;

/**
 * Accept Donations with PayPal & Stripe, without the plugin: active, with everything else of the real class.
 */
class FakeEasyPayPalDonation extends EasyPayPalDonation {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
