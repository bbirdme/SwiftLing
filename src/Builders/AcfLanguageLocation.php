<?php
/**
 * The ACF location rule "SwiftLing language".
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * The location rule "SwiftLing language is …" of ACF field groups, for a site that needs a field
 * group in only some languages. A post is in the original language, unless it is a separate copy
 * of another post for a language (see includes/copies.php): a group for German shows only on the
 * German copies, a group "is not German" everywhere else.
 *
 * Loaded only once ACF is, by Acf::registerLocation(): it extends ACF's own class. Checked in ACF
 * PRO 6.8.9 (includes/locations/abstract-acf-location.php).
 */
final class AcfLanguageLocation extends \ACF_Location {
	/**
	 * Names the rule.
	 */
	public function initialize() {
		$this->name        = 'swiftling_language';
		$this->label       = __( 'SwiftLing language', 'swiftling' );
		$this->category    = 'post';
		$this->object_type = 'post';
	}

	/**
	 * Returns the languages of the site: the original and every language it is translated into.
	 *
	 * @param array $rule Rule.
	 * @return array<string, string> Locale => name.
	 */
	public function get_values( $rule ) {
		$languages = swiftling_languages();
		$values    = array(
			/* translators: %s: name of the site's original language, e.g. English. */
			$languages['source']['locale'] => sprintf( __( '%s (original)', 'swiftling' ), $languages['source']['name'] ),
		);
		foreach ( $languages['targets'] as $language ) {
			$values[ $language['locale'] ] = $language['name'];
		}
		return $values;
	}

	/**
	 * Whether the post ACF shows field groups for is in the language of the rule.
	 *
	 * @param array $rule        Rule: operator and locale.
	 * @param array $screen      What ACF shows the field groups for.
	 * @param array $field_group Field group.
	 * @return bool
	 */
	public function match( $rule, $screen, $field_group ) {
		$post   = empty( $screen['post_id'] ) || ! is_numeric( $screen['post_id'] ) ? null : get_post( (int) $screen['post_id'] );
		$locale = $post && swiftling_copy_original_id( $post ) ? (string) get_post_meta( $post->ID, SWIFTLING_COPY_LANG, true ) : swiftling_languages()['source']['locale'];
		return $this->compare_to_rule( $locale, $rule );
	}
}
