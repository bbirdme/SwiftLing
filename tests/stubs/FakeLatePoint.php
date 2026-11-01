<?php
/**
 * LatePoint without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\LatePoint;

require_once __DIR__ . '/latepoint.php';

/**
 * LatePoint, without the plugin: active, with its tables of services, settings and processes, and
 * everything else of the real class.
 */
class FakeLatePoint extends LatePoint {
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
		return in_array( $table, array( $wpdb->prefix . 'latepoint_services', $wpdb->prefix . 'latepoint_settings', $wpdb->prefix . 'latepoint_processes' ), true );
	}

	/**
	 * Creates the plugin's tables with the columns SwiftLing reads, anew for each test.
	 */
	public static function install(): void {
		global $wpdb;
		$tables = array(
			'latepoint_services'  => 'id int(11) NOT NULL AUTO_INCREMENT, name varchar(255), short_description text, duration_name varchar(255), PRIMARY KEY (id)',
			'latepoint_settings'  => 'id int(11) NOT NULL AUTO_INCREMENT, name varchar(110), value longtext, PRIMARY KEY (id)',
			'latepoint_processes' => 'id int(11) NOT NULL AUTO_INCREMENT, actions_json longtext, PRIMARY KEY (id)',
		);
		foreach ( $tables as $table => $columns ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' copy of the plugin's tables.
			$wpdb->query( "CREATE TABLE {$wpdb->prefix}{$table} ( {$columns} )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		}
	}
}
