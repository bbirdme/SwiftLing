<?php
/**
 * Formidable Forms: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Formidable Forms. A form is a row of its own table, frm_forms, and its fields are rows of
 * frm_fields. While it shows a form, the plugin hands the form to the filter frm_pre_display_form
 * (its title, description and button) and each field, prepared for the page, to
 * frm_setup_new_fields_vars. The texts are translated there, see TableProvider.
 *
 * The message after sending: since Formidable 6.0 a form keeps it in On Submit actions, posts of
 * the type frm_form_actions with their settings as JSON in post_content, and moves an older form's
 * message there when its settings are opened. Of a moved form ("on_submit_migrated" in its options)
 * the plugin hands the actions that apply to a sent entry to frm_get_met_on_submit_actions, and the
 * message is translated there; the copy left in the form's options is not shown and not listed. Of
 * a form not moved yet the message is the one in its options, translated with the form.
 *
 * A form sent with "Submit this form with AJAX" on arrives through admin-ajax.php, with the action
 * frm_entries_create, and runs in the language of the page it was sent from, see
 * swiftling_sent_form_language(); one sent without it arrives at the page itself. Either way the
 * answer is in the page's language:
 * - the errors the plugin finds: it checks the fields it hands to frm_fields_to_validate, whose
 *   names and error messages are translated there, on copies, choices left as they are;
 * - the entry as the plugin lists it, in the email to the visitor: the fields it hands to
 *   frm_field_value_object, whose names are translated there, on copies;
 * - its own messages for a form, kept in its global settings (an error in the sending, an entry
 *   sent before, signing in needed), which it hands to frm_global_setting with the form they are
 *   shown for; they are listed with each form's texts;
 * - the emails, which the plugin sends at frm_trigger_email_action: one whose every recipient is
 *   taken from what the visitor sent (a field's shortcode) is the visitor's, its subject and text
 *   translated; any other, to the site's address or to a fixed one, is the site owner's and is
 *   built and sent in the original, see SentForms.
 *
 * What a visitor sends is not changed. Without "separate values" a choice sends its label, and the
 * plugin checks a sent value against the original choices, also when the form arrives through
 * admin-ajax.php, a request that knows no language. On a page in another language such a field
 * sends the original label as its value, with separate values on, while it shows the translated
 * one; a choice with a value of its own keeps it. The entry is then in the original language too.
 *
 * Not taken: what a program reads (the place of a label, CSS classes, the HTML around a field,
 * values).
 *
 * Checked against the code of Formidable Forms 6.35, in tests/FormidableTest.php with forms of the
 * same shape, and by hand on a site with the plugin.
 */
class Formidable extends TableProvider {
	use SentForms;

	/**
	 * The texts of a field, by their name in the array the plugin shows.
	 */
	const FIELD_TEXTS = array( 'name', 'description', 'placeholder', 'blank', 'invalid', 'unique_msg' );

	/**
	 * The texts of the form, by their name in its options.
	 */
	const FORM_OPTIONS = array( 'submit_value', 'success_msg' );

	/**
	 * The text of an On Submit action, by its kind: the message after sending, or the one shown while
	 * the visitor waits to be sent on, when the action waits. An action that opens a page has none.
	 */
	const ACTION_TEXTS = array(
		'message'  => 'success_msg',
		'redirect' => 'redirect_delay_msg',
	);

	/**
	 * The texts of a field that the errors the plugin finds in a sent form are made of, by their name
	 * in its field options; its name is the [field_name] in them.
	 */
	const ERROR_TEXTS = array( 'blank', 'invalid', 'unique_msg' );

	/**
	 * The plugin's own messages for a form, kept in its global settings and handed over with the form
	 * at frm_global_setting. Not "admin_permission", which only the admin shows.
	 */
	const SETTING_TEXTS = array( 'invalid_msg', 'failed_msg', 'login_msg' );

	/**
	 * The texts of an email action, by their name in its settings.
	 */
	const MAIL_TEXTS = array( 'email_subject', 'email_message' );

	/**
	 * The emails being sent, each until frm_trigger_email_action is done with it: the visitor's,
	 * with the settings of its action to put back, or the owner's, sent in the original.
	 *
	 * @var array<int, array{action: object, settings: array}|string|null>
	 */
	private $mails = array();

	/**
	 * Fields whose name the form does not show; an HTML field's description is its content.
	 */
	const NO_LABEL = array( 'html', 'hidden', 'captcha', 'user_id' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'formidable';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Formidable Forms';
	}

	/**
	 * Whether the plugin is on the site: its main class is declared while its files load.
	 */
	public function isActive(): bool {
		return class_exists( 'FrmAppHelper' );
	}

	/**
	 * Returns the action of admin-ajax.php with which the plugin receives a form sent with "Submit
	 * this form with AJAX" on, see FrmEntriesAJAXSubmitController::ajax_create().
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'frm_entries_create' );
	}

	/**
	 * Hooks the filters where the plugin hands over a form and its fields before it shows them, and
	 * what it makes of a form sent: its errors, its message, the entry and the emails.
	 */
	public function register(): void {
		add_filter( 'frm_pre_display_form', array( $this, 'filterForm' ) );
		add_filter( 'frm_setup_new_fields_vars', array( $this, 'filterField' ) );
		add_filter( 'frm_get_met_on_submit_actions', array( $this, 'filterActions' ), 10, 2 );
		add_filter( 'frm_fields_to_validate', array( $this, 'filterValidated' ) );
		add_filter( 'frm_field_value_object', array( $this, 'filterValueField' ) );
		add_filter( 'frm_global_setting', array( $this, 'filterSetting' ), 10, 3 );
		// Around FrmNotification::trigger_email(), at 10.
		add_action( 'frm_trigger_email_action', array( $this, 'startEmail' ), 9, 3 );
		add_action( 'frm_trigger_email_action', array( $this, 'endEmail' ), 11 );
	}

	/**
	 * Translates the texts of a form itself.
	 *
	 * @param mixed $form The form: the plugin's object with its options.
	 * @return mixed
	 */
	public function filterForm( $form ) {
		if ( ! is_object( $form ) || empty( $form->id ) ) {
			return $form;
		}
		$texts  = array(
			'name'        => isset( $form->name ) ? $form->name : '',
			'description' => isset( $form->description ) ? $form->description : '',
			'options'     => isset( $form->options ) && is_array( $form->options ) ? $form->options : array(),
		);
		$places = array();
		self::addPlace( $places, $texts, array( 'name' ), 'title' );
		self::addPlace( $places, $texts, array( 'description' ), 'description' );
		foreach ( self::FORM_OPTIONS as $name ) {
			if ( 'success_msg' !== $name || ! self::hasActions( $form ) ) {
				self::addPlace( $places, $texts, array( 'options', $name ), $name );
			}
		}
		$texts = $this->translate( (int) $form->id, $texts, $places );

		$form->name        = $texts['name'];
		$form->description = $texts['description'];
		if ( isset( $form->options ) && is_array( $form->options ) ) {
			$form->options = $texts['options'];
		}
		return $form;
	}

	/**
	 * Translates the texts of a field the plugin is about to show, and keeps what its choices send in
	 * the original.
	 *
	 * @param mixed $field The field, as an array.
	 * @return mixed
	 */
	public function filterField( $field ) {
		// The plugin starts the array of a field with its ID and its form's, see FrmAppHelper::start_field_array().
		if ( ! is_array( $field ) || empty( $field['id'] ) || empty( $field['form_id'] ) ) {
			return $field;
		}
		$form_id = (int) $field['form_id'];
		if ( 'translate' === swiftling_mode() ) {
			$field = $this->keepSentValues( $field );
		}

		$id     = 'fields/field_' . (int) $field['id'];
		$type   = isset( $field['type'] ) ? (string) $field['type'] : '';
		$places = array();
		foreach ( self::FIELD_TEXTS as $name ) {
			if ( 'name' !== $name || ! in_array( $type, self::NO_LABEL, true ) ) {
				self::addPlace( $places, $field, array( $name ), $id . '/' . $name );
			}
		}
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		foreach ( $options as $key => $option ) {
			$path = is_array( $option ) ? array( 'options', $key, 'label' ) : array( 'options', $key );
			self::addPlace( $places, $field, $path, $id . '/options/o' . $key );
		}
		return $this->translate( $form_id, $field, $places );
	}

	/**
	 * Translates the names and error messages of the fields the plugin checks a sent form against,
	 * on copies: the plugin keeps the same objects in its cache (FrmField::getAll()) and may read
	 * them again later in the request. Their choices stay the original ones, which a sent choice
	 * is checked against.
	 *
	 * @param mixed $fields The fields, as the plugin's objects.
	 * @return mixed
	 */
	public function filterValidated( $fields ) {
		if ( 'translate' !== swiftling_mode() || ! is_array( $fields ) ) {
			return $fields;
		}
		foreach ( $fields as $key => $field ) {
			$fields[ $key ] = $this->translatedField( $field, self::ERROR_TEXTS );
		}
		return $fields;
	}

	/**
	 * Translates the name of a field a sent entry is listed with, on a copy: in the email to the
	 * visitor ([default-message]) and where a page shows the entry. The owner's email is built in the
	 * original, see startEmail().
	 *
	 * @param mixed $field The field, as the plugin's object.
	 * @return mixed
	 */
	public function filterValueField( $field ) {
		return 'translate' === swiftling_mode() ? $this->translatedField( $field, array() ) : $field;
	}

	/**
	 * Returns a copy of a field, as the plugin's object, with its name and some of its field options
	 * translated; the field itself when none of them is.
	 *
	 * @param mixed    $field   The field.
	 * @param string[] $options Names of the field options to translate.
	 * @return mixed
	 */
	private function translatedField( $field, array $options ) {
		if ( ! is_object( $field ) || empty( $field->id ) || empty( $field->form_id ) ) {
			return $field;
		}
		$id     = 'fields/field_' . (int) $field->id;
		$before = array(
			'name'    => isset( $field->name ) ? $field->name : '',
			'options' => isset( $field->field_options ) && is_array( $field->field_options ) ? $field->field_options : array(),
		);
		$texts  = $before;
		$places = array();
		if ( ! in_array( isset( $field->type ) ? (string) $field->type : '', self::NO_LABEL, true ) ) {
			self::addPlace( $places, $texts, array( 'name' ), $id . '/name' );
		}
		foreach ( $options as $name ) {
			self::addPlace( $places, $texts, array( 'options', $name ), $id . '/' . $name );
		}
		if ( ! $places ) {
			return $field;
		}
		$texts = $this->translate( (int) $field->form_id, $texts, $places );
		if ( $texts === $before ) {
			return $field;
		}
		$copy       = clone $field;
		$copy->name = $texts['name'];
		if ( isset( $field->field_options ) && is_array( $field->field_options ) ) {
			$copy->field_options = $texts['options'];
		}
		return $copy;
	}

	/**
	 * Translates one of the plugin's own messages for a form, which it keeps in its global settings
	 * and hands over with the form it is shown for.
	 *
	 * @param mixed  $value    The message.
	 * @param string $name     Name of the setting.
	 * @param mixed  $settings The plugin's settings, with the form in current_form.
	 * @return mixed
	 */
	public function filterSetting( $value, $name = '', $settings = null ) {
		if ( 'translate' !== swiftling_mode() || ! is_string( $value ) || ! in_array( $name, self::SETTING_TEXTS, true ) || ! is_object( $settings ) || empty( $settings->current_form ) || ! is_numeric( $settings->current_form ) ) {
			return $value;
		}
		return $this->translateSetting( (int) $settings->current_form, $name, $value );
	}

	/**
	 * Translates a message of the plugin's global settings as a text of a form.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $name    Name of the setting.
	 * @param string $value   The message.
	 */
	private function translateSetting( int $form_id, string $name, string $value ): string {
		$texts  = array( $name => $value );
		$places = array();
		self::addPlace( $places, $texts, array( $name ), 'settings/' . $name );
		return $this->translate( $form_id, $texts, $places )[ $name ];
	}

	/**
	 * Before the plugin builds and sends the email of an email action, in a request in another
	 * language: an email to the visitor gets the translated subject and text of its action, put back
	 * by endEmail(); any other is the site owner's and is built and sent in the original.
	 *
	 * @param mixed $action The email action, as the plugin's object.
	 * @param mixed $entry  The entry sent.
	 * @param mixed $form   The form.
	 */
	public function startEmail( $action, $entry = null, $form = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The plugin passes the entry before the form.
		if ( 'translate' !== swiftling_mode() || ! is_object( $action ) || ! isset( $action->post_content ) || ! is_array( $action->post_content ) ) {
			$this->mails[] = null;
			return;
		}
		if ( ! self::mailsTheVisitor( $action->post_content ) || ! is_object( $form ) || empty( $form->id ) ) {
			$this->startOwnerMail();
			$this->mails[] = 'owner';
			return;
		}
		$this->mails[]        = array(
			'action'   => $action,
			'settings' => $action->post_content,
		);
		$action->post_content = $this->translateMail( (int) $form->id, $action );
	}

	/**
	 * After the plugin sent the email startEmail() prepared: puts back what it changed.
	 */
	public function endEmail(): void {
		$mail = array_pop( $this->mails );
		if ( 'owner' === $mail ) {
			$this->endOwnerMails();
		} elseif ( is_array( $mail ) ) {
			$mail['action']->post_content = $mail['settings'];
		}
	}

	/**
	 * Returns the settings of an email action with its subject and text translated.
	 *
	 * @param int    $form_id Form ID.
	 * @param object $action  The email action, as the plugin's object.
	 * @return array
	 */
	private function translateMail( int $form_id, $action ): array {
		$places = array();
		foreach ( self::MAIL_TEXTS as $name ) {
			self::addPlace( $places, $action->post_content, array( $name ), 'actions/a' . (int) $action->ID . '/' . $name );
		}
		return $this->translate( $form_id, $action->post_content, $places );
	}

	/**
	 * Whether an email action writes to the visitor: every address it sends to is taken from what the
	 * visitor sent, a field's shortcode such as [25] or [email-key]. The site's address, [admin_email],
	 * and a fixed address are the site owner's. Copies (cc, bcc) do not count.
	 *
	 * @param array $settings Settings of the email action.
	 */
	private static function mailsTheVisitor( array $settings ): bool {
		return self::onlyVisitors(
			isset( $settings['email_to'] ) && is_string( $settings['email_to'] ) ? $settings['email_to'] : '',
			static function ( string $recipient ): bool {
				return 1 === preg_match( '/^\[[^\[\]]+\]$/', $recipient ) && 1 !== preg_match( '/^\[\s*admin_email\s*\]$/i', $recipient );
			}
		);
	}

	/**
	 * Translates the texts of the On Submit actions that apply to an entry just sent.
	 *
	 * @param mixed $actions The actions, as the plugin's objects.
	 * @param mixed $args    What the plugin knows of the sending, with the form.
	 * @return mixed
	 */
	public function filterActions( $actions, $args = array() ) {
		if ( ! is_array( $actions ) || ! is_array( $args ) || ! isset( $args['form'] ) || ! is_object( $args['form'] ) || empty( $args['form']->id ) ) {
			return $actions;
		}
		foreach ( $actions as $key => $action ) {
			$actions[ $key ] = $this->translateAction( (int) $args['form']->id, $action );
		}
		return $actions;
	}

	/**
	 * Translates the text of an On Submit action, on a copy: the plugin keeps its actions in a cache
	 * that holds the same objects.
	 *
	 * @param int   $form_id Form ID.
	 * @param mixed $action  The action, as the plugin's object.
	 * @return mixed
	 */
	private function translateAction( int $form_id, $action ) {
		$name = self::actionText( $action );
		if ( '' === $name ) {
			return $action;
		}
		$places = array();
		self::addPlace( $places, $action->post_content, array( $name ), 'actions/a' . (int) $action->ID . '/' . $name );
		if ( ! $places ) {
			return $action;
		}
		$copy               = clone $action;
		$copy->post_content = $this->translate( $form_id, $action->post_content, $places );
		return $copy;
	}

	/**
	 * Returns the name of the text an On Submit action shows, by its kind.
	 *
	 * @param mixed $action The action, as the plugin's object.
	 * @return string Empty when it shows none.
	 */
	private static function actionText( $action ): string {
		if ( ! is_object( $action ) || empty( $action->ID ) || ! isset( $action->post_content ) || ! is_array( $action->post_content ) ) {
			return '';
		}
		$settings = $action->post_content;
		$kind     = isset( $settings['success_action'] ) ? (string) $settings['success_action'] : 'message';
		if ( ! isset( self::ACTION_TEXTS[ $kind ] ) || ( 'redirect' === $kind && empty( $settings['redirect_delay'] ) ) ) {
			return '';
		}
		return self::ACTION_TEXTS[ $kind ];
	}

	/**
	 * Whether a form keeps its messages after sending in On Submit actions, as the plugin asks it.
	 *
	 * @param object $form The form: the plugin's object with its options.
	 */
	private static function hasActions( $form ): bool {
		return isset( $form->options ) && is_array( $form->options ) && ! empty( $form->options['on_submit_migrated'] );
	}

	/**
	 * Makes the choices of a field without separate values send their original label: separate
	 * values on, and each label, as it is now, as its value, before the label is translated.
	 *
	 * @param array $field Field.
	 * @return array
	 */
	private function keepSentValues( array $field ): array {
		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) || ! empty( $field['separate_value'] ) ) {
			return $field;
		}
		foreach ( $field['options'] as $key => $option ) {
			$label                    = is_array( $option ) ? ( isset( $option['label'] ) ? (string) $option['label'] : (string) reset( $option ) ) : (string) $option;
			$field['options'][ $key ] = array_merge(
				is_array( $option ) ? $option : array(),
				array(
					'label' => $label,
					'value' => $label,
				)
			);
		}
		$field['separate_value'] = 1;
		return $field;
	}

	/**
	 * Returns the forms, drafts too, without the templates the plugin keeps beside them.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	protected function allForms(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once per screen.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM %i WHERE is_template = 0 AND status IN ( 'published', 'draft' ) AND ( parent_form_id = 0 OR parent_form_id IS NULL ) ORDER BY name ASC", $wpdb->prefix . 'frm_forms' ), ARRAY_A );
		$forms = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$forms[] = array(
				'id'    => (int) $row['id'],
				'title' => trim( (string) $row['name'] ),
			);
		}
		return $forms;
	}

	/**
	 * Returns a form as the plugin's object.
	 *
	 * @param int $form_id Form ID.
	 * @return object|null
	 */
	protected function formObject( int $form_id ) {
		$form = class_exists( 'FrmForm' ) ? \FrmForm::getOne( $form_id ) : null;
		return is_object( $form ) ? $form : null;
	}

	/**
	 * Returns the fields of a form as arrays like the ones the plugin shows: the columns of a field
	 * and its options, which the plugin lays out at the top of the array.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, array>
	 */
	protected function fieldsOf( int $form_id ): array {
		$fields = array();
		foreach ( class_exists( 'FrmField' ) ? (array) \FrmField::get_all_for_form( $form_id ) : array() as $field ) {
			$options  = isset( $field->field_options ) && is_array( $field->field_options ) ? $field->field_options : array();
			$fields[] = array_merge(
				$options,
				array(
					'id'          => (int) $field->id,
					'form_id'     => (int) $field->form_id,
					'type'        => (string) $field->type,
					'name'        => (string) $field->name,
					'description' => (string) $field->description,
					'options'     => $field->options,
				)
			);
		}
		return $fields;
	}

	/**
	 * Returns the actions of a form of a type, the published ones the plugin runs.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $type    Type of action: on_submit or email.
	 * @return object[]
	 */
	protected function actionsOf( int $form_id, string $type ): array {
		if ( ! class_exists( 'FrmFormAction' ) ) {
			return array();
		}
		$actions = \FrmFormAction::get_action_for_form( $form_id, $type, array( 'post_status' => 'publish' ) );
		return is_array( $actions ) ? array_values( $actions ) : array();
	}

	/**
	 * Returns the plugin's global settings.
	 *
	 * @return object|null
	 */
	protected function globalSettings() {
		return class_exists( 'FrmAppHelper' ) ? \FrmAppHelper::get_settings() : null;
	}

	/**
	 * Reads the texts of a form through the filters, as a page that shows it and the answer to it
	 * sent do: the form and its fields, the message after sending, the emails to the visitor, and
	 * the plugin's own messages for a form.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function readTexts( int $form_id ): void {
		$form = $this->formObject( $form_id );
		if ( null === $form ) {
			return;
		}
		$this->filterForm( $form );
		foreach ( $this->fieldsOf( $form_id ) as $field ) {
			$this->filterField( $field );
		}
		if ( self::hasActions( $form ) ) {
			$this->filterActions( $this->actionsOf( $form_id, 'on_submit' ), array( 'form' => $form ) );
		}
		foreach ( $this->actionsOf( $form_id, 'email' ) as $action ) {
			if ( isset( $action->post_content ) && is_array( $action->post_content ) && self::mailsTheVisitor( $action->post_content ) ) {
				$this->translateMail( $form_id, $action );
			}
		}
		$settings = $this->globalSettings();
		foreach ( self::SETTING_TEXTS as $name ) {
			if ( is_object( $settings ) && isset( $settings->$name ) && is_string( $settings->$name ) ) {
				$this->translateSetting( $form_id, $name, $settings->$name );
			}
		}
	}

	/**
	 * Returns the ways a post shows a form: the shortcode, or the block with the ID of the form.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, string[]>
	 */
	protected function pageMarks( int $form_id ): array {
		return array(
			array( '[formidable id="' . $form_id . '"' ),
			array( "[formidable id='" . $form_id . "'" ),
			array( '[formidable id=' . $form_id . ']' ),
			array( '[formidable id=' . $form_id . ' ' ),
			array( '<!-- wp:formidable/simple-form ', '"formId":"' . $form_id . '"' ),
		);
	}

	/**
	 * Whether the current user may edit forms, as the plugin asks it.
	 */
	protected function mayEdit(): bool {
		return current_user_can( 'frm_edit_forms' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- The plugin's own capability, which it grants to administrators.
	}

	/**
	 * Returns what the editor calls the field a text of a form belongs to, such as "Name › Label".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is not a text of one of this plugin's forms.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		$form_id = $this->formOf( $owner );
		if ( ! $form_id || 0 !== strpos( $path, self::BASE . '/' ) ) {
			return '';
		}
		$parts = explode( '/', substr( $path, strlen( self::BASE ) + 1 ) );
		$names = array(
			'title'        => __( 'Title', 'swiftling' ),
			'description'  => __( 'Description', 'swiftling' ),
			'submit_value' => __( 'Button', 'swiftling' ),
			'success_msg'  => __( 'Message after sending', 'swiftling' ),
			'name'         => __( 'Label', 'swiftling' ),
			'placeholder'  => __( 'Placeholder', 'swiftling' ),
			'blank'        => __( 'Error message when empty', 'swiftling' ),
			'invalid'      => __( 'Error message when invalid', 'swiftling' ),
			'unique_msg'   => __( 'Error message when sent before', 'swiftling' ),
		);
		if ( 'actions' === $parts[0] && isset( $parts[2] ) ) {
			return $this->actionLabel( $form_id, (int) substr( $parts[1], 1 ), $parts[2] );
		}
		if ( 'settings' === $parts[0] && isset( $parts[1] ) ) {
			$settings = array(
				'invalid_msg' => __( 'Error message when sending fails', 'swiftling' ),
				'failed_msg'  => __( 'Message for an entry sent before', 'swiftling' ),
				'login_msg'   => __( 'Message when signing in is needed', 'swiftling' ),
			);
			return isset( $settings[ $parts[1] ] ) ? $settings[ $parts[1] ] : '';
		}
		if ( 'fields' !== $parts[0] || count( $parts ) < 3 ) {
			return isset( $names[ $parts[0] ] ) ? $names[ $parts[0] ] : '';
		}

		$field_id = (int) substr( $parts[1], 6 );
		/* translators: %d: ID of a field of a form. */
		$title = sprintf( __( 'Field %d', 'swiftling' ), $field_id );
		foreach ( $this->fieldsOf( $form_id ) as $field ) {
			if ( $field['id'] === $field_id && '' !== trim( $field['name'] ) ) {
				$title = $field['name'];
			}
		}
		if ( 'options' === $parts[2] && isset( $parts[3] ) ) {
			$field   = array();
			$numbers = array();
			foreach ( $this->fieldsOf( $form_id ) as $candidate ) {
				if ( $candidate['id'] === $field_id ) {
					$field = $candidate;
				}
			}
			$numbers = array_map( 'strval', array_keys( isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array() ) );
			$number  = array_search( substr( $parts[3], 1 ), $numbers, true );
			/* translators: %d: number of a choice of a field, counted from one. */
			return $title . ' › ' . sprintf( __( 'Choice %d', 'swiftling' ), false === $number ? 0 : $number + 1 );
		}
		if ( 'description' === $parts[2] ) {
			return $title . ' › ' . $names['description'];
		}
		return $title . ' › ' . ( isset( $names[ $parts[2] ] ) ? $names[ $parts[2] ] : $parts[2] );
	}

	/**
	 * Returns what the editor calls a text of an action, On Submit or email: the action's title and
	 * the text.
	 *
	 * @param int    $form_id   Form ID.
	 * @param int    $action_id ID of the action.
	 * @param string $name      Name of the text in the action's settings.
	 */
	private function actionLabel( int $form_id, int $action_id, string $name ): string {
		$type = in_array( $name, self::MAIL_TEXTS, true ) ? 'email' : 'on_submit';
		/* translators: %d: ID of an action of a form, such as an email it sends. */
		$title = sprintf( __( 'Action %d', 'swiftling' ), $action_id );
		foreach ( $this->actionsOf( $form_id, $type ) as $action ) {
			if ( (int) $action->ID === $action_id && isset( $action->post_title ) && '' !== trim( (string) $action->post_title ) ) {
				$title = trim( (string) $action->post_title );
			}
		}
		$texts = array(
			'success_msg'        => __( 'Message after sending', 'swiftling' ),
			'redirect_delay_msg' => __( 'Message before going on', 'swiftling' ),
			'email_subject'      => __( 'Subject of the email to the visitor', 'swiftling' ),
			'email_message'      => __( 'Text of the email to the visitor', 'swiftling' ),
		);
		return $title . ' › ' . ( isset( $texts[ $name ] ) ? $texts[ $name ] : $name );
	}
}
