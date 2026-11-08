<?php
/**
 * Donation Platform for WooCommerce without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\WcDonationPlatform;

/**
 * Donation Platform for WooCommerce, without the plugin: active, with everything else of the real class.
 */
class FakeWcDonationPlatform extends WcDonationPlatform {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
