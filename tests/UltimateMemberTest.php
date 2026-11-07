<?php
/**
 * Tests of the classes of Ultimate Member.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\UltimateMember;

require_once __DIR__ . '/stubs/FakeUltimateMember.php';

/**
 * What SwiftLing translates of Ultimate Member: the fields of its forms, its messages, the message of
 * a restricted post, and its emails, each in the language of its reader.
 */
class UltimateMemberTest extends TestCase {

	/**
	 * The registration form of the tests.
	 *
	 * @var int
	 */
	private $form;

	/**
	 * The emails sent, as wp_mail() got them.
	 *
	 * @var array[]
	 */
	private $mails = array();

	/**
	 * German in folders, the plugin's type of forms with a form, its settings, and the fake in the
	 * registry.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		register_post_type( 'um_form' );
		$this->form = self::factory()->post->create(
			array(
				'post_type'  => 'um_form',
				'post_title' => 'Registracija',
			)
		);
		update_post_meta(
			$this->form,
			'_um_custom_fields',
			array(
				'first_name' => array(
					'title' => 'First Name',
					'label' => 'Ime',
					'help'  => 'Kao na članskoj kartici.',
					'type'  => 'text',
				),
				'garden'     => array(
					'title'   => 'Garden',
					'label'   => 'Vrt',
					'type'    => 'select',
					'options' => array( 'Povrtnjak', 'Cvjetnjak' ),
				),
			)
		);
		update_post_meta( $this->form, '_um_register_primary_btn_word', 'Pridruži se' );
		update_option(
			'um_options',
			array(
				'restricted_access_message'  => 'Samo za članove kluba.',
				'welcome_email_sub'          => 'Dobro došli, {display_name}',
				'notification_new_user_sub'  => 'Novi član',
				'restricted_access_redirect' => 'http://example.org/prijava/',
			)
		);
		self::reset_static( UltimateMember::class, 'emails' );
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof UltimateMember ? new FakeUltimateMember() : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		Registry::get( 'um' )->register();
		Registry::get( 'ultimate-member' )->register();
		add_filter(
			'pre_wp_mail',
			function ( $sent, $mail ) {
				$this->mails[] = $mail;
				return true;
			},
			10,
			2
		);
	}

	/**
	 * Gives the registry back its own builders, and the request its folder.
	 */
	public function tear_down() {
		unregister_post_type( 'um_form' );
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		self::reset_static( UltimateMember::class, 'emails' );
		swiftling_keep_folder( '' );
		parent::tear_down();
	}

	/**
	 * Returns the texts a subject offers, by original.
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
	 * A form offers the label and help text of each field and the word of its button, not the choices,
	 * which the plugin saves with the member; translated where the plugin hands out the fields.
	 */
	public function test_form_fields() {
		$units = $this->units( 'um-form:' . $this->form );
		$this->assertSame( array( 'Pridruži se', 'Ime', 'Kao na članskoj kartici.', 'Vrt' ), array_keys( $units ) );
		$this->assertSame( 'First Name › Label', Registry::fieldLabel( $units['Ime']['o'], $units['Ime']['p'] ) );

		$this->translate( $units['Ime']['o'], $units['Ime']['p'], 'Ime', 'de', 'Vorname' );
		$fields = get_post_meta( $this->form, '_um_custom_fields', true );
		$this->show_in( 'de' );
		$translated = apply_filters( 'um_get_form_fields', $fields, $this->form ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( 'Vorname', $translated['first_name']['label'] );
		$this->assertSame( array( 'Povrtnjak', 'Cvjetnjak' ), $translated['garden']['options'] );
		$this->reset_state();
		$this->assertSame( 'Ime', apply_filters( 'um_get_form_fields', $fields, $this->form )['first_name']['label'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
	}

	/**
	 * The settings offer the messages, the subjects and the texts of the emails to members, not a
	 * placeholder alone, not the owner's emails and not an address; the plugin hands a setting out
	 * translated. A post restricted with a message of its own offers the message, which a page check
	 * never sees.
	 */
	public function test_settings_and_restricted_post() {
		$units = $this->units( 'ultimate-member-settings' );
		foreach ( array( 'Samo za članove kluba.', 'Dobro došli, {display_name}', 'Hvala na prijavi!', 'Prijava' ) as $text ) {
			$this->assertArrayHasKey( $text, $units );
		}
		foreach ( array( 'Novi član', 'Novi član:', '{site_name}', 'http://example.org/prijava/' ) as $text ) {
			$this->assertArrayNotHasKey( $text, $units );
		}
		$unit = $units['Samo za članove kluba.'];
		$this->translate( $unit['o'], $unit['p'], 'Samo za članove kluba.', 'de', 'Nur für Clubmitglieder.' );
		$this->show_in( 'de' );
		$this->assertSame( 'Nur für Clubmitglieder.', apply_filters( 'um_get_option_filter__restricted_access_message', 'Samo za članove kluba.' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As the plugin hands a setting out.
		$this->reset_state();

		$post = self::factory()->post->create();
		update_post_meta(
			$post,
			'um_content_restriction',
			array(
				'_um_accessible'              => '2',
				'_um_restrict_custom_message' => 'Bilješke za prijavljene članove.',
			)
		);
		$texts = Registry::listTexts( swiftling_subject( 'post:' . $post ) );
		$this->assertSame( array( 'Bilješke za prijavljene članove.' ), wp_list_pluck( $texts, 'v' ) );
		$this->assertSame( 'Message on restricted content', $texts[0]['place'] );
	}

	/**
	 * An email to a member who registered in German is written in German, also from the admin, with the
	 * addresses of German pages; the email to the site's owner, sent from a page in German, in the
	 * original, with the addresses of the original's pages.
	 */
	public function test_emails() {
		$units = $this->units( 'ultimate-member-settings' );
		foreach ( array(
			'Dobro došli, {display_name}' => 'Willkommen, {display_name}',
			'Hvala na prijavi!'           => 'Danke für Ihre Anmeldung!',
		) as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
		$this->show_in( 'de' );
		$member = get_userdata( self::factory()->user->create() );
		$this->reset_state();
		$site = untrailingslashit( get_option( 'home' ) );

		UM()->mail()->send( $member->user_email, 'welcome_email' );
		$this->assertSame( 'Willkommen, {display_name}', $this->mails[0]['subject'] );
		$this->assertSame( '<div>Vrt</div><div>Danke für Ihre Anmeldung! <a href="' . $site . '/de/prijava/">Prijava</a></div>', $this->mails[0]['message'] );
		$this->assertSame( '', swiftling_mode(), 'The request as it was' );

		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );
		UM()->mail()->send( get_bloginfo( 'admin_email' ), 'notification_new_user', array( 'url' => home_url( '/prijava/' ) ) );
		$this->assertSame( 'Novi član', $this->mails[1]['subject'] );
		$this->assertSame( '<div>Novi član: <a href="' . $site . '/prijava/">pogledaj</a></div>', $this->mails[1]['message'] );
		$this->assertSame( 'translate', swiftling_mode() );
	}
}
