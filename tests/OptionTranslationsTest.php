<?php
/**
 * Tests of reading the translations of options.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * The object of options can list thousands of texts, found by recording themes and plugins, while
 * a page reads a few options. Without a persistent object cache, the translations of an option are
 * read when it is read.
 */
class OptionTranslationsTest extends TestCase {

	/**
	 * Lists many texts of options and the site title, as recording a theme's settings would, and
	 * translates two of them.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'blogname', 'Apartmani Marija' );
		update_option( 'blogdescription', 'Obiteljski apartmani' );
		$many = array();
		for ( $i = 1; $i <= 1100; $i++ ) {
			$many[ 'text_' . $i ] = 'Tekst postavke broj ' . $i;
		}
		update_option( 'zz_theme_texts', $many );
		$page = self::factory()->post->create();
		$this->check(
			'post:' . $page,
			static function () {
				get_option( 'blogname' );
				get_option( 'blogdescription' );
				get_option( 'zz_theme_texts' );
			}
		);
		$this->translate( 'options', 'option/blogname', 'Apartmani Marija', 'de', 'Ferienwohnungen Marija' );
		$this->translate( 'options', 'option/zz_theme_texts/text_7', 'Tekst postavke broj 7', 'de', 'Text der Einstellung Nummer 7' );
	}

	/**
	 * Counts the queries for translations while code runs.
	 *
	 * @param callable $code Code.
	 * @return int
	 */
	private function translation_queries( $code ) {
		$count  = 0;
		$filter = static function ( $query ) use ( &$count ) {
			if ( false !== strpos( $query, 'swiftling_strings' ) ) {
				++$count;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$code();
		} finally {
			remove_filter( 'query', $filter );
		}
		return $count;
	}

	/**
	 * The site title asks for its own translation, in one small query, not for the translations of
	 * all 1,100 other texts; each option shows its translation, and the original where it has none.
	 */
	public function test_an_option_reads_its_own_translations() {
		$this->assertGreaterThan( 1100, count( swiftling_get_maps( array( 'options' ) )['options']['units'] ) );
		$this->show_in( 'de' );

		$queries = $this->translation_queries(
			function () {
				$this->assertSame( 'Ferienwohnungen Marija', get_option( 'blogname' ) );
			}
		);
		$this->assertSame( 1, $queries, 'One query, for the texts of the site title' );

		$this->assertSame( 'Obiteljski apartmani', get_option( 'blogdescription' ), 'No translation: the original' );
		$texts = get_option( 'zz_theme_texts' );
		$this->assertSame( 'Text der Einstellung Nummer 7', $texts['text_7'] );
		$this->assertSame( 'Tekst postavke broj 8', $texts['text_8'] );

		$queries = $this->translation_queries(
			function () {
				$this->assertSame( 'Ferienwohnungen Marija', get_option( 'blogname' ) );
			}
		);
		$this->assertSame( 0, $queries, 'Read again: nothing more' );
	}

	/**
	 * With a persistent object cache the translations of all options are read at once, as before:
	 * from the cache, one read for all of them.
	 */
	public function test_with_a_persistent_object_cache() {
		wp_using_ext_object_cache( true );
		try {
			$this->show_in( 'de' );
			$this->assertSame( 'Ferienwohnungen Marija', get_option( 'blogname' ) );
			$texts = get_option( 'zz_theme_texts' );
			$this->assertSame( 'Text der Einstellung Nummer 7', $texts['text_7'] );
		} finally {
			wp_using_ext_object_cache( false );
		}
	}
}
