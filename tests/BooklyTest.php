<?php
/**
 * Tests of the class of Bookly.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use Bookly\Lib\Notifications\Booking\BaseSender;
use Bookly\Lib\Notifications\Booking\Item;
use SwiftLing\Builders\Bookly;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeBookly.php';

/**
 * What SwiftLing translates of Bookly: the texts the plugin hands to the filter by name, in the
 * language of the page, of the booking for an email to the customer and in the original for one to the
 * staff, and the language a booking keeps.
 */
class BooklyTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeBookly
	 */
	private $bookly;

	/**
	 * Puts the fake in the registry and hooks it, with a label of the form and a service.
	 */
	public function set_up() {
		global $wpdb;
		parent::set_up();
		FakeBookly::install();
		$this->bookly = new FakeBookly();
		$builders     = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Bookly ? $this->bookly : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->bookly->register();
		update_option( 'bookly_l10n_label_service', 'Usluga' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The tests' rows.
			$wpdb->prefix . 'bookly_services',
			array(
				'title' => 'Obilazak vrta',
				'info'  => 'Šetnja među biljem.',
			)
		);
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'bookly-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		foreach ( array(
			'Usluga'        => 'Dienstleistung',
			'Obilazak vrta' => 'Gartenrundgang',
		) as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
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
	 * Asks for a text as the plugin does.
	 *
	 * @param string $name    The plugin's name of the text.
	 * @param string $text    The text.
	 * @param string $context The plugin's domain.
	 * @return mixed
	 */
	private static function text( $name, $text, $context = 'bookly' ) {
		return apply_filters( 'wpml_translate_single_string', $text, $context, $name, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The filter the plugin hands its texts to.
	}

	/**
	 * The subject of the plugin's settings offers its labels and services by their names. Translated,
	 * the plugin's texts are in the language of the page; another plugin's texts handed to the same
	 * filter, and the plugin's in the original, stay.
	 */
	public function test_texts_by_name_in_the_language_of_the_page() {
		$this->assertSame( 'Label service', Registry::fieldLabel( 'bookly:strings', 'bookly/bookly_l10n_label_service' ) );
		$this->show_in( 'de' );
		$this->assertSame( 'Dienstleistung', self::text( 'bookly_l10n_label_service', 'Usluga' ) );
		$this->assertSame( 'Gartenrundgang', self::text( 'service_1', 'Obilazak vrta' ) );
		$this->assertSame( 'Usluga', self::text( 'bookly_l10n_label_service', 'Usluga', 'other-plugin' ), 'Another plugin' );
		$this->reset_state();
		$this->assertSame( 'Usluga', self::text( 'bookly_l10n_label_service', 'Usluga' ), 'The original' );
		$this->assertContains( 'bookly_save_appointment', $this->bookly->sendingActions() );
	}

	/**
	 * An email to the customer is written in the language the customer booked in, also from a request
	 * in the original, as a reminder; one to the staff in the original, also from a request in a
	 * language, and the texts the request translated before are back in the original in it.
	 */
	public function test_emails_by_who_they_go_to() {
		$texts = array( 'service_1' => 'Obilazak vrta' );
		$this->assertSame( array( 'service_1' => 'Gartenrundgang' ), BaseSender::notifyClient( $texts, new Item( 'de_DE' ) ) );
		$this->assertSame( $texts, BaseSender::notifyClient( $texts, new Item( null ) ), 'A booking in the original' );

		$this->show_in( 'de' );
		$this->assertSame( array( 'service_1' => 'Gartenrundgang' ), BaseSender::notifyClient( $texts, new Item( null ) ), 'Booked now, from a page in a language' );
		$this->assertSame( $texts, BaseSender::notifyStaffAndAdmins( $texts, new Item( 'de_DE' ) ) );
		$mail = apply_filters(
			'wp_mail', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as it runs it.
			array(
				'to'      => 'ana@example.org',
				'subject' => 'Novo: Gartenrundgang',
				'message' => 'Usluga: Gartenrundgang',
			)
		);
		$this->assertSame( 'Usluga: Obilazak vrta', $mail['message'], 'Written from what was written for the customer' );
		$this->assertSame( 'Gartenrundgang', apply_filters( 'wp_mail', array( 'message' => 'Gartenrundgang' ) )['message'], 'Only the next email' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as it runs it.
	}

	/**
	 * The bookings a request in a language saves keep it in the plugin's column of their locale: those
	 * of the order the visitor comes back with after paying, or else the new ones. Earlier bookings stay.
	 */
	public function test_bookings_keep_their_language() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests' rows.
		$table = $wpdb->prefix . 'bookly_customer_appointments';
		$wpdb->insert( $table, array( 'order_id' => 70 ) );
		$this->show_in( 'de' );
		$this->bookly->noteLast();
		$wpdb->insert( $table, array( 'order_id' => 71 ) );
		$this->bookly->keepLanguage();
		$this->assertSame( array( null, 'de_DE' ), $wpdb->get_col( "SELECT locale FROM {$table} ORDER BY id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.

		$wpdb->insert( $wpdb->prefix . 'bookly_orders', array( 'token' => 'abc' ) );
		$wpdb->insert( $table, array( 'order_id' => $wpdb->insert_id ) );
		$_GET['bookly_order'] = 'abc';
		$this->bookly->noteLast();
		$this->bookly->keepLanguage();
		$this->assertSame( array( null, 'de_DE', 'de_DE' ), $wpdb->get_col( "SELECT locale FROM {$table} ORDER BY id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		// phpcs:enable

		$_GET = array(
			'action'         => 'bookly_back_from_payment_system',
			'bookly_referer' => 'http://example.org/termin/?lang=de',
		);
		$this->assertSame( 'http://example.org/termin/?lang=de', Registry::sentFrom( 'https://pay.example.com/' ) );
	}

	/**
	 * Machine translation keeps the placeholders of the plugin's templates as they are.
	 */
	public function test_placeholders_kept() {
		$items = apply_filters( 'swiftling_machine_items', array( 'a' => array( 'text' => 'Poštovani {client_name}, vidimo se {appointment_date}.' ) ) );
		$this->assertSame( array( '{client_name}', '{appointment_date}' ), $items['a']['keep'] );
	}
}
