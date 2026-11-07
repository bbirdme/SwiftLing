<?php
/**
 * Tests of the class of Simple Membership.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\SimpleMembership;

require_once __DIR__ . '/stubs/FakeSimpleMembership.php';

/**
 * What SwiftLing translates of Simple Membership: the names of its levels in its forms, and its emails,
 * each in the language of its reader.
 */
class SimpleMembershipTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeSimpleMembership
	 */
	private $swpm;

	/**
	 * German in folders, the plugin's settings and levels, and the fake in the registry.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		FakeSimpleMembership::install();
		$site = untrailingslashit( get_option( 'home' ) );
		update_option(
			'swpm-settings',
			array(
				'reg-complete-mail-subject'       => 'Dobro došli',
				'reg-complete-mail-body'          => "Bok {first_name},\nprijava: {login_link}",
				'reg-complete-mail-subject-admin' => 'Novi član',
				'login-page-url'                  => $site . '/prijava/',
				'email-from'                      => 'Vrt <vrt@example.org>',
			)
		);
		\SwpmSettings::forget();
		$this->swpm = new FakeSimpleMembership();
		$builders   = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof SimpleMembership ? $this->swpm : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->swpm->register();
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'simple-membership-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Dobro došli', "Bok {first_name},\nprijava: {login_link}", 'Sadnica' ), array_keys( $units ), 'Not the owner\'s email, an address or the plugin\'s own level' );
		foreach ( array(
			'Dobro došli'                              => 'Willkommen',
			"Bok {first_name},\nprijava: {login_link}" => "Hallo {first_name},\nAnmeldung: {login_link}",
			'Sadnica'                                  => 'Setzling',
		) as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
	}

	/**
	 * Gives the registry back its own builders, and the plugin its settings.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		\SwpmSettings::forget();
		parent::tear_down();
	}

	/**
	 * On a page in German, the settings the plugin read before SwiftLing started hold the translations
	 * of its emails and the address of its login page in German; the names of the levels in its forms
	 * are translated. The original stays.
	 */
	public function test_page_in_a_language() {
		$settings = \SwpmSettings::get_instance();
		$site     = untrailingslashit( get_option( 'home' ) );
		$this->show_in( 'de' );
		$this->assertSame( 'Willkommen', $settings->get_value( 'reg-complete-mail-subject' ) );
		$this->assertSame( $site . '/de/prijava/', $settings->get_value( 'login-page-url' ) );
		$this->assertSame( 'Novi član', $settings->get_value( 'reg-complete-mail-subject-admin' ) );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as it runs it.
		$this->assertSame( '<label>Setzling</label>', apply_filters( 'do_shortcode_tag', '<label>Sadnica</label>', 'swpm_registration_form' ) );
		$this->assertSame( '<label>Sadnica</label>', apply_filters( 'do_shortcode_tag', '<label>Sadnica</label>', 'gallery' ) );

		$this->reset_state();
		$this->assertSame( '<label>Sadnica</label>', apply_filters( 'do_shortcode_tag', '<label>Sadnica</label>', 'swpm_registration_form' ) );
		// phpcs:enable
	}

	/**
	 * An email the plugin wrote in the original to a member who registered in German, as from the admin,
	 * is written again from the translation of its template, with what the plugin filled in and the
	 * addresses of German pages; a member in the original, or another email, stays as it is.
	 */
	public function test_email_written_later() {
		$this->show_in( 'de' );
		$member = get_userdata( self::factory()->user->create() );
		$this->reset_state();
		$other = get_userdata( self::factory()->user->create() );
		$site  = untrailingslashit( get_option( 'home' ) );
		$mail  = array(
			'subject' => 'Dobro došli',
			'message' => "Bok Ana,\nprijava: " . $site . '/prijava/',
		);

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as it runs it.
		$written = apply_filters( 'wp_mail', array( 'to' => $member->user_email ) + $mail );
		$this->assertSame( 'Willkommen', $written['subject'] );
		$this->assertSame( "Hallo Ana,\nAnmeldung: " . $site . '/de/prijava/', $written['message'] );
		$this->assertSame( array( 'to' => $other->user_email ) + $mail, apply_filters( 'wp_mail', array( 'to' => $other->user_email ) + $mail ) );
		$this->assertSame(
			'Drugo',
			apply_filters(
				'wp_mail',
				array(
					'to'      => $member->user_email,
					'subject' => 'X',
					'message' => 'Drugo',
				)
			)['message']
		);
		// phpcs:enable
	}
}
