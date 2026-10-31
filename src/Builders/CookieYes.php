<?php
/**
 * CookieYes: its cookie banner in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * CookieYes (Cookie Law Info). The plugin keeps the texts of its banner for each language itself, with
 * translations of its own for many languages, and texts edited for a language on its Languages screen.
 * What it does not know by itself is the language of the page:
 *
 * - The plugin asks which language a page is in, and finds out only from other multilingual plugins.
 *   It is told the language of the page, see pageLanguage(), when it has the banner in that language:
 *   a translation of its own, or a language added on its screen. For a language it has neither of, it
 *   shows its default language, as before.
 * - The banner the plugin made for a language is kept in its settings, one for each language. It is
 *   never marked by a page check, see fieldHoldsText(): its texts are the plugin's to translate.
 * - A site that has used the plugin since before its version 3 can still run its earlier version,
 *   whose texts are values of its settings: those are declared as text and a subject of their own on
 *   the Templates screen, see SettingsTexts, with the names and descriptions of its cookie categories.
 *
 * Checked against the code of CookieYes 3.5 (the free plugin), in tests/CookieYesTest.php, and by hand
 * on a site with the plugin, in both versions.
 */
class CookieYes extends SettingsTexts {
	/**
	 * Option of the earlier version with the texts of the banner.
	 */
	const LEGACY_OPTION = 'CookieLawInfo-0.9';

	/**
	 * Taxonomy of the cookie categories of the earlier version.
	 */
	const LEGACY_CATEGORIES = 'cookielawinfo-category';

	/**
	 * Option in which the plugin keeps the banner it made for each language.
	 */
	const TEMPLATE_OPTION = 'cky_banner_template';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'cookieyes';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'CookieYes';
	}

	/**
	 * Whether the plugin is on the site: it names its version while its files load.
	 */
	public function isActive(): bool {
		return defined( 'CLI_VERSION' );
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
	 * Hooks where the plugin asks for the language of the page.
	 */
	public function register(): void {
		add_filter( 'cky_current_language', array( $this, 'pageLanguage' ), 100, 1 );
	}

	/**
	 * Returns the language the plugin shows its banner in: the page's, when the plugin has the banner in
	 * it, as a code of its own, such as "de" or "pt-br".
	 *
	 * @param mixed $language The language the plugin chose: its default, without another multilingual plugin.
	 * @return mixed
	 */
	public function pageLanguage( $language ) {
		if ( 'translate' !== swiftling_mode() ) {
			return $language;
		}
		$locale = strtolower( str_replace( '_', '-', (string) swiftling_page_language()['locale'] ) );
		foreach ( array_unique( array( $locale, (string) strtok( $locale, '-' ) ) ) as $code ) {
			if ( $this->hasLanguage( $code ) ) {
				return $code;
			}
		}
		return $language;
	}

	/**
	 * Whether the plugin has its banner in a language: a translation of its own, or a language added on
	 * its screen.
	 *
	 * @param string $code The plugin's code of the language.
	 */
	protected function hasLanguage( string $code ): bool {
		if ( function_exists( 'cky_selected_languages' ) && in_array( $code, (array) cky_selected_languages(), true ) ) {
			return true;
		}
		$languages = 'CookieYes\\Lite\\Admin\\Modules\\Languages\\Includes\\Controller';
		return class_exists( $languages ) && (bool) $languages::get_instance()->is_cky_translated( $code );
	}

	/**
	 * Returns the texts of the earlier version's settings.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array(
			self::LEGACY_OPTION                 => array( 'bar_heading_text', 'notify_message', 'button_1_text', 'button_2_text', 'button_3_text', 'button_4_text', 'button_6_text', 'button_7_text', 'showagain_text' ),
			'cookielawinfo_privacy_overview_content_settings' => array( 'privacy_overview_title', 'privacy_overview_content' ),
			'cookielawinfo_thirdparty_settings' => array( 'thirdparty_title', 'thirdparty_description' ),
			'cookielawinfo_necessary_settings'  => array( 'necessary_title', 'necessary_description' ),
		);
	}

	/**
	 * Returns the links of the earlier version's buttons.
	 *
	 * @return array<string, string[]>
	 */
	protected function addressOptions(): array {
		return array( self::LEGACY_OPTION => array( 'button_1_url', 'button_2_url', 'button_3_url' ) );
	}

	/**
	 * Returns what the editor calls the texts of the earlier version.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array(
			self::LEGACY_OPTION => array(
				'bar_heading_text' => __( 'Title', 'swiftling' ),
				'notify_message'   => __( 'Message', 'swiftling' ),
				'button_1_text'    => __( 'Accept button', 'swiftling' ),
				'button_2_text'    => __( 'Read more link', 'swiftling' ),
				'button_3_text'    => __( 'Reject button', 'swiftling' ),
				'button_4_text'    => __( 'Settings button', 'swiftling' ),
				'button_6_text'    => __( 'Do not sell link (CCPA)', 'swiftling' ),
				'button_7_text'    => __( 'Accept all button', 'swiftling' ),
				'showagain_text'   => __( 'Revisit consent button', 'swiftling' ),
			),
		);
	}

	/**
	 * Whether the site runs the plugin's earlier version, whose texts are values of its settings.
	 */
	protected function isLegacy(): bool {
		return function_exists( 'cky_is_legacy' ) && cky_is_legacy();
	}

	/**
	 * Returns the subject of the earlier version's settings, for the Templates screen: only where that
	 * version runs. The later one keeps its texts per language itself.
	 *
	 * @return array
	 */
	public function subjects(): array {
		return $this->isLegacy() ? parent::subjects() : array();
	}

	/**
	 * Returns the subject of the earlier version's settings, only where that version runs.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		return $this->isLegacy() ? parent::subject( $key ) : null;
	}

	/**
	 * Reads the names and descriptions of the earlier version's cookie categories, terms of a taxonomy
	 * that has no list on the Terms screen.
	 */
	protected function readMoreTexts(): void {
		$terms = taxonomy_exists( self::LEGACY_CATEGORIES ) ? get_terms(
			array(
				'taxonomy'   => self::LEGACY_CATEGORIES,
				'hide_empty' => false,
			)
		) : array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			swiftling_transform_text( 'term:' . $term->term_id, 'field/name', 'term:name', $term->name );
			swiftling_transform_text( 'term:' . $term->term_id, 'field/description', 'term:description', $term->description );
		}
	}

	/**
	 * Declares the banner the plugin made for each language as no text, and the earlier version's
	 * settings as SettingsTexts does.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 'options' === $owner && ( 'option/' . self::TEMPLATE_OPTION === $path || 0 === strpos( $path, 'option/' . self::TEMPLATE_OPTION . '/' ) ) ) {
			return false;
		}
		return parent::fieldHoldsText( $owner, $path );
	}
}
