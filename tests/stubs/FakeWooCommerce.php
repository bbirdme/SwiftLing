<?php
/**
 * WooCommerce without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\WooCommerce;

/**
 * WooCommerce, without the plugin: a class that reads the images of products and the names of
 * attributes from lists, and keeps everything else of the real class.
 */
class FakeWooCommerce extends WooCommerce {
	/**
	 * Images of products by post ID: the gallery and the images of the variations.
	 *
	 * @var array<int, int[]>
	 */
	public $images = array();

	/**
	 * Names of the attributes by their taxonomy, as the shop owner wrote them.
	 *
	 * @var array<string, string>
	 */
	public $labels = array();

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Returns the images of a product besides its featured image.
	 *
	 * @param int $post_id Post ID of the product.
	 */
	protected function productImages( int $post_id ): array {
		return isset( $this->images[ $post_id ] ) ? $this->images[ $post_id ] : array();
	}

	/**
	 * Returns the names of the attributes, by taxonomy.
	 */
	protected function attributeLabels(): array {
		return $this->labels;
	}
}
