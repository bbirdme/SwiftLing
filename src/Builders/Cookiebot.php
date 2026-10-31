<?php
/**
 * Cookiebot: its cookie banner in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Cookiebot. Its banner and its cookie declaration come from the Cookiebot service, which keeps their
 * texts in each language of the account. The plugin tells the service which language to show, from a
 * setting: the language of WordPress, or a fixed one. On a page in a language the service is asked for
 * that language whatever the setting says, see pageLanguage(); the original keeps the setting.
 *
 * Checked against the code of Cookiebot 4.7, in tests/CookiebotTest.php, and by hand on a site with the
 * plugin and an account ID, without an account: what the service shows for a language depends on the
 * account.
 */
class Cookiebot extends Builder {
	/**
	 * The plugin's setting of the language the service is asked for.
	 */
	const OPTION = 'cookiebot-language';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'cookiebot';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Cookiebot';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return function_exists( 'cookiebot_get_language_from_setting' ) || defined( 'CYBOT_COOKIEBOT_PLUGIN_DIR' );
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
	 * Hooks where the plugin reads its setting of the language.
	 */
	public function register(): void {
		add_filter( 'pre_option_' . self::OPTION, array( $this, 'pageLanguage' ), 10, 1 );
	}

	/**
	 * Returns the language the plugin asks the service for: on a page in a language, that language, as
	 * the two letters the service knows it by.
	 *
	 * @param mixed $value What another filter returned, or false.
	 * @return mixed
	 */
	public function pageLanguage( $value ) {
		if ( 'translate' !== swiftling_mode() ) {
			return $value;
		}
		$code = strtolower( (string) strtok( (string) swiftling_page_language()['locale'], '_' ) );
		return '' !== $code ? $code : $value;
	}
}
