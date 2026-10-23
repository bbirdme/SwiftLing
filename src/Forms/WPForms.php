<?php
/**
 * WPForms: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * WPForms. A form is a post of the type "wpforms", but everything of it is JSON in the content of
 * that post, which the plugin decodes itself, so it passes through no filter SwiftLing watches.
 * While it shows a form, the plugin hands the decoded form to the filter
 * wpforms_frontend_form_data; the texts of the form are translated there, see TableProvider.
 *
 * What a visitor sends is not changed. A choice without a value of its own sends its label, and the
 * plugin accepts only a label of the form when the form arrives, through admin-ajax.php, a request
 * that knows no language, so it compares with the original. On a page in another language such a
 * field sends the original label as its value, with show_values on, while it shows the translated
 * one; a choice with a value of its own keeps it. The entry is then in the original language too.
 *
 * A form sent arrives through admin-ajax.php, with the action wpforms_submit, or at the page it is
 * on, and runs in the language of that page, see swiftling_sent_form_language(). The plugin hands
 * the form to wpforms_process_before_form_data, where the message after sending (a confirmation of
 * the type "message") and the emails to the visitor are translated; not the fields, which the
 * entry is saved with and the choices sent are checked against. An email whose every recipient is
 * the visitor's, a field's tag such as {field_id="4"} or the signed-in visitor's {user_email},
 * is the visitor's; any other is the site owner's and is built and sent in the original, see
 * SentForms, from wpforms_entry_email_process until the next email or wpforms_process_complete.
 * The errors the plugin finds are its own texts, in the language through the locale.
 *
 * An email the plugin is set to send later in the background ("Optimize Email Sending") is built
 * in this request all the same, and only sent later.
 *
 * Not taken: what a program reads (sizes, CSS classes, values) and the notes only the builder of
 * the form shows (an "internal information" field).
 *
 * Checked against the code of WPForms Lite 2.0, in tests/WPFormsTest.php with forms of the same
 * shape, and by hand on a site with the plugin.
 */
class WPForms extends TableProvider {
	use SentForms;

	/**
	 * The texts of a field, by their name in it.
	 */
	const FIELD_TEXTS = array( 'label', 'description', 'placeholder', 'first_placeholder', 'middle_placeholder', 'last_placeholder', 'confirmation_placeholder', 'other_placeholder', 'value_display', 'code', 'content', 'title', 'next', 'prev' );

	/**
	 * The texts of the form itself, by their name in its settings.
	 */
	const FORM_TEXTS = array( 'form_title', 'form_desc', 'submit_text', 'submit_text_processing' );

	/**
	 * The texts of an email of a form, by their name in its settings.
	 */
	const MAIL_TEXTS = array( 'subject', 'message' );

	/**
	 * Fields only the builder of the form shows.
	 */
	const BUILDER_ONLY = array( 'internal-information' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wpforms';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'WPForms';
	}

	/**
	 * Whether the plugin is on the site: its main file declares its version while it loads.
	 */
	public function isActive(): bool {
		return defined( 'WPFORMS_VERSION' );
	}

	/**
	 * Returns the action of admin-ajax.php with which the plugin receives a form, see
	 * WPForms_Process::ajax_submit().
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'wpforms_submit' );
	}

	/**
	 * Hooks the filter where the plugin hands over a form it is about to show, and those where it
	 * handles a form sent.
	 */
	public function register(): void {
		add_filter( 'wpforms_frontend_form_data', array( $this, 'filterFormData' ) );
		// After the plugin's own class has drawn the field, at priority 5.
		add_filter( 'wpforms_field_properties_gdpr-checkbox', array( $this, 'keepConsentValue' ), 20, 2 );
		add_filter( 'wpforms_process_before_form_data', array( $this, 'filterSentForm' ), 10, 2 );
		add_filter( 'wpforms_entry_email_process', array( $this, 'startNotification' ), 5, 4 );
		add_action( 'wpforms_process_complete', array( $this, 'endSending' ), PHP_INT_MIN );
	}

	/**
	 * Translates the answer to a form sent from a page in another language: the message after
	 * sending and the emails to the visitor. Not the fields, which the entry is saved with.
	 *
	 * @param mixed $form_data The decoded form.
	 * @param mixed $entry     What the visitor sent.
	 * @return mixed
	 */
	public function filterSentForm( $form_data, $entry = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The plugin passes the entry too.
		if ( 'translate' !== swiftling_mode() || ! is_array( $form_data ) || empty( $form_data['id'] ) ) {
			return $form_data;
		}
		return $this->translateAnswer( $form_data );
	}

	/**
	 * Translates the message after sending and the emails to the visitor of a form.
	 *
	 * @param array $form_data The decoded form.
	 */
	private function translateAnswer( array $form_data ): array {
		$places        = array();
		$confirmations = self::at( $form_data, array( 'settings', 'confirmations' ) );
		foreach ( is_array( $confirmations ) ? $confirmations : array() as $id => $confirmation ) {
			if ( is_array( $confirmation ) && ( ! isset( $confirmation['type'] ) || 'message' === $confirmation['type'] ) ) {
				self::addPlace( $places, $form_data, array( 'settings', 'confirmations', $id, 'message' ), 'confirmations/c' . $id . '/message' );
			}
		}
		$notifications = self::at( $form_data, array( 'settings', 'notifications' ) );
		foreach ( is_array( $notifications ) ? $notifications : array() as $id => $notification ) {
			if ( is_array( $notification ) && self::mailsTheVisitor( $notification ) ) {
				foreach ( self::MAIL_TEXTS as $name ) {
					self::addPlace( $places, $form_data, array( 'settings', 'notifications', $id, $name ), 'notifications/n' . $id . '/' . $name );
				}
			}
		}
		return $this->translate( (int) $form_data['id'], $form_data, $places );
	}

	/**
	 * Before the plugin builds an email of a form sent: an email to the site's owner is built and
	 * sent in the original, until the next email or the end of the sending. Whether the email is
	 * sent stays as the plugin and other code decided.
	 *
	 * @param mixed      $process         Whether the email is sent.
	 * @param mixed      $fields          The fields sent.
	 * @param mixed      $form_data       The form.
	 * @param int|string $notification_id ID of the email in the form's settings.
	 * @return mixed
	 */
	public function startNotification( $process, $fields = array(), $form_data = array(), $notification_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The plugin passes the fields before the form.
		$this->endOwnerMails();
		$notification = is_array( $form_data ) ? self::at( $form_data, array( 'settings', 'notifications', $notification_id ) ) : null;
		if ( $process && is_array( $notification ) && ! self::mailsTheVisitor( $notification ) ) {
			$this->startOwnerMail();
		}
		return $process;
	}

	/**
	 * Ends the sending of a form: an email to the site's owner sent last is done.
	 */
	public function endSending(): void {
		$this->endOwnerMails();
	}

	/**
	 * Whether an email of a form writes to the visitor: every address it sends to is a field's tag,
	 * as {field_id="4"}, or the signed-in visitor's {user_email}. The site's address, {admin_email},
	 * and a fixed address are the site owner's.
	 *
	 * @param array $notification Settings of the email.
	 */
	private static function mailsTheVisitor( array $notification ): bool {
		return self::onlyVisitors(
			isset( $notification['email'] ) && is_string( $notification['email'] ) ? $notification['email'] : '',
			static function ( string $recipient ): bool {
				return 1 === preg_match( '/^\{(field_id="\d+"|user_email)\}$/', $recipient );
			}
		);
	}

	/**
	 * Translates the texts of a form, see TableProvider::translate(), and keeps what its choices
	 * send in the original.
	 *
	 * @param mixed $form_data The decoded form.
	 * @return mixed The form, with its texts changed, or as it was.
	 */
	public function filterFormData( $form_data ) {
		if ( ! is_array( $form_data ) || empty( $form_data['id'] ) ) {
			return $form_data;
		}
		if ( 'translate' === swiftling_mode() ) {
			$form_data = $this->keepSentValues( $form_data );
		}
		return $this->translate( (int) $form_data['id'], $form_data, $this->places( $form_data ) );
	}

	/**
	 * Makes the choices without a value of their own send their original label: show_values on, and
	 * the label as the value, before the label is translated.
	 *
	 * @param array $form_data Form.
	 * @return array
	 */
	private function keepSentValues( array $form_data ): array {
		$fields = isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ? $form_data['fields'] : array();
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) || empty( $field['choices'] ) || ! is_array( $field['choices'] ) || ! empty( $field['show_values'] ) ) {
				continue;
			}
			foreach ( $field['choices'] as $row => $choice ) {
				if ( is_array( $choice ) && ( ! isset( $choice['value'] ) || '' === (string) $choice['value'] ) && isset( $choice['label'] ) ) {
					$form_data['fields'][ $key ]['choices'][ $row ]['value'] = (string) $choice['label'];
				}
			}
			$form_data['fields'][ $key ]['show_values'] = '1';
		}
		return $form_data;
	}

	/**
	 * Gives the box of a consent the original consent as its value. The plugin draws that box from
	 * the label alone, whatever show_values says, so on a page in another language it would send the
	 * translated consent, which the plugin refuses as an option it does not know: a form with a
	 * required consent could not be sent. keepSentValues() has kept the original as the value of the
	 * choice; the label shows the translation.
	 *
	 * @param mixed $properties Properties of the field, as the plugin's class made them.
	 * @param mixed $field      The field.
	 * @return mixed
	 */
	public function keepConsentValue( $properties, $field ) {
		if ( 'translate' !== swiftling_mode() || ! is_array( $properties ) || empty( $properties['inputs'] ) || ! is_array( $field ) || empty( $field['choices'] ) ) {
			return $properties;
		}
		foreach ( $properties['inputs'] as $key => $input ) {
			if ( isset( $input['attr'], $field['choices'][ $key ]['value'] ) && '' !== (string) $field['choices'][ $key ]['value'] ) {
				$properties['inputs'][ $key ]['attr']['value'] = (string) $field['choices'][ $key ]['value'];
			}
		}
		return $properties;
	}

	/**
	 * Returns where each text of a form is: its name in the texts, "fields/field_3/label", mapped to
	 * the keys that lead to it in the form.
	 *
	 * @param array $form_data Form.
	 * @return array<string, array<int, int|string>>
	 */
	private function places( array $form_data ): array {
		$places = array();
		foreach ( self::FORM_TEXTS as $name ) {
			self::addPlace( $places, $form_data, array( 'settings', $name ), $name );
		}
		$fields = isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ? $form_data['fields'] : array();
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) || in_array( isset( $field['type'] ) ? $field['type'] : '', self::BUILDER_ONLY, true ) ) {
				continue;
			}
			$id = 'fields/field_' . ( isset( $field['id'] ) ? (int) $field['id'] : (int) $key );
			foreach ( self::FIELD_TEXTS as $name ) {
				self::addPlace( $places, $form_data, array( 'fields', $key, $name ), $id . '/' . $name );
			}
			$choices = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();
			foreach ( array_keys( $choices ) as $row ) {
				self::addPlace( $places, $form_data, array( 'fields', $key, 'choices', $row, 'label' ), $id . '/choices/c' . $row );
			}
		}
		return $places;
	}

	/**
	 * Returns the forms, drafts too.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	protected function allForms(): array {
		$forms = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'wpforms',
				'post_status'    => array( 'publish', 'draft' ),
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- The forms of the Forms screen; the cap keeps a very large site from a list without end.
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) as $post ) {
			$forms[] = array(
				'id'    => (int) $post->ID,
				'title' => trim( (string) $post->post_title ),
			);
		}
		return $forms;
	}

	/**
	 * Returns a form, decoded, as the plugin decodes it.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null Null when there is no such form.
	 */
	protected function formData( int $form_id ): ?array {
		$post = get_post( $form_id );
		if ( ! $post || 'wpforms' !== $post->post_type ) {
			return null;
		}
		$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : json_decode( $post->post_content, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Reads the texts of a form through the filters, as a page that shows it and the sending of it
	 * do: the form, the message after sending and the emails to the visitor.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function readTexts( int $form_id ): void {
		$data = $this->formData( $form_id );
		if ( null !== $data ) {
			$this->filterFormData( $data );
			$this->translateAnswer( $data );
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
			array( '[wpforms id="' . $form_id . '"' ),
			array( "[wpforms id='" . $form_id . "'" ),
			array( '[wpforms id=' . $form_id . ']' ),
			array( '<!-- wp:wpforms/form-selector ', '"formId":"' . $form_id . '"' ),
		);
	}

	/**
	 * Whether the current user may edit forms, as the plugin asks it.
	 */
	protected function mayEdit(): bool {
		return function_exists( 'wpforms_current_user_can' ) ? (bool) wpforms_current_user_can( 'edit_forms' ) : current_user_can( 'manage_options' );
	}

	/**
	 * Returns what the editor calls the field a text of a form belongs to, such as "Make › Label".
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
			'form_title'               => __( 'Title', 'swiftling' ),
			'form_desc'                => __( 'Description', 'swiftling' ),
			'submit_text'              => __( 'Button', 'swiftling' ),
			'submit_text_processing'   => __( 'Button while sending', 'swiftling' ),
			'label'                    => __( 'Label', 'swiftling' ),
			'description'              => __( 'Description', 'swiftling' ),
			'placeholder'              => __( 'Placeholder', 'swiftling' ),
			'first_placeholder'        => __( 'First name placeholder', 'swiftling' ),
			'middle_placeholder'       => __( 'Middle name placeholder', 'swiftling' ),
			'last_placeholder'         => __( 'Last name placeholder', 'swiftling' ),
			'confirmation_placeholder' => __( 'Confirmation placeholder', 'swiftling' ),
			'other_placeholder'        => __( 'Other placeholder', 'swiftling' ),
			'value_display'            => __( 'Value shown', 'swiftling' ),
			'code'                     => __( 'Content', 'swiftling' ),
			'content'                  => __( 'Content', 'swiftling' ),
			'title'                    => __( 'Title', 'swiftling' ),
			'next'                     => __( 'Next button', 'swiftling' ),
			'prev'                     => __( 'Previous button', 'swiftling' ),
		);
		$data  = $this->formData( $form_id );
		if ( 'confirmations' === $parts[0] ) {
			return __( 'Message after sending', 'swiftling' );
		}
		if ( 'notifications' === $parts[0] && isset( $parts[2] ) ) {
			$name  = self::at( $data, array( 'settings', 'notifications', (int) substr( $parts[1], 1 ), 'notification_name' ) );
			$texts = array(
				'subject' => __( 'Subject of the email to the visitor', 'swiftling' ),
				'message' => __( 'Text of the email to the visitor', 'swiftling' ),
			);
			$text  = isset( $texts[ $parts[2] ] ) ? $texts[ $parts[2] ] : $parts[2];
			return is_string( $name ) && '' !== trim( $name ) ? trim( $name ) . ' › ' . $text : $text;
		}
		if ( 'fields' !== $parts[0] || count( $parts ) < 3 ) {
			return isset( $names[ $parts[0] ] ) ? $names[ $parts[0] ] : '';
		}

		$field_id = (int) substr( $parts[1], 6 );
		$field    = is_array( $data ) && isset( $data['fields'][ $field_id ] ) && is_array( $data['fields'][ $field_id ] ) ? $data['fields'][ $field_id ] : array();
		/* translators: %d: ID of a field of a form. */
		$title = isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? (string) $field['label'] : sprintf( __( 'Field %d', 'swiftling' ), $field_id );
		if ( 'choices' === $parts[2] && isset( $parts[3] ) ) {
			$number = array_search( (int) substr( $parts[3], 1 ), array_map( 'intval', array_keys( isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array() ) ), true );
			/* translators: %d: number of a choice of a field, counted from one. */
			return $title . ' › ' . sprintf( __( 'Choice %d', 'swiftling' ), false === $number ? (int) substr( $parts[3], 1 ) : $number + 1 );
		}
		return $title . ' › ' . ( isset( $names[ $parts[2] ] ) ? $names[ $parts[2] ] : $parts[2] );
	}
}
