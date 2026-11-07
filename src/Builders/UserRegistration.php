<?php
/**
 * User Registration & Membership: its messages and emails in the language of the member.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * User Registration & Membership's settings and emails; its forms are in SwiftLing\Forms\UserRegistration.
 *
 * - The messages of its forms and its emails are options, read with get_option() while it answers, see
 *   textOptions(). A text the site's owner never saved is the plugin's own, translated by its language
 *   pack.
 * - The forms are sent through admin-ajax.php, answered in the language of the page they were sent
 *   from, see sendingActions(), so the messages and the email after registering are in that language.
 * - An email the plugin writes later, as when the site's owner approves an account, is written in the
 *   language the member registered in, see swiftling_member_language(): the plugin hands the template
 *   to a filter before it fills its placeholders, see writeEmail(), with the addresses of the site's
 *   pages in that language. The plugin keeps a language with the member itself only for WPML. The site's
 *   owner's emails stay in the original.
 *
 * Checked against the code of User Registration & Membership 5.3 (the free plugin), in
 * tests/UserRegistrationTest.php, and by hand on a site with the plugin.
 */
class UserRegistration extends SettingsTexts {
	/**
	 * The options of the emails to members: subject and body of each.
	 */
	const EMAILS = array(
		'user_registration_email_confirmation',
		'user_registration_awaiting_admin_approval_email',
		'user_registration_registration_denied_email',
		'user_registration_successfully_registered_email',
		'user_registration_registration_pending_email',
		'user_registration_registration_approved_email',
		'user_registration_reset_password_email',
		'user_registration_profile_details_Updated_email',
	);

	/**
	 * The options of the messages a visitor reads.
	 */
	const MESSAGES = array(
		'user_registration_successful_form_submission_message_manual_registation',
		'user_registration_successful_form_submission_message_email_confirmation',
		'user_registration_successful_form_submission_message_admin_approval',
		'user_registration_form_submission_error_message_required_fields',
		'user_registration_form_submission_error_message_email',
		'user_registration_form_submission_error_message_website_URL',
		'user_registration_form_submission_error_message_number',
		'user_registration_form_submission_error_message_confirm_email',
		'user_registration_form_submission_error_message_confirm_password',
		'user_registration_form_submission_error_message_phone_number',
		'user_registration_form_submission_error_message_recaptcha',
		'user_registration_form_submission_error_message_disallow_username_character',
		'user_registration_successful_email_verified_message',
		'user_registration_general_setting_registration_label',
		'user_registration_label_username_or_email',
		'user_registration_label_password',
		'user_registration_label_remember_me',
		'user_registration_label_login',
		'user_registration_label_lost_your_password',
		'user_registration_placeholder_username_or_email',
		'user_registration_placeholder_password',
		'user_registration_message_username_required',
		'user_registration_message_empty_password',
		'user_registration_message_invalid_username',
		'user_registration_message_unknown_email',
		'user_registration_message_pending_approval',
		'user_registration_message_denied_account',
		'user_registration_invalid_email_error_message',
		'user_registration_invalid_url_error_message',
		'user_registration_invalid_date_error_message',
		'user_registration_empty_field_error_message',
		'user_registration_non_boolean_value_error_message',
		'user_registration_non_numeric_data_error_message',
	);

	/**
	 * A placeholder of the plugin's emails, as {{username}}.
	 */
	const PLACEHOLDER = '/\{\{[a-z0-9_]+\}\}/i';

	/**
	 * The language of the email whose template writeEmail() just handed on, until the plugin filled its
	 * placeholders; null when none.
	 *
	 * @var string|null
	 */
	private $filling = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'user-registration';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'User Registration & Membership';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'UR_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin: none is.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The plugin builds no posts.
		return false;
	}

	/**
	 * Returns the actions of admin-ajax.php the plugin's forms are sent through: answered in the language
	 * of the page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'user_registration_user_form_submit', 'user_registration_ajax_login_submit' );
	}

	/**
	 * Hooks where the plugin writes and sends an email.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		add_filter( 'user_registration_process_smart_tags', array( $this, 'writeEmail' ), 1, 2 );
		add_filter( 'user_registration_process_smart_tags', array( $this, 'emailAddresses' ), 100 );
		// phpcs:enable
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns the template of an email to a member in the language the member registered in, before the
	 * plugin fills its placeholders: where the plugin wrote it in another language, from the template's
	 * translation. The addresses the plugin fills in follow, see emailAddresses().
	 *
	 * @param mixed $content The template, or another text the plugin fills its placeholders in.
	 * @param mixed $values  What the placeholders name, with the member's email address.
	 * @return mixed
	 */
	public function writeEmail( $content, $values = array() ) {
		$this->filling = null;
		if ( ! is_string( $content ) || '' === trim( $content ) || ! is_array( $values ) || empty( $values['email'] ) || ! is_string( $values['email'] ) ) {
			return $content;
		}
		$slug = swiftling_member_language( $values['email'], false );
		if ( '' === $slug ) {
			return $content;
		}
		foreach ( self::EMAILS as $email ) {
			foreach ( array( $email . '_subject', $email ) as $option ) {
				swiftling_pause_translation();
				try {
					$original = get_option( $option );
				} finally {
					swiftling_resume_translation();
				}
				if ( ! is_string( $original ) || '' === $original ) {
					continue;
				}
				$translated = swiftling_in_language(
					$slug,
					function () use ( $option, $original ) {
						return $this->optionText( $option, '', $original );
					}
				);
				if ( $original === $content || $translated === $content ) {
					$this->filling = $slug;
					return is_string( $translated ) ? $translated : $content;
				}
			}
		}
		return $content;
	}

	/**
	 * Returns an email to a member, its placeholders filled, with the addresses of the site's pages in
	 * the language the member registered in.
	 *
	 * @param mixed $content The text, its placeholders filled.
	 * @return mixed
	 */
	public function emailAddresses( $content ) {
		$slug          = $this->filling;
		$this->filling = null;
		return null !== $slug && is_string( $content ) ? swiftling_addresses_in( $content, $slug ) : $content;
	}

	/**
	 * Returns the options that hold text: the messages, and the subject and body of every email to
	 * members.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$options = array();
		foreach ( self::MESSAGES as $option ) {
			$options[ $option ] = array( '' );
		}
		foreach ( self::EMAILS as $email ) {
			$options[ $email . '_subject' ] = array( '' );
			$options[ $email ]              = array( '' );
		}
		return $options;
	}

	/**
	 * Returns what the editor calls each text: its option, without the plugin's prefix.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		$labels = array();
		foreach ( array_keys( $this->textOptions() ) as $option ) {
			$labels[ $option ] = array( '' => ucfirst( str_replace( '_', ' ', substr( $option, strlen( 'user_registration_' ) ) ) ) );
		}
		return $labels;
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The messages of User Registration & Membership and its emails to members, which go out in the language the member registered in. A text you never saved in the plugin is its own, translated by its language pack. Keep every placeholder, such as {{username}}.', 'swiftling' );
	}

	/**
	 * Keeps the placeholders of the plugin's emails as they are when a text goes to machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, self::PLACEHOLDER );
	}
}
