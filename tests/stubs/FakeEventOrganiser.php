<?php
/**
 * Event Organiser without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\EventOrganiser;

/**
 * Event Organiser, without the plugin: active, with its type of post, its taxonomy of venues and its
 * table of venue fields, and everything else of the real class.
 */
class FakeEventOrganiser extends EventOrganiser {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the type of post and the taxonomy as Event Organiser 3.12 does, venues kept from the
	 * admin's term screens, and makes its table of venue fields; the test suite makes it temporary.
	 */
	public static function install(): void {
		global $wpdb;
		register_post_type( self::EVENT, array( 'public' => true ) );
		register_taxonomy(
			self::VENUE,
			self::EVENT,
			array(
				'public'  => true,
				'show_ui' => false,
			)
		);
		$wpdb->eo_venuemeta = $wpdb->prefix . 'eo_venuemeta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The plugin's table, made for a test.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->eo_venuemeta} ( meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, eo_venue_id bigint(20) unsigned NOT NULL DEFAULT 0, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id) )" );
	}
}
