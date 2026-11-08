<?php
/**
 * Donations via PayPal: its button in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Donations via PayPal (the plugin "paypal-donations"). Its shortcode [paypal-donation] prints a form
 * that sends the donor to PayPal, from the plugin's settings, the option "paypal_donations_options":
 *
 * - the purpose and the reference of the donation, which PayPal shows the donor: texts, see
 *   textOptions();
 * - the page PayPal sends the donor back to: in a language, that page in the language, see
 *   translateOptions();
 * - the picture of the button, which PayPal draws in a language of its own, and the language of
 *   PayPal's pages: in a language, the language's, where the plugin offers it, see buttonLanguage()
 *   and translateOptions(). Elsewhere what the site's owner chose.
 *
 * The same texts given in the shortcode itself are texts of the page that holds it.
 *
 * Checked against the code of Donations via PayPal 1.9 in tests/PayPalDonationsTest.php, and by hand on
 * a site with the plugin.
 */
class PayPalDonations extends SettingsTexts {
	/**
	 * The plugin's settings.
	 */
	const OPTION = 'paypal_donations_options';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'paypal-donations';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Donations via PayPal';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return class_exists( 'PayPalDonations', false );
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
	 * Hooks where the plugin reads its settings and works out the language of its button.
	 */
	public function register(): void {
		add_filter( 'option_' . self::OPTION, array( $this, 'translateOptions' ), PHP_INT_MAX );
		add_filter( 'pd_button_localized_value', array( $this, 'buttonLanguage' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
	}

	/**
	 * Returns the plugin's settings that hold text.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array( self::OPTION => array( 'purpose', 'reference' ) );
	}

	/**
	 * Returns the setting that holds the page PayPal sends the donor back to.
	 *
	 * @return array<string, string[]>
	 */
	protected function addressOptions(): array {
		return array( self::OPTION => array( 'return_page' ) );
	}

	/**
	 * Returns what the editor calls the texts of the plugin's settings.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array(
			self::OPTION => array(
				'purpose'   => __( 'Purpose', 'swiftling' ),
				'reference' => __( 'Reference', 'swiftling' ),
			),
		);
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		return __( 'The purpose and reference of a donation, which PayPal shows the donor. On a page in a language the button, PayPal\'s pages and the page PayPal sends the donor back to are in that language too.', 'swiftling' );
	}

	/**
	 * Returns the plugin's settings as a page in a language reads them: the page PayPal sends the donor
	 * back to in the language, and PayPal's pages in the language where the plugin offers it.
	 *
	 * @param mixed $options The settings.
	 * @return mixed
	 */
	public function translateOptions( $options ) {
		$slug = self::pageSlug();
		if ( '' === $slug || ! is_array( $options ) ) {
			return $options;
		}
		if ( isset( $options['return_page'] ) && is_string( $options['return_page'] ) && '' !== $options['return_page'] ) {
			$options['return_page'] = swiftling_addresses_in( $options['return_page'], $slug );
		}
		$country = self::country( self::locale(), self::CHECKOUT_COUNTRIES );
		if ( '' !== $country ) {
			$options['set_checkout_language'] = 'true';
			$options['checkout_language']     = $country;
		}
		return $options;
	}

	/**
	 * Returns the language of the button's picture: in a language, the language's, where PayPal has one.
	 *
	 * @param mixed $value The language, as "de_DE/DE".
	 * @return mixed
	 */
	public function buttonLanguage( $value ) {
		if ( '' === self::pageSlug() ) {
			return $value;
		}
		$locale   = self::locale();
		$language = strtolower( substr( $locale, 0, 2 ) );
		$own      = strlen( $locale ) >= 5 ? strtoupper( substr( $locale, 3, 2 ) ) : strtoupper( $language );
		$found    = '';
		$best     = 0;
		foreach ( self::BUTTONS as $button ) {
			if ( 0 !== strpos( $button, $language . '_' ) ) {
				continue;
			}
			// The locale's own country first, then the language's home country, then any.
			$country = strtoupper( substr( (string) strrchr( '/' . $button, '/' ), -2 ) );
			$score   = $country === $own ? 3 : ( strtoupper( $language ) === $country ? 2 : 1 );
			if ( $score > $best ) {
				$found = $button;
				$best  = $score;
			}
		}
		return '' !== $found ? $found : $value;
	}

	/**
	 * The pictures of the button PayPal has in a language, as the plugin offers them.
	 */
	const BUTTONS = array( 'en_AU', 'de_DE/AT', 'nl_NL/BE', 'fr_XC', 'zh_XC', 'fr_FR/FR', 'de_DE/DE', 'it_IT/IT', 'ja_JP/JP', 'es_XC', 'nl_NL/NL', 'pl_PL/PL', 'es_ES/ES', 'de_DE/CH', 'fr_FR/CH', 'en_US' );

	/**
	 * The countries whose language PayPal's pages can be shown in, as the plugin offers them, by the
	 * language PayPal shows there.
	 */
	const CHECKOUT_COUNTRIES = array(
		'de' => array( 'DE', 'AT', 'CH' ),
		'fr' => array( 'FR', 'BE', 'CA', 'CH' ),
		'nl' => array( 'NL', 'BE' ),
		'it' => array( 'IT' ),
		'es' => array( 'ES' ),
		'pl' => array( 'PL' ),
		'ru' => array( 'RU' ),
		'sv' => array( 'SE' ),
		'zh' => array( 'CN' ),
		'pt' => array( 'BR' ),
		'en' => array( 'US', 'GB', 'AU', 'CA' ),
	);

	/**
	 * Returns the country whose PayPal pages are in a locale's language: the locale's own country where
	 * PayPal shows that language there, or the first that does.
	 *
	 * @param string                  $locale    Locale, as "de_AT".
	 * @param array<string, string[]> $countries Countries by language.
	 * @return string Empty when PayPal shows the language nowhere the plugin offers.
	 */
	private static function country( string $locale, array $countries ): string {
		$language = strtolower( substr( $locale, 0, 2 ) );
		if ( ! isset( $countries[ $language ] ) ) {
			return '';
		}
		$own = strlen( $locale ) >= 5 ? strtoupper( substr( $locale, 3, 2 ) ) : '';
		return in_array( $own, $countries[ $language ], true ) ? $own : $countries[ $language ][0];
	}

	/**
	 * Returns the WordPress locale of the language the request runs in.
	 */
	private static function locale(): string {
		$language = swiftling_target_language( self::pageSlug() );
		return is_array( $language ) ? (string) ( $language['wp_locale'] ?? $language['locale'] ) : '';
	}

	/**
	 * Returns the address slug of the language the request runs in, or an empty string for the original.
	 */
	private static function pageSlug(): string {
		$state = &swiftling_state();
		return 'translate' === $state['mode'] ? (string) $state['language'] : '';
	}
}
