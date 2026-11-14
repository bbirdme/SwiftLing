<?php
/**
 * A plugin of galleries or sliders: the texts of each in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin that keeps galleries or sliders and prints them where a shortcode or a block asks. Each is a
 * subject of the Templates screen, see ListedObjects, with two kinds of texts:
 *
 * - texts of the images of the media library, as their captions and alt texts, translated under each
 *   image, see AttachmentTexts: once per image, in every gallery that shows it;
 * - texts the plugin keeps itself, as the text of a slide's layer or a caption of its own, under the
 *   object "<class ID>:<kind>:<ID>" of the gallery or slider, each by its words, see HtmlTexts::key(), so
 *   the same text is translated once and a slide moved keeps its translations.
 *
 * Where the plugin prints its texts through no filter of its own, those it keeps are put in its output
 * wherever one is printed whole, see replaceTexts().
 */
abstract class GalleryPlugin extends ListedObjects {
	/**
	 * Returns what the plugin calls one of its objects.
	 */
	protected function objectNoun(): string {
		return __( 'Gallery', 'swiftling' );
	}

	/**
	 * Returns the sentence the Templates screen shows under the plugin's name.
	 */
	protected function objectsDescription(): string {
		return __( 'Galleries and sliders, shown on the pages that hold them.', 'swiftling' );
	}

	/**
	 * Returns what the editor says an object is.
	 */
	protected function objectType(): string {
		/* translators: 1: what a plugin calls its objects, e.g. Slider; 2: name of a plugin, e.g. MetaSlider. */
		return sprintf( __( '%1$s of %2$s', 'swiftling' ), $this->objectNoun(), $this->name() );
	}

	/**
	 * Returns the sentence the editor shows above the texts of an object.
	 */
	protected function objectNote(): string {
		return __( 'Every text of this gallery or slider. The texts of an image of the media library, as its caption and alt text, are translated once for the image and show translated in every gallery that holds it. The gallery is translated once and shows translated on every page that holds it.', 'swiftling' );
	}

	/**
	 * Returns the images of the media library an object shows.
	 *
	 * @param string $id The object's ID.
	 * @return int[]
	 */
	protected function images( string $id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A plugin with images of the media library overrides it.
		return array();
	}

	/**
	 * Returns the object key of the texts an object keeps itself.
	 *
	 * @param string $id The object's ID.
	 */
	protected function owner( string $id ): string {
		return $this->id() . ':' . $this->objectKind() . ':' . $id;
	}

	/**
	 * Returns the kind of the plugin's objects in their object keys: gallery or slider.
	 */
	protected function objectKind(): string {
		return 'gallery';
	}

	/**
	 * Whether a text read for an object is one of its own: one it keeps, or one of its images.
	 *
	 * @param string $id   The object's ID.
	 * @param array  $leaf The text.
	 */
	protected function ownsText( string $id, array $leaf ): bool {
		if ( $this->owner( $id ) === $leaf['o'] ) {
			return true;
		}
		return AttachmentTexts::isImage( $leaf['o'] ) && in_array( (int) substr( $leaf['o'], 5 ), $this->images( $id ), true );
	}

	/**
	 * Returns a text an object keeps itself in the mode of the moment, by its words.
	 *
	 * @param string $id    The object's ID.
	 * @param mixed  $value The text.
	 * @return mixed
	 */
	protected function ownText( string $id, $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/\p{L}/u', wp_strip_all_tags( $value ) ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $value;
		}
		$translated = swiftling_transform_text( $this->owner( $id ), 'texts/' . HtmlTexts::key( $value ), $this->id() . ':' . $this->objectKind(), $value );
		return is_string( $translated ) ? $translated : $value;
	}

	/**
	 * Returns what a plugin printed with each of the texts an object keeps in the mode of the moment,
	 * wherever it printed one whole, see WholeTexts::replace().
	 *
	 * @param string   $html  What the plugin printed.
	 * @param string   $id    The object's ID.
	 * @param string[] $texts The texts the object keeps.
	 */
	protected function replaceTexts( string $html, string $id, array $texts ): string {
		return WholeTexts::replace(
			$html,
			$texts,
			function ( string $text ) use ( $id ) {
				return $this->ownText( $id, $text );
			}
		);
	}

	/**
	 * Declares the texts an object keeps itself as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		return 0 === strpos( $owner, $this->id() . ':' . $this->objectKind() . ':' ) && 0 === strpos( $path, 'texts/' ) ? true : null;
	}

	/**
	 * Returns what the editor calls a text of an object: one it keeps itself by what the plugin calls the
	 * object, one of an image by the image's file and which text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $owner, $this->id() . ':' . $this->objectKind() . ':' ) ) {
			return $this->objectNoun();
		}
		return AttachmentTexts::label( $owner, $path );
	}
}
