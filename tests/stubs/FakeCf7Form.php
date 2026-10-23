<?php
/**
 * A form of Contact Form 7 as the plugin hands it to its hooks, without the plugin.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * The part of WPCF7_ContactForm the hooks around its mails use: its ID and its properties.
 */
class FakeCf7Form {
	/**
	 * Post ID of the form.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Properties, as the plugin read them from the meta of the form.
	 *
	 * @var array
	 */
	private $properties;

	/**
	 * Reads the form's properties from its meta, as the plugin does when it loads a form.
	 *
	 * @param int $id Post ID of the form.
	 */
	public function __construct( $id ) {
		$this->id = $id;
		foreach ( array( 'form', 'messages', 'mail', 'mail_2' ) as $name ) {
			$this->properties[ $name ] = get_post_meta( $id, '_' . $name, true );
		}
	}

	/**
	 * Returns the form's ID.
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Returns a property.
	 *
	 * @param string $name Name.
	 */
	public function prop( $name ) {
		return isset( $this->properties[ $name ] ) ? $this->properties[ $name ] : null;
	}

	/**
	 * Sets properties.
	 *
	 * @param array $properties Name => value.
	 */
	public function set_properties( $properties ) {
		$this->properties = array_merge( $this->properties, $properties );
	}
}
