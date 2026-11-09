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
abstract class TablePlugin extends ListedObjects {
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
	 * Returns what the plugin calls a table, for the Templates screen and the editor.
	 */
	protected function tableNoun(): string {
		return __( 'Table', 'swiftling' );
	}

	/**
	 * Returns the tables as the objects of the Templates screen.
	 *
	 * @return array<string, string>
	 */
	protected function objects(): array {
		return $this->tables();
	}

	/**
	 * Reads every text of a table, see readTable().
	 *
	 * @param string $id The table's ID.
	 */
	protected function readObject( string $id ): void {
		$this->readTable( $id );
	}

	/**
	 * Returns what the plugin calls a table.
	 */
	protected function objectNoun(): string {
		return $this->tableNoun();
	}

	/**
	 * Returns the sentence the Templates screen shows under the plugin's name.
	 */
	protected function objectsDescription(): string {
		return __( 'Tables, shown on the pages that hold them.', 'swiftling' );
	}

	/**
	 * Returns what the editor says a table is.
	 */
	protected function objectType(): string {
		/* translators: %s: name of a plugin of tables, e.g. TablePress. */
		return sprintf( __( 'Table of %s', 'swiftling' ), $this->name() );
	}

	/**
	 * Returns the sentence the editor shows above the texts of a table.
	 */
	protected function objectNote(): string {
		return __( 'Every text of this table: its name, its headers and its cells, each text once, wherever it is in the table. Numbers and dates are not offered. The table is translated once and shows translated on every page that holds it.', 'swiftling' );
	}

	/**
	 * Whether a text read for a table is one of its own: under the table's object.
	 *
	 * @param string $id   The table's ID.
	 * @param array  $leaf The text.
	 */
	protected function ownsText( string $id, array $leaf ): bool {
		return $this->owner( $id ) === $leaf['o'];
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
