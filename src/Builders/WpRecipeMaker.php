<?php
/**
 * WP Recipe Maker: what of a recipe SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * WP Recipe Maker. A recipe is a post of the type "wprm_recipe" that a post shows with a block or a
 * shortcode; its ingredients, instructions and equipment are its meta, read through get_post_meta(),
 * and its courses and cuisines terms: SwiftLing finds them there, as texts of the recipe, once for every
 * post that shows it. What it does not find by itself:
 *
 * - The plugin prints the recipe's name and summary from the recipe's post itself, past get_the_title()
 *   and the_content, and hands each to its own filter: translated there, see translateField().
 * - A unit, as "tsp", or an ingredient of one word, as "honey", looks like a setting to SwiftLing: the
 *   texts of the ingredients, instructions and equipment, and the unit of the servings, are declared as
 *   text, see fieldHoldsText(). An amount stays as it is.
 *
 * Checked against the code of WP Recipe Maker 10.9 (the free plugin), in tests/ReviewsRecipesSportsTest.php,
 * and by hand on a site with the plugin. WP Recipe Maker Premium was not checked.
 */
class WpRecipeMaker extends Builder {
	/**
	 * The plugin's type of post.
	 */
	const POST_TYPE = 'wprm_recipe';

	/**
	 * The texts of a recipe's meta, by meta key: the paths inside it that hold text.
	 */
	const TEXTS = array(
		'wprm_ingredients'   => '#^\d+/(name|ingredients/\d+/(unit|name|notes))$#',
		'wprm_instructions'  => '#^\d+/(name|instructions/\d+/(name|text))$#',
		'wprm_equipment'     => '#^\d+/(name|notes)$#',
		'wprm_servings_unit' => '#^$#',
	);

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wp-recipe-maker';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'WP Recipe Maker';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'WPRM_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin: a recipe needs nothing of a builder.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A recipe needs nothing of a builder.
		return false;
	}

	/**
	 * Hooks where the plugin hands out a field of a recipe.
	 */
	public function register(): void {
		add_filter( 'wprm_recipe_field', array( $this, 'translateField' ), 10, 3 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
	}

	/**
	 * Returns a recipe's name or summary in the mode of the moment, when it is what the recipe's post
	 * holds.
	 *
	 * @param mixed $value  The field.
	 * @param mixed $field  Which field.
	 * @param mixed $recipe The plugin's object of the recipe.
	 * @return mixed
	 */
	public function translateField( $value, $field = '', $recipe = null ) {
		if ( ! is_string( $value ) || '' === trim( $value ) || ! in_array( $field, array( 'name', 'summary' ), true ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $value;
		}
		$id   = is_object( $recipe ) && method_exists( $recipe, 'id' ) ? (int) $recipe->id() : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $value;
		}
		if ( 'name' === $field ) {
			return $value === $post->post_title ? (string) swiftling_filter_title( $value, $post->ID ) : $value;
		}
		return $value === $post->post_content ? swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $value ) : $value;
	}

	/**
	 * Declares the texts of a recipe's ingredients, instructions and equipment and the unit of its
	 * servings as text, and their other values, as an amount or an ID, as none.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, 'post:' ) || 0 !== strpos( $path, 'meta/wprm_' ) ) {
			return null;
		}
		foreach ( self::TEXTS as $key => $texts ) {
			$prefix = swiftling_meta_path( $key, 0 );
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				if ( self::POST_TYPE !== get_post_type( (int) substr( $owner, 5 ) ) ) {
					return null;
				}
				return 1 === preg_match( $texts, (string) substr( $path, strlen( $prefix ) + 1 ) );
			}
		}
		return null;
	}

	/**
	 * Returns what the editor calls a text of a recipe: which part of the recipe it is.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( true !== $this->fieldHoldsText( $owner, $path ) ) {
			return '';
		}
		$parts = array(
			'#/ingredients/\d+/unit$#'                 => __( 'Ingredient › Unit', 'swiftling' ),
			'#/ingredients/\d+/name$#'                 => __( 'Ingredient', 'swiftling' ),
			'#/ingredients/\d+/notes$#'                => __( 'Ingredient › Notes', 'swiftling' ),
			'#wprm_instructions/\d+/instructions/\d+#' => __( 'Instruction', 'swiftling' ),
			'#wprm_equipment/\d+/notes$#'              => __( 'Equipment › Notes', 'swiftling' ),
			'#wprm_equipment/\d+/name$#'               => __( 'Equipment', 'swiftling' ),
			'#wprm_servings_unit$#'                    => __( 'Unit of the servings', 'swiftling' ),
		);
		foreach ( $parts as $pattern => $label ) {
			if ( preg_match( $pattern, $path ) ) {
				return $label;
			}
		}
		return __( 'Name of a group', 'swiftling' );
	}
}
