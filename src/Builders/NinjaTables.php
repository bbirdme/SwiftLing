<?php
/**
 * Ninja Tables: its tables in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Ninja Tables. A table is a post of the type "ninja-table": its columns are the post's meta
 * "_ninja_table_columns", its rows a table of the plugin's own. The page loads the rows through
 * admin-ajax.php, answered in the language of the page it was loaded from, see sendingActions().
 *
 * - The name of a column is translated where the plugin hands the columns out to print them, and the
 *   texts of the rows where it hands them out, see register(), after it read them from its own cache of
 *   rows, which holds them as stored.
 * - The plugin can also keep a table's HTML in the post's meta, for every visitor. In a language that
 *   copy is neither read nor written, see skipCachedHtml(), so no language is served another's.
 * - The columns, the rows and the plugin's caches, as meta of the post, are no texts of their own: the
 *   texts are offered by table.
 *
 * Checked against the code of Ninja Tables 5.5 (the free plugin), in tests/NinjaTablesTest.php, and by
 * hand on a site with the plugin.
 */
class NinjaTables extends TablePlugin {
	/**
	 * The post type of the tables.
	 */
	const POST_TYPE = 'ninja-table';

	/**
	 * The meta that keeps a table's HTML for every visitor.
	 */
	const HTML_CACHE = '__ninja_cached_table_html';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'ninja-tables';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Ninja Tables';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'NINJA_TABLES_VERSION' );
	}

	/**
	 * Returns the action of admin-ajax.php the plugin loads the rows of a table through.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'wp_ajax_ninja_tables_public_action' );
	}

	/**
	 * Hooks where the plugin hands out the columns and rows of a table, and reads and writes its copy of a
	 * table's HTML.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters.
		add_filter( 'ninja_get_table_columns_public', array( $this, 'translateColumns' ), 100, 2 );
		add_filter( 'ninja_tables_get_public_data', array( $this, 'translateRows' ), 100, 2 );
		// phpcs:enable
		add_filter( 'get_post_metadata', array( $this, 'skipCachedHtml' ), 10, 3 );
		add_filter( 'update_post_metadata', array( $this, 'skipCachingHtml' ), 10, 3 );
	}

	/**
	 * Returns the columns of a table with their names in the mode of the moment.
	 *
	 * @param mixed $columns  The columns.
	 * @param mixed $table_id The table's post ID.
	 * @return mixed
	 */
	public function translateColumns( $columns, $table_id = 0 ) {
		if ( ! is_array( $columns ) || ! is_numeric( $table_id ) ) {
			return $columns;
		}
		foreach ( $columns as $index => $column ) {
			if ( is_array( $column ) && isset( $column['name'] ) ) {
				$columns[ $index ]['name'] = $this->texts( (string) $table_id, $column['name'] );
			}
		}
		return $columns;
	}

	/**
	 * Returns the rows of a table with their texts in the mode of the moment.
	 *
	 * @param mixed $rows     The rows, each by the keys of the columns.
	 * @param mixed $table_id The table's post ID.
	 * @return mixed
	 */
	public function translateRows( $rows, $table_id = 0 ) {
		if ( ! is_array( $rows ) || ! is_numeric( $table_id ) ) {
			return $rows;
		}
		foreach ( $rows as $index => $row ) {
			foreach ( is_array( $row ) ? $row : array() as $key => $value ) {
				if ( '___id___' !== $key ) {
					$rows[ $index ][ $key ] = $this->texts( (string) $table_id, $value );
				}
			}
		}
		return $rows;
	}

	/**
	 * In a language, the plugin's copy of a table's HTML is none, so it builds the table anew.
	 *
	 * @param mixed  $value     What get_post_meta() returns, null to read it.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return mixed
	 */
	public function skipCachedHtml( $value, $object_id, $meta_key = '' ) {
		return self::HTML_CACHE === $meta_key && 'translate' === swiftling_mode() ? array( '' ) : $value;
	}

	/**
	 * In a language, the plugin does not keep the table it built as the copy every visitor gets.
	 *
	 * @param mixed  $check     Null to write the meta.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return mixed
	 */
	public function skipCachingHtml( $check, $object_id, $meta_key = '' ) {
		return self::HTML_CACHE === $meta_key && 'translate' === swiftling_mode() ? true : $check;
	}

	/**
	 * Returns the plugin's tables for the Templates screen: their titles, by post ID.
	 *
	 * @return array<string, string>
	 */
	protected function tables(): array {
		$tables = array();
		foreach ( get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => self::LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		) as $post ) {
			$tables[ (string) $post->ID ] = $post->post_title;
		}
		return $tables;
	}

	/**
	 * Reads every text of a table: the names of its columns and its rows, as stored.
	 *
	 * @param string $table The table's post ID.
	 */
	protected function readTable( string $table ): void {
		global $wpdb;
		$columns = get_post_meta( (int) $table, '_ninja_table_columns', true );
		$this->translateColumns( is_array( $columns ) ? $columns : array(), $table );
		$items = $wpdb->prefix . 'ninja_table_items';
		if ( ! $this->hasTable( $items ) ) {
			return;
		}
		$rows = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT value FROM %i WHERE table_id = %d ORDER BY id LIMIT 3000', $items, (int) $table ) ) as $value ) {
			$row = json_decode( (string) $value, true );
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		$this->translateRows( $rows, $table );
	}

	/**
	 * Declares the texts of the tables as text, and the meta the plugin keeps a table in as none: its
	 * texts are offered by table.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, 'post:' ) && 0 === strpos( $path, 'meta/' ) && self::POST_TYPE === get_post_type( (int) substr( $owner, 5 ) ) ) {
			return false;
		}
		return parent::fieldHoldsText( $owner, $path );
	}
}
