<?php
/**
 * Tests of the segmenter.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * How stored values are walked down to their texts, and which texts are candidates.
 */
class SegmenterTest extends TestCase {

	/**
	 * Leaves the callback saw: value, path and rule key.
	 *
	 * @var array
	 */
	private $leaves = array();

	/**
	 * Walks a value, remembers every leaf and returns the value rebuilt with the leaves in capitals.
	 *
	 * @param mixed  $value  Value.
	 * @param string $path   Path of the value.
	 * @param string $source Source of the value.
	 * @return mixed
	 */
	private function walk( $value, $path = 'meta/x', $source = 'meta:x' ) {
		$this->leaves = array();
		return swiftling_walk(
			$value,
			function ( $leaf, $leaf_path, $rule ) {
				$this->leaves[] = array( $leaf, $leaf_path, $rule );
				return mb_strtoupper( $leaf );
			},
			$path,
			$source
		);
	}

	/**
	 * Text a person wrote is a candidate; settings, addresses and identifiers are not.
	 *
	 * @dataProvider data_text_like
	 *
	 * @param string $value    Value.
	 * @param bool   $expected Whether it looks like text.
	 */
	public function test_is_text_like( $value, $expected ) {
		$this->assertSame( $expected, swiftling_is_text_like( $value ) );
	}

	/**
	 * Values and whether they look like text.
	 *
	 * @return array
	 */
	public function data_text_like() {
		return array(
			'capitalised word'     => array( 'Kontakt', true ),
			'sentence'             => array( 'Pišite nam bilo kada.', true ),
			'letters beyond ascii' => array( 'čitaj', true ),
			'lowercase phrase'     => array( 'read more about us', true ),
			'html with text'       => array( '<p>Dobro <b>došli</b></p>', true ),
			'empty'                => array( '', false ),
			'number'               => array( '1200', false ),
			'size'                 => array( '12px', false ),
			'machine date'         => array( '2027-06-01T00:00:00Z', false ),
			'address'              => array( 'https://example.org/a', false ),
			'path'                 => array( '/kontakt/', false ),
			'anchor'               => array( '#top', false ),
			'mail link'            => array( 'mailto:info@example.org', false ),
			'mail address'         => array( 'info@example.org', false ),
			'class of a widget'    => array( 'SiteOrigin_Widget_Headline_Widget', false ),
			'php class'            => array( 'App\\Widgets\\Hero', false ),
			'css colour'           => array( 'rgba(0, 0, 0, 0.5)', false ),
			'css rule'             => array( '.a { color: red }', false ),
			'one setting'          => array( 'center', false ),
			'one lowercase word'   => array( 'prijava', false ),
			'heading level'        => array( 'h2', false ),
			'icon classes'         => array( 'fas fa-check', false ),
			'block classes'        => array( 'is-style-outline has-text-color', false ),
			'already marked'       => array( 'Kontakt zqk1kqz', false ),
		);
	}

	/**
	 * Link addresses are recognised, and nothing that only looks a bit like one.
	 */
	public function test_is_url_like() {
		foreach ( array( 'https://example.org/a', 'http://example.org', '//cdn.example.org/a.js', '/kontakt/', 'mailto:a@b.hr', 'tel:+385123', 'sms:+385123' ) as $url ) {
			$this->assertTrue( swiftling_is_url_like( $url ), $url );
		}
		foreach ( array( 'Kontakt', '//', 'javascript:alert(1)', '/kontakt/ i više', '/' . str_repeat( 'a', 2048 ), 'www.example.org', 12 ) as $other ) {
			$this->assertFalse( swiftling_is_url_like( $other ), (string) $other );
		}
	}

	/**
	 * A marker is letters and digits only, found in any case.
	 */
	public function test_markers() {
		$this->assertSame( 'zqk5kqz', swiftling_marker( 5 ) );
		$this->assertTrue( swiftling_has_marker( 'A zqk12kqz' ) );
		$this->assertTrue( swiftling_has_marker( 'A ZQK12KQZ' ), 'A theme may print the text in capitals' );
		$this->assertFalse( swiftling_has_marker( 'zqkkqz' ) );
	}

	/**
	 * In HTML the marker goes after the last visible character, inside the closing tags.
	 *
	 * @dataProvider data_markers
	 *
	 * @param string $value    Value.
	 * @param string $expected Value with marker 1.
	 */
	public function test_add_marker( $value, $expected ) {
		$this->assertSame( $expected, swiftling_add_marker( $value, 1 ) );
	}

	/**
	 * Values and where their marker goes.
	 *
	 * @return array
	 */
	public function data_markers() {
		return array(
			'plain text'        => array( 'Kontakt', 'Kontaktzqk1kqz' ),
			'paragraph'         => array( '<p>Kontakt</p>', '<p>Kontaktzqk1kqz</p>' ),
			'nested tags'       => array( '<p>A <b>B</b></p>', '<p>A <b>Bzqk1kqz</b></p>' ),
			'line break at end' => array( 'Kontakt <br>', 'Kontaktzqk1kqz <br>' ),
			'newline after'     => array( "<p>Kontakt</p>\n", "<p>Kontaktzqk1kqz</p>\n" ),
			'trailing space'    => array( 'Kontakt ', 'Kontakt zqk1kqz' ),
			'only a tag'        => array( '<br>', '<br>zqk1kqz' ),
		);
	}

	/**
	 * Arrays are walked to their strings; numbers and booleans are left alone; paths and rules say where.
	 */
	public function test_walk_array() {
		$value = array(
			'title' => 'Naslov',
			'size'  => 12,
			'show'  => true,
			'items' => array(
				array(
					'id'   => 'a1',
					'text' => 'Jedan',
				),
				array( 'text' => 'Dva' ),
			),
		);
		$new   = $this->walk( $value );

		$this->assertContains( array( 'Naslov', 'meta/x/title', 'meta:x||title' ), $this->leaves );
		$this->assertContains( array( 'Jedan', 'meta/x/items/#a1/text', 'meta:x||items/*/text' ), $this->leaves );
		$this->assertContains( array( 'Dva', 'meta/x/items/1/text', 'meta:x||items/*/text' ), $this->leaves );
		$this->assertSame( 12, $new['size'] );
		$this->assertTrue( $new['show'] );
		$this->assertSame( 'NASLOV', $new['title'] );
		$this->assertSame( 'DVA', $new['items'][1]['text'] );
	}

	/**
	 * The nearest element type names the rule, as builders name their widgets and blocks.
	 */
	public function test_walk_uses_element_types() {
		$this->walk(
			array(
				array(
					'widgetType' => 'heading',
					'settings'   => array( 'title' => 'Dobro došli' ),
				),
			)
		);
		$this->assertContains( array( 'Dobro došli', 'meta/x/0/settings/title', 'meta:x|heading|settings/title' ), $this->leaves );
	}

	/**
	 * Objects keep their shape.
	 */
	public function test_walk_keeps_objects() {
		$new = $this->walk( (object) array( 'title' => 'Naslov' ) );
		$this->assertInstanceOf( 'stdClass', $new );
		$this->assertSame( 'NASLOV', $new->title );
	}

	/**
	 * JSON inside a string is walked too, and the string comes back untouched when nothing changed.
	 */
	public function test_walk_json_in_a_string() {
		$json = '{"title":"Naslov","align":"center"}';
		$new  = $this->walk( $json );
		$this->assertContains( array( 'Naslov', 'meta/x/@json/title', 'meta:x||title' ), $this->leaves );
		$this->assertSame(
			array(
				'title' => 'NASLOV',
				'align' => 'CENTER',
			),
			json_decode( $new, true )
		);

		$same = swiftling_walk(
			$json,
			static function ( $leaf ) {
				return $leaf;
			},
			'meta/x',
			'meta:x'
		);
		$this->assertSame( $json, $same );
	}

	/**
	 * Text between shortcodes, their attributes and their content are leaves; the shortcode is written again only when something changed.
	 */
	public function test_walk_shortcodes() {
		add_shortcode( 'button', '__return_empty_string' );
		$new = $this->walk( 'Uvod [button label="Klikni ovdje"]Idi[/button] kraj [[button]]' );

		$this->assertSame(
			array(
				array( 'Uvod ', 'meta/x/@t0', 'meta:x|shortcode-text|' ),
				array( 'Klikni ovdje', 'meta/x/@sc0/label', 'meta:x|button|attr/label' ),
				array( 'Idi', 'meta/x/@sc0/content', 'meta:x|button|content' ),
				array( ' kraj ', 'meta/x/@t1', 'meta:x|shortcode-text|' ),
			),
			$this->leaves
		);
		$this->assertSame( 'UVOD [button label="KLIKNI OVDJE"]IDI[/button] KRAJ [[button]]', $new );
		remove_shortcode( 'button' );
	}

	/**
	 * An attribute whose name says it names what the shortcode has to find is no leaf; the
	 * attributes that hold text are.
	 */
	public function test_walk_shortcode_identifiers() {
		add_shortcode( 'form', '__return_empty_string' );
		$new = $this->walk( '[form id="208e7b7" form_id="12" css_class="wide" title="Contact form 1" label="Pošalji"]' );

		$this->assertSame(
			array(
				array( 'Contact form 1', 'meta/x/@sc0/title', 'meta:x|form|attr/title' ),
				array( 'Pošalji', 'meta/x/@sc0/label', 'meta:x|form|attr/label' ),
			),
			$this->leaves
		);
		$this->assertStringContainsString( 'id="208e7b7"', $new );
		$this->assertStringContainsString( 'form_id="12"', $new );
		$this->assertStringContainsString( 'css_class="wide"', $new );
		$this->assertStringContainsString( 'title="CONTACT FORM 1"', $new );
		$this->assertStringContainsString( 'label="POŠALJI"', $new );
		remove_shortcode( 'form' );
	}

	/**
	 * A shortcode the site does not register is just text.
	 */
	public function test_walk_unregistered_shortcode() {
		$this->walk( 'Tekst [nepoznato a="b"]' );
		$this->assertSame( array( array( 'Tekst [nepoznato a="b"]', 'meta/x', 'meta:x||' ) ), $this->leaves );
	}

	/**
	 * HTML without visible text has its text in alt, title and label attributes, and its addresses in src and href.
	 */
	public function test_walk_html_attributes() {
		$new = $this->walk( '<a href="/kontakt/"><img src="/slika.jpg" alt="Mačka na prozoru" class="wp-image-5"></a>' );

		$this->assertContains( array( '/kontakt/', 'meta/x/@href0', 'meta:x||@href' ), $this->leaves );
		$this->assertContains( array( '/slika.jpg', 'meta/x/@src1', 'meta:x||@src' ), $this->leaves );
		$this->assertContains( array( 'Mačka na prozoru', 'meta/x/@alt0', 'meta:x||@alt' ), $this->leaves );
		$this->assertStringContainsString( 'alt="MAČKA NA PROZORU"', $new );
		$this->assertStringContainsString( 'class="wp-image-5"', $new, 'Other attributes stay' );
	}

	/**
	 * The address of a script or a style is never a leaf: a marker would keep it from loading.
	 */
	public function test_walk_leaves_script_addresses_alone() {
		// phpcs:ignore WordPress.WP.EnqueuedResources -- Stored HTML the segmenter walks; nothing is printed.
		$html = '<script src="/app.js"></script><link href="/style.css" rel="stylesheet">';
		$this->assertSame( $html, $this->walk( $html ) );
		$this->assertSame( array(), $this->leaves );
	}

	/**
	 * Path segments escape the characters paths use, and list items with an id keep it.
	 */
	public function test_path_segments() {
		$this->assertSame( 'a%2Fb%25c', swiftling_escape_segment( 'a/b%c' ) );
		$this->assertSame( '#5', swiftling_list_segment( 0, array( 'id' => 5 ) ) );
		$this->assertSame( '#abc', swiftling_list_segment( 0, (object) array( '_id' => 'abc' ) ) );
		$this->assertSame( '3', swiftling_list_segment( 3, array( 'id' => '' ) ) );
		$this->assertSame( '3', swiftling_list_segment( 3, 'text' ) );
	}

	/**
	 * Element types are names, not sentences.
	 */
	public function test_element_type() {
		$this->assertSame( 'core/paragraph', swiftling_element_type( array( 'blockName' => 'core/paragraph' ) ) );
		$this->assertSame( 'heading', swiftling_element_type( array( 'widgetType' => 'heading' ) ) );
		$this->assertSame( '', swiftling_element_type( array( 'type' => 'Two words' ) ) );
		$this->assertSame( '', swiftling_element_type( array( 'name' => 5 ) ) );
	}

	/**
	 * A rule key that would not fit the table is shortened with a hash, and stays unique.
	 */
	public function test_long_rule_keys_fit_the_table() {
		$short = swiftling_rule_key( 'meta:x', 'heading', 'settings/title' );
		$this->assertSame( 'meta:x|heading|settings/title', $short );

		$long  = swiftling_rule_key( 'meta:x', 'heading', str_repeat( 'a/', 120 ) );
		$other = swiftling_rule_key( 'meta:x', 'heading', str_repeat( 'a/', 120 ) . 'b' );
		$this->assertLessThanOrEqual( 191, strlen( $long ) );
		$this->assertNotSame( $long, $other );
	}

	/**
	 * Visible text has no tags, entities or runs of whitespace.
	 */
	public function test_visible_text() {
		$this->assertSame( 'Dobro došli & hvala', swiftling_visible_text( "<p>Dobro   <b>došli</b></p>\n<script>x()</script> &amp; hvala" ) );
		$this->assertSame( md5( 'Kontakt' ), swiftling_hash( 'Kontakt' ) );
	}

	/**
	 * JSON stored as base64, as Brizy does, is walked, and written back as base64 only when a leaf changed.
	 */
	public function test_base64_json_is_walked() {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The format under test.
		$stored = base64_encode(
			wp_json_encode(
				array(
					'title' => 'Dobro došli kući',
					'items' => array( array( 'text' => 'Pozdrav svijetu' ) ),
				)
			)
		);

		$walked = $this->walk( $stored );
		$this->assertSame( array( 'Dobro došli kući', 'Pozdrav svijetu' ), array_column( $this->leaves, 0 ) );
		$this->assertSame( 'meta/x/@b64/title', $this->leaves[0][1] );
		$this->assertSame( 'DOBRO DOŠLI KUĆI', json_decode( base64_decode( $walked ), true )['title'] );

		// Nothing changed: the same string comes back, not a re-encoded one.
		$same = swiftling_walk(
			$stored,
			static function ( $leaf ) {
				return $leaf;
			},
			'meta/x',
			'meta:x'
		);
		$this->assertSame( $stored, $same );
		// phpcs:enable
	}

	/**
	 * A word or a code that is valid base64 but no JSON stays one leaf; so do short strings.
	 */
	public function test_base64_that_is_no_json_is_left_alone() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The format under test.
		$this->assertNull( swiftling_decode_base64_json( base64_encode( 'Just a sentence, not JSON.' ) ) );
		$this->assertNull( swiftling_decode_base64_json( 'Dobrodoslicaaaaa' ) );
		$this->assertNull( swiftling_decode_base64_json( 'e30=' ) );
		$this->assertNull( swiftling_decode_base64_json( 'Not base64 at all, with spaces' ) );
	}

	/**
	 * Brizy marks each text of the HTML it prints; each is a leaf, the rest of the section stays as it is.
	 */
	public function test_brizy_texts_are_leaves() {
		$html = '<section class="brz-section"><div class="brz-rich-text"><div data-brz-translate-text="1"><h2 class="brz-tp-heading2"><span class="brz-cp-color2">Dobro došli</span></h2></div></div>'
			. '<a href="#"><span data-brz-translate-text="1" class="brz-span brz-text__editor">Pročitaj više</span></a>'
			. '<div data-brz-translate-text="1"><div><p>Unutra <b>sadržaj</b></p></div><div>drugi</div></div>'
			. '<span data-brz-translate-text="1">&nbsp;</span></section>';

		$walked = $this->walk( $html );

		$this->assertSame(
			array(
				'<h2 class="brz-tp-heading2"><span class="brz-cp-color2">Dobro došli</span></h2>',
				'Pročitaj više',
				'<div><p>Unutra <b>sadržaj</b></p></div><div>drugi</div>',
			),
			array_column( $this->leaves, 0 )
		);
		$this->assertSame( array( 'meta/x/@bz0', 'meta/x/@bz1', 'meta/x/@bz2' ), array_column( $this->leaves, 1 ) );
		$this->assertStringStartsWith( '<section class="brz-section"><div class="brz-rich-text"><div data-brz-translate-text="1"><H2 CLASS="BRZ-TP-HEADING2">', $walked );
		$this->assertStringContainsString( '<a href="#"><span data-brz-translate-text="1" class="brz-span brz-text__editor">PROČITAJ VIŠE</span></a>', $walked );
		// A marked element with no visible text is left as it is.
		$this->assertStringEndsWith( '<span data-brz-translate-text="1">&nbsp;</span></section>', $walked );

		// Nothing changed: the same string comes back.
		$this->assertSame(
			$html,
			swiftling_walk(
				$html,
				static function ( $leaf ) {
					return $leaf;
				},
				'meta/x',
				'meta:x'
			)
		);
	}

	/**
	 * HTML without Brizy's mark is not split by it.
	 */
	public function test_html_without_brizy_mark_is_one_leaf() {
		$this->walk( '<div><p>Dobro došli</p><p>kući</p></div>' );
		$this->assertCount( 1, $this->leaves );
	}
}
