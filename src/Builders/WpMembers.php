<?php
/**
 * WP-Members: its messages, fields and emails in the language of the member.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Members. Its messages and the labels of its fields are options, read with get_option() while it
 * answers: "wpmembers_dialogs" by the name of a message, and "wpmembers_fields", a list of fields whose
 * second value is the label. A message or label the site's owner left as it was is the plugin's own,
 * translated by its language pack.
 *
 * Its emails are options too once the site's owner changed them, and texts of the plugin's code
 * otherwise, which no language pack translates: each is "wp-members:email:<tag>" with the paths
 * "wpmem/subj" and "wpmem/body", and the footer of every email "wp-members:email:footer". An email to a
 * member is written in the language the member registered in, see swiftling_member_language(), also
 * when the plugin sends it later, as when the site's owner approves the account; the email to the site's
 * owner in the original. The plugin hands an email to a filter before it fills its placeholders, see
 * writeEmail().
 *
 * Checked against the code of WP-Members 3.5, in tests/WpMembersTest.php, and by hand on a site with the
 * plugin.
 */
class WpMembers extends SettingsTexts {
	/**
	 * The prefix of the object keys and paths of the emails.
	 */
	const BASE = 'wp-members';

	/**
	 * The emails to members, by the plugin's tag.
	 */
	const EMAILS = array( 'newreg', 'newmod', 'appmod', 'repass', 'getuser', 'validated' );

	/**
	 * A placeholder of the plugin's emails, as [blogname].
	 */
	const PLACEHOLDER = '/\[[a-z0-9_]+\]/i';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wp-members';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'WP-Members';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'WPMEM_VERSION' ) || class_exists( 'WP_Members', false );
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
	 * Hooks where the plugin writes an email.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters.
		add_filter( 'wpmem_email_filter', array( $this, 'writeEmail' ), 100 );
		add_filter( 'wpmem_notify_filter', array( $this, 'writeOwnersEmail' ), 100 );
		// phpcs:enable
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns an email to a member in the language the member registered in: its subject, body and footer
	 * from their translations, and the addresses of the site's pages in that language.
	 *
	 * @param mixed $email The email, before the plugin fills its placeholders.
	 * @return mixed
	 */
	public function writeEmail( $email ) {
		if ( ! is_array( $email ) || empty( $email['user_id'] ) || ! in_array( $email['tag'] ?? '', self::EMAILS, true ) ) {
			return $email;
		}
		$slug = swiftling_member_language( (int) $email['user_id'] );
		if ( '' !== $slug ) {
			$email = swiftling_in_language(
				$slug,
				function () use ( $email ) {
					return $this->translateEmail( $email, (string) $email['tag'] );
				}
			);
		}
		return self::addresses( $email, $slug );
	}

	/**
	 * Returns the email to the site's owner about a new member with the addresses of the site's pages in
	 * the original, also when the member registered on a page in a language.
	 *
	 * @param mixed $email The email, before the plugin fills its placeholders.
	 * @return mixed
	 */
	public function writeOwnersEmail( $email ) {
		return is_array( $email ) ? self::addresses( $email, '' ) : $email;
	}

	/**
	 * Returns an email with its subject, body and footer in the mode of the moment.
	 *
	 * @param array  $email The email.
	 * @param string $tag   The email, as the plugin names it.
	 * @return array
	 */
	private function translateEmail( array $email, string $tag ): array {
		$html = isset( $email['body'], $email['footer'] ) && is_string( $email['body'] ) && false !== strpos( $email['body'], '<p>' );
		foreach ( array( 'subj', 'body' ) as $part ) {
			$original = self::original( $tag, $part );
			if ( '' !== $original && isset( $email[ $part ] ) && is_string( $email[ $part ] ) ) {
				$email[ $part ] = self::translateAs( $email[ $part ], $original, self::text( $tag, $part, $original ), 'body' === $part && $html );
			}
		}
		$footer = self::original( 'footer', 'body' );
		if ( '' !== $footer && isset( $email['footer'] ) && is_string( $email['footer'] ) ) {
			$email['footer'] = self::translateAs( $email['footer'], $footer, self::text( 'footer', 'body', $footer ), $html );
		}
		return $email;
	}

	/**
	 * Returns the translation of a part of an email where the plugin has the part as its template is:
	 * as stored, or as the plugin makes paragraphs of it for an HTML email.
	 *
	 * @param string $written    The part, as the plugin has it.
	 * @param string $original   The template.
	 * @param string $translated The template's translation.
	 * @param bool   $paragraphs Whether the plugin makes paragraphs of the part.
	 */
	private static function translateAs( string $written, string $original, string $translated, bool $paragraphs ): string {
		if ( $written === $original ) {
			return $translated;
		}
		return $paragraphs && wpautop( $original ) === $written ? wpautop( $translated ) : $written;
	}

	/**
	 * Returns a text of an email in the mode of the moment.
	 *
	 * @param string $tag      The email, as the plugin names it, or "footer".
	 * @param string $part     "subj" or "body".
	 * @param string $original The text, as the site's owner reads it in the plugin.
	 */
	private static function text( string $tag, string $part, string $original ): string {
		$translated = swiftling_transform_text( self::BASE . ':email:' . $tag, 'wpmem/' . $part, self::BASE . ':email', $original );
		return is_string( $translated ) ? $translated : $original;
	}

	/**
	 * Returns a part of an email as the site's owner reads it in the plugin: the text they changed it to,
	 * or the plugin's own.
	 *
	 * @param string $tag  The email, as the plugin names it, or "footer".
	 * @param string $part "subj" or "body".
	 */
	private static function original( string $tag, string $part ): string {
		if ( ! function_exists( 'wpmem_get_email_settings' ) ) {
			return '';
		}
		swiftling_pause_translation();
		try {
			$settings = wpmem_get_email_settings( 'wpmembers_email_' . $tag );
		} finally {
			swiftling_resume_translation();
		}
		if ( 'footer' === $tag ) {
			return is_string( $settings ) ? $settings : '';
		}
		return is_array( $settings ) && isset( $settings[ $part ] ) && is_string( $settings[ $part ] ) ? $settings[ $part ] : '';
	}

	/**
	 * Returns an email with the addresses of the site's pages the plugin puts in it in a language.
	 *
	 * @param array  $email The email.
	 * @param string $slug  Address slug of a target language, or an empty string for the original.
	 * @return array
	 */
	private static function addresses( array $email, string $slug ): array {
		foreach ( $email as $key => $value ) {
			if ( is_string( $value ) && preg_match( '#^https?://#i', $value ) ) {
				$email[ $key ] = swiftling_addresses_in( $value, $slug );
			}
		}
		return $email;
	}

	/**
	 * Returns the options that hold text: the messages, and the fields, whose labels fieldHoldsText()
	 * declares.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array(
			'wpmembers_dialogs' => array( 'restricted_msg', 'user', 'email', 'success', 'editsuccess', 'pwdchangerr', 'pwdchangesuccess', 'pwdreseterr', 'pwdresetsuccess' ),
			'wpmembers_fields'  => array(),
		);
	}

	/**
	 * Returns what the editor calls the messages.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array(
			'wpmembers_dialogs' => array(
				'restricted_msg'   => __( 'Message on restricted content', 'swiftling' ),
				'user'             => __( 'Message: username taken', 'swiftling' ),
				'email'            => __( 'Message: email address taken', 'swiftling' ),
				'success'          => __( 'Message after registering', 'swiftling' ),
				'editsuccess'      => __( 'Message after updating the profile', 'swiftling' ),
				'pwdchangerr'      => __( 'Message: passwords do not match', 'swiftling' ),
				'pwdchangesuccess' => __( 'Message after changing the password', 'swiftling' ),
				'pwdreseterr'      => __( 'Message: account not found', 'swiftling' ),
				'pwdresetsuccess'  => __( 'Message after a password reset', 'swiftling' ),
			),
		);
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The messages of WP-Members, the labels of its fields and its emails to members, which go out in the language the member registered in. A message you did not change is the plugin\'s own, translated by its language pack. Keep every placeholder, such as [blogname].', 'swiftling' );
	}

	/**
	 * Reads the emails to members and the footer of every email for the subject of the settings.
	 */
	protected function readMoreTexts(): void {
		foreach ( array_merge( self::EMAILS, array( 'footer' ) ) as $tag ) {
			foreach ( 'footer' === $tag ? array( 'body' ) : array( 'subj', 'body' ) as $part ) {
				$original = self::original( $tag, $part );
				if ( '' !== trim( $original ) ) {
					self::text( $tag, $part, $original );
				}
			}
		}
	}

	/**
	 * Declares the texts of the emails, and of the fields their labels, as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, self::BASE . ':email:' ) ) {
			return 0 === strpos( $path, 'wpmem/' ) ? true : null;
		}
		if ( 'options' === $owner && 0 === strpos( $path, 'option/wpmembers_fields/' ) ) {
			return 1 === preg_match( '#^option/wpmembers_fields/\d+/1$#', $path );
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
			$tag = substr( $owner, strlen( self::BASE . ':email:' ) );
			if ( 'footer' === $tag ) {
				return __( 'Footer of every email', 'swiftling' );
			}
			/* translators: %s: the plugin's name of an email, e.g. newreg. */
			return sprintf( __( 'Email: %s', 'swiftling' ), $tag ) . ' › ' . ( 'wpmem/subj' === $path ? __( 'Subject', 'swiftling' ) : __( 'Body', 'swiftling' ) );
		}
		if ( 'options' === $owner && preg_match( '#^option/wpmembers_fields/(\d+)/1$#', $path, $found ) ) {
			/* translators: %s: number of a field of a form. */
			return sprintf( __( 'Field %s › Label', 'swiftling' ), (string) ( (int) $found[1] + 1 ) );
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
