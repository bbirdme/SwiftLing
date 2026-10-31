<?php
/**
 * My Calendar without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\MyCalendar;

/**
 * My Calendar, without the plugin: active, with the types of post that mirror its events and
 * locations, and everything else of the real class.
 */
class FakeMyCalendar extends MyCalendar {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the types of post as My Calendar 3.8 does: visitors open them, but search leaves them
	 * out, and its taxonomy of categories.
	 */
	public static function install(): void {
		register_post_type(
			self::EVENT,
			array(
				'public'              => true,
				'exclude_from_search' => true,
			)
		);
		register_post_type(
			self::LOCATION,
			array(
				'public'              => true,
				'exclude_from_search' => true,
			)
		);
		register_taxonomy( 'mc-event-category', self::EVENT, array( 'public' => true ) );
	}
}
