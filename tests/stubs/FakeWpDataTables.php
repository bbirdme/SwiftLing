<?php
/**
 * The class of wpDataTables without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\WpDataTables;

require_once __DIR__ . '/wpdatatables.php';

/**
 * The plugin wpDataTables, without the plugin itself: active.
 */
class FakeWpDataTables extends WpDataTables {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Returns the plugin's tables, as its table would.
	 *
	 * @return object[]
	 */
	protected function rows(): array {
		return array(
			(object) array(
				'id'                => 1,
				'title'             => 'Cijene bilja',
				'advanced_settings' => '{"table_description":"Za proljetni sajam."}',
			),
		);
	}
}
