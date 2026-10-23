<?php
/**
 * Tests of the form providers and the Forms screen.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use SwiftLing\Builders\Registry;
use SwiftLing\Forms\ContactForm7;
use SwiftLing\Forms\Forminator;
use SwiftLing\Forms\Provider;
use WP_Post;

/**
 * What the providers of the form plugins say, and the three states of the Forms screen.
 *
 * Neither plugin is in the test environment. A provider knows its plugin by what its files declare
 * and by the post type its forms are, so a test registers that post type as the plugin does and
 * fills posts of it with data of the same shape; nothing of the plugins themselves is stubbed.
 */
class FormsTest extends TestCase {

	/**
	 * Forgets which builders are active, as the start of a request works it out again.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Registers the post type a plugin keeps its forms in, so its provider is active, and lets the
	 * registry work out again which builders are active. WordPress's own tear down unregisters the
	 * post type after the test.
	 *
	 * @param Provider $provider Provider.
	 */
	private function activate( Provider $provider ) {
		register_post_type(
			$provider->postType(),
			array(
				'public'  => false,
				'show_ui' => false,
			)
		);
		self::reset_static( Registry::class, 'active' );
		$this->assertTrue( $provider->isActive(), $provider->name() . ' is not active' );
	}

	/**
	 * Makes a form of Contact Form 7, with the meta the plugin keeps its texts in.
	 *
	 * @return WP_Post
	 */
	private function cf7_form() {
		$form = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'wpcf7_contact_form',
				'post_title' => 'Kontakt obrazac',
			)
		);
		add_post_meta( $form->ID, '_form', "<label> Vaše ime\n    [text* vase-ime] </label>\n[submit \"Pošalji\"]" );
		add_post_meta(
			$form->ID,
			'_messages',
			array(
				'mail_sent_ok'     => 'Hvala na poruci. Poslana je.',
				'invalid_required' => 'Molimo ispunite ovo polje.',
			)
		);
		add_post_meta(
			$form->ID,
			'_mail',
			array(
				'subject'            => 'Nova poruka sa stranice',
				'body'               => 'Poruka: [vasa-poruka]',
				'recipient'          => 'info@example.org',
				'sender'             => 'Stranica <wordpress@example.org>',
				'additional_headers' => 'Reply-To: [vas-email]',
			)
		);
		add_post_meta(
			$form->ID,
			'_mail_2',
			array(
				'active'    => true,
				'subject'   => 'Primili smo vašu poruku',
				'body'      => 'Javit ćemo se uskoro.',
				'recipient' => '[vas-email]',
				'sender'    => 'Stranica <wordpress@example.org>',
			)
		);
		return $form;
	}

	/**
	 * Makes a form of Forminator, with the texts and the looks it keeps in one meta key.
	 *
	 * @return WP_Post
	 */
	private function forminator_form() {
		$form = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'forminator_forms',
				'post_title' => 'Prijava',
			)
		);
		add_post_meta(
			$form->ID,
			'forminator_form_meta',
			array(
				'fields'        => array(
					array(
						'id'                => 'name-1',
						'type'              => 'name',
						'field_label'       => 'Vaše ime',
						'placeholder'       => 'Unesite ime',
						'label-font-family' => 'Roboto',
						'options'           => array(
							array(
								'label' => 'Gospodin',
								'value' => 'mr',
							),
						),
					),
				),
				'settings'      => array(
					'formName'           => 'Prijava na novosti',
					'thankyou-message'   => 'Hvala na prijavi.',
					'submitData'         => array( 'custom-submit-text' => 'Prijavi me' ),
					'form-font-family'   => 'Open Sans',
					'template_name'      => 'Newsletter',
					'themeName'          => 'Default',
					'backgroundPosition' => 'center center',
				),
				'behaviors'     => array(
					array( 'thankyou-message' => 'Hvala, javit ćemo se.' ),
				),
				'notifications' => array(
					array(
						'label'            => 'Obavijest uredniku',
						'email-recipients' => 'default',
						'recipients'       => 'urednik@example.org',
						'email-subject'    => 'Nova prijava',
						'email-editor'     => '{all_fields}',
					),
					array(
						'label'            => 'Potvrda prijave',
						'email-recipients' => 'default',
						'recipients'       => '{email-1}',
						'email-subject'    => 'Hvala na prijavi',
						'email-editor'     => 'Javit ćemo se.',
					),
				),
			)
		);
		return $form;
	}

	/**
	 * Returns the paths of the texts a provider lists for a form.
	 *
	 * @param string $key Subject key of the form.
	 * @return array Path => text.
	 */
	private function texts( $key ) {
		$subject = swiftling_subject( $key );
		$this->assertNotNull( $subject, 'No subject ' . $key );
		$this->assertSame( 'fields', $subject['kind'] );

		$texts = array();
		foreach ( Registry::listTexts( $subject ) as $text ) {
			$texts[ $text['p'] ] = $text;
		}
		return $texts;
	}

	/**
	 * Both form plugins are known, and without them nothing of theirs is offered.
	 */
	public function test_providers_without_the_plugins() {
		$this->assertSame( array( 'cf7', 'forminator', 'gravityforms', 'ninjaforms', 'fluentforms', 'wpforms', 'formidable' ), array_keys( Registry::forms() ) );
		foreach ( Registry::forms() as $provider ) {
			$this->assertFalse( $provider->isActive(), $provider->name() );
			$this->assertSame( array(), $provider->forms() );
			$this->assertFalse( $provider->isUsed( 1 ) );
		}
		$this->assertSame( 'Contact Form 7', ( new ContactForm7() )->name() );
		$this->assertSame( 'Forminator', ( new Forminator() )->name() );
		$this->assertNull( swiftling_subject( 'cf7-form:1' ) );
		$this->assertNull( swiftling_subject( 'forminator-form:1' ) );
	}

	/**
	 * The screen is there whatever is on the site: in Content of Translations, right after Comments.
	 */
	public function test_screen_is_always_in_the_menu() {
		$slugs = array_keys( Menu::screens() );
		$this->assertSame( 'swiftling-forms', $slugs[ (int) array_search( 'swiftling-comments', $slugs, true ) + 1 ] );
		$this->assertSame( 'translations', Menu::screens()['swiftling-forms']['group'] );
		$this->assertSame( 'content', Menu::screens()['swiftling-forms']['part'] );

		$this->become( 'administrator' );
		( new Menu() )->registerMenu();
		$this->assertNotNull( get_plugin_page_hook( 'swiftling-forms', 'swiftling' ) );
	}

	/**
	 * A form is a subject of the editor, named after the plugin, and its texts are the values the
	 * plugin keeps in the meta of its post.
	 */
	public function test_contact_form_7_texts() {
		$this->activate( new ContactForm7() );
		$form  = $this->cf7_form();
		$texts = $this->texts( 'cf7-form:' . $form->ID );

		$this->assertArrayHasKey( 'meta/_messages/mail_sent_ok', $texts );
		$this->assertSame( 'Hvala na poruci. Poslana je.', $texts['meta/_messages/mail_sent_ok']['v'] );
		$this->assertSame( 'post:' . $form->ID, $texts['meta/_messages/mail_sent_ok']['o'] );
		$this->assertSame( 'Messages › Mail sent ok', $texts['meta/_messages/mail_sent_ok']['place'] );
		$this->assertArrayHasKey( 'meta/_messages/invalid_required', $texts );
		$this->assertArrayHasKey( 'meta/_mail_2/subject', $texts, 'The mail to the visitor' );
		$this->assertSame( 'Second mail › Subject', $texts['meta/_mail_2/subject']['place'] );
		$this->assertArrayHasKey( 'meta/_mail_2/body', $texts );
		$this->assertArrayNotHasKey( 'meta/_mail/subject', $texts, 'The mail to the site\'s owner stays in the original' );
		$this->assertArrayNotHasKey( 'meta/_mail/body', $texts );

		// Where a mail goes and how it travels is no text: a marker or a translation there would
		// change the mail's address, not its words.
		$this->assertArrayNotHasKey( 'meta/_mail_2/recipient', $texts );
		$this->assertArrayNotHasKey( 'meta/_mail_2/sender', $texts );
	}

	/**
	 * A form of Contact Form 7 sent from a page in another language arrives at the REST API and
	 * runs in that language: the plugin reads the form's messages and mails from its meta,
	 * translated. The mail to the site's owner gets its original back before the plugin sends it,
	 * also when a translation of it was made; the mail to the visitor keeps its translation.
	 */
	public function test_contact_form_7_mails_of_a_form_sent() {
		require_once __DIR__ . '/stubs/FakeCf7Form.php';
		$provider = new ContactForm7();
		$this->assertMatchesRegularExpression( $provider->sendingRoutes()[0], '/contact-form-7/v1/contact-forms/1512/feedback' );
		$this->activate( $provider );
		$form = $this->cf7_form();
		swiftling_save_field_list( swiftling_subject( 'cf7-form:' . $form->ID ) );
		$owner = 'post:' . $form->ID;
		$this->translate( $owner, 'meta/_messages/mail_sent_ok', 'Hvala na poruci. Poslana je.', 'de', 'Danke, gesendet.' );
		$this->translate( $owner, 'meta/_mail_2/subject', 'Primili smo vašu poruku', 'de', 'Wir haben Ihre Nachricht' );
		// A translation of the owner's mail, as one made before it was left out of the editor.
		$this->translate( $owner, 'meta/_mail/subject', 'Nova poruka sa stranice', 'de', 'Neue Nachricht' );

		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();
		$sent = new FakeCf7Form( $form->ID );
		$this->assertSame( 'Danke, gesendet.', $sent->prop( 'messages' )['mail_sent_ok'] );

		$provider->startMails( $sent );
		$this->assertSame( 'Nova poruka sa stranice', $sent->prop( 'mail' )['subject'], 'The owner\'s mail in the original' );
		$this->assertSame( 'info@example.org', $sent->prop( 'mail' )['recipient'] );
		$more = $provider->nextMails( array( 'mail_2' => $sent->prop( 'mail_2' ) ), $sent );
		$this->assertSame( 'Wir haben Ihre Nachricht', $more['mail_2']['subject'], 'The visitor\'s mail in the language' );
		$this->assertSame( 'translate', swiftling_mode() );

		// A second mail to the owner, as a copy to a fixed address, gets its original back too.
		update_post_meta(
			$form->ID,
			'_mail_2',
			array(
				'subject'   => 'Kopija',
				'recipient' => 'ured@example.org, [vas-email]',
			)
		);
		$this->translate( $owner, 'meta/_mail_2/subject', 'Kopija', 'de', 'Kopie' );
		$sent = new FakeCf7Form( $form->ID );
		$more = $provider->nextMails( array( 'mail_2' => $sent->prop( 'mail_2' ) ), $sent );
		$this->assertSame( 'Kopija', $more['mail_2']['subject'] );
	}

	/**
	 * The form itself is one text, in the plugin's form-tag syntax, which the editor shows as code
	 * and never machine translates.
	 */
	public function test_contact_form_7_form_tags_are_code() {
		$this->activate( new ContactForm7() );
		$form = $this->cf7_form();
		$text = $this->texts( 'cf7-form:' . $form->ID )['meta/_form'];

		$this->assertStringContainsString( '[text* vase-ime]', $text['v'], 'The whole form is one text, tags and all' );
		$this->assertStringContainsString( 'Vaše ime', $text['v'] );
		$this->assertTrue( swiftling_live_is_code( $text ), 'The editor shows it as code' );
	}

	/**
	 * The labels, choices, messages and mail subjects of a Forminator form are offered.
	 */
	public function test_forminator_texts() {
		$this->activate( new Forminator() );
		$form  = $this->forminator_form();
		$texts = $this->texts( 'forminator-form:' . $form->ID );
		$meta  = 'meta/forminator_form_meta/';

		foreach ( array(
			$meta . 'fields/#name-1/field_label'     => 'Vaše ime',
			$meta . 'fields/#name-1/options/0/label' => 'Gospodin',
			$meta . 'settings/formName'              => 'Prijava na novosti',
			$meta . 'settings/thankyou-message'      => 'Hvala na prijavi.',
			$meta . 'settings/submitData/custom-submit-text' => 'Prijavi me',
			$meta . 'behaviors/0/thankyou-message'   => 'Hvala, javit ćemo se.',
			$meta . 'notifications/1/email-subject'  => 'Hvala na prijavi',
			$meta . 'notifications/1/email-editor'   => 'Javit ćemo se.',
		) as $path => $value ) {
			$this->assertArrayHasKey( $path, $texts, $path );
			$this->assertSame( $value, $texts[ $path ]['v'], $path );
		}
		$this->assertSame( 'Fields › name-1 › Field label', $texts[ $meta . 'fields/#name-1/field_label' ]['place'] );
		$this->assertSame( 'Notifications › 2 › Email subject', $texts[ $meta . 'notifications/1/email-subject' ]['place'] );
		foreach ( array( 'label', 'email-subject', 'email-editor' ) as $name ) {
			$this->assertArrayNotHasKey( $meta . 'notifications/0/' . $name, $texts, 'The email to the site\'s owner stays in the original' );
		}
	}

	/**
	 * A Forminator form sent from a page in another language: the plugin builds each email from the
	 * form it loaded, translated, and hands the email's recipients to
	 * forminator_form_get_admin_email_recipients first. For the email to the site's owner, sent in
	 * the original, the fields of that form hold their original texts until it is sent; the email
	 * to the visitor lists them translated.
	 */
	public function test_forminator_owner_email_lists_the_original_fields() {
		$provider = new Forminator();
		$this->assertContains( 'forminator_submit_form_custom-forms', $provider->sendingActions() );
		$this->activate( $provider );
		$form = $this->forminator_form();
		swiftling_save_field_list( swiftling_subject( 'forminator-form:' . $form->ID ) );
		$this->translate( 'post:' . $form->ID, 'meta/forminator_form_meta/fields/#name-1/field_label', 'Vaše ime', 'de', 'Ihr Name' );
		$_GET['lang'] = 'de';
		swiftling_maybe_start_translation();

		// The form as the plugin loaded it: its fields are objects with their data in raw.
		$meta          = get_post_meta( $form->ID, 'forminator_form_meta', true );
		$field         = (object) array( 'raw' => array( 'element_id' => 'name-1' ) + $meta['fields'][0] );
		$module        = (object) array(
			'id'     => $form->ID,
			'fields' => array( $field ),
		);
		$notifications = $meta['notifications'];
		$this->assertSame( 'Ihr Name', $field->raw['field_label'] );

		$this->assertSame( array( 'urednik@example.org' ), $provider->startNotification( array( 'urednik@example.org' ), $notifications[0], array(), $module ) );
		$this->assertSame( '', swiftling_mode(), 'The owner\'s email is built in the original' );
		$this->assertSame( 'Vaše ime', $field->raw['field_label'] );
		$this->assertSame( 'Roboto', $field->raw['label-font-family'] );
		$provider->endNotification();
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'Ihr Name', $field->raw['field_label'], 'The translation is back' );

		$provider->startNotification( array( 'ivan@example.org' ), $notifications[1], array(), $module );
		$this->assertSame( 'translate', swiftling_mode(), 'The visitor\'s email in the language' );
		$this->assertSame( 'Ihr Name', $field->raw['field_label'] );
		$provider->endNotification();
	}

	/**
	 * The looks of a Forminator form are no text, although their values read like one.
	 */
	public function test_forminator_looks_are_no_text() {
		$this->activate( new Forminator() );
		$form  = $this->forminator_form();
		$texts = $this->texts( 'forminator-form:' . $form->ID );
		$meta  = 'meta/forminator_form_meta/';

		foreach ( array( 'fields/#name-1/label-font-family', 'settings/form-font-family', 'settings/template_name', 'settings/themeName', 'settings/backgroundPosition' ) as $path ) {
			$this->assertArrayNotHasKey( $meta . $path, $texts, $path );
		}
		// Every one of those values would be taken for text without the rule.
		foreach ( array( 'Roboto', 'Open Sans', 'Newsletter', 'Default', 'center center' ) as $value ) {
			$this->assertTrue( swiftling_is_text_like( $value ), $value );
		}
		// The label beside them is still offered, so the rule swallows nothing else.
		$this->assertArrayHasKey( $meta . 'fields/#name-1/field_label', $texts );
		$this->assertArrayHasKey( $meta . 'fields/#name-1/placeholder', $texts );
	}

	/**
	 * A form says how many pages show it, counted by the shortcode a page holds.
	 */
	public function test_forms_count_the_pages_they_are_on() {
		$this->activate( new Forminator() );
		$form  = $this->forminator_form();
		$other = $this->forminator_form();
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Kontakt',
				'post_content' => '<!-- wp:shortcode -->[forminator_form id="' . $form->ID . '"]<!-- /wp:shortcode -->',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'   => 'Novosti',
				'post_content' => '[forminator_form id="' . $form->ID . '" render_id="2"]',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '[forminator_form id="' . $form->ID . '"]',
			)
		);

		$forms = ( new Forminator() )->forms();
		$this->assertCount( 2, $forms );
		$pages = wp_list_pluck( $forms, 'pages', 'id' );
		$this->assertSame( 2, $pages[ $form->ID ], 'The published pages that hold its shortcode' );
		$this->assertSame( 0, $pages[ $other->ID ] );
		$this->assertSame( 'forminator-form:' . $form->ID, wp_list_pluck( $forms, 'key', 'id' )[ $form->ID ] );
	}

	/**
	 * With a form, the screen lists it with its plugin, the pages it is on and its state in every
	 * language, and its name opens the translation editor.
	 */
	public function test_screen_lists_the_forms() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$this->activate( new ContactForm7() );
		$this->activate( new Forminator() );
		$cf7        = $this->cf7_form();
		$forminator = $this->forminator_form();
		$_GET       = array();

		$html = $this->render_screen( 'forms' );

		$this->assertStringContainsString( 'Kontakt obrazac', $html );
		$this->assertStringContainsString( 'Prijava', $html );
		$this->assertStringContainsString( 'Contact Form 7', $html );
		$this->assertStringContainsString( 'Forminator', $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'cf7-form:' . $cf7->ID ) ), $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'forminator-form:' . $forminator->ID ) ), $html );
		$this->assertStringContainsString( 'No page found', $html );
		$this->assertStringContainsString( '2 forms from 2 plugins', $html );
		$this->assertStringContainsString( 'My form is not listed', $html );
		$this->assertStringContainsString( 'swiftling-status is-missing', $html );
		$this->assertStringContainsString( 'swiftling-table-wrap', $html );
		$this->assertStringNotContainsString( 'No forms found on this site', $html );
		set_current_screen( 'front' );
	}

	/**
	 * Without a form plugin the screen says so, names the plugins it works with and leads to the
	 * way for a form it does not know.
	 */
	public function test_screen_without_a_form() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$_GET = array();

		$html = $this->render_screen( 'forms' );

		$this->assertStringContainsString( 'No forms found on this site', $html );
		$this->assertStringContainsString( 'Works with', $html );
		$this->assertStringContainsString( 'Contact Form 7', $html );
		$this->assertStringContainsString( 'Forminator', $html );
		$this->assertStringContainsString( 'Gravity Forms', $html );
		$this->assertStringContainsString( 'Ninja Forms', $html );
		$this->assertStringContainsString( 'Fluent Forms', $html );
		$this->assertStringContainsString( 'WPForms', $html );
		$this->assertStringContainsString( 'Formidable Forms', $html );
		$this->assertStringContainsString( 'More form plugins are added one at a time.', $html );
		$this->assertStringNotContainsString( 'looks for their forms', $html, 'SwiftLing does not look for the forms of a plugin it has no class for' );
		$this->assertStringContainsString( 'the block editor, Divi or Elementor', $html );
		$this->assertStringContainsString( 'My form is not listed', $html );
		$this->assertStringContainsString( 'swiftling-notice is-info', $html );
		// No plugin is named that SwiftLing has no provider for.
		foreach ( array( 'Everest Forms', 'WS Form' ) as $other ) {
			$this->assertStringNotContainsString( $other, $html );
		}
		set_current_screen( 'front' );
	}

	/**
	 * "My form is not listed" offers the pages of the site, and its button opens the translation
	 * editor of the page chosen, where the page check finds what a form on it shows.
	 */
	public function test_screen_of_a_form_that_is_not_listed() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => 'Kontakt',
			)
		);
		$_GET = array( 'help' => 'not-listed' );

		$html = $this->render_screen( 'forms' );

		$this->assertStringContainsString( 'Forms › My form is not listed', $html );
		$this->assertStringContainsString( 'SwiftLing has a class for each form plugin it works with', $html );
		$this->assertStringContainsString( 'name="page" value="swiftling-translation"', $html );
		$this->assertStringContainsString( '<option value="' . $page->ID . '">Kontakt</option>', $html );
		$this->assertStringContainsString( 'Check page', $html );
		$this->assertStringContainsString( 'tables of its own often shows nothing here', $html );
		$this->assertStringNotContainsString( 'No forms found on this site', $html );
		set_current_screen( 'front' );
	}

	/**
	 * The translation editor of a form shows its texts.
	 */
	public function test_editor_of_a_form() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$this->activate( new ContactForm7() );
		$form = $this->cf7_form();
		$_GET = array(
			'subject' => 'cf7-form:' . $form->ID,
			'lang'    => 'de',
		);

		$html = $this->render_screen( 'editor' );

		$this->assertStringContainsString( 'Kontakt obrazac', $html );
		$this->assertStringContainsString( 'Hvala na poruci. Poslana je.', $html );
		$this->assertStringContainsString( 'Form of Contact Form 7', $html );
		set_current_screen( 'front' );
	}
}
