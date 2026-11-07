<?php
/**
 * Tests of the class of WP-Members.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\WpMembers;

require_once __DIR__ . '/stubs/FakeWpMembers.php';

/**
 * What SwiftLing translates of WP-Members: its messages, the labels of its fields and its emails, each
 * email in the language of its reader.
 */
class WpMembersTest extends TestCase {

	/**
	 * German in folders, the plugin's messages, fields and one email the owner changed, and the fake in
	 * the registry.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		update_option( 'wpmembers_dialogs', array( 'restricted_msg' => 'Samo za članove.' ) );
		update_option(
			'wpmembers_fields',
			array(
				array( 0, 'Ime', 'first_name', 'text', 'y', 'y', 'y' ),
				array( 1, 'Vrt', 'garden', 'select', 'y', 'n', 'n', array( 'Povrtnjak|veg', 'Cvjetnjak|flower' ) ),
			)
		);
		update_option(
			'wpmembers_email_appmod',
			array(
				'subj' => 'Odobreno za [blogname]',
				'body' => "Dobro došli.\n[reglink]",
			)
		);
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof WpMembers ? new FakeWpMembers() : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		Registry::get( 'wp-members' )->register();
	}

	/**
	 * Gives the registry back its own builders, and the request its folder.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		swiftling_keep_folder( '' );
		parent::tear_down();
	}

	/**
	 * Returns the texts of the settings, by original, noted as the editor notes them.
	 *
	 * @return array<string, array>
	 */
	private function units() {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'wp-members-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		return $units;
	}

	/**
	 * The settings offer the messages, the label of each field, not its choices or its name, and every
	 * email to members with the footer, also those the owner never changed; the plugin reads the
	 * messages and labels translated.
	 */
	public function test_texts_offered() {
		$units = $this->units();
		foreach ( array( 'Samo za članove.', 'Ime', 'Vrt', 'Odobreno za [blogname]', 'Your registration for [blogname]', "----\nAn automated message from [blogname]" ) as $text ) {
			$this->assertArrayHasKey( $text, $units );
		}
		foreach ( array( 'first_name', 'text', 'Povrtnjak|veg' ) as $text ) {
			$this->assertArrayNotHasKey( $text, $units );
		}
		$this->assertSame( 'Field 1 › Label', Registry::fieldLabel( $units['Ime']['o'], $units['Ime']['p'] ) );

		$this->translate( $units['Ime']['o'], $units['Ime']['p'], 'Ime', 'de', 'Vorname' );
		$this->translate( $units['Samo za članove.']['o'], $units['Samo za članove.']['p'], 'Samo za članove.', 'de', 'Nur für Mitglieder.' );
		$this->show_in( 'de' );
		$this->assertSame( 'Vorname', get_option( 'wpmembers_fields' )[0][1] );
		$this->assertSame( 'garden', get_option( 'wpmembers_fields' )[1][2] );
		$this->assertSame( 'Nur für Mitglieder.', get_option( 'wpmembers_dialogs' )['restricted_msg'] );
	}

	/**
	 * An email to a member who registered in German is written from the translations of its template
	 * and of the footer, also from the admin and also as paragraphs, with the addresses of German pages;
	 * a member in the original gets the original; the email to the site's owner leads to the original.
	 */
	public function test_emails() {
		$units = $this->units();
		foreach ( array(
			'Odobreno za [blogname]'                     => 'Freigegeben für [blogname]',
			"Dobro došli.\n[reglink]"                    => "Willkommen.\n[reglink]",
			"----\nAn automated message from [blogname]" => "----\nEine automatische Nachricht von [blogname]",
		) as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
		$this->show_in( 'de' );
		$member = self::factory()->user->create();
		$this->reset_state();
		$other = self::factory()->user->create();
		$site  = untrailingslashit( get_option( 'home' ) );
		$email = array(
			'tag'      => 'appmod',
			'subj'     => 'Odobreno za [blogname]',
			'body'     => "Dobro došli.\n[reglink]",
			'footer'   => "----\nAn automated message from [blogname]",
			'reg_link' => $site . '/prijava/',
		);

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters, as it runs them.
		$written = apply_filters( 'wpmem_email_filter', array( 'user_id' => $member ) + $email );
		$this->assertSame( 'Freigegeben für [blogname]', $written['subj'] );
		$this->assertSame( "Willkommen.\n[reglink]", $written['body'] );
		$this->assertSame( "----\nEine automatische Nachricht von [blogname]", $written['footer'] );
		$this->assertSame( $site . '/de/prijava/', $written['reg_link'] );

		$html = apply_filters(
			'wpmem_email_filter',
			array(
				'user_id' => $member,
				'body'    => wpautop( $email['body'] ),
			) + $email
		);
		$this->assertSame( wpautop( "Willkommen.\n[reglink]" ), $html['body'] );

		$this->assertSame( array( 'user_id' => $other ) + $email, apply_filters( 'wpmem_email_filter', array( 'user_id' => $other ) + $email ) );

		$this->show_in( 'de' );
		$owners = apply_filters(
			'wpmem_notify_filter',
			array(
				'user_id'  => $member,
				'reg_link' => $site . '/de/prijava/',
			)
		);
		$this->assertSame( $site . '/prijava/', $owners['reg_link'] );
		// phpcs:enable
	}
}
