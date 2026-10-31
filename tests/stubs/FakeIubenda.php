<?php
/**
 * The iubenda plugin without the plugin's code, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Iubenda;

/**
 * The iubenda plugin, without its code: active, with the plugin's main object, and everything else of the
 * real class.
 */
class FakeIubenda extends Iubenda {
	/**
	 * The plugin's main object, with its settings and its map of languages, as iubenda 3.13 keeps them.
	 *
	 * @var object
	 */
	public $main;

	/**
	 * Makes the plugin's main object, with the code of its cookie solution.
	 *
	 * @param string $code The code of the cookie solution.
	 */
	public function __construct( $code = '' ) {
		$this->main = (object) array(
			'options'      => array( 'cs' => array( 'code_default' => $code ) ),
			'lang_mapping' => array(
				'en_GB' => 'en-GB',
				'de_DE' => 'de',
				'pt_BR' => 'pt-BR',
			),
		);
	}

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Returns the plugin's main object.
	 *
	 * @return object
	 */
	protected function plugin() {
		return $this->main;
	}
}
