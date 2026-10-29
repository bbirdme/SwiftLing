<?php
/**
 * Brizy: the HTML it prints for a page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Brizy. Its editor builds a page in the browser and saves two things in the post meta "brizy" and
 * "brizy-compiled-sections", both as base64 encoded JSON: "editor_data", the tree the editor opens
 * again, and the HTML the page prints, section by section. A page is printed from the second, so
 * that is where its texts are, see swiftling_walk_brizy_texts(): Brizy marks each text in it with
 * data-brz-translate-text, and each is a text of its own. The tree of the editor is not read, as
 * no page shows it, and a translation of it would change nothing.
 *
 * A text Brizy marks as translatable is one of three: the HTML inside an element it marks with
 * data-brz-translate-text, the title of a tab it prints a second time for small screens, and an
 * attribute it names data-brz-translatable-*.
 *
 * Brizy builds the HTML in the browser, when a page is saved in its editor, so a text a page does
 * not print yet is found only after the page was saved there.
 *
 * Checked on Brizy 2.8.24 (the free plugin): 19 of its blocks, and every free element: text, image,
 * button, icon, map, line, menu, video, audio, icon box, alert, counter, countdown, progress, tabs,
 * accordion, PayPal, Pinterest and shortcode, and a template for pages. Its forms, popups, global
 * blocks, headers and footers are Pro and were not checked; the free plugin has none of them, so
 * this class names no header or footer, see Builder::sitePart().
 */
class Brizy extends Builder {
	/**
	 * Returns the builder's ID.
	 */
	public function id(): string {
		return 'brizy';
	}

	/**
	 * Returns the builder's name.
	 */
	public function name(): string {
		return 'Brizy';
	}

	/**
	 * Whether Brizy is active.
	 */
	public function isActive(): bool {
		return class_exists( '\Brizy_Editor' );
	}

	/**
	 * Whether a post is built with Brizy. Not said yet: the editor would then offer a separate copy
	 * as "changed by hand in Brizy", and whether such a copy opens and saves in Brizy was not
	 * checked.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Not said yet, see above.
		return false;
	}

	/**
	 * Whether a value is the tree of Brizy's editor, which no page shows.
	 *
	 * @param string $path Path of the value inside its object.
	 */
	public function holdsNoText( string $path ): bool {
		return 0 === strpos( $path, 'meta/brizy/' ) && false !== strpos( $path, '/editor_data' );
	}

	/**
	 * Whether Brizy itself declares a value as text: an attribute it names data-brz-translatable-*,
	 * such as the message of a countdown, which a script shows. Its marker shows only in the
	 * attribute, so the page check counts it as text only for this reason.
	 *
	 * @param string $rule Rule key of the value.
	 */
	public function declaresText( string $rule ): bool {
		return '@brizy-attribute' === substr( $rule, -16 );
	}

	/**
	 * Whether the request is the preview Brizy's editor shows while a page is edited.
	 */
	public function isEditorPreview(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether the parameter is there; Brizy checks the rights.
		return isset( $_GET['is-editor-iframe'] );
	}
}
