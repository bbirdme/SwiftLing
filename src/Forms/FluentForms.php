<?php
/**
 * Fluent Forms: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Fluent Forms. A form is a row of its own table, fluentform_forms, with its fields as JSON. While
 * it shows a form, the plugin decodes the fields into $form->fields and hands the form to the
 * filter fluentform/rendering_form, for a form on a page and for a conversational form alike. The
 * texts of the fields are translated there, see TableProvider.
 *
 * The fields are a tree: a field of several parts, such as a name, keeps its parts under "fields",
 * a row of columns keeps its fields under "columns". A field is named by the name a visitor's
 * answer is sent under, which the plugin keeps unique in a form.
 *
 * A form sent arrives through admin-ajax.php, with the action fluentform_submit, and runs in the
 * language of the page it was sent from, see swiftling_sent_form_language(). There:
 * - the message after sending, from the form's settings, is translated at
 *   fluentform/form_submission_confirmation;
 * - the error messages of the fields, which the plugin reads from the fields as stored, at
 *   fluentform/validations, by the name of the field and the rule each is keyed with;
 * - the emails are sent at once, not later in a request of the plugin's own that knows no
 *   language (fluentform/notifying_async_notifications). An email whose recipient is a field, or
 *   whose every address is a field's {inputs.name} or the signed-in visitor's, is the visitor's:
 *   its subject and text are translated from the email's settings and its tags filled in again
 *   (the plugin fills them in before it hands the email over). Any other email is the site
 *   owner's and is built and sent in the original, see SentForms.
 * The errors the plugin finds itself are its own texts, in the language through the locale.
 *
 * Not taken: the value of a choice, the name of a field, a CSS class and the name of a field in the
 * admin. Not covered: the messages a form shows instead of itself (a limit of entries, a schedule,
 * signing in needed), and an email the plugin sends only once a payment is made.
 *
 * Checked against the code of Fluent Forms 6.2, in tests/FluentFormsTest.php with forms of the same
 * shape, and by hand on a site with the plugin.
 */
class FluentForms extends TableProvider {
	use SentForms;

	/**
	 * The texts in the settings of a field, by their name there.
	 */
	const SETTING_TEXTS = array( 'label', 'help_message', 'prefix_label', 'suffix_label', 'tnc_html', 'html_codes', 'description' );

	/**
	 * The texts of an email of a form, by their name in its settings.
	 */
	const MAIL_TEXTS = array( 'subject', 'message' );

	/**
	 * The email to the visitor being sent now: its form, entry and settings; null between emails.
	 *
	 * @var array{form: int, entry: int|string, id: int, settings: array}|null
	 */
	private $visitor_mail = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'fluentforms';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Fluent Forms';
	}

	/**
	 * Whether the plugin is on the site: its main file declares FLUENTFORM while it loads.
	 */
	public function isActive(): bool {
		return defined( 'FLUENTFORM' );
	}

	/**
	 * Hooks the filter where the plugin hands over a form it is about to show.
	 */
	public function register(): void {
		add_filter( 'fluentform/rendering_form', array( $this, 'filterForm' ) );
		add_filter( 'fluentform/form_submission_confirmation', array( $this, 'filterConfirmation' ), 10, 3 );
		add_filter( 'fluentform/validations', array( $this, 'filterValidations' ), 10, 3 );
		add_filter( 'fluentform/notifying_async_notifications', array( $this, 'sendsLater' ), 10, 2 );
		// Around the plugin's own sending of an email, at 10.
		add_action( 'fluentform/integration_notify_notifications', array( $this, 'startNotification' ), 1, 4 );
		add_action( 'fluentform/integration_notify_notifications', array( $this, 'endNotification' ), 11 );
		add_filter( 'fluentform/submission_message_parse', array( $this, 'filterEmailBody' ), 10, 4 );
		add_filter( 'fluentform/email_subject', array( $this, 'filterEmailSubject' ), 10, 4 );
	}

	/**
	 * Returns the action of admin-ajax.php with which the plugin receives a form.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'fluentform_submit' );
	}

	/**
	 * Translates the message after sending of a form sent from a page in another language.
	 *
	 * @param mixed $confirmation What the form does after sending, from its settings.
	 * @param mixed $data         What the visitor sent.
	 * @param mixed $form         The form.
	 * @return mixed
	 */
	public function filterConfirmation( $confirmation, $data = array(), $form = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The plugin passes what was sent before the form.
		if ( 'translate' !== swiftling_mode() || ! is_array( $confirmation ) || ! is_object( $form ) || empty( $form->id ) ) {
			return $confirmation;
		}
		return $this->translateConfirmation( (int) $form->id, $confirmation );
	}

	/**
	 * Translates the message after sending in what a form does after sending.
	 *
	 * @param int   $form_id      Form ID.
	 * @param array $confirmation What the form does after sending.
	 */
	private function translateConfirmation( int $form_id, array $confirmation ): array {
		$places = array();
		self::addPlace( $places, $confirmation, array( 'messageToShow' ), 'confirmation/messageToShow' );
		return $this->translate( $form_id, $confirmation, $places );
	}

	/**
	 * Translates the error messages a form sent is checked with: the plugin keys each by the name of
	 * its field and the rule, as "names[first_name].required", and reads it from the field as
	 * stored, whose texts are named "fields/names/first_name/rules/required".
	 *
	 * @param mixed $validations The rules and the messages.
	 * @param mixed $form        The form.
	 * @param mixed $data        What the visitor sent.
	 * @return mixed
	 */
	public function filterValidations( $validations, $form = null, $data = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The plugin passes what was sent too.
		if ( 'translate' !== swiftling_mode() || ! is_array( $validations ) || ! isset( $validations[1] ) || ! is_array( $validations[1] ) || ! is_object( $form ) || empty( $form->id ) ) {
			return $validations;
		}
		$messages = $validations[1];
		$places   = array();
		foreach ( array_keys( $messages ) as $key ) {
			$path = $this->messagePath( (string) $key );
			if ( null !== $path ) {
				self::addPlace( $places, $messages, array( $key ), $path );
			}
		}
		$validations[1] = $this->translate( (int) $form->id, $messages, $places );
		return $validations;
	}

	/**
	 * Returns the name among a form's texts of an error message the plugin keys as
	 * "names[first_name].required".
	 *
	 * @param string $key Key of the message.
	 * @return string|null Null for a key of another shape, as of a repeated field.
	 */
	private function messagePath( string $key ): ?string {
		$dot = strrpos( $key, '.' );
		if ( false === $dot || false !== strpos( $key, '*' ) ) {
			return null;
		}
		$names = array_values( array_filter( (array) preg_split( '/[\[\]]+/', substr( $key, 0, $dot ) ), 'strlen' ) );
		$rule  = substr( $key, $dot + 1 );
		if ( ! $names || '' === $rule ) {
			return null;
		}
		return 'fields/' . implode( '/', array_map( array( $this, 'segment' ), $names ) ) . '/rules/' . $this->segment( $rule );
	}

	/**
	 * Whether the plugin sends the emails of a form sent later, in a request of its own: not from a
	 * page in another language, as that request would not know the language.
	 *
	 * @param mixed      $later   Whether the plugin sends them later.
	 * @param int|string $form_id Form ID.
	 * @return mixed
	 */
	public function sendsLater( $later, $form_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The plugin passes the form too.
		return 'translate' === swiftling_mode() ? false : $later;
	}

	/**
	 * Before the plugin sends an email of a form sent: an email to the visitor is translated in
	 * filterEmailSubject() and filterEmailBody(); one to the site's owner is built and sent in the
	 * original, until endNotification().
	 *
	 * @param mixed $feed  The email: its ID and its settings.
	 * @param mixed $data  What the visitor sent.
	 * @param mixed $entry The entry.
	 * @param mixed $form  The form.
	 */
	public function startNotification( $feed, $data = array(), $entry = null, $form = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The plugin passes what was sent before the entry.
		$this->endNotification();
		if ( 'translate' !== swiftling_mode() || ! is_array( $feed ) || ! isset( $feed['settings'] ) || ! is_array( $feed['settings'] ) || ! is_object( $form ) || empty( $form->id ) ) {
			return;
		}
		if ( ! self::mailsTheVisitor( $feed['settings'] ) ) {
			$this->startOwnerMail();
			return;
		}
		$this->visitor_mail = array(
			'form'     => (int) $form->id,
			'entry'    => is_object( $entry ) && isset( $entry->id ) ? $entry->id : 0,
			'id'       => isset( $feed['id'] ) ? (int) $feed['id'] : 0,
			'settings' => $feed['settings'],
		);
	}

	/**
	 * After the plugin sent an email: the next is decided again.
	 */
	public function endNotification(): void {
		$this->visitor_mail = null;
		$this->endOwnerMails();
	}

	/**
	 * Gives the email to the visitor being sent its translated subject, with its tags filled in.
	 *
	 * @param mixed $subject      The subject, its tags filled in.
	 * @param mixed $notification The email's settings, their tags filled in.
	 * @param mixed $data         What the visitor sent.
	 * @param mixed $form         The form.
	 * @return mixed
	 */
	public function filterEmailSubject( $subject, $notification = array(), $data = array(), $form = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The plugin passes the settings before what was sent.
		return $this->visitorText( 'subject', $subject, is_array( $data ) ? $data : array(), $form );
	}

	/**
	 * Gives the email to the visitor being sent its translated text, with its tags filled in. The
	 * plugin fills in the message after sending through the same filter, outside an email: that
	 * stays as it is, see filterConfirmation().
	 *
	 * @param mixed      $body     The text, its tags filled in.
	 * @param int|string $entry_id Entry ID.
	 * @param mixed      $data     What the visitor sent.
	 * @param mixed      $form     The form.
	 * @return mixed
	 */
	public function filterEmailBody( $body, $entry_id = 0, $data = array(), $form = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The entry is the email's own, kept by startNotification().
		return $this->visitorText( 'message', $body, is_array( $data ) ? $data : array(), $form );
	}

	/**
	 * Returns a text of the email to the visitor being sent, translated from its settings and its
	 * tags filled in again; the text as the plugin made it when there is no such email or no
	 * translation.
	 *
	 * @param string $name  Name of the text in the email's settings.
	 * @param mixed  $made  The text as the plugin made it.
	 * @param array  $data  What the visitor sent.
	 * @param mixed  $form  The form.
	 * @return mixed
	 */
	private function visitorText( string $name, $made, array $data, $form ) {
		$mail = $this->visitor_mail;
		if ( null === $mail || ! is_string( $made ) || ! isset( $mail['settings'][ $name ] ) || ! is_string( $mail['settings'][ $name ] ) ) {
			return $made;
		}
		$original = $mail['settings'][ $name ];
		$texts    = $this->translateMail( $mail['form'], $mail['id'], $mail['settings'] );
		if ( ! isset( $texts[ $name ] ) || $texts[ $name ] === $original ) {
			return $made;
		}
		return $this->fillTags( $texts[ $name ], $mail['entry'], $data, $form );
	}

	/**
	 * Returns the settings of an email with its subject and text translated.
	 *
	 * @param int   $form_id  Form ID.
	 * @param int   $mail_id  ID of the email.
	 * @param array $settings Settings of the email.
	 */
	private function translateMail( int $form_id, int $mail_id, array $settings ): array {
		$places = array();
		foreach ( self::MAIL_TEXTS as $name ) {
			self::addPlace( $places, $settings, array( $name ), 'notifications/n' . $mail_id . '/' . $name );
		}
		return $this->translate( $form_id, $settings, $places );
	}

	/**
	 * Fills in the tags of a text of an email as the plugin does.
	 *
	 * @param string     $text     Text with tags.
	 * @param int|string $entry_id Entry ID.
	 * @param array      $data     What the visitor sent.
	 * @param mixed      $form     The form.
	 */
	protected function fillTags( string $text, $entry_id, array $data, $form ): string {
		if ( ! class_exists( '\FluentForm\App\Services\FormBuilder\ShortCodeParser' ) ) {
			return $text;
		}
		return (string) \FluentForm\App\Services\FormBuilder\ShortCodeParser::parse( $text, $entry_id, $data, $form, false, 'notifications' );
	}

	/**
	 * Whether an email of a form writes to the visitor: sent to a field, or every address it sends
	 * to is a field's {inputs.name} or the signed-in visitor's {user.user_email}. The site's address,
	 * {wp.admin_email}, a fixed address and routing by the answers are the site owner's.
	 *
	 * @param array $settings Settings of the email.
	 */
	private static function mailsTheVisitor( array $settings ): bool {
		$type = isset( $settings['sendTo']['type'] ) ? (string) $settings['sendTo']['type'] : '';
		if ( 'field' === $type ) {
			return true;
		}
		return 'email' === $type && self::onlyVisitors(
			isset( $settings['sendTo']['email'] ) && is_string( $settings['sendTo']['email'] ) ? $settings['sendTo']['email'] : '',
			static function ( string $recipient ): bool {
				return 1 === preg_match( '/^\{(inputs\.[^{}]+|user\.user_email)\}$/', $recipient );
			}
		);
	}

	/**
	 * Translates the texts of the fields of a form, see TableProvider::translate().
	 *
	 * @param mixed $form The form: an object with its ID and its decoded fields.
	 * @return mixed The form, with its texts changed, or as it was.
	 */
	public function filterForm( $form ) {
		if ( ! is_object( $form ) || empty( $form->id ) || ! isset( $form->fields ) || ! is_array( $form->fields ) ) {
			return $form;
		}
		$form->fields = $this->translate( (int) $form->id, $form->fields, $this->places( $form->fields ) );
		return $form;
	}

	/**
	 * Returns where each text of the fields of a form is: its name in the texts,
	 * "fields/email/label", mapped to the keys that lead to it.
	 *
	 * @param array $fields Decoded fields of a form.
	 * @return array<string, array<int, int|string>>
	 */
	private function places( array $fields ): array {
		$places = array();
		if ( isset( $fields['fields'] ) && is_array( $fields['fields'] ) ) {
			$this->addFields( $places, $fields, $fields['fields'], array( 'fields' ), 'fields' );
		}
		self::addPlace( $places, $fields, array( 'submitButton', 'settings', 'button_ui', 'text' ), 'submit/text' );
		return $places;
	}

	/**
	 * Adds the places of the texts of a list of fields, and of the fields inside them.
	 *
	 * @param array<string, array<int, int|string>> $places Places so far.
	 * @param array                                 $all    All fields of the form.
	 * @param array                                 $items  The fields of the list.
	 * @param array<int, int|string>                $path   Keys that lead to the list.
	 * @param string                                $key    Name of the list in the texts.
	 */
	private function addFields( array &$places, array $all, array $items, array $path, string $key ): void {
		foreach ( $items as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$here = array_merge( $path, array( $index ) );
			$name = $this->fieldName( $field, (string) $index );
			$own  = $key . '/' . $name;

			foreach ( self::SETTING_TEXTS as $text ) {
				self::addPlace( $places, $all, array_merge( $here, array( 'settings', $text ) ), $own . '/' . $text );
			}
			self::addPlace( $places, $all, array_merge( $here, array( 'attributes', 'placeholder' ) ), $own . '/placeholder' );
			foreach ( array( 'prev_btn', 'next_btn' ) as $button ) {
				self::addPlace( $places, $all, array_merge( $here, array( 'settings', $button, 'text' ) ), $own . '/' . $button );
			}
			$options = self::at( $field, array( 'settings', 'advanced_options' ) );
			foreach ( is_array( $options ) ? array_keys( $options ) : array() as $row ) {
				self::addPlace( $places, $all, array_merge( $here, array( 'settings', 'advanced_options', $row, 'label' ) ), $own . '/options/o' . $row );
			}
			$rules = self::at( $field, array( 'settings', 'validation_rules' ) );
			foreach ( is_array( $rules ) ? array_keys( $rules ) : array() as $rule ) {
				self::addPlace( $places, $all, array_merge( $here, array( 'settings', 'validation_rules', $rule, 'message' ) ), $own . '/rules/' . $this->segment( (string) $rule ) );
			}

			// The parts of a field, such as a first and a last name, under the field's own name.
			if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
				$this->addFields( $places, $all, $field['fields'], array_merge( $here, array( 'fields' ) ), $own );
			}
			// The columns of a row hold fields of the form like any other.
			if ( isset( $field['columns'] ) && is_array( $field['columns'] ) ) {
				foreach ( $field['columns'] as $column => $cell ) {
					if ( isset( $cell['fields'] ) && is_array( $cell['fields'] ) ) {
						$this->addFields( $places, $all, $cell['fields'], array_merge( $here, array( 'columns', $column, 'fields' ) ), $key );
					}
				}
			}
		}
	}

	/**
	 * Returns the name of a field in the texts: the name its answer is sent under, else the key the
	 * plugin gives the element, else its place in the list.
	 *
	 * @param array  $field Field.
	 * @param string $index Its place in the list.
	 */
	private function fieldName( array $field, string $index ): string {
		foreach ( array( array( 'attributes', 'name' ), array( 'uniqElKey' ) ) as $path ) {
			$value = self::at( $field, $path );
			if ( is_string( $value ) && '' !== $value ) {
				return $this->segment( $value );
			}
		}
		return 'i' . $index;
	}

	/**
	 * Returns a name as one part of the name of a text: no slash in it.
	 *
	 * @param string $name Name.
	 */
	private function segment( string $name ): string {
		return str_replace( '/', '_', $name );
	}

	/**
	 * Returns the forms.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	protected function allForms(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once per screen.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title FROM %i ORDER BY title ASC', $wpdb->prefix . 'fluentform_forms' ), ARRAY_A );
		$forms = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$forms[] = array(
				'id'    => (int) $row['id'],
				'title' => trim( (string) $row['title'] ),
			);
		}
		return $forms;
	}

	/**
	 * Returns the fields of a form, decoded, as the plugin keeps them.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null Null when there is no such form.
	 */
	protected function formFields( int $form_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read when the editor lists a form.
		$json   = $wpdb->get_var( $wpdb->prepare( 'SELECT form_fields FROM %i WHERE id = %d', $wpdb->prefix . 'fluentform_forms', $form_id ) );
		$fields = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $fields ) ? $fields : null;
	}

	/**
	 * Returns the settings of a form, decoded, as the plugin keeps them under formSettings.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null Null when the form has none.
	 */
	protected function formSettings( int $form_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read when the editor lists a form.
		$json     = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE form_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'fluentform_form_meta', $form_id, 'formSettings' ) );
		$settings = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $settings ) ? $settings : null;
	}

	/**
	 * Returns the emails of a form, each its ID and its settings, decoded.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, array{id: int, settings: array}>
	 */
	protected function notificationFeeds( int $form_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read when the editor lists a form.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, value FROM %i WHERE form_id = %d AND meta_key = %s ORDER BY id ASC', $wpdb->prefix . 'fluentform_form_meta', $form_id, 'notifications' ), ARRAY_A );
		$feeds = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$settings = json_decode( (string) $row['value'], true );
			if ( is_array( $settings ) ) {
				$feeds[] = array(
					'id'       => (int) $row['id'],
					'settings' => $settings,
				);
			}
		}
		return $feeds;
	}

	/**
	 * Reads the texts of a form through the filters, as a page that shows it and the sending of it
	 * do: its fields, the message after sending and the emails to the visitor.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function readTexts( int $form_id ): void {
		$fields = $this->formFields( $form_id );
		if ( null !== $fields ) {
			$this->filterForm(
				(object) array(
					'id'     => $form_id,
					'fields' => $fields,
				)
			);
		}
		$settings = $this->formSettings( $form_id );
		if ( isset( $settings['confirmation'] ) && is_array( $settings['confirmation'] ) ) {
			$this->translateConfirmation( $form_id, $settings['confirmation'] );
		}
		foreach ( $this->notificationFeeds( $form_id ) as $feed ) {
			if ( self::mailsTheVisitor( $feed['settings'] ) ) {
				$this->translateMail( $form_id, $feed['id'], $feed['settings'] );
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
			array( '[fluentform id="' . $form_id . '"' ),
			array( "[fluentform id='" . $form_id . "'" ),
			array( '[fluentform id=' . $form_id . ']' ),
			array( '[fluentform type="conversational" id="' . $form_id . '"' ),
			// The plugin names its block so.
			array( '<!-- wp:fluentfom/guten-block ', '"formId":"' . $form_id . '"' ),
		);
	}

	/**
	 * Whether the current user may edit forms, as the plugin asks it.
	 */
	protected function mayEdit(): bool {
		return class_exists( '\FluentForm\App\Modules\Acl\Acl' ) ? (bool) \FluentForm\App\Modules\Acl\Acl::hasPermission( 'fluentform_forms_manager' ) : current_user_can( 'manage_options' );
	}

	/**
	 * Returns what the editor calls the field a text of a form belongs to, such as "Email › Label".
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
		if ( 'submit' === $parts[0] ) {
			return __( 'Button', 'swiftling' );
		}
		if ( 'confirmation' === $parts[0] ) {
			return __( 'Message after sending', 'swiftling' );
		}
		if ( 'notifications' === $parts[0] && isset( $parts[2] ) ) {
			return $this->mailLabel( $form_id, (int) substr( $parts[1], 1 ), $parts[2] );
		}
		if ( 'fields' !== $parts[0] || count( $parts ) < 3 ) {
			return '';
		}

		$names = array(
			'label'        => __( 'Label', 'swiftling' ),
			'placeholder'  => __( 'Placeholder', 'swiftling' ),
			'help_message' => __( 'Help text', 'swiftling' ),
			'prefix_label' => __( 'Prefix', 'swiftling' ),
			'suffix_label' => __( 'Suffix', 'swiftling' ),
			'tnc_html'     => __( 'Terms', 'swiftling' ),
			'html_codes'   => __( 'Content', 'swiftling' ),
			'description'  => __( 'Description', 'swiftling' ),
			'prev_btn'     => __( 'Previous button', 'swiftling' ),
			'next_btn'     => __( 'Next button', 'swiftling' ),
		);

		// The field is the last name before what is named: "fields/names/first_name/label".
		$what  = array_slice( $parts, 1 );
		$field = array_shift( $what );
		while ( $what && ! isset( $names[ $what[0] ] ) && 'options' !== $what[0] && 'rules' !== $what[0] ) {
			$field = array_shift( $what );
		}
		$tail = $what;

		$title = $this->titleOf( $form_id, $field );
		if ( ! $tail ) {
			return $title;
		}
		if ( 'options' === $tail[0] && isset( $tail[1] ) ) {
			/* translators: %d: number of a choice of a field, counted from one. */
			return $title . ' › ' . sprintf( __( 'Choice %d', 'swiftling' ), (int) substr( $tail[1], 1 ) + 1 );
		}
		if ( 'rules' === $tail[0] && isset( $tail[1] ) ) {
			/* translators: %s: name of a rule a field checks, e.g. required. */
			return $title . ' › ' . sprintf( __( 'Error message: %s', 'swiftling' ), $tail[1] );
		}
		return $title . ' › ' . ( isset( $names[ $tail[0] ] ) ? $names[ $tail[0] ] : $tail[0] );
	}

	/**
	 * Returns what the editor calls a text of an email: the email's name and the text.
	 *
	 * @param int    $form_id Form ID.
	 * @param int    $mail_id ID of the email.
	 * @param string $name    Name of the text in the email's settings.
	 */
	private function mailLabel( int $form_id, int $mail_id, string $name ): string {
		$text = 'subject' === $name ? __( 'Subject of the email to the visitor', 'swiftling' ) : __( 'Text of the email to the visitor', 'swiftling' );
		foreach ( $this->notificationFeeds( $form_id ) as $feed ) {
			if ( $feed['id'] === $mail_id && isset( $feed['settings']['name'] ) && '' !== trim( (string) $feed['settings']['name'] ) ) {
				return trim( (string) $feed['settings']['name'] ) . ' › ' . $text;
			}
		}
		return $text;
	}

	/**
	 * Returns how the editor names a field: its label, else its name in the admin, else its name.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $name    Name of the field in the texts.
	 */
	private function titleOf( int $form_id, string $name ): string {
		$fields = $this->formFields( $form_id );
		$found  = null;
		$search = static function ( $items ) use ( &$search, &$found, $name ): void {
			foreach ( is_array( $items ) ? $items : array() as $index => $field ) {
				if ( null !== $found || ! is_array( $field ) ) {
					continue;
				}
				$own = isset( $field['attributes']['name'] ) && '' !== $field['attributes']['name'] ? $field['attributes']['name'] : ( isset( $field['uniqElKey'] ) ? $field['uniqElKey'] : 'i' . $index );
				if ( str_replace( '/', '_', (string) $own ) === $name ) {
					$found = $field;
					return;
				}
				$search( isset( $field['fields'] ) ? $field['fields'] : array() );
				foreach ( isset( $field['columns'] ) && is_array( $field['columns'] ) ? $field['columns'] : array() as $cell ) {
					$search( isset( $cell['fields'] ) ? $cell['fields'] : array() );
				}
			}
		};
		$search( is_array( $fields ) && isset( $fields['fields'] ) ? $fields['fields'] : array() );

		foreach ( array( array( 'settings', 'label' ), array( 'settings', 'admin_field_label' ) ) as $path ) {
			$value = is_array( $found ) ? self::at( $found, $path ) : null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( wp_strip_all_tags( $value ) );
			}
		}
		return $name;
	}
}
