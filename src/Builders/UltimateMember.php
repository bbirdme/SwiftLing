<?php
/**
 * Ultimate Member: its messages and emails in the language of the member.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Ultimate Member's settings and emails; its forms are in SwiftLing\Forms\UltimateMember.
 *
 * - The plugin keeps its settings in the option "um_options", read once as it loads, before SwiftLing
 *   starts, and hands each out through a filter of its own, see register(): the texts a visitor reads,
 *   as the message on restricted content, are translated there.
 * - The message of a post restricted with a message of its own is in the post's meta
 *   "um_content_restriction", declared as text, so the editor of the post offers it.
 * - The body of an email is a template file of the plugin, or of the theme once the site's owner
 *   changed it, HTML with the words in it: its texts are "ultimate-member:email:<template>", one by one
 *   at paths such as "um/<key>", see HtmlTexts. The subject is a setting.
 * - An email to a member is written in the language the member registered in, see
 *   swiftling_member_language(), also when the plugin sends it later, as when the site's owner approves
 *   the account; an email to the site's owner in the original. The plugin says before and after an
 *   email which it sends to whom, see startEmail().
 *
 * Checked against the code of Ultimate Member 2.14, in tests/UltimateMemberTest.php, and by hand on a
 * site with the plugin.
 */
class UltimateMember extends SettingsTexts {
	/**
	 * The prefix of the object keys and paths of the texts of the emails.
	 */
	const BASE = 'ultimate-member';

	/**
	 * The settings a visitor reads, besides the subjects of the emails.
	 */
	const MESSAGES = array( 'restricted_access_message', 'restricted_access_post_title', 'delete_account_text', 'delete_account_no_pass_required_text', 'profile_primary_btn_word', 'profile_secondary_btn_word', 'register_primary_btn_word', 'register_secondary_btn_word', 'login_primary_btn_word', 'login_secondary_btn_word' );

	/**
	 * A placeholder of the plugin's emails, as {display_name}.
	 */
	const PLACEHOLDER = '/\{[a-z0-9_]+\}/i';

	/**
	 * The plugin's emails, by template: whether each goes to the member. Read once a request.
	 *
	 * @var array<string, bool>|null
	 */
	private static $emails = null;

	/**
	 * The language of each email being written, the last one last.
	 *
	 * @var string[]
	 */
	private $writing = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'ultimate-member';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Ultimate Member';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'UM_VERSION' ) && function_exists( 'UM' );
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
	 * Hooks where the plugin hands out a setting and writes an email.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		foreach ( $this->textOptions()['um_options'] as $key ) {
			add_filter(
				'um_get_option_filter__' . $key,
				function ( $value ) use ( $key ) {
					return $this->optionText( 'um_options', $key, $value );
				},
				100
			);
		}
		add_action( 'um_before_email_notification_sending', array( $this, 'startEmail' ), 1, 2 );
		add_action( 'um_after_email_notification_sending', array( $this, 'endEmail' ), 100 );
		add_filter( 'um_email_send_message_content', array( $this, 'translateEmail' ), 1, 2 );
		// phpcs:enable
		add_filter( 'wp_mail', array( $this, 'emailAddresses' ), 100 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns the plugin's emails, by template: whether each goes to the member.
	 *
	 * @return array<string, bool>
	 */
	private static function emails(): array {
		if ( null !== self::$emails ) {
			return self::$emails;
		}
		// The plugin's configuration reads its settings while it is built, through the filters this
		// class adds, which ask for the emails again: they are none until it is built.
		self::$emails = array();
		$config       = function_exists( 'UM' ) ? UM()->config() : null;
		$emails       = is_object( $config ) && isset( $config->email_notifications ) && is_array( $config->email_notifications ) ? $config->email_notifications : array();
		$built        = array();
		foreach ( $emails as $template => $email ) {
			$built[ (string) $template ] = is_array( $email ) && 'admin' !== ( $email['recipient'] ?? 'user' );
		}
		self::$emails = $built;
		return self::$emails;
	}

	/**
	 * Before the plugin writes an email: the request in the language of its reader, until endEmail().
	 *
	 * @param mixed $email    The address the email goes to.
	 * @param mixed $template The template, as the plugin names it.
	 */
	public function startEmail( $email, $template = '' ): void {
		$members = self::emails();
		$slug    = ! empty( $members[ (string) $template ] ) ? swiftling_member_language( (string) $email ) : '';
		swiftling_write_in( $slug );
		$this->writing[] = $slug;
	}

	/**
	 * After the plugin sent an email: the request as it was.
	 */
	public function endEmail(): void {
		if ( array() !== $this->writing ) {
			array_pop( $this->writing );
			swiftling_resume_translation();
		}
	}

	/**
	 * Returns the body of an email to a member, written in a language, with the texts of its template
	 * translated.
	 *
	 * @param mixed $message  The body, before the plugin fills its placeholders.
	 * @param mixed $template The template, as the plugin names it.
	 * @return mixed
	 */
	public function translateEmail( $message, $template = '' ) {
		if ( ! is_string( $message ) || ! is_string( $template ) || '' === $template || '' === swiftling_writing_language() || empty( self::emails()[ $template ] ) ) {
			return $message;
		}
		return self::emailTexts( $template, $message );
	}

	/**
	 * Returns the body of an email with its texts in the mode of the moment, each under its own path.
	 *
	 * @param string $template The template, as the plugin names it.
	 * @param string $html     The body.
	 */
	private static function emailTexts( string $template, string $html ): string {
		$owner = self::BASE . ':email:' . $template;
		return HtmlTexts::translate(
			$html,
			static function ( $text, $key ) use ( $owner ) {
				// A placeholder alone, as {site_name}, has no words to translate.
				if ( ! preg_match( '/\p{L}/u', (string) preg_replace( self::PLACEHOLDER, '', $text ) ) ) {
					return $text;
				}
				return swiftling_transform_text( $owner, 'um/' . $key, self::BASE . ':email', $text );
			}
		);
	}

	/**
	 * Returns an email as WordPress sends it with the addresses of the site's pages in the language of
	 * the email the plugin writes now: a member's in theirs, the site's owner's in the original.
	 *
	 * @param mixed $mail What wp_mail() sends.
	 * @return mixed
	 */
	public function emailAddresses( $mail ) {
		if ( array() === $this->writing || ! is_array( $mail ) || ! isset( $mail['message'] ) || ! is_string( $mail['message'] ) ) {
			return $mail;
		}
		$mail['message'] = swiftling_addresses_in( $mail['message'], (string) end( $this->writing ) );
		return $mail;
	}

	/**
	 * Returns the settings that hold text: the messages and the subjects of the emails to members.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$keys = self::MESSAGES;
		foreach ( self::emails() as $template => $member ) {
			if ( $member ) {
				$keys[] = $template . '_sub';
			}
		}
		return array( 'um_options' => $keys );
	}

	/**
	 * Returns what the editor calls the settings.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		$labels = array(
			'restricted_access_message'            => __( 'Message on restricted content', 'swiftling' ),
			'restricted_access_post_title'         => __( 'Title of restricted content', 'swiftling' ),
			'delete_account_text'                  => __( 'Text before deleting the account', 'swiftling' ),
			'delete_account_no_pass_required_text' => __( 'Text before deleting the account, no password', 'swiftling' ),
		);
		foreach ( self::emails() as $template => $member ) {
			/* translators: %s: name of an email, e.g. welcome email. */
			$labels[ $template . '_sub' ] = sprintf( __( 'Subject of %s', 'swiftling' ), str_replace( '_', ' ', $template ) );
		}
		return array( 'um_options' => $labels );
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The messages of Ultimate Member and its emails to members, which go out in the language the member registered in. Emails to you stay in your language. Keep every placeholder, such as {display_name}.', 'swiftling' );
	}

	/**
	 * Returns the texts of a subject: the settings, or for a post the message the plugin shows instead of
	 * it when it is restricted, which a page check never sees, as it reads the page as the site's owner.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$post = $subject['post'] ?? null;
		if ( 'post' !== ( $subject['kind'] ?? '' ) || ! $post instanceof \WP_Post ) {
			return parent::listTexts( $subject );
		}
		return $this->collect(
			static function () use ( $post ): void {
				get_post_meta( $post->ID, 'um_content_restriction', true );
			}
		);
	}

	/**
	 * Reads the texts of the emails to members, as the plugin writes them in the original.
	 */
	protected function readMoreTexts(): void {
		$mail = function_exists( 'UM' ) ? UM()->mail() : null;
		if ( ! is_object( $mail ) || ! method_exists( $mail, 'get_email_template' ) ) {
			return;
		}
		foreach ( self::emails() as $template => $member ) {
			if ( ! $member ) {
				continue;
			}
			swiftling_pause_translation();
			try {
				$html = (string) $mail->get_email_template( $template, array() );
			} finally {
				swiftling_resume_translation();
			}
			if ( '' !== trim( $html ) ) {
				self::emailTexts( $template, $html );
			}
		}
	}

	/**
	 * Declares the texts of the emails, and the message of a restricted post, as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, self::BASE . ':email:' ) ) {
			return 0 === strpos( $path, 'um/' ) ? true : null;
		}
		if ( 0 === strpos( $owner, 'post:' ) && 0 === strpos( $path, 'meta/um_content_restriction/' ) ) {
			return 'meta/um_content_restriction/_um_restrict_custom_message' === $path;
		}
		return parent::fieldHoldsText( $owner, $path );
	}

	/**
	 * Returns what the editor calls a text of the plugin.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $owner, self::BASE . ':email:' ) ) {
			/* translators: %s: name of an email, e.g. welcome email. */
			return sprintf( __( 'Email: %s', 'swiftling' ), str_replace( '_', ' ', substr( $owner, strlen( self::BASE . ':email:' ) ) ) );
		}
		if ( 'meta/um_content_restriction/_um_restrict_custom_message' === $path ) {
			return __( 'Message on restricted content', 'swiftling' );
		}
		return parent::fieldLabel( $owner, $path );
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
