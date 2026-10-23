<?php
/**
 * Tests of the variables of SEO plugins in machine translation.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Translation\Services;
use WP_REST_Request;

/**
 * A text of an SEO plugin may hold the plugin's variables, as "%%title%% %%sep%% Kontakt": the
 * plugin's syntax, not words. Machine translation sends them as parts to keep, and a translation
 * that comes back with one changed is not filled in, as one with broken placeholders.
 */
class SeoVariablesTest extends TestCase {

	/**
	 * Texts of the post, by meta key: Yoast SEO's, Rank Math's, All in One SEO's, and a text with
	 * hashtags.
	 */
	const TEXTS = array(
		'_yoast_wpseo_title'    => '%%title%% %%sep%% Pišite nam %%sep%% %%sitename%%',
		'rank_math_description' => 'Kontakt: %title% %sep% %customfield(podnaslov)%, 100% besplatno i 20%-tni popust',
		'_aioseo_title'         => '#post_title #separator_sa Pišite nam #custom_field-podnaslov #Porec',
		'napomena'              => 'Ljeto u Poreču #Porec #ljeto',
	);

	/**
	 * The post whose page shows the texts.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Checks a page that shows the texts.
	 */
	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/stubs/aioseo.php';
		swiftling_test_aioseo_reset();
		$this->post = self::factory()->post->create_and_get();
		foreach ( self::TEXTS as $key => $text ) {
			update_post_meta( $this->post->ID, $key, $text );
		}
		$id = $this->post->ID;
		$this->check(
			'post:' . $id,
			static function () use ( $id ) {
				foreach ( array_keys( self::TEXTS ) as $key ) {
					get_post_meta( $id, $key, true );
				}
			}
		);
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->become( 'administrator' );
	}

	/**
	 * Forgets the stand-in for All in One SEO.
	 */
	public function tear_down() {
		unset( $GLOBALS['swiftling_test_aioseo'] );
		parent::tear_down();
	}

	/**
	 * Returns the place of a text.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	private function place( $key ) {
		return swiftling_place_key( 'post:' . $this->post->ID, swiftling_path_key( swiftling_meta_path( $key, 0 ) ), swiftling_hash( self::TEXTS[ $key ] ) );
	}

	/**
	 * Asks the translation service for the texts, as the editor does.
	 *
	 * @return array What the API answered.
	 */
	private function translate_texts() {
		$items = array();
		foreach ( self::TEXTS as $key => $text ) {
			$items[] = array(
				'key'  => $this->place( $key ),
				'text' => $text,
			);
		}
		$request = new WP_REST_Request( 'POST', '/swiftling/v1/machine' );
		$request->set_body_params(
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => $items,
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	/**
	 * Returns the parts each text went to the service with, to keep.
	 *
	 * @return array<string, string[]> By meta key.
	 */
	private function kept() {
		$kept = array();
		foreach ( array_keys( self::TEXTS ) as $key ) {
			$kept[ $key ] = FakeService::$sent[ $this->place( $key ) ]['keep'] ?? array();
		}
		return $kept;
	}

	/**
	 * The variables of Yoast SEO and SEOPress, of Rank Math, and All in One SEO's own tags go to the
	 * service as parts to keep; a number with a percent sign and a hashtag do not.
	 */
	public function test_variables_are_kept() {
		$this->translate_texts();
		$this->assertSame(
			array(
				'_yoast_wpseo_title'    => array( '%%title%%', '%%sep%%', '%%sitename%%' ),
				'rank_math_description' => array( '%title%', '%sep%', '%customfield(podnaslov)%' ),
				'_aioseo_title'         => array( '#post_title', '#separator_sa', '#custom_field-podnaslov' ),
				'napomena'              => array(),
			),
			$this->kept()
		);
	}

	/**
	 * Without All in One SEO, a word after # is a hashtag like any other.
	 */
	public function test_tags_without_aioseo() {
		aioseo()->tags = null;
		$this->translate_texts();
		$this->assertSame( array(), $this->kept()['_aioseo_title'] );
	}

	/**
	 * A translation that comes back with a variable changed is not filled in: the fake service
	 * writes everything in capitals, so "%%title%%" comes back as "%%TITLE%%". A text without
	 * variables is translated.
	 */
	public function test_changed_variables_are_not_filled_in() {
		$data = $this->translate_texts();
		$this->assertEqualsCanonicalizing( array( $this->place( '_yoast_wpseo_title' ), $this->place( 'rank_math_description' ), $this->place( '_aioseo_title' ) ), $data['failed'] );
		$this->assertSame( array( $this->place( 'napomena' ) => 'LJETO U POREČU #POREC #LJETO' ), (array) $data['translations'] );
		// The log says why, with a variable it changed.
		$warning = swiftling_log_entries( array( 'per_page' => 50 ) )['entries'][0];
		$this->assertSame( array( 'warning', 'service' ), array( $warning->level, $warning->area ) );
		$this->assertStringContainsString( '3 machine translations by Fake were not used: they changed variables of an SEO plugin, such as %%title%%', $warning->message );

		// One that keeps them is.
		add_filter(
			'swiftling_machine_translations',
			static function ( $translations ) {
				return array_map(
					static function ( $text ) {
						return str_replace( array( '%%TITLE%%', '%%SEP%%', '%%SITENAME%%' ), array( '%%title%%', '%%sep%%', '%%sitename%%' ), $text );
					},
					$translations
				);
			}
		);
		$data = $this->translate_texts();
		$this->assertSame( '%%title%% %%sep%% PIŠITE NAM %%sep%% %%sitename%%', ( (array) $data['translations'] )[ $this->place( '_yoast_wpseo_title' ) ] );
	}
}
