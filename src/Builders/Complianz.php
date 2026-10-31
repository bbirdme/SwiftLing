<?php
/**
 * Complianz: its cookie banner and legal documents in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Complianz GDPR/CCPA Cookie Consent. Its legal documents, such as the cookie policy, are written
 * from texts in its code, translated in the language of the page as any text of a plugin. What
 * SwiftLing does not find by itself:
 *
 * - The texts of a cookie banner are a row of the plugin's own table, filled in the language of the
 *   site when the banner is made. They are texts of the banner, "complianz:<ID>" with paths such as
 *   "complianz/message_optin", translated where the plugin hands the banner's texts to its template,
 *   see translateBanner(), and offered on the subject of the plugin's settings, see readMoreTexts().
 * - The company's name and address, the name of the person accountable and the periods of retention
 *   that the documents name, and the text over blocked content, are values of the plugin's settings,
 *   read as options: declared as text, see textOptions(). Every other value there, such as a region or
 *   a choice, is none.
 * - The banner, the page to manage consent and the documents can be loaded from the page through the
 *   plugin's REST API: in the language of that page, see sendingRoutes().
 * - A consent area, a block or a shortcode of the plugin, shows what it holds only once a visitor
 *   consents, loaded from the REST API out of the post's content as stored, or printed by the
 *   shortcode on the next page: a page check never sees it. Its content is offered with the post's
 *   fields, see listTexts(), and the REST API answers with it translated, see translateConsentArea().
 * - The banner links to the legal documents by their page titles, which the plugin keeps for ten
 *   minutes for each language: emptied when a translation is saved, as the plugin empties them when a
 *   document changes, see forgetPageLinks().
 *
 * Checked against the code of Complianz 7.5 (the free plugin), in tests/ComplianzTest.php, and by hand
 * on a site with the plugin.
 */
class Complianz extends SettingsTexts {
	/**
	 * Name of the option the plugin keeps its settings in.
	 */
	const OPTION = 'cmplz_options';

	/**
	 * Where the texts of a banner are in the map of the banner, and their source for rule keys.
	 */
	const BASE   = 'complianz';
	const SOURCE = 'complianz:banner';

	/**
	 * The texts of a banner, by the name the plugin hands them to its template under.
	 */
	const BANNER_TEXTS = array( 'header', 'message_optin', 'message_optout', 'accept_optin', 'accept_optout', 'dismiss', 'manage_options', 'save_settings', 'manage_consent', 'category_functional', 'category_preferences', 'category_statistics', 'category_marketing', 'functional_text', 'preferences_text', 'statistics_text', 'statistics_text_anonymous', 'marketing_text' );

	/**
	 * The route of the plugin's REST API a consent area loads what it holds from, once a visitor consents.
	 */
	const CONSENT_ROUTE = '#^/complianz/v1/consent-area/(?P<post_id>\d+)/(?P<block_id>[a-z0-9_-]+)/?$#';

	/**
	 * The purposes of processing whose periods of retention and criteria the documents name.
	 */
	const PURPOSES = array( 'contact', 'payments', 'register-account', 'newsletters', 'support-services', 'legal-obligations', 'statistics', 'offer-personalized-products', 'selling-data-thirdparty', 'deliveries' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'complianz';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Complianz';
	}

	/**
	 * Whether the plugin is on the site: it names its version while its files load.
	 */
	public function isActive(): bool {
		return defined( 'CMPLZ_VERSION' );
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
	 * Hooks where the plugin hands the texts of a banner to its template.
	 */
	public function register(): void {
		add_filter( 'cmplz_cookiebanner_settings_html', array( $this, 'translateBanner' ), 10, 2 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'translateConsentArea' ), 10, 3 );
		add_action( 'swiftling_purge_urls', array( $this, 'forgetPageLinks' ), 10, 0 );
		add_action( 'swiftling_purge_all', array( $this, 'forgetPageLinks' ), 10, 0 );
	}

	/**
	 * Empties the plugin's copies of the links of its banner to the legal documents, with their titles
	 * in each language, after translations were saved: a translated title shows at once.
	 */
	public function forgetPageLinks(): void {
		if ( function_exists( 'cmplz_delete_transients_by_prefix' ) ) {
			cmplz_delete_transients_by_prefix( 'page_links_' );
		}
	}

	/**
	 * Returns the routes of the plugin's REST API the page loads the banner, the page to manage consent
	 * and the documents from: answered in the language of the page.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/complianz/v1/(banner|manage_consent_html|documents)/?$#', self::CONSENT_ROUTE );
	}

	/**
	 * Returns the values of the plugin's settings that hold text: what its documents name of the
	 * company and its purposes, and the text over blocked content.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$texts = array( 'organisation_name', 'address_company', 'ca_name_accountable_person', 'ca_address_accountable_person', 'blocked_content_text' );
		foreach ( self::PURPOSES as $purpose ) {
			array_push( $texts, $purpose . '_retain_wmy', $purpose . '_description_criteria_retention', $purpose . '_credit_protection_relevant_legislation' );
		}
		return array( self::OPTION => $texts );
	}

	/**
	 * Returns what the editor calls the texts of the settings it names.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array(
			self::OPTION => array(
				'organisation_name'             => __( 'Name of the organisation', 'swiftling' ),
				'address_company'               => __( 'Address of the organisation', 'swiftling' ),
				'ca_name_accountable_person'    => __( 'Person accountable', 'swiftling' ),
				'ca_address_accountable_person' => __( 'Address of the person accountable', 'swiftling' ),
				'blocked_content_text'          => __( 'Text over blocked content', 'swiftling' ),
			),
		);
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The texts of the cookie banner, and what the legal documents name of your organisation. The rest of the documents is translated by the plugin itself, in the language of the page.', 'swiftling' );
	}

	/**
	 * Returns the texts of a banner as the plugin hands them to its template, in the mode of the moment.
	 *
	 * @param mixed $output The banner's texts and settings, by name; a text may be an array with "text".
	 * @param mixed $banner The plugin's banner.
	 * @return mixed
	 */
	public function translateBanner( $output, $banner = null ) {
		if ( ! is_array( $output ) || ! is_object( $banner ) || empty( $banner->ID ) || ! self::inMode() ) {
			return $output;
		}
		$owner = self::BASE . ':' . (int) $banner->ID;
		foreach ( self::BANNER_TEXTS as $name ) {
			if ( ! isset( $output[ $name ] ) ) {
				continue;
			}
			$path = self::BASE . '/' . $name;
			if ( is_array( $output[ $name ] ) && isset( $output[ $name ]['text'] ) && is_string( $output[ $name ]['text'] ) ) {
				$output[ $name ]['text'] = swiftling_transform_text( $owner, $path, self::SOURCE, $output[ $name ]['text'] );
			} elseif ( is_string( $output[ $name ] ) ) {
				$output[ $name ] = swiftling_transform_text( $owner, $path, self::SOURCE, $output[ $name ] );
			}
		}
		return $output;
	}

	/**
	 * Returns the texts of the subject of the settings, and for a post the content of its consent areas,
	 * which its page shows only once a visitor consents: under the paths of the post's content, so a
	 * page that prints a consent area translates it as the rest of the content.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( 'post' !== $subject['kind'] || ! $subject['post'] instanceof \WP_Post ) {
			return parent::listTexts( $subject );
		}
		$post   = $subject['post'];
		$hidden = array_merge( array_values( self::consentBlocks( $post->post_content ) ), array_values( self::consentShortcodes( $post->post_content ) ) );
		if ( ! $hidden ) {
			return array();
		}
		$texts = array();
		foreach ( $this->collect(
			static function () use ( $post ): void {
				swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $post->post_content );
			}
		) as $text ) {
			if ( in_array( $text['v'], $hidden, true ) ) {
				$texts[] = array( 'place' => __( 'Shown once a visitor consents', 'swiftling' ) ) + $text;
			}
		}
		return $texts;
	}

	/**
	 * Returns what the consent areas of a post's content hold, by the ID the plugin's REST API knows
	 * them by, as Complianz 7.5 finds them there: its blocks, or without one its shortcodes.
	 *
	 * @param string $content The post's content.
	 * @return array<string, string>
	 */
	private static function consentAreas( string $content ): array {
		return has_block( 'complianz/consent-area', $content ) ? self::consentBlocks( $content ) : self::consentShortcodes( $content );
	}

	/**
	 * Returns what the consent area blocks of a post's content hold, by their IDs.
	 *
	 * @param string $content The post's content.
	 * @return array<string, string>
	 */
	private static function consentBlocks( string $content ): array {
		$areas = array();
		if ( has_block( 'complianz/consent-area', $content ) ) {
			$queue = parse_blocks( $content );
			while ( $queue ) {
				$block = array_shift( $queue );
				if ( 'complianz/consent-area' === $block['blockName'] && isset( $block['attrs']['blockId'], $block['attrs']['consentedContent'] ) && is_string( $block['attrs']['consentedContent'] ) ) {
					$id = sanitize_title( (string) $block['attrs']['blockId'] );
					if ( ! isset( $areas[ $id ] ) ) {
						$areas[ $id ] = $block['attrs']['consentedContent'];
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					array_push( $queue, ...$block['innerBlocks'] );
				}
			}
		}
		return $areas;
	}

	/**
	 * Returns what the consent area shortcodes of a post's content hold, by their IDs: the first also
	 * by the ID "default", for which the plugin's REST API hands it out.
	 *
	 * @param string $content The post's content.
	 * @return array<string, string>
	 */
	private static function consentShortcodes( string $content ): array {
		$areas = array();
		if ( preg_match_all( '/\[cmplz-consent-area([^\]]*)\](.*?)\[\/cmplz-consent-area\]/is', $content, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $index => $area ) {
				$atts = shortcode_parse_atts( $area[1] );
				$id   = is_array( $atts ) && isset( $atts['id'] ) ? sanitize_title( (string) $atts['id'] ) : '';
				if ( 0 === $index ) {
					$areas['default'] = $area[2];
				}
				if ( '' !== $id && ! isset( $areas[ $id ] ) ) {
					$areas[ $id ] = $area[2];
				}
			}
		}
		return $areas;
	}

	/**
	 * Answers the plugin's REST API for a consent area with its content in the language of the page:
	 * the plugin reads it from the post's content as stored. What the plugin answered otherwise, as a
	 * refusal for a post a visitor may not read, stays.
	 *
	 * @param mixed            $response What the plugin answered.
	 * @param mixed            $handler  The route's handler.
	 * @param \WP_REST_Request $request  The request.
	 * @return mixed
	 */
	public function translateConsentArea( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- The filter's arguments.
		if ( 'translate' !== swiftling_mode() || ! is_string( $response ) || '' === $response || ! $request instanceof \WP_REST_Request || ! preg_match( self::CONSENT_ROUTE, $request->get_route(), $route ) ) {
			return $response;
		}
		$post = get_post( (int) $route['post_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return $response;
		}
		$id       = sanitize_title( $route['block_id'] );
		$stored   = self::consentAreas( $post->post_content );
		$shown    = self::consentAreas( (string) swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $post->post_content ) );
		$original = $stored[ $id ] ?? null;
		if ( null === $original || ! isset( $shown[ $id ] ) || $shown[ $id ] === $original ) {
			return $response;
		}
		return do_shortcode( $shown[ $id ] );
	}

	/**
	 * Reads the texts of every active banner for the subject of the settings, as the plugin hands them
	 * to its template.
	 */
	protected function readMoreTexts(): void {
		foreach ( $this->banners() as $banner ) {
			if ( method_exists( $banner, 'get_html_settings' ) ) {
				$banner->get_html_settings();
			}
		}
	}

	/**
	 * Returns the plugin's active banners.
	 *
	 * @return object[]
	 */
	protected function banners(): array {
		if ( ! function_exists( 'cmplz_get_cookiebanners' ) || ! function_exists( 'cmplz_get_cookiebanner' ) ) {
			return array();
		}
		$banners = array();
		foreach ( (array) cmplz_get_cookiebanners() as $row ) {
			$banner = is_object( $row ) && isset( $row->ID ) ? cmplz_get_cookiebanner( (int) $row->ID ) : null;
			if ( is_object( $banner ) ) {
				$banners[] = $banner;
			}
		}
		return $banners;
	}

	/**
	 * Declares the texts of a banner as text: one word, as a button's, is one.
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
	 * Returns what the editor calls a text of a banner or of the settings.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $owner, self::BASE . ':' ) ) {
			$name = (string) substr( $path, strlen( self::BASE ) + 1 );
			/* translators: %d: number of a cookie banner of Complianz. */
			$banner = sprintf( __( 'Banner %d', 'swiftling' ), (int) substr( $owner, strlen( self::BASE ) + 1 ) );
			return in_array( $name, self::BANNER_TEXTS, true ) ? $banner . ' › ' . self::bannerLabel( $name ) : $banner;
		}
		return parent::fieldLabel( $owner, $path );
	}
	/**
	 * Returns what the editor calls a text of a banner.
	 *
	 * @param string $name Name of the text, as the plugin hands it to its template.
	 */
	private static function bannerLabel( string $name ): string {
		switch ( $name ) {
			case 'header':
				return __( 'Title', 'swiftling' );
			case 'message_optin':
				return __( 'Message (opt-in)', 'swiftling' );
			case 'message_optout':
				return __( 'Message (opt-out)', 'swiftling' );
			case 'accept_optin':
				return __( 'Accept button', 'swiftling' );
			case 'accept_optout':
				return __( 'Accept button (opt-out)', 'swiftling' );
			case 'dismiss':
				return __( 'Deny button', 'swiftling' );
			case 'manage_options':
				return __( 'View preferences button', 'swiftling' );
			case 'save_settings':
				return __( 'Save preferences button', 'swiftling' );
			case 'manage_consent':
				return __( 'Manage consent button', 'swiftling' );
			case 'category_functional':
				return __( 'Functional category', 'swiftling' );
			case 'category_preferences':
				return __( 'Preferences category', 'swiftling' );
			case 'category_statistics':
				return __( 'Statistics category', 'swiftling' );
			case 'category_marketing':
				return __( 'Marketing category', 'swiftling' );
			case 'functional_text':
				return __( 'Functional description', 'swiftling' );
			case 'preferences_text':
				return __( 'Preferences description', 'swiftling' );
			case 'statistics_text':
				return __( 'Statistics description', 'swiftling' );
			case 'statistics_text_anonymous':
				return __( 'Statistics description (anonymous)', 'swiftling' );
			case 'marketing_text':
				return __( 'Marketing description', 'swiftling' );
			default:
				return $name;
		}
	}
}
