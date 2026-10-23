<?php
/**
 * A field of Gravity Forms, for the tests of its provider.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use ArrayAccess;

/**
 * A field of Gravity Forms is an object that reads and writes its settings as an array does, and
 * its choices are arrays inside it.
 */
class FakeGravityField implements ArrayAccess {
	/**
	 * Settings of the field.
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Keeps the settings.
	 *
	 * @param array $data Settings.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	// phpcs:disable Squiz.Commenting.FunctionComment -- ArrayAccess, as GF_Field implements it.
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return isset( $this->data[ $offset ] );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return isset( $this->data[ $offset ] ) ? $this->data[ $offset ] : null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$this->data[ $offset ] = $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->data[ $offset ] );
	}
	// phpcs:enable
}
