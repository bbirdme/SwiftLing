<?php
/**
 * Contact Form 7: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Form 7. Its forms are posts of the type "wpcf7_contact_form" and their texts are in the
 * meta of those posts, as reported for Contact Form 7 6.2.2:
 *
 * - "_form": the form itself, written in the plugin's own form-tag syntax;
 * - "_messages": what a visitor reads after sending, and every message of a field that is wrong;
 * - "_mail" and "_mail_2": the mails the form sends, with their subject and body.
 *
 * The plugin reads them through get_post_meta(), so the meta filter of includes/sources.php sees
 * every value, both while a page with the form is checked and while a page shows it in another
 * language.
 *
 * A form sent arrives at the REST API, at /contact-form-7/v1/contact-forms/<id>/feedback, and runs
 * in the language of the page it was sent from, see swiftling_sent_form_language(): the plugin
 * reads the form's messages and mails translated. It runs the sending in the locale of the form
 * itself. A mail whose every recipient is the visitor's, a form-tag such as [your-email] or the
 * signed-in visitor's [_user_email], is the visitor's and keeps its translation; any other mail is
 * the site owner's: the editor does not offer its texts, and before the plugin sends it, it gets
 * its original back (wpcf7_before_send_mail for the first mail, wpcf7_additional_mail for the
 * second), also when a translation of it was made.
 *
 * Checked against the code of Contact Form 7 6.2, in tests/FormsTest.php with posts of the same
 * shape, and by hand on a site with the plugin.
 */
final class ContactForm7 extends Provider {
	use SentForms;

	/**
	 * Returns the provider's ID.
	 */
	public function id(): string {
		return 'cf7';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Contact Form 7';
	}

	/**
	 * Returns the post type the forms are.
	 */
	public function postType(): string {
		return 'wpcf7_contact_form';
	}

	/**
	 * Returns what the plugin's files declare while they load.
	 */
	protected function markers(): array {
		return array( 'WPCF7_VERSION', 'WPCF7_ContactForm' );
	}

	/**
	 * Returns the meta keys that hold the texts of a form.
	 */
	protected function metaKeys(): array {
		return array( '_form', '_messages', '_mail', '_mail_2' );
	}

	/**
	 * Returns what the editor calls those keys.
	 */
	protected function metaLabels(): array {
		return array(
			'_form'     => __( 'Form', 'swiftling' ),
			'_messages' => __( 'Messages', 'swiftling' ),
			'_mail'     => __( 'Mail', 'swiftling' ),
			'_mail_2'   => __( 'Second mail', 'swiftling' ),
		);
	}

	/**
	 * Returns the shortcode a page holds where it shows a form.
	 */
	protected function shortcodeTag(): string {
		return 'contact-form-7';
	}

	/**
	 * Returns the route of the REST API at which the plugin receives a form.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/contact-form-7/v1/contact-forms/\d+/feedback/?$#' );
	}

	/**
	 * Hooks where the plugin is about to send the mails of a form sent.
	 */
	public function register(): void {
		add_action( 'wpcf7_before_send_mail', array( $this, 'startMails' ) );
		add_filter( 'wpcf7_additional_mail', array( $this, 'nextMails' ), 10, 2 );
	}

	/**
	 * Before the plugin sends the first mail of a form sent from a page in another language: a mail
	 * to the site's owner gets its original back.
	 *
	 * @param mixed $contact_form The form, as the plugin's object.
	 */
	public function startMails( $contact_form ): void {
		if ( 'translate' !== swiftling_mode() || ! self::isForm( $contact_form ) ) {
			return;
		}
		$mail = $contact_form->prop( 'mail' );
		if ( is_array( $mail ) && ! self::mailsTheVisitor( $mail ) ) {
			$original = self::originalMail( (int) $contact_form->id(), 'mail' );
			if ( null !== $original ) {
				$contact_form->set_properties( array( 'mail' => $original ) );
			}
		}
	}

	/**
	 * Before the plugin sends the second mail of a form sent from a page in another language: a mail
	 * to the site's owner gets its original back. Mails other plugins add stay as they are.
	 *
	 * @param mixed $mails        The mails still to send, by name.
	 * @param mixed $contact_form The form, as the plugin's object.
	 * @return mixed
	 */
	public function nextMails( $mails, $contact_form = null ) {
		if ( 'translate' !== swiftling_mode() || ! is_array( $mails ) || ! self::isForm( $contact_form ) || ! isset( $mails['mail_2'] ) || ! is_array( $mails['mail_2'] ) ) {
			return $mails;
		}
		if ( ! self::mailsTheVisitor( $mails['mail_2'] ) ) {
			$original = self::originalMail( (int) $contact_form->id(), 'mail_2' );
			if ( null !== $original ) {
				$mails['mail_2'] = array_merge( $mails['mail_2'], $original );
			}
		}
		return $mails;
	}

	/**
	 * Whether a value is a form as the plugin hands it to these hooks.
	 *
	 * @param mixed $contact_form The value.
	 */
	private static function isForm( $contact_form ): bool {
		return is_object( $contact_form ) && method_exists( $contact_form, 'id' ) && method_exists( $contact_form, 'prop' ) && method_exists( $contact_form, 'set_properties' );
	}

	/**
	 * Returns a mail of a form as stored, untranslated.
	 *
	 * @param int    $form_id Post ID of the form.
	 * @param string $name    Name of the mail: mail or mail_2.
	 * @return array|null Null when the form has no such mail.
	 */
	private static function originalMail( int $form_id, string $name ): ?array {
		$mail = self::inOriginal(
			static function () use ( $form_id, $name ) {
				return get_post_meta( $form_id, '_' . $name, true );
			}
		);
		return is_array( $mail ) ? $mail : null;
	}

	/**
	 * Whether a mail of a form writes to the visitor: every address it sends to is a form-tag the
	 * visitor fills in, as [your-email], or the signed-in visitor's [_user_email]. The plugin's
	 * other special tags, as [_site_admin_email], and a fixed address are the site owner's.
	 *
	 * @param array $mail The mail, as the plugin keeps it.
	 */
	private static function mailsTheVisitor( array $mail ): bool {
		return self::onlyVisitors(
			isset( $mail['recipient'] ) && is_string( $mail['recipient'] ) ? $mail['recipient'] : '',
			static function ( string $recipient ): bool {
				return 1 === preg_match( '/^\[((?!_)[^\[\]\s]+|_user_email)\]$/', $recipient );
			}
		);
	}

	/**
	 * Returns the texts of a form for the editor, without those of a mail to the site's owner, which
	 * stays in the original.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$form_id = $this->formId( (string) $subject['key'] );
		$skip    = array();
		foreach ( array( '_mail', '_mail_2' ) as $key ) {
			$mail = get_post_meta( $form_id, $key, true );
			if ( is_array( $mail ) && ! self::mailsTheVisitor( $mail ) ) {
				$skip[] = 'meta/' . $key . '/';
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
	 * Whether a value of a form never holds text a visitor reads: what says where a mail goes and
	 * how it travels. A sender such as "Site Title <mail@example.org>" and a header such as
	 * "Reply-To: [your-email]" read like text, and a marker or a translation in them would change
	 * where the mail goes instead of what it says.
	 *
	 * @param string $path Path of the value inside its object.
	 */
	public function holdsNoText( string $path ): bool {
		if ( 0 !== strpos( $path, 'meta/_mail' ) ) {
			return false;
		}
		$name = (string) substr( $path, (int) strrpos( $path, '/' ) + 1 );
		return in_array( $name, array( 'recipient', 'sender', 'additional_headers', 'attachments' ), true );
	}

	/**
	 * Whether a value of a form is code the editor shows as it is: the form itself. It is written in
	 * the plugin's form-tag syntax, as "[text* your-name]" beside the words a visitor reads, and
	 * the segmenter keeps such a value whole, because the site registers no shortcode of those
	 * names. The editor shows code in a plain field and leaves it out of machine translation, so
	 * the tags stay as they are unless someone changes them by hand.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @param string $rule  Rule key of the value.
	 */
	public function isCode( string $owner, string $path, string $rule ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The path says it.
		return 'meta/_form' === $path && null !== $this->form( (int) substr( $owner, 5 ) );
	}
}
