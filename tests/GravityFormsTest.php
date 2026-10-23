<?php
/**
 * Tests of the Gravity Forms provider.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Forms\GravityForms;

require_once __DIR__ . '/stubs/FakeGravityField.php';
require_once __DIR__ . '/stubs/FakeGravityForms.php';

/**
 * What the Gravity Forms provider does: where its forms are, and the texts it hands to the
 * translation of a page, and takes back.
 */
class GravityFormsTest extends TestCase {

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Makes a provider with one form of every shape of text a form has.
	 *
	 * @return FakeGravityForms
	 */
	private function provider() {
		$provider = new FakeGravityForms();
		// The registry knows the fake as the plugin's class, as it knows the real one on a site with the plugin.
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof GravityForms ? $provider : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$provider->forms = array(
			7 => array(
				'id'            => 7,
				'title'         => 'Upit za ponudu',
				'description'   => 'Javit ćemo se brzo.',
				'button'        => array(
					'type' => 'text',
					'text' => 'Pošalji upit',
				),
				'fields'        => array(
					new FakeGravityField(
						array(
							'id'          => 1,
							'type'        => 'text',
							'label'       => 'Vaše ime',
							'description' => 'Ime i prezime.',
							'placeholder' => 'Ivan Horvat',
							'adminLabel'  => 'Ime',
							'cssClass'    => 'wide',
						)
					),
					new FakeGravityField(
						array(
							'id'      => 2,
							'type'    => 'select',
							'label'   => 'Usluga',
							'choices' => array(
								array(
									'text'  => 'Smještaj',
									'value' => 'smjestaj',
								),
								array(
									'text'  => 'Prijevoz',
									'value' => 'prijevoz',
								),
							),
						)
					),
					new FakeGravityField(
						array(
							'id'     => 3,
							'type'   => 'name',
							'label'  => 'Ime',
							'inputs' => array(
								array(
									'id'    => '3.3',
									'label' => 'Ime',
									'name'  => '',
								),
								array(
									'id'    => '3.6',
									'label' => 'Prezime',
									'name'  => '',
								),
							),
						)
					),
				),
				'confirmations' => array(
					'c1' => array(
						'id'      => 'c1',
						'name'    => 'Zadana potvrda',
						'type'    => 'message',
						'message' => 'Hvala, primili smo upit.',
					),
				),
				'notifications' => array(
					'n1' => array(
						'id'      => 'n1',
						'name'    => 'Obavijest administratoru',
						'toType'  => 'email',
						'to'      => '{admin_email}',
						'subject' => 'Novi upit',
						'message' => '{all_fields}',
					),
					'n2' => array(
						'id'      => 'n2',
						'name'    => 'Potvrda gostu',
						'toType'  => 'field',
						'to'      => '4',
						'subject' => 'Primili smo vaš upit',
						'message' => 'Javit ćemo se uskoro.',
					),
					'n3' => array(
						'id'      => 'n3',
						'name'    => 'Kopija',
						'toType'  => 'email',
						'to'      => 'ured@example.com, {Email:4}',
						'subject' => 'Kopija upita',
						'message' => 'Stigao je upit.',
					),
				),
			),
		);
		return $provider;
	}

	/**
	 * Gives every text of the form under an owner of its own, not its look or what a program reads:
	 * the value of a choice, the CSS class, the name of a field in the admin and an address of mail
	 * stay out.
	 */
	public function test_page_check_finds_the_texts_of_a_form() {
		$provider = $this->provider();
		$leaves   = $this->probe(
			static function () use ( $provider ) {
				$provider->fresh( 7 );
			}
		);

		$found = array();
		foreach ( $leaves as $leaf ) {
			$this->assertSame( 'gravityforms:7', $leaf['o'] );
			$found[ $leaf['p'] ] = $leaf['v'];
		}
		$this->assertContains( 'Upit za ponudu', $found );
		$this->assertContains( 'Vaše ime', $found );
		$this->assertContains( 'Ime', $found, 'A label of one word is a text' );
		$this->assertContains( 'Smještaj', $found );
		$this->assertContains( 'Prezime', $found );
		$this->assertContains( 'Pošalji upit', $found );
		$this->assertContains( 'Hvala, primili smo upit.', $found );
		$this->assertContains( 'Primili smo vaš upit', $found, 'The email to the visitor' );
		$this->assertContains( 'Javit ćemo se uskoro.', $found );
		foreach ( array( 'smjestaj', 'prijevoz', 'wide', '{admin_email}', 'Zadana potvrda', 'Obavijest administratoru', 'Novi upit', '{all_fields}', 'Kopija upita', 'Stigao je upit.' ) as $silent ) {
			$this->assertNotContains( $silent, $found, $silent . ' is not a text of the form' );
		}
	}

	/**
	 * A page in another language gets the translations, in the form the plugin goes on with; what
	 * is not a text stays as it was.
	 */
	public function test_translated_page_shows_the_translations() {
		$this->set_languages();
		$provider = $this->provider();
		$this->check(
			'gravityforms-form:7',
			static function () use ( $provider ) {
				$provider->fresh( 7 );
			}
		);
		$leaves = $this->probe(
			static function () use ( $provider ) {
				$provider->fresh( 7 );
			}
		);
		$german = array(
			'Vaše ime'             => 'Ihr Name',
			'Smještaj'             => 'Unterkunft',
			'Prezime'              => 'Nachname',
			'Pošalji upit'         => 'Anfrage senden',
			'Upit za ponudu'       => 'Angebotsanfrage',
			'Primili smo vaš upit' => 'Wir haben Ihre Anfrage',
		);
		foreach ( $leaves as $leaf ) {
			if ( isset( $german[ $leaf['v'] ] ) ) {
				$this->translate( 'gravityforms:7', $leaf['p'], $leaf['v'], 'de', $german[ $leaf['v'] ] );
			}
		}

		$this->show_in( 'de' );
		$form = $provider->fresh( 7 );
		$this->assertSame( 'Angebotsanfrage', $form['title'] );
		$this->assertSame( 'Anfrage senden', $form['button']['text'] );
		$this->assertSame( 'Ihr Name', $form['fields'][0]['label'] );
		$this->assertSame( 'Ivan Horvat', $form['fields'][0]['placeholder'], 'Not translated yet: the original' );
		$this->assertInstanceOf( FakeGravityField::class, $form['fields'][0], 'A field stays the object the plugin made' );
		$this->assertSame( 'Unterkunft', $form['fields'][1]['choices'][0]['text'] );
		$this->assertSame( 'smjestaj', $form['fields'][1]['choices'][0]['value'], 'What a visitor sends is not changed' );
		$this->assertSame( 'Nachname', $form['fields'][2]['inputs'][1]['label'] );
		$this->assertSame( 'Wir haben Ihre Anfrage', $form['notifications']['n2']['subject'] );
		$this->assertSame( '4', $form['notifications']['n2']['to'] );
		$this->assertSame( 'Novi upit', $form['notifications']['n1']['subject'], 'The email to the site\'s owner stays in the original' );
		$this->assertSame( 'wide', $form['fields'][0]['cssClass'] );

		$this->show_in( 'fr' );
		$this->assertSame( 'Upit za ponudu', $provider->fresh( 7 )['title'], 'No French translation yet' );
		$this->reset_state();
		$this->assertSame( 'Upit za ponudu', $provider->fresh( 7 )['title'], 'The original language' );
	}

	/**
	 * A form sent from a page in another language: the plugin hands each email to
	 * gform_notification before it builds it; one to the site's owner is built and sent in the
	 * original, until it is sent (gform_after_email), the next email, the confirmation or the end of
	 * the sending.
	 */
	public function test_email_to_the_owner_in_the_original() {
		$provider = $this->provider();
		$this->assertContains( 'gform_submit_form', $provider->sendingActions() );
		$this->set_languages();
		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();
		$form = $provider->forms[7];

		$owner = $form['notifications']['n1'];
		$this->assertSame( $owner, $provider->startNotification( $owner, $form, array() ) );
		$this->assertSame( '', swiftling_mode(), 'The owner\'s email is built in the original' );
		$this->assertSame( swiftling_site_locale(), get_locale() );
		$provider->endEmail();
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'de_DE', get_locale() );

		$provider->startNotification( $form['notifications']['n3'], $form, array() );
		$this->assertSame( '', swiftling_mode(), 'A fixed address beside the visitor\'s is the owner\'s' );
		$provider->startNotification( $form['notifications']['n2'], $form, array() );
		$this->assertSame( 'translate', swiftling_mode(), 'The next email, the visitor\'s, is in the language' );
		$provider->startNotification( $owner, $form, array() );
		$provider->endEmail();
		$provider->endEmail();
		$this->assertSame( 'translate', swiftling_mode(), 'Each pause is ended once' );

		$this->assertFalse( $provider->sendsLater( true ), 'Sent at once, not in a request of the plugin\'s own that knows no language' );
		$this->reset_state();
		$this->assertTrue( $provider->sendsLater( true ), 'In the original the plugin decides' );
	}

	/**
	 * The plugin keeps the form it read, translated, for the rest of the request and builds every
	 * email from it, the {all_fields} of the owner's too: while the owner's email is built, its
	 * fields, objects the plugin shares, hold their original texts again, and the form's title in
	 * the email is the original; afterwards the translations are back.
	 */
	public function test_owner_email_lists_the_original_fields() {
		$provider = $this->provider();
		$provider->forms[7]['notifications']['n1']['subject'] = 'Novi upit: {form_title}';
		$this->set_languages();
		$this->check(
			'gravityforms-form:7',
			static function () use ( $provider ) {
				$provider->fresh( 7 );
			}
		);
		$german = array(
			'Vaše ime'       => 'Ihr Name',
			'Smještaj'       => 'Unterkunft',
			'Upit za ponudu' => 'Angebotsanfrage',
		);
		foreach ( $this->probe(
			static function () use ( $provider ) {
				$provider->fresh( 7 );
			}
		) as $leaf ) {
			if ( isset( $german[ $leaf['v'] ] ) ) {
				$this->translate( 'gravityforms:7', $leaf['p'], $leaf['v'], 'de', $german[ $leaf['v'] ] );
			}
		}
		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();
		$form = $provider->fresh( 7 );
		$this->assertSame( 'Ihr Name', $form['fields'][0]['label'] );

		$owner = $provider->startNotification( $form['notifications']['n1'], $form, array() );
		$this->assertSame( 'Vaše ime', $form['fields'][0]['label'], 'The owner\'s email lists the original label' );
		$this->assertSame( 'Smještaj', $form['fields'][1]['choices'][0]['text'] );
		$this->assertSame( 'Novi upit: Upit za ponudu', $owner['subject'], 'The form\'s original title' );
		$provider->endEmail();
		$this->assertSame( 'Ihr Name', $form['fields'][0]['label'], 'The translation is back for the rest of the request' );
		$this->assertSame( 'Unterkunft', $form['fields'][1]['choices'][0]['text'] );

		$visitor = $provider->startNotification( $form['notifications']['n2'], $form, array() );
		$this->assertSame( 'Ihr Name', $form['fields'][0]['label'], 'The visitor\'s email lists the translations' );
		$this->assertSame( $form['notifications']['n2'], $visitor );
		$provider->endEmail();
		$this->reset_state();
		$this->assertTrue( $provider->sendsLater( true ), 'In the original the plugin decides' );
	}

	/**
	 * The Forms screen lists a form with the subject its texts are translated under, and the editor
	 * lists its texts with the owner and place a page finds them under, each named by the field.
	 */
	public function test_forms_and_their_texts() {
		$provider = $this->provider();
		$this->assertSame( 'Gravity Forms', $provider->name() );

		$forms = $provider->forms();
		$this->assertCount( 1, $forms );
		$this->assertSame( 'gravityforms-form:7', $forms[0]['key'] );
		$this->assertSame( 'Upit za ponudu', $forms[0]['title'] );
		$this->assertSame( 0, $forms[0]['pages'] );

		$page = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:gravityforms/form {"inputPrimaryColor":"#204ce5","formId":"7"} /-->',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[gravityform id="7" title="false"]',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[gravityform id="70"]',
			)
		);
		$this->assertGreaterThan( 0, $page );
		$this->assertSame( 2, $provider->forms()[0]['pages'], 'A block and a shortcode, not the form 70' );

		$subject = $provider->subject( 'gravityforms-form:7' );
		$this->assertSame( 'fields', $subject['kind'] );
		$this->assertSame( 'Upit za ponudu', $subject['title'] );
		$this->assertNull( $provider->subject( 'gravityforms-form:8' ) );
		$this->assertNull( $provider->subject( 'cf7-form:7' ) );

		$texts  = $provider->listTexts( $subject );
		$places = array();
		foreach ( $texts as $text ) {
			$this->assertSame( 'gravityforms:7', $text['o'] );
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Vaše ime › Label', $places['Vaše ime'] );
		$this->assertSame( 'Vaše ime › Description', $places['Ime i prezime.'] );
		$this->assertSame( 'Usluga › Choice 2', $places['Prijevoz'] );
		$this->assertSame( 'Ime › Sub-label 2', $places['Prezime'] );
		$this->assertSame( 'Button', $places['Pošalji upit'] );
		$this->assertSame( 'Confirmation: Zadana potvrda', $places['Hvala, primili smo upit.'] );
		$this->assertSame( 'Potvrda gostu › Subject of the email to the visitor', $places['Primili smo vaš upit'] );
		$this->assertSame( 'Potvrda gostu › Text of the email to the visitor', $places['Javit ćemo se uskoro.'] );
		$this->assertArrayNotHasKey( 'Novi upit', $places, 'The email to the site\'s owner is not translated' );

		$this->assertTrue( $provider->fieldHoldsText( 'gravityforms:7', 'form/texts/fields/field_3/label' ) );
		$this->assertNull( $provider->fieldHoldsText( 'post:7', 'form/texts/title' ) );
	}

	/**
	 * A person translates a form with the right to edit forms, which is not the right to edit
	 * posts or the theme.
	 */
	public function test_who_may_translate_a_form() {
		$provider = $this->provider();
		$this->become( 'editor' );
		$this->assertFalse( $provider->canTranslate( 'gravityforms:7' ), 'An editor has no right to forms' );
		$this->assertNull( $provider->canTranslate( 'post:7' ), 'Not an object of this plugin' );
		$this->become( 'administrator' );
		$this->assertTrue( $provider->canTranslate( 'gravityforms:7' ) );
	}
}
