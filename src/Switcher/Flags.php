<?php
/**
 * Flags of the languages in the switcher.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * Flags of the languages in the switcher.
 *
 * A flag stands for a country, not a language, so each language can have the flag of another
 * country, no flag, or an image of the site's own. Without a choice, the flag is the country in
 * the locale (de_AT: Austria), or for a locale without one the country most people link with the
 * language (hr: Croatia). Some languages have no such country, such as Arabic or Esperanto, and
 * show no flag until one is chosen.
 *
 * The flags are SVG files in assets/flags, from country-flag-icons (MIT, see LICENSE.txt there),
 * named by ISO 3166 code, with a few regions: es-ct, gb-eng, gb-nir, gb-sct, gb-wls and eu.
 */
final class Flags {
	/**
	 * Countries of locales without a country. An empty value means no flag.
	 */
	private const LANGUAGE_COUNTRIES = array(
		'af'  => 'za',
		'am'  => 'et',
		'ar'  => '',
		'arg' => 'es',
		'ary' => 'ma',
		'as'  => 'in',
		'az'  => 'az',
		'azb' => 'ir',
		'bel' => 'by',
		'bo'  => '',
		'ca'  => 'es-ct',
		'ceb' => 'ph',
		'ckb' => 'iq',
		'cy'  => 'gb-wls',
		'dsb' => 'de',
		'dzo' => 'bt',
		'el'  => 'gr',
		'en'  => 'gb',
		'eo'  => '',
		'et'  => 'ee',
		'eu'  => '',
		'fi'  => 'fi',
		'fur' => 'it',
		'fy'  => 'nl',
		'gd'  => 'gb-sct',
		'gu'  => 'in',
		'haz' => 'af',
		'hr'  => 'hr',
		'hsb' => 'de',
		'hy'  => 'am',
		'ja'  => 'jp',
		'kab' => 'dz',
		'kir' => 'kg',
		'kk'  => 'kz',
		'km'  => 'kh',
		'kn'  => 'in',
		'lo'  => 'la',
		'lv'  => 'lv',
		'mn'  => 'mn',
		'mr'  => 'in',
		'oci' => 'fr',
		'pcm' => 'ng',
		'ps'  => 'af',
		'rhg' => 'mm',
		'sah' => 'ru',
		'skr' => 'pk',
		'snd' => 'pk',
		'sq'  => 'al',
		'sw'  => 'ke',
		'szl' => 'pl',
		'tah' => 'pf',
		'te'  => 'in',
		'th'  => 'th',
		'tl'  => 'ph',
		'uk'  => 'ua',
		'ur'  => 'pk',
		'vi'  => 'vn',
		'yor' => 'ng',
	);

	/**
	 * Returns the flag a language shows: an address of an image, or an empty string for none.
	 *
	 * @param string $locale Locale of the language, e.g. "de_DE".
	 */
	public static function url( string $locale ): string {
		$choice = self::choice( $locale );
		if ( '' !== $choice['image'] ) {
			return $choice['image'];
		}
		$country = '' !== $choice['country'] ? $choice['country'] : self::automatic( $locale );
		return '' !== $country && 'none' !== $country ? self::countryUrl( $country ) : '';
	}

	/**
	 * Returns what the Switcher screen saved for a language.
	 *
	 * @param string $locale Locale of the language.
	 * @return array{country: string, image: string} Country is empty for automatic, "none" for no flag.
	 */
	public static function choice( string $locale ): array {
		$flags = Settings::get()['flags'];
		$flag  = isset( $flags[ $locale ] ) && is_array( $flags[ $locale ] ) ? $flags[ $locale ] : array();
		return array(
			'country' => isset( $flag['country'] ) ? (string) $flag['country'] : '',
			'image'   => isset( $flag['image'] ) ? (string) $flag['image'] : '',
		);
	}

	/**
	 * Returns the country whose flag a locale shows without a choice.
	 *
	 * @param string $locale For example "de_AT", "pt_PT_ao90" or "hr".
	 * @return string Code of a bundled flag, e.g. "at", or an empty string for none.
	 */
	public static function automatic( string $locale ): string {
		$parts    = explode( '_', $locale );
		$language = strtolower( $parts[0] );
		if ( isset( $parts[1] ) && preg_match( '/^[A-Z]{2}$/', $parts[1] ) ) {
			$country = strtolower( $parts[1] );
		} else {
			$country = isset( self::LANGUAGE_COUNTRIES[ $language ] ) ? self::LANGUAGE_COUNTRIES[ $language ] : '';
		}
		// A file check, not countries(): this runs on every page and needs no names.
		return '' !== $country && is_readable( SWIFTLING_DIR . 'assets/flags/' . $country . '.svg' ) ? $country : '';
	}

	/**
	 * Returns the address of a bundled flag.
	 *
	 * @param string $country Code of the flag, e.g. "de".
	 */
	public static function countryUrl( string $country ): string {
		return SWIFTLING_URL . 'assets/flags/' . $country . '.svg';
	}

	/**
	 * Returns every bundled flag with the name of its country, sorted by name.
	 *
	 * @return array<string, string> Code => name, e.g. "de" => "Germany".
	 */
	public static function countries(): array {
		static $countries = null;
		if ( null !== $countries ) {
			return $countries;
		}

		$regions   = array(
			'es-ct'  => __( 'Catalonia', 'swiftling' ),
			'eu'     => __( 'European Union', 'swiftling' ),
			'gb-eng' => __( 'England', 'swiftling' ),
			'gb-nir' => __( 'Northern Ireland', 'swiftling' ),
			'gb-sct' => __( 'Scotland', 'swiftling' ),
			'gb-wls' => __( 'Wales', 'swiftling' ),
		);
		$names     = class_exists( 'Locale' );
		$locale    = get_user_locale();
		$countries = array();
		foreach ( (array) glob( SWIFTLING_DIR . 'assets/flags/*.svg' ) as $file ) {
			$code = basename( (string) $file, '.svg' );
			if ( isset( $regions[ $code ] ) ) {
				$countries[ $code ] = $regions[ $code ];
				continue;
			}
			$name               = $names ? \Locale::getDisplayRegion( 'und-' . strtoupper( $code ), $locale ) : '';
			$countries[ $code ] = '' !== $name && strtoupper( $code ) !== $name ? $name : strtoupper( $code );
		}

		uasort( $countries, 'strcasecmp' );
		return $countries;
	}
	/**
	 * Returns the flag choices a form posted for the languages the site has, each field sanitized as
	 * it is read: the country as a key, the image as an address. Both the Languages and the Switcher
	 * screen post them as flags[locale][country|image], and verify their nonce before they read them.
	 *
	 * @param string[] $locales Locales of the languages the site has.
	 * @return array<string, array{country: string, image: string}> Only the languages the form names.
	 */
	public static function posted( array $locales ): array {
		$flags = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the form's handler, which reads them.
		foreach ( $locales as $locale ) {
			if ( ! isset( $_POST['flags'][ $locale ] ) || ! is_array( $_POST['flags'][ $locale ] ) ) {
				continue;
			}
			$flags[ $locale ] = array(
				'country' => isset( $_POST['flags'][ $locale ]['country'] ) && is_string( $_POST['flags'][ $locale ]['country'] ) ? sanitize_key( wp_unslash( $_POST['flags'][ $locale ]['country'] ) ) : '',
				'image'   => isset( $_POST['flags'][ $locale ]['image'] ) && is_string( $_POST['flags'][ $locale ]['image'] ) ? esc_url_raw( wp_unslash( $_POST['flags'][ $locale ]['image'] ) ) : '',
			);
		}
		// phpcs:enable
		return $flags;
	}

	/**
	 * Returns the flag choices of a form as they are saved: only languages the site has, and only a
	 * known country, "none" or an image address. A language left on automatic is not kept.
	 *
	 * @param array    $posted  The posted flags by locale, see posted().
	 * @param string[] $locales Locales of the languages the site has.
	 * @return array<string, array{country: string, image: string}>
	 */
	public static function sanitize( array $posted, array $locales ): array {
		$countries = self::countries();
		$flags     = array();
		foreach ( $posted as $locale => $flag ) {
			$locale = sanitize_text_field( (string) $locale );
			if ( ! in_array( $locale, $locales, true ) || ! is_array( $flag ) ) {
				continue;
			}
			$country = isset( $flag['country'] ) ? sanitize_key( $flag['country'] ) : '';
			$image   = isset( $flag['image'] ) ? esc_url_raw( trim( (string) $flag['image'] ) ) : '';
			if ( 'none' !== $country && ! isset( $countries[ $country ] ) ) {
				$country = '';
			}
			if ( '' !== $country || '' !== $image ) {
				$flags[ $locale ] = array(
					'country' => $country,
					'image'   => $image,
				);
			}
		}
		return $flags;
	}

	/**
	 * Saves the flags a form posted and leaves every other setting of the switcher as it is. A
	 * language the form names but left on automatic loses its choice; a language the form does not
	 * name keeps it.
	 *
	 * @param array    $posted  The posted flags, unsanitized, by locale.
	 * @param string[] $locales Locales of the languages the site has.
	 */
	public static function save( array $posted, array $locales ): void {
		$saved = get_option( Settings::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$flags = isset( $saved['flags'] ) && is_array( $saved['flags'] ) ? $saved['flags'] : array();
		foreach ( array_keys( $posted ) as $locale ) {
			unset( $flags[ sanitize_text_field( (string) $locale ) ] );
		}
		$saved['flags'] = array_merge( $flags, self::sanitize( $posted, $locales ) );
		update_option( Settings::OPTION, $saved, true );
	}
}
