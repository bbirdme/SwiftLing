<?php
/**
 * Newsletter without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Newsletter;

require_once __DIR__ . '/newsletter.php';

/**
 * Newsletter, without the plugin: active, with everything else of the real class.
 */
class FakeNewsletter extends Newsletter {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
