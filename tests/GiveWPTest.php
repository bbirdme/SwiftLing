<?php
/**
 * Tests of the classes of GiveWP.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\GiveWP;
use SwiftLing\Builders\Registry;
use SwiftLing\Forms\GiveWP as GiveWPForms;

require_once __DIR__ . '/stubs/FakeGiveWPForms.php';
require_once __DIR__ . '/stubs/FakeGiveWP.php';

/**
 * What SwiftLing translates of GiveWP: the texts of its forms and receipts in the data the plugin hands
 * its script, its emails to donors in the donor's language and those to the site's owner in the
 * original, and its campaigns.
 */
class GiveWPTest extends TestCase {

	/**
	 * The class of the forms.
	 *
	 * @var FakeGiveWPForms
	 */
	private $forms;

	/**
	 * The class of the rest of the plugin.
	 *
	 * @var FakeGiveWP
	 */
	private $give;

	/**
	 * German in folders, the fakes in the registry, and one form of every kind of text, as the plugin
	 * hands it to its script.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		$this->forms = new FakeGiveWPForms();
		$this->give  = new FakeGiveWP();
		$builders    = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof GiveWPForms ? $this->forms : ( $builder instanceof GiveWP ? $this->give : $builder );
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->forms->register();
		$this->give->register();
		$this->give->hookEmail( 'new-donation' );
		$this->give->hookEmail( 'donation-receipt' );
		$this->forms->forms[4]               = self::form();
		$GLOBALS['swiftling_test_give_meta'] = array();
	}

	/**
	 * Gives the registry back its own builders, and WordPress its scripts.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		unset( $GLOBALS['swiftling_test_give_meta'], $GLOBALS['l10n']['give'] );
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of scripts.
		swiftling_keep_folder( '' );
		parent::tear_down();
	}

	/**
	 * Returns a form as the plugin hands it to its script: two sections with fields, the same text twice,
	 * descriptions of amounts, a paragraph, terms, the field that catches robots, and the settings.
	 *
	 * @return array
	 */
	private static function form() {
		return array(
			'nodeType' => 'group',
			'type'     => 'form',
			'name'     => 'donation-form',
			'nodes'    => array(
				array(
					'type'        => 'section',
					'name'        => 'section-1',
					'label'       => 'Koliko želite dati?',
					'description' => 'Svaki dar pomaže.',
					'nodes'       => array(
						array(
							'type'  => 'donationAmount',
							'name'  => 'donationAmount',
							'nodes' => array(
								array(
									'type'   => 'amount',
									'name'   => 'amount',
									'label'  => 'Iznos',
									'levels' => array(
										array(
											'value' => 10,
											'label' => 'Jedna sadnica',
										),
										array(
											'value' => 25,
											'label' => '',
										),
									),
								),
							),
						),
						array(
							'type'    => 'paragraph',
							'name'    => 'paragraph-1',
							'content' => 'Sadimo u <strong>proljeće</strong>.',
						),
					),
				),
				array(
					'type'        => 'section',
					'name'        => 'section-2',
					'label'       => 'Tko daruje?',
					'description' => '',
					'nodes'       => array(
						array(
							'type'        => 'text',
							'name'        => 'firstName',
							'label'       => 'Ime',
							'placeholder' => 'Ana',
						),
						array(
							'type'  => 'email',
							'name'  => 'email',
							'label' => 'E-mail',
						),
						array(
							'type'          => 'consent',
							'name'          => 'consent-2',
							'checkboxLabel' => 'Prihvaćam pravila.',
							'agreementText' => 'Darovi idu samo drveću.',
							'linkUrl'       => 'https://example.org/pravila',
						),
						array(
							'type'  => 'honeypot',
							'name'  => 'donationBirthday',
							'label' => 'Rođendan donacije',
						),
						array(
							'type'  => 'text',
							'name'  => 'company',
							'label' => 'Ime',
						),
					),
				),
			),
			'settings' => array(
				'formTitle'                    => 'Posadi drvo',
				'heading'                      => 'Podržite nas',
				'donateButtonCaption'          => 'Daruj',
				'receiptHeading'               => 'Hvala, {first_name}!',
				'receiptDescription'           => '{first_name}, potvrdu smo poslali na {email}.',
				'offlineDonationsCustomize'    => false,
				'offlineDonationsInstructions' => 'Uplatite na račun.',
				'designId'                     => 'multi-step',
				'primaryColor'                 => '#2d6a4f',
			),
		);
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
	 * Saves German translations of texts of a subject.
	 *
	 * @param string $key    Subject key.
	 * @param array  $german Original => translation.
	 */
	private function translate_texts( $key, array $german ) {
		$units = $this->units( $key );
		foreach ( $german as $original => $text ) {
			$this->assertArrayHasKey( $original, $units, $original . ' is offered' );
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
	}

	/**
	 * Hands the plugin's script its data as the plugin does, has WordPress print the scripts of the
	 * footer, or of the head, and returns the data the script got.
	 *
	 * @param array  $lines Lines of data, as the plugin adds them.
	 * @param string $where Where the plugin prints its script: "footer" or "head".
	 * @return array<int, string>
	 */
	private function printed( array $lines, $where = 'footer' ) {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of scripts.
		wp_register_script( GiveWPForms::SCRIPT, 'https://example.org/registrars.js', array(), '1', 'footer' === $where );
		foreach ( $lines as $line ) {
			wp_add_inline_script( GiveWPForms::SCRIPT, $line, 'before' );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own action, as it runs it.
		do_action( 'givewp_donation_form_schema', null, 4, array() );
		wp_enqueue_script( GiveWPForms::SCRIPT );
		ob_start();
		if ( 'footer' === $where ) {
			wp_print_footer_scripts();
		} else {
			// As wp_print_head_scripts() does the first time in a request, as on the plugin's page of a form.
			do_action( 'wp_print_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own action.
			print_head_scripts();
		}
		ob_end_clean();
		// WordPress starts the list with what it had before, nothing.
		return array_values( array_filter( wp_scripts()->get_data( GiveWPForms::SCRIPT, 'before' ) ) );
	}

	/**
	 * Returns the data of a line the plugin hands its script.
	 *
	 * @param string $line The line.
	 * @return array
	 */
	private static function data( $line ) {
		return json_decode( preg_replace( '/^window\.\w+ = (.*);$/s', '$1', $line ), true );
	}

	/**
	 * A form offers its settings a visitor reads and each text of its fields once, wherever it is, each
	 * named by its field; not the field that catches robots, an address, a colour, or the instructions of
	 * an offline donation the form does not use.
	 */
	public function test_form_texts() {
		$this->assertSame(
			array(
				array(
					'key'   => 'givewp-form:4',
					'id'    => 4,
					'title' => 'Posadi drvo',
					'pages' => 0,
				),
			),
			$this->forms->forms()
		);
		$units = $this->units( 'givewp-form:4' );
		foreach ( array( 'Posadi drvo', 'Podržite nas', 'Daruj', 'Hvala, {first_name}!', 'Koliko želite dati?', 'Svaki dar pomaže.', 'Iznos', 'Jedna sadnica', 'Sadimo u <strong>proljeće</strong>.', 'Tko daruje?', 'Ime', 'Ana', 'E-mail', 'Prihvaćam pravila.', 'Darovi idu samo drveću.' ) as $text ) {
			$this->assertArrayHasKey( $text, $units );
		}
		foreach ( array( 'Rođendan donacije', 'https://example.org/pravila', '#2d6a4f', 'multi-step', 'Uplatite na račun.' ) as $silent ) {
			$this->assertArrayNotHasKey( $silent, $units, $silent . ' is no text a visitor reads' );
		}
		$this->assertSame( 'givewp:4', $units['Ime']['o'] );
		$this->assertSame( 'form/texts/fields/' . substr( md5( 'Ime' ), 0, 8 ), $units['Ime']['p'], 'A text of a field by its words' );
		$this->assertSame( 'form/texts/settings/receiptHeading', $units['Hvala, {first_name}!']['p'] );
		$this->assertSame( 'Tko daruje? › Ime › Label', $this->forms->fieldLabel( 'givewp:4', $units['Ime']['p'] ) );
		$this->assertSame( 'Koliko želite dati? › Iznos › Amount 1', $this->forms->fieldLabel( 'givewp:4', $units['Jedna sadnica']['p'] ) );

		$this->forms->forms[4]['settings']['offlineDonationsCustomize'] = true;
		$this->assertArrayHasKey( 'Uplatite na račun.', $this->units( 'givewp-form:4' ), 'Offered once the form uses its own' );
	}

	/**
	 * In a language, the data of the form the plugin hands its script holds the translations, each text
	 * wherever it is, before WordPress prints it; other lines stay as they are. In the original, and for
	 * a form the plugin did not build, nothing changes.
	 */
	public function test_form_data_in_the_language() {
		$this->translate_texts(
			'givewp-form:4',
			array(
				'Podržite nas'                        => 'Unterstützen Sie uns',
				'Ime'                                 => 'Name',
				'Jedna sadnica'                       => 'Ein Setzling',
				'Sadimo u <strong>proljeće</strong>.' => 'Wir pflanzen im <strong>Frühling</strong>.',
				'Prihvaćam pravila.'                  => 'Ich akzeptiere die Regeln.',
			)
		);
		$line  = 'window.givewpDonationFormExports = ' . wp_json_encode(
			array(
				'donateUrl' => 'https://example.org/?givewp-route=donate',
				'form'      => self::form(),
			)
		) . ';';
		$other = 'window.givewpDonationFormRegistrars = {"x":1};';

		$this->show_in( 'de' );
		$printed = $this->printed( array( $line, $other ) );
		$data    = self::data( $printed[0] );
		$this->assertSame( 'Unterstützen Sie uns', $data['form']['settings']['heading'] );
		$this->assertSame( 'Name', $data['form']['nodes'][1]['nodes'][0]['label'] );
		$this->assertSame( 'Name', $data['form']['nodes'][1]['nodes'][4]['label'], 'The same text, translated once, wherever it is' );
		$this->assertSame( 'Ein Setzling', $data['form']['nodes'][0]['nodes'][0]['nodes'][0]['levels'][0]['label'] );
		$this->assertSame( 'Wir pflanzen im <strong>Frühling</strong>.', $data['form']['nodes'][0]['nodes'][1]['content'] );
		$this->assertSame( 'Ich akzeptiere die Regeln.', $data['form']['nodes'][1]['nodes'][2]['checkboxLabel'] );
		$this->assertSame( 'Rođendan donacije', $data['form']['nodes'][1]['nodes'][3]['label'], 'The field that catches robots' );
		$this->assertSame( 'Daruj', $data['form']['settings']['donateButtonCaption'], 'Not translated yet: the original' );
		$this->assertSame( 'https://example.org/?givewp-route=donate', $data['donateUrl'] );
		$this->assertSame( $other, $printed[1] );
		$head = self::data( $this->printed( array( $line ), 'head' )[0] );
		$this->assertSame( 'Unterstützen Sie uns', $head['form']['settings']['heading'], 'A script printed in the head' );

		$this->reset_state();
		$this->assertSame( array( $line ), $this->printed( array( $line ) ), 'The original' );
		swiftling_start( 'probe', '' );
		$this->assertSame( array( $line ), $this->printed( array( $line ) ), 'A page check, which never opens the form\'s page, leaves it' );
		$this->reset_state();
	}

	/**
	 * A receipt's heading and description are written again from the translations of the form's
	 * templates, with the values the plugin put in them; a label of its details that is a text of the
	 * form is translated as there.
	 */
	public function test_receipt_in_the_language() {
		$this->translate_texts(
			'givewp-form:4',
			array(
				'Hvala, {first_name}!' => 'Danke, {first_name}!',
				'{first_name}, potvrdu smo poslali na {email}.' => '{first_name}, wir haben die Quittung an {email} geschickt.',
				'E-mail'               => 'E-Mail',
			)
		);
		$receipt = array(
			'receipt' => array(
				'settings'          => array(
					'heading'     => 'Hvala, Eva!',
					'description' => 'Eva, potvrdu smo poslali na eva@example.org.',
					'currency'    => 'EUR',
				),
				'donorDetails'      => array(
					array(
						'label' => 'E-mail',
						'value' => 'eva@example.org',
					),
				),
				'additionalDetails' => array(),
			),
		);
		$line    = 'window.givewpDonationConfirmationReceiptExports = ' . wp_json_encode( $receipt ) . ';';

		$this->show_in( 'de' );
		$data = self::data( $this->printed( array( $line ) )[0] )['receipt'];
		$this->assertSame( 'Danke, Eva!', $data['settings']['heading'] );
		$this->assertSame( 'Eva, wir haben die Quittung an eva@example.org geschickt.', $data['settings']['description'] );
		$this->assertSame( 'E-Mail', $data['donorDetails'][0]['label'] );
		$this->assertSame( 'eva@example.org', $data['donorDetails'][0]['value'] );
	}

	/**
	 * An email names a donation by its form's title and the description of the chosen amount, each
	 * translated as on the form.
	 */
	public function test_title_of_a_donation() {
		$this->translate_texts(
			'givewp-form:4',
			array(
				'Posadi drvo'   => 'Pflanz einen Baum',
				'Jedna sadnica' => 'Ein Setzling',
			)
		);
		$GLOBALS['swiftling_test_give_meta'][31]['_give_payment_form_id'] = 4;
		$this->show_in( 'de' );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( 'Pflanz einen Baum - Ein Setzling', apply_filters( 'give_get_donation_form_title', 'Posadi drvo - Jedna sadnica', 31 ) );
		$this->assertSame( 'Pflanz einen Baum', apply_filters( 'give_get_donation_form_title', 'Posadi drvo', 31 ) );
		$this->assertSame( 'Drugi obrazac', apply_filters( 'give_get_donation_form_title', 'Drugi obrazac', 31 ), 'Not the form\'s title' );
		$this->reset_state();
		$this->assertSame( 'Posadi drvo - Jedna sadnica', apply_filters( 'give_get_donation_form_title', 'Posadi drvo - Jedna sadnica', 31 ) );
		// phpcs:enable
	}

	/**
	 * The texts of the emails to donors and of the terms are offered and translated where the plugin
	 * hands them out; an email to the site's owner is not.
	 */
	public function test_settings_texts() {
		update_option(
			'give_settings',
			array(
				'donation-receipt_email_message' => 'Dragi {name}, hvala na daru.',
				'new-donation_email_message'     => 'Novi dar od {name}.',
				'agreement_text'                 => 'Darovi idu drveću.',
				'currency'                       => 'EUR',
			)
		);
		$units = $this->units( 'give-settings' );
		$this->assertArrayHasKey( 'Dragi {name}, hvala na daru.', $units );
		$this->assertArrayHasKey( 'Darovi idu drveću.', $units );
		$this->assertArrayNotHasKey( 'Novi dar od {name}.', $units, 'The email to the site\'s owner' );
		$this->assertArrayNotHasKey( 'EUR', $units );
		$this->translate( $units['Dragi {name}, hvala na daru.']['o'], $units['Dragi {name}, hvala na daru.']['p'], 'Dragi {name}, hvala na daru.', 'de', 'Liebe(r) {name}, danke.' );

		$this->show_in( 'de' );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( 'Liebe(r) {name}, danke.', apply_filters( 'give_get_option', 'Dragi {name}, hvala na daru.', 'donation-receipt_email_message', false ) );
		$this->assertSame( 'Novi dar od {name}.', apply_filters( 'give_get_option', 'Novi dar od {name}.', 'new-donation_email_message', false ) );
		$this->reset_state();
		$this->assertSame( 'Dragi {name}, hvala na daru.', apply_filters( 'give_get_option', 'Dragi {name}, hvala na daru.', 'donation-receipt_email_message', false ) );
		// phpcs:enable
	}

	/**
	 * A donation made in a language keeps it. An email to its donor is written in it, also when the
	 * plugin writes it later from the original, its default subject from the plugin's language pack in
	 * that language; an email to the site's owner about a donation made in a language is written in the
	 * original, its default subject, which the request worked out in the language, back in the original.
	 * The request goes on in its own language afterwards.
	 */
	public function test_emails_in_the_language_of_the_donation() {
		$this->show_in( 'de' );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The plugin's own hooks, as it runs them.
		do_action( 'give_insert_payment', 31, array() );
		$this->assertSame( 'de', give_get_meta( 31, GiveWP::LANGUAGE_META, true ) );

		// The plugin's language pack in German, which WordPress loads whenever it runs in German, as for
		// this request on a page in German.
		$pack = new \MO();
		foreach ( array(
			'New Donation - #{payment_id}' => 'Neue Spende - #{payment_id}',
			'Donation Receipt'             => 'Spendenquittung',
		) as $original => $german ) {
			$pack->add_entry(
				new \Translation_Entry(
					array(
						'singular'     => $original,
						'translations' => array( $german ),
					)
				)
			);
		}
		$load = static function ( $locale ) use ( $pack ) {
			if ( 'de_DE' === $locale ) {
				$GLOBALS['l10n']['give'] = $pack; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The plugin's language pack, as WordPress loads it.
			}
		};
		add_action( 'change_locale', $load );
		$GLOBALS['l10n']['give'] = $pack; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As above.
		$decided                 = &swiftling_decided_language();
		$decided                 = 'de';
		$owner                   = self::email( 'new-donation', true, 'Neue Spende - #{payment_id}' );
		$this->send( $owner, 31 );
		$this->assertSame( '', swiftling_mode(), 'The owner\'s email in the original' );
		$this->assertSame( 'New Donation - #{payment_id}', apply_filters( 'give_new-donation_get_email_subject', 'Neue Spende - #{payment_id}', $owner, 4 ) );
		$this->assertSame( 'Eigener Betreff', apply_filters( 'give_new-donation_get_email_subject', 'Eigener Betreff', $owner, 4 ), 'A subject of the owner\'s own' );
		$this->sent( $owner );
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'de', swiftling_state()['language'], 'The request goes on in its language' );

		$donor = self::email( 'donation-receipt', false, 'Spendenquittung' );
		$this->send( $donor, 31 );
		$this->assertSame( 'translate', swiftling_mode(), 'The donor\'s email in the request\'s language, the donation\'s' );
		$this->sent( $donor );

		// Later, from the original, as when the site's owner sends a receipt again.
		$this->reset_state();
		$decided = null;
		unset( $GLOBALS['l10n']['give'] );
		$donor = self::email( 'donation-receipt', false, 'Donation Receipt' );
		$this->send( $donor, 31 );
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'de_DE', get_locale() );
		$this->assertSame( 'Spendenquittung', apply_filters( 'give_donation-receipt_get_email_subject', 'Donation Receipt', $donor, 4 ) );
		$this->sent( $donor );
		$this->assertSame( '', swiftling_mode(), 'The request goes on in the original' );

		$this->send( $donor, 32 );
		$this->assertSame( '', swiftling_mode(), 'A donation made in the original' );
		$this->assertSame( 'Donation Receipt', apply_filters( 'give_donation-receipt_get_email_subject', 'Donation Receipt', $donor, 4 ) );
		$this->sent( $donor );
		remove_action( 'change_locale', $load );
		// phpcs:enable

		$this->reset_state();
		do_action( 'give_insert_payment', 33, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
		$this->assertSame( '', give_get_meta( 33, GiveWP::LANGUAGE_META, true ), 'Nothing kept in the original' );
	}

	/**
	 * Starts writing an email about a donation as the plugin does: it hands the email's placeholders over,
	 * then says it sends.
	 *
	 * @param object $email       The plugin's email.
	 * @param int    $donation_id The donation.
	 */
	private function send( $email, $donation_id ) {
		$id = $email->config['id'];
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The plugin's own hooks, as it runs them.
		apply_filters( "give_{$id}_email_tag_args", array( 'payment_id' => $donation_id ), $email );
		do_action( "give_{$id}_email_send_before", $email, 4 );
		// phpcs:enable
	}

	/**
	 * Ends an email as the plugin does once it sent it.
	 *
	 * @param object $email The plugin's email.
	 */
	private function sent( $email ) {
		do_action( "give_{$email->config['id']}_email_send_after", true, $email, 4 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
	}

	/**
	 * Returns an email of the plugin, as its object holds its settings.
	 *
	 * @param string $id      The email.
	 * @param bool   $owner   Whether it goes to the site's owner.
	 * @param string $subject Its default subject, as the request worked it out.
	 * @return object
	 */
	private static function email( $id, $owner, $subject ) {
		return (object) array(
			'config' => array(
				'id'                    => $id,
				'has_recipient_field'   => $owner,
				'default_email_subject' => $subject,
			),
		);
	}

	/**
	 * A campaign is a subject of its own; its title is translated where the plugin's block prints it, and
	 * its title and short description where the plugin's REST API hands campaigns to its blocks, which
	 * ask in the language of the page that holds them.
	 */
	public function test_campaigns() {
		$this->give->campaigns = array(
			1 => array(
				'title'            => 'Posadi drvo',
				'shortDescription' => 'Sto stabala uz rijeku.',
			),
		);
		$this->assertSame( 'give-campaign:1', $this->give->subjects()[0]['subjects'][1]['key'] );
		$this->assertContains( '#^/givewp/v3/campaigns(/\d+)?/?$#', $this->give->sendingRoutes() );
		$this->translate_texts(
			'give-campaign:1',
			array(
				'Posadi drvo'            => 'Pflanz einen Baum',
				'Sto stabala uz rijeku.' => 'Hundert Bäume am Fluss.',
			)
		);

		$this->show_in( 'de' );
		$block = array( 'attrs' => array( 'campaignId' => 1 ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- WordPress's filter of the plugin's block.
		$this->assertSame( '<h1 class="givewp-campaign-title">Pflanz einen Baum</h1>', apply_filters( 'render_block_givewp/campaign-title', '<h1 class="givewp-campaign-title">Posadi drvo</h1>', $block ) );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's filter, as the REST API runs it.
		$request  = new \WP_REST_Request( 'GET', '/givewp/v3/campaigns' );
		$response = new \WP_REST_Response(
			array(
				array(
					'id'               => 1,
					'title'            => 'Posadi drvo',
					'shortDescription' => 'Sto stabala uz rijeku.',
					'goal'             => 5000,
				),
			)
		);
		$data     = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request )->get_data();
		$this->assertSame( 'Pflanz einen Baum', $data[0]['title'] );
		$this->assertSame( 'Hundert Bäume am Fluss.', $data[0]['shortDescription'] );
		$this->assertSame( 5000, $data[0]['goal'] );
		$one = apply_filters(
			'rest_post_dispatch',
			new \WP_REST_Response(
				array(
					'id'    => 1,
					'title' => 'Posadi drvo',
				)
			),
			rest_get_server(),
			new \WP_REST_Request( 'GET', '/givewp/v3/campaigns/1' )
		)->get_data();
		$this->assertSame( 'Pflanz einen Baum', $one['title'] );
		$other = apply_filters(
			'rest_post_dispatch',
			new \WP_REST_Response(
				array(
					'id'    => 1,
					'title' => 'Posadi drvo',
				)
			),
			rest_get_server(),
			new \WP_REST_Request( 'GET', '/wp/v2/pages/1' )
		)->get_data();
		$this->assertSame( 'Posadi drvo', $other['title'], 'Another route' );
		// phpcs:enable

		$this->reset_state();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- As above.
		$this->assertSame( '<h1>Posadi drvo</h1>', apply_filters( 'render_block_givewp/campaign-title', '<h1>Posadi drvo</h1>', $block ) );
	}
}
