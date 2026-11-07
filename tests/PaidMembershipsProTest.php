<?php
/**
 * Tests of the class of Paid Memberships Pro.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\PaidMembershipsPro;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakePaidMembershipsPro.php';

/**
 * What SwiftLing translates of Paid Memberships Pro: its levels where it hands them out and prints
 * them, and its emails, each in the language of its reader.
 */
class PaidMembershipsProTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakePaidMembershipsPro
	 */
	private $pmpro;

	/**
	 * The emails sent, as wp_mail() got them.
	 *
	 * @var array[]
	 */
	private $mails = array();

	/**
	 * Puts the fake in the registry and hooks it, with a level, German in folders and the translations
	 * of the level, an email and the header of every email.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		FakePaidMembershipsPro::install();
		$this->pmpro = new FakePaidMembershipsPro();
		$builders    = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof PaidMembershipsPro ? $this->pmpro : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->pmpro->register();
		// As the editor of the settings notes the texts before they are translated.
		swiftling_save_field_list( swiftling_subject( 'pmpro-settings' ) );
		$this->translate( 'pmpro:level:1', 'pmpro/name', 'Sadnica', 'de', 'Setzling' );
		$this->translate( 'pmpro:level:1', 'pmpro/confirmation', 'Dobro došli u klub.', 'de', 'Willkommen im Club.' );
		$this->translate( 'pmpro:email:checkout_free', 'pmpro/subject', 'Your membership at {{ sitename }}', 'de', 'Ihre Mitgliedschaft bei {{ sitename }}' );
		$this->translate( 'pmpro:email:checkout_free', 'pmpro/body', '<p>You are now a !!membership_level_name!!. Log in: !!login_url!!</p>', 'de', '<p>Sie sind jetzt !!membership_level_name!!. Anmelden: !!login_url!!</p>' );
		$this->translate( 'pmpro:email:header', 'pmpro/body', '<p>Dear !!header_name!!,</p>', 'de', '<p>Liebe(r) !!header_name!!,</p>' );
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
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		swiftling_keep_folder( '' );
		parent::tear_down();
	}

	/**
	 * A level the plugin hands out is translated as a copy, as the plugin keeps the one it read; its name
	 * where a page of the plugin prints it from its table, and on the confirmation page; the original
	 * stays.
	 */
	public function test_levels() {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters, as it runs them.
		$level = pmpro_getLevel( 1 );
		$this->show_in( 'de' );
		$levels = apply_filters( 'pmpro_levels_array', array( 1 => $level ) );
		$this->assertSame( 'Setzling', $levels[1]->name );
		$this->assertSame( 'Vrtno pismo svaki mjesec.', $levels[1]->description, 'Not translated yet' );
		$this->assertSame( 'Sadnica', $level->name, 'The plugin\'s own' );
		$this->assertSame( 'Setzling', apply_filters( 'pmpro_checkout_level', $level )->name );

		$this->assertSame( '<td> Setzling </td>', apply_filters( 'do_shortcode_tag', '<td> Sadnica </td>', 'pmpro_confirmation' ) );
		$this->assertSame( '<td>Sadnica</td>', apply_filters( 'do_shortcode_tag', '<td>Sadnica</td>', 'gallery' ), 'Another shortcode' );
		$this->assertSame( '<td>Setzling</td>', $this->pmpro->translateBlock( '<td>Sadnica</td>', array( 'blockName' => 'pmpro/account-page' ) ) );
		$this->assertSame( '<td>Sadnica</td>', $this->pmpro->translateBlock( '<td>Sadnica</td>', array( 'blockName' => 'core/table' ) ) );
		$order = (object) array( 'membership_id' => 1 );
		$this->assertSame( '<p>Vaša Setzling</p><p>Willkommen im Club.</p>' . "\n", apply_filters( 'pmpro_confirmation_message', '<p>Vaša Sadnica</p>' . wpautop( 'Dobro došli u klub.' ), $order ) );

		$this->reset_state();
		$this->assertSame( 'Sadnica', apply_filters( 'pmpro_levels_array', array( 1 => $level ) )[1]->name );
		// phpcs:enable
	}

	/**
	 * The settings offer the levels, the messages and the templates of emails to members with the header
	 * of every email, not the templates of emails to the site's owner.
	 */
	public function test_texts_offered() {
		update_option( 'pmpro_nonmembertext', 'Samo za članove.' );
		$values = wp_list_pluck( swiftling_save_field_list( swiftling_subject( 'pmpro-settings' ) )['units'], 'v' );
		foreach ( array( 'Samo za članove.', 'Sadnica', 'Vrtno pismo svaki mjesec.', 'Dobro došli u klub.', 'Your membership at {{ sitename }}', '<p>You are now a !!membership_level_name!!. Log in: !!login_url!!</p>', '<p>Dear !!header_name!!,</p>' ) as $text ) {
			$this->assertContains( $text, $values );
		}
		$this->assertNotContains( '<p>A new !!membership_level_name!! joined. See: !!login_url!!</p>', $values, 'The owner\'s' );

		$items = apply_filters( 'swiftling_machine_items', array( 'a' => array( 'text' => 'Bok !!name!!, {{ sitename }}' ) ) );
		$this->assertSame( array( '!!name!!', '{{ sitename }}' ), $items['a']['keep'] );
	}

	/**
	 * An email to a member who registered in German is written in German, once, by its template, also
	 * when the plugin sends it from the admin: its translation, the level's name, the header, and the
	 * addresses of German pages. A member who registered in the original gets the original.
	 */
	public function test_member_email_in_their_language() {
		$this->show_in( 'de' );
		$member = get_userdata( self::factory()->user->create() );
		$this->reset_state();
		$other = get_userdata( self::factory()->user->create() );
		$site  = untrailingslashit( get_option( 'home' ) );

		( new \PMPro_Email_Template_Checkout_Free( $member ) )->send();
		( new \PMPro_Email_Template_Checkout_Free( $other ) )->send();

		$this->assertCount( 2, $this->mails, 'Once each' );
		$this->assertSame( 'Ihre Mitgliedschaft bei Vrt', $this->mails[0]['subject'] );
		$this->assertSame( '<p>Liebe(r) !!header_name!!,</p><p>Sie sind jetzt Setzling. Anmelden: ' . $site . '/de/prijava/</p>', $this->mails[0]['message'] );
		$this->assertSame( 'Your membership at Vrt', $this->mails[1]['subject'] );
		$this->assertSame( '<p>Dear !!header_name!!,</p><p>You are now a Sadnica. Log in: ' . $site . '/prijava/</p>', $this->mails[1]['message'] );
		$this->assertSame( '', swiftling_mode(), 'The request as it was' );
	}

	/**
	 * The email to the site's owner, sent from a page in German, is written in the original, with the
	 * addresses of the original's pages; the member's from that page in German without writing it again.
	 */
	public function test_owners_email_in_the_original() {
		$member = get_userdata( self::factory()->user->create() );
		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );
		$site = untrailingslashit( get_option( 'home' ) );

		( new \PMPro_Email_Template_Checkout_Free_Admin( $member ) )->send();
		( new \PMPro_Email_Template_Checkout_Free( $member ) )->send();

		$this->assertCount( 2, $this->mails );
		$this->assertSame( get_bloginfo( 'admin_email' ), $this->mails[0]['to'] );
		$this->assertSame( 'Your membership at Vrt', $this->mails[0]['subject'] );
		$this->assertSame( '<p>Dear !!header_name!!,</p><p>A new Sadnica joined. See: ' . $site . '/prijava/</p>', $this->mails[0]['message'] );
		$this->assertSame( 'Ihre Mitgliedschaft bei Vrt', $this->mails[1]['subject'] );
		$this->assertStringContainsString( $site . '/de/prijava/', $this->mails[1]['message'] );
		$this->assertSame( 'translate', swiftling_mode() );
	}
}
