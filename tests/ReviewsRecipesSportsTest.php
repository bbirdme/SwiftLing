<?php
/**
 * Tests of the classes of Site Reviews, WP Recipe Maker and SportsPress.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/review-objects.php';
require_once __DIR__ . '/stubs/FakeSiteReviews.php';
require_once __DIR__ . '/stubs/FakeWpRecipeMaker.php';
require_once __DIR__ . '/stubs/FakeSportsPress.php';

/**
 * What SwiftLing translates of Site Reviews, WP Recipe Maker and SportsPress: the texts the plugins
 * print from their own objects, past get_the_title() and the_content, and the labels SportsPress prints
 * straight from their posts.
 */
class ReviewsRecipesSportsTest extends TestCase {

	/**
	 * German, and the fakes in the registry.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$fakes    = array(
			'site-reviews'    => new FakeSiteReviews(),
			'wp-recipe-maker' => new FakeWpRecipeMaker(),
			'sportspress'     => new FakeSportsPress(),
		);
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $fakes[ $id ] ?? $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
	}

	/**
	 * Gives the registry back its own builders.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Site Reviews: a review's title and text, which the plugin prints from its own object, are those of
	 * the review's post, translated once; a value the post does not hold, and the reviewer's name, stay;
	 * the plugin's form sends to its REST API in the language of the page.
	 */
	public function test_site_reviews() {
		$reviews = Registry::get( 'site-reviews' );
		register_post_type( 'site-review' );
		$id     = self::factory()->post->create(
			array(
				'post_type'    => 'site-review',
				'post_title'   => 'Lijep vrt',
				'post_content' => 'Metvica je bila svježa.',
			)
		);
		$review = static function () use ( $id ) {
			return new StandInReview(
				array(
					'ID'      => $id,
					'title'   => 'Lijep vrt',
					'content' => 'Metvica je bila svježa.',
					'author'  => 'Ana',
				)
			);
		};
		$this->check(
			'post:' . self::factory()->post->create( array( 'post_type' => 'page' ) ),
			static function () use ( $reviews, $review ) {
				$reviews->translateReview( $review() );
			}
		);
		$this->translate( 'post:' . $id, 'field/post_title', 'Lijep vrt', 'de', 'Schöner Garten' );
		$this->translate( 'post:' . $id, 'field/post_content', 'Metvica je bila svježa.', 'de', 'Die Minze war frisch.' );

		$this->show_in( 'de' );
		$shown = $review();
		$reviews->translateReview( $shown );
		$this->assertSame( array( 'Schöner Garten', 'Die Minze war frisch.', 'Ana' ), array( $shown->title, $shown->content, $shown->author ) );
		$other = new StandInReview(
			array(
				'ID'      => $id,
				'title'   => 'Drugi naslov',
				'content' => '',
			)
		);
		$reviews->translateReview( $other );
		$this->assertSame( 'Drugi naslov', $other->title, 'A value the post does not hold' );

		$this->reset_state();
		$shown = $review();
		$reviews->translateReview( $shown );
		$this->assertSame( 'Lijep vrt', $shown->title );
		$this->assertSame( 1, preg_match( $reviews->sendingRoutes()[0], '/site-reviews/v1/submissions' ) );
		unregister_post_type( 'site-review' );
	}

	/**
	 * WP Recipe Maker: the recipe's name and summary, which the plugin prints from its post, are
	 * translated where it hands them out; the units, names and notes of ingredients are text, also one
	 * word, and an amount is none.
	 */
	public function test_wp_recipe_maker() {
		$wprm = Registry::get( 'wp-recipe-maker' );
		register_post_type( 'wprm_recipe' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'wprm_recipe',
				'post_title'   => 'Čaj od metvice',
				'post_content' => 'Gotov za pet minuta.',
			)
		);
		$this->check(
			'post:' . self::factory()->post->create(),
			static function () use ( $wprm, $id ) {
				$recipe = new StandInRecipe( $id );
				$wprm->translateField( 'Čaj od metvice', 'name', $recipe );
				$wprm->translateField( 'Gotov za pet minuta.', 'summary', $recipe );
			}
		);
		$this->translate( 'post:' . $id, 'field/post_title', 'Čaj od metvice', 'de', 'Minztee' );
		$this->translate( 'post:' . $id, 'field/post_content', 'Gotov za pet minuta.', 'de', 'In fünf Minuten fertig.' );
		$this->assertTrue( $wprm->fieldHoldsText( 'post:' . $id, 'meta/wprm_ingredients/0/ingredients/2/unit' ) );
		$this->assertTrue( $wprm->fieldHoldsText( 'post:' . $id, 'meta/wprm_ingredients/0/ingredients/2/name' ) );
		$this->assertTrue( $wprm->fieldHoldsText( 'post:' . $id, 'meta/wprm_servings_unit' ) );
		$this->assertFalse( $wprm->fieldHoldsText( 'post:' . $id, 'meta/wprm_ingredients/0/ingredients/2/amount' ) );
		$this->assertNull( $wprm->fieldHoldsText( 'post:' . self::factory()->post->create(), 'meta/wprm_ingredients/0/ingredients/2/unit' ), 'Another post\'s meta' );
		$this->assertSame( 'Ingredient › Unit', $wprm->fieldLabel( 'post:' . $id, 'meta/wprm_ingredients/0/ingredients/2/unit' ) );

		$this->show_in( 'de' );
		$recipe = new StandInRecipe( $id );
		$this->assertSame( 'Minztee', $wprm->translateField( 'Čaj od metvice', 'name', $recipe ) );
		$this->assertSame( 'In fünf Minuten fertig.', $wprm->translateField( 'Gotov za pet minuta.', 'summary', $recipe ) );
		$this->assertSame( 'Drugi', $wprm->translateField( 'Drugi', 'name', $recipe ), 'A value the post does not hold' );
		$leaves = $this->probe(
			static function () use ( $wprm, $id ) {
				$wprm->translateField( 'Drugi', 'name', new StandInRecipe( $id ) );
			}
		);
		$this->assertSame( array(), wp_list_pluck( $leaves, 'v' ), 'Nor offered as the title' );
		$this->reset_state();
		$this->assertSame( 'Čaj od metvice', $wprm->translateField( 'Čaj od metvice', 'name', $recipe ) );
		unregister_post_type( 'wprm_recipe' );
	}

	/**
	 * SportsPress: the labels of its sport are offered on the Templates screen and put in the language
	 * wherever a template of the plugin prints one whole, also in a template inside another; a word inside
	 * a longer text, and a page in the original, stay.
	 */
	public function test_sportspress() {
		$sports = Registry::get( 'sportspress' );
		register_post_type( 'sp_outcome' );
		register_post_type( 'sp_column' );
		self::factory()->post->create(
			array(
				'post_type'  => 'sp_outcome',
				'post_title' => 'Pobjeda',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'  => 'sp_column',
				'post_title' => 'Bod',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'  => 'sp_column',
				'post_title' => 'Pts',
			)
		);
		$this->assertSame( array( 'sportspress-labels' ), wp_list_pluck( Registry::subjects()['SportsPress'][0]['subjects'], 'key' ) );
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'sportspress-labels' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Pobjeda', 'Bod', 'Pts' ), array_keys( $units ), 'Also a label of a few letters' );
		$this->assertSame( 'SportsPress', Registry::fieldSource( $units['Pts']['o'], $units['Pts']['p'] ), 'The editor names where a label comes from' );
		$this->translate( $units['Pobjeda']['o'], $units['Pobjeda']['p'], 'Pobjeda', 'de', 'Sieg' );
		$this->translate( $units['Bod']['o'], $units['Bod']['p'], 'Bod', 'de', 'Punkt' );
		$this->translate( $units['Pts']['o'], $units['Pts']['p'], 'Pts', 'de', 'Pkt' );

		$printed = function () use ( $sports ): string {
			ob_start();
			$level = ob_get_level();
			$sports->startTemplate();
			echo '<th class="data-pts">Bod</th>';
			$sports->startTemplate();
			echo '<td>Pobjeda</td><p>Pobjeda je bila lijepa.</p>';
			$sports->endTemplate();
			echo '<th title="Pts">Pts</th>';
			$sports->endTemplate();
			$this->assertSame( $level, ob_get_level(), 'A template inside another closes no buffer of its own' );
			return (string) ob_get_clean();
		};
		$this->show_in( 'de' );
		$this->assertSame( '<th class="data-pts">Punkt</th><td>Sieg</td><p>Pobjeda je bila lijepa.</p><th title="Pkt">Pkt</th>', $printed() );
		$this->reset_state();
		$this->assertSame( '<th class="data-pts">Bod</th><td>Pobjeda</td><p>Pobjeda je bila lijepa.</p><th title="Pts">Pts</th>', $printed() );
		unregister_post_type( 'sp_outcome' );
		unregister_post_type( 'sp_column' );
	}
}
