<?php
/**
 * Cookie Notice & Compliance: its banner in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Cookie Notice & Compliance for GDPR/CCPA. Its banner shows the message, the buttons and the link to
 * the privacy policy from the plugin's settings. What SwiftLing does not find by itself:
 *
 * - The plugin reads its settings once, when it loads, before SwiftLing starts, and prints the banner
 *   from that copy: the texts are put in the mode of the moment on the copy before the page renders,
 *   see translateSettings(). They are a subject of their own on the Templates screen, see
 *   SettingsTexts.
 * - The plugin hides the banner from bots, and a page check is a request of the server: a page check
 *   counts as no bot, so it sees the banner, see showToPageCheck().
 * - The link to the privacy policy names a page, whose address SwiftLing gives in the language of
 *   the page already.
 *
 * Checked against the code of Cookie Notice 3.1 (the free plugin), in tests/CookieNoticeTest.php, and
 * by hand on a site with the plugin. Its Cookie Compliance service, which shows a banner of its own
 * from the service, was not checked.
 */
class CookieNotice extends SettingsTexts {
	/**
	 * Name of the option the plugin keeps its settings in.
	 */
	const OPTION = 'cookie_notice_options';

	/**
	 * The plugin's settings that hold text a visitor reads.
	 */
	const TEXTS = array( 'message_text', 'accept_text', 'refuse_text', 'revoke_message_text', 'revoke_text', 'see_more_opt/text' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'cookie-notice';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Cookie Notice';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'COOKIE_NOTICE_PATH' ) || function_exists( 'Cookie_Notice' );
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
	 * Returns the values of the plugin's settings that hold text.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array( self::OPTION => self::TEXTS );
	}

	/**
	 * Returns the link of the privacy policy the user typed instead of choosing a page.
	 *
	 * @return array<string, string[]>
	 */
	protected function addressOptions(): array {
		return array( self::OPTION => array( 'see_more_opt/link' ) );
	}

	/**
	 * Returns what the editor calls each text.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array(
			self::OPTION => array(
				'message_text'        => __( 'Message', 'swiftling' ),
				'accept_text'         => __( 'Accept button', 'swiftling' ),
				'refuse_text'         => __( 'Refuse button', 'swiftling' ),
				'revoke_message_text' => __( 'Message to revoke consent', 'swiftling' ),
				'revoke_text'         => __( 'Revoke button', 'swiftling' ),
				'see_more_opt/text'   => __( 'Privacy policy link', 'swiftling' ),
			),
		);
	}

	/**
	 * Hooks where the page starts rendering and where the plugin asks whether a visitor is a bot.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'translateSettings' ), 0 );
		add_filter( 'cn_is_bot', array( $this, 'showToPageCheck' ), 100, 1 );
	}

	/**
	 * Puts the texts of the plugin's copy of its settings in the mode of the moment: the plugin read
	 * them when it loaded, before SwiftLing started.
	 */
	public function translateSettings(): void {
		if ( ! self::inMode() ) {
			return;
		}
		$plugin = $this->plugin();
		if ( ! is_object( $plugin ) || ! isset( $plugin->options['general'] ) || ! is_array( $plugin->options['general'] ) ) {
			return;
		}
		$general = $plugin->options['general'];
		foreach ( self::TEXTS as $path ) {
			$parts = explode( '/', $path );
			if ( 1 === count( $parts ) && isset( $general[ $parts[0] ] ) ) {
				$general[ $parts[0] ] = $this->optionText( self::OPTION, $path, $general[ $parts[0] ] );
			} elseif ( 2 === count( $parts ) && isset( $general[ $parts[0] ][ $parts[1] ] ) ) {
				$general[ $parts[0] ][ $parts[1] ] = $this->optionText( self::OPTION, $path, $general[ $parts[0] ][ $parts[1] ] );
			}
		}
		$plugin->options['general'] = $general;
	}

	/**
	 * Returns the plugin's main object, which keeps its settings.
	 *
	 * @return object|null Null when the plugin is not loaded.
	 */
	protected function plugin() {
		return function_exists( 'Cookie_Notice' ) ? Cookie_Notice() : null;
	}

	/**
	 * Returns whether a visitor is a bot, as the plugin decided: never for a page check, which must see
	 * the banner to find its texts.
	 *
	 * @param mixed $is_bot Whether the plugin takes the visitor for a bot.
	 * @return mixed
	 */
	public function showToPageCheck( $is_bot ) {
		return swiftling_is_probe() ? false : $is_bot;
	}
}
