<?php
/**
 * Charitable: its donation form, receipt and emails in the language of the donor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Charitable. A campaign is a post of the type "campaign", translated as any post. What SwiftLing does
 * not find by itself:
 *
 * - The descriptions of a campaign's suggested amounts are meta of the campaign, read where the donation
 *   form shows them, not on the campaign's page: offered with the campaign, see listTexts().
 * - The donation form is sent through admin-ajax.php, loaded through it where it opens in a window, and
 *   its amounts are loaded through it again after the page: answered in the language of the page, see
 *   sendingActions(), so the receipt the form leads to is the page in the language, and so are the
 *   emails sent with the donation.
 * - The texts of its emails to donors, of its gateways and of its terms are values of its settings, the
 *   option "charitable_settings", see textOptions(). A text of an email is one text with the plugin's
 *   shortcodes in it, see isWhole(). The emails to the site's owner are not offered.
 * - The language of a donation is kept with it, see keepLanguage(). An email to the donor is written in
 *   it, also when the plugin sends it later; an email to the site's owner in the original, see
 *   startEmail().
 * - The plugin keeps the names of a donation's campaigns with it, as they were when it was made: the
 *   receipt and an email name them by the campaigns' titles in the language, see receiptCampaigns() and
 *   emailCampaigns().
 *
 * Checked against the code of Charitable 1.8 (the free plugin), in tests/CharitableTest.php, and by hand
 * on a site with the plugin.
 */
class Charitable extends SettingsTexts {
	/**
	 * The meta of a donation SwiftLing keeps its language in.
	 */
	const LANGUAGE_META = 'swiftling_language';

	/**
	 * The meta of a campaign that holds its suggested amounts, each with a description.
	 */
	const AMOUNTS_META = '_campaign_suggested_donations';

	/**
	 * The plugin's emails to donors, and the texts of each in its settings.
	 */
	const DONOR_EMAILS = array( 'donation_receipt', 'offline_donation_receipt', 'email_verification', 'password_reset' );
	const EMAIL_TEXTS  = array( 'subject', 'headline', 'body' );

	/**
	 * The plugin's gateways, whose names a donor reads.
	 */
	const GATEWAYS = array( 'offline', 'paypal', 'paypal_commerce', 'stripe', 'square_core' );

	/**
	 * The emails being written, each whether SwiftLing changed the language for it.
	 *
	 * @var bool[]
	 */
	private $writing = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'charitable';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Charitable';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return class_exists( 'Charitable', false );
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
	 * Returns the actions of admin-ajax.php with which the plugin loads its donation form into a window,
	 * loads the parts of a form that depend on the visitor again after the page, as its amounts, receives
	 * a donation and makes and takes a payment through PayPal: answered in the language of the page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'make_donation', 'get_donation_form', 'charitable_get_session_content', 'charitable_paypal_commerce_create_order', 'charitable_paypal_commerce_capture_order' );
	}

	/**
	 * Hooks where the plugin saves a donation and sends an email.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		// Before the plugin sends the emails of the donation, at 10.
		add_action( 'charitable_after_save_donation', array( $this, 'keepLanguage' ), 1, 1 );
		add_action( 'charitable_after_save_donation', array( $this, 'endEmails' ), PHP_INT_MAX, 0 );
		add_action( 'charitable_before_send_email', array( $this, 'startEmail' ), 1, 1 );
		add_action( 'charitable_after_send_email', array( $this, 'endEmail' ), PHP_INT_MAX, 0 );
		add_action( 'charitable_donation_receipt_page', array( $this, 'receiptCampaigns' ) );
		add_filter( 'charitable_email_content_field_value_campaigns', array( $this, 'emailCampaigns' ), 10, 3 );
		// phpcs:enable
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Keeps the language a donor gave in with the donation.
	 *
	 * @param mixed $donation_id The donation.
	 */
	public function keepLanguage( $donation_id ): void {
		$slug = self::pageSlug();
		if ( '' !== $slug && is_numeric( $donation_id ) && (int) $donation_id > 0 ) {
			update_post_meta( (int) $donation_id, self::LANGUAGE_META, $slug );
		}
	}

	/**
	 * Before the plugin writes an email: one to the site's owner in the original, one to a donor in the
	 * language of the donation, or of the request when the email is about none. Until endEmail().
	 *
	 * @param mixed $email The plugin's email.
	 */
	public function startEmail( $email ): void {
		// An email the plugin gave up on before it was sent never reached endEmail().
		$this->endEmails();
		$id    = is_object( $email ) && method_exists( $email, 'get_email_id' ) ? (string) $email->get_email_id() : '';
		$slug  = in_array( $id, self::DONOR_EMAILS, true ) ? $this->donationLanguage( $email ) : '';
		$write = self::pageSlug() !== $slug && ( '' === $slug || null !== swiftling_target_language( $slug ) );
		if ( $write ) {
			swiftling_write_in( $slug );
		}
		$this->writing[] = $write;
	}

	/**
	 * After the plugin sent an email: the request goes on in its own language.
	 */
	public function endEmail(): void {
		if ( array_pop( $this->writing ) ) {
			swiftling_resume_translation();
		}
	}

	/**
	 * Ends every email still being written, so the request goes on in its own language.
	 */
	public function endEmails(): void {
		while ( $this->writing ) {
			$this->endEmail();
		}
	}

	/**
	 * Before the plugin prints a donation's receipt: the names of the campaigns, which the plugin keeps with
	 * the donation as they were when it was made, are the titles of the campaigns in the language. Only
	 * the donation the receipt prints, for this request; nothing is saved.
	 *
	 * @param mixed $donation The donation.
	 */
	public function receiptCampaigns( $donation ): void {
		if ( 'translate' !== swiftling_mode() || ! is_object( $donation ) || ! method_exists( $donation, 'get_campaign_donations' ) ) {
			return;
		}
		foreach ( (array) $donation->get_campaign_donations() as $record ) {
			if ( is_object( $record ) && isset( $record->campaign_id, $record->campaign_name ) && is_string( $record->campaign_name ) ) {
				$record->campaign_name = swiftling_filter_title( $record->campaign_name, (int) $record->campaign_id );
			}
		}
	}

	/**
	 * Returns the campaigns of a donation as an email names them, [charitable_email show=campaigns], with
	 * their titles in the language the email is written in.
	 *
	 * @param mixed $value The names, or links to the campaigns.
	 * @param mixed $args  The shortcode's attributes.
	 * @param mixed $email The plugin's email.
	 * @return mixed
	 */
	public function emailCampaigns( $value, $args = array(), $email = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The plugin passes the attributes before the email.
		$donation = is_object( $email ) && method_exists( $email, 'get_donation' ) ? $email->get_donation() : null;
		if ( ! is_string( $value ) || 'translate' !== swiftling_mode() || ! is_object( $donation ) || ! method_exists( $donation, 'get_campaign_donations' ) ) {
			return $value;
		}
		$names = array();
		foreach ( (array) $donation->get_campaign_donations() as $record ) {
			if ( is_object( $record ) && isset( $record->campaign_id, $record->campaign_name ) && is_string( $record->campaign_name ) && '' !== $record->campaign_name ) {
				$names[ $record->campaign_name ] = swiftling_filter_title( $record->campaign_name, (int) $record->campaign_id );
			}
		}
		return strtr( $value, $names );
	}

	/**
	 * Returns the language of the donation an email is about: the one kept with it, or the request's when
	 * the email is about none, as a confirmation of an address.
	 *
	 * @param object $email The plugin's email.
	 * @return string Address slug, or an empty string for the original.
	 */
	private function donationLanguage( $email ): string {
		$donation = method_exists( $email, 'get_donation' ) ? $email->get_donation() : null;
		if ( ! is_object( $donation ) || empty( $donation->ID ) ) {
			return self::pageSlug();
		}
		$slug = get_post_meta( (int) $donation->ID, self::LANGUAGE_META, true );
		return is_string( $slug ) && '' !== $slug && null !== swiftling_target_language( $slug ) ? $slug : '';
	}

	/**
	 * Returns the plugin's settings that hold text: its terms and consent, the names of its gateways and
	 * the instructions of an offline donation, and the texts of its emails to donors.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$texts = array( 'contact_consent_label', 'privacy_policy', 'terms_conditions', 'gateways_offline/instructions' );
		foreach ( self::GATEWAYS as $gateway ) {
			$texts[] = 'gateways_' . $gateway . '/label';
		}
		foreach ( self::DONOR_EMAILS as $email ) {
			foreach ( self::EMAIL_TEXTS as $text ) {
				$texts[] = 'emails_' . $email . '/' . $text;
			}
		}
		return array( 'charitable_settings' => $texts );
	}

	/**
	 * Returns what the editor calls the texts of the plugin's settings.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		$labels = array(
			'contact_consent_label'         => __( 'Consent to be contacted', 'swiftling' ),
			'privacy_policy'                => __( 'Privacy policy', 'swiftling' ),
			'terms_conditions'              => __( 'Terms and conditions', 'swiftling' ),
			'gateways_offline/instructions' => __( 'Instructions for an offline donation', 'swiftling' ),
		);
		foreach ( self::GATEWAYS as $gateway ) {
			/* translators: %s: name of a way to pay, e.g. offline. */
			$labels[ 'gateways_' . $gateway . '/label' ] = sprintf( __( 'Name of the payment method: %s', 'swiftling' ), str_replace( '_', ' ', $gateway ) );
		}
		$names = array(
			'subject'  => __( 'Subject', 'swiftling' ),
			'headline' => __( 'Heading', 'swiftling' ),
			'body'     => __( 'Text', 'swiftling' ),
		);
		foreach ( self::DONOR_EMAILS as $email ) {
			foreach ( $names as $text => $name ) {
				$labels[ 'emails_' . $email . '/' . $text ] = ucfirst( str_replace( '_', ' ', $email ) ) . ' › ' . $name;
			}
		}
		return array( 'charitable_settings' => $labels );
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		return __( 'The texts of the emails Charitable sends donors, the names of its payment methods and its terms. Each donor gets the emails in the language they donated in; the emails to you stay in the original. Keep every shortcode in square brackets, such as [charitable_email show=donor].', 'swiftling' );
	}

	/**
	 * Whether a value is one text as a whole: the subject, heading and text of an email, whose placeholders
	 * are the plugin's shortcode [charitable_email show=...].
	 *
	 * @param string $path Path of the value inside its object.
	 */
	public function isWhole( string $path ): bool {
		return 0 === strpos( $path, 'option/charitable_settings/emails_' ) && 1 === preg_match( '#^option/charitable_settings/emails_[a-z_]+/(' . implode( '|', self::EMAIL_TEXTS ) . ')$#', $path );
	}

	/**
	 * Keeps the plugin's shortcodes of its emails as they are when a text goes to machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, '/\[charitable_email\b[^\]]*\]/' );
	}

	/**
	 * Returns the texts of a subject: of the plugin's settings, or of a campaign beside those of its page,
	 * the descriptions of its suggested amounts.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$post = $subject['post'] ?? null;
		if ( 'post' !== ( $subject['kind'] ?? '' ) || ! $post instanceof \WP_Post ) {
			return parent::listTexts( $subject );
		}
		if ( 'campaign' !== $post->post_type ) {
			return array();
		}
		return $this->collect(
			static function () use ( $post ): void {
				get_post_meta( $post->ID, self::AMOUNTS_META, true );
			}
		);
	}

	/**
	 * Returns what the editor calls a text of the plugin.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $owner, 'post:' ) && preg_match( '#^' . preg_quote( swiftling_meta_path( self::AMOUNTS_META, 0 ), '#' ) . '/(\d+)/description$#', $path, $found ) ) {
			/* translators: %d: number of a suggested amount of a campaign, counted from one. */
			return sprintf( __( 'Suggested amount %d › Description', 'swiftling' ), (int) $found[1] + 1 );
		}
		return parent::fieldLabel( $owner, $path );
	}

	/**
	 * Returns the address slug of the language the request runs in, or an empty string for the original.
	 */
	private static function pageSlug(): string {
		$state = &swiftling_state();
		return 'translate' === $state['mode'] ? (string) $state['language'] : '';
	}
}
