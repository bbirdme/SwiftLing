<?php
/**
 * The class of NextGEN Gallery without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\NextGen;

/**
 * NextGEN Gallery, without the plugin itself: active, with a copy of its tables.
 */
class FakeNextGen extends NextGen {
	/**
	 * The plugin's tables, by name without the prefix: their columns.
	 */
	const TABLES = array(
		'ngg_gallery'  => 'gid int(11) NOT NULL AUTO_INCREMENT, title varchar(255), galdesc text, PRIMARY KEY (gid)',
		'ngg_pictures' => 'pid int(11) NOT NULL AUTO_INCREMENT, galleryid int(11), alttext text, description text, sortorder int(11) DEFAULT 0, PRIMARY KEY (pid)',
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
