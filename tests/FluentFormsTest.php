<?php
/**
 * Tests of the Fluent Forms class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Forms\FluentForms;

require_once __DIR__ . '/stubs/FakeFluentForms.php';

/**
 * What the Fluent Forms class does: where its forms are, and the texts it hands to the translation
 * of a page at the filter the plugin calls while it shows a form, also a conversational one.
 */
class FluentFormsTest extends TestCase {

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Makes the class with one form of every shape a form of the plugin has: a field of several
	 * parts, a row of columns, choices, rules with their messages, HTML, terms and the button.
	 *
	 * @return FakeFluentForms
	 */
	private function provider() {
		$provider = new FakeFluentForms();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof FluentForms ? $provider : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );

		$provider->forms    = array(
			9 => array(
				'title'  => 'Rezervacija',
				'fields' => array(
					'fields'       => array(
						array(
							'element'    => 'input_name',
							'uniqElKey'  => 'el_1',
							'attributes' => array( 'name' => 'names' ),
							'settings'   => array( 'admin_field_label' => 'Ime i prezime' ),
							'fields'     => array(
								'first_name' => array(
									'element'    => 'input_text',
									'attributes' => array(
										'name'        => 'first_name',
										'placeholder' => 'Ivan',
									),
									'settings'   => array(
										'label'            => 'Ime',
										'validation_rules' => array(
											'required' => array(
												'value'   => true,
												'message' => 'Upišite ime',
											),
										),
									),
								),
								'last_name'  => array(
									'element'    => 'input_text',
									'attributes' => array( 'name' => 'last_name' ),
									'settings'   => array( 'label' => 'Prezime' ),
								),
							),
						),
						array(
							'element'   => 'container',
							'uniqElKey' => 'el_2',
							'settings'  => array( 'container_class' => 'two-cols' ),
							'columns'   => array(
								array(
									'fields' => array(
										array(
											'element'    => 'select',
											'uniqElKey'  => 'el_3',
											'attributes' => array(
												'name'  => 'usluga',
												'class' => 'big',
											),
											'settings'   => array(
												'label' => 'Usluga',
												'help_message' => 'Izaberite jednu.',
												'advanced_options' => array(
													array(
														'label' => 'Smještaj',
														'value' => 'smjestaj',
													),
													array(
														'label' => 'Prijevoz',
														'value' => 'prijevoz',
													),
												),
											),
										),
									),
								),
								array(
									'fields' => array(
										array(
											'element'    => 'input_email',
											'uniqElKey'  => 'el_4',
											'attributes' => array(
												'name' => 'email',
												'placeholder' => 'vi@primjer.hr',
											),
											'settings'   => array(
												'label' => 'E-pošta',
												'validation_rules' => array(
													'email' => array(
														'value'   => true,
														'message' => 'Neispravna adresa',
													),
												),
											),
										),
									),
								),
							),
						),
						array(
							'element'   => 'custom_html',
							'uniqElKey' => 'el_5',
							'settings'  => array( 'html_codes' => '<p>Odgovaramo brzo.</p>' ),
						),
						array(
							'element'    => 'terms_and_condition',
							'uniqElKey'  => 'el_6',
							'attributes' => array( 'name' => 'uvjeti' ),
							'settings'   => array( 'tnc_html' => 'Slažem se s uvjetima.' ),
						),
					),
					'submitButton' => array(
						'element'  => 'button',
						'settings' => array(
							'button_ui' => array(
								'type' => 'default',
								'text' => 'Pošalji',
							),
						),
					),
				),
			),
		);
		$provider->settings = array(
			9 => array(
				'confirmation' => array(
					'redirectTo'    => 'samePage',
					'messageToShow' => 'Hvala, javit ćemo se.',
				),
			),
		);
		$provider->feeds    = array(
			9 => array(
				array(
					'id'       => 21,
					'settings' => array(
						'name'    => 'Obavijest',
						'enabled' => true,
						'sendTo'  => array(
							'type'  => 'email',
							'email' => '{wp.admin_email}',
						),
						'subject' => 'Nova rezervacija',
						'message' => '{all_data}',
					),
				),
				array(
					'id'       => 22,
					'settings' => array(
						'name'     => 'Potvrda gostu',
						'enabled'  => true,
						'sendTo'   => array(
							'type'  => 'field',
							'field' => 'email',
						),
						'fromName' => 'Apartmani',
						'subject'  => 'Primili smo rezervaciju',
						'message'  => '<p>Hvala na upitu, {inputs.email}.</p>',
					),
				),
				array(
					'id'       => 23,
					'settings' => array(
						'name'    => 'Kopija',
						'enabled' => true,
						'sendTo'  => array(
							'type'  => 'email',
							'email' => 'ured@example.com, {inputs.email}',
						),
						'subject' => 'Kopija rezervacije',
						'message' => 'Stigla je rezervacija.',
					),
				),
			),
		);
		return $provider;
	}

	/**
	 * Saves German translations of texts of form 9, as the editor does: it stores the texts it lists
	 * and saves the translations typed beside them.
	 *
	 * @param FakeFluentForms $provider Class.
	 * @param array           $german   Original => translation.
	 */
	private function translate_form( $provider, array $german ) {
		$this->set_languages();
		$subject = $provider->subject( 'fluentforms-form:9' );
		swiftling_save_field_list( $subject );
		foreach ( $provider->listTexts( $subject ) as $text ) {
			if ( isset( $german[ $text['v'] ] ) ) {
				$this->translate( 'fluentforms:9', $text['p'], $text['v'], 'de', $german[ $text['v'] ] );
			}
		}
	}

	/**
	 * The editor lists the message after sending and the email to the visitor, not the emails to the
	 * site's owner.
	 */
	public function test_texts_of_the_answer() {
		$provider = $this->provider();
		$this->assertContains( 'fluentform_submit', $provider->sendingActions() );
		$places = array();
		foreach ( $provider->listTexts( $provider->subject( 'fluentforms-form:9' ) ) as $text ) {
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Message after sending', $places['Hvala, javit ćemo se.'] );
		$this->assertSame( 'Potvrda gostu › Subject of the email to the visitor', $places['Primili smo rezervaciju'] );
		$this->assertSame( 'Potvrda gostu › Text of the email to the visitor', $places['<p>Hvala na upitu, {inputs.email}.</p>'] );
		foreach ( array( 'Nova rezervacija', '{all_data}', 'Kopija rezervacije', 'Stigla je rezervacija.', 'Apartmani' ) as $not_listed ) {
			$this->assertArrayNotHasKey( $not_listed, $places, $not_listed );
		}
	}

	/**
	 * A form sent from a page in another language: its message, the error messages of its fields
	 * and the email to the visitor are in the language; the plugin sends its emails at once, not in
	 * a request of its own that knows no language; the email to the site's owner is built and sent
	 * in the original.
	 */
	public function test_form_sent_in_another_language() {
		$provider = $this->provider();
		$this->translate_form(
			$provider,
			array(
				'Hvala, javit ćemo se.'                  => 'Danke, wir melden uns.',
				'Upišite ime'                            => 'Bitte Vornamen eingeben',
				'Neispravna adresa'                      => 'Ungültige Adresse',
				'Primili smo rezervaciju'                => 'Wir haben Ihre Buchung',
				'<p>Hvala na upitu, {inputs.email}.</p>' => '<p>Danke für Ihre Anfrage, {inputs.email}.</p>',
			)
		);
		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();
		$form = (object) array( 'id' => 9 );
		$data = array( 'email' => 'ivan@example.com' );

		$confirmation = $provider->filterConfirmation( $provider->settings[9]['confirmation'], $data, $form );
		$this->assertSame( 'Danke, wir melden uns.', $confirmation['messageToShow'] );
		$this->assertSame( 'samePage', $confirmation['redirectTo'] );

		$validations = $provider->filterValidations(
			array(
				array(
					'names[first_name]' => 'required',
					'email'             => 'email',
				),
				array(
					'names[first_name].required' => 'Upišite ime',
					'email.email'                => 'Neispravna adresa',
					'usluga.required'            => 'Odaberite uslugu',
				),
			),
			$form,
			$data
		);
		$this->assertSame( 'required', $validations[0]['names[first_name]'] );
		$this->assertSame( 'Bitte Vornamen eingeben', $validations[1]['names[first_name].required'] );
		$this->assertSame( 'Ungültige Adresse', $validations[1]['email.email'] );
		$this->assertSame( 'Odaberite uslugu', $validations[1]['usluga.required'], 'Not a text of the form: as it is' );

		$this->assertFalse( $provider->sendsLater( true, 9 ) );

		$entry = (object) array( 'id' => 5 );
		$provider->startNotification( $provider->feeds[9][1], $data, $entry, $form );
		$this->assertSame( 'Wir haben Ihre Buchung', $provider->filterEmailSubject( 'Primili smo rezervaciju', array(), $data, $form ) );
		$this->assertSame( '<p>Danke für Ihre Anfrage, ivan@example.com.</p>', $provider->filterEmailBody( '<p>Hvala na upitu, ivan@example.com.</p>', 5, $data, $form ) );
		$provider->endNotification();
		$this->assertSame( 'x', $provider->filterEmailBody( 'x', 5, $data, $form ), 'Outside an email, as the message after sending, as it is' );

		foreach ( array( 0, 2 ) as $owners ) {
			$provider->startNotification( $provider->feeds[9][ $owners ], $data, $entry, $form );
			$this->assertSame( '', swiftling_mode(), 'The owner\'s email is built in the original' );
			$this->assertSame( 'Nova', substr( $provider->filterEmailSubject( 'Nova', array(), $data, $form ), 0, 4 ) );
			$provider->endNotification();
			$this->assertSame( 'translate', swiftling_mode() );
		}

		$this->reset_state();
		$this->assertTrue( $provider->sendsLater( true, 9 ), 'In the original the plugin decides' );
		$this->assertSame( $provider->settings[9]['confirmation'], $provider->filterConfirmation( $provider->settings[9]['confirmation'], $data, $form ) );
	}

	/**
	 * A page check finds every text of the form under an owner of its own, in a field of several
	 * parts and in columns too, not what a program reads: the value of a choice, the name of a
	 * field, a CSS class, the name of a field in the admin.
	 */
	public function test_page_check_finds_the_texts_of_a_form() {
		$provider = $this->provider();
		$leaves   = $this->probe(
			static function () use ( $provider ) {
				$provider->shown( 9 );
			}
		);
		$found    = array();
		foreach ( $leaves as $leaf ) {
			$this->assertSame( 'fluentforms:9', $leaf['o'] );
			$found[] = $leaf['v'];
		}
		foreach ( array( 'Ime', 'Ivan', 'Upišite ime', 'Prezime', 'Usluga', 'Izaberite jednu.', 'Smještaj', 'Prijevoz', 'E-pošta', 'vi@primjer.hr', 'Neispravna adresa', '<p>Odgovaramo brzo.</p>', 'Slažem se s uvjetima.', 'Pošalji' ) as $text ) {
			$this->assertContains( $text, $found );
		}
		foreach ( array( 'smjestaj', 'names', 'usluga', 'big', 'two-cols', 'Ime i prezime', 'default' ) as $silent ) {
			$this->assertNotContains( $silent, $found, $silent . ' is not a text of the form' );
		}
	}

	/**
	 * A page in another language gets the translations in the form the plugin goes on with; what is
	 * not a text stays as it was.
	 */
	public function test_translated_page_shows_the_translations() {
		$this->set_languages();
		$provider = $this->provider();
		$read     = static function () use ( $provider ) {
			$provider->shown( 9 );
		};
		$this->check( 'fluentforms-form:9', $read );
		$german = array(
			'Ime'      => 'Vorname',
			'Smještaj' => 'Unterkunft',
			'E-pošta'  => 'E-Mail',
			'Pošalji'  => 'Senden',
			'Prezime'  => 'Nachname',
		);
		foreach ( $this->probe( $read ) as $leaf ) {
			if ( isset( $german[ $leaf['v'] ] ) ) {
				$this->translate( 'fluentforms:9', $leaf['p'], $leaf['v'], 'de', $german[ $leaf['v'] ] );
			}
		}

		$this->show_in( 'de' );
		$form   = $provider->shown( 9 );
		$fields = $form->fields['fields'];
		$this->assertSame( 'Vorname', $fields[0]['fields']['first_name']['settings']['label'] );
		$this->assertSame( 'Nachname', $fields[0]['fields']['last_name']['settings']['label'] );
		$this->assertSame( 'Ivan', $fields[0]['fields']['first_name']['attributes']['placeholder'], 'Not translated yet: the original' );
		$this->assertSame( 'Unterkunft', $fields[1]['columns'][0]['fields'][0]['settings']['advanced_options'][0]['label'] );
		$this->assertSame( 'smjestaj', $fields[1]['columns'][0]['fields'][0]['settings']['advanced_options'][0]['value'], 'What a visitor sends is not changed' );
		$this->assertSame( 'E-Mail', $fields[1]['columns'][1]['fields'][0]['settings']['label'] );
		$this->assertSame( 'Senden', $form->fields['submitButton']['settings']['button_ui']['text'] );
		$this->assertSame( 'big', $fields[1]['columns'][0]['fields'][0]['attributes']['class'] );

		$this->show_in( 'fr' );
		$this->assertSame( 'Pošalji', $provider->shown( 9 )->fields['submitButton']['settings']['button_ui']['text'], 'No French translation yet' );
		$this->reset_state();
		$this->assertSame( 'Pošalji', $provider->shown( 9 )->fields['submitButton']['settings']['button_ui']['text'], 'The original language' );
	}

	/**
	 * The Forms screen lists the form with the subject its texts are translated under and the pages
	 * that show it, and the editor lists its texts, each named by its field.
	 */
	public function test_forms_and_their_texts() {
		$provider = $this->provider();
		$this->assertSame( 'Fluent Forms', $provider->name() );

		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:fluentfom/guten-block {"formId":"9","isConversationalForm":true} /-->',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[fluentform id="9"]',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:fluentfom/guten-block {"formId":"90"} /--> [fluentform id="91"]',
			)
		);
		$forms = $provider->forms();
		$this->assertSame( 'fluentforms-form:9', $forms[0]['key'] );
		$this->assertSame( 'Rezervacija', $forms[0]['title'] );
		$this->assertSame( 2, $forms[0]['pages'], 'A block and a shortcode, not the forms 90 and 91' );

		$subject = $provider->subject( 'fluentforms-form:9' );
		$this->assertSame( 'fields', $subject['kind'] );
		$this->assertNull( $provider->subject( 'fluentforms-form:8' ) );

		$places = array();
		foreach ( $provider->listTexts( $subject ) as $text ) {
			$this->assertSame( 'fluentforms:9', $text['o'] );
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Ime › Label', $places['Ime'] );
		$this->assertSame( 'Ime › Error message: required', $places['Upišite ime'] );
		$this->assertSame( 'Usluga › Help text', $places['Izaberite jednu.'] );
		$this->assertSame( 'Usluga › Choice 2', $places['Prijevoz'] );
		$this->assertSame( 'E-pošta › Placeholder', $places['vi@primjer.hr'] );
		$this->assertSame( 'Button', $places['Pošalji'] );
	}
}
