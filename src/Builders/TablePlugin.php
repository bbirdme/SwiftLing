<?php
/**
 * A plugin of tables: the texts of each table translated text by text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin that keeps tables of its own and prints them where a shortcode or a block asks. Each table is
 * a subject of its own on the Templates screen, as a visitor never opens a table by its own address, and
 * its texts are objects of their own, "<class ID>:table:<table ID>", under these paths:
 *
 * - "name" and "description": the table's name and description, as the plugin prints them;
 * - "cells/<key>": a text of a cell or of a header, where <key> is the text's own, see HtmlTexts: the
 *   same text is translated once for the whole table, wherever it is, and a row added or moved keeps
 *   the translations of the others. HTML in a cell, as a link, is translated text by text with its
 *   tags left as they are, and a link to a page of the site leads to the page in the language.
 *
 * A cell without letters, as a price or a date, and a formula are no text and are never offered.
 */
abstract class TablePlugin extends Builder {
	/**
	 * How many tables the Templates screen lists.
	 */
	const LIMIT = 200;

	/**
	 * Returns the plugin's tables for the Templates screen: their titles, by ID.
	 *
	 * @return array<string, string>
	 */
	abstract protected function tables(): array;

	/**
	 * Reads every text of a table for the editor, as the plugin hands them out, so each is noted under
	 * the object and path it is translated at.
	 *
	 * @param string $table The table's ID.
	 */
	abstract protected function readTable( string $table ): void;

	/**
	 * Returns the right the plugin asks to edit its tables.
	 */
	protected function capability(): string {
		return 'manage_options';
	}

	/**
	 * Returns what the plugin calls a table, for the Templates screen and the editor.
	 */
	protected function tableNoun(): string {
		return __( 'Table', 'swiftling' );
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
	 * Returns the object key of a table's texts.
	 *
	 * @param string $table The table's ID.
	 */
	protected function owner( string $table ): string {
		return $this->id() . ':table:' . $table;
	}

	/**
	 * Returns HTML or a text of a table with its texts in the mode of the moment, each under its own
	 * key. Without letters, or as a formula, it is no text and stays as it is.
	 *
	 * @param string $table The table's ID.
	 * @param mixed  $html  The text, as the plugin has it.
	 * @param string $place      "cells", or "name" or "description" for a text of the table itself.
	 * @param bool   $attributes Whether the texts of attributes count too, as the alt text of an image.
	 * @return mixed
	 */
	protected function texts( string $table, $html, string $place = 'cells', bool $attributes = false ) {
		if ( ! is_string( $html ) || ! self::isText( $html ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $html;
		}
		$owner = $this->owner( $table );
		$rule  = $this->id() . ':table';
		if ( 'cells' !== $place && false === strpos( $html, '<' ) ) {
			$translated = swiftling_transform_text( $owner, $place, $rule, $html );
			return is_string( $translated ) ? $translated : $html;
		}
		$html = HtmlTexts::translate(
			$html,
			static function ( $text, $key ) use ( $owner, $rule, $place ) {
				$translated = swiftling_transform_text( $owner, 'cells' === $place ? 'cells/' . $key : $place, $rule, $text );
				return is_string( $translated ) ? $translated : $text;
			},
			$attributes
		);
		// A link in a cell leads to the page in the language, as a link in a translated page does.
		return 'translate' === swiftling_mode() && false !== strpos( $html, '<a ' ) ? swiftling_localize_links( $html ) : $html;
	}

	/**
	 * Whether a cell holds text: it has letters, and is no formula.
	 *
	 * @param string $value The cell, as the plugin has it.
	 */
	protected static function isText( string $value ): bool {
		$value = trim( $value );
		return '' !== $value && '=' !== $value[0] && 1 === preg_match( '/\p{L}/u', wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Returns the tables as subjects of the Templates screen.
	 *
	 * @return array
	 */
	public function subjects(): array {
		$subjects = array();
		foreach ( $this->tables() as $table => $title ) {
			$subjects[] = array(
				'key'         => $this->id() . ':' . $table,
				'title'       => '' !== trim( $title ) ? $title : __( 'No title', 'swiftling' ),
				'description' => $this->tableNoun(),
			);
		}
		if ( ! $subjects ) {
			return array();
		}
		return array(
			array(
				'title'       => $this->name(),
				'description' => __( 'Tables, shown on the pages that hold them.', 'swiftling' ),
				'subjects'    => $subjects,
			),
		);
	}

	/**
	 * Returns a subject of the editor: one table.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		$table = $this->tableOf( $key );
		if ( null === $table ) {
			return null;
		}
		$title = $this->tables()[ $table ];
		return array(
			'key'   => $key,
			'kind'  => 'fields',
			'title' => '' !== trim( $title ) ? $title : __( 'No title', 'swiftling' ),
			/* translators: %s: name of a plugin of tables, e.g. TablePress. */
			'type'  => sprintf( __( 'Table of %s', 'swiftling' ), $this->name() ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => $this->capability(),
			'note'  => __( 'Every text of this table: its name, its headers and its cells, each text once, wherever it is in the table. Numbers and dates are not offered. The table is translated once and shows translated on every page that holds it.', 'swiftling' ),
		);
	}

	/**
	 * Returns the table a subject names.
	 *
	 * @param string $key Subject key.
	 * @return string|null Null when the key names no table of the plugin.
	 */
	private function tableOf( string $key ): ?string {
		$prefix = $this->id() . ':';
		if ( 0 !== strpos( $key, $prefix ) ) {
			return null;
		}
		$table = substr( $key, strlen( $prefix ) );
		return '' !== $table && isset( $this->tables()[ $table ] ) ? $table : null;
	}

	/**
	 * Returns the texts of a table for its subject.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$table = $this->tableOf( (string) $subject['key'] );
		if ( null === $table ) {
			return array();
		}
		$owner = $this->owner( $table );
		$texts = array();
		foreach ( swiftling_collect(
			function () use ( $table ): void {
				$this->readTable( $table );
			}
		) as $leaf ) {
			if ( $owner !== $leaf['o'] ) {
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

	/**
	 * Declares the texts of the tables as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, $this->id() . ':table:' ) ) {
			return null;
		}
		return 'name' === $path || 'description' === $path || 0 === strpos( $path, 'cells/' ) ? true : null;
	}

	/**
	 * Returns what the editor calls a text of a table.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, $this->id() . ':table:' ) ) {
			return '';
		}
		$labels = array(
			'name'        => __( 'Name', 'swiftling' ),
			'description' => __( 'Description', 'swiftling' ),
		);
		return $labels[ $path ] ?? __( 'Cell', 'swiftling' );
	}
}
