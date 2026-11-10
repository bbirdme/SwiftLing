<?php
/**
 * The class of GeoDirectory without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\GeoDirectory;

/**
 * GeoDirectory, without the plugin itself: active, with a copy of its tables.
 */
class FakeGeoDirectory extends GeoDirectory {
	/**
	 * The plugin's tables, by name without the prefix: their columns.
	 */
	const TABLES = array(
		'geodir_custom_fields' => 'id int(11) NOT NULL AUTO_INCREMENT, post_type varchar(100), htmlvar_name varchar(255), field_type varchar(50), data_type varchar(50), frontend_title varchar(255), is_active tinyint(1) DEFAULT 1, PRIMARY KEY (id)',
	);

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Whether a table of the plugin is there: the tests' copies are temporary tables, which SHOW TABLES
	 * does not list.
	 *
	 * @param string $table Table name.
	 */
	protected function hasTable( string $table ): bool {
		global $wpdb;
		return 0 === strpos( $table, $wpdb->prefix ) && isset( self::TABLES[ substr( $table, strlen( $wpdb->prefix ) ) ] );
	}

	/**
	 * Creates the tests' copy of the plugin's tables.
	 */
	public static function install(): void {
		global $wpdb;
		foreach ( self::TABLES as $table => $columns ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' copy of the plugin's tables.
			$wpdb->query( "CREATE TABLE {$wpdb->prefix}{$table} ( {$columns} )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		}
	}
}
