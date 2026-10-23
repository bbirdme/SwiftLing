<?php
/**
 * Tests of the languages and content in a language through the REST API.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WP_REST_Request;

/**
 * A site or app that shows the content elsewhere reads the languages visitors see, each with its
 * address; a post or term says the language of the answer and its address and state in each
 * language; a request in a language answers with the translations.
 */
class RestLanguagesTest extends TestCase {

	/**
	 * German is visible, French hidden from visitors.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => array(
					'slug'    => 'fr',
					'visible' => false,
				),
			)
		);
		do_action( 'rest_api_init', rest_get_server() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own action, so the fields of types registered by now are there.
	}

	/**
	 * Sends a GET request to a route.
	 *
	 * @param string $route Route.
	 * @return \WP_REST_Response
	 */
	private function get( $route ) {
		return rest_do_request( new WP_REST_Request( 'GET', $route ) );
	}

	/**
	 * The route of the languages lists the original and the languages visitors see, with what a
	 * switcher needs; a hidden language is not among them. Anyone may read it.
	 */
	public function test_languages() {
		$response = $this->get( '/swiftling/v1/languages' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'hr', 'de-DE' ), wp_list_pluck( $data, 'code' ) );
		$this->assertTrue( $data[0]['original'] );
		$this->assertTrue( $data[0]['current'] );
		$this->assertSame( 'Deutsch', $data[1]['name'] );
		$this->assertSame( 'ltr', $data[1]['direction'] );
		$this->assertStringContainsString( 'lang=de', $data[1]['url'] );
	}

	/**
	 * A post says the language of the answer and, for each language visitors see, its address and
	 * the state of its translation.
	 */
	public function test_post_field() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_content' => '',
				'post_excerpt' => '',
			)
		);
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktseite' );

		$field = $this->get( '/wp/v2/posts/' . $post->ID )->get_data()['swiftling'];
		$this->assertSame( 'hr', $field['language'] );
		$this->assertSame( array( 'hr', 'de-DE' ), wp_list_pluck( $field['translations'], 'code' ) );
		$this->assertSame( array( 'original', 'reviewed' ), wp_list_pluck( $field['translations'], 'state' ) );
		$this->assertStringContainsString( 'lang=de', $field['translations'][1]['url'] );

		$category = $this->get( '/wp/v2/categories/' . get_option( 'default_category' ) )->get_data()['swiftling'];
		$this->assertSame( array( 'hr', 'de-DE' ), wp_list_pluck( $category['translations'], 'code' ) );
	}

	/**
	 * A request in a language answers with the translations: the title in German, and the field
	 * names the answer's language.
	 */
	public function test_content_in_a_language() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Kontakt' ) );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktseite' );

		$this->show_in( 'de' );
		$data = $this->get( '/wp/v2/posts/' . $post->ID )->get_data();
		$this->assertSame( 'Kontaktseite', $data['title']['rendered'] );
		$this->assertSame( 'de-DE', $data['swiftling']['language'] );
	}
}
