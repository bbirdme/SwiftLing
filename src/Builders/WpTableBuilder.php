<?php
/**
 * WP Table Builder: its tables in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * WP Table Builder. A table is a post of the type "wptb-tables" whose meta "_wptb_content_" is the
 * table's HTML, which the plugin prints as it is where its shortcode or block asks. The HTML is
 * translated text by text where the plugin reads it, see translateHtml(), with the texts of images and
 * buttons in their attributes, instead of as one field of code a translator has to edit by hand; the
 * meta itself, and the copy of it the plugin's editor shows, are declared as no text.
 *
 * Checked against the code of WP Table Builder 2.2 (the free plugin), in tests/WpTableBuilderTest.php,
 * and by hand on a site with the plugin.
 */
class WpTableBuilder extends TablePlugin {
	/**
	 * The post type of the tables, and the meta of a table's HTML.
	 */
	const POST_TYPE = 'wptb-tables';
	const CONTENT   = '_wptb_content_';

	/**
	 * Whether the class reads the HTML as stored now.
	 *
	 * @var bool
	 */
	private $reading = false;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wp-table-builder';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'WP Table Builder';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'WPTB_VERSION' );
	}

	/**
	 * Hooks where the plugin reads a table's HTML.
	 */
	public function register(): void {
		add_filter( 'get_post_metadata', array( $this, 'translateHtml' ), 1000, 4 );
	}

	/**
	 * Returns a table's HTML with its texts in the mode of the moment, where the plugin reads it.
	 *
	 * @param mixed  $value     What get_post_meta() returns, null to read it.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether one value is asked for.
	 * @return mixed
	 */
	public function translateHtml( $value, $object_id, $meta_key = '', $single = false ) {
		if ( null !== $value || self::CONTENT !== $meta_key || $this->reading || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $value;
		}
		$html = $this->html( (int) $object_id );
		if ( '' === $html ) {
			return $value;
		}
		$html = $this->texts( (string) $object_id, $html, 'cells', true );
		return $single ? array( $html ) : array( $html );
	}

	/**
	 * Returns a table's HTML as stored.
	 *
	 * @param int $table The table's post ID.
	 */
	private function html( int $table ): string {
		$this->reading = true;
		try {
			$html = get_post_meta( $table, self::CONTENT, true );
		} finally {
			$this->reading = false;
		}
		return is_string( $html ) ? $html : '';
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
	 * Reads every text of a table's HTML.
	 *
	 * @param string $table The table's post ID.
	 */
	protected function readTable( string $table ): void {
		$this->texts( $table, $this->html( (int) $table ), 'cells', true );
	}

	/**
	 * Declares the texts of the tables as text, and the meta the plugin keeps a table's HTML in as none:
	 * its texts are offered one by one.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( in_array( $path, array( 'meta/' . self::CONTENT, 'meta/_wptb_content_preview_' ), true ) && 0 === strpos( $owner, 'post:' ) && self::POST_TYPE === get_post_type( (int) substr( $owner, 5 ) ) ) {
			return false;
		}
		return parent::fieldHoldsText( $owner, $path );
	}
}
