<?php
/**
 * A stand-in for the part of Newsletter that SwiftLing answers: its module switching its language for a
 * subscriber, as the plugin does, through the action wpml_switch_language.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile -- The plugin's own names, in one file for the tests.

/**
 * The plugin's base of its modules, with its switch of language.
 */
class NewsletterModuleBase {
	/**
	 * Switches the plugin's language to a subscriber's, as the plugin does.
	 *
	 * @param string $language The language the plugin keeps with the subscriber.
	 */
	public function switch_language( $language ) {
		if ( ! $language ) {
			return;
		}
		do_action( 'wpml_switch_language', $language );
	}

	/**
	 * Puts the plugin's language back, as the plugin does without WPML: naming the language before.
	 */
	public function restore_language() {
		do_action( 'wpml_switch_language', '' );
	}
}
