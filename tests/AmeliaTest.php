<?php
/**
 * Tests of the class of Amelia.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Amelia;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeAmelia.php';

/**
 * What SwiftLing translates of Amelia: the rows of its tables where its API hands them to a form, the
 * translations it writes where the plugin reads them for its emails, and an email to the customer
 * written from the translation of its template.
 */
class AmeliaTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeAmelia
	 */
	private $amelia;

	/**
	 * Puts the fake in the registry and hooks it, with a service in a category, an employee, a custom
	 * field and the plugin's emails to the customer and to the employee.
	 */
	public function set_up() {
		global $wpdb;
		parent::set_up();
		FakeAmelia::install();
		$this->amelia = new FakeAmelia();
		$builders     = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Amelia ? $this->amelia : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->amelia->register();
		$rows = array(
			'amelia_services'      => array(
				'name'        => 'Obilazak vrta',
				'description' => '<p>Šetnja među biljem.</p>',
			),
			'amelia_categories'    => array( 'name' => 'Posjeti' ),
			'amelia_users'         => array(
				'type'        => 'provider',
				'description' => 'Ana čuva gredice.',
			),
			'amelia_custom_fields' => array( 'label' => 'Napomena' ),
			'amelia_notifications' => array(
				'sendTo'  => 'customer',
				'type'    => 'email',
				'subject' => 'Termin %service_name% potvrđen',
				'content' => 'Poštovana %customer_full_name%, vidimo se.',
			),
		);
		foreach ( $rows as $table => $row ) {
			$wpdb->insert( $wpdb->prefix . $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The tests' rows.
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The tests' rows.
			$wpdb->prefix . 'amelia_notifications',
			array(
				'sendTo'  => 'provider',
				'type'    => 'email',
				'subject' => 'Novi termin',
				'content' => 'Imate novi termin.',
			)
		);
		update_option( 'amelia_settings', wp_json_encode( array( 'general' => array( 'usedLanguages' => array() ) ) ) );
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
		foreach ( swiftling_save_field_list( swiftling_subject( 'amelia-settings' ) )['units'] as $unit ) {
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
	 * The subject of the plugin's settings offers the texts of its rows and the emails to the customer,
	 * not those to an employee. Translated, the rows the plugin's API hands to a form have their texts
	 * in the language, also a service listed under its category or its employee; the original keeps them.
	 */
	public function test_rows_offered_and_translated_where_the_api_hands_them_out() {
		$units = $this->units();
		foreach ( array( 'Obilazak vrta', '<p>Šetnja među biljem.</p>', 'Posjeti', 'Ana čuva gredice.', 'Napomena', 'Termin %service_name% potvrđen' ) as $text ) {
			$this->assertArrayHasKey( $text, $units, $text );
		}
		$this->assertArrayNotHasKey( 'Novi termin', $units, 'An email to the employee' );
		$this->assertSame( 'Service 1 › Name', Registry::fieldLabel( $units['Obilazak vrta']['o'], $units['Obilazak vrta']['p'] ) );

		$this->translate_texts(
			array(
				'Obilazak vrta' => 'Gartenrundgang',
				'Posjeti'       => 'Besuche',
			)
		);
		$service = array(
			'id'          => 1,
			'name'        => 'Obilazak vrta',
			'description' => '<p>Šetnja među biljem.</p>',
			'price'       => 20,
		);
		$data    = array(
			'categories' => array(
				array(
					'id'          => 1,
					'name'        => 'Posjeti',
					'serviceList' => array( $service ),
				),
			),
			'employees'  => array(
				array(
					'id'          => 1,
					'firstName'   => 'Ana',
					'serviceList' => array( $service ),
				),
			),
		);
		$this->show_in( 'de' );
		$shown = apply_filters( 'amelia_get_entities_filter', $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( 'Besuche', $shown['categories'][0]['name'] );
		$this->assertSame( 'Gartenrundgang', $shown['categories'][0]['serviceList'][0]['name'] );
		$this->assertSame( 'Gartenrundgang', $shown['employees'][0]['serviceList'][0]['name'] );
		$this->assertSame( 'Ana', $shown['employees'][0]['firstName'] );
		$this->assertSame( 20, $shown['categories'][0]['serviceList'][0]['price'] );

		$this->reset_state();
		$this->assertSame( $data, apply_filters( 'amelia_get_entities_filter', $data ), 'The original' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
	}

	/**
	 * Only a request of the plugin's API that reads what a form shows is answered in the language of the
	 * page: one that saves a booking stays in the original.
	 */
	public function test_api_reads_in_the_language_of_the_page() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['call']              = '/entities';
		$this->assertSame( array( 'wpamelia_api' ), $this->amelia->sendingActions() );
		$_GET['call'] = '/slots';
		$this->assertSame( array( 'wpamelia_api' ), $this->amelia->sendingActions() );
		$_GET['call'] = '/bookings';
		$this->assertSame( array(), $this->amelia->sendingActions() );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['call']              = '/entities';
		$this->assertSame( array(), $this->amelia->sendingActions() );
	}

	/**
	 * Once translations are saved, the plugin's rows hold them in their column of translations, by the
	 * field and the locale as the plugin reads them, a custom field by the locale alone, and the plugin
	 * uses the language. A translation removed is removed there too; the translation of another language
	 * the plugin's screens added stays.
	 */
	public function test_translations_written_where_the_plugin_reads_them() {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'amelia_services', array( 'translations' => wp_json_encode( array( 'name' => array( 'it_IT' => 'Giro del giardino' ) ) ) ), array( 'id' => 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As the plugin's screens add one.
		$this->translate_texts(
			array(
				'Obilazak vrta'                  => 'Gartenrundgang',
				'Napomena'                       => 'Anmerkung',
				'Termin %service_name% potvrđen' => 'Termin %service_name% bestätigt',
			)
		);
		$this->amelia->syncPending();
		$read = static function ( $table ) use ( $wpdb ) {
			return json_decode( (string) $wpdb->get_var( "SELECT translations FROM {$wpdb->prefix}{$table} WHERE id = 1" ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The tests' rows.
		};
		$this->assertSame(
			array(
				'name' => array(
					'it_IT' => 'Giro del giardino',
					'de_DE' => 'Gartenrundgang',
				),
			),
			$read( 'amelia_services' )
		);
		$this->assertSame( array( 'de_DE' => 'Anmerkung' ), $read( 'amelia_custom_fields' ) );
		$this->assertSame( array( 'subject' => array( 'de_DE' => 'Termin %service_name% bestätigt' ) ), $read( 'amelia_notifications' ) );
		$this->assertSame( array( 'de_DE' ), json_decode( get_option( 'amelia_settings' ), true )['general']['usedLanguages'] );

		$this->translate_texts( array( 'Obilazak vrta' => '' ) );
		$this->amelia->syncPending();
		$this->assertSame( array( 'name' => array( 'it_IT' => 'Giro del giardino' ) ), $read( 'amelia_services' ) );
	}

	/**
	 * An email the plugin writes for the customer of a booking made in a language is written again from
	 * the translation of its template, with the values the plugin put into the placeholders; an email to
	 * an employee, one of a booking in the original, or one that matches no template stays.
	 */
	public function test_email_to_the_customer_from_the_translation_of_its_template() {
		$this->translate_texts(
			array(
				'Termin %service_name% potvrđen' => 'Termin %service_name% bestätigt',
				'Poštovana %customer_full_name%, vidimo se.' => 'Liebe %customer_full_name%, bis bald.',
			)
		);
		$email   = function ( $to, $locale, $subject, $body ) {
			return apply_filters( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
				'amelia_manipulate_email_data', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
				array(
					'email'   => $to,
					'subject' => $subject,
					'body'    => $body,
					'data'    => array(
						'reservation' => array(
							'bookings' => array(
								array(
									'customer' => array( 'email' => 'eva@example.org' ),
									'info'     => wp_json_encode( array( 'locale' => $locale ) ),
								),
							),
						),
						'bookingKey'  => 0,
					),
				)
			);
		};
		$written = $email( 'eva@example.org', 'de_DE', 'Termin Gartenrundgang potvrđen', 'Poštovana Eva Gast, vidimo se.' );
		$this->assertSame( 'Termin Gartenrundgang bestätigt', $written['subject'] );
		$this->assertSame( 'Liebe Eva Gast, bis bald.', $written['body'] );
		$this->assertSame( 'Poštovana Eva Gast, vidimo se.', $email( 'ana@example.org', 'de_DE', 'Termin X potvrđen', 'Poštovana Eva Gast, vidimo se.' )['body'], 'An email to an employee' );
		$this->assertSame( 'Poštovana Eva Gast, vidimo se.', $email( 'eva@example.org', 'hr', 'Termin X potvrđen', 'Poštovana Eva Gast, vidimo se.' )['body'], 'A booking in the original' );
		$this->assertSame( 'Drugi tekst', $email( 'eva@example.org', 'de_DE', 'Drugi', 'Drugi tekst' )['body'], 'No template' );
	}

	/**
	 * A text is written again from a translated template with the values read by the original one, a
	 * placeholder used twice with the same value; a text the template did not write is not.
	 */
	public function test_refill() {
		$this->assertSame( 'Hallo Ana, Ana!', Amelia::refill( 'Bok %name%, %name%!', 'Hallo %name%, %name%!', 'Bok Ana, Ana!' ) );
		$this->assertSame( 'Am 5. Mai: Massage', Amelia::refill( '%service%, %date%', 'Am %date%: %service%', 'Massage, 5. Mai' ) );
		$this->assertNull( Amelia::refill( 'Bok %name%, %name%!', 'Hallo %name%', 'Bok Ana, Eva!' ) );
		$this->assertNull( Amelia::refill( 'Bok %name%', 'Hallo %name%', 'Nešto drugo' ) );
	}

	/**
	 * Machine translation keeps the placeholders of the plugin's templates as they are.
	 */
	public function test_placeholders_kept() {
		$items = apply_filters( 'swiftling_machine_items', array( 'a' => array( 'text' => 'Bok %customer_full_name%' ) ) );
		$this->assertSame( array( '%customer_full_name%' ), $items['a']['keep'] );
	}
}
