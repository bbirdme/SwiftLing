<?php
/**
 * Tests of what the editor learns from a page check.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Verdicts from where markers landed, the maps and rules a page check stores, and who may save what.
 */
class ProbeResultsTest extends TestCase {

	/**
	 * Returns a marked value as the probe lists it.
	 *
	 * @param string $path  Path inside the object.
	 * @param string $value Value.
	 * @param string $owner Object key.
	 * @param string $rule  Rule key; by default one of its own.
	 * @return array
	 */
	private function leaf( $path, $value, $owner = 'post:1', $rule = '' ) {
		return array(
			'o' => $owner,
			'p' => $path,
			'v' => $value,
			'r' => '' !== $rule ? $rule : 'meta:x||' . $path,
		);
	}

	/**
	 * A value whose marker landed in visible text is a text, and its field is learned as text.
	 */
	public function test_visible_text_is_text() {
		$verdicts = swiftling_probe_verdicts(
			array( 1 => $this->leaf( 'field/post_title', 'Naslov', 'post:1', 'field:post_title||' ) ),
			array( 1 => array( $this->landing( 'text', 3, array( 'kind' => 'heading' ) ) ) ),
			array()
		);
		$this->assertCount( 1, $verdicts['units'] );
		$unit = $verdicts['units'][0];
		$this->assertSame( array( 'heading', 3, false, false ), array( $unit['kind'], $unit['order'], $unit['mixed'], $unit['rescued'] ) );
		$this->assertSame( array( 'field:post_title||' => SWIFTLING_RULE_TEXT ), $verdicts['rules'] );
	}

	/**
	 * Of several landings, visible text ranks before attributes and the head of the page.
	 */
	public function test_visible_text_ranks_first() {
		$verdicts = swiftling_probe_verdicts(
			array( 1 => $this->leaf( 'meta/a', 'Naslov' ) ),
			array(
				1 => array(
					$this->landing( 'meta', 1, array( 'kind' => 'meta_description' ) ),
					$this->landing( 'alt', 2, array( 'kind' => 'alt' ) ),
					$this->landing( 'text', 9, array( 'kind' => 'heading' ) ),
				),
			),
			array()
		);
		$this->assertSame( 'heading', $verdicts['units'][0]['kind'] );
	}

	/**
	 * What a block saved between its tags is text whatever it says, also where a script reads it from
	 * a data attribute. The attributes of a shortcode in it are settings of the shortcode, as a
	 * builder's button keeps its hover effect "none", which it prints in data-hover: no text there,
	 * unless they look like text. The content between a shortcode's tags is still written text.
	 */
	public function test_shortcode_attribute_in_a_blocks_html_is_no_declared_text() {
		$verdicts = swiftling_probe_verdicts(
			array(
				1 => $this->leaf( 'b/0/c0/@sc0/hover_transition', 'none', 'post:1', 'block:freeform#html|fusion_button|attr/hover_transition' ),
				2 => $this->leaf( 'b/0/c0/@sc0/content', 'Pošalji', 'post:1', 'block:freeform#html|fusion_button|content' ),
				3 => $this->leaf( 'b/1/c0', 'ok', 'post:1', 'block:core/code#html||' ),
			),
			array(
				1 => array( $this->landing( 'data', 1 ) ),
				2 => array( $this->landing( 'data', 2 ) ),
				3 => array( $this->landing( 'data', 3 ) ),
			),
			array()
		);
		$values   = wp_list_pluck( $verdicts['units'], 'v' );
		$this->assertNotContains( 'none', $values );
		$this->assertContains( 'Pošalji', $values );
		$this->assertContains( 'ok', $values, 'One word a block saved is text' );
		$this->assertSame( SWIFTLING_RULE_NOT_TEXT, $verdicts['rules']['block:freeform#html|fusion_button|attr/hover_transition'] );
	}

	/**
	 * A value that shows as text but also sits in a class is a setting, such as an icon name.
	 */
	public function test_value_in_a_class_is_a_setting() {
		$verdicts = swiftling_probe_verdicts(
			array( 1 => $this->leaf( 'meta/icon', 'Check Mark' ) ),
			array( 1 => array( $this->landing( 'text' ), $this->landing( 'class' ) ) ),
			array()
		);
		$this->assertSame( array(), $verdicts['units'] );
		$this->assertCount( 1, $verdicts['classed'] );
		$this->assertSame( array( 'meta:x||meta/icon' => SWIFTLING_RULE_NOT_TEXT ), $verdicts['rules'] );
	}

	/**
	 * Text a page also uses as an anchor is translated and marked for checking.
	 */
	public function test_value_in_an_id_is_text_to_check() {
		$verdicts = swiftling_probe_verdicts(
			array( 1 => $this->leaf( 'meta/section', 'O nama' ) ),
			array( 1 => array( $this->landing( 'text' ), $this->landing( 'id' ) ) ),
			array()
		);
		$this->assertTrue( $verdicts['units'][0]['mixed'] );
	}

	/**
	 * A value that landed only in a style, an address or a script is no text.
	 */
	public function test_value_seen_only_in_code_is_no_text() {
		$verdicts = swiftling_probe_verdicts(
			array( 1 => $this->leaf( 'meta/font', 'Open Sans' ) ),
			array( 1 => array( $this->landing( 'style' ), $this->landing( 'script' ) ) ),
			array()
		);
		$this->assertSame( array(), $verdicts['units'] );
		$this->assertSame( array(), $verdicts['not_seen'] );
		$this->assertSame( array( 'meta:x||meta/font' => SWIFTLING_RULE_NOT_TEXT ), $verdicts['rules'] );
	}

	/**
	 * A value whose marker landed nowhere is rescued once by the page rendered without markers.
	 */
	public function test_unseen_value_is_rescued_once() {
		$verdicts = swiftling_probe_verdicts(
			array(
				1 => $this->leaf( 'option/blogname', 'Moja stranica', 'options' ),
				2 => $this->leaf( 'meta/site', 'Moja stranica' ),
				3 => $this->leaf( 'meta/other', 'Nigdje' ),
			),
			array(),
			array(
				array(
					't' => 'Moja  STRANICA',
					'k' => 'site_title',
					'r' => 'header',
					'c' => false,
				),
			)
		);
		$this->assertCount( 1, $verdicts['units'] );
		$unit = $verdicts['units'][0];
		$this->assertSame( array( 'options', true, 'site_title', 'header', 1000000 ), array( $unit['o'], $unit['rescued'], $unit['kind'], $unit['region'], $unit['order'] ) );
		$this->assertSame( array( 'meta/site', 'meta/other' ), wp_list_pluck( $verdicts['not_seen'], 'p' ) );
	}

	/**
	 * A text already found is not rescued a second time for another value.
	 */
	public function test_found_text_explains_the_control() {
		$verdicts = swiftling_probe_verdicts(
			array(
				1 => $this->leaf( 'meta/a', 'Dobro došli' ),
				2 => $this->leaf( 'meta/b', 'Dobro došli' ),
			),
			array( 1 => array( $this->landing( 'text' ) ) ),
			array(
				array(
					't' => 'Dobro došli',
					'k' => 'paragraph',
					'r' => '',
					'c' => true,
				),
			)
		);
		$this->assertCount( 1, $verdicts['units'] );
		$this->assertSame( 'meta/a', $verdicts['units'][0]['p'] );
	}

	/**
	 * A block attribute that repeats text of the block's saved HTML is left out: the HTML is what shows.
	 */
	public function test_attribute_repeating_the_blocks_html_is_left_out() {
		$verdicts = swiftling_probe_verdicts(
			array(
				1 => $this->leaf( 'b/0/c0', '<p>Pozdrav svima</p>' ),
				2 => $this->leaf( 'b/0/a/content', 'Pozdrav svima' ),
			),
			array( 1 => array( $this->landing( 'text' ) ) ),
			array()
		);
		$this->assertSame( array( 'b/0/c0' ), wp_list_pluck( $verdicts['units'], 'p' ) );
		$this->assertSame( array(), $verdicts['not_seen'] );
	}

	/**
	 * A link joins the text inside it; a link without text gets a row of its own.
	 */
	public function test_links() {
		$verdicts = swiftling_probe_verdicts(
			array(
				1 => $this->leaf( 'meta/label', 'Pišite nam' ),
				2 => $this->leaf( 'meta/url', '/kontakt/' ),
				3 => $this->leaf( 'meta/more', 'https://example.org/vise/' ),
			),
			array(
				1 => array( $this->landing( 'text', 1, array( 'link' => 7 ) ) ),
				2 => array( $this->landing( 'href', 2, array( 'el' => 7 ) ) ),
				3 => array( $this->landing( 'href', 3, array( 'el' => 9 ) ) ),
			),
			array()
		);
		$this->assertCount( 2, $verdicts['units'] );
		$this->assertSame( '/kontakt/', $verdicts['units'][0]['link']['v'] );
		$this->assertSame( 'url', $verdicts['units'][0]['link']['kind'] );
		$this->assertSame( 'https://example.org/vise/', $verdicts['units'][1]['v'] );
		$this->assertSame( 'url', $verdicts['units'][1]['kind'] );
		$this->assertCount( 2, $verdicts['links'] );
	}

	/**
	 * Addresses of images and videos are images and videos; a page address in a style is nothing.
	 */
	public function test_media_addresses() {
		$verdicts = swiftling_probe_verdicts(
			array(
				1 => $this->leaf( 'meta/photo', 'https://example.org/a.jpg' ),
				2 => $this->leaf( 'meta/bg', '/uploads/bg.webp' ),
				3 => $this->leaf( 'meta/page', '/o-nama/' ),
				4 => $this->leaf( 'meta/video', 'https://www.youtube.com/embed/abc' ),
			),
			array(
				1 => array( $this->landing( 'image' ) ),
				2 => array( $this->landing( 'style' ) ),
				3 => array( $this->landing( 'style' ) ),
				4 => array( $this->landing( 'frame' ) ),
			),
			array()
		);
		$this->assertSame(
			array(
				'meta/photo' => 'image',
				'meta/bg'    => 'image',
				'meta/video' => 'video',
			),
			wp_list_pluck( $verdicts['units'], 'kind', 'p' )
		);
	}

	/**
	 * A field seen as text and as a setting on the same page is mixed; texts from code teach nothing.
	 */
	public function test_rules() {
		$verdicts = swiftling_probe_verdicts(
			array(
				1 => $this->leaf( 'meta/a', 'Prvi', 'post:1', 'meta:x|w|title' ),
				2 => $this->leaf( 'meta/b', 'Drugi', 'post:1', 'meta:x|w|title' ),
				3 => $this->leaf( 'string/abc', 'Read more', 'gettext:acme', 'gettext|x|y' ),
			),
			array(
				1 => array( $this->landing( 'text' ) ),
				2 => array( $this->landing( 'class' ) ),
				3 => array( $this->landing( 'text' ) ),
			),
			array()
		);
		$this->assertSame( array( 'meta:x|w|title' => SWIFTLING_RULE_MIXED ), $verdicts['rules'] );
	}

	/**
	 * A rule learned on one page and contradicted on another becomes mixed; an agreeing one stays.
	 */
	public function test_learned_rules_merge() {
		swiftling_save_rules(
			array(
				'r:a' => SWIFTLING_RULE_TEXT,
				'r:b' => SWIFTLING_RULE_TEXT,
			)
		);
		swiftling_save_learned_rules(
			array(
				'r:a' => SWIFTLING_RULE_NOT_TEXT,
				'r:b' => SWIFTLING_RULE_TEXT,
				'r:c' => SWIFTLING_RULE_NOT_TEXT,
			)
		);
		$rules = swiftling_get_rules( array( 'r:a', 'r:b', 'r:c' ) );
		ksort( $rules );
		$this->assertSame(
			array(
				'r:a' => SWIFTLING_RULE_MIXED,
				'r:b' => SWIFTLING_RULE_TEXT,
				'r:c' => SWIFTLING_RULE_NOT_TEXT,
			),
			$rules
		);
	}

	/**
	 * The kind of an address follows from its file or its site.
	 *
	 * @dataProvider data_media
	 *
	 * @param string $address  Address.
	 * @param string $expected Kind.
	 */
	public function test_media_kind( $address, $expected ) {
		$this->assertSame( $expected, swiftling_media_kind( $address ) );
	}

	/**
	 * Addresses and their kinds.
	 *
	 * @return array
	 */
	public function data_media() {
		return array(
			'jpeg'        => array( 'https://example.org/a.JPEG', 'image' ),
			'svg'         => array( '/logo.svg', 'image' ),
			'mp4'         => array( '/film.mp4', 'video' ),
			'audio'       => array( '/song.mp3', 'video' ),
			'youtube'     => array( 'https://www.youtube.com/watch?v=x', 'video' ),
			'short link'  => array( 'https://youtu.be/x', 'video' ),
			'vimeo'       => array( 'https://player.vimeo.com/video/1', 'video' ),
			'page'        => array( 'https://example.org/o-nama/', '' ),
			'lookalike'   => array( 'https://notyoutube.com/x', '' ),
			'query image' => array( '/image.php?f=a.jpg', '' ),
		);
	}

	/**
	 * A value belongs to the block its path names.
	 */
	public function test_block_path() {
		$this->assertSame( 'b/3/0', swiftling_block_path( 'b/3/0/a/title' ) );
		$this->assertSame( 'b/3/0', swiftling_block_path( 'b/3/0/c1' ) );
		$this->assertSame( '', swiftling_block_path( 'meta/x' ) );
		$this->assertSame( '', swiftling_block_path( 'b/3/0/c1/x' ) );
	}

	/**
	 * A value's pieces: its whole text, each paragraph or item, and its alt texts, in lowercase.
	 */
	public function test_text_pieces() {
		$pieces = swiftling_text_pieces( '<p>Prvi Red</p><ul><li>Drugi</li></ul><img alt="Slika Mora">' );
		foreach ( array( 'prvi red', 'drugi', 'slika mora' ) as $piece ) {
			$this->assertContains( $piece, $pieces );
		}
	}

	/**
	 * Where the editor of a page lists a text: the page's own texts, its head, and shared texts in its content.
	 */
	public function test_unit_section() {
		$other = self::factory()->post->create();
		$cases = array(
			array( array( 'post:1', 'field/post_title', 'heading', true ), 'page' ),
			array( array( 'post:1', 'meta/seo', 'seo_title', false ), 'search' ),
			array( array( 'post:1', 'meta/seo', 'seo_title', true ), 'page' ),
			array( array( 'post:1', 'meta/og', 'social_title', false ), 'search' ),
			array( array( 'post:1', 'meta/og-text', 'social_description', false ), 'search' ),
			array( array( 'options', 'option/blogname', 'site_title', false ), '' ),
			array( array( 'options', 'option/blogname', 'paragraph', true ), 'page' ),
			array( array( 'gettext:acme', 'string/x', 'button', true ), 'page' ),
			array( array( 'post:' . $other, 'field/post_title', 'heading', true ), '' ),
			array( array( 'post:' . $other, 'b/0/c0', 'paragraph', true ), 'page' ),
		);
		foreach ( $cases as $index => $case ) {
			list( $owner, $path, $kind, $content ) = $case[0];
			$unit                                  = array(
				'o'       => $owner,
				'p'       => $path,
				'kind'    => $kind,
				'content' => $content,
			);
			$this->assertSame( $case[1], swiftling_unit_section( $unit, 'post:1', true ), 'Case ' . $index );
		}
		$this->assertSame(
			'',
			swiftling_unit_section(
				array(
					'o'       => 'options',
					'p'       => 'option/blogname',
					'kind'    => 'paragraph',
					'content' => true,
				),
				'post:1',
				false
			),
			'Without the content area, a shared text cannot be placed'
		);
	}

	/**
	 * Where the editor of a template lists a text: what every page shows, the template's own, or nothing.
	 */
	public function test_template_unit_section() {
		$menu = self::factory()->post->create( array( 'post_type' => 'wp_navigation' ) );
		$post = self::factory()->post->create();
		$unit = static function ( $owner, $region = '', $content = false ) {
			return array(
				'o'       => $owner,
				'region'  => $region,
				'content' => $content,
			);
		};
		$this->assertSame( 'site', swiftling_template_unit_section( $unit( 'part:theme//header' ) ) );
		$this->assertSame( 'site', swiftling_template_unit_section( $unit( 'options' ) ) );
		$this->assertSame( 'site', swiftling_template_unit_section( $unit( 'post:' . $menu ) ) );
		$this->assertSame( 'site', swiftling_template_unit_section( $unit( 'gettext:acme', 'footer' ) ) );
		$this->assertSame( 'page', swiftling_template_unit_section( $unit( 'gettext:acme', 'main' ) ) );
		$this->assertSame( 'page', swiftling_template_unit_section( $unit( 'template:theme//archive' ) ) );
		$this->assertSame( '', swiftling_template_unit_section( $unit( 'post:' . $post ) ), 'A post is translated in its own editor' );
		$this->assertSame( '', swiftling_template_unit_section( $unit( 'options', '', true ) ), 'Content of posts' );
	}

	/**
	 * The excerpt an author wrote is a text of the post even where the page does not show it.
	 */
	public function test_excerpt_is_listed() {
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => 'Kratki opis',
			)
		);
		$result = $this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->assertSame( 'lists', $this->found( $result, 'Kratki opis' )['section'] );
		$this->assertSame( array( 'field/post_title', 'field/post_excerpt' ), wp_list_pluck( $result['units'], 'p' ) );
	}

	/**
	 * Checking a page again keeps the previous version of a changed text until every language has the new one.
	 */
	public function test_outdated_versions_settle() {
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		$owner  = 'post:' . $post->ID;
		$key    = swiftling_path_key( 'field/post_title' );
		$render = static function () use ( $post ) {
			get_the_title( $post->ID );
		};
		$this->check( $owner, $render );
		$this->translate( $owner, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );

		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => 'Kontakti',
			)
		);
		$this->check( $owner, $render );
		$map = swiftling_get_maps( array( $owner ) )[ $owner ]['units'];
		$this->assertSame( array( swiftling_hash( 'Kontakti' ), swiftling_hash( 'Kontakt' ) ), $map[ $key ] );

		$this->translate( $owner, 'field/post_title', 'Kontakti', 'de', 'Kontakte' );
		$this->check( $owner, $render );
		$map = swiftling_get_maps( array( $owner ) )[ $owner ]['units'];
		$this->assertSame( swiftling_hash( 'Kontakti' ), $map[ $key ] );
	}

	/**
	 * A text that is no longer on the page leaves the page's map, and the page counts as changed.
	 */
	public function test_removed_text_leaves_the_map() {
		$post  = self::factory()->post->create_and_get( array( 'post_excerpt' => '' ) );
		$owner = 'post:' . $post->ID;
		add_post_meta( $post->ID, 'subtitle', 'Podnaslov stranice' );
		$this->check(
			$owner,
			static function () use ( $post ) {
				get_the_title( $post );
				get_post_meta( $post->ID, 'subtitle', true );
			}
		);
		$this->assertCount( 2, swiftling_get_maps( array( $owner ) )[ $owner ]['units'] );

		$this->check(
			$owner,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$map = swiftling_get_maps( array( $owner ) )[ $owner ];
		$this->assertSame( array( swiftling_path_key( 'field/post_title' ) ), array_keys( $map['units'] ) );
		$this->assertSame( array( 'field/post_title' ), $map['sources'] );
	}

	/**
	 * The featured image of a page shows outside its content, as the theme prints it, and belongs to
	 * the media library. The editor of the page offers it all the same, with its alt text, in the
	 * section of the page's fields: before, it offered no image a page shows outside its content.
	 */
	public function test_featured_image_is_offered_with_the_page() {
		$image = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/naslovna.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $image, '_wp_attachment_image_alt', 'Pogled na more' );
		$post = self::factory()->post->create_and_get( array( 'post_excerpt' => '' ) );
		set_post_thumbnail( $post, $image );
		$url = wp_get_attachment_url( $image );

		$result = swiftling_save_probe(
			swiftling_subject( 'post:' . $post->ID ),
			array(
				'leaves' => array(
					1 => $this->leaf( 'field/post_title', $post->post_title, 'post:' . $post->ID, 'field:post_title||' ),
					2 => $this->leaf( 'file', $url, 'post:' . $image, 'attachment:file||' ),
				),
			),
			array(
				1 => array( $this->landing( 'text', 1, array( 'kind' => 'heading' ) ) ),
				2 => array(
					$this->landing(
						'image',
						2,
						array(
							'content' => 0,
							'region'  => 'main',
						)
					),
				),
			),
			array(),
			true
		);

		$fields = array();
		foreach ( $result['sections']['fields'] as $unit ) {
			$fields[ $unit['o'] . '|' . $unit['p'] ] = $unit;
		}
		$this->assertArrayHasKey( 'post:' . $image . '|file', $fields, 'The featured image' );
		$this->assertSame( 'image', $fields[ 'post:' . $image . '|file' ]['kind'] );
		$this->assertSame( 'Pogled na more', $fields[ 'post:' . $image . '|' . swiftling_meta_path( '_wp_attachment_image_alt', 0 ) ]['v'] );
	}

	/**
	 * Who may save what: the page's own texts with the right to edit the page, shared texts with the
	 * right to edit their object, and addresses as addresses.
	 */
	public function test_translatable_keys_follow_the_rights() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create_and_get(
			array(
				'post_author'  => $author,
				'post_title'   => 'Moj članak',
				'post_excerpt' => '',
			)
		);
		update_option( 'blogname', 'Moja stranica' );
		add_post_meta( $post->ID, 'cta', 'https://example.org/kontakt/' );
		$owner = 'post:' . $post->ID;
		$this->check(
			$owner,
			static function () use ( $post ) {
				get_the_title( $post );
				get_option( 'blogname' );
				get_post_meta( $post->ID, 'cta', true );
			},
			array( 'meta/cta' => 'href' )
		);

		$this->become( 'administrator' );
		$all = swiftling_translatable_keys( $owner );
		$this->assertSame( array( 'text', 'text', 'url' ), array_values( $all ) );

		wp_set_current_user( $author );
		$own = swiftling_translatable_keys( $owner );
		$this->assertCount( 2, $own, 'Not the site title' );
		$this->assertArrayHasKey( swiftling_place_key( $owner, swiftling_path_key( 'field/post_title' ), swiftling_hash( 'Moj članak' ) ), $own );

		$this->become( 'subscriber' );
		$this->assertSame( array(), swiftling_translatable_keys( $owner ) );
		$this->assertSame( array(), swiftling_translatable_keys( 'post:999999' ), 'A page never checked' );
	}

	/**
	 * The right to translate an object is the right to edit it.
	 */
	public function test_can_translate_object() {
		$term = self::factory()->category->create();
		$this->become( 'editor' );
		$post = self::factory()->post->create();
		$this->assertTrue( swiftling_can_translate_object( 'post:' . $post ) );
		$this->assertTrue( swiftling_can_translate_object( 'term:' . $term ) );
		$this->assertFalse( swiftling_can_translate_object( 'options' ) );
		$this->assertFalse( swiftling_can_translate_object( 'gettext:acme' ) );
		$this->assertFalse( swiftling_can_translate_object( 'part:theme//header' ) );

		$this->become( 'administrator' );
		$this->assertTrue( swiftling_can_translate_object( 'options' ) );
		$this->assertTrue( swiftling_can_translate_object( 'part:theme//header' ) );
	}

	/**
	 * The terms of a taxonomy are listed with their names and descriptions, without a page check.
	 */
	public function test_term_list() {
		$news   = self::factory()->category->create(
			array(
				'name'        => 'Vijesti',
				'description' => 'Najnovije',
			)
		);
		$result = swiftling_save_term_list( swiftling_subject( 'terms:category' ) );

		$this->assertSame( 0, $result['more'] );
		$this->assertContains( 'Vijesti', wp_list_pluck( $result['units'], 'v' ) );
		$this->assertContains( 'Najnovije', wp_list_pluck( $result['units'], 'v' ) );
		$this->assertArrayHasKey( 'term:' . $news, $result['maps'] );
		$this->assertArrayHasKey( 'terms:category', $result['maps'] );
		$this->assertTrue( swiftling_is_shared_key( (string) array_keys( $result['maps']['terms:category']['units'] )[0] ) );
	}

	/**
	 * WordPress's own translation of a text from code is found in its language files.
	 */
	public function test_official_translations() {
		$this->assertContains( 'de_DE', get_available_languages(), 'WordPress\'s test data has German' );
		$found = swiftling_official_translations(
			'de_DE',
			array(
				'p1' => array(
					'd' => 'default',
					't' => 'Update %s now',
				),
				'p2' => array(
					'd' => 'default',
					't' => 'A text WordPress never translated',
				),
			)
		);
		$this->assertSame( array( 'p1' => 'Jetzt %s aktualisieren' ), $found );
		$this->assertSame( array(), swiftling_official_translations( 'fr_FR', array( 'p1' => array( 't' => 'x' ) ) ), 'Not installed' );
		$this->assertSame( 'en_US', determine_locale(), 'The locale is put back' );
	}
}
