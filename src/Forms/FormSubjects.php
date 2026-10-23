<?php
/**
 * What the classes of form plugins share: the subject key of a form and the kind of a text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * The subject key of a form is the ID of its plugin's class and the ID of the form, "cf7-form:12".
 * The class that uses this trait has an id() method.
 */
trait FormSubjects {
	/**
	 * Returns the subject key a form is translated under, e.g. "cf7-form:12".
	 *
	 * @param int $form_id ID of the form.
	 */
	public function subjectKey( int $form_id ): string {
		return $this->id() . '-form:' . $form_id;
	}

	/**
	 * Returns the form a subject key names.
	 *
	 * @param string $key Subject key.
	 * @return int ID of the form, or 0 when the key is not one of this plugin's.
	 */
	protected function formId( string $key ): int {
		$prefix = $this->id() . '-form:';
		$rest   = 0 === strpos( $key, $prefix ) ? substr( $key, strlen( $prefix ) ) : '';
		return ctype_digit( $rest ) ? (int) $rest : 0;
	}

	/**
	 * Returns what kind of text a value of a form is: an address, a line of text or a paragraph.
	 *
	 * @param string $value Value.
	 */
	protected function textKind( string $value ): string {
		if ( swiftling_is_url_like( $value ) ) {
			$media = swiftling_media_kind( $value );
			return '' !== $media ? $media : 'url';
		}
		return false === strpos( $value, "\n" ) && false === strpos( $value, '<' ) ? 'text' : 'paragraph';
	}
}
