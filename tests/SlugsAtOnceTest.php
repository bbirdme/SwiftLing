<?php
/**
 * Tests of reading translated addresses for every language at once.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * A page links to itself in every language, in the language switcher and in the hreflang links.
 * Its translated addresses are read for all languages at once, not language by language.
 */
class SlugsAtOnceTest extends TestCase {

	/**
	 * Counts the queries to the table of translated addresses while code runs.
	 *
	 * @param callable $code Code.
	 * @return int
	 */
	private function slug_queries( $code ) {
		$count  = 0;
		$filter = static function ( $query ) use ( &$count ) {
			if ( false !== strpos( $query, 'swiftling_slugs' ) ) {
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
	 * The addresses of a child page in five languages, four with translated slugs and one without,
	 * take two queries, however many languages there are; each is the right one.
	 */
	public function test_addresses_in_every_language() {
		$languages = array(
			'de_DE' => 'de',
			'fr_FR' => 'fr',
			'it_IT' => 'it',
			'es_ES' => 'es',
			'pt_PT' => 'pt',
		);
		$this->set_languages( $languages, 'directory' );
		$this->use_pretty_permalinks();
		$parent = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => 'Apartmani',
				'post_name'  => 'apartmani',
			)
		);
		$child  = self::factory()->post->create_and_get(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Lavanda',
				'post_name'   => 'lavanda',
				'post_parent' => $parent->ID,
			)
		);
		foreach ( array(
			'de_DE' => 'ferienwohnungen',
			'fr_FR' => 'appartements',
			'it_IT' => 'appartamenti',
			'es_ES' => 'apartamentos',
		) as $locale => $slug ) {
			swiftling_save_slug( 'post:' . $parent->ID, $locale, $slug );
			swiftling_save_slug( 'post:' . $child->ID, $locale, 'lavanda-' . substr( $locale, 0, 2 ) );
		}
		swiftling_forget_slugs();

		$urls    = array();
		$queries = $this->slug_queries(
			static function () use ( $languages, $child, &$urls ) {
				foreach ( $languages as $slug ) {
					$urls[ $slug ] = swiftling_post_language_url( $child, $slug );
				}
			}
		);
		$this->assertSame( 'http://example.org/de/ferienwohnungen/lavanda-de/', $urls['de'] );
		$this->assertSame( 'http://example.org/fr/appartements/lavanda-fr/', $urls['fr'] );
		$this->assertSame( 'http://example.org/es/apartamentos/lavanda-es/', $urls['es'] );
		$this->assertSame( 'http://example.org/pt/apartmani/lavanda/', $urls['pt'], 'No translated slug: the original' );
		$this->assertLessThanOrEqual( 2, $queries, 'Not three for each language' );

		// A slug saved later shows at once.
		swiftling_save_slug( 'post:' . $child->ID, 'pt_PT', 'lavanda-pt' );
		$this->assertSame( 'http://example.org/pt/apartmani/lavanda-pt/', swiftling_post_language_url( $child, 'pt' ) );
	}
}
