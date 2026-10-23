<?php
/**
 * Ninja Forms: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Ninja Forms. A form is rows of its own tables (nf3_forms, nf3_fields and their meta). While it
 * shows a form, NF_Display_Render hands its settings to the filter ninja_forms_display_form_settings
 * and, once it has prepared them, its fields to ninja_forms_display_fields, as plain arrays it then
 * gives the script that draws the form in the browser. The texts in both are translated there, see
 * TableProvider; the title the plugin prints itself goes through ninja_forms_form_title.
 *
 * Not taken: the value of a choice, a CSS class, the key of a field, the place of its label, and
 * the label of a field that shows no label, such as an HTML field, whose content is its text.
 *
 * The message after sending and the emails are actions of the form. The plugin sends a form
 * through admin-ajax.php, with the action nf_ajax_submit, which runs in the language of the page
 * it was sent from, see swiftling_sent_form_language(), and hands each action it runs to
 * ninja_forms_run_action_settings before it fills in its tags (at 10). There, at 5:
 * - the message after sending (a "successmessage" action) is translated;
 * - an email whose every recipient is the visitor's, a field's tag such as {field:email} or the
 *   signed-in visitor's {wp:user_email}, is the visitor's: its subject and text are translated;
 * - any other email is the site owner's and is built and sent in the original, see SentForms,
 *   until the next action or the end of the sending (ninja_forms_after_submission).
 * The errors the plugin finds are its own texts, in the language through the locale.
 *
 * Not covered: the message for a value sent before, which the plugin reads from the form as
 * stored and puts in its answer without a filter; and the labels of the fields in an email, which
 * it reads from the fields as stored.
 *
 * Checked against the code of Ninja Forms 3.15, in tests/NinjaFormsTest.php with forms of the same
 * shape, and by hand on a site with the plugin.
 */
class NinjaForms extends TableProvider {
	use SentForms;

	/**
	 * The texts of a field, by their name in its settings.
	 */
	const FIELD_TEXTS = array( 'label', 'placeholder', 'help_text', 'desc_text', 'input_limit_msg', 'processing_label' );

	/**
	 * Fields whose label the form does not show; an HTML field's content is its text.
	 */
	const NO_LABEL = array( 'html', 'hr', 'hidden', 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile', 'spam' );

	/**
	 * The texts of the form itself, by their name in its settings, and what the editor calls them.
	 */
	const FORM_TEXTS = array( 'title', 'not_logged_in_msg', 'sub_limit_msg', 'unique_field_error' );

	/**
	 * The texts of an email action, by their name in its settings.
	 */
	const MAIL_TEXTS = array( 'email_subject', 'email_message', 'email_message_plain' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'ninjaforms';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Ninja Forms';
	}

	/**
	 * Whether the plugin is on the site: its main class is declared while its files load.
	 */
	public function isActive(): bool {
		return class_exists( 'Ninja_Forms' ) && function_exists( 'Ninja_Forms' );
	}

	/**
	 * Returns the action of admin-ajax.php with which the plugin receives a form, see
	 * NF_AJAX_Controllers_Submission::submit().
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'nf_ajax_submit' );
	}

	/**
	 * Hooks the filters where the plugin hands over a form it is about to show, and the actions it
	 * runs for a form sent.
	 */
	public function register(): void {
		add_filter( 'ninja_forms_display_form_settings', array( $this, 'filterSettings' ), 10, 2 );
		add_filter( 'ninja_forms_display_fields', array( $this, 'filterFields' ), 10, 2 );
		add_filter( 'ninja_forms_form_title', array( $this, 'filterTitle' ), 10, 2 );
		// Before the plugin fills in the tags of an action's settings, at 10.
		add_filter( 'ninja_forms_run_action_settings', array( $this, 'filterAction' ), 5, 3 );
		add_action( 'ninja_forms_after_submission', array( $this, 'endActions' ), PHP_INT_MIN );
	}

	/**
	 * Translates the texts of an action the plugin runs for a form sent: the message after sending
	 * and the email to the visitor. An email to the site's owner is built and sent in the original,
	 * until the next action or the end of the sending.
	 *
	 * @param mixed      $settings  Settings of the action.
	 * @param int|string $form_id   Form ID.
	 * @param int|string $action_id Action ID.
	 * @return mixed
	 */
	public function filterAction( $settings, $form_id = 0, $action_id = 0 ) {
		$this->endOwnerMails();
		if ( 'translate' !== swiftling_mode() || ! is_array( $settings ) || ! (int) $form_id || ! (int) $action_id ) {
			return $settings;
		}
		$type = isset( $settings['type'] ) ? (string) $settings['type'] : '';
		if ( 'email' === $type && ! self::mailsTheVisitor( $settings ) ) {
			$this->startOwnerMail();
			return $settings;
		}
		return $this->translateAction( (int) $form_id, (int) $action_id, $settings );
	}

	/**
	 * Ends the sending of a form: an email to the site's owner sent last is done.
	 */
	public function endActions(): void {
		$this->endOwnerMails();
	}

	/**
	 * Translates the texts of an action: the message after sending, or the subject and text of an
	 * email. Other actions have none.
	 *
	 * @param int   $form_id   Form ID.
	 * @param int   $action_id Action ID.
	 * @param array $settings  Settings of the action.
	 */
	private function translateAction( int $form_id, int $action_id, array $settings ): array {
		$type   = isset( $settings['type'] ) ? (string) $settings['type'] : '';
		$names  = 'successmessage' === $type ? array( 'success_msg' ) : ( 'email' === $type ? self::MAIL_TEXTS : array() );
		$places = array();
		foreach ( $names as $name ) {
			self::addPlace( $places, $settings, array( $name ), 'actions/a' . $action_id . '/' . $name );
		}
		return $this->translate( $form_id, $settings, $places );
	}

	/**
	 * Whether an email action writes to the visitor: every address it sends to is a field's tag, as
	 * {field:email}, or the signed-in visitor's own. The site's address, {wp:admin_email} or
	 * {system:admin_email}, a post author's and a fixed address are the site owner's.
	 *
	 * @param array $settings Settings of the email action.
	 */
	private static function mailsTheVisitor( array $settings ): bool {
		return self::onlyVisitors(
			isset( $settings['to'] ) && is_string( $settings['to'] ) ? $settings['to'] : '',
			static function ( string $recipient ): bool {
				return 1 === preg_match( '/^\{(field:[^{}]+|wp:user_email|user:email)\}$/', $recipient );
			}
		);
	}

	/**
	 * Translates the texts of the settings of a form.
	 *
	 * @param mixed      $settings Settings of the form.
	 * @param int|string $form_id  Form ID.
	 * @return mixed
	 */
	public function filterSettings( $settings, $form_id ) {
		if ( ! is_array( $settings ) || ! (int) $form_id ) {
			return $settings;
		}
		$places = array();
		foreach ( self::FORM_TEXTS as $name ) {
			self::addPlace( $places, $settings, array( $name ), $name );
		}
		return $this->translate( (int) $form_id, $settings, $places );
	}

	/**
	 * Translates the title of a form the plugin prints itself, as the title in its settings.
	 *
	 * @param mixed      $title   Title.
	 * @param int|string $form_id Form ID.
	 * @return mixed
	 */
	public function filterTitle( $title, $form_id ) {
		if ( ! is_string( $title ) || ! (int) $form_id ) {
			return $title;
		}
		$places = array();
		self::addPlace( $places, array( 'title' => $title ), array( 'title' ), 'title' );
		return $this->translate( (int) $form_id, array( 'title' => $title ), $places )['title'];
	}

	/**
	 * Translates the texts of the fields of a form.
	 *
	 * @param mixed      $fields  Fields: the settings of each, with its ID.
	 * @param int|string $form_id Form ID.
	 * @return mixed
	 */
	public function filterFields( $fields, $form_id ) {
		if ( ! is_array( $fields ) || ! (int) $form_id ) {
			return $fields;
		}
		$places = array();
		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
				continue;
			}
			// A form shown twice on a page has its fields numbered "1_1"; their texts stay field 1's.
			$id   = 'fields/field_' . (int) $field['id'];
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			foreach ( self::FIELD_TEXTS as $name ) {
				if ( 'label' !== $name || ! in_array( $type, self::NO_LABEL, true ) ) {
					self::addPlace( $places, $fields, array( $index, $name ), $id . '/' . $name );
				}
			}
			if ( 'html' === $type ) {
				self::addPlace( $places, $fields, array( $index, 'default' ), $id . '/content' );
			}
			$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
			foreach ( array_keys( $options ) as $row ) {
				self::addPlace( $places, $fields, array( $index, 'options', $row, 'label' ), $id . '/options/o' . $row . '/label' );
			}
		}
		return $this->translate( (int) $form_id, $fields, $places );
	}

	/**
	 * Returns the forms.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	protected function allForms(): array {
		$forms = array();
		foreach ( Ninja_Forms()->form()->get_forms() as $model ) {
			$forms[] = array(
				'id'    => (int) $model->get_id(),
				'title' => trim( (string) $model->get_setting( 'title' ) ),
			);
		}
		return $forms;
	}

	/**
	 * Returns the settings of a form, as the plugin's model gives them.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formSettings( int $form_id ): array {
		return (array) Ninja_Forms()->form( $form_id )->get()->get_settings();
	}

	/**
	 * Returns the fields of a form, each its settings with its ID, as the plugin's models give them.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formFields( int $form_id ): array {
		$fields = array();
		foreach ( Ninja_Forms()->form( $form_id )->get_fields() as $model ) {
			$fields[] = array( 'id' => (int) $model->get_id() ) + (array) $model->get_settings();
		}
		return $fields;
	}

	/**
	 * Returns the actions of a form, each its ID and its settings, as the plugin's models give them.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, array{id: int, settings: array}>
	 */
	protected function formActions( int $form_id ): array {
		$actions = array();
		foreach ( Ninja_Forms()->form( $form_id )->get_actions() as $model ) {
			$actions[] = array(
				'id'       => (int) $model->get_id(),
				'settings' => (array) $model->get_settings(),
			);
		}
		return $actions;
	}

	/**
	 * Reads the texts of a form through the filters, as a page that shows it and the sending of it
	 * do: its settings, its fields, the message after sending and the emails to the visitor.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function readTexts( int $form_id ): void {
		$this->filterSettings( $this->formSettings( $form_id ), $form_id );
		$this->filterFields( $this->formFields( $form_id ), $form_id );
		foreach ( $this->formActions( $form_id ) as $action ) {
			$type = isset( $action['settings']['type'] ) ? (string) $action['settings']['type'] : '';
			if ( 'email' !== $type || self::mailsTheVisitor( $action['settings'] ) ) {
				$this->translateAction( $form_id, (int) $action['id'], $action['settings'] );
			}
		}
	}

	/**
	 * Returns the ways a post shows a form: the shortcode, or the block with the ID of the form,
	 * which the block saves as a number.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, string[]>
	 */
	protected function pageMarks( int $form_id ): array {
		$marks = array();
		foreach ( array( 'ninja_form', 'ninja_forms' ) as $tag ) {
			foreach ( array( $form_id . ']', $form_id . ' ', '"' . $form_id . '"', "'" . $form_id . "'" ) as $id ) {
				$marks[] = array( '[' . $tag . ' id=' . $id );
			}
		}
		foreach ( array( $form_id . ',', $form_id . '}', '"' . $form_id . '"' ) as $id ) {
			$marks[] = array( '<!-- wp:ninja-forms/form ', '"formID":' . $id );
		}
		return $marks;
	}

	/**
	 * Whether the current user may edit forms, as the plugin's screen of forms asks.
	 */
	protected function mayEdit(): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as its screen of forms asks it.
		return current_user_can( (string) apply_filters( 'ninja_forms_admin_all_forms_capabilities', 'manage_options' ) );
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
			'title'              => __( 'Title', 'swiftling' ),
			'not_logged_in_msg'  => __( 'Message for visitors who are not logged in', 'swiftling' ),
			'sub_limit_msg'      => __( 'Message when the limit of entries is reached', 'swiftling' ),
			'unique_field_error' => __( 'Message when a value was sent before', 'swiftling' ),
			'label'              => __( 'Label', 'swiftling' ),
			'placeholder'        => __( 'Placeholder', 'swiftling' ),
			'help_text'          => __( 'Help text', 'swiftling' ),
			'desc_text'          => __( 'Description', 'swiftling' ),
			'input_limit_msg'    => __( 'Characters left', 'swiftling' ),
			'processing_label'   => __( 'Processing label', 'swiftling' ),
			'content'            => __( 'Content', 'swiftling' ),
		);
		if ( 'actions' === $parts[0] && isset( $parts[2] ) ) {
			return $this->actionLabel( $form_id, (int) substr( $parts[1], 1 ), $parts[2] );
		}
		if ( 'fields' !== $parts[0] || count( $parts ) < 3 ) {
			return isset( $names[ $parts[0] ] ) ? $names[ $parts[0] ] : '';
		}

		$field_id = (int) substr( $parts[1], 6 );
		/* translators: %d: ID of a field of a form. */
		$title = sprintf( __( 'Field %d', 'swiftling' ), $field_id );
		foreach ( $this->formFields( $form_id ) as $field ) {
			if ( (int) $field['id'] === $field_id && isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ) {
				$title = (string) $field['label'];
			}
		}
		if ( 'options' === $parts[2] && isset( $parts[3] ) ) {
			/* translators: %d: number of a choice of a field, counted from one. */
			return $title . ' › ' . sprintf( __( 'Choice %d', 'swiftling' ), (int) substr( $parts[3], 1 ) + 1 );
		}
		return $title . ' › ' . ( isset( $names[ $parts[2] ] ) ? $names[ $parts[2] ] : $parts[2] );
	}

	/**
	 * Returns what the editor calls a text of an action: the action's label and the text.
	 *
	 * @param int    $form_id   Form ID.
	 * @param int    $action_id Action ID.
	 * @param string $name      Name of the text in the action's settings.
	 */
	private function actionLabel( int $form_id, int $action_id, string $name ): string {
		/* translators: %d: ID of an action of a form, such as an email it sends. */
		$title = sprintf( __( 'Action %d', 'swiftling' ), $action_id );
		foreach ( $this->formActions( $form_id ) as $action ) {
			if ( $action['id'] === $action_id && isset( $action['settings']['label'] ) && '' !== trim( (string) $action['settings']['label'] ) ) {
				$title = trim( (string) $action['settings']['label'] );
			}
		}
		$texts = array(
			'success_msg'         => __( 'Message after sending', 'swiftling' ),
			'email_subject'       => __( 'Subject of the email to the visitor', 'swiftling' ),
			'email_message'       => __( 'Text of the email to the visitor', 'swiftling' ),
			'email_message_plain' => __( 'Plain text of the email to the visitor', 'swiftling' ),
		);
		return $title . ' › ' . ( isset( $texts[ $name ] ) ? $texts[ $name ] : $name );
	}
}
