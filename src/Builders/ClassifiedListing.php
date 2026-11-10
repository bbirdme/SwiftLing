<?php
/**
 * Classified Listing: what of a classified ad SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Classified Listing. An ad is a post of the type "rtcl_listing", its categories and locations terms,
 * and the labels of its form builder print on the pages that show them: SwiftLing finds them there.
 * What it does not find by itself:
 *
 * - The plugin loads its filters, lists of categories and locations, its forms and its search from the
 *   page through admin-ajax.php: answered in the language of that page, see sendingActions().
 * - An ad keeps the language of the page it was posted or edited on, see keepLanguage(). The plugin
 *   writes its emails to the ad's owner, as that the ad is published or that a visitor wrote, in that
 *   language, those to a visitor, as after registering, in the language of the page, and those to the
 *   site's owner in the original, see startEmail(). The plugin itself puts the emails to an owner in
 *   the site's language: this class decides instead, see switchLocale().
 * - The subjects and headings of the emails to owners and visitors are values of the plugin's
 *   settings: declared as text and offered on the Templates screen, see textOptions(). They hold the
 *   plugin's placeholders, as {listing_title}, which machine translation keeps.
 * - The plugin builds the rules of its addresses on init from the addresses of its pages, which
 *   SwiftLing builds with their original slugs there, see swiftling_builds_rules(), and from words of
 *   its language pack and a whole address, which this class puts in the original, see originalBases(),
 *   ruleWord() and ruleEndpoints(). In another language they changed, so the plugin wrote its rules
 *   again on every request.
 * - An ad gets no separate copy: its views, promotions and orders are kept by its ID. It is translated
 *   in place, see noCopyTypes().
 *
 * Checked against the code of Classified Listing 6.2 (the free plugin), in tests/JobBoardsTest.php,
 * and by hand on a site with the plugin. Its Pro add-on and its payments were not checked.
 */
class ClassifiedListing extends SettingsTexts {
	/**
	 * The plugin's type of post.
	 */
	const POST_TYPE = 'rtcl_listing';

	/**
	 * Where an ad keeps the language it was posted in.
	 */
	const LANGUAGE_META = 'swiftling_language';

	/**
	 * The option with the subjects and headings of the plugin's emails.
	 */
	const TEMPLATES = 'rtcl_email_templates_settings';

	/**
	 * A placeholder of the plugin's emails.
	 */
	const PLACEHOLDER = '/\{[a-z][a-z_]*\}/';

	/**
	 * The plugin's emails, by their class: "owner" to an ad's owner, in the ad's language; "visitor" to
	 * the person on the page, in its language; "site" to the site's owner, in the original.
	 */
	const EMAILS = array(
		'ContactEmailToAdmin'                => 'site',
		'ListingContactEmailToAdmin'         => 'site',
		'ListingExpiredEmailToAdmin'         => 'site',
		'ListingSubmittedEmailToAdmin'       => 'site',
		'ListingUpdateEmailToAdmin'          => 'site',
		'OrderCompletedEmailToAdmin'         => 'site',
		'OrderCreatedEmailToAdmin'           => 'site',
		'ReportAbuseEmailToAdmin'            => 'site',
		'UserNewRegistrationEmailToAdmin'    => 'site',
		'ListingContactEmailToOwner'         => 'owner',
		'ListingExpiredEmailToOwner'         => 'owner',
		'ListingModerationEmailToOwner'      => 'owner',
		'ListingPublishedEmailToOwner'       => 'owner',
		'ListingRenewalEmailToOwner'         => 'owner',
		'ListingRenewalReminderEmailToOwner' => 'owner',
		'ListingSubmittedEmailToOwner'       => 'owner',
		'OrderCompletedEmailToCustomer'      => 'visitor',
		'OrderCreatedEmailToCustomer'        => 'visitor',
		'UserNewRegistrationEmailToUser'     => 'visitor',
		'UserResetPasswordEmailToUser'       => 'visitor',
		'UserImportEmailToUser'              => 'visitor',
	);

	/**
	 * The keys of the plugin's emails, for its filter of an email's recipients.
	 */
	const EMAIL_IDS = array( 'contact_admin', 'listing_contact_admin', 'contact', 'expired', 'listing_moderation_to_user', 'listing_published', 'renewal', 'renewal_reminder', 'listing_submitted_admin', 'listing_submitted', 'listing_updated', 'order_completed_admin', 'order_completed', 'order_created_admin', 'order_created', 'report_abuse', 'user_import_email_to_user', 'new_user_registration_to_admin', 'new_user_registration_to_user', 'user_reset_password' );

	/**
	 * The plugin's words of its addresses it takes from its language pack, by their use.
	 */
	const BASES = array(
		'listing_base'  => 'listing',
		'category_base' => 'listing-category',
		'location_base' => 'listing-location',
		'tag_base'      => 'listing-tag',
	);

	/**
	 * The emails to owners and visitors whose subject and heading the settings keep, by key.
	 */
	const TEMPLATE_IDS = array( 'listing_submitted', 'listing_published', 'listing_moderation_to_user', 'renewal', 'renewal_reminder', 'expired', 'contact', 'order_created', 'order_completed', 'new_user_registration_to_user', 'user_reset_password' );

	/**
	 * The actions of admin-ajax.php the plugin sends to from a page, with texts in their answers or
	 * emails after them.
	 */
	const ACTIONS = array( 'rtcl_ajax_filter_get_sub_terms_html', 'rtcl_ajax_filter_load_data', 'rtcl_ajax_taxonomy_filter_get_sub_level_html', 'rtcl_child_dropdown_terms', 'rtcl_fb_get_category', 'rtcl_fb_get_location', 'rtcl_fb_get_tags', 'rtcl_gb_all_locations', 'rtcl_gb_categories', 'rtcl_gb_listing_cat_box', 'rtcl_gb_listing_promotion_ajax', 'rtcl_gb_listing_type_ajax', 'rtcl_gb_location_ajax', 'rtcl_gb_pricing_table_post', 'rtcl_gb_single_location', 'rtcl_get_all_cat_list_for_modal', 'rtcl_get_all_location_list_for_modal', 'rtcl_get_one_level_category_select_list', 'rtcl_get_one_level_category_select_list_by_type', 'rtcl_get_sub_location_options', 'rtcl_get_terms', 'rtcl_inline_search_autocomplete', 'rtcl_json_search_taxonomy', 'rtcl_login_request', 'rtcl_match_location_terms', 'rtcl_post_new_listing', 'rtcl_public_report_abuse', 'rtcl_public_send_contact_email', 'rtcl_registration_request', 'rtcl_tag_search', 'rtcl_update_listing', 'rtcl_user_ad_load_more' );

	/**
	 * The emails being written, the last the one the plugin writes now: each with whether its language
	 * was decided, and whether this class changed it.
	 *
	 * @var array<int, array{email: object, started: bool, changed: bool}>
	 */
	private $writing = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'classified-listing';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Classified Listing';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'RTCL_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin: an ad needs nothing of a builder.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- An ad needs nothing of a builder.
		return false;
	}

	/**
	 * Returns the plugin's ads: translated in place.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( self::POST_TYPE );
	}

	/**
	 * Returns the actions of admin-ajax.php the plugin sends to from a page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return self::ACTIONS;
	}

	/**
	 * Returns the values of the plugin's settings that hold text: the subject and heading of each email
	 * to an owner or a visitor.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$paths = array();
		foreach ( self::TEMPLATE_IDS as $email ) {
			$paths[] = $email . '_subject';
			$paths[] = $email . '_heading';
		}
		return array( self::TEMPLATES => $paths );
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		return __( 'The subjects and headings of the emails to the owners of ads, in the language of each ad, and to visitors, in the language of the page they used. Keep every placeholder, such as {listing_title}: the plugin fills it in.', 'swiftling' );
	}

	/**
	 * Hooks where the plugin saves an ad and writes an email.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		add_action( 'rtcl_listing_form_after_save_or_update', array( $this, 'keepLanguage' ), 10, 1 );
		add_filter( 'rtcl_allow_switching_email_locale', array( $this, 'switchLocale' ), PHP_INT_MAX, 2 );
		foreach ( self::EMAIL_IDS as $id ) {
			// Asked once the email knows its ad, before its subject and text.
			add_filter( 'rtcl_email_recipient_' . $id, array( $this, 'startEmail' ), PHP_INT_MAX, 1 );
		}
		add_filter( 'rtcl_email_placeholders', array( $this, 'translatePlaceholders' ), PHP_INT_MAX, 2 );
		add_filter( 'rtcl_allow_restoring_email_locale', array( $this, 'endEmail' ), PHP_INT_MAX, 2 );
		// phpcs:enable
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
		add_filter( 'rtcl_permalink_structure', array( $this, 'originalBases' ), PHP_INT_MAX, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		add_filter( 'gettext_with_context_classified-listing', array( $this, 'ruleWord' ), PHP_INT_MAX, 3 );
		add_filter( 'rtcl_my_account_endpoint', array( $this, 'ruleEndpoints' ), PHP_INT_MAX, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
	}

	/**
	 * Returns the words of the plugin's addresses, as "listing-category", in the site's language: the
	 * plugin takes those the site's owner did not set from its language pack, in the language of the
	 * request, so its rules changed with the language too. A translated base is SwiftLing's, on the
	 * Addresses screen.
	 *
	 * @param mixed $bases The words, by their use.
	 * @return mixed
	 */
	public function originalBases( $bases ) {
		if ( ! is_array( $bases ) || '' === swiftling_writing_language() ) {
			return $bases;
		}
		$words = get_translations_for_domain( 'classified-listing' );
		foreach ( self::BASES as $key => $word ) {
			if ( isset( $bases[ $key ] ) && (string) $words->translate( $word, 'slug' ) === $bases[ $key ] ) {
				$bases[ $key ] = self::siteWord( $word );
			}
		}
		return $bases;
	}

	/**
	 * Returns a word of the plugin's addresses in the site's language while the plugin builds its rules:
	 * it also hands the bases the site's owner set to its language pack.
	 *
	 * @param mixed $translation The word in the language of the request.
	 * @param mixed $text        The word.
	 * @param mixed $context     What the word is.
	 * @return mixed
	 */
	public function ruleWord( $translation, $text = '', $context = '' ) {
		if ( ! swiftling_builds_rules() || 'slug' !== $context || ! is_string( $text ) || '' === swiftling_writing_language() ) {
			return $translation;
		}
		return self::siteWord( $text );
	}

	/**
	 * Returns the words of the plugin's account page it makes rules of, while it makes them, with an
	 * address among them in the original language: the plugin names the form for an ad by its whole
	 * address, which in another language holds the language's folder.
	 *
	 * @param mixed $endpoints The words, by their use.
	 * @return mixed
	 */
	public function ruleEndpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) || ! swiftling_builds_rules() || '' === swiftling_writing_language() ) {
			return $endpoints;
		}
		foreach ( $endpoints as $key => $endpoint ) {
			if ( is_string( $endpoint ) && 0 === strpos( $endpoint, 'http' ) ) {
				// Page addresses keep their original slugs while rules are built: only the folder is the language's.
				$endpoints[ $key ] = swiftling_address_without_language( $endpoint );
			}
		}
		return $endpoints;
	}

	/**
	 * Returns a word of the plugin's language pack in the site's language.
	 *
	 * @param string $word The word.
	 */
	private static function siteWord( string $word ): string {
		static $words = array();
		if ( ! isset( $words[ $word ] ) ) {
			$switched       = switch_to_locale( swiftling_site_locale() );
			$words[ $word ] = (string) get_translations_for_domain( 'classified-listing' )->translate( $word, 'slug' );
			if ( $switched ) {
				restore_previous_locale();
			}
		}
		return $words[ $word ];
	}

	/**
	 * Keeps the language of the page an ad was posted or edited on; one in the original keeps none.
	 *
	 * @param mixed $listing The plugin's object of the ad.
	 */
	public function keepLanguage( $listing ): void {
		$id = is_object( $listing ) && method_exists( $listing, 'get_id' ) ? (int) $listing->get_id() : 0;
		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) ) {
			return;
		}
		$slug = swiftling_writing_language();
		if ( '' === $slug ) {
			delete_post_meta( $id, self::LANGUAGE_META );
		} else {
			update_post_meta( $id, self::LANGUAGE_META, $slug );
		}
	}

	/**
	 * Notes the email the plugin starts to write, and keeps the plugin from putting it in the site's
	 * language: this class puts each in the language of its reader, see startEmail().
	 *
	 * @param mixed $allow Whether the plugin switches.
	 * @param mixed $email The plugin's email.
	 * @return mixed
	 */
	public function switchLocale( $allow, $email = null ) {
		unset( $allow );
		if ( is_object( $email ) ) {
			$this->writing[] = array(
				'email'   => $email,
				'started' => false,
				'changed' => false,
			);
		}
		return false;
	}

	/**
	 * Before the plugin writes the email it started, once it knows what the email is about: in the
	 * language of its reader, until endEmail().
	 *
	 * @param mixed $recipient The recipients.
	 * @return mixed
	 */
	public function startEmail( $recipient ) {
		$last = count( $this->writing ) - 1;
		if ( $last < 0 || $this->writing[ $last ]['started'] ) {
			return $recipient;
		}
		$slug = self::readerLanguage( $this->writing[ $last ]['email'] );
		if ( swiftling_writing_language() !== $slug && ( '' === $slug || null !== swiftling_target_language( $slug ) ) ) {
			swiftling_write_in( $slug );
			$this->writing[ $last ]['changed'] = true;
		}
		$this->writing[ $last ]['started'] = true;
		return $recipient;
	}

	/**
	 * Returns the placeholders of an email with the ad's title in the language the email is written in:
	 * the plugin worked it out before, in the language of the request.
	 *
	 * @param mixed $placeholders The placeholders and their values.
	 * @param mixed $email        The plugin's email.
	 * @return mixed
	 */
	public function translatePlaceholders( $placeholders, $email = null ) {
		$last = count( $this->writing ) - 1;
		if ( $last < 0 || ! $this->writing[ $last ]['changed'] || $this->writing[ $last ]['email'] !== $email || ! is_array( $placeholders ) || ! isset( $placeholders['{listing_title}'] ) ) {
			return $placeholders;
		}
		$listing = $email->object ?? null;
		if ( is_object( $listing ) && method_exists( $listing, 'get_id' ) ) {
			$placeholders['{listing_title}'] = html_entity_decode( (string) get_the_title( (int) $listing->get_id() ) );
		}
		return $placeholders;
	}

	/**
	 * After the plugin wrote an email: the request goes on in its own language. The plugin restores no
	 * language of its own, as it switched none, see switchLocale().
	 *
	 * @param mixed $allow Whether the plugin restores its language.
	 * @param mixed $email The plugin's email.
	 * @return mixed
	 */
	public function endEmail( $allow, $email = null ) {
		unset( $allow );
		$last = count( $this->writing ) - 1;
		if ( $last >= 0 && $this->writing[ $last ]['email'] === $email ) {
			$entry = array_pop( $this->writing );
			if ( $entry['changed'] ) {
				swiftling_resume_translation();
			}
		}
		return false;
	}

	/**
	 * Returns the language an email is written in: of its ad for its owner, of the page for a visitor,
	 * the original for the site's owner.
	 *
	 * @param object $email The plugin's email.
	 */
	private static function readerLanguage( $email ): string {
		$class = substr( (string) strrchr( '\\' . get_class( $email ), '\\' ), 1 );
		$kind  = self::EMAILS[ $class ] ?? 'site';
		if ( 'visitor' === $kind ) {
			return swiftling_writing_language();
		}
		if ( 'owner' !== $kind ) {
			return '';
		}
		$listing = $email->object ?? null;
		$id      = is_object( $listing ) && method_exists( $listing, 'get_id' ) ? (int) $listing->get_id() : 0;
		$slug    = $id ? (string) get_post_meta( $id, self::LANGUAGE_META, true ) : '';
		return null !== swiftling_target_language( $slug ) ? $slug : '';
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
