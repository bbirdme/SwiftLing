<?php
/**
 * Tests of the class of Charitable.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Charitable;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeCharitable.php';
require_once __DIR__ . '/stubs/FakeCharitableEmail.php';
require_once __DIR__ . '/stubs/FakeCharitableDonation.php';

/**
 * What SwiftLing translates of Charitable: its settings, an email's text whole with the plugin's
 * shortcodes in it, the descriptions of a campaign's suggested amounts, the names of a donation's
 * campaigns, and each email in the language of its reader.
 */
class CharitableTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeCharitable
	 */
	private $charitable;

	/**
	 * German in folders, the fake in the registry, the plugin's shortcode of its emails, and its settings.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		register_post_type( 'campaign', array( 'public' => true ) );
		$this->charitable = new FakeCharitable();
		$builders         = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Charitable ? $this->charitable : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->charitable->register();
		add_shortcode(
			'charitable_email',
			static function () {
				return 'Ana';
			}
		);
		update_option(
			'charitable_settings',
			array(
				'emails_donation_receipt' => array(
					'subject'  => 'Hvala na daru',
					'headline' => 'Vaš dar',
					'body'     => 'Draga [charitable_email show=donor_first_name], hvala na daru za [charitable_email show=campaigns].',
				),
				'emails_new_donation'     => array(
					'subject' => 'Novi dar',
					'body'    => '[charitable_email show=donor] je darovao.',
				),
				'gateways_offline'        => array(
					'label'        => 'Platite kasnije',
					'instructions' => 'Poslat ćemo vam podatke.',
				),
				'currency'                => 'EUR',
			)
		);
	}

	/**
	 * Gives the registry back its own builders, and the request its folder.
	 */
	public function tear_down() {
		remove_shortcode( 'charitable_email' );
		unregister_post_type( 'campaign' );
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
	 * The settings offer the texts of the emails to donors, the text of an email whole with the plugin's
	 * shortcodes in it, and the name and instructions of a payment method; not the emails to the site's
	 * owner, nor what a shortcode names. The plugin reads its settings translated in a language, and its
	 * form is answered in the language of its page.
	 */
	public function test_settings() {
		$body  = 'Draga [charitable_email show=donor_first_name], hvala na daru za [charitable_email show=campaigns].';
		$units = $this->units( 'charitable-settings' );
		foreach ( array( 'Hvala na daru', 'Vaš dar', $body, 'Platite kasnije', 'Poslat ćemo vam podatke.' ) as $text ) {
			$this->assertArrayHasKey( $text, $units );
		}
		foreach ( array( 'Novi dar', 'donor_first_name', 'campaigns', 'Draga ', 'EUR' ) as $silent ) {
			$this->assertArrayNotHasKey( $silent, $units );
		}
		$this->translate( $units[ $body ]['o'], $units[ $body ]['p'], $body, 'de', 'Liebe [charitable_email show=donor_first_name], danke für [charitable_email show=campaigns].' );
		$this->translate( $units['Platite kasnije']['o'], $units['Platite kasnije']['p'], 'Platite kasnije', 'de', 'Später zahlen' );

		$this->show_in( 'de' );
		$settings = get_option( 'charitable_settings' );
		$this->assertSame( 'Liebe [charitable_email show=donor_first_name], danke für [charitable_email show=campaigns].', $settings['emails_donation_receipt']['body'] );
		$this->assertSame( 'Später zahlen', $settings['gateways_offline']['label'] );
		$this->assertSame( 'Novi dar', $settings['emails_new_donation']['subject'] );
		$this->reset_state();
		$this->assertSame( $body, get_option( 'charitable_settings' )['emails_donation_receipt']['body'] );

		foreach ( array( 'make_donation', 'get_donation_form', 'charitable_get_session_content' ) as $action ) {
			$this->assertContains( $action, $this->charitable->sendingActions() );
		}
		$machine = $this->charitable->keepPlaceholders( array( 'a' => array( 'text' => $body ) ) );
		$this->assertSame( array( '[charitable_email show=donor_first_name]', '[charitable_email show=campaigns]' ), $machine['a']['keep'] );
	}

	/**
	 * A campaign offers the descriptions of its suggested amounts, not the amounts, and the plugin reads
	 * them translated in a language.
	 */
	public function test_suggested_amounts() {
		$campaign = self::factory()->post->create( array( 'post_type' => 'campaign' ) );
		update_post_meta(
			$campaign,
			Charitable::AMOUNTS_META,
			array(
				array(
					'amount'      => 10,
					'description' => 'Paketić sjemena',
				),
				array(
					'amount'      => 50,
					'description' => 'Sjeme za razred',
				),
			)
		);
		$listed = Registry::listTexts( swiftling_subject( 'post:' . $campaign ) );
		$this->assertSame( array( 'Paketić sjemena', 'Sjeme za razred' ), wp_list_pluck( $listed, 'v' ) );
		$this->assertSame( 'Suggested amount 2 › Description', $listed[1]['place'] );
		$this->assertSame( array(), Registry::listTexts( swiftling_subject( 'post:' . self::factory()->post->create() ) ), 'A post that is no campaign' );

		$fields = $this->check(
			'post:' . $campaign,
			static function () {},
			array(
				$listed[0]['p'] => null,
				$listed[1]['p'] => null,
			)
		)['sections']['fields'];
		$this->translate( $fields[0]['o'], $fields[0]['p'], 'Paketić sjemena', 'de', 'Ein Päckchen Samen' );

		$this->show_in( 'de' );
		$amounts = get_post_meta( $campaign, Charitable::AMOUNTS_META, true );
		$this->assertSame( 'Ein Päckchen Samen', $amounts[0]['description'] );
		$this->assertSame( 10, $amounts[0]['amount'] );
		$this->assertSame( 'Sjeme za razred', $amounts[1]['description'], 'Not translated yet: the original' );
	}

	/**
	 * A donation made in a language keeps it. An email to its donor is written in it, also when the
	 * plugin sends it from the original; an email to the site's owner in the original. An email the
	 * plugin gave up on before it was sent is ended at the next email or once the donation's emails are
	 * sent, so the request goes on in its language.
	 */
	public function test_emails_in_the_language_of_the_donation() {
		$donation = self::factory()->post->create();
		$this->show_in( 'de' );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks, as it runs them.
		do_action( 'charitable_after_save_donation', $donation, null );
		$this->assertSame( 'de', get_post_meta( $donation, Charitable::LANGUAGE_META, true ) );

		$owner = new FakeCharitableEmail( 'new_donation', new FakeCharitableDonation( $donation ) );
		do_action( 'charitable_before_send_email', $owner );
		$this->assertSame( '', swiftling_mode(), 'The owner\'s email in the original' );
		do_action( 'charitable_after_send_email', $owner, true );
		$this->assertSame( 'translate', swiftling_mode() );

		// The plugin gives up on an email before it sends it: no end. The next one ends it.
		do_action( 'charitable_before_send_email', $owner );
		$donor = new FakeCharitableEmail( 'donation_receipt', new FakeCharitableDonation( $donation ) );
		do_action( 'charitable_before_send_email', $donor );
		$this->assertSame( 'translate', swiftling_mode(), 'The donor\'s email, in the donation\'s language' );
		do_action( 'charitable_after_send_email', $donor, true );
		$this->assertSame( 'de', swiftling_state()['language'] );
		do_action( 'charitable_before_send_email', $owner );
		do_action( 'charitable_after_save_donation', $donation, null );
		$this->assertSame( 'translate', swiftling_mode(), 'Ended once the donation\'s emails are sent' );

		$this->reset_state();
		do_action( 'charitable_before_send_email', $donor );
		$this->assertSame( 'translate', swiftling_mode(), 'Sent later, from the original' );
		$this->assertSame( 'de_DE', get_locale() );
		do_action( 'charitable_after_send_email', $donor, true );
		$this->assertSame( '', swiftling_mode() );

		$other = self::factory()->post->create();
		do_action( 'charitable_after_save_donation', $other, null );
		$this->assertSame( '', get_post_meta( $other, Charitable::LANGUAGE_META, true ), 'Nothing kept in the original' );
		do_action( 'charitable_before_send_email', new FakeCharitableEmail( 'donation_receipt', new FakeCharitableDonation( $other ) ) );
		$this->assertSame( '', swiftling_mode(), 'A donation made in the original' );
		do_action( 'charitable_after_send_email', $donor, true );
		// phpcs:enable
	}

	/**
	 * The receipt and an email name a donation's campaigns, which the plugin keeps with the donation as
	 * they were named, by the campaigns' titles in the language. Nothing changes in the original.
	 */
	public function test_names_of_the_campaigns() {
		$campaign = self::factory()->post->create(
			array(
				'post_type'  => 'campaign',
				'post_title' => 'Sjeme za škole',
			)
		);
		// The campaign's page, checked as the editor checks it, with its title.
		$this->check(
			'post:' . $campaign,
			static function () use ( $campaign ) {
				get_the_title( $campaign );
			}
		);
		$this->translate( swiftling_post_owner( $campaign ), 'field/post_title', 'Sjeme za škole', 'de', 'Samen für Schulen' );
		$record   = (object) array(
			'campaign_id'   => $campaign,
			'campaign_name' => 'Sjeme za škole',
			'amount'        => 50,
		);
		$donation = new FakeCharitableDonation( 9, array( $record ) );
		$email    = new FakeCharitableEmail( 'donation_receipt', $donation );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks, as it runs them.
		$this->assertSame( 'Sjeme za škole, Drugo', apply_filters( 'charitable_email_content_field_value_campaigns', 'Sjeme za škole, Drugo', array(), $email ), 'The original' );
		do_action( 'charitable_donation_receipt_page', $donation );
		$this->assertSame( 'Sjeme za škole', $record->campaign_name );

		$this->show_in( 'de' );
		$this->assertSame( 'Samen für Schulen, Drugo', apply_filters( 'charitable_email_content_field_value_campaigns', 'Sjeme za škole, Drugo', array(), $email ) );
		$this->assertSame( 'Sjeme za škole', $record->campaign_name, 'The email leaves the donation as it is' );
		do_action( 'charitable_donation_receipt_page', $donation );
		$this->assertSame( 'Samen für Schulen', $record->campaign_name );
		// phpcs:enable
	}
}
