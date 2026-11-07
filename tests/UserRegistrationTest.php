<?php
/**
 * Tests of the classes of User Registration & Membership.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\UserRegistration;

require_once __DIR__ . '/stubs/FakeUserRegistration.php';

/**
 * What SwiftLing translates of User Registration & Membership: the fields of its forms, its messages
 * and its emails, each email in the language of its reader.
 */
class UserRegistrationTest extends TestCase {

	/**
	 * The form of the tests.
	 *
	 * @var int
	 */
	private $form;

	/**
	 * German in folders, the plugin's type of forms with a form, its settings, and the fake in the
	 * registry.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		register_post_type( 'user_registration' );
		$fields     = array(
			array(
				array(
					(object) array(
						'field_key'       => 'user_login',
						'general_setting' => (object) array(
							'label'       => 'Korisničko ime',
							'field_name'  => 'user_login',
							'placeholder' => 'npr. vrtlar',
						),
					),
				),
			),
		);
		$this->form = self::factory()->post->create(
			array(
				'post_type'    => 'user_registration',
				'post_title'   => 'Prijava',
				'post_content' => wp_slash( (string) wp_json_encode( $fields ) ),
			)
		);
		update_post_meta( $this->form, 'user_registration_form_setting_form_submit_label', 'Pridruži se' );
		update_option( 'user_registration_successful_form_submission_message_manual_registation', 'Dobro došli u klub!' );
		update_option( 'user_registration_registration_approved_email_subject', 'Odobreno' );
		update_option( 'user_registration_registration_approved_email', 'Bok {{username}}, prijava: {{home_url}}' );
		update_option( 'user_registration_admin_email', 'Novi član {{username}}' );
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof UserRegistration ? new FakeUserRegistration() : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		Registry::get( 'ur' )->register();
		Registry::get( 'user-registration' )->register();
		// As the plugin fills its placeholders.
		add_filter(
			'user_registration_process_smart_tags',
			static function ( $content ) {
				return is_string( $content ) ? str_replace( array( '{{username}}', '{{home_url}}' ), array( 'ana', home_url( '/prijava/' ) ), $content ) : $content;
			}
		);
	}

	/**
	 * Gives the registry back its own builders, and the request its folder.
	 */
	public function tear_down() {
		unregister_post_type( 'user_registration' );
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		swiftling_keep_folder( '' );
		parent::tear_down();
	}

	/**
	 * Returns the texts a subject offers, by original, noted as the editor notes them.
	 *
	 * @param string $key Subject key.
	 * @return array<string, array>
	 */
	private function units( $key ) {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( $key ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		return $units;
	}

	/**
	 * A form offers the label and placeholder of each field, and the word of its button; translated where
	 * the plugin hands out the fields of a part of the form, as often as it does.
	 */
	public function test_form_fields() {
		$units = $this->units( 'ur-form:' . $this->form );
		$this->assertSame( array( 'Pridruži se', 'Korisničko ime', 'npr. vrtlar' ), array_keys( $units ) );
		$this->translate( $units['Korisničko ime']['o'], $units['Korisničko ime']['p'], 'Korisničko ime', 'de', 'Benutzername' );

		$grid = json_decode( get_post( $this->form )->post_content )[0][0];
		$this->show_in( 'de' );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$grid = apply_filters( 'user_registration_handle_form_fields', $grid, $this->form );
		$grid = apply_filters( 'user_registration_handle_form_fields', $grid, $this->form );
		// phpcs:enable
		$this->assertSame( 'Benutzername', $grid[0]->general_setting->label );
		$this->assertSame( array( 'user_registration_user_form_submit', 'user_registration_ajax_login_submit' ), Registry::get( 'user-registration' )->sendingActions() );
	}

	/**
	 * The settings offer the messages and the emails to members, not those to the site's owner. An email
	 * to a member who registered in German is written from the translation of its template, also from the
	 * admin, with the addresses of German pages; a member in the original gets the original.
	 */
	public function test_emails() {
		$units = $this->units( 'user-registration-settings' );
		foreach ( array( 'Dobro došli u klub!', 'Odobreno', 'Bok {{username}}, prijava: {{home_url}}' ) as $text ) {
			$this->assertArrayHasKey( $text, $units );
		}
		$this->assertArrayNotHasKey( 'Novi član {{username}}', $units );
		$this->translate( $units['Bok {{username}}, prijava: {{home_url}}']['o'], $units['Bok {{username}}, prijava: {{home_url}}']['p'], 'Bok {{username}}, prijava: {{home_url}}', 'de', 'Hallo {{username}}, Anmeldung: {{home_url}}' );

		$this->show_in( 'de' );
		$member = get_userdata( self::factory()->user->create() );
		$this->reset_state();
		$other = get_userdata( self::factory()->user->create() );
		$site  = untrailingslashit( get_option( 'home' ) );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( 'Hallo ana, Anmeldung: ' . $site . '/de/prijava/', apply_filters( 'user_registration_process_smart_tags', 'Bok {{username}}, prijava: {{home_url}}', array( 'email' => $member->user_email ) ) );
		$this->assertSame( 'Bok ana, prijava: ' . $site . '/prijava/', apply_filters( 'user_registration_process_smart_tags', 'Bok {{username}}, prijava: {{home_url}}', array( 'email' => $other->user_email ) ) );
		$this->assertSame( 'Drugo ' . $site . '/prijava/', apply_filters( 'user_registration_process_smart_tags', 'Drugo {{home_url}}', array( 'email' => $member->user_email ) ), 'No email of the plugin' );

		// Registering on a page in German: the plugin reads the template translated already.
		$this->show_in( 'de' );
		$this->assertSame( 'Hallo ana, Anmeldung: ' . $site . '/de/prijava/', apply_filters( 'user_registration_process_smart_tags', get_option( 'user_registration_registration_approved_email' ), array( 'email' => $member->user_email ) ) );
		// phpcs:enable
	}
}
