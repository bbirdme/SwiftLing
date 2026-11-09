<?php
/**
 * The class of Envira Gallery without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Envira;

/**
 * Envira Gallery, without the plugin itself: active.
 */
class FakeEnvira extends Envira {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}
}
