<?php
/**
 * Paid Memberships Pro without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\PaidMembershipsPro;

require_once __DIR__ . '/pmpro.php';

/**
 * Paid Memberships Pro, without the plugin: active, with its table of levels, and everything else of the
 * real class.
 */
class FakePaidMembershipsPro extends PaidMembershipsPro {
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
		return $wpdb->prefix . 'pmpro_membership_levels' === $table;
	}

	/**
	 * Creates the plugin's table of levels with one level, anew for each test, and the level as the
	 * plugin hands it out.
	 */
	public static function install(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pmpro_membership_levels';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' copy of the plugin's table.
		$wpdb->query( "CREATE TABLE {$table} ( id int(11) NOT NULL, name varchar(255), description longtext, confirmation longtext, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		$level = array(
			'id'           => 1,
			'name'         => 'Sadnica',
			'description'  => 'Vrtno pismo svaki mjesec.',
			'confirmation' => 'Dobro došli u klub.',
		);
		$wpdb->insert( $table, $level ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As above.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The plugin's own globals.
		$GLOBALS['pmpro_test_levels']              = array( 1 => (object) $level );
		$GLOBALS['pmpro_email_templates_defaults'] = array(
			'header' => array( 'body' => '<p>Dear !!header_name!!,</p>' ),
			'footer' => array( 'body' => '<p>Respectfully</p>' ),
		);
		// phpcs:enable
	}
}
