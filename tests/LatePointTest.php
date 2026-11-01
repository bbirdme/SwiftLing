<?php
/**
 * Tests of the class of LatePoint.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\LatePoint;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeLatePoint.php';

/**
 * What SwiftLing translates of LatePoint: its models and the texts of its steps where the plugin loads
 * them in a language, and its emails, to the customer in the language of the booking and to the staff
 * in the original.
 */
class LatePointTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeLatePoint
	 */
	private $lp;

	/**
	 * Puts the fake in the registry and hooks it, with a service, the texts of the steps and a process
	 * that emails the customer and the agent.
	 */
	public function set_up() {
		global $wpdb;
		parent::set_up();
		FakeLatePoint::install();
		$this->lp = new FakeLatePoint();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof LatePoint ? $this->lp : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->lp->register();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests' rows.
		$wpdb->insert(
			$wpdb->prefix . 'latepoint_services',
			array(
				'name'              => 'Obilazak vrta',
				'short_description' => 'Šetnja među biljem.',
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'latepoint_settings',
			array(
				'name'  => 'steps_settings',
				'value' => maybe_serialize( array( 'booking__services' => array( 'main_panel_heading' => 'Dostupne usluge' ) ) ),
			)
		);
		$email = static function ( $id, $to, $subject, $content ) {
			return array(
				'type'     => 'action',
				'id'       => $id,
				'settings' => array(
					'status'   => 'active',
					'type'     => 'send_email',
					'settings' => array(
						'to_email' => $to,
						'subject'  => $subject,
						'content'  => $content,
					),
				),
			);
		};
		$wpdb->insert(
			$wpdb->prefix . 'latepoint_processes',
			array(
				'actions_json' => wp_json_encode(
					array(
						array(
							'type'  => 'group',
							'items' => array(
								$email( 'pa_customer', '{{customer_full_name}} <{{customer_email}}>', 'Termin: {{service_name}}', '<div style="padding:20px"><p>Bok {{customer_full_name}},</p><p>potvrđujemo {{service_name}}.</p></div>' ),
								$email( 'pa_agent', '{{agent_full_name}} <{{agent_email}}>', 'Novi termin: {{service_name}}', '<p>Imate novi termin.</p>' ),
							),
						),
					)
				),
			)
		);
		// phpcs:enable
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
	 * Saves a German translation of some texts the subject of the settings offers, as the editor does.
	 *
	 * @param array $german Original => translation.
	 * @return array<string, array> The units stored, by original.
	 */
	private function translate_texts( array $german ) {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'latepoint-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		foreach ( $german as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
		return $units;
	}

	/**
	 * Returns a booking of the service, loaded as the plugin loads it in the mode of the moment.
	 *
	 * @param array $meta The booking's meta.
	 * @return \OsBookingModel
	 */
	private static function booking( array $meta = array() ) {
		$booking          = new \OsBookingModel();
		$booking->id      = 3;
		$booking->meta    = $meta;
		$booking->service = \OsServiceModel::loaded(
			array(
				'id'                => 1,
				'name'              => 'Obilazak vrta',
				'short_description' => 'Šetnja među biljem.',
			)
		);
		return $booking;
	}

	/**
	 * The subject of the plugin's settings offers the texts of its models and steps, and the email to
	 * the customer text by text, its HTML left out, not the email to the agent. Translated, a model and
	 * the steps the plugin loads in a language have their texts in it; in the original they do not.
	 */
	public function test_models_and_steps_translated_where_the_plugin_loads_them() {
		$units = $this->translate_texts(
			array(
				'Obilazak vrta'   => 'Gartenrundgang',
				'Dostupne usluge' => 'Verfügbare Dienste',
			)
		);
		foreach ( array( 'Šetnja među biljem.', 'Termin: {{service_name}}', 'Bok {{customer_full_name}},', 'potvrđujemo {{service_name}}.' ) as $text ) {
			$this->assertArrayHasKey( $text, $units, $text );
		}
		$this->assertArrayNotHasKey( 'Novi termin: {{service_name}}', $units, 'The email to the agent' );
		$this->assertArrayNotHasKey( '<div style="padding:20px"><p>Bok {{customer_full_name}},</p><p>potvrđujemo {{service_name}}.</p></div>', $units, 'The HTML of an email' );
		$this->assertSame( 'Service 1 › Name', Registry::fieldLabel( $units['Obilazak vrta']['o'], $units['Obilazak vrta']['p'] ) );

		$this->show_in( 'de' );
		$this->assertSame( 'Gartenrundgang', self::booking()->service->name );
		$steps = \OsSettingsModel::loaded(
			array(
				'name'  => 'steps_settings',
				'value' => maybe_serialize( array( 'booking__services' => array( 'main_panel_heading' => 'Dostupne usluge' ) ) ),
			)
		);
		$this->assertSame( array( 'booking__services' => array( 'main_panel_heading' => 'Verfügbare Dienste' ) ), maybe_unserialize( $steps->value ) );

		$this->reset_state();
		$this->assertSame( 'Obilazak vrta', self::booking()->service->name, 'The original' );
	}

	/**
	 * A booking made from a page in a language keeps it. The email to its customer is written from the
	 * translation of the template, while the booking is made and later; the email to the agent is the
	 * original, also the names the request translated before. An email about a booking in the original
	 * stays.
	 */
	public function test_emails_in_the_language_of_the_booking() {
		$this->translate_texts(
			array(
				'Obilazak vrta'                 => 'Gartenrundgang',
				'Termin: {{service_name}}'      => 'Ihr Termin: {{service_name}}',
				'Bok {{customer_full_name}},'   => 'Hallo {{customer_full_name}},',
				'potvrđujemo {{service_name}}.' => 'wir bestätigen {{service_name}}.',
			)
		);
		$this->show_in( 'de' );
		$booking = self::booking();
		do_action( 'latepoint_booking_created', $booking ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own action, as it runs it.
		$this->assertSame( array( LatePoint::LANGUAGE_META => 'de' ), $booking->meta );
		$vars = array(
			'booking'            => $booking,
			'customer_full_name' => 'Eva',
		);
		$this->assertSame( 'Ihr Termin: Gartenrundgang', \OsReplacerHelper::replace_all_vars( 'Termin: {{service_name}}', $vars ) );
		$this->assertSame( '<div style="padding:20px"><p>Hallo Eva,</p><p>wir bestätigen Gartenrundgang.</p></div>', \OsReplacerHelper::replace_all_vars( '<div style="padding:20px"><p>Bok {{customer_full_name}},</p><p>potvrđujemo {{service_name}}.</p></div>', $vars ) );
		$this->assertSame( 'Novi termin: Obilazak vrta', \OsReplacerHelper::replace_all_vars( 'Novi termin: {{service_name}}', $vars ), 'The email to the agent' );
		$this->assertSame( 'de', swiftling_state()['language'], 'The request as it was' );

		// Later, as a reminder from a scheduled task.
		$this->reset_state();
		$vars['booking'] = self::booking( array( LatePoint::LANGUAGE_META => 'de' ) );
		$this->assertSame( 'Ihr Termin: Gartenrundgang', \OsReplacerHelper::replace_all_vars( 'Termin: {{service_name}}', $vars ) );
		$vars['booking'] = self::booking();
		$this->assertSame( 'Termin: Obilazak vrta', \OsReplacerHelper::replace_all_vars( 'Termin: {{service_name}}', $vars ), 'A booking in the original' );
		$this->assertSame( 'Bez predloška', \OsReplacerHelper::replace_all_vars( 'Bez predloška', $vars ), 'No template' );
	}
}
