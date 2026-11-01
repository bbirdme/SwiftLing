<?php
/**
 * Tests of the class of Booking Calendar.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\BookingCalendar;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeBookingCalendar.php';

/**
 * What SwiftLing translates of Booking Calendar: the texts of its forms, around and inside the fields
 * of the plugin, its messages and the emails to the visitor, in the language of the booking, and the
 * email to the site's owner in the original.
 */
class BookingCalendarTest extends TestCase {

	/**
	 * The form of the tests, as the plugin keeps it.
	 */
	const FORM = '<r><c><item><div class="wpbc_static_text">Odaberite datume</div></item></c></r><r><c><item><l>Ime*</l><br>[text* firstname placeholder:"Npr. Ana"]<div class="wpbc_field_description">Upišite ime.</div></item></c><c><item><l>Odrasli</l><br>[selectbox* adults "--- Odaberite ---@@" "1" "2"]</item></c></r>[calendar]<span>[submit "Pošalji"]</span>';

	/**
	 * The summary of a booking of the tests' form.
	 */
	const SUMMARY = '<div class="standard-content-form"><b>Ime</b>: <f>[name]</f><br></div>';

	/**
	 * The class.
	 *
	 * @var FakeBookingCalendar
	 */
	private $wpbc;

	/**
	 * Puts the fake in the registry and hooks it, with the plugin's standard form, its message after a
	 * booking and its emails.
	 */
	public function set_up() {
		global $wpdb;
		parent::set_up();
		FakeBookingCalendar::install();
		$this->wpbc = new FakeBookingCalendar();
		$builders   = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof BookingCalendar ? $this->wpbc : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->wpbc->register();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The tests' form.
			$wpdb->prefix . 'booking_form_structures',
			array(
				'form_slug'     => 'standard',
				'is_default'    => 1,
				'advanced_form' => self::FORM,
				'content_form'  => self::SUMMARY,
			)
		);
		$GLOBALS['swiftling_test_wpbc_summary'] = self::SUMMARY;
		update_option( 'booking_title_after_reservation', 'Hvala, rezervacija je primljena.' );
		update_option(
			'booking_email_approved',
			array(
				'enabled' => 'On',
				'subject' => 'Rezervacija odobrena',
				'content' => 'Vaša rezervacija [dates] je odobrena.',
				'from'    => 'vrt@example.org',
			)
		);
		update_option(
			'booking_email_new_admin',
			array(
				'subject' => 'Nova rezervacija',
				'content' => 'Odobrite rezervaciju.',
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
		foreach ( swiftling_save_field_list( swiftling_subject( 'booking-calendar-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		return $units;
	}

	/**
	 * Saves a German translation of some texts the subject of the settings offers.
	 *
	 * @param array $german Original => translation.
	 */
	private function translate_texts( array $german ) {
		$units = $this->units();
		foreach ( $german as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
	}

	/**
	 * Returns the form the plugin's loader hands out.
	 *
	 * @return array
	 */
	private static function load() {
		return apply_filters( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
			'wpbc_bfb_form_loader_from_builder', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
			array(
				'form'      => self::FORM,
				'content'   => self::SUMMARY,
				'form_slug' => 'standard',
			),
			array()
		);
	}

	/**
	 * The subject of the plugin's settings offers each text of a form, its placeholders, the labels of
	 * its options and the button's text, the message after a booking and the emails to the visitor, and
	 * neither the fields themselves, nor the values of options, nor the email to the owner. Translated,
	 * the form the plugin's loader hands out has them in the language, every field still there; the
	 * original keeps them.
	 */
	public function test_form_offered_and_translated_field_by_field() {
		$units = $this->units();
		foreach ( array( 'Odaberite datume', 'Ime*', 'Upišite ime.', 'Npr. Ana', '--- Odaberite ---', 'Pošalji', 'Ime', 'Hvala, rezervacija je primljena.', 'Rezervacija odobrena', 'Vaša rezervacija [dates] je odobrena.' ) as $text ) {
			$this->assertArrayHasKey( $text, $units, $text );
		}
		foreach ( array( 'Nova rezervacija', '1', 'firstname', 'vrt@example.org' ) as $text ) {
			$this->assertArrayNotHasKey( $text, $units, $text );
		}
		$this->assertSame( 'Form standard › firstname › Placeholder', Registry::fieldLabel( $units['Npr. Ana']['o'], $units['Npr. Ana']['p'] ) );
		$this->assertSame( 'Form standard › Summary of a booking', Registry::fieldLabel( $units['Ime']['o'], $units['Ime']['p'] ) );

		$this->translate_texts(
			array(
				'Ime*'              => 'Vorname*',
				'Npr. Ana'          => 'Z. B. Anna',
				'--- Odaberite ---' => '--- Wählen ---',
				'Pošalji'           => 'Senden',
				'Ime'               => 'Vorname',
			)
		);
		$this->show_in( 'de' );
		$pair = self::load();
		$this->assertSame( str_replace( array( '<l>Ime*</l>', 'placeholder:"Npr. Ana"', '"--- Odaberite ---@@"', '[submit "Pošalji"]' ), array( '<l>Vorname*</l>', 'placeholder:"Z. B. Anna"', '"--- Wählen ---@@"', '[submit "Senden"]' ), self::FORM ), $pair['form'] );
		$this->assertSame( str_replace( '<b>Ime</b>', '<b>Vorname</b>', self::SUMMARY ), $pair['content'] );

		$this->reset_state();
		$this->assertSame( self::FORM, self::load()['form'], 'The original' );
	}

	/**
	 * The calendar and the booking are sent in the language of the page, and the booking keeps it where
	 * the plugin keeps the locale of a booking; a booking from the original keeps none.
	 */
	public function test_booking_keeps_its_language() {
		$this->assertContains( 'WPBC_AJX_BOOKING__CREATE', Registry::get( 'booking-calendar' )->sendingActions() );
		$this->show_in( 'de' );
		do_action( 'wpbc_track_new_booking', array( 'booking_id' => 9 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own action, as it runs it.
		$this->assertSame( 'de_DE', wpbc_get_booking_meta_option( 9, 'booking_meta_locale' ) );
		$this->reset_state();
		do_action( 'wpbc_track_new_booking', array( 'booking_id' => 10 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
		$this->assertFalse( wpbc_get_booking_meta_option( 10, 'booking_meta_locale' ) );
	}

	/**
	 * An email the plugin sends the visitor later, as when the booking is approved in its admin, is
	 * written from the translation of its template in the language of the booking, with the summary in
	 * it; one about a booking in the original stays. The email to the owner about a booking sent from a
	 * page in a language has the labels of the summary back in the original.
	 */
	public function test_emails_in_the_language_of_the_booking() {
		$this->translate_texts(
			array(
				'Rezervacija odobrena' => 'Buchung bestätigt',
				'Ime'                  => 'Vorname',
			)
		);
		wpbc_save_booking_meta_option( 5, array( 'booking_meta_locale' => 'de_DE' ) );
		$this->reset_state();

		$replace = apply_filters( 'wpbc_replace_params_for_booking', array( 'content' => 'Ime: Eva' ), 5, 1, 'Eva' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( str_replace( array( '<b>Ime</b>', '[name]' ), array( '<b>Vorname</b>', 'Eva' ), self::SUMMARY ), $replace['content'] );
		$this->assertSame( 'Buchung bestätigt', apply_filters( 'wpbc_email_api_get_subject_before', 'Rezervacija odobrena', 'approved' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
		$this->assertSame( 'Nova rezervacija', apply_filters( 'wpbc_email_api_get_subject_before', 'Nova rezervacija', 'new_admin' ), 'The email to the owner' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.

		apply_filters( 'wpbc_replace_params_for_booking', array( 'content' => 'Ime: Iva' ), 6, 1, 'Iva' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
		$this->assertSame( 'Rezervacija odobrena', apply_filters( 'wpbc_email_api_get_subject_before', 'Rezervacija odobrena', 'approved' ), 'A booking in the original' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.

		$this->show_in( 'de' );
		$this->assertSame( 'Odobrite: <b>Ime</b>: Eva', apply_filters( 'wpbc_email_api_get_content_after', 'Odobrite: <b>Vorname</b>: Eva', 'new_admin', 'html' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
		$this->assertSame( 'Hvala: <b>Vorname</b>: Eva', apply_filters( 'wpbc_email_api_get_content_after', 'Hvala: <b>Vorname</b>: Eva', 'new_visitor', 'html' ), 'The email to the visitor' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
	}
}
