<?php
/**
 * The suggestion of the browser's language.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * A short note for a visitor whose browser asks for another language the page is in, in that
 * language: "Diese Seite gibt es auch auf Deutsch. Anzeigen ×". Off until the Switcher screen
 * turns it on.
 *
 * SwiftLing decides the language from the address alone, never from the browser, a cookie or the
 * visitor's country, so every visitor of an address gets the same page and page caches keep
 * working. So the page only carries what the note needs: the text of the note in each language
 * the page is in, next to the hreflang links of its head, see hreflang.php. A small script
 * (assets/js/suggestion.js) compares them with the browser's languages and shows the note when
 * the page is in a language the visitor prefers to the one shown. Closing it is remembered in the
 * browser's localStorage, for that language; nothing is sent anywhere.
 *
 * The texts are WordPress translations of the plugin in each language, unless the Switcher screen
 * gives others. They are read in each language once and kept for a day.
 *
 * The Switcher screen may also open the home page in the browser's language, the first time, with
 * the note on the other pages. A small script in the head (assets/js/suggestion-open.js), before
 * the page paints, goes there only when the visitor comes from outside the site, never chose a
 * language (the switcher, the note, or an earlier opening, remembered in localStorage), did not
 * close the note for that language, and is no search engine or link preview. The page and its
 * address stay the same for every visitor, so page caches keep working and every language keeps
 * its address for search engines.
 */
final class Suggestion {
	/**
	 * Transient that keeps the default texts of each language.
	 */
	private const CACHE = 'swiftling_suggestion_texts';

	/**
	 * Hooks the note into the pages of the site.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'printData' ), 5 );
		// Right after the hreflang links it reads, see hreflang.php.
		add_action( 'wp_head', array( self::class, 'printOpening' ), 2 );
		// The default texts change with the languages and with updated translations.
		foreach ( array( 'add_option_' . Settings::OPTION, 'update_option_' . Settings::OPTION, 'add_option_swiftling_languages', 'update_option_swiftling_languages', 'upgrader_process_complete' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget' ) );
		}
	}

	/**
	 * Loads the script and its styles on a page that can suggest a language.
	 */
	public function enqueue(): void {
		// Where the home page may open in another language, every page notes a visitor's choice.
		if ( ! self::offers() && 'open' !== Settings::get()['suggest']['mode'] ) {
			return;
		}
		wp_enqueue_style( 'swiftling-suggestion', SWIFTLING_URL . 'assets/css/suggestion.css', array(), SWIFTLING_VERSION );
		wp_enqueue_script(
			'swiftling-suggestion',
			SWIFTLING_URL . 'assets/js/suggestion.js',
			array(),
			SWIFTLING_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Prints what the script needs: where the note sits, the hreflang tag of the language shown, and
	 * the texts of the note in each other language the page is in, keyed by their hreflang tags.
	 */
	public function printData(): void {
		$offers = self::offers();
		if ( ! $offers ) {
			return;
		}
		$data = array(
			'position' => Settings::get()['suggest']['position'],
			'shown'    => swiftling_hreflang_code( swiftling_page_language()['locale'] ),
			'offers'   => $offers,
		);
		printf(
			'<script type="application/json" id="swiftling-suggestion-data">%s</script>' . "\n",
			wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Prints the script that opens the home page in the browser's language, on the home page when
	 * the Switcher screen asks for it, with what it needs: the languages the page is in, the one
	 * shown, and the hosts of the site, which a visitor coming from the site itself is on.
	 */
	public static function printOpening(): void {
		if ( 'open' !== Settings::get()['suggest']['mode'] || ! is_front_page() || is_paged() ) {
			return;
		}
		$offers = self::offers();
		if ( ! $offers ) {
			return;
		}
		$hosts  = function_exists( 'swiftling_own_hosts' ) ? swiftling_own_hosts() : array( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$data   = array(
			'offers' => array_keys( $offers ),
			'shown'  => swiftling_hreflang_code( swiftling_page_language()['locale'] ),
			'hosts'  => array_values( $hosts ),
		);
		$script = (string) file_get_contents( SWIFTLING_DIR . 'assets/js/suggestion-open.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin, printed inline so it runs before the page paints.
		// Without its comments and indents: it is printed into every home page.
		$script = (string) preg_replace( array( '~/\*.*?\*/~s', '~^\s*//.*$~m', '~^\s+~m', '~\n+~' ), array( '', '', '', "\n" ), $script );
		wp_print_inline_script_tag( trim( str_replace( 'SWIFTLING_OPENING', (string) wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ), $script ) ) );
	}

	/**
	 * Returns the texts of the note in each other language the page being shown is in, when the
	 * note is on.
	 *
	 * @return array<string, array{text: string, show: string, close: string, label: string, lang: string, dir: string}>
	 *         Keyed by hreflang tag, e.g. "de-DE"; empty when the page suggests nothing.
	 */
	public static function offers(): array {
		if ( ! Settings::get()['suggest']['enabled'] ) {
			return array();
		}
		$links  = swiftling_hreflangs();
		$shown  = swiftling_hreflang_code( swiftling_page_language()['locale'] );
		$offers = array();
		foreach ( swiftling_ordered_languages() as $language ) {
			$code = swiftling_hreflang_code( $language['locale'] );
			if ( '' !== $code && $code !== $shown && isset( $links[ $code ] ) && ! isset( $offers[ $code ] ) ) {
				$offers[ $code ] = self::texts( $language );
			}
		}
		return $offers;
	}

	/**
	 * Returns the texts of the note in a language: those the Switcher screen gives, or the defaults.
	 *
	 * @param array $language Language, see swiftling_languages().
	 * @return array{text: string, show: string, close: string, label: string, lang: string, dir: string}
	 */
	public static function texts( array $language ): array {
		$texts = self::defaults( $language );
		$saved = Settings::get()['suggest']['texts'];
		$own   = isset( $saved[ $language['locale'] ] ) && is_array( $saved[ $language['locale'] ] ) ? $saved[ $language['locale'] ] : array();
		foreach ( array( 'text', 'show' ) as $field ) {
			if ( isset( $own[ $field ] ) && is_string( $own[ $field ] ) && '' !== $own[ $field ] ) {
				$texts[ $field ] = $own[ $field ];
			}
		}
		return $texts;
	}

	/**
	 * Returns the default texts of the note in a language: the plugin's translations in the locale
	 * WordPress runs in for it, read once and kept for a day.
	 *
	 * @param array $language Language, see swiftling_languages().
	 * @return array{text: string, show: string, close: string, label: string, lang: string, dir: string}
	 */
	public static function defaults( array $language ): array {
		$kept   = get_transient( self::CACHE );
		$kept   = is_array( $kept ) ? $kept : array();
		$locale = (string) $language['locale'];
		if ( isset( $kept[ $locale ] ) && is_array( $kept[ $locale ] ) ) {
			return $kept[ $locale ];
		}

		// A locale WordPress has no translations for gets the English texts, never the page's.
		$wanted   = (string) $language['wp_locale'];
		$switched = determine_locale() !== $wanted && ( switch_to_locale( $wanted ) || ( 'en_US' !== determine_locale() && switch_to_locale( 'en_US' ) ) );
		try {
			$texts = array(
				/* translators: %s: name of a language in that language, e.g. Deutsch. */
				'text'  => sprintf( __( 'This page is also in %s.', 'swiftling' ), $language['name'] ),
				'show'  => _x( 'Show', 'open the page in the language a note suggests', 'swiftling' ),
				'close' => __( 'Close', 'swiftling' ),
				'label' => __( 'Language suggestion', 'swiftling' ),
				'lang'  => str_replace( '_', '-', $locale ),
				'dir'   => null !== $language['rtl'] ? ( $language['rtl'] ? 'rtl' : 'ltr' ) : ( is_rtl() ? 'rtl' : 'ltr' ),
			);
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		$kept[ $locale ] = $texts;
		set_transient( self::CACHE, $kept, DAY_IN_SECONDS );
		return $texts;
	}

	/**
	 * Forgets the default texts kept, after the languages or their translations changed.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE );
	}
}
