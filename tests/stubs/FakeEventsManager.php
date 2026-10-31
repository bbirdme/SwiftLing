<?php
/**
 * Events Manager without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\EventsManager;

/**
 * Events Manager, without the plugin: active, with its types of post and the columns of its tables of
 * events and tickets the class reads, and everything else of the real class.
 */
class FakeEventsManager extends EventsManager {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the types of post as Events Manager 7.4 does, and makes its tables of events and
	 * tickets; the test suite makes them temporary.
	 */
	public static function install(): void {
		global $wpdb;
		register_post_type( self::EVENT, array( 'public' => true ) );
		register_post_type( self::LOCATION, array( 'public' => true ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The plugin's tables, made for a test.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}em_events ( event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_id bigint(20) unsigned DEFAULT NULL, event_name text, event_type varchar(20) DEFAULT 'single', PRIMARY KEY (event_id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}em_tickets ( ticket_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, event_id bigint(20) unsigned NOT NULL, ticket_name tinytext, ticket_description text, ticket_parent bigint(20) unsigned DEFAULT NULL, ticket_order int(2) unsigned DEFAULT NULL, PRIMARY KEY (ticket_id) )" );
		// phpcs:enable
	}
}
