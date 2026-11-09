<?php
/**
 * The class of Photo Gallery by 10Web without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\PhotoGallery;

/**
 * Photo Gallery by 10Web, without the plugin itself: active, with a copy of its tables.
 */
class FakePhotoGallery extends PhotoGallery {
	/**
	 * The plugin's tables, by name without the prefix: their columns.
	 */
	const TABLES = array(
		'bwg_gallery' => 'id int(11) NOT NULL AUTO_INCREMENT, name varchar(255), description text, PRIMARY KEY (id)',
		'bwg_image'   => 'id int(11) NOT NULL AUTO_INCREMENT, gallery_id int(11), alt text, description text, published tinyint(1) DEFAULT 1, `order` int(11) DEFAULT 0, PRIMARY KEY (id)',
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
