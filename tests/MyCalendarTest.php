<?php
/**
 * Tests of the class of My Calendar.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\MyCalendar;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeMyCalendar.php';

/**
 * What SwiftLing translates of My Calendar: the rows of the plugin's tables it prints an event, a
 * location and the calendar's legend from, put back as stored once printed.
 */
class MyCalendarTest extends TestCase {

	/**
	 * The class, with the plugin's types of post.
	 *
	 * @var FakeMyCalendar
	 */
	private $mc;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		FakeMyCalendar::install();
		$this->mc = new FakeMyCalendar();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof MyCalendar ? $this->mc : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->mc->register();
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
	 * Runs an action or a filter of the plugin, as it does.
	 *
	 * @param string $hook    The action or filter.
	 * @param mixed  ...$args Its arguments.
	 * @return mixed
	 */
	private static function mc( $hook, ...$args ) {
		return apply_filters( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own hooks, as it runs them.
	}

	/**
	 * Makes an event with its location and category, as the plugin's rows and their posts.
	 *
	 * @return array{event: int, location: int, term: int, row: object}
	 */
	private function event() {
		$event    = self::factory()->post->create(
			array(
				'post_type'    => MyCalendar::EVENT,
				'post_title'   => 'Dan otvorenog vrta',
				'post_content' => '<p>Dođite na dan među biljem.</p>',
				'post_excerpt' => 'Dan među biljem.',
			)
		);
		$location = self::factory()->post->create(
			array(
				'post_type'  => MyCalendar::LOCATION,
				'post_title' => 'Zelena dvorana',
			)
		);
		$term     = self::factory()->term->create(
			array(
				'taxonomy' => 'mc-event-category',
				'name'     => 'Radionice',
			)
		);
		$row      = (object) array(
			'event_post'         => $event,
			'event_title'        => 'Dan otvorenog vrta',
			'event_desc'         => '<p>Dođite na dan među biljem.</p>',
			'event_short'        => 'Dan među biljem.',
			'event_registration' => 'Prijave do 10. prosinca.',
			'categories'         => array(
				(object) array(
					'category_name' => 'Radionice',
					'category_term' => $term,
				),
			),
			'location'           => (object) array(
				'location_post'   => $location,
				'location_label'  => 'Zelena dvorana',
				'location_street' => 'Mlinska ulica 12',
				'location_city'   => 'Varaždin',
			),
		);
		return array(
			'event'    => $event,
			'location' => $location,
			'term'     => $term,
			'row'      => $row,
		);
	}

	/**
	 * Returns what the plugin's tags and templates read of an event's row while it draws the event.
	 *
	 * @param object $row The row.
	 * @return string[]
	 */
	private static function printed( $row ) {
		return array( $row->event_title, $row->event_desc, $row->event_registration, $row->categories[0]->category_name, $row->location->location_label, $row->location->location_street );
	}

	/**
	 * While the plugin makes an event's tags and draws it, the row's texts, its location's and its
	 * category's are found by a page check and shown translated in a language, under the paths of the
	 * posts and terms that mirror them. Once drawn, the row is as stored again, so the plugin's caches
	 * of rows never keep a translation; started twice, as the plugin does, it is put back once.
	 */
	public function test_rows_translated_while_drawn_and_put_back() {
		$ids    = $this->event();
		$row    = $ids['row'];
		$seen   = array();
		$result = $this->check(
			'post:' . $ids['event'],
			static function () use ( $row, &$seen ) {
				self::mc( 'my_calendar_drawing_event', $row );
				self::mc( 'mc_create_tags', $row );
				$seen = self::printed( $row );
				self::mc( 'mc_tags_created', $row );
				self::mc( 'my_calendar_event_drawn', $row );
			}
		);
		$this->assertNotSame( 'Dan otvorenog vrta', $seen[0], 'Marked while drawn' );
		$this->assertSame( 'Dan otvorenog vrta', $row->event_title, 'Put back once drawn' );
		$title  = $this->found( $result, 'Dan otvorenog vrta' );
		$reg    = $this->found( $result, 'Prijave do' );
		$street = $this->found( $result, 'Mlinska ulica' );
		$this->assertSame( 'field/post_title', $title['p'] );
		$this->assertSame( 'Registration information', Registry::fieldLabel( $reg['o'], $reg['p'] ) );
		$this->assertSame( 'post:' . $ids['location'], $street['o'] );

		$this->translate( $title['o'], $title['p'], 'Dan otvorenog vrta', 'de', 'Tag des offenen Gartens' );
		$this->translate( $reg['o'], $reg['p'], 'Prijave do 10. prosinca.', 'de', 'Anmeldung bis 10. Dezember.' );
		$this->translate( $street['o'], $street['p'], 'Mlinska ulica 12', 'de', 'Mühlenstraße 12' );
		$this->translate( 'term:' . $ids['term'], 'field/name', 'Radionice', 'de', 'Workshops' );

		$this->show_in( 'de' );
		self::mc( 'my_calendar_drawing_event', $row );
		self::mc( 'mc_create_tags', $row );
		$this->assertSame( array( 'Tag des offenen Gartens', '<p>Dođite na dan među biljem.</p>', 'Anmeldung bis 10. Dezember.', 'Workshops', 'Zelena dvorana', 'Mühlenstraße 12' ), self::printed( $row ) );
		self::mc( 'mc_tags_created', $row );
		$this->assertSame( 'Tag des offenen Gartens', $row->event_title, 'Still drawn' );
		self::mc( 'my_calendar_event_drawn', $row );
		$this->assertSame( array( 'Dan otvorenog vrta', '<p>Dođite na dan među biljem.</p>', 'Prijave do 10. prosinca.', 'Radionice', 'Zelena dvorana', 'Mlinska ulica 12' ), self::printed( $row ) );
	}

	/**
	 * On an event's page the plugin puts the row's title in place of the post's: shown translated. On a
	 * location's page and in its structured data the location's address, and in the calendar's legend
	 * the names of the categories.
	 */
	public function test_title_location_page_and_legend() {
		$ids = $this->event();
		$this->translate( 'post:' . $ids['event'], 'field/post_title', 'Dan otvorenog vrta', 'de', 'Tag des offenen Gartens' );
		$this->check(
			'post:' . $ids['location'],
			static function () use ( $ids ) {
				self::mc( 'mc_display_location_events', array(), clone $ids['row']->location );
			}
		);
		$this->translate( 'post:' . $ids['location'], MyCalendar::BASE . '/location_street', 'Mlinska ulica 12', 'de', 'Mühlenstraße 12' );
		$this->check(
			'post:' . $ids['event'],
			static function () use ( $ids ) {
				get_the_title( $ids['event'] );
				self::mc(
					'mc_category_key_array',
					array(
						(object) array(
							'category_name' => 'Radionice',
							'category_term' => $ids['term'],
						),
					)
				);
			}
		);
		$this->translate( 'term:' . $ids['term'], 'field/name', 'Radionice', 'de', 'Workshops' );

		// The plugin puts the row's title, after an icon, in place of the post's (10).
		$plugin = static function () {
			return ' Dan otvorenog vrta';
		};
		add_filter( 'the_title', $plugin, 10 );

		$this->show_in( 'de' );
		$this->assertSame( ' Tag des offenen Gartens', self::mc( 'the_title', 'Dan otvorenog vrta', $ids['event'] ), 'The plugin\'s title, with its icon' );
		$location = clone $ids['row']->location;
		self::mc( 'mc_display_location_events', array(), $location );
		$this->assertSame( 'Mühlenstraße 12', $location->location_street );
		$schema = self::mc(
			'mc_location_schema',
			array(
				'name'    => 'Zelena dvorana',
				'address' => array( 'streetAddress' => 'Mlinska ulica 12' ),
			),
			$ids['row']->location
		);
		$this->assertSame( 'Mühlenstraße 12', $schema['address']['streetAddress'] );
		$this->assertSame( 'Mlinska ulica 12', $ids['row']->location->location_street, 'The location as the plugin keeps it' );
		$legend = self::mc(
			'mc_category_key_array',
			array(
				(object) array(
					'category_name' => 'Radionice',
					'category_term' => $ids['term'],
				),
			)
		);
		$this->assertSame( 'Workshops', $legend[0]->category_name );

		$this->reset_state();
		$this->assertSame( ' Dan otvorenog vrta', self::mc( 'the_title', 'Dan otvorenog vrta', $ids['event'] ) );
		remove_filter( 'the_title', $plugin, 10 );
	}

	/**
	 * More upcoming events and the search of locations are sent from the page, in its language. An event and a location get no
	 * separate copy: a copy has no row in the plugin's tables.
	 */
	public function test_search_in_the_language_and_no_copies() {
		$this->assertTrue( Registry::sendsForm( 'mcjs_action' ) );
		$this->assertTrue( Registry::sendsForm( 'mc_core_autocomplete_search_locations' ) );
		$this->assertSame( array( MyCalendar::EVENT, MyCalendar::LOCATION ), array_values( array_intersect( array( MyCalendar::EVENT, MyCalendar::LOCATION ), Registry::noCopyTypes() ) ) );
	}
}
