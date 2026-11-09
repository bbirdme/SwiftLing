<?php
/**
 * A plugin whose objects, as tables or galleries, are each a subject of the Templates screen.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin that keeps objects of its own, as tables, galleries or sliders, and prints them where a
 * shortcode or a block asks. A visitor never opens such an object by its own address, so each is a
 * subject of its own on the Templates screen, "<class ID>:<object ID>", grouped under the plugin's
 * name. Its texts are read as the plugin hands them out, see readObject(), so each is noted under the
 * object and path a page in a language translates it at.
 */
abstract class ListedObjects extends Builder {
	/**
	 * How many objects the Templates screen lists.
	 */
	const LIMIT = 200;

	/**
	 * Returns the plugin's objects for the Templates screen: their titles, by ID.
	 *
	 * @return array<string, string>
	 */
	abstract protected function objects(): array;

	/**
	 * Reads every text of an object for the editor, as the plugin hands them out.
	 *
	 * @param string $id The object's ID.
	 */
	abstract protected function readObject( string $id ): void;

	/**
	 * Returns what the plugin calls one of its objects, for the Templates screen.
	 */
	abstract protected function objectNoun(): string;

	/**
	 * Returns the sentence the Templates screen shows under the plugin's name.
	 */
	abstract protected function objectsDescription(): string;

	/**
	 * Returns what the editor says an object is, as "Table of TablePress".
	 */
	abstract protected function objectType(): string;

	/**
	 * Returns the sentence the editor shows above the texts of an object.
	 */
	abstract protected function objectNote(): string;

	/**
	 * Whether a text read for an object is one of its own: the editor lists only those.
	 *
	 * @param string $id    The object's ID.
	 * @param array  $leaf  The text, as swiftling_collect() noted it.
	 */
	abstract protected function ownsText( string $id, array $leaf ): bool;

	/**
	 * Returns the right the plugin asks to edit its objects.
	 */
	protected function capability(): string {
		return 'manage_options';
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
	 * Returns the objects as subjects of the Templates screen.
	 *
	 * @return array
	 */
	public function subjects(): array {
		$subjects = array();
		foreach ( $this->objects() as $id => $title ) {
			$subjects[] = array(
				'key'         => $this->id() . ':' . $id,
				'title'       => '' !== trim( $title ) ? $title : __( 'No title', 'swiftling' ),
				'description' => $this->objectNoun(),
			);
		}
		if ( ! $subjects ) {
			return array();
		}
		return array(
			array(
				'title'       => $this->name(),
				'description' => $this->objectsDescription(),
				'subjects'    => $subjects,
			),
		);
	}

	/**
	 * Returns a subject of the editor: one object.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		$id = $this->objectOf( $key );
		if ( null === $id ) {
			return null;
		}
		$title = $this->objects()[ $id ];
		return array(
			'key'   => $key,
			'kind'  => 'fields',
			'title' => '' !== trim( $title ) ? $title : __( 'No title', 'swiftling' ),
			'type'  => $this->objectType(),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => $this->capability(),
			'note'  => $this->objectNote(),
		);
	}

	/**
	 * Returns the object a subject names.
	 *
	 * @param string $key Subject key.
	 * @return string|null Null when the key names no object of the plugin.
	 */
	protected function objectOf( string $key ): ?string {
		$prefix = $this->id() . ':';
		if ( 0 !== strpos( $key, $prefix ) ) {
			return null;
		}
		$id = substr( $key, strlen( $prefix ) );
		return '' !== $id && isset( $this->objects()[ $id ] ) ? $id : null;
	}

	/**
	 * Returns the texts of an object for its subject.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$id = $this->objectOf( (string) $subject['key'] );
		if ( null === $id ) {
			return array();
		}
		$texts = array();
		foreach ( swiftling_collect(
			function () use ( $id ): void {
				$this->readObject( $id );
			}
		) as $leaf ) {
			if ( ! $this->ownsText( $id, $leaf ) ) {
				continue;
			}
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'text',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}
}
