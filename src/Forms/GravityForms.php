<?php
/**
 * Gravity Forms: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms. A form is a row of its own tables, read as one array by
 * GFFormsModel::get_form_meta(), which hands it to the filter gform_form_post_get_meta before it
 * keeps it for the rest of the request. The texts of the form, listed in places(), are translated
 * there, see TableProvider. Because Gravity keeps the form for the request after the filter, the
 * texts are in it translated (or marked, in a page check) wherever the plugin uses the form
 * afterwards: the page, the validation, the confirmation and the emails to the visitor of a form
 * sent from a page in the language. A form sent through Ajax arrives through admin-ajax.php, with
 * the action gform_submit_form, and runs in the language of its page too, see
 * swiftling_sent_form_language().
 *
 * Only the emails to the visitor are texts of the form: sent to a field ("toType" field), or every
 * address a field's merge tag such as {Email:4}. Any other email is the site owner's: its texts
 * stay the original, and the plugin builds and sends it in the original, see SentForms, from
 * gform_notification until it is sent (gform_after_email), the next email, the confirmation
 * (gform_pre_handle_confirmation) or the end of the sending (gform_after_submission). The emails
 * of a form sent in another language are sent at once, not later in a request of the plugin's own
 * that knows no language (gform_is_asynchronous_notifications_enabled). The plugin builds every
 * email from the form it keeps, translated; for the owner's, the fields of that form, objects it
 * shares, hold their original texts again while the email is built, and {form_title} is the
 * original title.
 *
 * A field is an object (GF_Field) that is read and written as an array; it stays the object the
 * plugin made. Not taken: the value of a choice, a CSS class, the name of a field in the admin and
 * the address mail goes to.
 *
 * Checked against the code of Gravity Forms 3.1, in tests/GravityFormsTest.php with a form of the
 * same shape, and by hand on a site with the plugin.
 */
class GravityForms extends TableProvider {
	use SentForms;

	/**
	 * Of each form translated in this request: its original title, and what the translation changed
	 * in its fields, each field the object the plugin shares.
	 *
	 * @var array<int, array{title: string, changes: array<int, array{field: object, path: array, original: string, translated: string}>}>
	 */
	private $originals = array();

	/**
	 * The form whose fields hold their original texts for the owner's email being built, or 0.
	 *
	 * @var int
	 */
	private $owners_form = 0;

	/**
	 * The texts of a field, by their name in the form.
	 */
	const FIELD_TEXTS = array( 'label', 'description', 'placeholder', 'errorMessage', 'content', 'checkboxLabel' );

	/**
	 * The texts of the form itself, by their name in the form.
	 */
	const FORM_TEXTS = array( 'title', 'description', 'limitEntriesMessage', 'scheduleMessage', 'requireLoginMessage' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'gravityforms';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Gravity Forms';
	}

	/**
	 * Whether the plugin is on the site: its main class is declared while its files load.
	 */
	public function isActive(): bool {
		return class_exists( 'GFForms' );
	}

	/**
	 * Hooks the filter that takes the texts of a form in and out of its translation.
	 */
	public function register(): void {
		add_filter( 'gform_form_post_get_meta', array( $this, 'filterForm' ) );
		add_filter( 'gform_notification', array( $this, 'startNotification' ), 5, 3 );
		add_action( 'gform_after_email', array( $this, 'endEmail' ), PHP_INT_MIN );
		add_action( 'gform_pre_handle_confirmation', array( $this, 'endEmail' ), PHP_INT_MIN );
		add_action( 'gform_after_submission', array( $this, 'endEmail' ), PHP_INT_MIN );
		add_filter( 'gform_is_asynchronous_notifications_enabled', array( $this, 'sendsLater' ) );
	}

	/**
	 * Whether the plugin sends the emails of a form sent later, in a request of its own (the default
	 * since Gravity Forms 2.10): not from a page in another language, as that request would not know
	 * the language.
	 *
	 * @param mixed $later Whether the plugin sends them later.
	 * @return mixed
	 */
	public function sendsLater( $later ) {
		return 'translate' === swiftling_mode() ? false : $later;
	}

	/**
	 * Returns the action of admin-ajax.php with which the plugin receives a form sent through Ajax.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'gform_submit_form' );
	}

	/**
	 * Before the plugin builds an email of a form sent: one to the site's owner is built and sent in
	 * the original, until endEmail().
	 *
	 * @param mixed $notification The email, from the form.
	 * @param mixed $form         The form.
	 * @param mixed $entry        The entry.
	 * @return mixed
	 */
	public function startNotification( $notification, $form = array(), $entry = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The plugin passes the entry too.
		$this->endEmail();
		if ( 'translate' !== swiftling_mode() || ! is_array( $notification ) || self::mailsTheVisitor( $notification ) ) {
			return $notification;
		}
		$this->startOwnerMail();
		$form_id = is_array( $form ) && isset( $form['id'] ) ? (int) $form['id'] : 0;
		if ( ! isset( $this->originals[ $form_id ] ) ) {
			return $notification;
		}
		// The plugin builds the email from the translated form it keeps: its fields hold their
		// original texts again until endEmail(), and its title, a value of the form alone, is put in.
		$this->owners_form = $form_id;
		$this->putTexts( $form_id, 'original' );
		$title = $this->originals[ $form_id ]['title'];
		foreach ( array( 'subject', 'message' ) as $name ) {
			if ( '' !== $title && isset( $notification[ $name ] ) && is_string( $notification[ $name ] ) ) {
				$notification[ $name ] = str_replace( '{form_title}', $title, $notification[ $name ] );
			}
		}
		return $notification;
	}

	/**
	 * After an email of a form sent, or once the plugin goes on to the confirmation or ends the
	 * sending: an email to the site's owner is done.
	 */
	public function endEmail(): void {
		if ( $this->owners_form ) {
			$this->putTexts( $this->owners_form, 'translated' );
			$this->owners_form = 0;
		}
		$this->endOwnerMails();
	}

	/**
	 * Whether an email of a form writes to the visitor: sent to a field, or every address it sends
	 * to is a field's merge tag, as {Email:4}, or the signed-in visitor's {user:user_email}. The
	 * site's address, {admin_email}, a fixed address and routing are the site owner's.
	 *
	 * @param array $notification The email, from the form.
	 */
	private static function mailsTheVisitor( array $notification ): bool {
		$type = isset( $notification['toType'] ) ? (string) $notification['toType'] : 'email';
		if ( 'field' === $type ) {
			return true;
		}
		return 'email' === $type && self::onlyVisitors(
			isset( $notification['to'] ) && is_string( $notification['to'] ) ? $notification['to'] : '',
			static function ( string $recipient ): bool {
				return 1 === preg_match( '/^\{([^{}:]*:\d+(\.\d+)?(:[^{}]*)?|user:user_email)\}$/', $recipient );
			}
		);
	}

	/**
	 * Translates the texts of a form the plugin has read, see TableProvider::translate().
	 *
	 * @param mixed $form The form.
	 * @return mixed The form, with its texts changed, or as it was.
	 */
	public function filterForm( $form ) {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return $form;
		}
		$places = $this->places( $form );
		// Read before the translation, which writes into the field objects themselves.
		$before     = 'translate' === swiftling_mode() ? self::textsAt( $form, $places ) : array();
		$translated = $this->translate( (int) $form['id'], $form, $places );
		if ( 'translate' === swiftling_mode() ) {
			$this->keepOriginals( (int) $form['id'], $form, $before, $translated, $places );
		}
		return $translated;
	}

	/**
	 * Returns the texts of a form at its places, as they are now.
	 *
	 * @param array                                 $form   The form.
	 * @param array<string, array<int, int|string>> $places Where each text is.
	 * @return array<string, mixed>
	 */
	private static function textsAt( array $form, array $places ): array {
		$texts = array();
		foreach ( $places as $key => $path ) {
			$texts[ $key ] = self::at( $form, $path );
		}
		return $texts;
	}

	/**
	 * Keeps what the translation changed in the fields of a form, and its original title, so the
	 * email to the site's owner can be built from the original, see startNotification(). The fields
	 * are objects the translated form shares with every later use of it in the request.
	 *
	 * @param int                                   $form_id    Form ID.
	 * @param array                                 $original   The form as read.
	 * @param array<string, mixed>                  $before     Its texts before the translation.
	 * @param array                                 $translated The form translated.
	 * @param array<string, array<int, int|string>> $places     Where each text is.
	 */
	private function keepOriginals( int $form_id, array $original, array $before, array $translated, array $places ): void {
		$changes = array();
		foreach ( $places as $key => $path ) {
			if ( 'fields' !== $path[0] || ! isset( $path[1], $translated['fields'][ $path[1] ] ) || ! is_object( $translated['fields'][ $path[1] ] ) ) {
				continue;
			}
			$after = self::at( $translated, $path );
			if ( isset( $before[ $key ] ) && is_string( $before[ $key ] ) && is_string( $after ) && $before[ $key ] !== $after ) {
				$changes[] = array(
					'field'      => $translated['fields'][ $path[1] ],
					'path'       => array_slice( $path, 2 ),
					'original'   => $before[ $key ],
					'translated' => $after,
				);
			}
		}
		$this->originals[ $form_id ] = array(
			'title'   => isset( $original['title'] ) && is_string( $original['title'] ) ? $original['title'] : '',
			'changes' => $changes,
		);
	}

	/**
	 * Puts the original or the translated texts in the fields of a form the translation changed.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $which   original or translated.
	 */
	private function putTexts( int $form_id, string $which ): void {
		foreach ( isset( $this->originals[ $form_id ] ) ? $this->originals[ $form_id ]['changes'] : array() as $change ) {
			$field = $change['field'];
			self::setAt( $field, $change['path'], $change[ $which ] );
		}
	}

	/**
	 * Returns where each text of a form is: its name in the texts, "fields/field_3/label", mapped to
	 * the keys that lead to it in the form.
	 *
	 * @param array $form Form.
	 * @return array<string, array<int, int|string>>
	 */
	private function places( array $form ): array {
		$places = array();
		$add    = static function ( array $path, string $key ) use ( &$places, $form ): void {
			self::addPlace( $places, $form, $path, $key );
		};

		foreach ( self::FORM_TEXTS as $name ) {
			$add( array( $name ), $name );
		}
		$add( array( 'button', 'text' ), 'button/text' );
		$add( array( 'pagination', 'progressbar_completion_text' ), 'pagination/completion' );
		$pages = self::at( $form, array( 'pagination', 'pages' ) );
		foreach ( is_array( $pages ) ? array_keys( $pages ) : array() as $index ) {
			$add( array( 'pagination', 'pages', $index ), 'pagination/page_' . $index );
		}

		$fields = self::at( $form, array( 'fields' ) );
		foreach ( is_array( $fields ) ? $fields : array() as $index => $field ) {
			$id = 'fields/field_' . ( isset( $field['id'] ) ? $field['id'] : $index );
			foreach ( self::FIELD_TEXTS as $name ) {
				$add( array( 'fields', $index, $name ), $id . '/' . $name );
			}
			foreach ( array( 'nextButton', 'previousButton' ) as $name ) {
				$add( array( 'fields', $index, $name, 'text' ), $id . '/' . $name . '/text' );
			}
			foreach ( array(
				'choices' => array( 'text' ),
				'inputs'  => array( 'label', 'customLabel', 'placeholder' ),
			) as $list => $names ) {
				$rows = self::at( $field, array( $list ) );
				foreach ( is_array( $rows ) ? array_keys( $rows ) : array() as $row ) {
					foreach ( $names as $name ) {
						$add( array( 'fields', $index, $list, $row, $name ), $id . '/' . $list . '/' . substr( $list, 0, 1 ) . $row . '/' . $name );
					}
				}
			}
		}

		foreach ( array(
			'confirmations' => array( 'c', array( 'message' ) ),
			'notifications' => array( 'n', array( 'subject', 'message' ) ),
		) as $list => $set ) {
			$rows = self::at( $form, array( $list ) );
			foreach ( is_array( $rows ) ? $rows : array() as $row => $item ) {
				// An email to the site's owner keeps its texts, see startNotification().
				if ( 'notifications' === $list && ( ! is_array( $item ) || ! self::mailsTheVisitor( $item ) ) ) {
					continue;
				}
				foreach ( $set[1] as $name ) {
					$add( array( $list, $row, $name ), $list . '/' . $set[0] . '_' . $row . '/' . $name );
				}
			}
		}
		return $places;
	}

		/**
		 * Returns the forms, inactive ones too.
		 *
		 * @return array<int, array{id: int, title: string}>
		 */
	protected function allForms(): array {
		$forms = array();
		foreach ( (array) \GFFormsModel::get_forms( null ) as $row ) {
			$row     = (array) $row;
			$forms[] = array(
				'id'    => (int) $row['id'],
				'title' => isset( $row['title'] ) ? trim( (string) $row['title'] ) : '',
			);
		}
		return $forms;
	}

	/**
	 * Reads a form as the plugin does, which ends in the filter of this class.
	 *
	 * @param int  $form_id Form ID.
	 * @param bool $fresh   Whether to read it from the database again: the plugin keeps a form it has
	 *                      read for the rest of the request, and a copy kept earlier has not been
	 *                      through the filter in the mode the caller wants.
	 * @return array|null Null when there is no such form.
	 */
	protected function readForm( int $form_id, bool $fresh = false ): ?array {
		if ( $fresh ) {
			\GFFormsModel::flush_current_forms();
		}
		$form = \GFAPI::get_form( $form_id );
		return is_array( $form ) ? $form : null;
	}

	/**
	 * Reads the texts of a form through the filter, then forgets the copy, which holds the markers
	 * of the page check, so the rest of the request reads the form again.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function readTexts( int $form_id ): void {
		$this->readForm( $form_id, true );
		if ( class_exists( 'GFFormsModel' ) ) {
			\GFFormsModel::flush_current_forms();
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
			array( '[gravityform id="' . $form_id . '"' ),
			array( '[gravityforms id="' . $form_id . '"' ),
			array( '<!-- wp:gravityforms/form ', '"formId":"' . $form_id . '"' ),
		);
	}

	/**
	 * Whether the current user may edit forms. Gravity Forms does not grant that as a plain
	 * capability: an administrator holds "gform_full_access" and the plugin asks for either, see
	 * GFCommon::current_user_can_any(), so it is asked the same way.
	 */
	protected function mayEdit(): bool {
		return class_exists( 'GFCommon' ) ? (bool) \GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) : current_user_can( 'manage_options' );
	}

	/**
	 * Returns what the editor calls the field a text of a form belongs to, such as
	 * "Your name › Label" or "Notification: Admin › Subject".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is not a text of one of this plugin's forms.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		$id = $this->formOf( $owner );
		if ( ! $id || 0 !== strpos( $path, self::BASE . '/' ) ) {
			return '';
		}
		$parts = explode( '/', substr( $path, strlen( self::BASE ) + 1 ) );
		$form  = $this->readForm( $id );

		$names = array(
			'title'               => __( 'Title', 'swiftling' ),
			'description'         => __( 'Description', 'swiftling' ),
			'limitEntriesMessage' => __( 'Message when the limit of entries is reached', 'swiftling' ),
			'scheduleMessage'     => __( 'Message outside the schedule', 'swiftling' ),
			'requireLoginMessage' => __( 'Message for visitors who are not logged in', 'swiftling' ),
			'button'              => __( 'Button', 'swiftling' ),
			'label'               => __( 'Label', 'swiftling' ),
			'placeholder'         => __( 'Placeholder', 'swiftling' ),
			'errorMessage'        => __( 'Error message', 'swiftling' ),
			'content'             => __( 'Content', 'swiftling' ),
			'checkboxLabel'       => __( 'Checkbox label', 'swiftling' ),
			'nextButton'          => __( 'Next button', 'swiftling' ),
			'previousButton'      => __( 'Previous button', 'swiftling' ),
			'subject'             => __( 'Subject', 'swiftling' ),
			'message'             => __( 'Message', 'swiftling' ),
		);

		$head = array_shift( $parts );
		$last = (string) end( $parts );
		if ( 'button' === $head ) {
			return $names['button'];
		}
		if ( 'pagination' === $head ) {
			return 'completion' === $parts[0] ? __( 'Progress bar › Completion text', 'swiftling' ) : sprintf( /* translators: %d: number of a page of the form. */ __( 'Page %d title', 'swiftling' ), (int) substr( $parts[0], 5 ) + 1 );
		}
		if ( 'fields' === $head && count( $parts ) >= 2 ) {
			$field_id = (int) substr( $parts[0], 6 );
			$title    = sprintf( /* translators: %d: ID of a field of a form. */ __( 'Field %d', 'swiftling' ), $field_id );
			foreach ( is_array( $form ) && isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array() as $field ) {
				if ( isset( $field['id'] ) && (int) $field['id'] === $field_id && isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ) {
					$title = (string) $field['label'];
				}
			}
			if ( isset( $parts[1] ) && ( 'choices' === $parts[1] || 'inputs' === $parts[1] ) && isset( $parts[2] ) ) {
				$number = (int) substr( $parts[2], 1 ) + 1;
				if ( 'choices' === $parts[1] ) {
					/* translators: %d: number of a choice of a field, counted from one. */
					return $title . ' › ' . sprintf( __( 'Choice %d', 'swiftling' ), $number );
				}
				/* translators: %d: number of a part of a field, such as the first and the last name, counted from one. */
				$sub = 'placeholder' === $last ? __( 'Sub-placeholder %d', 'swiftling' ) : __( 'Sub-label %d', 'swiftling' );
				return $title . ' › ' . sprintf( $sub, $number );
			}
			$what = 'nextButton' === $parts[1] || 'previousButton' === $parts[1] ? $names[ $parts[1] ] : ( isset( $names[ $last ] ) ? $names[ $last ] : $last );
			return $title . ' › ' . $what;
		}
		if ( ( 'confirmations' === $head || 'notifications' === $head ) && count( $parts ) >= 2 ) {
			$id_part = substr( $parts[0], 2 );
			$rows    = is_array( $form ) && isset( $form[ $head ] ) && is_array( $form[ $head ] ) ? $form[ $head ] : array();
			$name    = isset( $rows[ $id_part ]['name'] ) ? (string) $rows[ $id_part ]['name'] : $id_part;
			if ( 'confirmations' === $head ) {
				/* translators: %s: name of a confirmation of a form. */
				return sprintf( __( 'Confirmation: %s', 'swiftling' ), $name );
			}
			$texts = array(
				'subject' => __( 'Subject of the email to the visitor', 'swiftling' ),
				'message' => __( 'Text of the email to the visitor', 'swiftling' ),
			);
			return $name . ' › ' . ( isset( $texts[ $last ] ) ? $texts[ $last ] : $last );
		}
		return isset( $names[ $head ] ) ? $names[ $head ] : '';
	}
}
