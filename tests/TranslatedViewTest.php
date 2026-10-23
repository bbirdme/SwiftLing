<?php
/**
 * Tests of the translated view, from the page check to the page in another language.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Every place where WordPress hands stored data over: what a page check finds there, and what a
 * page in another language shows once it is translated.
 */
class TranslatedViewTest extends TestCase {

	/**
	 * Makes a post.
	 *
	 * @param array $args Post fields.
	 * @return \WP_Post
	 */
	private function post( $args = array() ) {
		return self::factory()->post->create_and_get(
			$args + array(
				'post_title'   => 'Kontakt',
				'post_content' => 'Pišite nam bilo kada.',
				'post_excerpt' => '',
			)
		);
	}

	/**
	 * Renders the content of a post as a theme does.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function content( $post ) {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
		setup_postdata( $post );
		return apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
	}

	/**
	 * A title shows the translation of the page's language, the original elsewhere.
	 */
	public function test_title() {
		$post = $this->post();
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );

		$this->show_in( 'de' );
		$this->assertSame( 'Kontaktieren', get_the_title( $post ) );
		$this->show_in( 'fr' );
		$this->assertSame( 'Kontakt', get_the_title( $post ), 'No French translation yet' );
		$this->reset_state();
		$this->assertSame( 'Kontakt', get_the_title( $post ), 'The original language' );
	}

	/**
	 * A page that lists posts, such as the blog page, shows each post translated: its texts are
	 * handed over wherever WordPress renders them, not only on the post's own page. The excerpt
	 * counts both ways: written by hand, and built from the content.
	 */
	public function test_posts_in_a_list() {
		$written = $this->post( array( 'post_excerpt' => 'Kratko o nama.' ) );
		$built   = $this->post(
			array(
				'post_title'   => 'Novosti',
				'post_content' => 'Otvorili smo novu sezonu.',
			)
		);
		$this->check(
			'post:' . $written->ID,
			static function () use ( $written ) {
				get_the_title( $written );
				get_the_excerpt( $written );
			}
		);
		$this->reset_state();
		$this->check(
			'post:' . $built->ID,
			function () use ( $built ) {
				get_the_title( $built );
				$this->content( $built );
			}
		);
		$this->translate( 'post:' . $written->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );
		$this->translate( 'post:' . $written->ID, 'field/post_excerpt', 'Kratko o nama.', 'de', 'Kurz über uns.' );
		$this->translate( 'post:' . $built->ID, 'field/post_title', 'Novosti', 'de', 'Neuigkeiten' );
		$this->translate( 'post:' . $built->ID, 'field/post_content', 'Otvorili smo novu sezonu.', 'de', 'Wir haben die neue Saison eröffnet.' );

		$this->show_in( 'de' );
		$query    = new \WP_Query(
			array(
				'post__in' => array( $written->ID, $built->ID ),
				'orderby'  => 'post__in',
			)
		);
		$titles   = array();
		$excerpts = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$titles[]   = get_the_title();
			$excerpts[] = trim( wp_strip_all_tags( get_the_excerpt() ) );
		}
		wp_reset_postdata();

		$this->assertSame( array( 'Kontaktieren', 'Neuigkeiten' ), $titles );
		$this->assertSame( 'Kurz über uns.', $excerpts[0], 'An excerpt written by hand' );
		$this->assertSame( 'Wir haben die neue Saison eröffnet.', $excerpts[1], 'An excerpt built from the content' );
	}

	/**
	 * An excerpt built from content with blocks shows the translation too, as a list of posts does
	 * it, such as the blog page in the language. WordPress renders the blocks of an excerpt before
	 * the content's own filter, and leaves out some, such as an image, so each block takes its place
	 * in the post from where it stands there, not from how many rendered before it.
	 */
	public function test_built_excerpt_of_blocks() {
		$post   = $this->post(
			array(
				'post_title'   => 'Stevie',
				'post_content' => "<!-- wp:paragraph -->\n<p>Prvi odlomak.</p>\n<!-- /wp:paragraph -->\n\n"
					. "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.org/a.jpg\" alt=\"\"/></figure>\n<!-- /wp:image -->\n\n"
					. "<!-- wp:paragraph -->\n<p>Drugi odlomak.</p>\n<!-- /wp:paragraph -->\n\n"
					. "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Odlomak u grupi.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->",
			)
		);
		$owner  = 'post:' . $post->ID;
		$leaves = $this->probe(
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		$this->check(
			$owner,
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		$words = array(
			'Prvi odlomak.'    => 'Erster Absatz.',
			'Drugi odlomak.'   => 'Zweiter Absatz.',
			'Odlomak u grupi.' => 'Absatz in der Gruppe.',
		);
		foreach ( $leaves as $leaf ) {
			foreach ( $words as $original => $translation ) {
				if ( $owner === $leaf['o'] && false !== strpos( $leaf['v'], $original ) ) {
					$this->translate( $owner, $leaf['p'], $leaf['v'], 'de', str_replace( $original, $translation, $leaf['v'] ) );
				}
			}
		}

		$this->show_in( 'de' );
		$this->assertStringContainsString( 'Zweiter Absatz.', $this->content( $post ), 'The post\'s own page' );
		wp_reset_postdata();

		$query = new \WP_Query( array( 'p' => $post->ID ) );
		$query->the_post();
		$excerpt = trim( wp_strip_all_tags( get_the_excerpt() ) );
		wp_reset_postdata();

		$this->assertSame( 'Erster Absatz. Zweiter Absatz. Absatz in der Gruppe.', preg_replace( '/\s+/', ' ', $excerpt ) );
	}

	/**
	 * Classic content without blocks is one text.
	 */
	public function test_classic_content() {
		$post = $this->post();
		$this->check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_content', 'Pišite nam bilo kada.', 'de', 'Schreiben Sie uns jederzeit.' );

		$this->show_in( 'de' );
		$this->assertStringContainsString( 'Schreiben Sie uns jederzeit.', $this->content( $post ) );
		$this->assertStringNotContainsString( 'Pišite', $this->content( $post ) );
	}

	/**
	 * Classic content is walked once, as the post's own text. WordPress runs it through do_blocks()
	 * as one freeform block after SwiftLing took it whole; that block is the same content and not a
	 * setting of the site. A setting a shortcode prints in an attribute, as a builder's button prints
	 * its hover effect in data-hover, is then no text of anything, and a translation kept for it under
	 * the site's settings, from when the block was walked a second time, does not reach the page.
	 */
	public function test_classic_content_is_walked_once() {
		add_shortcode(
			'swiftling_test_button',
			static function ( $atts, $content = '' ) {
				return '<a class="button" data-hover="' . esc_attr( (string) $atts['hover_transition'] ) . '">' . $content . '</a>';
			}
		);
		$post   = $this->post( array( 'post_content' => '[swiftling_test_button hover_transition="none"]Pošaljite upit[/swiftling_test_button]' ) );
		$leaves = $this->probe(
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		$values = array();
		foreach ( $leaves as $leaf ) {
			$this->assertNotSame( 'options', $leaf['o'], $leaf['v'] . ' is of the post, not a setting of the site' );
			if ( 'post:' . $post->ID === $leaf['o'] ) {
				$values[] = $leaf['v'];
			}
		}
		$this->assertContains( 'Pošaljite upit', $values );

		// What a second walk left under the site's settings: the setting taken for a text, translated.
		$place = 'b/0/c0/@sc0/hover_transition';
		swiftling_save_map( 'options', array( swiftling_path_key( $place ) => swiftling_hash( 'none' ) ), array( 'b' ), array(), time() );
		$this->translate( 'options', $place, 'none', 'de', 'keiner' );
		$this->show_in( 'de' );
		$this->assertStringContainsString( 'data-hover="none"', $this->content( $post ) );
		remove_shortcode( 'swiftling_test_button' );
	}

	/**
	 * Each block is translated where it renders, its HTML and its attributes, in a dynamic block too.
	 */
	public function test_blocks() {
		register_block_type(
			'swiftling-test/greeting',
			array(
				'attributes'      => array( 'greeting' => array( 'type' => 'string' ) ),
				'render_callback' => static function ( $attributes ) {
					return '<div class="greeting">' . esc_html( $attributes['greeting'] ) . '</div>';
				},
			)
		);
		$post   = $this->post(
			array(
				'post_content' => "<!-- wp:paragraph -->\n<p>Dobro došli na našu stranicu.</p>\n<!-- /wp:paragraph -->\n\n"
					. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">O nama</h2>\n<!-- /wp:heading -->\n\n"
					. '<!-- wp:swiftling-test/greeting {"greeting":"Dobar dan"} /-->',
			)
		);
		$result = $this->check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		foreach ( array(
			'Dobro došli' => 'Willkommen auf unserer Seite.',
			'O nama'      => 'Über uns',
			'Dobar dan'   => 'Guten Tag',
		) as $needle => $german ) {
			$unit = $this->found( $result, $needle );
			$this->translate( 'post:' . $post->ID, $unit['p'], $unit['v'], 'de', str_replace( $unit['v'] === $needle ? $needle : swiftling_visible_text( $unit['v'] ), $german, $unit['v'] ) );
		}

		$this->show_in( 'de' );
		$html = $this->content( $post );
		$this->assertStringContainsString( '>Willkommen auf unserer Seite.</p>', $html );
		$this->assertStringNotContainsString( 'Dobro', $html );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Über uns</h2>', $html );
		$this->assertStringContainsString( '<div class="greeting">Guten Tag</div>', $html );
		unregister_block_type( 'swiftling-test/greeting' );
	}

	/**
	 * A shortcode gets the attributes that name what it shows whole, so it still finds them while
	 * the page is checked: a plugin looks its form up by the id in the shortcode. Its attributes
	 * that hold text are found and translated all the same.
	 */
	public function test_shortcode_identifiers_reach_the_shortcode() {
		$seen = array();
		add_shortcode(
			'swiftling_test_form',
			static function ( $atts ) use ( &$seen ) {
				$seen = (array) $atts;
				return '<div class="form">' . esc_html( (string) $atts['heading'] ) . '</div>';
			}
		);
		$post   = $this->post(
			array(
				'post_content' => "<!-- wp:shortcode -->\n[swiftling_test_form id=\"208e7b7\" title=\"Contact form 1\" heading=\"Pišite nam\"]\n<!-- /wp:shortcode -->",
			)
		);
		$result = $this->check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);

		$this->assertSame( '208e7b7', $seen['id'], 'The id of the form reaches the shortcode whole' );
		$this->assertTrue( swiftling_has_marker( $seen['title'] ), 'A title is text, and is marked' );
		$this->assertSame( 'Contact form 1', $this->found( $result, 'Contact form 1' )['v'] );

		$unit = $this->found( $result, 'Pišite nam' );
		$this->translate( 'post:' . $post->ID, $unit['p'], $unit['v'], 'de', 'Schreiben Sie uns' );
		$this->show_in( 'de' );
		$html = $this->content( $post );
		$this->assertStringContainsString( '<div class="form">Schreiben Sie uns</div>', $html );
		$this->assertSame( '208e7b7', $seen['id'], 'And in the language too' );
		remove_shortcode( 'swiftling_test_form' );
	}

	/**
	 * A builder's JSON keeps its structure: only the text inside it changes.
	 */
	public function test_builder_json_in_meta() {
		$post = $this->post();
		$data = array(
			array(
				'id'         => 'a1',
				'widgetType' => 'heading',
				'settings'   => array(
					'title' => 'Naslov stranice',
					'size'  => 'h2',
				),
			),
		);
		add_post_meta( $post->ID, '_builder_data', wp_slash( wp_json_encode( $data ) ) );

		$result = $this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_post_meta( $post->ID, '_builder_data', true );
			}
		);
		$unit   = $this->found( $result, 'Naslov stranice' );
		$this->assertSame( 'meta/_builder_data/@json/#a1/settings/title', $unit['p'] );
		$this->assertSame( 'meta:_builder_data|heading|settings/title', $unit['r'] );
		$this->translate( 'post:' . $post->ID, $unit['p'], 'Naslov stranice', 'de', 'Seitentitel' );

		$this->show_in( 'de' );
		$data[0]['settings']['title'] = 'Seitentitel';
		$this->assertSame( $data, json_decode( get_post_meta( $post->ID, '_builder_data', true ), true ) );
	}

	/**
	 * A serialized array and a key with several values are translated value by value.
	 */
	public function test_serialized_and_repeated_meta() {
		$post = $this->post();
		add_post_meta(
			$post->ID,
			'hero',
			array(
				'heading' => 'Veliki naslov',
				'align'   => 'center',
			)
		);
		add_post_meta( $post->ID, 'faq', 'Prvo pitanje' );
		add_post_meta( $post->ID, 'faq', 'Drugo pitanje' );

		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_post_meta( $post->ID, 'hero', true );
				get_post_meta( $post->ID, 'faq', false );
			}
		);
		$this->translate( 'post:' . $post->ID, 'meta/hero/heading', 'Veliki naslov', 'de', 'Große Überschrift' );
		$this->translate( 'post:' . $post->ID, 'meta/faq/@1', 'Drugo pitanje', 'de', 'Zweite Frage' );

		$this->show_in( 'de' );
		$this->assertSame(
			array(
				'heading' => 'Große Überschrift',
				'align'   => 'center',
			),
			get_post_meta( $post->ID, 'hero', true )
		);
		$this->assertSame( array( 'Prvo pitanje', 'Zweite Frage' ), get_post_meta( $post->ID, 'faq', false ) );
	}

	/**
	 * Bookkeeping and technical meta are never read as text.
	 */
	public function test_technical_meta_is_left_alone() {
		$post = $this->post();
		foreach ( array( '_edit_lock', '_thumbnail_id', '_swiftling_copy_of', 'custom_css', 'page_cache', 'api_token' ) as $key ) {
			add_post_meta( $post->ID, $key, 'Looks Like Text' );
		}
		$leaves = $this->probe(
			static function () use ( $post ) {
				foreach ( array( '_edit_lock', '_thumbnail_id', '_swiftling_copy_of', 'custom_css', 'page_cache', 'api_token' ) as $key ) {
					get_post_meta( $post->ID, $key, true );
				}
			}
		);
		$this->assertSame( array(), $leaves );
	}

	/**
	 * Options read while the page renders are shared texts: translated once, on every page.
	 */
	public function test_site_title_is_shared() {
		update_option( 'blogname', 'Moja stranica' );
		$post  = $this->post();
		$other = $this->post( array( 'post_title' => 'Drugi post' ) );
		$this->check(
			'post:' . $post->ID,
			static function () {
				get_option( 'blogname' );
				get_option( 'date_format' );
			}
		);
		$this->translate( 'options', 'option/blogname', 'Moja stranica', 'de', 'Meine Seite' );

		$this->show_in( 'de' );
		$this->assertSame( 'Meine Seite', get_option( 'blogname' ) );
		$this->assertSame( 'Meine Seite', get_bloginfo( 'name' ) );
		$this->assertSame( get_option( 'date_format' ), get_option( 'date_format' ), 'Settings stay' );
		$this->assertSame( 'Drugi post', get_the_title( $other ), 'A page never checked keeps its own texts' );
	}

	/**
	 * Options read while WordPress starts up are settings, not text, and are not marked.
	 */
	public function test_options_before_the_page_renders_are_not_marked() {
		$this->reset_state();
		swiftling_start( 'probe' );
		update_option( 'swiftling_test_option', 'Neki tekst' );
		$this->assertSame( 'Neki tekst', get_option( 'swiftling_test_option' ) );
		$state = &swiftling_state();
		$this->assertSame( array(), $state['registry'] );
	}

	/**
	 * Text from the code of a theme is translated once for every page, by the text in the code.
	 */
	public function test_text_from_code() {
		$post = $this->post();
		$path = swiftling_string_path( array( 't' => 'Read more' ) );
		$this->check(
			'post:' . $post->ID,
			static function () {
				__( 'Read more', 'acme-theme' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- A theme's text.
			},
			array( $path => 'text' )
		);
		$this->translate( 'gettext:acme-theme', $path, 'Read more', 'de', 'Weiterlesen' );

		$this->show_in( 'de' );
		$this->assertSame( 'Weiterlesen', __( 'Read more', 'acme-theme' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- A theme's text.
		$this->assertSame( 'Read less', __( 'Read less', 'acme-theme' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- A theme's text.
		$this->assertSame( 'Settings', __( 'Settings', 'swiftling' ), 'SwiftLing leaves its own texts alone' );
	}

	/**
	 * A text with plural forms is one place per form the page showed.
	 */
	public function test_plural_forms_have_their_own_places() {
		$one   = swiftling_string_path(
			array(
				't' => '%d comment',
				'p' => '%d comments',
				'n' => 1,
			)
		);
		$other = swiftling_string_path(
			array(
				't' => '%d comment',
				'p' => '%d comments',
				'n' => 5,
			)
		);
		$this->assertNotSame( $one, $other );
		$this->assertSame(
			$other,
			swiftling_string_path(
				array(
					't' => '%d comment',
					'p' => '%d comments',
					'n' => 2,
				)
			),
			'Every number but one shows the same form'
		);
		$this->assertNotSame(
			swiftling_string_path( array( 't' => 'Post' ) ),
			swiftling_string_path(
				array(
					't' => 'Post',
					'c' => 'verb',
				)
			)
		);
	}

	/**
	 * The name and description of a category are its texts, wherever the term is read.
	 */
	public function test_terms() {
		$term_id = self::factory()->category->create(
			array(
				'name'        => 'Vijesti',
				'description' => 'Najnovije vijesti',
			)
		);
		$post    = $this->post();
		$this->check(
			'post:' . $post->ID,
			static function () use ( $term_id ) {
				get_term( $term_id );
			}
		);
		$this->translate( 'term:' . $term_id, 'field/name', 'Vijesti', 'de', 'Nachrichten' );

		$this->show_in( 'de' );
		$term = get_term( $term_id );
		$this->assertSame( 'Nachrichten', $term->name );
		$this->assertSame( 'Najnovije vijesti', $term->description, 'Not translated yet' );
		$this->reset_state();
		$this->assertSame( 'Vijesti', get_term( $term_id )->name, 'The cached term is untouched' );
	}

	/**
	 * A text that changed after it was translated shows its new original, and the page says it is outdated.
	 */
	public function test_changed_original() {
		$post   = $this->post();
		$render = static function () use ( $post ) {
			get_the_title( $post->ID );
		};
		$this->check( 'post:' . $post->ID, $render );
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );
		$this->assertSame( 'reviewed', swiftling_language_states( $post->ID )['de'] );

		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => 'Kontakti',
			)
		);
		$this->show_in( 'de' );
		$this->assertSame( 'Kontakti', get_the_title( $post->ID ), 'The old translation is not shown for the new text' );

		$this->check( 'post:' . $post->ID, $render );
		$this->assertSame( 'outdated', swiftling_language_states( $post->ID )['de'] );
		$this->assertSame( 'missing', swiftling_language_states( $post->ID )['fr'] );

		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakti', 'de', 'Kontakte' );
		$this->assertSame( 'reviewed', swiftling_language_states( $post->ID )['de'] );
		$this->show_in( 'de' );
		$this->assertSame( 'Kontakte', get_the_title( $post->ID ) );
	}

	/**
	 * A machine translation shows like any other; WordPress's own translation of a code text is
	 * only noted, and the page shows WordPress's files instead.
	 */
	public function test_statuses() {
		$post = $this->post();
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontakt (machine)', SWIFTLING_MACHINE );
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'fr', 'Contact (official)', SWIFTLING_OFFICIAL );

		$this->show_in( 'de' );
		$this->assertSame( 'Kontakt (machine)', get_the_title( $post ) );
		$this->assertSame( 'machine', swiftling_language_states( $post->ID )['de'] );
		$this->show_in( 'fr' );
		$this->assertSame( 'Kontakt', get_the_title( $post ) );
	}

	/**
	 * A post that is a duplicate in a language shows its original texts there, while its translations stay stored.
	 */
	public function test_duplicate_shows_the_original() {
		$this->become( 'administrator' );
		$post = $this->post();
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );
		$this->assertSame( 'duplicate', swiftling_set_language_mode( $post, 'de', 'duplicate' ) );

		$this->show_in( 'de' );
		$this->assertSame( 'Kontakt', get_the_title( $post ) );
		$this->assertSame( 'duplicate', swiftling_language_states( $post->ID )['de'] );

		$this->assertSame( 'translation', swiftling_set_language_mode( $post, 'de', 'translation' ) );
		$this->show_in( 'de' );
		$this->assertSame( 'Kontaktieren', get_the_title( $post ), 'The translation comes back' );
	}

	/**
	 * Links in a translated text lead to pages of its language.
	 */
	public function test_links_in_translations_follow_the_language() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$post = $this->post( array( 'post_content' => '<a href="/kontakt/">Pišite nam</a> ili nazovite.' ) );
		$this->check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_content', $post->post_content, 'de', '<a href="/kontakt/">Schreiben Sie uns</a> oder rufen Sie an.' );

		$this->show_in( 'de' );
		$this->assertStringContainsString( '<a href="/de/kontakt/">Schreiben Sie uns</a>', $this->content( $post ) );
	}

	/**
	 * An image can show another file in another language.
	 */
	public function test_image_per_language() {
		$image = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/kava.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$post  = $this->post();
		$url   = wp_get_attachment_url( $image );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $image ) {
				wp_get_attachment_url( $image );
			},
			array( 'file' => 'image' )
		);
		$this->translate_address( 'post:' . $image, 'file', $url, 'de', 'http://example.org/wp-content/uploads/2026/10/kaffee.jpg' );

		$this->show_in( 'de' );
		$this->assertSame( 'http://example.org/wp-content/uploads/2026/10/kaffee.jpg', wp_get_attachment_url( $image ) );
		$this->show_in( 'fr' );
		$this->assertSame( $url, wp_get_attachment_url( $image ) );
	}

	/**
	 * A revision belongs to the post it was made from.
	 */
	public function test_revisions_belong_to_their_post() {
		$post = $this->post();
		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => 'Kontakt 2',
			)
		);
		$revisions = wp_get_post_revisions( $post->ID );
		$this->assertNotEmpty( $revisions );
		$this->assertSame( 'post:' . $post->ID, swiftling_post_owner( reset( $revisions )->ID ) );
	}

	/**
	 * Values can be collected outside a page, as for a settings page, without changing the request's mode.
	 */
	public function test_collect() {
		update_option( 'acme_footer_text', 'Tekst postavki' );
		$this->show_in( 'de' );
		$leaves = swiftling_collect(
			static function () {
				get_option( 'acme_footer_text' );
			}
		);
		$this->assertSame( array( 'options', 'option/acme_footer_text', 'Tekst postavki' ), array( $leaves[1]['o'], $leaves[1]['p'], $leaves[1]['v'] ) );
		$this->assertSame( 'translate', swiftling_mode() );
		$this->assertSame( 'Tekst postavki', get_option( 'acme_footer_text' ), 'Values come back without markers' );
	}
}
