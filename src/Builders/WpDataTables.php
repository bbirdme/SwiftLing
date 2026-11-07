<?php
/**
 * The tables of wpDataTables in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin wpDataTables. A table is a row of the plugin's own table "wpdatatables", with its columns in another;
 * its cells are often not in WordPress at all, but in a CSV or Excel file or a Google Sheet the table is
 * read from. They are translated where the plugin hands each cell out to print it, text by text, see
 * register(), so a cell whose source changes is offered anew and the others keep their translations.
 * The table's title and description, and the titles of its columns, are translated where the plugin
 * prints them.
 *
 * Checked against the code of wpDataTables 6.6 (the free plugin), in tests/WpDataTablesTest.php, and by
 * hand on a site with the plugin.
 */
class WpDataTables extends TablePlugin {
	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wpdatatables';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'wpDataTables';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'WDT_ROOT_PATH' );
	}

	/**
	 * Hooks where the plugin hands out a cell, the titles of the columns and the title and description of
	 * a table.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters.
		add_filter( 'wpdatatables_filter_cell_val', array( $this, 'translateCell' ), 100, 2 );
		add_filter( 'wpdatatables_filter_columns_metadata', array( $this, 'translateColumns' ), 100, 2 );
		add_filter( 'wpdatatables_filter_table_title', array( $this, 'translateTitle' ), 100, 2 );
		add_filter( 'wpdatatables_filter_table_description_text', array( $this, 'translateDescription' ), 100, 2 );
		// phpcs:enable
	}

	/**
	 * Returns a cell with its texts in the mode of the moment.
	 *
	 * @param mixed $value    The cell, as the plugin prints it.
	 * @param mixed $table_id The table's ID.
	 * @return mixed
	 */
	public function translateCell( $value, $table_id = 0 ) {
		return is_numeric( $table_id ) ? $this->texts( (string) $table_id, $value ) : $value;
	}

	/**
	 * Returns the columns of a table with their titles in the mode of the moment.
	 *
	 * @param mixed $columns  The columns, as the plugin's rows of them.
	 * @param mixed $table_id The table's ID.
	 * @return mixed
	 */
	public function translateColumns( $columns, $table_id = 0 ) {
		if ( ! is_array( $columns ) || ! is_numeric( $table_id ) ) {
			return $columns;
		}
		foreach ( $columns as $index => $column ) {
			if ( is_object( $column ) && isset( $column->display_header ) ) {
				$column                 = clone $column;
				$column->display_header = $this->texts( (string) $table_id, $column->display_header );
				$columns[ $index ]      = $column;
			}
		}
		return $columns;
	}

	/**
	 * Returns the title of a table as the plugin prints it, in the mode of the moment.
	 *
	 * @param mixed $html     The title, in its heading.
	 * @param mixed $table_id The table's ID.
	 * @return mixed
	 */
	public function translateTitle( $html, $table_id = 0 ) {
		return is_numeric( $table_id ) ? $this->texts( (string) $table_id, $html, 'name' ) : $html;
	}

	/**
	 * Returns the description of a table as the plugin prints it, in the mode of the moment.
	 *
	 * @param mixed $html     The description, in its paragraph.
	 * @param mixed $table_id The table's ID.
	 * @return mixed
	 */
	public function translateDescription( $html, $table_id = 0 ) {
		return is_numeric( $table_id ) ? $this->texts( (string) $table_id, $html, 'description' ) : $html;
	}

	/**
	 * Returns the plugin's tables for the Templates screen: their titles, by ID.
	 *
	 * @return array<string, string>
	 */
	protected function tables(): array {
		$tables = array();
		foreach ( $this->rows() as $row ) {
			$tables[ (string) $row->id ] = (string) $row->title;
		}
		return $tables;
	}

	/**
	 * Returns the plugin's tables, with their titles and their settings, which hold the description.
	 *
	 * @return object[]
	 */
	protected function rows(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpdatatables';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the Templates screen and the editor.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, advanced_settings FROM %i ORDER BY id LIMIT %d', $table, self::LIMIT ) );
	}

	/**
	 * Reads every text of a table, as the plugin hands it out: its title and description, the titles of
	 * its columns, and its cells, read from its source.
	 *
	 * @param string $table The table's ID.
	 */
	protected function readTable( string $table ): void {
		foreach ( $this->rows() as $row ) {
			if ( (string) $row->id === $table ) {
				$settings = json_decode( (string) $row->advanced_settings );
				$this->texts( $table, (string) $row->title, 'name' );
				$this->texts( $table, (string) ( $settings->table_description ?? '' ), 'description' );
			}
		}
		if ( ! class_exists( 'WPDataTable' ) || ! class_exists( 'WDTConfigController' ) ) {
			return;
		}
		$this->translateColumns( (array) \WDTConfigController::loadColumnsFromDB( (int) $table ), $table );
		try {
			$data = \WPDataTable::loadWpDataTable( (int) $table );
		} catch ( \Exception $e ) {
			return;
		}
		foreach ( (array) $data->getDataRows() as $cells ) {
			foreach ( (array) $cells as $value ) {
				$this->translateCell( $value, $table );
			}
		}
	}
}
