<?php
/**
 * Stand-ins for the objects of Site Reviews and WP Recipe Maker that SwiftLing's classes of them read,
 * for the tests of those classes: shaped as the plugins'.
 *
 * @package SwiftLing
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Squiz.Commenting, WordPress.NamingConventions.PrefixAllGlobals -- The plugins' own shapes.

namespace SwiftLing\Tests;

/**
 * Site Reviews' object of a review: its values read-only, but for set().
 */
class StandInReview extends \ArrayObject {
	public function __construct( array $values ) {
		parent::__construct( $values, \ArrayObject::ARRAY_AS_PROPS );
	}

	public function set( string $path, $value ): void {
		$values          = $this->getArrayCopy();
		$values[ $path ] = $value;
		$this->exchangeArray( $values );
	}
}

/**
 * WP Recipe Maker's object of a recipe.
 */
class StandInRecipe {
	private $id;

	public function __construct( int $id ) {
		$this->id = $id;
	}

	public function id() {
		return $this->id;
	}
}
