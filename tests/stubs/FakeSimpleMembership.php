<?php
/**
 * Simple Membership without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\SimpleMembership;

require_once __DIR__ . '/simple-membership.php';

/**
 * Simple Membership, without the plugin: active, with its table of levels, and everything else of the
 * real class.
 */
class FakeSimpleMembership extends SimpleMembership {
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
		return $wpdb->prefix . 'swpm_membership_tbl' === $table;
	}

	/**
	 * Creates the plugin's table of levels, with the level the plugin keeps for itself and one more,
	 * anew for each test.
	 */
	public static function install(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'swpm_membership_tbl';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' copy of the plugin's table.
		$wpdb->query( "CREATE TABLE {$table} ( id int(11) NOT NULL, alias varchar(255), PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		foreach ( array( 'Content Protection', 'Sadnica' ) as $index => $alias ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As above.
			$wpdb->insert(
				$table,
				array(
					'id'    => $index + 1,
					'alias' => $alias,
				)
			);
		}
	}
}
