<?php
/**
 * The right to translate, apart from managing the site.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The capability to translate: to open the Translations screens and the translation editor, and to
 * save translations. Settings stay the right of whoever manages the site.
 */
const SWIFTLING_TRANSLATE = 'swiftling_translate';

/**
 * Gives the right to translate to whoever manages the site, and to whoever another plugin names
 * through swiftling_can_translate, as SwiftLing Pro's Translator role. SwiftLing decides it here
 * alone: a role that has the capability saved, as a role editor would give it, does not count.
 *
 * @param array    $allcaps The user's capabilities.
 * @param array    $caps    Capabilities asked for.
 * @param array    $args    The check.
 * @param \WP_User $user    The user.
 * @return array
 */
function swiftling_grant_translate( $allcaps, $caps, $args, $user ) {
	unset( $caps, $args );
	$manages = ! empty( $allcaps['manage_options'] );

	/**
	 * Filters whether a user who does not manage the site may translate: everything, in every
	 * language, as SwiftLing Pro's Translator role. See docs/hooks.md.
	 *
	 * @param bool     $can  Whether the user may translate.
	 * @param \WP_User $user The user.
	 */
	$allcaps[ SWIFTLING_TRANSLATE ] = $manages || ( $user instanceof WP_User && (bool) apply_filters( 'swiftling_can_translate', false, $user ) );
	return $allcaps;
}
add_filter( 'user_has_cap', 'swiftling_grant_translate', 10, 4 );

/**
 * Whether the current user translates without managing the site: a translator, who translates
 * every text, in every language, also of what they may not edit, such as a template or a form.
 */
function swiftling_is_translator() {
	return current_user_can( SWIFTLING_TRANSLATE ) && ! current_user_can( 'manage_options' );
}

/**
 * Whether the current user may see what a capability guards in the lists of the Translations
 * screens, such as the menus (edit_theme_options): with that capability, or as a translator.
 *
 * @param string $capability Capability WordPress asks to edit it.
 */
function swiftling_can_list( $capability ) {
	return current_user_can( $capability ) || swiftling_is_translator();
}

/**
 * Whether the current user may translate with the site's translation service, which costs its
 * manager: whoever manages the site may; a translator unless the manager said no, through
 * swiftling_translator_machine, as SwiftLing Pro's Settings › Translators asks.
 */
function swiftling_can_machine_translate() {
	if ( ! current_user_can( SWIFTLING_TRANSLATE ) ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	/**
	 * Filters whether a translator who does not manage the site may use machine translation. See
	 * docs/hooks.md.
	 *
	 * @param bool     $can  Whether they may; they may unless a plugin says no.
	 * @param \WP_User $user The translator.
	 */
	return (bool) apply_filters( 'swiftling_translator_machine', true, wp_get_current_user() );
}
