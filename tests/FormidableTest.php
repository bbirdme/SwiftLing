<?php
/**
 * Tests of the Formidable Forms class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Forms\Formidable;

require_once __DIR__ . '/stubs/FakeFormidable.php';

/**
 * What the Formidable Forms class does: where its forms are, and the texts it hands to the
 * translation of a page at the filters the plugin calls while it shows a form, without changing
 * what a visitor sends.
 */
class FormidableTest extends TestCase {

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
	 * @return FakeFormidable
	 */
	private function provider() {
		$provider = new FakeFormidable();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Formidable ? $provider : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );

		$provider->forms = array(
			5 => array(
				'form'   => (object) array(
					'id'          => 5,
					'name'        => 'Upit',
					'description' => 'Javite nam se.',
					'options'     => array(
						'submit_value' => 'Pošalji',
						'success_msg'  => 'Hvala, javit ćemo se.',
						'show_title'   => '1',
						'form_class'   => 'wide',
					),
				),
				'fields' => array(
					array(
						'id'          => 21,
						'form_id'     => 5,
						'type'        => 'text',
						'name'        => 'Ime',
						'description' => 'Ime i prezime.',
						'placeholder' => 'Ivan',
						'blank'       => 'Upišite ime.',
						'invalid'     => '[field_name] nije ispravno.',
						'label'       => 'hidden',
						'classes'     => 'frm_half',
						'custom_html' => '<div>[input]</div>',
					),
					array(
						'id'             => 22,
						'form_id'        => 5,
						'type'           => 'radio',
						'name'           => 'Gorivo',
						'separate_value' => 0,
						'options'        => array(
							array(
								'label' => 'Benzin',
								'value' => 'Benzin',
							),
							array(
								'label' => 'Dizel',
								'value' => 'Staro',
							),
						),
					),
					array(
						'id'             => 23,
						'form_id'        => 5,
						'type'           => 'select',
						'name'           => 'Mjenjač',
						'separate_value' => 1,
						'options'        => array(
							array(
								'label' => 'Ručni',
								'value' => 'manual',
							),
						),
					),
					array(
						'id'      => 24,
						'form_id' => 5,
						'type'    => 'checkbox',
						'name'    => 'Doručak',
						'options' => array( 'Da', 'Ne' ),
					),
					array(
						'id'          => 25,
						'form_id'     => 5,
						'type'        => 'html',
						'name'        => 'HTML',
						'description' => '<p>Odgovaramo brzo.</p>',
					),
				),
			),
		);
		// A form whose messages after sending moved to On Submit actions: the plugin shows those, and
		// the message left in the form's options is a copy it no longer reads.
		$provider->forms[6]   = array(
			'form'   => (object) array(
				'id'          => 6,
				'name'        => 'Prijava',
				'description' => '',
				'options'     => array(
					'submit_value'       => 'Prijavi se',
					'success_msg'        => 'Stara poruka.',
					'on_submit_migrated' => 1,
				),
			),
			'fields' => array(),
		);
		$provider->actions[6] = array(
			(object) array(
				'ID'           => 31,
				'post_excerpt' => 'on_submit',
				'post_title'   => 'Potvrda',
				'post_content' => array(
					'event'          => array( 'create' ),
					'success_action' => 'message',
					'success_msg'    => 'Primili smo prijavu.',
					'show_form'      => '',
				),
			),
			(object) array(
				'ID'           => 32,
				'post_excerpt' => 'on_submit',
				'post_title'   => 'Preusmjeravanje',
				'post_content' => array(
					'event'              => array( 'create' ),
					'success_action'     => 'redirect',
					'success_url'        => 'https://example.com/hvala',
					'redirect_delay'     => '1',
					'redirect_delay_msg' => 'Šaljemo vas dalje.',
				),
			),
			(object) array(
				'ID'           => 33,
				'post_excerpt' => 'on_submit',
				'post_title'   => 'Stranica',
				'post_content' => array(
					'event'           => array( 'create' ),
					'success_action'  => 'page',
					'success_page_id' => '7',
				),
			),
		);
		$provider->actions[5] = array(
			(object) array(
				'ID'           => 41,
				'post_excerpt' => 'email',
				'post_title'   => 'Potvrda posjetitelju',
				'post_content' => array(
					'email_to'      => '[25], [ime-key]',
					'cc'            => '[admin_email]',
					'email_subject' => 'Hvala na upitu',
					'email_message' => 'Javit ćemo se uskoro.',
				),
			),
			(object) array(
				'ID'           => 42,
				'post_excerpt' => 'email',
				'post_title'   => 'Obavijest',
				'post_content' => array(
					'email_to'      => '[admin_email]',
					'email_subject' => 'Novi upit',
					'email_message' => '[default-message]',
				),
			),
			(object) array(
				'ID'           => 43,
				'post_excerpt' => 'email',
				'post_title'   => 'Obavijest uredu',
				'post_content' => array(
					'email_to'      => 'ured@example.com, [25]',
					'email_subject' => 'Upit za ured',
					'email_message' => 'Stigao je upit.',
				),
			),
		);
		$provider->settings   = (object) array(
			'invalid_msg'      => 'Došlo je do greške.',
			'failed_msg'       => 'To ste već poslali.',
			'login_msg'        => 'Prijavite se.',
			'admin_permission' => 'Nemate dopuštenje.',
			'blank_msg'        => '[field_name] je obavezno.',
		);
		return $provider;
	}

	/**
	 * Saves German translations of texts of form 5, as the editor does: it stores the texts it lists
	 * and saves the translations typed beside them.
	 *
	 * @param FakeFormidable $provider Class.
	 * @param array          $german   Original => translation.
	 */
	private function translate_form( $provider, array $german ) {
		$this->set_languages();
		$subject = $provider->subject( 'formidable-form:5' );
		swiftling_save_field_list( $subject );
		foreach ( $provider->listTexts( $subject ) as $text ) {
			if ( isset( $german[ $text['v'] ] ) ) {
				$this->translate( 'formidable:5', $text['p'], $text['v'], 'de', $german[ $text['v'] ] );
			}
		}
	}

	/**
	 * Reads a form as a page that shows it does.
	 *
	 * @param FakeFormidable $provider Class.
	 * @return array{form: object, fields: array}
	 */
	private function shown( $provider ) {
		$form   = $provider->filterForm( clone $provider->forms[5]['form'] );
		$fields = array();
		foreach ( $provider->forms[5]['fields'] as $field ) {
			$fields[] = $provider->filterField( $field );
		}
		return compact( 'form', 'fields' );
	}

	/**
	 * A page check finds every text a visitor reads under an owner of its own, not what a program
	 * reads: the place of a label, a CSS class, the HTML around a field, a value of its own.
	 */
	public function test_page_check_finds_the_texts_of_a_form() {
		$provider = $this->provider();
		$leaves   = $this->probe(
			function () use ( $provider ) {
				$this->shown( $provider );
			}
		);
		$found    = array();
		foreach ( $leaves as $leaf ) {
			$this->assertSame( 'formidable:5', $leaf['o'] );
			$found[] = $leaf['v'];
		}
		foreach ( array( 'Upit', 'Javite nam se.', 'Pošalji', 'Hvala, javit ćemo se.', 'Ime', 'Ime i prezime.', 'Ivan', 'Upišite ime.', '[field_name] nije ispravno.', 'Gorivo', 'Benzin', 'Dizel', 'Ručni', 'Da', 'Ne', '<p>Odgovaramo brzo.</p>' ) as $text ) {
			$this->assertContains( $text, $found );
		}
		foreach ( array( 'hidden', 'frm_half', '<div>[input]</div>', 'manual', 'Staro', 'wide', 'HTML' ) as $silent ) {
			$this->assertNotContains( $silent, $found, $silent . ' is not a text of the form' );
		}
	}

	/**
	 * A page in another language shows the translations, and a choice keeps sending what it sends in
	 * the original: the plugin checks a sent value against the original choices.
	 */
	public function test_translated_page_shows_the_translations_and_sends_the_original() {
		$this->set_languages();
		$provider = $this->provider();
		$read     = function () use ( $provider ) {
			$this->shown( $provider );
		};
		$this->check( 'formidable-form:5', $read );
		$german = array(
			'Upit'                  => 'Anfrage',
			'Pošalji'               => 'Senden',
			'Hvala, javit ćemo se.' => 'Danke, wir melden uns.',
			'Ime'                   => 'Name',
			'Dizel'                 => 'Diesel',
			'Ručni'                 => 'Schaltgetriebe',
			'Da'                    => 'Ja',
		);
		foreach ( $this->probe( $read ) as $leaf ) {
			if ( isset( $german[ $leaf['v'] ] ) ) {
				$this->translate( 'formidable:5', $leaf['p'], $leaf['v'], 'de', $german[ $leaf['v'] ] );
			}
		}

		$this->show_in( 'de' );
		$shown = $this->shown( $provider );
		$this->assertSame( 'Anfrage', $shown['form']->name );
		$this->assertSame( 'Senden', $shown['form']->options['submit_value'] );
		$this->assertSame( 'Danke, wir melden uns.', $shown['form']->options['success_msg'] );
		$this->assertSame( 'Name', $shown['fields'][0]['name'] );
		$this->assertSame( 'Ivan', $shown['fields'][0]['placeholder'], 'Not translated yet: the original' );

		// A choice without a value of its own sends its label: it keeps sending the original one.
		$this->assertSame( 'Diesel', $shown['fields'][1]['options'][1]['label'] );
		$this->assertSame( 'Dizel', $shown['fields'][1]['options'][1]['value'], 'Not the stale value, the label it sent' );
		$this->assertSame( 1, $shown['fields'][1]['separate_value'] );
		$this->assertSame(
			array(
				'label' => 'Ja',
				'value' => 'Da',
			),
			$shown['fields'][3]['options'][0],
			'A choice kept as text alone'
		);
		// A choice with a value of its own keeps it.
		$this->assertSame( 'Schaltgetriebe', $shown['fields'][2]['options'][0]['label'] );
		$this->assertSame( 'manual', $shown['fields'][2]['options'][0]['value'] );

		$this->reset_state();
		$this->assertSame( $provider->forms[5]['fields'], $this->shown( $provider )['fields'], 'In the original language the fields are not touched' );
	}

	/**
	 * A form whose messages after sending are On Submit actions: the editor lists the messages of
	 * the actions, not the copy left in the form's options, and the page in another language, after
	 * sending, shows the translated message of the action the plugin hands over.
	 */
	public function test_message_of_an_on_submit_action() {
		$this->set_languages();
		$provider = $this->provider();

		$subject = $provider->subject( 'formidable-form:6' );
		$places  = array();
		foreach ( $provider->listTexts( $subject ) as $text ) {
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Potvrda › Message after sending', $places['Primili smo prijavu.'] );
		$this->assertSame( 'Preusmjeravanje › Message before going on', $places['Šaljemo vas dalje.'] );
		$this->assertArrayNotHasKey( 'Stara poruka.', $places, 'The plugin no longer shows the message in the options' );
		$this->assertArrayNotHasKey( 'https://example.com/hvala', $places );

		$sent = array(
			'form'  => $provider->forms[6]['form'],
			'event' => 'create',
		);
		$read = function () use ( $provider, $sent ) {
			$provider->filterActions( $provider->actions[6], $sent );
		};
		$this->check( 'formidable-form:6', $read );
		foreach ( $this->probe( $read ) as $leaf ) {
			$this->assertSame( 'formidable:6', $leaf['o'] );
			if ( 'Primili smo prijavu.' === $leaf['v'] ) {
				$this->translate( 'formidable:6', $leaf['p'], $leaf['v'], 'de', 'Wir haben Ihre Anmeldung.' );
			}
		}

		$this->show_in( 'de' );
		$shown = $provider->filterActions( $provider->actions[6], $sent );
		$this->assertSame( 'Wir haben Ihre Anmeldung.', $shown[0]->post_content['success_msg'] );
		$this->assertSame( 'message', $shown[0]->post_content['success_action'] );
		$this->assertSame( 'Šaljemo vas dalje.', $shown[1]->post_content['redirect_delay_msg'], 'Not translated yet: the original' );
		$this->assertSame( 'https://example.com/hvala', $shown[1]->post_content['success_url'] );
		$this->assertSame( 'Primili smo prijavu.', $provider->actions[6][0]->post_content['success_msg'], 'The plugin\'s own object, which it may keep in its cache, is not changed' );
	}

	/**
	 * A form sent from a page in another language is checked against fields whose error messages
	 * and names are translated, on copies: the plugin keeps its fields in a cache that also builds
	 * the email to the site's owner. What a choice may send stays the original.
	 */
	public function test_errors_found_on_sending_are_in_the_language() {
		$provider = $this->provider();
		$this->translate_form(
			$provider,
			array(
				'Ime'                         => 'Name',
				'Upišite ime.'                => 'Bitte geben Sie Ihren Namen ein.',
				'[field_name] nije ispravno.' => '[field_name] ist ungültig.',
				'Benzin'                      => 'Benzin DE',
			)
		);
		$fields = array(
			(object) array(
				'id'            => 21,
				'form_id'       => 5,
				'type'          => 'text',
				'name'          => 'Ime',
				'field_options' => array(
					'blank'   => 'Upišite ime.',
					'invalid' => '[field_name] nije ispravno.',
					'classes' => 'frm_half',
				),
			),
			(object) array(
				'id'            => 22,
				'form_id'       => 5,
				'type'          => 'radio',
				'name'          => 'Gorivo',
				'options'       => array( 'Benzin', 'Dizel' ),
				'field_options' => array(),
			),
		);

		$this->show_in( 'de' );
		$checked = $provider->filterValidated( $fields );
		$this->assertSame( 'Name', $checked[0]->name );
		$this->assertSame( 'Bitte geben Sie Ihren Namen ein.', $checked[0]->field_options['blank'] );
		$this->assertSame( '[field_name] ist ungültig.', $checked[0]->field_options['invalid'] );
		$this->assertSame( 'frm_half', $checked[0]->field_options['classes'] );
		$this->assertSame( array( 'Benzin', 'Dizel' ), $checked[1]->options, 'A sent choice is checked against the original ones' );
		$this->assertSame( 'Ime', $fields[0]->name, 'The plugin\'s own object is not changed' );
		$this->assertSame( 'Upišite ime.', $fields[0]->field_options['blank'] );

		$this->reset_state();
		$this->assertSame( $fields, $provider->filterValidated( $fields ), 'In the original nothing changes' );
	}

	/**
	 * A sent entry is listed, as in the email to the visitor ([default-message]), with the names of
	 * its fields translated, on a copy.
	 */
	public function test_entry_lists_the_translated_names() {
		$provider = $this->provider();
		$this->translate_form( $provider, array( 'Ime' => 'Name' ) );
		$field = (object) array(
			'id'            => 21,
			'form_id'       => 5,
			'type'          => 'text',
			'name'          => 'Ime',
			'field_options' => array( 'blank' => 'Upišite ime.' ),
		);

		$this->show_in( 'de' );
		$listed = $provider->filterValueField( $field );
		$this->assertSame( 'Name', $listed->name );
		$this->assertSame( 'Ime', $field->name, 'The plugin\'s own object is not changed' );

		$this->reset_state();
		$this->assertSame( $field, $provider->filterValueField( $field ), 'In the original, as for the owner\'s email, nothing changes' );
	}

	/**
	 * The plugin's own messages for a form, kept in its global settings, are texts of each form: the
	 * plugin hands them over with the form they are shown for.
	 */
	public function test_messages_of_the_plugins_settings() {
		$provider = $this->provider();
		$places   = array();
		foreach ( $provider->listTexts( $provider->subject( 'formidable-form:5' ) ) as $text ) {
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Error message when sending fails', $places['Došlo je do greške.'] );
		$this->assertSame( 'Message for an entry sent before', $places['To ste već poslali.'] );
		$this->assertSame( 'Message when signing in is needed', $places['Prijavite se.'] );
		$this->assertArrayNotHasKey( 'Nemate dopuštenje.', $places, 'Shown only in the admin' );

		$this->translate_form( $provider, array( 'Došlo je do greške.' => 'Es gab einen Fehler.' ) );
		$this->show_in( 'de' );
		$settings = (object) array( 'current_form' => 5 );
		$this->assertSame( 'Es gab einen Fehler.', $provider->filterSetting( 'Došlo je do greške.', 'invalid_msg', $settings ) );
		$this->assertSame( 'Nemate dopuštenje.', $provider->filterSetting( 'Nemate dopuštenje.', 'admin_permission', $settings ) );
		$this->assertSame( 'Došlo je do greške.', $provider->filterSetting( 'Došlo je do greške.', 'invalid_msg', (object) array( 'current_form' => 0 ) ), 'No form: not this form\'s text' );
	}

	/**
	 * An email to the visitor, every recipient taken from what the visitor sent, is in the visitor's
	 * language; an email to the site's owner, or to a fixed address beside the visitor, stays in the
	 * original, texts and locale, and the translation goes on after it.
	 */
	public function test_email_to_the_visitor_and_to_the_owner() {
		$provider = $this->provider();
		$places   = array();
		foreach ( $provider->listTexts( $provider->subject( 'formidable-form:5' ) ) as $text ) {
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Potvrda posjetitelju › Subject of the email to the visitor', $places['Hvala na upitu'] );
		$this->assertSame( 'Potvrda posjetitelju › Text of the email to the visitor', $places['Javit ćemo se uskoro.'] );
		foreach ( array( 'Novi upit', '[default-message]', 'Upit za ured', 'Stigao je upit.' ) as $owners ) {
			$this->assertArrayNotHasKey( $owners, $places, $owners . ' is the owner\'s' );
		}

		$this->translate_form(
			$provider,
			array(
				'Hvala na upitu'        => 'Danke für Ihre Anfrage',
				'Javit ćemo se uskoro.' => 'Wir melden uns bald.',
			)
		);
		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();
		$form = $provider->forms[5]['form'];

		$visitor = $provider->actions[5][0];
		$provider->startEmail( $visitor, null, $form );
		$this->assertSame( 'Danke für Ihre Anfrage', $visitor->post_content['email_subject'] );
		$this->assertSame( 'Wir melden uns bald.', $visitor->post_content['email_message'] );
		$this->assertSame( 'de_DE', get_locale() );
		$provider->endEmail();
		$this->assertSame( 'Hvala na upitu', $visitor->post_content['email_subject'], 'The plugin\'s action is put back' );

		foreach ( array( 1, 2 ) as $owners ) {
			$provider->startEmail( $provider->actions[5][ $owners ], null, $form );
			$this->assertSame( '', swiftling_mode() );
			$this->assertSame( swiftling_site_locale(), get_locale() );
			$provider->endEmail();
			$this->assertSame( 'translate', swiftling_mode() );
			$this->assertSame( 'de_DE', get_locale() );
		}
	}

	/**
	 * The Forms screen lists the form with the subject its texts are translated under and the pages
	 * that show it, and the editor lists its texts, each named by its field.
	 */
	public function test_forms_and_their_texts() {
		$provider = $this->provider();
		$this->assertSame( 'Formidable Forms', $provider->name() );

		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:formidable/simple-form {"formId":"5"} --><div>[formidable id="5"]</div><!-- /wp:formidable/simple-form -->',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[formidable id=5 title=true]',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[formidable id="50"] <!-- wp:formidable/simple-form {"formId":"51"} /-->',
			)
		);
		$forms = $provider->forms();
		$this->assertSame( 'formidable-form:5', $forms[0]['key'] );
		$this->assertSame( 2, $forms[0]['pages'], 'Two posts, not the forms 50 and 51' );

		$subject = $provider->subject( 'formidable-form:5' );
		$this->assertSame( 'fields', $subject['kind'] );
		$this->assertNull( $provider->subject( 'formidable-form:7' ) );

		$places = array();
		foreach ( $provider->listTexts( $subject ) as $text ) {
			$this->assertSame( 'formidable:5', $text['o'] );
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Title', $places['Upit'] );
		$this->assertSame( 'Button', $places['Pošalji'] );
		$this->assertSame( 'Message after sending', $places['Hvala, javit ćemo se.'] );
		$this->assertSame( 'Ime › Label', $places['Ime'] );
		$this->assertSame( 'Ime › Error message when empty', $places['Upišite ime.'] );
		$this->assertSame( 'Gorivo › Choice 2', $places['Dizel'] );
	}
}
