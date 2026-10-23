<?php
/**
 * Tests of where translations are stored.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * The tables of translations, maps and rules, and the keys that tie them together.
 */
class StorageTest extends TestCase {

	/**
	 * The screens name a stored status by its state.
	 */
	public function test_status_state() {
		$this->assertSame( 'machine', swiftling_status_state( SWIFTLING_MACHINE ) );
		$this->assertSame( 'reviewed', swiftling_status_state( SWIFTLING_REVIEWED ) );
		$this->assertSame( 'official', swiftling_status_state( SWIFTLING_OFFICIAL ) );
	}

	/**
	 * Only 32 lowercase hex characters are hashes.
	 */
	public function test_is_hash() {
		$this->assertTrue( swiftling_is_hash( md5( 'a' ) ) );
		$this->assertFalse( swiftling_is_hash( strtoupper( md5( 'a' ) ) ) );
		$this->assertFalse( swiftling_is_hash( substr( md5( 'a' ), 1 ) ) );
		$this->assertFalse( swiftling_is_hash( null ) );
	}

	/**
	 * The source of a text is the part of its object it comes from.
	 */
	public function test_path_source() {
		$this->assertSame( 'meta/_elementor_data', swiftling_path_source( 'meta/_elementor_data/@json/#a1/settings/title' ) );
		$this->assertSame( 'field/post_title', swiftling_path_source( 'field/post_title' ) );
		$this->assertSame( 'option/blogname', swiftling_path_source( 'option/blogname' ) );
		$this->assertSame( 'b', swiftling_path_source( 'b/0/c0' ) );
		$this->assertSame( 'string', swiftling_path_source( 'string/' . md5( 'x' ) ) );
	}

	/**
	 * Texts and addresses have keys of their own kind in a map.
	 */
	public function test_map_keys() {
		$text = swiftling_path_key( 'field/post_title' );
		$link = swiftling_link_key( 'meta/url' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $text );
		$this->assertMatchesRegularExpression( '/^~[0-9a-f]{7}$/', $link );
		$this->assertFalse( swiftling_is_link_key( $text ) );
		$this->assertTrue( swiftling_is_link_key( $link ) );
	}

	/**
	 * The same words in two places, or two versions of a text in one place, are kept apart.
	 */
	public function test_place_keys_differ_by_place_and_version() {
		$hash = swiftling_hash( 'Kontakt' );
		$key  = swiftling_path_key( 'field/post_title' );
		$this->assertNotSame( swiftling_place_key( 'post:1', $key, $hash ), swiftling_place_key( 'post:2', $key, $hash ) );
		$this->assertNotSame( swiftling_place_key( 'post:1', $key, $hash ), swiftling_place_key( 'post:1', swiftling_path_key( 'field/post_excerpt' ), $hash ) );
		$this->assertNotSame( swiftling_place_key( 'post:1', $key, $hash ), swiftling_place_key( 'post:1', $key, swiftling_hash( 'Kontakti' ) ) );
		$this->assertTrue( swiftling_is_hash( swiftling_place_key( 'post:1', $key, $hash ) ) );
	}

	/**
	 * A shared key points back to the object and key of the shared text, through the page's references.
	 */
	public function test_shared_keys() {
		$text   = swiftling_path_key( 'option/blogname' );
		$shared = swiftling_shared_key( 'options', $text );
		$this->assertTrue( swiftling_is_shared_key( $shared ) );
		$this->assertFalse( swiftling_is_shared_key( $text ) );
		$this->assertSame( array( 'options', $text ), swiftling_shared_key_target( $shared, array( 'post:9', 'options' ) ) );
		$this->assertNull( swiftling_shared_key_target( $shared, array( 'post:9' ) ), 'Not among the references' );
		$this->assertNull( swiftling_shared_key_target( $text, array( 'options' ) ), 'Not shared' );

		$link = swiftling_shared_key( 'options', swiftling_link_key( 'option/url' ) );
		$this->assertSame( '~^', substr( $link, 0, 2 ) );
		$this->assertTrue( swiftling_is_link_key( $link ) );
		$this->assertSame( array( 'options', swiftling_link_key( 'option/url' ) ), swiftling_shared_key_target( $link, array( 'options' ) ) );
	}

	/**
	 * The places of a map: the current version of each text and, while it waits, the previous one.
	 */
	public function test_map_places() {
		$title  = swiftling_path_key( 'field/post_title' );
		$name   = swiftling_path_key( 'option/blogname' );
		$shared = swiftling_shared_key( 'options', $name );
		$new    = swiftling_hash( 'Novo' );
		$old    = swiftling_hash( 'Staro' );
		$site   = swiftling_hash( 'Stranica' );
		$map    = array(
			'units' => array(
				$title            => array( $new, $old ),
				$shared           => $site,
				'^zzzzzz12345678' => $site,
			),
			'refs'  => array( 'options' ),
		);

		$places = swiftling_map_places( 'post:1', $map );
		$this->assertSame(
			array(
				$title  => array( swiftling_place_key( 'post:1', $title, $new ), swiftling_place_key( 'post:1', $title, $old ) ),
				$shared => array( swiftling_place_key( 'options', $name, $site ), '' ),
			),
			$places,
			'A shared key whose object is not referenced is left out'
		);
	}

	/**
	 * Translations are saved, replaced and removed by place and language.
	 */
	public function test_save_and_get_translations() {
		$one = md5( 'one' );
		$two = md5( 'two' );
		swiftling_save_translations(
			'de_DE',
			array(
				$one        => array(
					'text'   => 'Eins',
					'status' => SWIFTLING_MACHINE,
				),
				$two        => array(
					'text'   => 'Zwei',
					'status' => SWIFTLING_REVIEWED,
				),
				'not-a-key' => array(
					'text'   => 'Ignored',
					'status' => SWIFTLING_REVIEWED,
				),
			)
		);

		$found = swiftling_get_translations( 'de_DE', array( $one, $two, 'not-a-key', $one ) );
		ksort( $found );
		$expected = array(
			$one => array(
				'text'   => 'Eins',
				'status' => SWIFTLING_MACHINE,
			),
			$two => array(
				'text'   => 'Zwei',
				'status' => SWIFTLING_REVIEWED,
			),
		);
		ksort( $expected );
		$this->assertSame( $expected, $found );
		$this->assertSame( array(), swiftling_get_translations( 'fr_FR', array( $one ) ), 'Another language has none' );

		swiftling_save_translations(
			'de_DE',
			array(
				$one => array(
					'text'   => 'Ein',
					'status' => SWIFTLING_REVIEWED,
				),
				$two => array(
					'text'   => '',
					'status' => SWIFTLING_REVIEWED,
				),
			)
		);
		$this->assertSame(
			array(
				$one => array(
					'text'   => 'Ein',
					'status' => SWIFTLING_REVIEWED,
				),
			),
			swiftling_get_translations( 'de_DE', array( $one, $two ) )
		);
	}

	/**
	 * Hundreds of translations go in batches and come back whole, with text beyond ASCII intact.
	 */
	public function test_many_translations() {
		$items = array();
		for ( $i = 0; $i < 1234; $i++ ) {
			$items[ md5( 'text ' . $i ) ] = array(
				'text'   => 'Čćžšđ ' . $i,
				'status' => SWIFTLING_REVIEWED,
			);
		}
		swiftling_save_translations( 'hr', $items );
		$found = swiftling_get_translations( 'hr', array_keys( $items ) );
		$this->assertCount( 1234, $found );
		$this->assertSame( 'Čćžšđ 1233', $found[ md5( 'text 1233' ) ]['text'] );
	}

	/**
	 * Maps keep their units, sources, references and time, and can be read by prefix.
	 */
	public function test_save_and_get_maps() {
		$units = array(
			'aaaaaaaa' => md5( 'a' ),
			'bbbbbbbb' => array( md5( 'b' ), md5( 'c' ) ),
		);
		swiftling_save_map( 'post:7', $units, array( 'field/post_title', 'b' ), array( 'options' ), 1234567890 );
		swiftling_save_map( 'gettext:acme', array(), array(), array(), 1 );
		swiftling_save_map( 'gettext_other', array(), array(), array(), 1 );

		$maps = swiftling_get_maps( array( 'post:7', 'post:8' ) );
		$this->assertSame( array( 'post:7' ), array_keys( $maps ) );
		$this->assertSame( $units, $maps['post:7']['units'] );
		$this->assertSame( array( 'field/post_title', 'b' ), $maps['post:7']['sources'] );
		$this->assertSame( array( 'options' ), $maps['post:7']['refs'] );
		$this->assertSame( 1234567890, $maps['post:7']['changed'] );

		$this->assertSame( array( 'gettext:acme' ), array_keys( swiftling_get_maps_by_prefix( 'gettext:' ) ), 'The prefix is matched literally' );
		$this->assertSame( array(), swiftling_get_maps_by_prefix( 'nothing:' ) );
	}

	/**
	 * A post deleted for good takes the map of its texts with it, and what was cached of it; a post
	 * in the trash keeps its map, as it may come back. The texts' translations stay: they are kept by
	 * text and may be shared.
	 */
	public function test_deleted_post_takes_its_map() {
		$kept    = self::factory()->post->create();
		$deleted = self::factory()->post->create();
		swiftling_save_map( 'post:' . $kept, array( 'aaaaaaaa' => md5( 'a' ) ), array( 'field/post_title' ), array(), 1 );
		swiftling_save_map( 'post:' . $deleted, array( 'aaaaaaaa' => md5( 'a' ) ), array( 'field/post_title' ), array(), 1 );
		swiftling_save_translations(
			'de_DE',
			array(
				md5( 'a' ) => array(
					'text'   => 'A',
					'status' => SWIFTLING_REVIEWED,
				),
			)
		);
		$this->assertCount( 1, swiftling_get_maps( array( 'post:' . $deleted ) ) );
		$version = swiftling_cache_version();

		wp_trash_post( $kept );
		wp_delete_post( $deleted, true );
		$this->assertSame( array(), swiftling_get_maps( array( 'post:' . $deleted ) ) );
		$this->assertCount( 1, swiftling_get_maps( array( 'post:' . $kept ) ), 'In the trash it may come back' );
		$this->assertNotSame( $version, swiftling_cache_version(), 'What was cached of it goes' );
		$this->assertCount( 1, swiftling_get_translations( 'de_DE', array( md5( 'a' ) ) ), 'Translations are the texts\'' );
	}

	/**
	 * Updating the tables to version 11 deletes the maps of posts deleted before they took their
	 * maps with them, and nothing else; running it again deletes nothing more.
	 */
	public function test_update_deletes_maps_of_deleted_posts() {
		$post = self::factory()->post->create();
		swiftling_save_map( 'post:' . $post, array(), array(), array(), 1 );
		swiftling_save_map( 'post:999999', array(), array(), array(), 1 );
		swiftling_save_map( 'template:theme//page', array(), array(), array(), 1 );
		update_option( 'swiftling_db_version', 10 );

		swiftling_maybe_install_tables();
		$this->assertSame( 11, (int) get_option( 'swiftling_db_version' ) );
		$this->assertSame( array( 'post:' . $post, 'template:theme//page' ), array_keys( swiftling_get_maps( array( 'post:' . $post, 'post:999999', 'template:theme//page' ) ) ) );

		swiftling_delete_orphan_maps();
		$this->assertCount( 2, swiftling_get_maps( array( 'post:' . $post, 'template:theme//page' ) ), 'Again: nothing more' );
	}

	/**
	 * Saving a map or translations retires everything cached, also in a persistent object cache.
	 */
	public function test_saving_retires_the_cache() {
		$before = swiftling_cache_version();
		$this->assertSame( $before, swiftling_cache_version() );
		swiftling_save_map( 'post:1', array(), array(), array(), 1 );
		$after = swiftling_cache_version();
		$this->assertNotSame( $before, $after );
		swiftling_save_translations( 'de_DE', array() );
		$this->assertNotSame( $after, swiftling_cache_version() );
	}

	/**
	 * Rules are saved and read by key.
	 */
	public function test_rules() {
		swiftling_save_rules(
			array(
				'meta:x|heading|settings/title' => SWIFTLING_RULE_TEXT,
				'meta:x|heading|settings/css'   => SWIFTLING_RULE_NOT_TEXT,
			)
		);
		$this->assertSame(
			array( 'meta:x|heading|settings/title' => SWIFTLING_RULE_TEXT ),
			swiftling_get_rules( array( 'meta:x|heading|settings/title', 'unknown' ) )
		);
	}

	/**
	 * The upgrade to places copies each translation saved by text to every place that shows the text,
	 * and leaves a translation already saved by place alone.
	 */
	public function test_upgrade_to_places() {
		$title   = swiftling_path_key( 'field/post_title' );
		$excerpt = swiftling_path_key( 'field/post_excerpt' );
		$hash    = swiftling_hash( 'Kontakt' );
		swiftling_save_map(
			'post:3',
			array(
				$title   => $hash,
				$excerpt => $hash,
			),
			array(),
			array(),
			1
		);
		// Before version 4 the key was the hash of the text alone.
		swiftling_save_translations(
			'de_DE',
			array(
				$hash => array(
					'text'   => 'Kontakt DE',
					'status' => SWIFTLING_REVIEWED,
				),
				swiftling_place_key( 'post:3', $excerpt, $hash ) => array(
					'text'   => 'Already by place',
					'status' => SWIFTLING_MACHINE,
				),
			)
		);

		swiftling_upgrade_to_places();
		swiftling_upgrade_to_places();

		$found = swiftling_get_translations( 'de_DE', array( swiftling_place_key( 'post:3', $title, $hash ), swiftling_place_key( 'post:3', $excerpt, $hash ) ) );
		$this->assertSame( 'Kontakt DE', $found[ swiftling_place_key( 'post:3', $title, $hash ) ]['text'] );
		$this->assertSame( 'Already by place', $found[ swiftling_place_key( 'post:3', $excerpt, $hash ) ]['text'] );
	}
}
