<?php
/**
 * Paid Memberships Pro: its levels, messages and emails in the language of the member.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Paid Memberships Pro. Its levels are rows of its own table, which it reads with its own queries, and
 * its emails are templates it keeps in classes of its own, or in options once the site's owner changed
 * them. What SwiftLing does not find by itself:
 *
 * - The name, description and confirmation message of a level are "pmpro:level:<ID>" with paths such
 *   as "pmpro/name", translated where the plugin hands a level out to its pages: the levels page, the
 *   checkout, the account and the confirmation, see register().
 * - Each template of an email to a member is "pmpro:email:<template>" with the paths "pmpro/subject"
 *   and "pmpro/body", as the site's owner changed it or as the plugin wrote it, and the header and
 *   footer of every email are "pmpro:email:header" and "pmpro:email:footer". They are offered because
 *   the plugin's language packs lack the texts of its current templates.
 * - An email to a member is written in the language the member registered in, see
 *   swiftling_member_language(), also when the plugin sends it later, as when a membership expires; an
 *   email to the site's owner in the original. The plugin writes an email whole before it hands it to a
 *   filter, so one written in another language is written again by its template in the right one, see
 *   writeEmail().
 * - The messages for visitors without a membership are options, read with get_option().
 *
 * The plugin has left wordpress.org; it is downloaded from its own site. Checked against the code of
 * Paid Memberships Pro 3.9, in tests/PaidMembershipsProTest.php, and by hand on a site with the plugin.
 */
class PaidMembershipsPro extends SettingsTexts {
	/**
	 * The prefix of the object keys and paths of the plugin's texts.
	 */
	const BASE = 'pmpro';

	/**
	 * The texts of a level, as the plugin's table names them.
	 */
	const LEVEL_FIELDS = array( 'name', 'description', 'confirmation' );

	/**
	 * A placeholder of the plugin's emails: !!name!!, {{ name }} or a tag of their Liquid syntax.
	 */
	const PLACEHOLDER = '/!![a-z0-9_]+!!|\{\{[^{}]*\}\}|\{%[^{}]*%\}/i';

	/**
	 * Whether SwiftLing sends an email of the plugin again now, in another language.
	 *
	 * @var bool
	 */
	private $resending = false;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'pmpro';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Paid Memberships Pro';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'PMPRO_VERSION' );
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
	 * Hooks where the plugin hands out its levels and writes an email.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters.
		add_filter( 'pmpro_levels_array', array( $this, 'translateLevels' ), 100 );
		add_filter( 'pmpro_get_membership_levels_for_user', array( $this, 'translateLevels' ), 100 );
		add_filter( 'pmpro_checkout_level', array( $this, 'translateLevel' ), 100 );
		add_filter( 'pmpro_get_membership_level_for_user', array( $this, 'translateLevel' ), 100 );
		add_filter( 'pmpro_confirmation_message', array( $this, 'translateConfirmation' ), 100, 2 );
		add_filter( 'do_shortcode_tag', array( $this, 'translatePage' ), 100, 2 );
		add_filter( 'render_block', array( $this, 'translateBlock' ), 100, 2 );
		add_filter( 'pmpro_email_filter', array( $this, 'writeEmail' ), 100 );
		add_filter( 'pmpro_email_header', array( $this, 'translateHeader' ), 100, 2 );
		add_filter( 'pmpro_email_footer', array( $this, 'translateFooter' ), 100, 2 );
		// phpcs:enable
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns levels the plugin hands out with their texts in the mode of the moment.
	 *
	 * @param mixed $levels Levels, by ID.
	 * @return mixed
	 */
	public function translateLevels( $levels ) {
		if ( ! is_array( $levels ) ) {
			return $levels;
		}
		foreach ( $levels as $key => $level ) {
			$levels[ $key ] = $this->translateLevel( $level );
		}
		return $levels;
	}

	/**
	 * Returns a level with its texts in the mode of the moment: a copy, as the plugin keeps the level it
	 * read for the rest of the request.
	 *
	 * @param mixed $level The level.
	 * @return mixed
	 */
	public function translateLevel( $level ) {
		if ( ! is_object( $level ) || empty( $level->id ) || ! self::inMode() ) {
			return $level;
		}
		$level = clone $level;
		foreach ( self::LEVEL_FIELDS as $field ) {
			if ( isset( $level->$field ) && is_string( $level->$field ) && '' !== trim( $level->$field ) ) {
				$level->$field = self::levelText( (int) $level->id, $field, $level->$field );
			}
		}
		return $level;
	}

	/**
	 * Returns a text of a level in the mode of the moment.
	 *
	 * @param int    $id    The level's ID.
	 * @param string $field The text, as the table names it.
	 * @param string $text  The text, as stored.
	 */
	private static function levelText( int $id, string $field, string $text ): string {
		$translated = swiftling_transform_text( self::BASE . ':level:' . $id, self::BASE . '/' . $field, self::BASE . ':level', $text );
		return is_string( $translated ) ? $translated : $text;
	}

	/**
	 * Returns the message on the confirmation page with the name and the confirmation message of the
	 * level in the mode of the moment: the plugin reads them from its table itself.
	 *
	 * @param mixed $message The message.
	 * @param mixed $order   The plugin's order.
	 * @return mixed
	 */
	public function translateConfirmation( $message, $order = null ) {
		if ( ! is_string( $message ) || ! is_object( $order ) || empty( $order->membership_id ) || ! self::inMode() ) {
			return $message;
		}
		return self::replaceLevelTexts( $message, (int) $order->membership_id );
	}

	/**
	 * Returns what a shortcode of the plugin printed, as its account, confirmation and order pages, with
	 * the names of the levels in it in the mode of the moment: the plugin prints the name of the level
	 * of an order as it reads it from its table.
	 *
	 * @param mixed $html The shortcode's output.
	 * @param mixed $tag  The shortcode.
	 * @return mixed
	 */
	public function translatePage( $html, $tag = '' ) {
		return is_string( $tag ) && 0 === strpos( $tag, self::BASE . '_' ) ? self::levelNamesIn( $html ) : $html;
	}

	/**
	 * Returns what a block of the plugin printed with the names of the levels in it in the mode of the
	 * moment, as translatePage().
	 *
	 * @param mixed $html  The block's output.
	 * @param mixed $block The block.
	 * @return mixed
	 */
	public function translateBlock( $html, $block = array() ) {
		return is_array( $block ) && is_string( $block['blockName'] ?? null ) && 0 === strpos( $block['blockName'], self::BASE . '/' ) ? self::levelNamesIn( $html ) : $html;
	}

	/**
	 * Returns HTML with every text that is the name of a level in the mode of the moment.
	 *
	 * @param mixed $html The HTML.
	 * @return mixed
	 */
	private static function levelNamesIn( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! self::inMode() || ! function_exists( 'pmpro_getAllLevels' ) ) {
			return $html;
		}
		$names = array();
		foreach ( (array) pmpro_getAllLevels( true ) as $level ) {
			if ( is_object( $level ) && is_string( $level->name ?? null ) && '' !== trim( $level->name ) ) {
				$names[ $level->name ] = (int) $level->id;
			}
		}
		return HtmlTexts::translate(
			$html,
			static function ( $text ) use ( $names ) {
				$name = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return isset( $names[ $name ] ) ? esc_html( self::levelText( $names[ $name ], 'name', $name ) ) : $text;
			}
		);
	}

	/**
	 * Returns a text the plugin wrote with the name and the confirmation message of a level in it, both
	 * in the mode of the moment.
	 *
	 * @param string $text The text.
	 * @param int    $id   The level's ID.
	 */
	private static function replaceLevelTexts( string $text, int $id ): string {
		$level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( $id ) : null;
		if ( ! is_object( $level ) ) {
			return $text;
		}
		$pairs = array();
		if ( is_string( $level->confirmation ?? null ) && '' !== trim( $level->confirmation ) ) {
			// As the plugin prints it.
			$pairs[ wpautop( stripslashes( $level->confirmation ) ) ] = wpautop( stripslashes( self::levelText( $id, 'confirmation', $level->confirmation ) ) );
			$pairs[ wpautop( $level->confirmation ) ]                 = wpautop( self::levelText( $id, 'confirmation', $level->confirmation ) );
		}
		if ( is_string( $level->name ?? null ) && '' !== trim( $level->name ) ) {
			$pairs[ $level->name ] = self::levelText( $id, 'name', $level->name );
		}
		return strtr( $text, array_filter( $pairs, 'strlen', ARRAY_FILTER_USE_KEY ) );
	}

	/**
	 * Returns an email of the plugin written in the language of its reader: a member's in the language
	 * the member registered in, from the translation of its template; the site's owner's in the original.
	 * An email the plugin wrote in another language is sent again by its template in the right one, and
	 * this one not: the plugin builds the parts of an email, as the price of a level, before any filter.
	 *
	 * @param mixed $email The plugin's email, written.
	 * @return mixed The email, or false for one SwiftLing sent again.
	 */
	public function writeEmail( $email ) {
		if ( ! is_object( $email ) || empty( $email->template ) || ! is_string( $email->template ) || 'default' === $email->template ) {
			return $email;
		}
		$slug = self::isOwnersEmail( $email->template ) ? '' : swiftling_member_language( (string) ( $email->email ?? '' ) );
		if ( swiftling_writing_language() !== $slug ) {
			$template = $this->resending ? null : self::templateBeingSent();
			if ( null === $template ) {
				return $email;
			}
			$this->resending = true;
			swiftling_write_in( $slug );
			try {
				$template->send();
			} finally {
				swiftling_resume_translation();
				$this->resending = false;
			}
			return false;
		}
		if ( '' === $slug ) {
			return $email;
		}
		// The plugin fills the placeholders once more after this filter, with the header and footer.
		foreach ( array( 'subject', 'body' ) as $part ) {
			$template = $this->template( $email->template, $part );
			if ( null !== $template ) {
				$email->$part = $template;
			}
		}
		if ( is_array( $email->data ) && ! empty( $email->data['membership_id'] ) ) {
			foreach ( array( 'membership_level_name', 'membership_level_confirmation_message' ) as $key ) {
				if ( isset( $email->data[ $key ] ) && is_string( $email->data[ $key ] ) ) {
					$email->data[ $key ] = self::replaceLevelTexts( $email->data[ $key ], (int) $email->data['membership_id'] );
				}
			}
		}
		return $email;
	}

	/**
	 * Returns a part of the template of an email in the language the request writes in, with its
	 * placeholders: the translation of the template, or the template as the plugin has it in that
	 * language.
	 *
	 * @param string $template The template, as the plugin names it.
	 * @param string $part     "subject" or "body".
	 * @return string|null Null for a template the plugin does not name.
	 */
	private function template( string $template, string $part ): ?string {
		$original = self::originalTemplate( $template, $part );
		if ( null === $original ) {
			return null;
		}
		$translated = swiftling_transform_text( self::BASE . ':email:' . $template, self::BASE . '/' . $part, self::BASE . ':email', $original );
		if ( is_string( $translated ) && $translated !== $original ) {
			return $translated;
		}
		$stored = get_option( 'pmpro_email_' . $template . '_' . $part );
		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}
		$class = self::templateClass( $template );
		if ( null === $class ) {
			return null;
		}
		return 'subject' === $part ? (string) $class::get_default_subject() : (string) $class::get_default_body();
	}

	/**
	 * Returns a part of the template of an email as the site's owner reads it in the plugin: the text
	 * they changed it to, or the plugin's own in the site's language.
	 *
	 * @param string $template The template, as the plugin names it.
	 * @param string $part     "subject" or "body".
	 * @return string|null Null for a template the plugin does not name.
	 */
	private static function originalTemplate( string $template, string $part ): ?string {
		$class = self::templateClass( $template );
		if ( null === $class ) {
			return null;
		}
		swiftling_pause_translation();
		try {
			$stored = get_option( 'pmpro_email_' . $template . '_' . $part );
			if ( is_string( $stored ) && '' !== $stored ) {
				return $stored;
			}
			return 'subject' === $part ? (string) $class::get_default_subject() : (string) $class::get_default_body();
		} finally {
			swiftling_resume_translation();
		}
	}

	/**
	 * Returns the class the plugin writes a template of an email with.
	 *
	 * @param string $template The template, as the plugin names it.
	 * @return string|null Null for a template the plugin does not name.
	 */
	private static function templateClass( string $template ): ?string {
		if ( ! class_exists( 'PMPro_Email_Template' ) ) {
			return null;
		}
		$class = \PMPro_Email_Template::get_email_template( $template );
		return is_string( $class ) && class_exists( $class ) ? $class : null;
	}

	/**
	 * Returns the templates of emails to members, by name: the plugin's own but those to the site's
	 * owner.
	 *
	 * @return array<string, string> The class of each.
	 */
	private static function memberTemplates(): array {
		if ( ! class_exists( 'PMPro_Email_Template' ) ) {
			return array();
		}
		$templates = array();
		foreach ( (array) \PMPro_Email_Template::get_all_email_templates() as $template => $class ) {
			if ( is_string( $template ) && is_string( $class ) && class_exists( $class ) && ! self::isOwnersEmail( $template ) ) {
				$templates[ $template ] = $class;
			}
		}
		return $templates;
	}

	/**
	 * Whether a template of an email goes to the site's owner: the plugin names those with "_admin".
	 *
	 * @param string $template The template, as the plugin names it.
	 */
	private static function isOwnersEmail( string $template ): bool {
		return '_admin' === substr( $template, -6 );
	}

	/**
	 * Returns the template of the plugin that is sending the email of the moment, from the calls that led
	 * here: the plugin hands no filter the template, which can write the email again.
	 *
	 * @return object|null
	 */
	private static function templateBeingSent() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Which template of the plugin is sending, to send it again; read, never shown.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 12 ) as $call ) {
			if ( 'send' === ( $call['function'] ?? '' ) && isset( $call['object'] ) && $call['object'] instanceof \PMPro_Email_Template ) {
				return $call['object'];
			}
		}
		return null;
	}

	/**
	 * Returns the header of an email of the plugin, translated for a member's email in a language.
	 *
	 * @param mixed $header The header, as the plugin wrote it.
	 * @param mixed $email  The plugin's email.
	 * @return mixed
	 */
	public function translateHeader( $header, $email = null ) {
		return $this->translatePart( 'header', $header, $email );
	}

	/**
	 * Returns the footer of an email of the plugin, translated for a member's email in a language.
	 *
	 * @param mixed $footer The footer, as the plugin wrote it.
	 * @param mixed $email  The plugin's email.
	 * @return mixed
	 */
	public function translateFooter( $footer, $email = null ) {
		return $this->translatePart( 'footer', $footer, $email );
	}

	/**
	 * Returns the header or footer of an email in the language the request writes in, where it is the
	 * one the site's owner keeps, and the email a member's.
	 *
	 * @param string $part  "header" or "footer".
	 * @param mixed  $text  The part, as the plugin wrote it.
	 * @param mixed  $email The plugin's email.
	 * @return mixed
	 */
	private function translatePart( string $part, $text, $email ) {
		if ( ! is_string( $text ) || '' === $text || ! is_object( $email ) || ! is_string( $email->template ?? null ) || self::isOwnersEmail( $email->template ) || '' === swiftling_writing_language() ) {
			return $text;
		}
		$original = self::originalPart( $part );
		if ( $original !== $text ) {
			return $text;
		}
		$translated = swiftling_transform_text( self::BASE . ':email:' . $part, self::BASE . '/body', self::BASE . ':email', $original );
		return is_string( $translated ) ? $translated : $text;
	}

	/**
	 * Returns the header or footer of every email as the site's owner keeps it: the text they changed it
	 * to, or the plugin's own, which it writes in the site's language as it loads.
	 *
	 * @param string $part "header" or "footer".
	 */
	private static function originalPart( string $part ): string {
		swiftling_pause_translation();
		try {
			$stored = get_option( 'pmpro_email_' . $part . '_body' );
		} finally {
			swiftling_resume_translation();
		}
		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}
		$defaults = $GLOBALS['pmpro_email_templates_defaults'] ?? array();
		return is_array( $defaults ) && isset( $defaults[ $part ]['body'] ) && is_string( $defaults[ $part ]['body'] ) ? $defaults[ $part ]['body'] : '';
	}

	/**
	 * Returns the messages the plugin keeps in options: for visitors without a membership, not signed in,
	 * in the feed, and the instructions of paying by check.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array(
			'pmpro_nonmembertext'   => array( '' ),
			'pmpro_notloggedintext' => array( '' ),
			'pmpro_rsstext'         => array( '' ),
			'pmpro_instructions'    => array( '' ),
		);
	}

	/**
	 * Returns what the editor calls the messages.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array(
			'pmpro_nonmembertext'   => array( '' => __( 'Message for visitors without the membership', 'swiftling' ) ),
			'pmpro_notloggedintext' => array( '' => __( 'Message for visitors not signed in', 'swiftling' ) ),
			'pmpro_rsstext'         => array( '' => __( 'Message in the feed', 'swiftling' ) ),
			'pmpro_instructions'    => array( '' => __( 'Instructions for paying by check', 'swiftling' ) ),
		);
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The membership levels of Paid Memberships Pro, its messages and the emails to members, which go out in the language the member registered in. Emails to you stay in your language. Keep every placeholder, such as !!name!! or {{ name }}.', 'swiftling' );
	}

	/**
	 * Reads the texts of the levels, the templates of emails to members and the header and footer of
	 * every email for the subject of the settings.
	 */
	protected function readMoreTexts(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pmpro_membership_levels';
		if ( $this->hasTable( $table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, description, confirmation FROM %i ORDER BY id', $table ) ) as $row ) {
				$this->translateLevel( (object) $row );
			}
		}
		foreach ( array( 'header', 'footer' ) as $part ) {
			$text = self::originalPart( $part );
			if ( '' !== trim( $text ) ) {
				swiftling_transform_text( self::BASE . ':email:' . $part, self::BASE . '/body', self::BASE . ':email', $text );
			}
		}
		foreach ( array_keys( self::memberTemplates() ) as $template ) {
			foreach ( array( 'subject', 'body' ) as $part ) {
				$text = self::originalTemplate( $template, $part );
				if ( null !== $text && '' !== trim( $text ) ) {
					swiftling_transform_text( self::BASE . ':email:' . $template, self::BASE . '/' . $part, self::BASE . ':email', $text );
				}
			}
		}
	}

	/**
	 * Declares the texts of the levels and of the emails as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, self::BASE . ':' ) ) {
			return 0 === strpos( $path, self::BASE . '/' ) ? true : null;
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
		if ( 0 !== strpos( $owner, self::BASE . ':' ) ) {
			return parent::fieldLabel( $owner, $path );
		}
		$parts = explode( ':', $owner, 3 );
		$field = (string) substr( $path, strlen( self::BASE ) + 1 );
		if ( 'level' === ( $parts[1] ?? '' ) ) {
			$fields = array(
				'name'         => __( 'Name', 'swiftling' ),
				'description'  => __( 'Description', 'swiftling' ),
				'confirmation' => __( 'Confirmation message', 'swiftling' ),
			);
			/* translators: %s: ID of a membership level. */
			return sprintf( __( 'Level %s', 'swiftling' ), $parts[2] ?? '' ) . ' › ' . ( $fields[ $field ] ?? $field );
		}
		$template = $parts[2] ?? '';
		if ( 'header' === $template || 'footer' === $template ) {
			return 'header' === $template ? __( 'Header of every email', 'swiftling' ) : __( 'Footer of every email', 'swiftling' );
		}
		$class = self::templateClass( $template );
		$name  = null !== $class ? (string) $class::get_template_name() : $template;
		/* translators: %s: name of an email, e.g. Checkout - Free. */
		return sprintf( __( 'Email: %s', 'swiftling' ), $name ) . ' › ' . ( 'subject' === $field ? __( 'Subject', 'swiftling' ) : __( 'Body', 'swiftling' ) );
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
