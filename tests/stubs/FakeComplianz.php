<?php
/**
 * Complianz without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Complianz;

require_once __DIR__ . '/FakeComplianzBanner.php';

/**
 * Complianz, without the plugin: active, with banners of its own table, and everything else of the
 * real class.
 */
class FakeComplianz extends Complianz {
	/**
	 * The plugin's active banners.
	 *
	 * @var FakeComplianzBanner[]
	 */
	public $banners = array();

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the block and the shortcode of a consent area, as Complianz 7.5 does.
	 */
	public static function install(): void {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'complianz/consent-area' ) ) {
			register_block_type( 'complianz/consent-area', array( 'render_callback' => '__return_empty_string' ) );
		}
		add_shortcode( 'cmplz-consent-area', '__return_empty_string' );
	}

	/**
	 * Returns the plugin's active banners.
	 *
	 * @return object[]
	 */
	protected function banners(): array {
		return $this->banners;
	}
}
