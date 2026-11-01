<?php
/**
 * Booking Calendar without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\BookingCalendar;

require_once __DIR__ . '/booking-calendar.php';

/**
 * Booking Calendar, without the plugin: active, with its table of forms, and everything else of the
 * real class.
 */
class FakeBookingCalendar extends BookingCalendar {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Whether a table is in the database: the tests' tables are temporary, which MySQL does not list.
	 *
	 * @param string $table The table's name.
	 */
	protected function hasTable( string $table ): bool {
		return true;
	}

	/**
	 * Creates the plugin's table of forms with the columns SwiftLing reads, anew for each test.
	 */
	public static function install(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'booking_form_structures';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' copy of the plugin's table.
		$wpdb->query( "CREATE TABLE {$table} ( booking_form_id int(11) NOT NULL AUTO_INCREMENT, form_slug varchar(100) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'published', is_default tinyint(1) NOT NULL DEFAULT 0, advanced_form longtext, content_form longtext, PRIMARY KEY (booking_form_id) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		$GLOBALS['swiftling_test_wpbc_meta'] = array();
	}
}
