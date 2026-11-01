<?php
/**
 * Bookly without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Bookly;

require_once __DIR__ . '/bookly.php';

/**
 * Bookly, without the plugin: active, with its tables of services, bookings and orders, and
 * everything else of the real class.
 */
class FakeBookly extends Bookly {
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
		global $wpdb;
		return $wpdb->prefix . 'bookly_services' === $table;
	}

	/**
	 * Creates the plugin's tables with the columns SwiftLing reads, anew for each test.
	 */
	public static function install(): void {
		global $wpdb;
		$tables = array(
			'bookly_services'              => 'id int(11) NOT NULL AUTO_INCREMENT, title varchar(255), info text, PRIMARY KEY (id)',
			'bookly_orders'                => 'id int(11) NOT NULL AUTO_INCREMENT, token varchar(255), PRIMARY KEY (id)',
			'bookly_customer_appointments' => 'id int(11) NOT NULL AUTO_INCREMENT, order_id int(11), locale varchar(8) DEFAULT NULL, PRIMARY KEY (id)',
		);
		foreach ( $tables as $table => $columns ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' copy of the plugin's tables.
			$wpdb->query( "CREATE TABLE {$wpdb->prefix}{$table} ( {$columns} )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		}
	}
}
