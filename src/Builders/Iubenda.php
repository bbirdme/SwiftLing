<?php
/**
 * The iubenda Cookie Solution: its cookie banner in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * The iubenda Cookie Solution. Its banner comes from the iubenda service, set up by a code the user pastes
 * into the plugin, which names the language of the banner. Without another multilingual plugin the
 * plugin keeps one code and prints it as it is. On a page in a language the code asks for that
 * language, see pageLanguage(); the original keeps the code as pasted. A code of iubenda's later kind,
 * a script of embeds.iubenda.com, names no language: iubenda takes the language of the page from its
 * html tag, which SwiftLing sets.
 *
 * The code names the cookie policy too, which iubenda keeps for each language under another ID: a
 * page in a language links to the policy of the code. Checked against the code of iubenda 3.13, in
 * tests/IubendaTest.php, and by hand with a pasted code, without an account.
 */
class Iubenda extends Builder {
	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'iubenda';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'iubenda';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return function_exists( 'iubenda' );
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
	 * Hooks where the page starts rendering, before the plugin prints its code in the head.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'pageLanguage' ), 0 );
	}

	/**
	 * Gives the plugin's code the language of the page: the plugin read its settings when it loaded.
	 */
	public function pageLanguage(): void {
		if ( 'translate' !== swiftling_mode() ) {
			return;
		}
		$plugin = $this->plugin();
		if ( ! is_object( $plugin ) || ! isset( $plugin->options['cs']['code_default'] ) || ! is_string( $plugin->options['cs']['code_default'] ) ) {
			return;
		}
		$plugin->options['cs']['code_default'] = self::withLanguage( $plugin->options['cs']['code_default'], self::code( (string) swiftling_page_language()['locale'], isset( $plugin->lang_mapping ) && is_array( $plugin->lang_mapping ) ? $plugin->lang_mapping : array() ) );
	}

	/**
	 * Returns the plugin's main object, which keeps its settings.
	 *
	 * @return object|null Null when the plugin is not loaded.
	 */
	protected function plugin() {
		return function_exists( 'iubenda' ) ? iubenda() : null;
	}

	/**
	 * Returns iubenda's code of a language: the plugin's own for the locale, or the language's two letters.
	 *
	 * @param string $locale  Locale, e.g. de_DE.
	 * @param array  $mapping The plugin's codes by locale.
	 */
	public static function code( string $locale, array $mapping ): string {
		return isset( $mapping[ $locale ] ) ? (string) $mapping[ $locale ] : strtolower( (string) strtok( $locale, '_' ) );
	}

	/**
	 * Returns a code with the language it asks iubenda for set, as "lang":"de" or lang: 'de'. A code
	 * that names no language is left as it is.
	 *
	 * @param string $code     The code as pasted.
	 * @param string $language iubenda's code of the language.
	 */
	public static function withLanguage( string $code, string $language ): string {
		if ( '' === $language ) {
			return $code;
		}
		return (string) preg_replace( '/((?<![\w$.])(["\']?)lang\2\s*:\s*["\'])[A-Za-z_-]*(["\'])/', '${1}' . $language . '${3}', $code );
	}
}
