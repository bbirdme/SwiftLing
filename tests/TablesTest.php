<?php
/**
 * Tests of the classes of the plugins of tables.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\NinjaTables;
use SwiftLing\Builders\Registry;
use SwiftLing\Builders\TablePress;
use SwiftLing\Builders\Visualizer;
use SwiftLing\Builders\WpDataTables;
use SwiftLing\Builders\WpTableBuilder;

require_once __DIR__ . '/stubs/FakeTablePress.php';
require_once __DIR__ . '/stubs/FakeNinjaTables.php';
require_once __DIR__ . '/stubs/FakeWpDataTables.php';
require_once __DIR__ . '/stubs/FakeWpTableBuilder.php';
require_once __DIR__ . '/stubs/FakeVisualizer.php';

/**
 * What SwiftLing translates of TablePress, Ninja Tables, wpDataTables, WP Table Builder and Visualizer:
 * each table a subject of its own, its texts translated once each, where the plugin hands them out, and
 * neither numbers nor formulas offered.
 */
class TablesTest extends TestCase {

	/**
	 * German, a TablePress table, and the fake in the registry.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The stand-in's tables.
		$GLOBALS['tablepress_test_tables'] = array(
			'1' => array(
				'id'          => '1',
				'name'        => 'Cijene bilja',
				'description' => 'Za proljetni sajam.',
				'data'        => array(
					array( 'Biljka', 'Cijena' ),
					array( 'Bosiljak', '3.50' ),
					array( 'Metvica', '=B2*2' ),
					array( '<a href="http://example.org/bosiljak/">Uzgoj bosiljka</a>', 'Bosiljak' ),
				),
			),
		);
		$fakes                             = array(
			'tablepress'       => new FakeTablePress(),
			'ninja-tables'     => new FakeNinjaTables(),
			'wpdatatables'     => new FakeWpDataTables(),
			'wp-table-builder' => new FakeWpTableBuilder(),
			'visualizer'       => new FakeVisualizer(),
		);
		$builders                          = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $fakes[ $id ] ?? $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
	}

	/**
	 * Gives the registry back its own builders.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		unset( $GLOBALS['tablepress_test_tables'] );
		parent::tear_down();
	}

	/**
	 * Notes the texts of a subject as the editor does, and translates some into German.
	 *
	 * @param string $key          Subject key.
	 * @param array  $translations Original => German.
	 * @return array<string, array> The texts offered, by original.
	 */
	private function units( $key, $translations = array() ) {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( $key ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		foreach ( $translations as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
		return $units;
	}

	/**
	 * A table is a subject on the Templates screen; it offers its name, description and the texts of its
	 * cells once each, also a link's text, not a number or a formula. Translated, the plugin prints them
	 * in the language; the original stays.
	 */
	public function test_tablepress() {
		$tablepress = Registry::get( 'tablepress' );
		$this->assertSame( array( 'tablepress:1' ), wp_list_pluck( Registry::subjects()['TablePress'][0]['subjects'], 'key' ) );
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'tablepress:1' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Biljka', 'Cijena', 'Bosiljak', 'Metvica', 'Uzgoj bosiljka', 'Cijene bilja', 'Za proljetni sajam.' ), array_keys( $units ) );
		$this->assertSame( 'Cell', Registry::fieldLabel( $units['Bosiljak']['o'], $units['Bosiljak']['p'] ) );
		$this->assertSame( 'Name', Registry::fieldLabel( $units['Cijene bilja']['o'], $units['Cijene bilja']['p'] ) );
		foreach ( array(
			'Bosiljak'       => 'Basilikum',
			'Uzgoj bosiljka' => 'Basilikum anbauen',
			'Cijene bilja'   => 'Kräuterpreise',
		) as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}

		$table = $GLOBALS['tablepress_test_tables']['1'];
		$this->show_in( 'de' );
		$printed = $tablepress->translateTable( $table );
		$this->assertSame( array( 'Basilikum', '3.50' ), $printed['data'][1] );
		$this->assertSame( '=B2*2', $printed['data'][2][1] );
		$this->assertSame( array( '<a href="http://example.org/bosiljak/?lang=de">Basilikum anbauen</a>', 'Basilikum' ), $printed['data'][3], 'Once each, wherever it is; the link to the page in the language' );
		$this->assertSame( 'Kräuterpreise', $printed['name'] );
		$this->assertSame( array( 'swiftling_language' => 'de' ), array_intersect_key( $tablepress->languageOption( array( 'id' => '1' ) ), array( 'swiftling_language' => 1 ) ), 'The plugin\'s copy of the table is kept by language' );

		$this->reset_state();
		$this->assertSame( $table, $tablepress->translateTable( $table ) );
		$this->assertSame( array( 'id' => '1' ), $tablepress->languageOption( array( 'id' => '1' ) ) );
	}

	/**
	 * Ninja Tables: the names of the columns and the texts of the rows are translated where the plugin
	 * hands them out, also to admin-ajax.php, answered in the language of the page; in a language its copy
	 * of a table's HTML is neither read nor written; the post's meta is no text of its own.
	 */
	public function test_ninja_tables() {
		$ninja = Registry::get( 'ninja-tables' );
		register_post_type( NinjaTables::POST_TYPE );
		$table = self::factory()->post->create(
			array(
				'post_type'  => NinjaTables::POST_TYPE,
				'post_title' => 'Bilje',
			)
		);
		update_post_meta(
			$table,
			'_ninja_table_columns',
			array(
				array(
					'key'  => 'herb',
					'name' => 'Bosiljak',
				),
			)
		);
		$this->assertSame( array( 'Bosiljak' ), array_keys( $this->units( 'ninja-tables:' . $table, array( 'Bosiljak' => 'Basilikum' ) ) ) );

		$this->show_in( 'de' );
		$this->assertSame(
			array(
				array(
					'herb'     => 'Basilikum',
					'price'    => '3.50',
					'___id___' => 5,
				),
			),
			$ninja->translateRows(
				array(
					array(
						'herb'     => 'Bosiljak',
						'price'    => '3.50',
						'___id___' => 5,
					),
				),
				$table
			)
		);
		$this->assertSame(
			'Basilikum',
			$ninja->translateColumns(
				array(
					array(
						'key'  => 'herb',
						'name' => 'Bosiljak',
					),
				),
				$table
			)[0]['name']
		);
		$this->assertSame( array( '' ), $ninja->skipCachedHtml( null, $table, NinjaTables::HTML_CACHE ) );
		$this->assertTrue( $ninja->skipCachingHtml( null, $table, NinjaTables::HTML_CACHE ) );
		$this->assertNull( $ninja->skipCachedHtml( null, $table, '_ninja_table_columns' ) );
		$this->reset_state();
		$this->assertNull( $ninja->skipCachedHtml( null, $table, NinjaTables::HTML_CACHE ), 'The original keeps the plugin\'s copy' );
		$this->assertNull( $ninja->skipCachingHtml( null, $table, NinjaTables::HTML_CACHE ) );
		$this->assertSame( array( 'wp_ajax_ninja_tables_public_action' ), $ninja->sendingActions() );

		$this->assertFalse( $ninja->fieldHoldsText( 'post:' . $table, 'meta/_ninja_table_columns/0/name' ) );
		$this->assertNull( $ninja->fieldHoldsText( 'post:' . self::factory()->post->create(), 'meta/_ninja_table_columns/0/name' ) );
		unregister_post_type( NinjaTables::POST_TYPE );
	}

	/**
	 * The plugin wpDataTables: a cell, the title of a column, and the title and description of a table as the plugin
	 * prints them, in their HTML.
	 */
	public function test_wpdatatables() {
		$wpdt  = Registry::get( 'wpdatatables' );
		$units = $this->units(
			'wpdatatables:1',
			array(
				'Cijene bilja' => 'Kräuterpreise',
				'Bosiljak'     => 'Basilikum',
				'Biljka'       => 'Kraut',
			)
		);
		$this->assertSame( array( 'Cijene bilja', 'Za proljetni sajam.', 'Biljka', 'Bosiljak' ), array_keys( $units ), 'The cells as read from the plugin\'s source, not a number' );
		$this->show_in( 'de' );
		$this->assertSame( 'Basilikum', $wpdt->translateCell( 'Bosiljak', 1 ) );
		$this->assertSame( '3,50', $wpdt->translateCell( '3,50', 1 ) );
		$column = (object) array( 'display_header' => 'Biljka' );
		$this->assertSame( 'Kraut', $wpdt->translateColumns( array( $column ), 1 )[0]->display_header );
		$this->assertSame( 'Biljka', $column->display_header, 'The plugin\'s own' );
		$this->assertSame( '<h3 class="wpdt-c">Kräuterpreise</h3>', $wpdt->translateTitle( '<h3 class="wpdt-c">Cijene bilja</h3>', 1 ) );
		$this->reset_state();
		$this->assertSame( 'Bosiljak', $wpdt->translateCell( 'Bosiljak', 1 ) );
	}

	/**
	 * WP Table Builder: the table's HTML is translated text by text where the plugin reads it, with the
	 * texts of images; the meta is no text of its own, and the original stays.
	 */
	public function test_wp_table_builder() {
		register_post_type( WpTableBuilder::POST_TYPE );
		$builder = Registry::get( 'wp-table-builder' );
		$builder->register();
		$table = self::factory()->post->create( array( 'post_type' => WpTableBuilder::POST_TYPE ) );
		$html  = '<table><tr><td><p>Bosiljak</p></td><td><p>3.50</p></td><td><img src="a.png" alt="Bosiljak"></td></tr></table>';
		update_post_meta( $table, WpTableBuilder::CONTENT, $html );
		$this->assertSame( array( 'Bosiljak' ), array_keys( $this->units( 'wp-table-builder:' . $table, array( 'Bosiljak' => 'Basilikum' ) ) ), 'Once, from the cell and the image' );

		$this->show_in( 'de' );
		$this->assertSame( '<table><tr><td><p>Basilikum</p></td><td><p>3.50</p></td><td><img src="a.png" alt="Basilikum"></td></tr></table>', get_post_meta( $table, WpTableBuilder::CONTENT, true ) );
		$this->reset_state();
		$this->assertSame( $html, get_post_meta( $table, WpTableBuilder::CONTENT, true ) );
		$this->assertFalse( $builder->fieldHoldsText( 'post:' . $table, 'meta/' . WpTableBuilder::CONTENT ) );
		remove_filter( 'get_post_metadata', array( $builder, 'translateHtml' ), 1000 );
		unregister_post_type( WpTableBuilder::POST_TYPE );
	}

	/**
	 * Visualizer: the cells, the labels of the series, and the title and the titles of the axes.
	 */
	public function test_visualizer() {
		$visualizer = Registry::get( 'visualizer' );
		register_post_type( Visualizer::POST_TYPE );
		$chart = self::factory()->post->create(
			array(
				'post_type'    => Visualizer::POST_TYPE,
				'post_title'   => 'Cijene',
				'post_content' => serialize( array( array( 'Bosiljak', 3.5 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As the plugin keeps its data.
			)
		);
		update_post_meta(
			$chart,
			'visualizer-series',
			array(
				array(
					'label' => 'Biljka',
					'type'  => 'string',
				),
			)
		);
		update_post_meta( $chart, 'visualizer-settings', array( 'title' => 'Cijene' ) );
		$this->assertSame(
			array( 'Bosiljak', 'Biljka', 'Cijene' ),
			array_keys(
				$this->units(
					'visualizer:' . $chart,
					array(
						'Bosiljak' => 'Basilikum',
						'Cijene'   => 'Preise',
					)
				)
			)
		);
		$this->assertFalse( $visualizer->fieldHoldsText( 'post:' . $chart, 'meta/visualizer-settings/title' ), 'Offered by table' );
		unregister_post_type( Visualizer::POST_TYPE );
		$this->show_in( 'de' );
		$this->assertSame( array( array( 'Basilikum', 3.5 ) ), $visualizer->translateData( array( array( 'Bosiljak', 3.5 ) ), $chart ) );
		$this->assertSame(
			'Basilikum',
			$visualizer->translateSeries(
				array(
					array(
						'label' => 'Bosiljak',
						'type'  => 'string',
					),
				),
				$chart
			)[0]['label']
		);
		$settings = $visualizer->translateSettings(
			array(
				'title' => 'Cijene',
				'hAxis' => array( 'title' => 'Bosiljak' ),
			),
			$chart
		);
		$this->assertSame( array( 'Preise', 'Basilikum' ), array( $settings['title'], $settings['hAxis']['title'] ) );
	}
}
