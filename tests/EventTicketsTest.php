<?php
/**
 * Tests of the class of Event Tickets.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\EventTickets;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeEventTickets.php';

/**
 * The tickets sold on a post: their names and descriptions in the post's editor and in the language
 * of the page wherever the plugin hands them out, and the order sent in that language.
 */
class EventTicketsTest extends TestCase {

	/**
	 * The class, with the plugin's type of post of a ticket.
	 *
	 * @var FakeEventTickets
	 */
	private $tickets;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		FakeEventTickets::install();
		$this->tickets = new FakeEventTickets();
		$builders      = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof EventTickets ? $this->tickets : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->tickets->register();
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
	 * Applies a filter of the plugin, as it does.
	 *
	 * @param string $hook    The filter.
	 * @param mixed  ...$args Its arguments.
	 * @return mixed
	 */
	private static function et( $hook, ...$args ) {
		return apply_filters( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own filters, as it runs them.
	}

	/**
	 * A ticket has no editor of its own: the editor of the post it is sold on offers its name and
	 * description, named. A page in a language shows them translated where the plugin hands out the
	 * ticket and an attendee of an order; the original shows them as stored.
	 */
	public function test_tickets_offered_and_translated() {
		$event  = self::factory()->post->create( array( 'post_title' => 'Dan otvorenog vrta' ) );
		$ticket = self::factory()->post->create(
			array(
				'post_type'    => EventTickets::TICKET,
				'post_title'   => 'Mjesto za radionicu',
				'post_excerpt' => 'Mjesto za stolom za presađivanje.',
			)
		);
		update_post_meta( $ticket, EventTickets::EVENT_KEY, $event );

		$result = $this->check( 'post:' . $event, '__return_null' );
		$name   = $this->offered( $result, 'Mjesto za radionicu' );
		$desc   = $this->offered( $result, 'Mjesto za stolom' );
		$this->assertSame( 'post:' . $ticket, $name['o'] );
		$this->assertSame( 'Ticket: Mjesto za radionicu › Name', $name['place'] );
		$this->assertSame( 'Ticket: Mjesto za radionicu › Description', $desc['place'] );

		$this->translate( $name['o'], $name['p'], 'Mjesto za radionicu', 'de', 'Platz im Workshop' );
		$this->translate( $desc['o'], $desc['p'], 'Mjesto za stolom za presađivanje.', 'de', 'Ein Platz am Pflanztisch.' );

		$object   = static function () use ( $ticket ) {
			return (object) array(
				'ID'          => $ticket,
				'name'        => 'Mjesto za radionicu',
				'description' => 'Mjesto za stolom za presađivanje.',
			);
		};
		$attendee = array(
			'product_id' => $ticket,
			'ticket'     => 'Mjesto za radionicu',
		);

		$this->show_in( 'de' );
		$shown = self::et( 'tec_tickets_commerce_get_ticket_legacy', $object(), $event, $ticket );
		$this->assertSame( 'Platz im Workshop', $shown->name );
		$this->assertSame( 'Ein Platz am Pflanztisch.', $shown->description );
		$this->assertSame( 'Platz im Workshop', self::et( 'tec_tickets_commerce_get_attendee_after', $attendee )['ticket'] );

		$this->reset_state();
		$this->assertSame( 'Mjesto za radionicu', self::et( 'tec_tickets_commerce_get_ticket_legacy', $object(), $event, $ticket )->name );
		$this->assertSame( $attendee, self::et( 'tec_tickets_commerce_get_attendee_after', $attendee ) );
	}

	/**
	 * The checkout sends the order from its page: answered in its language, with the address of the
	 * confirmation in it. Other routes of the plugin stay in the original.
	 */
	public function test_order_sent_in_the_language_of_the_page() {
		foreach ( array( 'free', 'paypal', 'square', 'stripe' ) as $gateway ) {
			$this->assertTrue( Registry::sendsFormTo( '/tribe/tickets/v1/commerce/' . $gateway . '/order' ), $gateway );
		}
		$this->assertFalse( Registry::sendsFormTo( '/tribe/tickets/v1/commerce/stripe/webhook' ) );
		$this->assertFalse( Registry::sendsFormTo( '/tribe/tickets/v1/attendees' ) );
	}
}
