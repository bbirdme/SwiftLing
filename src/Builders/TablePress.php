<?php
/**
 * TablePress: its tables in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * TablePress. A table is a post of the type "tablepress_table" whose content is the table as JSON,
 * which the plugin reads with its own model, and prints where its shortcode or block asks. Its cells,
 * name and description are translated text by text where the plugin hands the table to its filter
 * after its formulas are worked out, see translateTable(), so a formula is never offered, only what it
 * gives.
 *
 * The plugin keeps a table's HTML for visitors who are not signed in, under a key made of how the table
 * is printed, which holds WordPress's locale. A language of the site that runs in another's locale, as
 * a custom language does, would share the other's copy, so the language itself is made part of the key
 * too, see languageOption().
 *
 * Checked against the code of TablePress 3.4, in tests/TablePressTest.php, and by hand on a site with the
 * plugin.
 */
class TablePress extends TablePlugin {
	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'tablepress';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'TablePress';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'TABLEPRESS_ABSPATH' ) && class_exists( 'TablePress', false );
	}

	/**
	 * Returns the right the plugin asks to edit its tables.
	 */
	protected function capability(): string {
		return 'tablepress_edit_tables';
	}

	/**
	 * Hooks where the plugin hands a table out to print it.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters.
		add_filter( 'tablepress_table_render_data', array( $this, 'translateTable' ), 100 );
		add_filter( 'tablepress_table_render_options', array( $this, 'languageOption' ), 100 );
		// phpcs:enable
	}

	/**
	 * Returns a table with its cells, name and description in the mode of the moment.
	 *
	 * @param mixed $table The table: its ID, name, description and its cells by row.
	 * @return mixed
	 */
	public function translateTable( $table ) {
		if ( ! is_array( $table ) || ! isset( $table['id'] ) || ! is_array( $table['data'] ?? null ) ) {
			return $table;
		}
		$id = (string) $table['id'];
		foreach ( $table['data'] as $row => $cells ) {
			foreach ( is_array( $cells ) ? $cells : array() as $column => $cell ) {
				$table['data'][ $row ][ $column ] = $this->texts( $id, $cell );
			}
		}
		foreach ( array( 'name', 'description' ) as $part ) {
			if ( isset( $table[ $part ] ) ) {
				$table[ $part ] = $this->texts( $id, $table[ $part ], $part );
			}
		}
		return $table;
	}

	/**
	 * Returns how a table is printed with the language of the page in it, so the plugin keeps the HTML of
	 * each language under a key of its own.
	 *
	 * @param mixed $options How the table is printed.
	 * @return mixed
	 */
	public function languageOption( $options ) {
		if ( is_array( $options ) && '' !== swiftling_writing_language() ) {
			$options['swiftling_language'] = swiftling_writing_language();
		}
		return $options;
	}

	/**
	 * Returns the plugin's tables for the Templates screen: their names, by ID.
	 *
	 * @return array<string, string>
	 */
	protected function tables(): array {
		$model = $this->model();
		if ( null === $model ) {
			return array();
		}
		$tables = array();
		foreach ( array_slice( (array) $model->load_all( false ), 0, self::LIMIT ) as $id ) {
			$table = $model->load( (string) $id, false, false );
			if ( is_array( $table ) ) {
				$tables[ (string) $id ] = (string) ( $table['name'] ?? '' );
			}
		}
		return $tables;
	}

	/**
	 * Reads every text of a table, as the plugin hands the table out.
	 *
	 * @param string $table The table's ID.
	 */
	protected function readTable( string $table ): void {
		$model = $this->model();
		$data  = null !== $model ? $model->load( $table, true, false ) : null;
		if ( is_array( $data ) ) {
			$this->translateTable( $data );
		}
	}

	/**
	 * Returns the plugin's model of tables.
	 *
	 * @return object|null
	 */
	protected function model() {
		return class_exists( 'TablePress', false ) && method_exists( 'TablePress', 'load_model' ) ? \TablePress::load_model( 'table' ) : null;
	}
}
