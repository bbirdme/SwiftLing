<?php
/**
 * Simple Membership: its levels and emails in the language of the member.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Simple Membership (Simple WordPress Membership). Its messages are texts of its code, translated by its
 * language pack. What SwiftLing does not find by itself:
 *
 * - Its emails are saved, all of them and in English, in its settings, the option "swpm-settings", when
 *   it is installed, so no language pack translates them. The plugin reads its settings once as it loads,
 *   before SwiftLing starts, and keeps them in an object of its own: in a request in a language their
 *   texts, and the addresses of its pages, are put into that object translated, see start().
 * - An email the plugin writes later, as when the site's owner approves an account, is written in the
 *   language the member registered in, see swiftling_member_language(): the plugin fills its
 *   placeholders before any filter, so the email is written again from the translation of its template
 *   with what the plugin filled in, see writeEmail(). The site's owner's emails stay in the original.
 * - The name of a level is a row of its own table, "simple-membership:level:<ID>" with the path
 *   "swpm/alias", translated where the plugin prints it in its forms, see translateForm().
 *
 * Checked against the code of Simple Membership 4.8, in tests/SimpleMembershipTest.php, and by hand on a
 * site with the plugin.
 */
class SimpleMembership extends SettingsTexts {
	/**
	 * The prefix of the object keys of the levels.
	 */
	const BASE = 'simple-membership';

	/**
	 * The emails to members, as the settings name them: each has a "-subject" and a "-body".
	 */
	const EMAILS = array( 'reg-complete-mail', 'email-activation-mail', 'reg-prompt-complete-mail', 'reset-mail', 'manual-account-approve-member-mail', 'upgrade-complete-mail', 'renew-complete-mail', 'subscription-cancel-member-mail', 'subscription-renewal-member-mail', 'bulk-activate-notify-mail' );

	/**
	 * The settings that hold the addresses of the plugin's pages.
	 */
	const PAGES = array( 'login-page-url', 'registration-page-url', 'profile-page-url', 'reset-page-url', 'join-us-page-url', 'thank-you-page-url' );

	/**
	 * The shortcodes of the forms that show the name of a level.
	 */
	const FORMS = array( 'swpm_registration_form', 'swpm_profile_form' );

	/**
	 * A placeholder of the plugin's emails, as {first_name}.
	 */
	const PLACEHOLDER = '/\{[a-z0-9_]+\}/i';

	/**
	 * The names of the levels, by ID, read once a request.
	 *
	 * @var array<int, string>|null
	 */
	private $levels = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'simple-membership';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Simple Membership';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'SIMPLE_WP_MEMBERSHIP_VER' );
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
	 * Hooks where the plugin prints a form and where an email is sent.
	 */
	public function register(): void {
		add_filter( 'do_shortcode_tag', array( $this, 'translateForm' ), 100, 2 );
		add_filter( 'wp_mail', array( $this, 'writeEmail' ), 100 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * In a request in a language: the texts of the plugin's settings, and the addresses of its pages,
	 * translated in the object the plugin keeps them in. The plugin writing its settings again keeps the
	 * original, as SwiftLing guards every option it translates.
	 *
	 * @param string $mode  The mode of the request.
	 * @param bool   $probe Whether a page check runs.
	 */
	public function start( string $mode, bool $probe ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- As the other builders start.
		if ( 'translate' !== $mode || ! class_exists( 'SwpmSettings' ) ) {
			return;
		}
		$settings = \SwpmSettings::get_instance();
		foreach ( $this->textOptions()['swpm-settings'] as $key ) {
			$value = $settings->get_value( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$settings->set_value( $key, $this->optionText( 'swpm-settings', $key, $value ) );
			}
		}
		foreach ( self::PAGES as $key ) {
			$value = $settings->get_value( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$settings->set_value( $key, swiftling_addresses_in( $value, swiftling_writing_language() ) );
			}
		}
	}

	/**
	 * Returns a form of the plugin with the names of the levels in it in the mode of the moment.
	 *
	 * @param mixed $html The shortcode's output.
	 * @param mixed $tag  The shortcode.
	 * @return mixed
	 */
	public function translateForm( $html, $tag = '' ) {
		if ( ! is_string( $html ) || ! in_array( $tag, self::FORMS, true ) || ! self::inMode() ) {
			return $html;
		}
		$levels = array_flip( $this->levels() );
		return HtmlTexts::translate(
			$html,
			static function ( $text ) use ( $levels ) {
				return isset( $levels[ $text ] ) ? self::levelName( (int) $levels[ $text ], $text ) : $text;
			}
		);
	}

	/**
	 * Returns the name of a level in the mode of the moment.
	 *
	 * @param int    $id   The level's ID.
	 * @param string $name The name, as stored.
	 */
	private static function levelName( int $id, string $name ): string {
		$translated = swiftling_transform_text( self::BASE . ':level:' . $id, 'swpm/alias', self::BASE . ':level', $name );
		return is_string( $translated ) ? $translated : $name;
	}

	/**
	 * Returns the names of the plugin's levels, by ID, but the one the plugin keeps for itself.
	 *
	 * @return array<int, string>
	 */
	private function levels(): array {
		if ( null !== $this->levels ) {
			return $this->levels;
		}
		global $wpdb;
		$this->levels = array();
		$table        = $wpdb->prefix . 'swpm_membership_tbl';
		if ( ! $this->hasTable( $table ) ) {
			return $this->levels;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once a request.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, alias FROM %i WHERE id > 1 ORDER BY id', $table ) ) as $row ) {
			if ( is_string( $row->alias ) && '' !== trim( $row->alias ) ) {
				$this->levels[ (int) $row->id ] = $row->alias;
			}
		}
		return $this->levels;
	}

	/**
	 * Returns an email of the plugin to a member in the language the member registered in, where the
	 * plugin wrote it in another: written again from the translation of its template, with what the
	 * plugin filled in, and the addresses of the site's pages in that language.
	 *
	 * @param mixed $mail What wp_mail() sends.
	 * @return mixed
	 */
	public function writeEmail( $mail ) {
		if ( ! is_array( $mail ) || ! isset( $mail['to'], $mail['subject'], $mail['message'] ) || ! is_string( $mail['to'] ) || ! is_string( $mail['subject'] ) || ! is_string( $mail['message'] ) || ! class_exists( 'SwpmSettings' ) ) {
			return $mail;
		}
		$slug = swiftling_member_language( $mail['to'], false );
		if ( '' === $slug || swiftling_writing_language() === $slug ) {
			return $mail;
		}
		swiftling_pause_translation();
		try {
			$stored = get_option( 'swpm-settings' );
		} finally {
			swiftling_resume_translation();
		}
		if ( ! is_array( $stored ) ) {
			return $mail;
		}
		$address = static function ( $value ) use ( $slug ) {
			return swiftling_addresses_in( (string) $value, $slug );
		};
		foreach ( self::EMAILS as $email ) {
			$subject = $stored[ $email . '-subject' ] ?? '';
			$body    = $stored[ $email . '-body' ] ?? '';
			if ( ! is_string( $subject ) || ! is_string( $body ) || '' === $body ) {
				continue;
			}
			$written = Templates::refill( html_entity_decode( $body ), html_entity_decode( $this->inLanguage( $slug, $email . '-body', $body ) ), $mail['message'], self::PLACEHOLDER, $address );
			if ( null === $written ) {
				continue;
			}
			$mail['message'] = $written;
			$refilled        = Templates::refill( $subject, $this->inLanguage( $slug, $email . '-subject', $subject ), $mail['subject'], self::PLACEHOLDER );
			if ( null !== $refilled ) {
				$mail['subject'] = $refilled;
			}
			return $mail;
		}
		return $mail;
	}

	/**
	 * Returns a text of the plugin's settings in a language.
	 *
	 * @param string $slug  Address slug of the language.
	 * @param string $key   The setting.
	 * @param string $value The text, as stored.
	 */
	private function inLanguage( string $slug, string $key, string $value ): string {
		$translated = swiftling_in_language(
			$slug,
			function () use ( $key, $value ) {
				return $this->optionText( 'swpm-settings', $key, $value );
			}
		);
		return is_string( $translated ) ? $translated : $value;
	}

	/**
	 * Returns the settings that hold text: the subject and body of every email to members.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$keys = array();
		foreach ( self::EMAILS as $email ) {
			$keys[] = $email . '-subject';
			$keys[] = $email . '-body';
		}
		return array( 'swpm-settings' => $keys );
	}

	/**
	 * Returns the settings that hold the addresses of the plugin's pages.
	 *
	 * @return array<string, string[]>
	 */
	protected function addressOptions(): array {
		return array( 'swpm-settings' => self::PAGES );
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The membership levels of Simple Membership and its emails to members, which go out in the language the member registered in. Emails to you stay in your language. Keep every placeholder, such as {first_name}.', 'swiftling' );
	}

	/**
	 * Reads the names of the levels for the subject of the settings.
	 */
	protected function readMoreTexts(): void {
		foreach ( $this->levels() as $id => $name ) {
			self::levelName( $id, $name );
		}
	}

	/**
	 * Declares the names of the levels as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, self::BASE . ':level:' ) ) {
			return 'swpm/alias' === $path ? true : null;
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
		if ( 0 === strpos( $owner, self::BASE . ':level:' ) ) {
			/* translators: %s: ID of a membership level. */
			return sprintf( __( 'Level %s', 'swiftling' ), substr( $owner, strlen( self::BASE . ':level:' ) ) ) . ' › ' . __( 'Name', 'swiftling' );
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
