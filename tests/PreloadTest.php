<?php
/**
 * Tests of reading translations at once.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * The translations of the posts and terms a page shows are read when they are found, in a few
 * queries however many there are, and show as they did when each was read when it was shown.
 */
class PreloadTest extends TestCase {

	/**
	 * Makes posts with a translated title, categories with a translated name, and returns them.
	 *
	 * @param int $count Number of posts.
	 * @return int[] Post IDs, oldest first.
	 */
	private function posts_and_terms( $count ) {
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$category = self::factory()->category->create( array( 'name' => 'Kategorija ' . $i ) );
			$id       = self::factory()->post->create(
				array(
					'post_title'    => 'Naslov ' . $i,
					'post_category' => array( $category ),
				)
			);
			$this->check(
				'post:' . $id,
				static function () use ( $id ) {
					get_the_title( $id );
				}
			);
			$this->translate( 'post:' . $id, 'field/post_title', 'Naslov ' . $i, 'de', 'Titel ' . $i );
			$this->translate( 'term:' . $category, 'field/name', 'Kategorija ' . $i, 'de', 'Kategorie ' . $i );
			$ids[] = $id;
		}
		$result = swiftling_save_term_list( swiftling_subject( 'terms:category' ) );
		foreach ( $result['units'] as $unit ) {
			if ( 'field/name' === $unit['p'] && 0 === strpos( $unit['v'], 'Kategorija ' ) ) {
				$this->translate( $unit['o'], 'field/name', $unit['v'], 'de', str_replace( 'Kategorija', 'Kategorie', $unit['v'] ) );
			}
		}
		return $ids;
	}

	/**
	 * Reads what a list of posts shows: each title and each category, as a theme prints them.
	 *
	 * @param int[] $ids Post IDs.
	 * @return string[]
	 */
	private function show( $ids ) {
		$out   = array();
		$query = new \WP_Query(
			array(
				'post__in'            => $ids,
				'orderby'             => 'post__in',
				'posts_per_page'      => count( $ids ),
				'ignore_sticky_posts' => true,
			)
		);
		foreach ( $query->posts as $post ) {
			$out[] = get_the_title( $post ) . ' / ' . implode( ', ', wp_list_pluck( get_the_category( $post->ID ), 'name' ) );
		}
		return $out;
	}

	/**
	 * The texts show translated, and the queries do not grow with the number of posts: twelve posts
	 * and their categories cost no more than two.
	 */
	public function test_a_list_reads_its_translations_at_once() {
		$ids = $this->posts_and_terms( 12 );

		$this->show_in( 'de' );
		$this->show( array_slice( $ids, 0, 2 ) ); // Reads what every request reads once.
		global $wpdb;
		$before = $wpdb->num_queries;
		$two    = $this->show( array_slice( $ids, 2, 2 ) );
		$few    = $wpdb->num_queries - $before;

		$before = $wpdb->num_queries;
		$many   = $this->show( array_slice( $ids, 4, 8 ) );
		$more   = $wpdb->num_queries - $before;

		$this->assertSame( 'Titel 3 / Kategorie 3', $two[0] );
		$this->assertSame( 'Titel 12 / Kategorie 12', $many[7] );
		$this->assertLessThanOrEqual( $few + 2, $more, 'Eight posts cost no more than two, give or take a query' );

		$this->reset_state();
		$this->assertSame( 'Naslov 5 / Kategorija 5', $this->show( array( $ids[4] ) )[0], 'The original' );
	}
}
