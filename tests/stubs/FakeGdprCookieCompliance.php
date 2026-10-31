<?php
/**
 * GDPR Cookie Compliance without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\GdprCookieCompliance;

/**
 * GDPR Cookie Compliance, without the plugin: active, and everything else of the real class.
 */
class FakeGdprCookieCompliance extends GdprCookieCompliance {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
