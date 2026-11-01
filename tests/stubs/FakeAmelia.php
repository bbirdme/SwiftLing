<?php
/**
 * Amelia without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Amelia;

/**
 * Amelia, without the plugin: active, with the plugin's tables of rows with texts, as Amelia 2.4
 * creates them, and everything else of the real class.
 */
class FakeAmelia extends Amelia {
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
	 * Creates the plugin's tables with the columns SwiftLing reads, anew for each test: the tests' tables
	 * are temporary and keep the numbering of their rows from one test to the next.
	 */
	public static function install(): void {
		global $wpdb;
		$tables = array(
			'amelia_services'              => 'name varchar(255) NOT NULL DEFAULT \'\', description mediumtext',
			'amelia_categories'            => 'name varchar(255) NOT NULL DEFAULT \'\'',
			'amelia_users'                 => 'type varchar(20) NOT NULL DEFAULT \'provider\', description text',
			'amelia_locations'             => 'name varchar(255) NOT NULL DEFAULT \'\', description text',
			'amelia_extras'                => 'name varchar(255) NOT NULL DEFAULT \'\', description text',
			'amelia_packages'              => 'name varchar(255) NOT NULL DEFAULT \'\', description text',
			'amelia_events'                => 'name varchar(255) NOT NULL DEFAULT \'\', description text',
			'amelia_events_to_tickets'     => 'name varchar(255) NOT NULL DEFAULT \'\'',
			'amelia_custom_fields'         => 'label text',
			'amelia_custom_fields_options' => 'label text',
			'amelia_notifications'         => 'sendTo varchar(20) NOT NULL DEFAULT \'customer\', type varchar(20) NOT NULL DEFAULT \'email\', subject varchar(255), content text',
		);
		foreach ( $tables as $table => $columns ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$table} ( id int(11) NOT NULL AUTO_INCREMENT, {$columns}, translations text, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' copy of the plugin's tables.
		}
	}
}
