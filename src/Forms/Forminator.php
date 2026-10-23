<?php
/**
 * Forminator: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Forminator. Its forms are posts of the type "forminator_forms" and everything of a form is in
 * one meta key, "forminator_form_meta": the labels of the fields and of their choices, the name of
 * the form, the text of the button, the message after sending and the labels and subjects of the
 * mails it sends.
 *
 * The plugin reads that key through get_post_meta(), so the meta filter of includes/sources.php
 * sees every value in it. The same key also holds the look of the form, and those values read like
 * text: the name of a font is "Roboto", a theme is "Default", a background sits at "center
 * center". holdsNoText() keeps them out, of the editor and of a page check alike, so no marker
 * ends up in a font name while a page with the form is checked.
 *
 * A form sent arrives through admin-ajax.php, with the action forminator_submit_form_custom-forms,
 * and runs in the language of the page it was sent from, see swiftling_sent_form_language(): the
 * plugin loads the form translated, with its message after sending and its errors. An email whose
 * every recipient is a field's tag, as {email-1}, is the visitor's and keeps its translation. Any
 * other email is the site owner's: the editor does not offer its texts, and the plugin builds and
 * sends it in the original, see SentForms, from forminator_form_get_admin_email_recipients, which
 * it calls first for each email, until it is sent (forminator_custom_form_mail_admin_sent), the
 * next email or the end of the emails. Meanwhile the fields of the loaded form, objects the plugin
 * builds {all_fields} from, hold their original texts.
 *
 * Checked against the code of Forminator 1.58, in tests/FormsTest.php with posts of the same
 * shape, and by hand on a site with the plugin.
 */
final class Forminator extends Provider {
	use SentForms;

	/**
	 * The fields whose texts are the original for the owner's email being built: each field and its
	 * data before.
	 *
	 * @var array<int, array{field: object, raw: array}>
	 */
	private $original_fields = array();

	/**
	 * The meta key that holds a whole form.
	 */
	const META = 'forminator_form_meta';

	/**
	 * First words of the settings of the look, written in camel case, as the block that shows a
	 * form saves them: themeName, backgroundPosition. The texts of a form are named in snake or
	 * kebab case, as "field_label" and "thankyou-message", so a name in camel case that starts
	 * with one of these words is a setting, whatever its value reads like.
	 */
	const LOOKS = array( 'theme', 'template', 'font', 'background', 'border', 'padding', 'margin', 'color', 'colour', 'align', 'spacing', 'shadow', 'radius', 'preset', 'style', 'image', 'icon' );

	/**
	 * Returns the provider's ID.
	 */
	public function id(): string {
		return 'forminator';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Forminator';
	}

	/**
	 * Returns the post type the forms are.
	 */
	public function postType(): string {
		return 'forminator_forms';
	}

	/**
	 * Returns what the plugin's files declare while they load.
	 */
	protected function markers(): array {
		return array( 'FORMINATOR_VERSION', 'Forminator_API' );
	}

	/**
	 * Returns the meta key that holds the texts of a form.
	 */
	protected function metaKeys(): array {
		return array( self::META );
	}

	/**
	 * Returns what the editor calls that key: nothing of its own, so a text is named after the part
	 * of the form it sits in, such as "Fields › name-1 › Field label".
	 */
	protected function metaLabels(): array {
		return array( self::META => '' );
	}

	/**
	 * Returns the shortcode a page holds where it shows a form.
	 */
	protected function shortcodeTag(): string {
		return 'forminator_form';
	}

	/**
	 * Returns the action of admin-ajax.php with which the plugin receives a form.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'forminator_submit_form_custom-forms' );
	}

	/**
	 * Hooks where the plugin builds and sends the emails of a form sent.
	 */
	public function register(): void {
		add_filter( 'forminator_form_get_admin_email_recipients', array( $this, 'startNotification' ), 10, 4 );
		add_action( 'forminator_custom_form_mail_admin_sent', array( $this, 'endNotification' ) );
		add_action( 'forminator_custom_form_mail_after_send_mail', array( $this, 'endNotification' ) );
	}

	/**
	 * Before the plugin builds an email of a form sent: one to the site's owner is built and sent in
	 * the original, its fields holding their original texts, until endNotification(). The
	 * recipients stay as the plugin found them.
	 *
	 * @param mixed $recipients   The addresses the email goes to.
	 * @param mixed $notification The email, from the form.
	 * @param mixed $data         What the visitor sent.
	 * @param mixed $module       The form, as the plugin loaded it.
	 * @return mixed
	 */
	public function startNotification( $recipients, $notification = array(), $data = array(), $module = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The plugin passes what was sent before the form.
		$this->endNotification();
		if ( 'translate' !== swiftling_mode() || ! is_array( $notification ) || self::mailsTheVisitor( $notification ) ) {
			return $recipients;
		}
		$this->startOwnerMail();
		if ( is_object( $module ) && ! empty( $module->id ) && isset( $module->fields ) && is_array( $module->fields ) ) {
			$this->putOriginalFields( (int) $module->id, $module->fields );
		}
		return $recipients;
	}

	/**
	 * After an email of a form sent, or at the end of the emails: the fields hold their translations
	 * again, and an email to the site's owner is done.
	 */
	public function endNotification(): void {
		foreach ( $this->original_fields as $kept ) {
			$kept['field']->raw = $kept['raw'];
		}
		$this->original_fields = array();
		$this->endOwnerMails();
	}

	/**
	 * Gives the fields of a loaded form their original texts, from the form as stored, and keeps
	 * their data before, for endNotification(). A field keeps its data in raw, by the same names.
	 *
	 * @param int   $form_id Post ID of the form.
	 * @param array $fields  The fields, as the plugin's objects.
	 */
	private function putOriginalFields( int $form_id, array $fields ): void {
		$meta      = self::inOriginal(
			static function () use ( $form_id ) {
				return get_post_meta( $form_id, self::META, true );
			}
		);
		$originals = array();
		foreach ( is_array( $meta ) && isset( $meta['fields'] ) && is_array( $meta['fields'] ) ? $meta['fields'] : array() as $field ) {
			$key = is_array( $field ) ? self::fieldKey( $field ) : '';
			if ( '' !== $key ) {
				$originals[ $key ] = $field;
			}
		}
		foreach ( $fields as $field ) {
			$raw = is_object( $field ) ? $field->raw : null;
			$key = is_array( $raw ) ? self::fieldKey( $raw ) : '';
			if ( '' === $key || ! isset( $originals[ $key ] ) ) {
				continue;
			}
			$original = $raw;
			foreach ( $originals[ $key ] as $name => $value ) {
				// The texts of the field and of its choices; the rest as the plugin prepared it.
				if ( ( is_string( $value ) || 'options' === $name ) && isset( $raw[ $name ] ) && $raw[ $name ] !== $value ) {
					$original[ $name ] = $value;
				}
			}
			if ( $original !== $raw ) {
				$this->original_fields[] = array(
					'field' => $field,
					'raw'   => $raw,
				);
				$field->raw              = $original;
			}
		}
	}

	/**
	 * Returns the key a field of a form is known by: its element ID, as "email-1".
	 *
	 * @param array $field The field's data.
	 */
	private static function fieldKey( array $field ): string {
		foreach ( array( 'element_id', 'id' ) as $name ) {
			if ( isset( $field[ $name ] ) && is_string( $field[ $name ] ) && '' !== $field[ $name ] ) {
				return $field[ $name ];
			}
		}
		return '';
	}

	/**
	 * Whether an email of a form writes to the visitor: every address it sends to is a field's tag,
	 * as {email-1}. Routing by the answers, the site's address and a fixed address are the site
	 * owner's.
	 *
	 * @param array $notification The email, from the form.
	 */
	private static function mailsTheVisitor( array $notification ): bool {
		if ( isset( $notification['email-recipients'] ) && 'routing' === $notification['email-recipients'] ) {
			return false;
		}
		return self::onlyVisitors(
			isset( $notification['recipients'] ) && is_string( $notification['recipients'] ) ? $notification['recipients'] : '',
			static function ( string $recipient ): bool {
				return 1 === preg_match( '/^\{[a-z]+(?:-[a-z]+)*-\d+\}$/', $recipient );
			}
		);
	}

	/**
	 * Returns the texts of a form for the editor, without those of an email to the site's owner,
	 * which stays in the original.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$meta = get_post_meta( $this->formId( (string) $subject['key'] ), self::META, true );
		$skip = array();
		foreach ( is_array( $meta ) && isset( $meta['notifications'] ) && is_array( $meta['notifications'] ) ? $meta['notifications'] : array() as $index => $notification ) {
			if ( is_array( $notification ) && ! self::mailsTheVisitor( $notification ) ) {
				$skip[] = 'meta/' . self::META . '/notifications/' . $index . '/';
			}
		}
		return array_values(
			array_filter(
				parent::listTexts( $subject ),
				static function ( array $text ) use ( $skip ): bool {
					foreach ( $skip as $prefix ) {
						if ( 0 === strpos( $text['p'], $prefix ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Whether a value of a form never holds text a visitor reads: the name of a font, the template
	 * the form was made from, and the settings of the look of the block that shows it.
	 *
	 * @param string $path Path of the value inside its object.
	 */
	public function holdsNoText( string $path ): bool {
		if ( 0 !== strpos( $path, 'meta/' . self::META . '/' ) ) {
			return false;
		}
		$name = (string) substr( $path, (int) strrpos( $path, '/' ) + 1 );

		return 'template_name' === $name
			|| 1 === preg_match( '/(?:^|[-_])font-family$/', $name )
			|| 1 === preg_match( '/^(?:' . implode( '|', self::LOOKS ) . ')[A-Z]/', $name );
	}
}
