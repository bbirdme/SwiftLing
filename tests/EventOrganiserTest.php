<?php
/**
 * Tests of the class of Event Organiser.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\EventOrganiser;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeEventOrganiser.php';

/**
 * What SwiftLing translates of an event of Event Organiser beyond its post and terms: the address and
 * description of its venue, kept in the plugin's own table, and the calendar the page loads.
 */
class EventOrganiserTest extends TestCase {

	/**
	 * The class, with the plugin's type of post, taxonomy and table.
	 *
	 * @var FakeEventOrganiser
	 */
	private $eo;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		FakeEventOrganiser::install();
		$this->eo = new FakeEventOrganiser();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof EventOrganiser ? $this->eo : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->eo->register();
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		remove_all_filters( 'get_' . EventOrganiser::META_TYPE . '_metadata' );
		foreach ( EventOrganiser::CACHES as $cache ) {
			remove_all_filters( 'pre_transient_' . $cache );
			remove_all_filters( 'eventorganiser_cache_expiration_' . $cache );
		}
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Returns a text the editor offers.
	 *
	 * @param array  $result What check() returned.
	 * @param string $needle Words in the value.
	 * @return array The text.
	 */
	private function offered( $result, $needle ) {
		foreach ( array_merge( $result['sections']['page'], $result['sections']['fields'] ) as $unit ) {
			if ( false !== strpos( $unit['v'], $needle ) ) {
				return $unit;
			}
		}
		$this->fail( 'The editor offers no text with "' . $needle . '".' );
		return array();
	}

	/**
	 * Whether the editor offers a text with some words.
	 *
	 * @param array  $result What check() returned.
	 * @param string $needle Words in the value.
	 */
	private static function offers( $result, $needle ) {
		foreach ( array_merge( $result['sections']['page'], $result['sections']['fields'] ) as $unit ) {
			if ( false !== strpos( $unit['v'], $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A venue has no list on the Terms screen: the editor of the event offers its name, address and
	 * description, named, and not its postcode or its place on the map. A page in a language shows the
	 * address translated where the plugin reads it; the original as stored.
	 */
	public function test_venue_offered_and_translated() {
		$event = self::factory()->post->create(
			array(
				'post_type'  => EventOrganiser::EVENT,
				'post_title' => 'Dan otvorenog vrta',
			)
		);
		$venue = self::factory()->term->create(
			array(
				'taxonomy' => EventOrganiser::VENUE,
				'name'     => 'Zelena dvorana',
			)
		);
		wp_set_object_terms( $event, array( $venue ), EventOrganiser::VENUE );
		add_metadata( EventOrganiser::META_TYPE, $venue, '_address', 'Mlinska ulica 12' );
		add_metadata( EventOrganiser::META_TYPE, $venue, '_description', 'Svijetla dvorana iza starog mlina.' );
		add_metadata( EventOrganiser::META_TYPE, $venue, '_postcode', 'HR 42000' );
		add_metadata( EventOrganiser::META_TYPE, $venue, '_lat', '46.3' );

		$result  = $this->check( 'post:' . $event, '__return_null' );
		$name    = $this->offered( $result, 'Zelena dvorana' );
		$address = $this->offered( $result, 'Mlinska ulica' );
		$this->offered( $result, 'Svijetla dvorana' );
		$this->assertSame( 'term:' . $venue, $address['o'] );
		$this->assertSame( 'Venue: Zelena dvorana › Address', $address['place'] );
		$this->assertSame( 'Venue: Zelena dvorana › Name', $name['place'] );
		$this->assertFalse( self::offers( $result, 'HR 42000' ), 'A postcode is no text' );

		$this->translate( $address['o'], $address['p'], 'Mlinska ulica 12', 'de', 'Mühlenstraße 12' );
		$this->translate( $name['o'], $name['p'], 'Zelena dvorana', 'de', 'Grüne Halle' );

		$this->show_in( 'de' );
		$this->assertSame( 'Mühlenstraße 12', get_metadata( EventOrganiser::META_TYPE, $venue, '_address', true ) );
		$this->assertSame( 'Grüne Halle', get_term( $venue, EventOrganiser::VENUE )->name );
		$this->assertSame( 'HR 42000', get_metadata( EventOrganiser::META_TYPE, $venue, '_postcode', true ) );

		$this->reset_state();
		remove_all_filters( 'get_' . EventOrganiser::META_TYPE . '_metadata' );
		$this->assertSame( 'Mlinska ulica 12', get_metadata( EventOrganiser::META_TYPE, $venue, '_address', true ) );
	}

	/**
	 * The calendar loads its events from the page, in its language. The plugin keeps what it loaded by
	 * the query only: a page in a language neither reads nor fills that cache, the original does.
	 */
	public function test_calendar_in_the_language_without_the_plugins_cache() {
		foreach ( array( 'eventorganiser-fullcal', 'eo_widget_agenda', 'eo_widget_cal' ) as $action ) {
			$this->assertTrue( Registry::sendsForm( $action ), $action );
		}
		set_transient( 'eo_full_calendar_public', array( 'eo_fc_x' => array( array( 'title' => 'Dan otvorenog vrta' ) ) ) );

		$this->show_in( 'de' );
		$this->assertSame( array(), get_transient( 'eo_full_calendar_public' ) );
		$this->assertSame( -1, apply_filters( 'eventorganiser_cache_expiration_eo_full_calendar_public', 3600 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.

		$this->reset_state();
		foreach ( EventOrganiser::CACHES as $cache ) {
			remove_all_filters( 'pre_transient_' . $cache );
			remove_all_filters( 'eventorganiser_cache_expiration_' . $cache );
		}
		$this->assertArrayHasKey( 'eo_fc_x', get_transient( 'eo_full_calendar_public' ) );
	}

	/**
	 * An event gets no separate copy: its dates are rows of the plugin's table.
	 */
	public function test_events_get_no_separate_copy() {
		$this->assertFalse( swiftling_can_have_copies( get_post( self::factory()->post->create( array( 'post_type' => EventOrganiser::EVENT ) ) ) ) );
	}
}
