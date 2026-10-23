<?php
/**
 * Tests of how far translations have come.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * States of pages and languages, and the counts of the Overview.
 */
class ProgressTest extends TestCase {

	/**
	 * The state of a set of texts: the worst one wins, and addresses do not count.
	 *
	 * @dataProvider data_states
	 *
	 * @param array  $translated Map key => status of its current translation, "old" for one of the previous version, or null.
	 * @param string $expected   State.
	 */
	public function test_units_state( $translated, $expected ) {
		$places       = array();
		$translations = array();
		foreach ( $translated as $key => $status ) {
			$places[ $key ] = array( 'now-' . $key, 'old-' . $key );
			if ( 'old' === $status ) {
				$translations[ 'old-' . $key ] = array(
					'text'   => 'x',
					'status' => SWIFTLING_REVIEWED,
				);
			} elseif ( null !== $status ) {
				$translations[ 'now-' . $key ] = array(
					'text'   => 'x',
					'status' => $status,
				);
			}
		}
		$this->assertSame( $expected, swiftling_units_state( $places, $translations ) );
	}

	/**
	 * Sets of texts and their states.
	 *
	 * @return array
	 */
	public function data_states() {
		return array(
			'all reviewed'           => array(
				array(
					'a' => SWIFTLING_REVIEWED,
					'b' => SWIFTLING_REVIEWED,
				),
				'reviewed',
			),
			'one machine'            => array(
				array(
					'a' => SWIFTLING_REVIEWED,
					'b' => SWIFTLING_MACHINE,
				),
				'machine',
			),
			'official counts'        => array( array( 'a' => SWIFTLING_OFFICIAL ), 'reviewed' ),
			'some missing'           => array(
				array(
					'a' => SWIFTLING_REVIEWED,
					'b' => null,
				),
				'partial',
			),
			'none'                   => array(
				array(
					'a' => null,
					'b' => null,
				),
				'missing',
			),
			'outdated wins'          => array(
				array(
					'a' => null,
					'b' => 'old',
				),
				'outdated',
			),
			'address does not count' => array(
				array(
					'a'  => SWIFTLING_REVIEWED,
					'~b' => null,
				),
				'reviewed',
			),
			'nothing to translate'   => array( array(), 'reviewed' ),
		);
	}

	/**
	 * A set of texts counts as translated when each has a translation now, had one before its
	 * original changed, or falls back to a translated place; an address does not count. An outdated
	 * text, which the state names first, does not make up for one never translated.
	 */
	public function test_units_translated() {
		$translated = array(
			'text'   => 'x',
			'status' => SWIFTLING_MACHINE,
		);
		$places     = array(
			'a'  => array( 'now-a', 'old-a' ),
			'b'  => array( 'now-b', '' ),
			'c'  => array( 'now-c', '', 'title' ),
			'~d' => array( 'now-d', '' ),
		);
		$this->assertTrue( swiftling_units_translated( $places, array_fill_keys( array( 'now-a', 'now-b', 'now-c' ), $translated ) ) );
		$this->assertTrue( swiftling_units_translated( $places, array_fill_keys( array( 'old-a', 'now-b', 'title' ), $translated ) ), 'Outdated, and falling back' );
		$this->assertFalse( swiftling_units_translated( $places, array_fill_keys( array( 'old-a', 'now-c' ), $translated ) ), 'b never translated' );
		$this->assertSame( 'outdated', swiftling_units_state( $places, array_fill_keys( array( 'old-a', 'now-c' ), $translated ) ), 'The state still names it first' );
		$this->assertTrue( swiftling_units_translated( array(), array() ), 'Nothing to translate' );
	}

	/**
	 * A page never opened in the editor is missing in every language.
	 */
	public function test_unchecked_post_is_missing() {
		$post = self::factory()->post->create();
		$this->assertSame(
			array(
				'de' => 'missing',
				'fr' => 'missing',
			),
			swiftling_language_states( $post )
		);
	}

	/**
	 * Counts of a subject's texts per language leave addresses out.
	 */
	public function test_subject_counts() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => 'Kratki opis',
			)
		);
		add_post_meta( $post->ID, 'cta', 'https://example.org/kontakt/' );
		$owner = 'post:' . $post->ID;
		$this->assertSame( array(), swiftling_subject_counts( $owner ), 'Never checked' );

		$this->check(
			$owner,
			static function () use ( $post ) {
				get_the_title( $post );
				get_post_meta( $post->ID, 'cta', true );
			},
			array( 'meta/cta' => 'href' )
		);
		$this->translate( $owner, 'field/post_title', 'Kontakt', 'de', 'Kontakt' );

		$this->assertSame(
			array(
				'de' => array(
					'translated' => 1,
					'total'      => 2,
				),
				'fr' => array(
					'translated' => 0,
					'total'      => 2,
				),
			),
			swiftling_subject_counts( $owner )
		);
		$this->assertSame(
			array(
				'de' => 'partial',
				'fr' => 'missing',
			),
			swiftling_language_states( $post->ID )
		);
	}

	/**
	 * The Overview counts published pages of the listed types once per language, copies left out.
	 */
	public function test_site_page_progress() {
		$checked = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		self::factory()->post->create();
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$copy = self::factory()->post->create();
		add_post_meta( $copy, SWIFTLING_COPY_OF, $checked->ID );
		add_post_meta( $copy, SWIFTLING_COPY_LANG, 'fr_FR' );
		swiftling_copy_map( true );

		$this->check(
			'post:' . $checked->ID,
			static function () use ( $checked ) {
				get_the_title( $checked );
			}
		);
		$this->translate( 'post:' . $checked->ID, 'field/post_title', 'Kontakt', 'de', 'Kontakt', SWIFTLING_MACHINE );

		$progress = swiftling_site_page_progress();
		$this->assertSame( 3, $progress['total'], 'Published posts and pages, without the copy and the draft' );
		$this->assertSame( 1, $progress['languages']['de']['machine'] );
		$this->assertSame( 2, $progress['languages']['de']['missing'] );
		$this->assertSame( 1, $progress['languages']['fr']['reviewed'], 'The published copy is the French translation' );
		$this->assertSame( 2, $progress['languages']['fr']['missing'] );
		$this->assertSame( array(), $progress['outdated'] );
		$this->assertSame( 3, array_sum( $progress['languages']['de'] ) );
	}

	/**
	 * A published copy is reviewed until its original changes after it.
	 */
	public function test_copy_state() {
		$original = self::factory()->post->create_and_get( array( 'post_modified_gmt' => '2026-01-01 00:00:00' ) );
		$copy     = self::factory()->post->create_and_get();
		add_post_meta( $copy->ID, SWIFTLING_COPY_OF, $original->ID );
		add_post_meta( $copy->ID, SWIFTLING_COPY_FROM, $original->post_modified_gmt );
		swiftling_copy_map( true );
		$this->assertSame( 'reviewed', swiftling_copy_state( $copy ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dating the original after its copy.
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2099-01-01 00:00:00' ), array( 'ID' => $original->ID ) );
		clean_post_cache( $original->ID );
		$this->assertSame( 'outdated', swiftling_copy_state( $copy ) );
	}
}
