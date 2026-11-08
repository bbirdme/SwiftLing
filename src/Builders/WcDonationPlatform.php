<?php
/**
 * Donation Platform for WooCommerce: the texts of its settings.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Donation Platform for WooCommerce (the plugin "wc-donation-platform"). A donation is a WooCommerce
 * product, translated as any product, and its form leads to WooCommerce's checkout. The titles of the
 * form, the leaderboard of donors and the checkbox for an anonymous donation are its settings, options
 * of their own, see textOptions(); a title the site's owner left empty is the plugin's own, which its
 * language packs have. The placeholders of the leaderboard, as {firstname}, stay as they are in machine
 * translation, see keepPlaceholders().
 *
 * Checked against the code of Donation Platform for WooCommerce 1.5 in tests/WcDonationPlatformTest.php,
 * and by hand on a site with the plugin.
 */
class WcDonationPlatform extends SettingsTexts {
	/**
	 * The plugin's settings that hold text, each an option of one text.
	 */
	const TEXTS = array( 'wcdp_contribution_title', 'wcdp_choose_amount_title', 'wcdp_checkout_checkbox_text', 'wcdp_lb_title', 'wcdp_lb_subtitle', 'wcdp_lb_title_checked', 'wcdp_lb_title_unchecked', 'wcdp_lb_subtitle_checked', 'wcdp_lb_subtitle_unchecked' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wc-donation-platform';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Donation Platform for WooCommerce';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'WCDP_DIR' ) || class_exists( 'WCDP_Form', false );
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
	 * Hooks the placeholders of the leaderboard for machine translation.
	 */
	public function register(): void {
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns the plugin's settings that hold text: each an option of one text.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array_fill_keys( self::TEXTS, array( '' ) );
	}

	/**
	 * Returns what the editor calls the texts of the plugin's settings.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array(
			'wcdp_contribution_title'     => array( '' => __( 'Title of the amount', 'swiftling' ) ),
			'wcdp_choose_amount_title'    => array( '' => __( 'Title of the suggested amounts', 'swiftling' ) ),
			'wcdp_checkout_checkbox_text' => array( '' => __( 'Checkbox for an anonymous donation', 'swiftling' ) ),
			'wcdp_lb_title'               => array( '' => __( 'Leaderboard › Title', 'swiftling' ) ),
			'wcdp_lb_subtitle'            => array( '' => __( 'Leaderboard › Subtitle', 'swiftling' ) ),
			'wcdp_lb_title_checked'       => array( '' => __( 'Leaderboard › Title of an anonymous donation', 'swiftling' ) ),
			'wcdp_lb_title_unchecked'     => array( '' => __( 'Leaderboard › Title of a donation with a name', 'swiftling' ) ),
			'wcdp_lb_subtitle_checked'    => array( '' => __( 'Leaderboard › Subtitle of an anonymous donation', 'swiftling' ) ),
			'wcdp_lb_subtitle_unchecked'  => array( '' => __( 'Leaderboard › Subtitle of a donation with a name', 'swiftling' ) ),
		);
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		return __( 'The titles of the donation form, the leaderboard and the checkbox for an anonymous donation. The donations themselves are WooCommerce products, translated as products. Keep every placeholder in braces, such as {amount}.', 'swiftling' );
	}

	/**
	 * Keeps the placeholders of the leaderboard, as {firstname}, as they are when a text goes to machine
	 * translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, '/\{[a-z_]+\}/' );
	}
}
