<?php
/**
 * Mailchimp for WordPress: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

use SwiftLing\Builders\HtmlTexts;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Mailchimp for WordPress. Its forms are posts of the type "mc4wp-form", which visitors never open:
 *
 * - The form itself is the content of the post, HTML the site's owner writes, with the labels, the
 *   placeholders and the button a visitor reads. The plugin reads the post and hands its content to the
 *   filter mc4wp_form_content before it prints the form: translated there, see translateForm(), text
 *   by text, the labels between the tags and the placeholders, titles and buttons in their attributes,
 *   under the post's own object, so it is translated once for every page that shows the form. The
 *   markup, with the names of the fields Mailchimp reads, stays as it is, and no text is code a
 *   translator has to edit by hand or machine translation leaves out.
 * - What a visitor reads after sending is in the meta of the post, "text_subscribed" and the like, which
 *   the plugin reads with get_post_meta() as any meta. A message the owner did not change is the
 *   plugin's own, translated by its language pack.
 *
 * The form is sent to the page that shows it, so the answer is in the language of that page. Mailchimp
 * itself sends the emails of a subscription; it is told the language the visitor subscribed in, see
 * keepLanguage(), unless the form names one itself, so a list with emails in more than one language
 * writes to the visitor in theirs.
 *
 * Checked against the code of Mailchimp for WordPress 4.14 (the free plugin), in
 * tests/MailchimpForWpTest.php, and by hand on a site with the plugin.
 */
final class MailchimpForWp extends Provider {
	/**
	 * Where the texts of the form itself are in the object of its post, and their source for rule keys.
	 */
	const BASE   = 'mc4wp/form';
	const SOURCE = 'mc4wp:form';

	/**
	 * The messages of a form, as the plugin names them.
	 */
	const MESSAGES = array( 'subscribed', 'updated', 'unsubscribed', 'not_subscribed', 'error', 'invalid_email', 'already_subscribed', 'required_field_missing', 'no_lists_selected' );

	/**
	 * Returns the provider's ID.
	 */
	public function id(): string {
		return 'mc4wp';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Mailchimp for WordPress';
	}

	/**
	 * Returns the post type the forms are.
	 */
	public function postType(): string {
		return 'mc4wp-form';
	}

	/**
	 * Returns what the plugin's files declare while they load.
	 */
	protected function markers(): array {
		return array( 'MC4WP_VERSION', 'MC4WP_Form' );
	}

	/**
	 * Returns the meta keys that hold the messages of a form.
	 */
	protected function metaKeys(): array {
		return array_map(
			static function ( string $message ): string {
				return 'text_' . $message;
			},
			self::MESSAGES
		);
	}

	/**
	 * Returns what the editor calls those keys.
	 */
	protected function metaLabels(): array {
		$labels = array(
			'subscribed'             => __( 'Message: subscribed', 'swiftling' ),
			'updated'                => __( 'Message: details updated', 'swiftling' ),
			'unsubscribed'           => __( 'Message: unsubscribed', 'swiftling' ),
			'not_subscribed'         => __( 'Message: not subscribed', 'swiftling' ),
			'error'                  => __( 'Message: error', 'swiftling' ),
			'invalid_email'          => __( 'Message: invalid email address', 'swiftling' ),
			'already_subscribed'     => __( 'Message: already subscribed', 'swiftling' ),
			'required_field_missing' => __( 'Message: required field missing', 'swiftling' ),
			'no_lists_selected'      => __( 'Message: no list selected', 'swiftling' ),
		);
		$keys   = array();
		foreach ( $labels as $message => $label ) {
			$keys[ 'text_' . $message ] = $label;
		}
		return $keys;
	}

	/**
	 * Returns the shortcode a page holds where it shows a form.
	 */
	protected function shortcodeTag(): string {
		return 'mc4wp_form';
	}

	/**
	 * Returns the right the plugin asks to edit its forms, which WordPress's own right to edit their
	 * posts does not grant: the post type names rights of its own no role has.
	 */
	private static function capability(): string {
		return (string) apply_filters( 'mc4wp_admin_required_capability', 'manage_options' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it asks it.
	}

	/**
	 * Returns the subject of the translation editor for one form, under the plugin's own right.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		$subject = parent::subject( $key );
		if ( null !== $subject ) {
			$subject['cap'] = self::capability();
		}
		return $subject;
	}

	/**
	 * Whether the current user may translate a form: the plugin's own right to edit its forms.
	 *
	 * @param string $owner Object key.
	 * @return bool|null Null when the object is not a form of the plugin.
	 */
	public function canTranslate( string $owner ): ?bool {
		if ( 0 !== strpos( $owner, 'post:' ) || null === $this->form( (int) substr( $owner, 5 ) ) ) {
			return null;
		}
		return current_user_can( self::capability() );
	}

	/**
	 * Hooks where the plugin prints a form and where it hands Mailchimp what a visitor sent.
	 */
	public function register(): void {
		// Before the plugin replaces its tags in the form and runs its shortcodes (10).
		add_filter( 'mc4wp_form_content', array( $this, 'translateForm' ), 1, 2 );
		add_filter( 'mc4wp_form_data', array( $this, 'keepLanguage' ), 10, 1 );
	}

	/**
	 * Returns the form, the content of its post, in the mode of the moment.
	 *
	 * @param mixed $content The form, as the post keeps it.
	 * @param mixed $form    The form, as the plugin's object.
	 * @return mixed
	 */
	public function translateForm( $content, $form = null ) {
		if ( ! is_string( $content ) || '' === trim( $content ) || ! is_object( $form ) || empty( $form->ID ) ) {
			return $content;
		}
		return self::formTexts( (int) $form->ID, $content );
	}

	/**
	 * Returns the HTML of a form with its texts in the mode of the moment, each under its own path.
	 *
	 * @param int    $form_id Post ID of the form.
	 * @param string $html    The form, as the post keeps it.
	 */
	private static function formTexts( int $form_id, string $html ): string {
		$owner = swiftling_post_owner( $form_id );
		return HtmlTexts::translate(
			$html,
			static function ( $text, $key ) use ( $owner ) {
				return swiftling_transform_text( $owner, self::BASE . '/' . $key, self::SOURCE, $text );
			},
			true
		);
	}

	/**
	 * Tells Mailchimp the language a visitor subscribed in, from a page in a language, unless the form
	 * names one itself in its field MC_LANGUAGE.
	 *
	 * @param mixed $data What the visitor sent, by field.
	 * @return mixed
	 */
	public function keepLanguage( $data ) {
		if ( ! is_array( $data ) || 'translate' !== swiftling_mode() || ( isset( $data['MC_LANGUAGE'] ) && '' !== trim( (string) $data['MC_LANGUAGE'] ) ) ) {
			return $data;
		}
		$locale = (string) swiftling_state()['locale'];
		if ( '' !== $locale ) {
			// The plugin turns a locale into Mailchimp's code of the language, as "de".
			$data['MC_LANGUAGE'] = $locale;
		}
		return $data;
	}

	/**
	 * Reads the form itself, as the plugin hands it to its filter.
	 *
	 * @param WP_Post $form Form.
	 */
	protected function readMoreTexts( WP_Post $form ): void {
		if ( '' !== trim( $form->post_content ) ) {
			self::formTexts( $form->ID, $form->post_content );
		}
	}

	/**
	 * Returns what the editor calls a text of a form: the form itself, or a message.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $path, self::BASE . '/' ) && 0 === strpos( $owner, 'post:' ) && null !== $this->form( (int) substr( $owner, 5 ) ) ) {
			return __( 'Form', 'swiftling' );
		}
		return parent::fieldLabel( $owner, $path );
	}

	/**
	 * Declares the texts of the form itself as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for what is not a text of the form itself.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		return 0 === strpos( $path, self::BASE . '/' ) && 0 === strpos( $owner, 'post:' ) && null !== $this->form( (int) substr( $owner, 5 ) ) ? true : null;
	}
}
