<?php
/**
 * Tests of the class of Events Manager.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\EventsManager;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeEventsManager.php';

/**
 * What SwiftLing translates of Events Manager beyond its posts: the texts the plugin prints from its
 * own objects, its tickets, also those of a recurrence, and the texts of its settings, with their
 * placeholders kept.
 */
class EventsManagerTest extends TestCase {

	/**
	 * The class, with the plugin's types of post and tables.
	 *
	 * @var FakeEventsManager
	 */
	private $em;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		FakeEventsManager::install();
		$this->em = new FakeEventsManager();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof EventsManager ? $this->em : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->em->register();
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Returns a text the editor offers.
	 *
	 * @param array  $result What check() or the list of a subject returned.
	 * @param string $needle Words in the value.
	 * @return array The text.
	 */
	private function offered( $result, $needle ) {
		foreach ( array_merge( $result['sections']['page'], isset( $result['sections']['fields'] ) ? $result['sections']['fields'] : array() ) as $unit ) {
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
	 * @param array  $result What check() or the list of a subject returned.
	 * @param string $needle Words in the value.
	 */
	private static function offers( $result, $needle ) {
		foreach ( array_merge( $result['sections']['page'], isset( $result['sections']['fields'] ) ? $result['sections']['fields'] : array() ) as $unit ) {
			if ( false !== strpos( $unit['v'], $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Runs an action of the plugin, as it does.
	 *
	 * @param string $hook    The action.
	 * @param mixed  ...$args Its arguments.
	 */
	private static function em( $hook, ...$args ) {
		do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own actions, as it runs them.
	}

	/**
	 * Returns an event as the plugin loads it: an object with copies of the post's texts.
	 *
	 * @param int $post_id Post ID.
	 * @return object
	 */
	private static function event_object( $post_id ) {
		$post = get_post( $post_id );
		return (object) array(
			'post_id'      => $post_id,
			'event_name'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => '',
		);
	}

	/**
	 * Returns a location as the plugin loads it, with its address from the plugin's table.
	 *
	 * @param int $post_id Post ID.
	 * @return object
	 */
	private static function location_object( $post_id ) {
		return (object) array(
			'post_id'          => $post_id,
			'location_name'    => get_post( $post_id )->post_title,
			'post_content'     => '',
			'location_address' => 'Mlinska ulica 12',
			'location_town'    => 'Varaždin',
			'location_state'   => '',
			'location_region'  => '',
		);
	}

	/**
	 * The plugin prints an event's name and notes and a location's name and address from its own
	 * objects: a page check finds them under the post's paths and the location's own, a page in a
	 * language shows them translated, and a form of the plugin that saves an object it loaded saves
	 * what was stored.
	 */
	public function test_event_and_location_from_the_plugins_objects() {
		$event    = self::factory()->post->create(
			array(
				'post_type'    => EventsManager::EVENT,
				'post_title'   => 'Dan otvorenog vrta',
				'post_content' => 'Dođite na dan među biljem.',
			)
		);
		$location = self::factory()->post->create(
			array(
				'post_type'  => EventsManager::LOCATION,
				'post_title' => 'Zelena dvorana',
			)
		);
		$render   = static function () use ( $event, $location ) {
			$shown = array( self::event_object( $event ), self::location_object( $location ) );
			self::em( 'em_event', $shown[0], $event, 'post_id' );
			self::em( 'em_location', $shown[1], $location, 'post_id' );
			return $shown;
		};
		$result   = $this->check( 'post:' . $location, $render );
		$street   = $this->offered( $result, 'Mlinska ulica' );
		$this->assertSame( EventsManager::BASE . '/location_address', $street['p'] );
		$this->assertSame( 'Address', Registry::fieldLabel( $street['o'], $street['p'] ) );
		$name   = $this->offered( $result, 'Zelena dvorana' );
		$result = $this->check( 'post:' . $event, $render );
		$title  = $this->offered( $result, 'Dan otvorenog vrta' );
		$this->assertSame( 'field/post_title', $title['p'] );

		$this->translate( $title['o'], $title['p'], 'Dan otvorenog vrta', 'de', 'Tag des offenen Gartens' );
		$this->translate( $name['o'], $name['p'], 'Zelena dvorana', 'de', 'Grüne Halle' );
		$this->translate( $street['o'], $street['p'], 'Mlinska ulica 12', 'de', 'Mühlenstraße 12' );

		$this->show_in( 'de' );
		list( $shown_event, $shown_location ) = $render();
		$this->assertSame( 'Tag des offenen Gartens', $shown_event->event_name );
		$this->assertSame( 'Grüne Halle', $shown_location->location_name );
		$this->assertSame( 'Mühlenstraße 12', $shown_location->location_address );
		$this->assertSame( 'Varaždin', $shown_location->location_town, 'A text without a translation stays' );

		self::em( 'em_event_save_pre', $shown_event );
		self::em( 'em_location_save_pre', $shown_location );
		$this->assertSame( 'Dan otvorenog vrta', $shown_event->event_name, 'Saved as stored' );
		$this->assertSame( 'Mlinska ulica 12', $shown_location->location_address );

		$this->reset_state();
		list( $original ) = $render();
		$this->assertSame( 'Dan otvorenog vrta', $original->event_name );
	}

	/**
	 * A ticket is a row of the plugin's table: the editor of its event offers its name and description.
	 * A recurrence has no post, and its ticket is a copy of its recurring event's ticket: it shows that
	 * ticket's translation.
	 */
	public function test_tickets_of_an_event_and_its_recurrences() {
		global $wpdb;
		$post = self::factory()->post->create(
			array(
				'post_type'  => EventsManager::EVENT,
				'post_title' => 'Tjedni klub sjemenja',
			)
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's tables, filled for a test.
		$wpdb->insert(
			$wpdb->prefix . 'em_events',
			array(
				'post_id'    => $post,
				'event_name' => 'Tjedni klub sjemenja',
				'event_type' => 'recurring',
			)
		);
		$recurring = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'em_events',
			array(
				'event_name' => 'Tjedni klub sjemenja',
				'event_type' => 'recurrence',
			)
		);
		$recurrence = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'em_tickets',
			array(
				'event_id'           => $recurring,
				'ticket_name'        => 'Mjesto za stolom',
				'ticket_description' => 'Mjesto za stolom kluba.',
			)
		);
		$ticket = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'em_tickets',
			array(
				'event_id'      => $recurrence,
				'ticket_name'   => 'Mjesto za stolom',
				'ticket_parent' => $ticket,
			)
		);
		$copy = (int) $wpdb->insert_id;
		// phpcs:enable

		$result = $this->check( 'post:' . $post, '__return_null' );
		$name   = $this->offered( $result, 'Mjesto za stolom' );
		$this->assertSame( EventsManager::ticketPath( $ticket, 'name' ), $name['p'] );
		$this->assertSame( 'Ticket ' . $ticket . ' › Name', $name['place'] );
		$this->offered( $result, 'Mjesto za stolom kluba' );

		$this->translate( $name['o'], $name['p'], 'Mjesto za stolom', 'de', 'Platz am Tisch' );
		$tickets = static function () use ( $recurring, $recurrence, $ticket, $copy ) {
			$own  = (object) array(
				'ticket_id'          => $ticket,
				'event_id'           => $recurring,
				'ticket_parent'      => null,
				'ticket_name'        => 'Mjesto za stolom',
				'ticket_description' => '',
			);
			$date = (object) array(
				'ticket_id'          => $copy,
				'event_id'           => $recurrence,
				'ticket_parent'      => $ticket,
				'ticket_name'        => 'Mjesto za stolom',
				'ticket_description' => '',
			);
			self::em( 'em_ticket', $own, array(), $ticket );
			self::em( 'em_ticket', $date, array(), $copy );
			return array( $own, $date );
		};

		$this->show_in( 'de' );
		list( $own, $date ) = $tickets();
		$this->assertSame( 'Platz am Tisch', $own->ticket_name );
		$this->assertSame( 'Platz am Tisch', $date->ticket_name, 'The ticket of a recurrence shows its parent\'s translation' );

		$this->reset_state();
		list( $own ) = $tickets();
		$this->assertSame( 'Mjesto za stolom', $own->ticket_name );
	}

	/**
	 * The plugin's settings that hold text are a subject of their own, also the message after a
	 * booking, which no page check sees, and a page in a language shows them translated. A format of
	 * dates is never offered nor marked.
	 */
	public function test_settings_offered_and_translated() {
		update_option( 'dbem_search_form_submit', 'Traži' );
		update_option( 'dbem_booking_feedback_pending', 'Rezervacija je primljena.' );
		update_option( 'dbem_date_format', 'j. F Y.' );

		$result = swiftling_save_field_list( swiftling_subject( EventsManager::SETTINGS_SUBJECT ) );
		$search = $this->offered( $result, 'Traži' );
		$booked = $this->offered( $result, 'Rezervacija je primljena' );
		$this->assertSame( 'Search form submit', $search['place'] );
		$this->assertFalse( self::offers( $result, 'j. F Y.' ), 'A format of dates is no text' );

		$this->translate( 'options', $search['p'], 'Traži', 'de', 'Suchen' );
		$this->translate( 'options', $booked['p'], 'Rezervacija je primljena.', 'de', 'Buchung erhalten.' );

		$this->show_in( 'de' );
		$this->assertSame( 'Suchen', get_option( 'dbem_search_form_submit' ) );
		$this->assertSame( 'Buchung erhalten.', get_option( 'dbem_booking_feedback_pending' ) );
		$this->assertSame( 'j. F Y.', get_option( 'dbem_date_format' ) );

		$leaves = $this->probe(
			function () {
				$this->assertNotSame( 'Traži', get_option( 'dbem_search_form_submit' ) );
				$this->assertSame( 'j. F Y.', get_option( 'dbem_date_format' ), 'A format of dates is never marked' );
			}
		);
		$this->assertSame( array( 'Traži' ), array_values( wp_list_pluck( $leaves, 'v' ) ) );
	}

	/**
	 * A format places the event's fields with placeholders: a translation that leaves one out shows
	 * the original, and machine translation keeps them as they are.
	 */
	public function test_formats_keep_their_placeholders() {
		update_option( 'dbem_single_event_format', '<h3>Kada</h3><p>#_EVENTDATES #_{j. M}</p>' );
		$result = swiftling_save_field_list( swiftling_subject( EventsManager::SETTINGS_SUBJECT ) );
		$when   = $this->offered( $result, 'Kada' );

		$this->translate( 'options', $when['p'], $when['v'], 'de', str_replace( 'Kada', 'Wann', $when['v'] ) );
		$this->show_in( 'de' );
		$this->assertSame( '<h3>Wann</h3><p>#_EVENTDATES #_{j. M}</p>', get_option( 'dbem_single_event_format' ) );

		$this->reset_state();
		$this->translate( 'options', $when['p'], $when['v'], 'de', str_replace( array( 'Kada', ' #_{j. M}' ), array( 'Wann', '' ), $when['v'] ) );
		$this->show_in( 'de' );
		$this->assertSame( '<h3>Kada</h3><p>#_EVENTDATES #_{j. M}</p>', get_option( 'dbem_single_event_format' ), 'A placeholder is missing' );

		$items = apply_filters( 'swiftling_machine_items', array( 'a' => array( 'text' => '<p>Kada: #_EVENTDATES #_{j. M}</p>' ) ) );
		$this->assertSame( array( '#_EVENTDATES', '#_{j. M}' ), $items['a']['keep'] );
	}

	/**
	 * A booking and the search are sent from the page, in its language. An event and a location get no
	 * separate copy.
	 */
	public function test_bookings_in_the_language_and_no_copies() {
		foreach ( array( 'booking_add', 'booking_form_summary', 'search_events' ) as $action ) {
			$this->assertTrue( Registry::sendsForm( $action ), $action );
		}
		$this->assertFalse( Registry::sendsForm( 'booking_save' ) );
		foreach ( array( EventsManager::EVENT, EventsManager::LOCATION ) as $type ) {
			$this->assertFalse( swiftling_can_have_copies( get_post( self::factory()->post->create( array( 'post_type' => $type ) ) ) ), $type );
		}
	}
}
