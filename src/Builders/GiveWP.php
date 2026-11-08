<?php
/**
 * GiveWP: its emails, campaigns and the language of a donation.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

use SwiftLing\Forms\GiveWP as GiveWPForms;

defined( 'ABSPATH' ) || exit;

/**
 * GiveWP, beside its forms, which SwiftLing\Forms\GiveWP translates:
 *
 * - The texts of its emails to donors and of its terms are values of its settings, the option
 *   "give_settings", which the plugin keeps in memory from the start of a request and hands out one by
 *   one, see giveOption(). The emails to the site's owner are not offered.
 * - A campaign's title and short description are kept in the plugin's own table: a subject of each
 *   campaign on the Templates screen, "give-campaign:<ID>", translated where the plugin's block prints
 *   the title and where its REST API hands campaigns to its blocks of campaigns, see translateCampaigns();
 *   those requests are answered in the language of the page that holds the block, see sendingRoutes().
 *   The page of a campaign holds its description as content, translated as the page.
 * - The language of a donation is kept with it, see keepLanguage(). An email to the donor is written in
 *   it, also when the plugin writes it later, as when the site's owner sends a receipt again; an email
 *   to the site's owner in the original, see startEmail(). The plugin works out the default subject and
 *   heading of its emails once a request, in the request's language: an email in another language gets
 *   them from the plugin's language pack again, see defaultText().
 *
 * Checked against the code of GiveWP 4.19 (the free plugin), in tests/GiveWPTest.php, and by hand on a
 * site with the plugin.
 */
class GiveWP extends SettingsTexts {
	/**
	 * The meta of a donation SwiftLing keeps its language in.
	 */
	const LANGUAGE_META = 'swiftling_language';

	/**
	 * The plugin's settings that hold text a visitor reads, beside those of the emails to donors.
	 */
	const SETTING_TEXTS = array( 'agree_to_terms_label', 'agreement_text', 'global_offline_donation_content' );

	/**
	 * The plugin's emails to donors, and the texts of each in its settings.
	 */
	const DONOR_EMAILS = array( 'donation-receipt', 'donation-processing-receipt', 'offline-donation-instruction', 'donor-register', 'donor-note', 'email-access' );
	const EMAIL_TEXTS  = array( 'email_subject', 'email_header', 'email_message' );

	/**
	 * The texts of a campaign, by the plugin's name of them and the column of its table.
	 */
	const CAMPAIGN_TEXTS = array(
		'title'            => 'campaign_title',
		'shortDescription' => 'short_desc',
	);

	/**
	 * The donation the plugin writes an email for now.
	 *
	 * @var int
	 */
	private $donation = 0;

	/**
	 * The emails being written, each whether SwiftLing changed the language for it, and the original of
	 * its default texts.
	 *
	 * @var array<int, array{changed: bool, id: string, defaults: array<string, array{0: string, 1: string}>}>
	 */
	private $writing = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'give';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'GiveWP';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'GIVE_VERSION' );
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
	 * Returns the routes of the plugin's REST API its blocks of a campaign and of campaigns read campaigns
	 * from: answered in the language of the page that holds the block.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/givewp/v3/campaigns(/\d+)?/?$#' );
	}

	/**
	 * Hooks where the plugin hands out a setting, makes a donation, writes an email, prints a campaign's
	 * title and hands campaigns out through its REST API.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		add_filter( 'give_get_option', array( $this, 'giveOption' ), 10, 2 );
		add_action( 'give_insert_payment', array( $this, 'keepLanguage' ), 10, 1 );
		add_filter( 'give_get_donation_form_title', array( $this, 'formTitle' ), 10, 2 );
		add_filter( 'render_block_givewp/campaign-title', array( $this, 'campaignTitle' ), 10, 2 );
		// phpcs:enable
		add_filter( 'rest_post_dispatch', array( $this, 'translateCampaigns' ), 10, 3 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
		// The plugin makes its emails at init, -1.
		add_action( 'init', array( $this, 'hookEmails' ), 20 );
	}

	/**
	 * Hooks where the plugin writes each of its emails: it names its hooks after the email.
	 */
	public function hookEmails(): void {
		if ( ! class_exists( 'Give_Email_Notifications' ) ) {
			return;
		}
		foreach ( (array) \Give_Email_Notifications::get_instance()->get_email_notifications() as $email ) {
			if ( is_object( $email ) && isset( $email->config['id'] ) && '' !== (string) $email->config['id'] ) {
				$this->hookEmail( (string) $email->config['id'] );
			}
		}
	}

	/**
	 * Hooks where the plugin writes one of its emails.
	 *
	 * @param string $id The email, as "donation-receipt".
	 */
	public function hookEmail( string $id ): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		add_filter( "give_{$id}_email_tag_args", array( $this, 'noteDonation' ), 10, 1 );
		add_action( "give_{$id}_email_send_before", array( $this, 'startEmail' ), 1, 1 );
		add_action( "give_{$id}_email_send_after", array( $this, 'endEmail' ), PHP_INT_MAX, 0 );
		foreach ( array( 'subject', 'header', 'message' ) as $part ) {
			add_filter( "give_{$id}_get_email_{$part}", array( $this, 'defaultText' ), 1, 2 );
		}
		// phpcs:enable
	}

	/**
	 * Returns a setting of the plugin that holds text in the mode of the moment.
	 *
	 * @param mixed $value The setting.
	 * @param mixed $key   Its name.
	 * @return mixed
	 */
	public function giveOption( $value, $key = '' ) {
		return is_string( $key ) && in_array( $key, $this->settingTexts(), true ) ? $this->optionText( 'give_settings', $key, $value ) : $value;
	}

	/**
	 * Returns the plugin's settings that hold text: its terms, and the texts of its emails to donors.
	 *
	 * @return string[]
	 */
	private function settingTexts(): array {
		$texts = self::SETTING_TEXTS;
		foreach ( self::DONOR_EMAILS as $email ) {
			foreach ( self::EMAIL_TEXTS as $text ) {
				$texts[] = $email . '_' . $text;
			}
		}
		return $texts;
	}

	/**
	 * Returns the plugin's settings that hold text.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array( 'give_settings' => $this->settingTexts() );
	}

	/**
	 * Returns what the editor calls the texts of the plugin's settings.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		$labels = array(
			'agree_to_terms_label'            => __( 'Label of the terms', 'swiftling' ),
			'agreement_text'                  => __( 'Terms', 'swiftling' ),
			'global_offline_donation_content' => __( 'Instructions for an offline donation', 'swiftling' ),
		);
		$names  = array(
			'email_subject' => __( 'Subject', 'swiftling' ),
			'email_header'  => __( 'Heading', 'swiftling' ),
			'email_message' => __( 'Text', 'swiftling' ),
		);
		foreach ( self::DONOR_EMAILS as $email ) {
			foreach ( $names as $text => $name ) {
				$labels[ $email . '_' . $text ] = ucfirst( str_replace( '-', ' ', $email ) ) . ' › ' . $name;
			}
		}
		return array( 'give_settings' => $labels );
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		return __( 'The texts of the emails GiveWP sends donors, and its terms. Each donor gets the emails in the language they donated in; the emails to you stay in the original. Keep every placeholder in braces, such as {name}.', 'swiftling' );
	}

	/**
	 * Keeps the placeholders of the plugin's templates, as {first_name}, as they are when a text goes to
	 * machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, GiveWPForms::PLACEHOLDER );
	}

	/**
	 * Keeps the language a donor gave in with the donation.
	 *
	 * @param mixed $donation_id The donation.
	 */
	public function keepLanguage( $donation_id ): void {
		$slug = self::pageSlug();
		if ( '' !== $slug && is_numeric( $donation_id ) && function_exists( 'give_update_meta' ) ) {
			give_update_meta( (int) $donation_id, self::LANGUAGE_META, $slug );
		}
	}

	/**
	 * Notes the donation the plugin writes an email for.
	 *
	 * @param mixed $args What the email's placeholders name, with the donation as "payment_id".
	 * @return mixed
	 */
	public function noteDonation( $args ) {
		$this->donation = is_array( $args ) && isset( $args['payment_id'] ) && is_numeric( $args['payment_id'] ) ? (int) $args['payment_id'] : 0;
		return $args;
	}

	/**
	 * Before the plugin writes an email: one to the site's owner in the original, one to a donor in the
	 * language of the donation, or of the request when the email is about none. Until endEmail().
	 *
	 * @param mixed $email The plugin's email.
	 */
	public function startEmail( $email ): void {
		$owner = is_object( $email ) && ! empty( $email->config['has_recipient_field'] );
		$slug  = $owner ? '' : $this->donationLanguage();
		$entry = array(
			'changed'  => false,
			'id'       => is_object( $email ) && isset( $email->config['id'] ) ? (string) $email->config['id'] : '',
			'defaults' => array(),
		);
		if ( self::pageSlug() !== $slug && ( '' === $slug || null !== swiftling_target_language( $slug ) ) ) {
			// The plugin worked its default texts out in the request's language: their originals are found now.
			foreach ( array( 'subject', 'header', 'message' ) as $part ) {
				$default = is_object( $email ) && isset( $email->config[ 'default_email_' . $part ] ) && is_string( $email->config[ 'default_email_' . $part ] ) ? $email->config[ 'default_email_' . $part ] : '';
				if ( '' !== $default ) {
					$entry['defaults'][ $part ] = array( $default, self::original( $default ) );
				}
			}
			swiftling_write_in( $slug );
			$entry['changed'] = true;
		}
		$this->writing[] = $entry;
	}

	/**
	 * After the plugin sent an email: the request goes on in its own language.
	 */
	public function endEmail(): void {
		$entry = array_pop( $this->writing );
		if ( null !== $entry && $entry['changed'] ) {
			swiftling_resume_translation();
		}
		$this->donation = 0;
	}

	/**
	 * Returns a part of an email in the language it is written in: a default text of the plugin the
	 * request worked out in another language comes from the plugin's language pack again, in the locale
	 * the email is written in.
	 *
	 * @param mixed $text  The subject, heading or text.
	 * @param mixed $email The plugin's email.
	 * @return mixed
	 */
	public function defaultText( $text, $email = null ) {
		$entry = end( $this->writing );
		if ( ! is_string( $text ) || false === $entry || ! is_object( $email ) || ( $email->config['id'] ?? '' ) !== $entry['id'] ) {
			return $text;
		}
		foreach ( $entry['defaults'] as $default ) {
			if ( $text === $default[0] ) {
				// From the plugin's language pack in the locale of the moment, which WordPress loaded on switching.
				return (string) get_translations_for_domain( 'give' )->translate( $default[1] );
			}
		}
		return $text;
	}

	/**
	 * Returns the original of a text of the plugin's language pack, as the request translated it: the text
	 * itself when the pack has none.
	 *
	 * @param string $text A text of the plugin, translated.
	 */
	private static function original( string $text ): string {
		// WordPress's translations hand their entries out through a magic property, which isset() does not see.
		$entries = get_translations_for_domain( 'give' )->entries;
		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			if ( is_object( $entry ) && isset( $entry->translations ) && in_array( $text, (array) $entry->translations, true ) ) {
				return (string) $entry->singular;
			}
		}
		return $text;
	}

	/**
	 * Returns the language of the donation the plugin writes an email for: the one kept with it, or the
	 * request's.
	 *
	 * @return string Address slug, or an empty string for the original.
	 */
	private function donationLanguage(): string {
		if ( $this->donation && function_exists( 'give_get_meta' ) ) {
			$slug = give_get_meta( $this->donation, self::LANGUAGE_META, true );
			if ( is_string( $slug ) && '' !== $slug ) {
				return null !== swiftling_target_language( $slug ) ? $slug : '';
			}
			return '';
		}
		return self::pageSlug();
	}

	/**
	 * Returns the title of a donation's form in the mode of the moment, as an email names it.
	 *
	 * @param mixed $title       The title, maybe with the chosen amount's description after it.
	 * @param mixed $donation_id The donation.
	 * @return mixed
	 */
	public function formTitle( $title, $donation_id = 0 ) {
		$forms = Registry::get( 'givewp' );
		if ( ! is_string( $title ) || 'translate' !== swiftling_mode() || ! $forms instanceof GiveWPForms || ! function_exists( 'give_get_payment_form_id' ) || ! is_numeric( $donation_id ) ) {
			return $title;
		}
		return $forms->translateTitle( (int) give_get_payment_form_id( (int) $donation_id ), $title );
	}

	/**
	 * Returns the title of a campaign as the plugin's block prints it, in the mode of the moment.
	 *
	 * @param mixed $html  The block, as printed.
	 * @param mixed $block The block, with the campaign in its attributes.
	 * @return mixed
	 */
	public function campaignTitle( $html, $block = array() ) {
		$id = is_array( $block ) && isset( $block['attrs']['campaignId'] ) ? (int) $block['attrs']['campaignId'] : 0;
		if ( ! is_string( $html ) || ! $id || ! self::inMode() ) {
			return $html;
		}
		$campaign = $this->campaign( $id );
		if ( null === $campaign || '' === $campaign['title'] ) {
			return $html;
		}
		$translated = $this->campaignText( $id, 'title', $campaign['title'] );
		return $translated === $campaign['title'] ? $html : str_replace( esc_html( $campaign['title'] ), esc_html( $translated ), $html );
	}

	/**
	 * Returns campaigns the plugin's REST API hands out with their titles and short descriptions in the
	 * mode of the moment: to its blocks of a campaign and of campaigns.
	 *
	 * @param mixed $response The response.
	 * @param mixed $server   The REST server.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public function translateCampaigns( $response, $server = null, $request = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress passes the server first.
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || 'translate' !== swiftling_mode() || 0 !== strpos( $request->get_route(), '/givewp/v3/campaigns' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		$list = isset( $data['id'] ) ? array( $data ) : $data;
		foreach ( $list as $index => $campaign ) {
			if ( ! is_array( $campaign ) || ! isset( $campaign['id'] ) || ! is_numeric( $campaign['id'] ) ) {
				continue;
			}
			foreach ( array_keys( self::CAMPAIGN_TEXTS ) as $text ) {
				if ( isset( $campaign[ $text ] ) && is_string( $campaign[ $text ] ) ) {
					$list[ $index ][ $text ] = $this->campaignText( (int) $campaign['id'], $text, $campaign[ $text ] );
				}
			}
		}
		$response->set_data( isset( $data['id'] ) ? $list[0] : $list );
		return $response;
	}

	/**
	 * Returns a text of a campaign in the mode of the moment.
	 *
	 * @param int    $id   The campaign.
	 * @param string $text Which text, see CAMPAIGN_TEXTS.
	 * @param string $value The text.
	 */
	private function campaignText( int $id, string $text, string $value ): string {
		if ( '' === trim( $value ) ) {
			return $value;
		}
		$translated = swiftling_transform_text( self::campaignOwner( $id ), 'campaign/' . $text, $this->id() . ':campaign', $value );
		return is_string( $translated ) ? $translated : $value;
	}

	/**
	 * Returns the object key of a campaign's texts.
	 *
	 * @param int $id The campaign.
	 */
	private static function campaignOwner( int $id ): string {
		return 'give:campaign:' . $id;
	}

	/**
	 * Returns the plugin's campaigns, drafts too: their titles and short descriptions, by ID.
	 *
	 * @return array<int, array{title: string, shortDescription: string}>
	 */
	protected function campaigns(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'give_campaigns';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the Templates screen and the editor.
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, campaign_title, short_desc FROM %i WHERE status <> 'archived' ORDER BY id LIMIT %d", $table, 200 ) );
		$campaigns = array();
		foreach ( $rows as $row ) {
			$campaigns[ (int) $row->id ] = array(
				'title'            => (string) $row->campaign_title,
				'shortDescription' => (string) $row->short_desc,
			);
		}
		return $campaigns;
	}

	/**
	 * Returns a campaign's texts.
	 *
	 * @param int $id The campaign.
	 * @return array{title: string, shortDescription: string}|null
	 */
	private function campaign( int $id ): ?array {
		return $this->campaigns()[ $id ] ?? null;
	}

	/**
	 * Returns the subjects of the Templates screen: the plugin's settings and each campaign.
	 *
	 * @return array
	 */
	public function subjects(): array {
		$groups    = parent::subjects();
		$campaigns = array();
		foreach ( $this->campaigns() as $id => $campaign ) {
			$campaigns[] = array(
				'key'         => 'give-campaign:' . $id,
				'title'       => '' !== trim( $campaign['title'] ) ? $campaign['title'] : __( 'No title', 'swiftling' ),
				'description' => __( 'Campaign', 'swiftling' ),
			);
		}
		if ( $campaigns ) {
			$groups[0]['subjects'] = array_merge( $groups[0]['subjects'], $campaigns );
		}
		return $groups;
	}

	/**
	 * Returns a subject of the editor: the plugin's settings, or a campaign.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		$id = self::campaignOf( $key );
		if ( ! $id ) {
			return parent::subject( $key );
		}
		$campaign = $this->campaign( $id );
		if ( null === $campaign ) {
			return null;
		}
		return array(
			'key'   => $key,
			'kind'  => 'fields',
			'title' => '' !== trim( $campaign['title'] ) ? $campaign['title'] : __( 'No title', 'swiftling' ),
			'type'  => __( 'Campaign of GiveWP', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => 'manage_options',
			'note'  => __( 'The title and short description of this campaign, as GiveWP shows them in its blocks of campaigns. The page of the campaign is translated as any page.', 'swiftling' ),
		);
	}

	/**
	 * Returns the texts of a subject: of the plugin's settings, or of a campaign.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$id = self::campaignOf( (string) $subject['key'] );
		if ( ! $id ) {
			return parent::listTexts( $subject );
		}
		$campaign = $this->campaign( $id );
		if ( null === $campaign ) {
			return array();
		}
		return $this->collect(
			function () use ( $id, $campaign ): void {
				foreach ( array_keys( self::CAMPAIGN_TEXTS ) as $text ) {
					$this->campaignText( $id, $text, $campaign[ $text ] );
				}
			}
		);
	}

	/**
	 * Returns the campaign a subject names.
	 *
	 * @param string $key Subject key.
	 * @return int Zero when the key names none.
	 */
	private static function campaignOf( string $key ): int {
		return 0 === strpos( $key, 'give-campaign:' ) && ctype_digit( substr( $key, 14 ) ) ? (int) substr( $key, 14 ) : 0;
	}

	/**
	 * Declares the texts of the plugin's settings and of its campaigns as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, 'give:campaign:' ) ) {
			return 0 === strpos( $path, 'campaign/' ) ? true : null;
		}
		return parent::fieldHoldsText( $owner, $path );
	}

	/**
	 * Returns what the editor calls a text of the plugin.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $owner, 'give:campaign:' ) ) {
			return 'campaign/title' === $path ? __( 'Title', 'swiftling' ) : __( 'Short description', 'swiftling' );
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
