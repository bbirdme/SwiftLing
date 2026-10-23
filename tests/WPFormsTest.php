<?php
/**
 * Tests of the WPForms class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Forms\WPForms;

require_once __DIR__ . '/stubs/FakeWPForms.php';

/**
 * What the WPForms class does: where its forms are, and the texts it hands to the translation of a
 * page at the filter the plugin calls while it shows a form, without changing what a visitor sends.
 */
class WPFormsTest extends TestCase {

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Makes the class with one form of every shape a form of the plugin has.
	 *
	 * @return FakeWPForms
	 */
	private function provider() {
		$provider = new FakeWPForms();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof WPForms ? $provider : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		// The hooks the plugin's class adds on a site with the plugin; the test restores hooks after it.
		$provider->register();

		$provider->forms = array(
			12 => array(
				'id'       => '12',
				'settings' => array(
					'form_title'             => 'Podaci o vozilu',
					'form_desc'              => 'Kratko o vozilu.',
					'submit_text'            => 'Pošalji',
					'submit_text_processing' => 'Šaljem...',
					'form_class'             => 'wide',
					'confirmations'          => array(
						1 => array(
							'type'    => 'message',
							'message' => '<p>Hvala.</p>',
						),
						2 => array(
							'type' => 'page',
							'page' => '7',
						),
					),
					'notification_enable'    => '1',
					'notifications'          => array(
						1 => array(
							'email'   => '{admin_email}',
							'subject' => 'Novi unos: vozilo',
							'message' => '{all_fields}',
						),
						2 => array(
							'notification_name' => 'Potvrda kupcu',
							'email'             => '{field_id="4"}',
							'subject'           => 'Primili smo podatke',
							'message'           => 'Javit ćemo se.',
							'sender_name'       => 'Auto kuća',
						),
						3 => array(
							'email'   => 'ured@example.com, {field_id="4"}',
							'subject' => 'Kopija za ured',
							'message' => 'Stigao je unos.',
						),
					),
				),
				'fields'   => array(
					9  => array(
						'id'          => '9',
						'type'        => 'internal-information',
						'label'       => 'Bilješka za urednika',
						'description' => 'Ovo vidi samo graditelj forme.',
					),
					1  => array(
						'id'          => '1',
						'type'        => 'text',
						'label'       => 'Marka',
						'placeholder' => 'npr. Ford',
						'description' => 'Proizvođač vozila.',
						'size'        => 'medium',
						'css'         => 'big',
					),
					8  => array(
						'id'                => '8',
						'type'              => 'name',
						'label'             => 'Vaše ime',
						'format'            => 'first-last',
						'first_placeholder' => 'Ivan',
						'last_placeholder'  => 'Horvat',
					),
					14 => array(
						'id'      => '14',
						'type'    => 'radio',
						'label'   => 'Gorivo',
						'choices' => array(
							1 => array(
								'label' => 'Benzin',
								'value' => '',
							),
							2 => array(
								'label' => 'Dizel',
								'value' => '',
							),
						),
					),
					16 => array(
						'id'          => '16',
						'type'        => 'select',
						'label'       => 'Mjenjač',
						'show_values' => '1',
						'choices'     => array(
							1 => array(
								'label' => 'Ručni',
								'value' => 'manual',
							),
						),
					),
					10 => array(
						'id'       => '10',
						'type'     => 'gdpr-checkbox',
						'label'    => 'Privola',
						'required' => '1',
						'choices'  => array(
							1 => array(
								'label' => 'Slažem se s pohranom podataka.',
								'value' => '',
							),
						),
					),
					11 => array(
						'id'            => '11',
						'type'          => 'number-slider',
						'label'         => 'Kilometraža',
						'value_display' => 'Odabrano: {value}',
						'min'           => '0',
					),
				),
			),
		);
		return $provider;
	}

	/**
	 * A page check finds every text a visitor reads under an owner of its own, not what a program
	 * reads, not the notes only the builder of the form shows, and not the messages after sending,
	 * which the plugin shows from a request without the language of the page.
	 */
	public function test_page_check_finds_the_texts_of_a_form() {
		$provider = $this->provider();
		$leaves   = $this->probe(
			static function () use ( $provider ) {
				$provider->filterFormData( $provider->forms[12] );
			}
		);
		$found    = array();
		foreach ( $leaves as $leaf ) {
			$this->assertSame( 'wpforms:12', $leaf['o'] );
			$found[] = $leaf['v'];
		}
		foreach ( array( 'Podaci o vozilu', 'Kratko o vozilu.', 'Pošalji', 'Šaljem...', 'Marka', 'npr. Ford', 'Proizvođač vozila.', 'Vaše ime', 'Ivan', 'Horvat', 'Gorivo', 'Benzin', 'Dizel', 'Ručni', 'Privola', 'Slažem se s pohranom podataka.', 'Odabrano: {value}' ) as $text ) {
			$this->assertContains( $text, $found );
		}
		foreach ( array( 'Bilješka za urednika', 'Ovo vidi samo graditelj forme.', 'manual', 'medium', 'big', 'wide', '<p>Hvala.</p>' ) as $silent ) {
			$this->assertNotContains( $silent, $found, $silent . ' is not a text of the form here' );
		}
	}

	/**
	 * Saves German translations of texts of form 12, as the editor does: it stores the texts it lists
	 * and saves the translations typed beside them.
	 *
	 * @param FakeWPForms $provider Class.
	 * @param array       $german   Original => translation.
	 */
	private function translate_form( $provider, array $german ) {
		$this->set_languages();
		$subject = $provider->subject( 'wpforms-form:12' );
		swiftling_save_field_list( $subject );
		foreach ( $provider->listTexts( $subject ) as $text ) {
			if ( isset( $german[ $text['v'] ] ) ) {
				$this->translate( 'wpforms:12', $text['p'], $text['v'], 'de', $german[ $text['v'] ] );
			}
		}
	}

	/**
	 * The editor lists the message after sending and the email to the visitor, not the emails to the
	 * site's owner.
	 */
	public function test_texts_of_the_answer() {
		$provider = $this->provider();
		$this->assertContains( 'wpforms_submit', $provider->sendingActions() );
		$places = array();
		foreach ( $provider->listTexts( $provider->subject( 'wpforms-form:12' ) ) as $text ) {
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Message after sending', $places['<p>Hvala.</p>'] );
		$this->assertSame( 'Potvrda kupcu › Subject of the email to the visitor', $places['Primili smo podatke'] );
		$this->assertSame( 'Potvrda kupcu › Text of the email to the visitor', $places['Javit ćemo se.'] );
		foreach ( array( 'Novi unos: vozilo', '{all_fields}', 'Kopija za ured', 'Stigao je unos.', 'Auto kuća', '7' ) as $not_listed ) {
			$this->assertArrayNotHasKey( $not_listed, $places, $not_listed );
		}
	}

	/**
	 * A form sent from a page in another language: the plugin hands the form to
	 * wpforms_process_before_form_data, where the message after sending and the email to the
	 * visitor are translated, not the fields, which the entry is saved with. Each email goes through
	 * wpforms_entry_email_process; one to the site's owner is sent in the original, until the next
	 * one or the end of the sending.
	 */
	public function test_form_sent_in_another_language() {
		$provider = $this->provider();
		$this->translate_form(
			$provider,
			array(
				'<p>Hvala.</p>'       => '<p>Danke.</p>',
				'Primili smo podatke' => 'Wir haben Ihre Daten',
				'Marka'               => 'Marke',
			)
		);
		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();

		$sent = $provider->filterSentForm( $provider->forms[12], array() );
		$this->assertSame( '<p>Danke.</p>', $sent['settings']['confirmations'][1]['message'] );
		$this->assertSame( 'Wir haben Ihre Daten', $sent['settings']['notifications'][2]['subject'] );
		$this->assertSame( 'Novi unos: vozilo', $sent['settings']['notifications'][1]['subject'] );
		$this->assertSame( 'Marka', $sent['fields'][1]['label'], 'The entry is saved with the original labels' );

		$this->assertTrue( $provider->startNotification( true, array(), $sent, 1 ) );
		$this->assertSame( '', swiftling_mode(), 'The owner\'s email is built in the original' );
		$this->assertSame( swiftling_site_locale(), get_locale() );
		$this->assertFalse( $provider->startNotification( false, array(), $sent, 2 ), 'What other code decided stays' );
		$this->assertSame( 'translate', swiftling_mode(), 'The visitor\'s email is in the language' );
		$provider->startNotification( true, array(), $sent, 3 );
		$this->assertSame( '', swiftling_mode(), 'An email to a fixed address beside the visitor is the owner\'s' );
		$provider->endSending();
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'de_DE', get_locale() );

		$this->reset_state();
		$this->assertSame( $provider->forms[12], $provider->filterSentForm( $provider->forms[12], array() ), 'In the original nothing changes' );
	}

	/**
	 * A page in another language shows the translations. A choice whose label is also what the form
	 * sends keeps sending the original, which is what the plugin accepts when the form arrives
	 * through a request that knows no language; a choice with a value of its own keeps that value.
	 */
	public function test_translated_page_shows_the_translations_and_sends_the_original() {
		$this->set_languages();
		$provider = $this->provider();
		$read     = static function () use ( $provider ) {
			$provider->filterFormData( $provider->forms[12] );
		};
		$this->check( 'wpforms-form:12', $read );
		$german = array(
			'Podaci o vozilu'                => 'Fahrzeugdaten',
			'Pošalji'                        => 'Senden',
			'Marka'                          => 'Marke',
			'Benzin'                         => 'Benzin (DE)',
			'Dizel'                          => 'Diesel',
			'Ručni'                          => 'Schaltgetriebe',
			'Slažem se s pohranom podataka.' => 'Ich stimme der Speicherung zu.',
		);
		foreach ( $this->probe( $read ) as $leaf ) {
			if ( isset( $german[ $leaf['v'] ] ) ) {
				$this->translate( 'wpforms:12', $leaf['p'], $leaf['v'], 'de', $german[ $leaf['v'] ] );
			}
		}

		$this->show_in( 'de' );
		$form = $provider->filterFormData( $provider->forms[12] );
		$this->assertSame( 'Fahrzeugdaten', $form['settings']['form_title'] );
		$this->assertSame( 'Senden', $form['settings']['submit_text'] );
		$this->assertSame( 'Marke', $form['fields'][1]['label'] );
		$this->assertSame( 'npr. Ford', $form['fields'][1]['placeholder'], 'Not translated yet: the original' );

		$this->assertSame( 'Diesel', $form['fields'][14]['choices'][2]['label'] );
		$this->assertSame( 'Dizel', $form['fields'][14]['choices'][2]['value'], 'The form sends the original' );
		$this->assertSame( 'Benzin', $form['fields'][14]['choices'][1]['value'] );
		$this->assertSame( '1', $form['fields'][14]['show_values'], 'The plugin sends the value, not the label' );
		$this->assertSame( 'Slažem se s pohranom podataka.', $form['fields'][10]['choices'][1]['value'], 'A required consent can still be given' );

		$this->assertSame( 'Schaltgetriebe', $form['fields'][16]['choices'][1]['label'] );
		$this->assertSame( 'manual', $form['fields'][16]['choices'][1]['value'], 'A value of its own stays' );

		$this->reset_state();
		$original = $provider->filterFormData( $provider->forms[12] );
		$this->assertSame( $provider->forms[12], $original, 'In the original language the form is not touched at all' );
	}

	/**
	 * A consent box is drawn from its label alone, whatever the field says of values, so on a page in
	 * another language it would send the translated consent, which the plugin refuses as an option
	 * it does not know, and a form with a required consent could not be sent. Its box sends the
	 * original consent, while its label shows the translation.
	 */
	public function test_consent_sends_the_original() {
		$this->set_languages();
		$provider = $this->provider();
		$read     = static function () use ( $provider ) {
			$provider->filterFormData( $provider->forms[12] );
		};
		$this->check( 'wpforms-form:12', $read );
		foreach ( $this->probe( $read ) as $leaf ) {
			if ( 'Slažem se s pohranom podataka.' === $leaf['v'] ) {
				$this->translate( 'wpforms:12', $leaf['p'], $leaf['v'], 'de', 'Ich stimme der Speicherung zu.' );
			}
		}
		$this->show_in( 'de' );
		$form  = $provider->filterFormData( $provider->forms[12] );
		$field = $form['fields'][10];

		// What the plugin's own class makes of the field: the label as the text and as the value.
		$properties = array(
			'inputs' => array(
				1 => array(
					'label' => array( 'text' => $field['choices'][1]['label'] ),
					'attr'  => array(
						'name'  => 'wpforms[fields][10][]',
						'value' => $field['choices'][1]['label'],
					),
				),
			),
		);
		$properties = apply_filters( 'wpforms_field_properties_gdpr-checkbox', $properties, $field, $form ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The plugin's own filter, as it runs it.

		$this->assertSame( 'Ich stimme der Speicherung zu.', $properties['inputs'][1]['label']['text'] );
		$this->assertSame( 'Slažem se s pohranom podataka.', $properties['inputs'][1]['attr']['value'] );
	}

	/**
	 * The Forms screen lists the form with the subject its texts are translated under and the pages
	 * that show it, and the editor lists its texts, each named by its field.
	 */
	public function test_forms_and_their_texts() {
		$provider = $this->provider();
		$this->assertSame( 'WPForms', $provider->name() );

		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:wpforms/form-selector {"clientId":"x","formId":"12","theme":"default"} /-->',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[wpforms id="12" title="false"]',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:wpforms/form-selector {"formId":"120"} /--> [wpforms id="121"]',
			)
		);
		$forms = $provider->forms();
		$this->assertSame( 'wpforms-form:12', $forms[0]['key'] );
		$this->assertSame( 'Podaci o vozilu', $forms[0]['title'] );
		$this->assertSame( 2, $forms[0]['pages'], 'A block and a shortcode, not the forms 120 and 121' );

		$subject = $provider->subject( 'wpforms-form:12' );
		$this->assertSame( 'fields', $subject['kind'] );
		$this->assertNull( $provider->subject( 'wpforms-form:13' ) );

		$places = array();
		foreach ( $provider->listTexts( $subject ) as $text ) {
			$this->assertSame( 'wpforms:12', $text['o'] );
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Title', $places['Podaci o vozilu'] );
		$this->assertSame( 'Button', $places['Pošalji'] );
		$this->assertSame( 'Marka › Label', $places['Marka'] );
		$this->assertSame( 'Marka › Placeholder', $places['npr. Ford'] );
		$this->assertSame( 'Vaše ime › Last name placeholder', $places['Horvat'] );
		$this->assertSame( 'Gorivo › Choice 2', $places['Dizel'] );
	}
}
