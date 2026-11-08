<?php
/**
 * GiveWP without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\GiveWP;

require_once __DIR__ . '/give.php';

/**
 * GiveWP, without the plugin: active, with its campaigns from a list.
 */
class FakeGiveWP extends GiveWP {
	/**
	 * The campaigns, by ID: their titles and short descriptions.
	 *
	 * @var array<int, array{title: string, shortDescription: string}>
	 */
	public $campaigns = array();

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Returns the campaigns of the list.
	 */
	protected function campaigns(): array {
		return $this->campaigns;
	}
}
