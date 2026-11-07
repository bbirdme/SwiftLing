<?php
/**
 * The language of a member: kept when the member registers, read when a plugin writes to them later.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The user meta SwiftLing keeps a member's language in: the address slug of a target language.
 */
const SWIFTLING_MEMBER_LANGUAGE = 'swiftling_language';

/**
 * Keeps the language of the page a user registered from with the user, so what a membership plugin
 * writes to them later, as the email when their membership expires, can be in that language: the
 * request of a scheduled task or of the admin has no page in a language to tell. A user who registers
 * from a page in the original keeps none, and is written to in the original.
 *
 * WordPress's own setting of the user's language, the user meta "locale", is left alone: it is the
 * language of the admin screens the user sees.
 *
 * @param int $user_id ID of the user registered.
 */
function swiftling_keep_member_language( $user_id ) {
	$state = &swiftling_state();
	$slug  = 'translate' === $state['mode'] ? (string) $state['language'] : '';
	if ( '' !== $slug && $user_id ) {
		update_user_meta( (int) $user_id, SWIFTLING_MEMBER_LANGUAGE, $slug );
	}
}
add_action( 'user_register', 'swiftling_keep_member_language', 5 );

/**
 * Returns the language to write to a member in: the language they registered in while the site still
 * has it, else the language of the page the request is in, as for a member who registered before
 * SwiftLing and now acts on a page in a language.
 *
 * @param WP_User|int|string|null $user      The user, their ID or their email address.
 * @param bool                    $from_page Whether a user who kept no language is written to in the
 *                                           language of the page; false where the reader may be the
 *                                           site's owner, who reads the original.
 * @return string Address slug of a target language, or an empty string for the original.
 */
function swiftling_member_language( $user, $from_page = true ) {
	if ( is_string( $user ) && is_email( $user ) ) {
		$user = get_user_by( 'email', $user );
	} elseif ( is_numeric( $user ) ) {
		$user = get_user_by( 'id', (int) $user );
	}
	if ( $user instanceof WP_User ) {
		$slug = (string) get_user_meta( $user->ID, SWIFTLING_MEMBER_LANGUAGE, true );
		if ( '' !== $slug ) {
			return null !== swiftling_target_language( $slug ) ? $slug : '';
		}
	}
	return $from_page ? swiftling_writing_language() : '';
}

/**
 * Returns the language the request translates into, or an empty string for the original.
 *
 * @return string
 */
function swiftling_writing_language() {
	$state = &swiftling_state();
	return 'translate' === $state['mode'] ? (string) $state['language'] : '';
}

/**
 * Puts the request in a language for what is written to someone, until swiftling_resume_translation():
 * a member's language, or the original for the site's owner.
 *
 * @param string $slug Address slug of a target language, or an empty string for the original.
 */
function swiftling_write_in( $slug ) {
	if ( '' === $slug ) {
		swiftling_pause_translation();
	} else {
		swiftling_switch_translation( $slug );
	}
}

/**
 * Returns a text with the addresses of this site's pages in a language: an email written to a member
 * in their language leads to the pages in it, one to the site's owner to the pages in the original.
 * Other sites' addresses, files and the admin stay as they are.
 *
 * @param string $text Text or HTML.
 * @param string $slug Address slug of a target language, or an empty string for the original.
 * @return string
 */
function swiftling_addresses_in( $text, $slug ) {
	$home = preg_quote( untrailingslashit( (string) preg_replace( '#^https?://#i', '', (string) get_option( 'home' ) ) ), '#' );
	return (string) preg_replace_callback(
		'#https?://' . $home . '(?:/[^\s"\'<>]*)?#i',
		static function ( $found ) use ( $slug ) {
			$url  = $found[0];
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( preg_match( '#/(wp-admin|wp-content|wp-includes|wp-json)(/|$)|\.php$#', $path ) ) {
				return $url;
			}
			// An address in a language names its post or term by translated slugs: the original ones first.
			$from = swiftling_target_language( swiftling_address_language( $url ) );
			if ( $from ) {
				$uri = (string) preg_replace( '#^https?://[^/]+#i', '', $url );
				$url = substr( $url, 0, strlen( $url ) - strlen( $uri ) ) . swiftling_untranslate_path( '' === $uri ? '/' : $uri, $from['locale'] );
			}
			// The slugs of the post or term the address names, then the language's folder or host.
			return swiftling_language_url( swiftling_object_address( swiftling_address_without_language( $url ), $slug ), $slug );
		},
		$text
	);
}
