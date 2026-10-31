<?php
/**
 * GDPR Cookie Compliance (Moove Agency): its banner and settings screen in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * GDPR Cookie Compliance. Until its texts are changed, the plugin shows its own texts, translated in
 * the language of the page as any text of a plugin. A text changed in its settings is a value of its
 * one option, read when the banner and the settings screen are printed: those values are declared as
 * text, and every other value of the option, such as a colour or a choice, as none. They are a subject
 * of their own on the Templates screen, see SettingsTexts. The plugin keeps the option in a table of
 * its own and hands it out itself, before SwiftLing's filter of options: it is translated there, see
 * translateOption().
 *
 * The plugin keeps a text for each language of a multilingual plugin it knows, under a key with the
 * language's code; with SwiftLing it keeps one, the original, under the plain key.
 *
 * Checked against the code of GDPR Cookie Compliance 5.2 (the free plugin), in
 * tests/GdprCookieComplianceTest.php, and by hand on a site with the plugin.
 */
class GdprCookieCompliance extends SettingsTexts {
	/**
	 * Name of the option the plugin keeps its settings in.
	 */
	const OPTION = 'moove_gdpr_plugin_settings';

	/**
	 * The plugin's settings that hold text a visitor reads, as labels and the content of the tabs of
	 * the settings screen.
	 */
	const TEXTS = array(
		'moove_gdpr_info_bar_content',
		'moove_gdpr_infobar_accept_button_label',
		'moove_gdpr_infobar_reject_button_label',
		'moove_gdpr_infobar_settings_button_label',
		'moove_gdpr_floating_button_label',
		'moove_gdpr_modal_allow_button_label',
		'moove_gdpr_modal_reject_button_label',
		'moove_gdpr_modal_save_button_label',
		'moove_gdpr_modal_enabled_checkbox_label',
		'moove_gdpr_modal_disabled_checkbox_label',
		'moove_gdpr_modal_powered_by_label',
		'moove_gdpr_privacy_overview_tab_title',
		'moove_gdpr_privacy_overview_tab_content',
		'moove_gdpr_strictly_necessary_cookies_tab_title',
		'moove_gdpr_strict_necessary_cookies_tab_content',
		'moove_gdpr_performance_cookies_tab_title',
		'moove_gdpr_performance_cookies_tab_content',
		'moove_gdpr_performance_ccat_tab_title',
		'moove_gdpr_preference_ccat_tab_title',
		'moove_gdpr_advanced_cookies_tab_title',
		'moove_gdpr_advanced_cookies_tab_content',
		'moove_gdpr_cookie_policy_tab_nav_label',
		'moove_gdpr_cookies_policy_tab_content',
	);

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'gdpr-cookie-compliance';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'GDPR Cookie Compliance';
	}

	/**
	 * Whether the plugin is on the site: it names its version while its files load.
	 */
	public function isActive(): bool {
		return defined( 'MOOVE_GDPR_VERSION' );
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
	 * Hooks where the plugin hands out its option.
	 */
	public function register(): void {
		// After the plugin's own (99), which hands it out from the plugin's table.
		add_filter( 'pre_option_' . self::OPTION, array( $this, 'translateOption' ), 100, 1 );
	}

	/**
	 * Returns the plugin's option in the mode of the moment: the plugin hands it out from a table of its
	 * own, before SwiftLing's filter of options, which then leaves it alone.
	 *
	 * @param mixed $value The option, as the plugin handed it out.
	 * @return mixed
	 */
	public function translateOption( $value ) {
		return $this->optionValue( $value, self::OPTION );
	}

	/**
	 * Returns the values of the plugin's settings that hold text.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array( self::OPTION => self::TEXTS );
	}

	/**
	 * Returns what the editor calls each text: its key without the plugin's prefix.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		$labels = array();
		foreach ( self::TEXTS as $key ) {
			$labels[ $key ] = ucfirst( str_replace( '_', ' ', substr( $key, strlen( 'moove_gdpr_' ) ) ) );
		}
		return array( self::OPTION => $labels );
	}
}
