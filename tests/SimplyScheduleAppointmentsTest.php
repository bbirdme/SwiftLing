<?php
/**
 * Tests of the class of Simply Schedule Appointments.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\SimplyScheduleAppointments;

require_once __DIR__ . '/stubs/FakeSimplyScheduleAppointments.php';

/**
 * What SwiftLing translates of Simply Schedule Appointments: its appointment types where the plugin
 * prepares them, the requests of its frame in the language of the page, and its emails in the
 * language the customer booked in, those to the staff in the original.
 */
class SimplyScheduleAppointmentsTest extends TestCase {

	/**
	 * The class, with the locales of the appointments.
	 *
	 * @var FakeSimplyScheduleAppointments
	 */
	private $ssa;

	/**
	 * The appointment type of the tests, as the plugin prepares it.
	 *
	 * @var array
	 */
	private $type = array(
		'id'           => 1,
		'title'        => 'Obilazak vrta',
		'description'  => 'Šetnja među biljem.',
		'instructions' => 'Ponesite cipele za travu.',
		'slug'         => 'obilazak-vrta',
		'duration'     => 60,
	);

	/**
	 * Puts the fake in the registry and hooks it, with the plugin's notifications: one to the staff,
	 * one to the customer.
	 */
	public function set_up() {
		parent::set_up();
		$this->ssa = new FakeSimplyScheduleAppointments();
		$builders  = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof SimplyScheduleAppointments ? $this->ssa : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->ssa->register();
		swiftling_test_ssa()->appointment_type_model->types = array( $this->type );
		update_option(
			SimplyScheduleAppointments::OPTION,
			wp_json_encode(
				array(
					'global'        => array( 'admin_email' => 'vrt@example.org' ),
					'notifications' => array(
						'notifications' => array(
							array(
								'id'      => 101,
								'title'   => 'Booked (staff)',
								'sent_to' => array( '{{admin_email}}' ),
								'subject' => '{{ customer_name }} je rezervirao',
								'message' => 'Nova rezervacija.',
							),
							array(
								'id'      => 102,
								'title'   => 'Booked (customer)',
								'sent_to' => array( '{{customer_email}}' ),
								'subject' => 'Vaš termin, {{ customer_name }}',
								'message' => '<p>Hvala na rezervaciji.</p>',
							),
						),
					),
				)
			)
		);
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
	 * Returns the units the subject of the plugin's settings stores, by original.
	 *
	 * @return array<string, array>
	 */
	private function units() {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'simply-schedule-appointments-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		return $units;
	}

	/**
	 * The subject of the plugin's settings offers the texts of the appointment types and the templates
	 * of the emails to the customer, not those to the staff, nor any other setting. Translated, a type
	 * the plugin prepares in a language has its texts in it; the original keeps them.
	 */
	public function test_types_and_emails_to_the_customer_offered_and_translated() {
		$units = $this->units();
		foreach ( array( 'Obilazak vrta', 'Šetnja među biljem.', 'Ponesite cipele za travu.', 'Vaš termin, {{ customer_name }}', '<p>Hvala na rezervaciji.</p>' ) as $text ) {
			$this->assertArrayHasKey( $text, $units, $text );
		}
		$this->assertArrayNotHasKey( 'Nova rezervacija.', $units, 'An email to the staff' );
		$this->assertArrayNotHasKey( 'vrt@example.org', $units );
		$this->assertSame( 'Appointment type 1 › Name', Registry::fieldLabel( $units['Obilazak vrta']['o'], $units['Obilazak vrta']['p'] ) );
		$this->assertSame( 'Booked (customer) › Subject', Registry::fieldLabel( 'options', $units['Vaš termin, {{ customer_name }}']['p'] ) );
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/' . SimplyScheduleAppointments::OPTION . '/@json/notifications/notifications/#101/subject' ) );

		$this->translate( $units['Obilazak vrta']['o'], $units['Obilazak vrta']['p'], 'Obilazak vrta', 'de', 'Gartenrundgang' );
		$this->show_in( 'de' );
		$type = apply_filters( 'ssa/appointment_type/prepare_item_for_response', $this->type, 1, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The plugin's own filter, as it runs it.
		$this->assertSame( 'Gartenrundgang', $type['title'] );
		$this->assertSame( 'obilazak-vrta', $type['slug'] );

		$this->reset_state();
		$type = apply_filters( 'ssa/appointment_type/prepare_item_for_response', $this->type, 1, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- As above.
		$this->assertSame( 'Obilazak vrta', $type['title'], 'The original' );
	}

	/**
	 * The plugin's frame and its booking are answered in the language of the page: the frame's
	 * requests name the frame, whose address names the page. Other routes of the plugin are not.
	 */
	public function test_requests_of_the_frame_in_the_language_of_the_page() {
		$frame = 'http://example.org/wp-json/ssa/v1/embed-inner?ssa_locale=de_DE&booking_url=' . rawurlencode( 'http://example.org/termin/?lang=de' );
		$this->assertSame( 'http://example.org/termin/?lang=de', Registry::sentFrom( $frame ) );
		$this->assertSame( 'http://example.org/termin/', Registry::sentFrom( 'http://example.org/termin/' ) );

		$_SERVER['HTTP_REFERER'] = $frame;
		foreach ( array(
			'/wp-json/ssa/v1/appointment_types/1/availability' => 'de',
			'/wp-json/ssa/v1/appointments' => 'de',
			'/wp-json/ssa/v1/settings'     => '',
		) as $uri => $expected ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$decided                = &swiftling_decided_language();
			$decided                = null;
			$this->assertSame( $expected, swiftling_decide_translation_language(), $uri );
		}
	}

	/**
	 * While the plugin sends a notification of an appointment, the request is in the language the
	 * customer booked in when it goes to the customer, and in the original when it goes to the staff,
	 * also in a request in a language; afterwards it is as it was. The email to the customer is written
	 * again from the translation of its template; the one to the staff stays.
	 */
	public function test_emails_in_the_language_of_the_booking() {
		$units = $this->units();
		$this->translate( 'options', $units['Vaš termin, {{ customer_name }}']['p'], 'Vaš termin, {{ customer_name }}', 'de', 'Ihr Termin, {{ customer_name }}' );
		$this->ssa->locales = array( 7 => 'de_DE' );

		$seen = array();
		$send = function ( $payload ) use ( &$seen ) {
			$state  = &swiftling_state();
			$seen[] = $state['mode'] . ':' . $state['language'];
			if ( 102 === $payload['notification']['id'] ) {
				$seen[] = apply_filters( 'ssa/notifications/email/args', array( 'subject' => 'Vaš termin, Ana' ), $this->notification( 102 ), array( 'customer_name' => 'Ana' ), new \stdClass(), 'customer' )['subject']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The plugin's own filter, as it runs it.
			} else {
				$seen[] = apply_filters( 'ssa/notifications/email/args', array( 'subject' => 'Ana je rezervirao' ), $this->notification( 101 ), array( 'customer_name' => 'Ana' ), new \stdClass(), 'staff' )['subject']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- As above.
			}
		};
		add_action( 'ssa_fire_appointment_booked_notifications', $send, 10, 1 );

		$this->show_in( 'fr' );
		foreach ( array( 102, 101 ) as $notification ) {
			do_action( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own action, as it runs it.
				'ssa_fire_appointment_booked_notifications', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
				array(
					'notification' => array( 'id' => $notification ),
					'appointment'  => array( 'id' => 7 ),
				),
				array()
			);
		}
		$this->assertSame( array( 'translate:de', 'Ihr Termin, Ana', ':', 'Ana je rezervirao' ), $seen );
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'fr', swiftling_state()['language'], 'The request as it was' );
		remove_action( 'ssa_fire_appointment_booked_notifications', $send, 10 );
	}

	/**
	 * Returns a notification of the tests' settings, as the plugin read them first in the request,
	 * untranslated.
	 *
	 * @param int $id The notification's ID.
	 * @return array
	 */
	private function notification( $id ) {
		foreach ( json_decode( swiftling_raw_option( SimplyScheduleAppointments::OPTION ), true )['notifications']['notifications'] as $notification ) {
			if ( $id === $notification['id'] ) {
				return $notification;
			}
		}
		return array();
	}

	/**
	 * Machine translation keeps the placeholders of the plugin's templates as they are.
	 */
	public function test_placeholders_kept() {
		$items = apply_filters( 'swiftling_machine_items', array( 'a' => array( 'text' => 'Bok {{ customer_name }}, {% if x %}da{% endif %}' ) ) );
		$this->assertSame( array( '{{ customer_name }}', '{% if x %}', '{% endif %}' ), $items['a']['keep'] );
	}
}
