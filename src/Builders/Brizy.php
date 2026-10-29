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
 * Brizy builds the HTML in the browser, when a page is saved in its editor, so a text a page does
 * not print yet is found only after the page was saved there.
 *
 * Checked on Brizy 2.8.24 (the free plugin) with a page of its free blocks: headings, paragraphs
 * and buttons. Its Pro elements, its popups, its headers and footers and its menus were not
 * checked.
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
	 * Whether the request is the preview Brizy's editor shows while a page is edited.
	 */
	public function isEditorPreview(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether the parameter is there; Brizy checks the rights.
		return isset( $_GET['is-editor-iframe'] );
	}
}
