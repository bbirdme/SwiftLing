<?php
/**
 * Event Tickets without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\EventTickets;

/**
 * Event Tickets, without the plugin: active, with the type of post of a ticket the plugin registers,
 * and everything else of the real class.
 */
class FakeEventTickets extends EventTickets {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the type of post of a ticket as Event Tickets 5.30 does: visitors never open one.
	 */
	public static function install(): void {
		register_post_type( self::TICKET, array( 'public' => false ) );
	}
}
