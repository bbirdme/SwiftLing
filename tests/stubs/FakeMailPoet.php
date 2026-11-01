<?php
/**
 * MailPoet without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Forms\MailPoet;

require_once __DIR__ . '/mailpoet.php';

/**
 * MailPoet, without the plugin: active, with its table of forms, and everything else of the real class.
 */
class FakeMailPoet extends MailPoet {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Creates the plugin's table of forms with the columns SwiftLing reads, anew for each test, with a
	 * form.
	 *
	 * @param array $body     The blocks of the form.
	 * @param array $settings Its settings.
	 */
	public static function install( array $body, array $settings ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' copy of the plugin's table.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mailpoet_forms" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}mailpoet_forms ( id int(11) NOT NULL AUTO_INCREMENT, name varchar(90), body longtext, settings longtext, deleted_at datetime NULL, PRIMARY KEY (id) )" );
		$wpdb->insert(
			$wpdb->prefix . 'mailpoet_forms',
			array(
				'name'     => 'Vrt',
				'body'     => serialize( $body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As the plugin stores it.
				'settings' => serialize( $settings ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As above.
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'mailpoet_forms',
			array(
				'name'       => 'U smeću',
				'body'       => serialize( $body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As above.
				'settings'   => serialize( $settings ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As above.
				'deleted_at' => '2026-10-01 10:00:00',
			)
		);
		// phpcs:enable
	}
}
