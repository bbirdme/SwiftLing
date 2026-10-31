<?php
/**
 * Tests of the class of The Events Calendar.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\TheEventsCalendar;

require_once __DIR__ . '/stubs/FakeTheEventsCalendar.php';

/**
 * What SwiftLing translates of an event of The Events Calendar beyond its post: its venue and
 * organizer, the words the plugin's settings put around the calendar, the excerpt and the calendar
 * file the plugin makes from the stored post, and the words of the calendar's addresses.
 */
class TheEventsCalendarTest extends TestCase {

	/**
	 * The class, with the plugin's post types.
	 *
	 * @var FakeTheEventsCalendar
	 */
	private $tec;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		FakeTheEventsCalendar::install();
		$this->tec = new FakeTheEventsCalendar();
		$builders  = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof TheEventsCalendar ? $this->tec : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->tec->register();
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		$this->tec->removeFiles();
		delete_option( TheEventsCalendar::OPTION );
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Makes an event with a venue and an organizer, as the plugin keeps them.
	 *
	 * @return array{event: int, venue: int, organizer: int}
	 */
	private function event() {
		$venue     = self::factory()->post->create(
			array(
				'post_type'    => TheEventsCalendar::VENUE,
				'post_title'   => 'Zelena dvorana',
				'post_content' => 'Svijetla dvorana iza starog mlina.',
			)
		);
		$organizer = self::factory()->post->create(
			array(
				'post_type'  => TheEventsCalendar::ORGANIZER,
				'post_title' => 'Vrtlarsko društvo',
			)
		);
		update_post_meta( $venue, '_VenueAddress', 'Mlinska ulica 12' );
		update_post_meta( $venue, '_VenueCity', 'Varaždin' );
		update_post_meta( $venue, '_VenuePhone', '042 000 000' );
		update_post_meta( $organizer, '_OrganizerEmail', 'drustvo@example.com' );
		$event = self::factory()->post->create(
			array(
				'post_type'    => TheEventsCalendar::EVENT,
				'post_title'   => 'Dan otvorenog vrta',
				'post_content' => '<p>Dođite na dan među začinskim biljem.</p>',
				'post_excerpt' => '',
			)
		);
		update_post_meta( $event, '_EventVenueID', $venue );
		add_post_meta( $event, '_EventOrganizerID', $organizer );
		update_post_meta( $event, '_EventStartDate', '2026-12-12 10:00:00' );
		update_post_meta( $event, '_EventTimezone', 'Europe/Zagreb' );
		update_post_meta( $event, '_EventCost', 'Ulaz slobodan' );
		return array(
			'event'     => $event,
			'venue'     => $venue,
			'organizer' => $organizer,
		);
	}

	/**
	 * Returns a text the editor offers with a post's fields or on its page.
	 *
	 * @param array  $result What check() returned.
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
	 * @param array  $result What check() returned.
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
	 * Applies a filter of the plugin, as it does.
	 *
	 * @param string $hook    The filter.
	 * @param mixed  ...$args Its arguments.
	 * @return mixed
	 */
	private static function tec( $hook, ...$args ) {
		return apply_filters( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own filters, as it runs them.
	}

	/**
	 * A venue and an organizer have no editor of their own: the editor of the event offers their name,
	 * description and address, named, and none of their phone numbers or e-mail addresses. A page in a
	 * language shows them translated, also where the views print the venue's name straight from the
	 * post; the original prints them as stored.
	 */
	public function test_venue_and_organizer_offered_and_translated() {
		$ids    = $this->event();
		$result = $this->check( 'post:' . $ids['event'], '__return_null' );
		$name   = $this->offered( $result, 'Zelena dvorana' );
		$street = $this->offered( $result, 'Mlinska ulica' );
		$this->offered( $result, 'Svijetla dvorana' );
		$this->offered( $result, 'Varaždin' );
		$this->offered( $result, 'Vrtlarsko društvo' );
		$this->assertSame( 'post:' . $ids['venue'], $name['o'], 'A text of the venue is the venue\'s' );
		$this->assertSame( 'Venue: Zelena dvorana › Name', $name['place'] );
		$this->assertSame( 'Venue: Zelena dvorana › Address', $street['place'] );
		$this->assertFalse( self::offers( $result, '042 000' ), 'A phone number is no text' );
		$this->assertFalse( self::offers( $result, 'drustvo@' ), 'An e-mail address is no text' );

		$this->translate( $name['o'], $name['p'], 'Zelena dvorana', 'de', 'Grüne Halle' );
		$this->translate( $street['o'], $street['p'], 'Mlinska ulica 12', 'de', 'Mühlenstraße 12' );

		$venue = get_post( $ids['venue'] );
		$this->show_in( 'de' );
		$this->assertSame( 'Grüne Halle', self::tec( 'tribe_get_venue_object_after', $venue )->post_title );
		$this->assertSame( 'Grüne Halle', get_the_title( $ids['venue'] ) );
		$this->assertSame( 'Mühlenstraße 12', get_post_meta( $ids['venue'], '_VenueAddress', true ) );
		$this->assertSame( 'Zelena dvorana', get_post( $ids['venue'] )->post_title, 'The post in the cache keeps its title' );

		$this->reset_state();
		$this->assertSame( 'Zelena dvorana', self::tec( 'tribe_get_venue_object_after', $venue )->post_title );
		$this->assertSame( 'Mlinska ulica 12', get_post_meta( $ids['venue'], '_VenueAddress', true ) );
	}

	/**
	 * The plugin reads its dates, times and time zone back from the event's fields: a page check
	 * never marks them, while it marks the cost.
	 */
	public function test_dates_and_time_zone_never_marked() {
		$ids    = $this->event();
		$leaves = $this->probe(
			function () use ( $ids ) {
				$this->assertSame( 'Europe/Zagreb', get_post_meta( $ids['event'], '_EventTimezone', true ) );
				$this->assertSame( '2026-12-12 10:00:00', get_post_meta( $ids['event'], '_EventStartDate', true ) );
				$this->assertNotSame( 'Ulaz slobodan', get_post_meta( $ids['event'], '_EventCost', true ), 'The cost is marked' );
			}
		);
		$this->assertSame( array( 'Ulaz slobodan' ), array_values( wp_list_pluck( $leaves, 'v' ) ) );
	}

	/**
	 * The words before and after the calendar are a subject of their own, with the formats of dates:
	 * offered, and shown translated where the plugin hands the setting out, which it read before
	 * SwiftLing started. The slugs of the calendar's addresses are no text: never offered, never
	 * marked. A page check marks the words but never a format, which the plugin hands to date().
	 */
	public function test_settings_offered_and_translated() {
		update_option(
			TheEventsCalendar::OPTION,
			array(
				'tribeEventsBeforeHTML' => '<p>Svi događaji su besplatni.</p>',
				'dateWithYearFormat'    => 'j. F Y.',
				'eventsSlug'            => 'dogadaji',
				'postsPerPage'          => '12',
			)
		);
		$result = swiftling_save_field_list( swiftling_subject( TheEventsCalendar::SETTINGS_SUBJECT ) );
		$before = $this->offered( $result, 'Svi događaji' );
		$format = $this->offered( $result, 'j. F Y.' );
		$this->assertSame( 'Before the calendar', $before['place'] );
		$this->assertFalse( self::offers( $result, 'dogadaji' ), 'A slug is no text' );
		$this->offered( $result, 'F Y' );
		$no_year = $this->offered( $result, 'F j' );
		$this->assertSame( 'option/' . TheEventsCalendar::OPTION . '/dateWithoutYearFormat', $no_year['p'], 'A format no one saved is offered as the plugin\'s default' );

		$this->translate( 'options', $before['p'], '<p>Svi događaji su besplatni.</p>', 'de', '<p>Alle Veranstaltungen sind kostenlos.</p>' );
		$this->translate( 'options', $format['p'], 'j. F Y.', 'de', 'j. F Y' );
		$this->translate( 'options', $no_year['p'], 'F j', 'de', 'j. F' );

		$this->show_in( 'de' );
		$this->assertSame( '<p>Alle Veranstaltungen sind kostenlos.</p>', self::tec( 'tribe_get_option', '<p>Svi događaji su besplatni.</p>', 'tribeEventsBeforeHTML' ) );
		$this->assertSame( 'j. F Y', self::tec( 'tribe_get_option', 'j. F Y.', 'dateWithYearFormat' ) );
		$this->assertSame( 'dogadaji', self::tec( 'tribe_get_option', 'dogadaji', 'eventsSlug' ) );
		$this->assertSame( 'j. F', self::tec( 'tribe_get_option', 'F j', 'dateWithoutYearFormat' ), 'The default, as the plugin hands it out' );

		$leaves = $this->probe(
			function () {
				$this->assertNotSame( '<p>Svi događaji su besplatni.</p>', self::tec( 'tribe_get_option', '<p>Svi događaji su besplatni.</p>', 'tribeEventsBeforeHTML' ) );
				$this->assertSame( 'j. F Y.', self::tec( 'tribe_get_option', 'j. F Y.', 'dateWithYearFormat' ), 'A format is never marked' );
				$this->assertSame( 'dogadaji', self::tec( 'tribe_get_option', 'dogadaji', 'eventsSlug' ) );
			}
		);
		$this->assertCount( 1, $leaves );

		$this->reset_state();
		$this->assertSame( '<p>Svi događaji su besplatni.</p>', self::tec( 'tribe_get_option', '<p>Svi događaji su besplatni.</p>', 'tribeEventsBeforeHTML' ) );
	}

	/**
	 * The rules of the calendar's addresses hold the words of every language, in the same order
	 * whatever the language of the request that makes them, also when the plugin's own word in that
	 * language differs in case, as "Seite": otherwise the rules made in one language do not match the
	 * words of another, and the calendar's links keep the rule's pattern. An address shows the word of
	 * the language of its page.
	 */
	public function test_address_words_of_every_language() {
		$in_croatian = self::tec(
			'tribe_events_rewrite_i18n_slugs_raw',
			array(
				'month' => array( 'month', 'mjesec' ),
				'list'  => array( 'list', 'popis' ),
				'page'  => array( 'page', 'page' ),
			),
			'regex',
			array()
		);
		$in_german   = self::tec(
			'tribe_events_rewrite_i18n_slugs_raw',
			array(
				'month' => array( 'month', 'monat' ),
				'list'  => array( 'list', 'liste' ),
				'page'  => array( 'page', 'Seite' ),
			),
			'regex',
			array()
		);
		$this->assertSame( $in_croatian, $in_german );
		$this->assertSame( array( 'month', 'monat', 'mois', 'mjesec' ), $in_german['month'] );
		$this->assertSame( array( 'page', 'seite' ), $in_german['page'] );

		$this->show_in( 'de' );
		$this->assertSame( 'monat', self::tec( 'tec_common_rewrite_localize_matcher', null, 'month' ) );
		$this->reset_state();
		$this->assertSame( 'mjesec', self::tec( 'tec_common_rewrite_localize_matcher', null, 'month' ), 'The original has the word of its own language' );
	}

	/**
	 * The language switcher and the alternates of a view of the calendar lead to the same view in each
	 * language, with the words of that language; elsewhere the address stays as SwiftLing built it.
	 */
	public function test_view_in_another_language_has_its_words() {
		$this->go_to( '/?post_type=' . TheEventsCalendar::EVENT );
		$this->assertTrue( is_post_type_archive( TheEventsCalendar::EVENT ) );
		$this->assertSame( 'http://example.org/events/liste/2/?lang=de', Registry::currentAddress( 'http://example.org/events/popis/2/?lang=de', 'de' ) );

		$_SERVER['REQUEST_URI'] = '/events/popis/';
		$links                  = self::tec(
			'swiftling_hreflangs',
			array(
				'hr'        => 'http://example.org/events/',
				'de-DE'     => 'http://example.org/events/?lang=de',
				'x-default' => 'http://example.org/events/',
			)
		);
		$this->assertSame( 'http://example.org/events/liste/?lang=de', $links['de-DE'] );
		$this->assertSame( 'http://example.org/events/popis/', $links['hr'] );

		$this->go_to( '/?p=' . self::factory()->post->create() );
		$this->assertSame( 'http://example.org/events/popis/?lang=de', Registry::currentAddress( 'http://example.org/events/popis/?lang=de', 'de' ), 'Only a view of the calendar' );
	}

	/**
	 * Once the languages change, WordPress builds its rules of addresses again, with the words of the
	 * languages there are now.
	 */
	public function test_rules_built_again_when_the_languages_change() {
		update_option( 'rewrite_rules', array( 'events/month/?$' => 'index.php?post_type=tribe_events' ) );
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->assertFalse( get_option( 'rewrite_rules' ) );
	}

	/**
	 * The plugin keeps the HTML of the month view by the locale and empties it only when an event
	 * changes: only the original keeps it. A page check, which renders in the original's locale,
	 * would serve its markers to visitors of the original.
	 */
	public function test_month_view_cached_only_in_the_original() {
		$this->assertTrue( self::tec( 'tribe_events_views_v2_should_cache_html', true ) );
		$this->show_in( 'de' );
		$this->assertFalse( self::tec( 'tribe_events_views_v2_should_cache_html', true ) );
		$this->probe(
			function () {
				$this->assertFalse( self::tec( 'tribe_events_views_v2_should_cache_html', true ) );
			}
		);
	}

	/**
	 * The views show an excerpt the plugin makes from the stored content: in a language, from its
	 * translation.
	 */
	public function test_excerpt_made_from_the_translation() {
		$ids    = $this->event();
		$result = $this->check(
			'post:' . $ids['event'],
			static function () use ( $ids ) {
				$GLOBALS['post'] = get_post( $ids['event'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The page of the event, as WordPress sets it up.
				setup_postdata( $GLOBALS['post'] );
				apply_filters( 'the_content', get_post( $ids['event'] )->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a page runs it.
				wp_reset_postdata();
			}
		);
		$text   = $this->found( $result, 'Dođite' );
		$this->translate( $text['o'], $text['p'], $text['v'], 'de', 'Kommen Sie zu einem Tag unter Kräutern.' );

		$made = "<p>Dođite na dan među začinskim biljem.</p>\n";
		$this->show_in( 'de' );
		$this->assertStringContainsString( 'Kommen Sie zu einem Tag', self::tec( 'tribe_events_get_the_excerpt', $made, get_post( $ids['event'] ) ) );
		$this->reset_state();
		$this->assertSame( $made, self::tec( 'tribe_events_get_the_excerpt', $made, get_post( $ids['event'] ) ) );
	}

	/**
	 * The calendar file writes the title of an event and the name of its organizer straight from the
	 * posts: in a language, translated.
	 */
	public function test_calendar_file_in_the_language() {
		$ids    = $this->event();
		$result = $this->check( 'post:' . $ids['event'], '__return_null' );
		$title  = $this->offered( $result, 'Dan otvorenog vrta' );
		$org    = $this->offered( $result, 'Vrtlarsko društvo' );
		$this->translate( $title['o'], $title['p'], 'Dan otvorenog vrta', 'de', 'Tag des offenen Gartens, mit Tee' );
		$this->translate( $org['o'], $org['p'], 'Vrtlarsko društvo', 'de', 'Gartenverein' );
		$item = array(
			'SUMMARY'   => 'SUMMARY:Dan otvorenog vrta',
			// The plugin encodes what a calendar file does not allow in a name, as "š".
			'ORGANIZER' => 'ORGANIZER;CN="Vrtlarsko dru%C5%A1tvo":MAILTO:drustvo@example.com',
		);

		$this->show_in( 'de' );
		$shown = self::tec( 'tribe_ical_feed_item', $item, get_post( $ids['event'] ) );
		$this->assertSame( 'SUMMARY:Tag des offenen Gartens\, mit Tee', $shown['SUMMARY'] );
		$this->assertSame( 'ORGANIZER;CN="Gartenverein":MAILTO:drustvo@example.com', $shown['ORGANIZER'] );

		$this->reset_state();
		$this->assertSame( $item, self::tec( 'tribe_ical_feed_item', $item, get_post( $ids['event'] ) ) );
	}

	/**
	 * The calendar asks for another month or page from the page, in its language. An event gets no
	 * separate copy: a copy has no dates in the plugin's tables.
	 */
	public function test_views_in_the_language_and_no_copies() {
		$this->assertTrue( Registry::sendsFormTo( '/tribe/views/v2/html' ) );
		$this->assertFalse( Registry::sendsFormTo( '/tribe/events/v1/events' ) );
		$this->assertFalse( swiftling_can_have_copies( get_post( self::factory()->post->create( array( 'post_type' => TheEventsCalendar::EVENT ) ) ) ) );
	}
}
