<?php
/**
 * Visualizer: its tables and charts in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Visualizer (Tables and Charts Manager). A table or chart is a post of the type "visualizer" whose
 * content is its data, and whose meta are its series and settings; the plugin prints them into the page
 * for its scripts, which draw the table or chart. Its cells, the labels of its series, and its title and
 * the titles of its axes are translated where the plugin hands them out, see register().
 *
 * Checked against the code of Visualizer 4.0 (the free plugin), in tests/VisualizerTest.php, and by hand
 * on a site with the plugin.
 */
class Visualizer extends TablePlugin {
	/**
	 * The post type of the tables and charts.
	 */
	const POST_TYPE = 'visualizer';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'visualizer';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Visualizer';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'VISUALIZER_BASEFILE' );
	}

	/**
	 * Returns what the plugin calls a table.
	 */
	protected function tableNoun(): string {
		return __( 'Table or chart', 'swiftling' );
	}

	/**
	 * Hooks where the plugin hands out the data, the series and the settings of a table or chart.
	 */
	public function register(): void {
		add_filter( 'visualizer-get-chart-data', array( $this, 'translateData' ), 100, 2 );
		add_filter( 'visualizer-get-chart-series', array( $this, 'translateSeries' ), 100, 2 );
		add_filter( 'visualizer-get-chart-settings', array( $this, 'translateSettings' ), 100, 2 );
	}

	/**
	 * Returns the data of a table or chart with its texts in the mode of the moment.
	 *
	 * @param mixed $data     The rows.
	 * @param mixed $chart_id The post ID.
	 * @return mixed
	 */
	public function translateData( $data, $chart_id = 0 ) {
		if ( ! is_array( $data ) || ! is_numeric( $chart_id ) ) {
			return $data;
		}
		foreach ( $data as $row => $cells ) {
			foreach ( is_array( $cells ) ? $cells : array() as $column => $cell ) {
				$data[ $row ][ $column ] = $this->texts( (string) $chart_id, $cell );
			}
		}
		return $data;
	}

	/**
	 * Returns the series of a table or chart with their labels in the mode of the moment.
	 *
	 * @param mixed $series   The series, each with its label and type.
	 * @param mixed $chart_id The post ID.
	 * @return mixed
	 */
	public function translateSeries( $series, $chart_id = 0 ) {
		if ( ! is_array( $series ) || ! is_numeric( $chart_id ) ) {
			return $series;
		}
		foreach ( $series as $index => $serie ) {
			if ( is_array( $serie ) && isset( $serie['label'] ) ) {
				$series[ $index ]['label'] = $this->texts( (string) $chart_id, $serie['label'] );
			}
		}
		return $series;
	}

	/**
	 * Returns the settings of a table or chart with its title and the titles of its axes in the mode of
	 * the moment.
	 *
	 * @param mixed $settings The settings.
	 * @param mixed $chart_id The post ID.
	 * @return mixed
	 */
	public function translateSettings( $settings, $chart_id = 0 ) {
		if ( ! is_array( $settings ) || ! is_numeric( $chart_id ) ) {
			return $settings;
		}
		if ( isset( $settings['title'] ) ) {
			$settings['title'] = $this->texts( (string) $chart_id, $settings['title'], 'name' );
		}
		foreach ( array( 'hAxis', 'vAxis' ) as $axis ) {
			if ( isset( $settings[ $axis ]['title'] ) && is_array( $settings[ $axis ] ) ) {
				$settings[ $axis ]['title'] = $this->texts( (string) $chart_id, $settings[ $axis ]['title'] );
			}
		}
		return $settings;
	}

	/**
	 * Returns the plugin's tables and charts for the Templates screen: their titles, by post ID.
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
	 * Reads every text of a table or chart, as the plugin hands them out.
	 *
	 * @param string $table The post ID.
	 */
	protected function readTable( string $table ): void {
		$chart = get_post( (int) $table );
		if ( ! $chart instanceof \WP_Post || self::POST_TYPE !== $chart->post_type ) {
			return;
		}
		$data = class_exists( 'Visualizer_Module' ) ? \Visualizer_Module::get_chart_data( $chart, '', false ) : maybe_unserialize( $chart->post_content );
		$this->translateData( is_array( $data ) ? $data : array(), $table );
		$series = get_post_meta( $chart->ID, 'visualizer-series', true );
		$this->translateSeries( is_array( $series ) ? $series : array(), $table );
		$settings = get_post_meta( $chart->ID, 'visualizer-settings', true );
		$this->translateSettings( is_array( $settings ) ? $settings : array(), $table );
	}

	/**
	 * Declares the texts of the tables and charts as text, and the post's own meta, its series and
	 * settings, as none: their texts are offered by table.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, 'post:' ) && 0 === strpos( $path, 'meta/visualizer-' ) && self::POST_TYPE === get_post_type( (int) substr( $owner, 5 ) ) ) {
			return false;
		}
		return parent::fieldHoldsText( $owner, $path );
	}
}
