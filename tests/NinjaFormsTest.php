<?php
/**
 * Tests of the Ninja Forms class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Forms\NinjaForms;

require_once __DIR__ . '/stubs/FakeNinjaForms.php';

/**
 * What the Ninja Forms class does: where its forms are, and the texts it hands to the translation
 * of a page at the two filters the plugin calls while it shows a form, the settings of the form and
 * its fields.
 */
class NinjaFormsTest extends TestCase {

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Makes the class with one form of every shape of text a form has, known to the registry as
	 * the plugin's class.
	 *
	 * @return FakeNinjaForms
	 */
	private function provider() {
		$provider = new FakeNinjaForms();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof NinjaForms ? $provider : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );

		$provider->forms   = array(
			4 => array(
				'settings' => array(
					'title'              => 'Kontaktirajte nas',
					'not_logged_in_msg'  => 'Prijavite se za slanje.',
					'unique_field_error' => 'Ovo je već poslano.',
					'wrapper_class'      => 'wide',
					'add_submit'         => '1',
				),
				'fields'   => array(
					array(
						'id'              => 1,
						'type'            => 'textbox',
						'key'             => 'ime',
						'label'           => 'Ime',
						'placeholder'     => 'Ivan',
						'help_text'       => 'Kako da vas zovemo?',
						'input_limit_msg' => 'znakova preostalo',
						'element_class'   => 'big',
						'label_pos'       => 'above',
					),
					array(
						'id'      => 2,
						'type'    => 'listselect',
						'key'     => 'usluga',
						'label'   => 'Usluga',
						'options' => array(
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
					array(
						'id'      => 3,
						'type'    => 'html',
						'key'     => 'uvod',
						'label'   => 'HTML',
						'default' => '<p>Odgovaramo u roku od dana.</p>',
					),
					array(
						'id'               => 5,
						'type'             => 'submit',
						'key'              => 'posalji',
						'label'            => 'Pošalji',
						'processing_label' => 'Šaljem',
					),
				),
			),
		);
		$provider->actions = array(
			4 => array(
				array(
					'id'       => 11,
					'settings' => array(
						'type'        => 'successmessage',
						'label'       => 'Poruka',
						'active'      => '1',
						'success_msg' => '<p>Hvala, {field:ime}!</p>',
					),
				),
				array(
					'id'       => 12,
					'settings' => array(
						'type'                => 'email',
						'label'               => 'Potvrda',
						'active'              => '1',
						'to'                  => '{field:email}',
						'email_subject'       => 'Primili smo upit',
						'email_message'       => '<p>Javit ćemo se.</p>{all_fields_table}',
						'email_message_plain' => 'Javit ćemo se.',
						'from_name'           => 'Apartmani',
					),
				),
				array(
					'id'       => 13,
					'settings' => array(
						'type'          => 'email',
						'label'         => 'Obavijest',
						'active'        => '1',
						'to'            => '{wp:admin_email}',
						'email_subject' => 'Novi upit od {field:ime}',
						'email_message' => '{all_fields_table}',
					),
				),
				array(
					'id'       => 14,
					'settings' => array(
						'type'          => 'email',
						'label'         => 'Kopija uredu',
						'active'        => '1',
						'to'            => 'ured@example.com, {field:email}',
						'email_subject' => 'Upit za ured',
						'email_message' => 'Stigao je upit.',
					),
				),
				array(
					'id'       => 15,
					'settings' => array(
						'type'   => 'save',
						'label'  => 'Spremi',
						'active' => '1',
					),
				),
			),
		);
		return $provider;
	}

	/**
	 * Saves German translations of texts of form 4, as the editor does: it stores the texts it lists
	 * and saves the translations typed beside them.
	 *
	 * @param FakeNinjaForms $provider Class.
	 * @param array          $german   Original => translation.
	 */
	private function translate_form( $provider, array $german ) {
		$this->set_languages();
		$subject = $provider->subject( 'ninjaforms-form:4' );
		swiftling_save_field_list( $subject );
		foreach ( $provider->listTexts( $subject ) as $text ) {
			if ( isset( $german[ $text['v'] ] ) ) {
				$this->translate( 'ninjaforms:4', $text['p'], $text['v'], 'de', $german[ $text['v'] ] );
			}
		}
	}

	/**
	 * The editor lists the message after sending and the email to the visitor, not the emails to
	 * the site's owner, nor the settings of an action that are not text.
	 */
	public function test_texts_of_the_actions() {
		$provider = $this->provider();
		$this->assertContains( 'nf_ajax_submit', $provider->sendingActions() );
		$places = array();
		foreach ( $provider->listTexts( $provider->subject( 'ninjaforms-form:4' ) ) as $text ) {
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Poruka › Message after sending', $places['<p>Hvala, {field:ime}!</p>'] );
		$this->assertSame( 'Potvrda › Subject of the email to the visitor', $places['Primili smo upit'] );
		$this->assertSame( 'Potvrda › Text of the email to the visitor', $places['<p>Javit ćemo se.</p>{all_fields_table}'] );
		$this->assertSame( 'Potvrda › Plain text of the email to the visitor', $places['Javit ćemo se.'] );
		foreach ( array( 'Novi upit od {field:ime}', '{all_fields_table}', 'Upit za ured', 'Stigao je upit.', 'Apartmani', '{field:email}' ) as $not_listed ) {
			$this->assertArrayNotHasKey( $not_listed, $places, $not_listed );
		}
	}

	/**
	 * A form sent from a page in another language: the plugin hands each action it runs to
	 * ninja_forms_run_action_settings; the message and the email to the visitor are translated
	 * there, before the plugin fills in its tags, and an email to the site's owner is sent in the
	 * original, until the next action or the end of the sending.
	 */
	public function test_actions_of_a_form_sent_in_another_language() {
		$provider = $this->provider();
		$this->translate_form(
			$provider,
			array(
				'<p>Hvala, {field:ime}!</p>' => '<p>Danke, {field:ime}!</p>',
				'Primili smo upit'           => 'Wir haben Ihre Anfrage',
				'Javit ćemo se.'             => 'Wir melden uns.',
			)
		);
		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();
		$actions = $provider->actions[4];

		$this->assertSame( '<p>Danke, {field:ime}!</p>', $provider->filterAction( $actions[0]['settings'], 4, 11 )['success_msg'] );
		$visitor = $provider->filterAction( $actions[1]['settings'], 4, 12 );
		$this->assertSame( 'Wir haben Ihre Anfrage', $visitor['email_subject'] );
		$this->assertSame( 'Wir melden uns.', $visitor['email_message_plain'] );
		$this->assertSame( '{field:email}', $visitor['to'] );

		$owner = $provider->filterAction( $actions[2]['settings'], 4, 13 );
		$this->assertSame( $actions[2]['settings'], $owner );
		$this->assertSame( '', swiftling_mode(), 'The owner\'s email is built in the original' );
		$this->assertSame( swiftling_site_locale(), get_locale() );

		$provider->filterAction( $actions[4]['settings'], 4, 15 );
		$this->assertSame( 'translate', swiftling_mode(), 'The next action is in the language again' );
		$this->assertSame( 'de_DE', get_locale() );

		$provider->filterAction( $actions[3]['settings'], 4, 14 );
		$this->assertSame( '', swiftling_mode(), 'An email to a fixed address beside the visitor is the owner\'s' );
		$provider->endActions();
		$this->assertSame( 'translate', swiftling_mode(), 'The end of the sending puts the language back' );
		$provider->endActions();
		$this->assertSame( 'translate', swiftling_mode() );

		$this->reset_state();
		$this->assertSame( $actions[0]['settings'], $provider->filterAction( $actions[0]['settings'], 4, 11 ), 'In the original nothing changes' );
	}

	/**
	 * A page check finds every text of the form under an owner of its own, not its look or what a
	 * program reads: the value of a choice, a CSS class, the key of a field, the place of a label.
	 */
	public function test_page_check_finds_the_texts_of_a_form() {
		$provider = $this->provider();
		$leaves   = $this->probe(
			static function () use ( $provider ) {
				$provider->filterSettings( $provider->forms[4]['settings'], 4 );
				$provider->filterFields( $provider->forms[4]['fields'], 4 );
			}
		);
		$found    = array();
		foreach ( $leaves as $leaf ) {
			$this->assertSame( 'ninjaforms:4', $leaf['o'] );
			$found[] = $leaf['v'];
		}
		foreach ( array( 'Kontaktirajte nas', 'Prijavite se za slanje.', 'Ovo je već poslano.', 'Ime', 'Ivan', 'Kako da vas zovemo?', 'znakova preostalo', 'Usluga', 'Smještaj', 'Pošalji', 'Šaljem' ) as $text ) {
			$this->assertContains( $text, $found );
		}
		$this->assertContains( '<p>Odgovaramo u roku od dana.</p>', $found, 'The content of an HTML field, as one paragraph' );
		foreach ( array( 'smjestaj', 'wide', 'big', 'ime', 'above', 'HTML' ) as $silent ) {
			$this->assertNotContains( $silent, $found, $silent . ' is not a text of the form' );
		}
	}

	/**
	 * A page in another language gets the translations in the settings and the fields the plugin
	 * goes on with, also in the title it prints; what is not a text stays as it was.
	 */
	public function test_translated_page_shows_the_translations() {
		$this->set_languages();
		$provider = $this->provider();
		$read     = static function () use ( $provider ) {
			$provider->filterSettings( $provider->forms[4]['settings'], 4 );
			$provider->filterFields( $provider->forms[4]['fields'], 4 );
		};
		$this->check( 'ninjaforms-form:4', $read );
		$german = array(
			'Kontaktirajte nas' => 'Kontaktieren Sie uns',
			'Ime'               => 'Name',
			'Smještaj'          => 'Unterkunft',
			'Pošalji'           => 'Senden',
		);
		foreach ( $this->probe( $read ) as $leaf ) {
			if ( isset( $german[ $leaf['v'] ] ) ) {
				$this->translate( 'ninjaforms:4', $leaf['p'], $leaf['v'], 'de', $german[ $leaf['v'] ] );
			}
		}

		$this->show_in( 'de' );
		$settings = $provider->filterSettings( $provider->forms[4]['settings'], 4 );
		$fields   = $provider->filterFields( $provider->forms[4]['fields'], 4 );
		$this->assertSame( 'Kontaktieren Sie uns', $settings['title'] );
		$this->assertSame( 'Kontaktieren Sie uns', $provider->filterTitle( 'Kontaktirajte nas', 4 ) );
		$this->assertSame( 'Name', $fields[0]['label'] );
		$this->assertSame( 'Ivan', $fields[0]['placeholder'], 'Not translated yet: the original' );
		$this->assertSame( 'Unterkunft', $fields[1]['options'][0]['label'] );
		$this->assertSame( 'smjestaj', $fields[1]['options'][0]['value'], 'What a visitor sends is not changed' );
		$this->assertSame( 'Senden', $fields[3]['label'] );
		$this->assertSame( 'big', $fields[0]['element_class'] );

		$this->show_in( 'fr' );
		$this->assertSame( 'Ime', $provider->filterFields( $provider->forms[4]['fields'], 4 )[0]['label'], 'No French translation yet' );
		$this->reset_state();
		$this->assertSame( 'Ime', $provider->filterFields( $provider->forms[4]['fields'], 4 )[0]['label'], 'The original language' );
	}

	/**
	 * The plugin numbers a field again when a page shows the same form twice, "1_1"; its texts stay
	 * the texts of field 1.
	 */
	public function test_second_copy_of_a_form_on_a_page() {
		$provider = $this->provider();
		$fields   = $provider->forms[4]['fields'];

		$fields[0]['id'] = '1_1';
		$leaves          = $this->probe(
			static function () use ( $provider, $fields ) {
				$provider->filterFields( $fields, 4 );
			}
		);
		$paths           = wp_list_pluck( $leaves, 'p' );
		$this->assertContains( 'form/texts/fields/field_1/label', $paths );
		$this->assertNotContains( 'form/texts/fields/field_1_1/label', $paths );
	}

	/**
	 * The Forms screen lists the form with the subject its texts are translated under and the pages
	 * that show it, and the editor lists its texts, each named by its field.
	 */
	public function test_forms_and_their_texts() {
		$provider = $this->provider();
		$this->assertSame( 'Ninja Forms', $provider->name() );

		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:ninja-forms/form {"formID":4,"formTitle":"Kontaktirajte nas"} /-->',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[ninja_form id=4]',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:ninja-forms/form {"formID":40} /--> [ninja_form id=41]',
			)
		);
		$forms = $provider->forms();
		$this->assertSame( 'ninjaforms-form:4', $forms[0]['key'] );
		$this->assertSame( 'Kontaktirajte nas', $forms[0]['title'] );
		$this->assertSame( 2, $forms[0]['pages'], 'A block and a shortcode, not the forms 40 and 41' );

		$subject = $provider->subject( 'ninjaforms-form:4' );
		$this->assertSame( 'fields', $subject['kind'] );
		$this->assertNull( $provider->subject( 'ninjaforms-form:9' ) );

		$places = array();
		foreach ( $provider->listTexts( $subject ) as $text ) {
			$this->assertSame( 'ninjaforms:4', $text['o'] );
			$places[ $text['v'] ] = $text['place'];
		}
		$this->assertSame( 'Title', $places['Kontaktirajte nas'] );
		$this->assertSame( 'Ime › Label', $places['Ime'] );
		$this->assertSame( 'Ime › Help text', $places['Kako da vas zovemo?'] );
		$this->assertSame( 'Usluga › Choice 2', $places['Prijevoz'] );
		$this->assertSame( 'Pošalji › Processing label', $places['Šaljem'] );
		$this->assertSame( 'Message for visitors who are not logged in', $places['Prijavite se za slanje.'] );
	}
}
