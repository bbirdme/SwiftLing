<?php
/**
 * The class of Master Slider without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\MasterSlider;

/**
 * Master Slider, without the plugin itself: active, with a copy of its tables.
 */
class FakeMasterSlider extends MasterSlider {
	/**
	 * The plugin's tables, by name without the prefix: their columns.
	 */
	const TABLES = array(
		'masterslider_sliders' => 'ID int(11) NOT NULL AUTO_INCREMENT, title varchar(255), params mediumtext, status varchar(10), PRIMARY KEY (ID)',
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
