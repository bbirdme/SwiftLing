<?php
/**
 * WooCommerce: what of a shop SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce. A product is a post, its descriptions are its content and excerpt, and its
 * categories, tags and the values of its attributes are terms: SwiftLing finds and translates
 * those as it does any post and term. Two things it does not find by itself:
 *
 * - The images of a product besides its featured image, its gallery and the images of its
 *   variations, show outside the product's description. They are files of the media library, which
 *   a page offers only where they show inside its content. The editor of a product offers them with
 *   the product's own fields, see swiftling_unshown_field_texts(); a translated image changes
 *   wherever the file is used, as any image does.
 * - The short description of a product is its excerpt, which the product's page of a classic theme
 *   prints from the post as stored, through woocommerce_short_description, past get_the_excerpt
 *   where SwiftLing translates an excerpt. The page of a block theme shows it with the excerpt
 *   block, which goes through get_the_excerpt. The editor names it as the plugin does.
 * - The plugin's REST API and Store API, and the structured data of a product's page, read the
 *   descriptions from the product itself, past the_content and get_the_excerpt, see
 *   filterDescription().
 * - A review is a comment, translated on the Comments screen as any comment, see
 *   includes/comments.php. The list names it as a review, with its stars.
 * - The name of an attribute that is a list of terms, such as "Boja" of the attribute pa_boja, is
 *   kept by the plugin in a table of its own and read through a transient, which SwiftLing leaves
 *   alone. It is a text of the list, "list:pa_boja", offered first in the editor of the list on the
 *   screen of categories and tags, and translated wherever the plugin names the attribute through
 *   woocommerce_attribute_label, such as on the product's page.
 *
 * A product's SKU and GTIN are identifiers, the same in every language, and declared as no text.
 *
 * An order placed with the checkout block is received on the page in the language it was placed
 * from, see filterOrderReceivedUrl(). The cart and checkout name products in the visitor's
 * language, see filterProductName(), also the blocks, which ask the plugin's Store API from the
 * page (sendingRoutes()); an order keeps the names as stored.
 *
 * An attribute of one product alone, not a list, is named in the product's own data; it is no
 * list and is left as it is here.
 *
 * Checked against the code of WooCommerce 11.2, in tests/WooCommerceTest.php with products of the
 * same shape, and by hand on a site with the plugin.
 */
class WooCommerce extends Builder {
	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'woocommerce';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'WooCommerce';
	}

	/**
	 * Whether the plugin is on the site: its main class is declared while its files load.
	 */
	public function isActive(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether a post is built with the plugin. A product is a post SwiftLing reads as any other.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A product needs nothing of a builder.
		return false;
	}

	/**
	 * Hooks where the plugin names an attribute and a product, and reads the descriptions of a
	 * product.
	 */
	public function register(): void {
		add_filter( 'woocommerce_attribute_label', array( $this, 'filterAttributeLabel' ), 10, 3 );
		// The values of an attribute of one product alone, where the plugin shows them: the variation
		// form, the cart, the checkout, and the table of the product's attributes.
		add_filter( 'woocommerce_variation_option_name', array( $this, 'filterOptionName' ), 10, 4 );
		add_filter( 'woocommerce_attribute', array( $this, 'filterAttributeValues' ), 10, 3 );
		// Before the plugin's own filters turn the text into HTML, from 8 on, so it is still the
		// excerpt as stored.
		add_filter( 'woocommerce_short_description', array( $this, 'filterShortDescription' ), 5 );
		// Where the plugin reads a product's descriptions itself: its REST API, its Store API, its
		// structured data.
		add_filter( 'woocommerce_product_get_description', array( $this, 'filterDescription' ), 10, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'filterProductShortDescription' ), 10, 2 );
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'filterOrderReceivedUrl' ) );
		// The name the plugin prints in the cart, the checkout and the mini cart: not the page's title.
		add_filter( 'woocommerce_product_get_name', array( $this, 'filterProductName' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_name', array( $this, 'filterProductName' ), 10, 2 );
		// An order's products where the customer reads them, in the language of the page: the
		// confirmation, My account, the emails. The order keeps them in the original.
		add_filter( 'woocommerce_order_item_name', array( $this, 'filterOrderItemName' ), 10, 2 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'filterOrderItemLines' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'filterOrderItemValue' ), 10, 3 );
		// The title the cart and checkout blocks name an item with, through the Store API.
		add_filter( 'woocommerce_product_title', array( $this, 'filterVariationProductTitle' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'keepOriginalItemName' ), 10, 3 );
		// The title the plugin builds for a variation and stores: always in the original.
		add_filter( 'woocommerce_product_variation_title', array( $this, 'filterVariationTitle' ), 10, 3 );
	}

	/**
	 * Returns the routes of the plugin's Store API, which its cart and checkout blocks ask from a
	 * page: answered in the language of that page, so the cart names its products in it.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/wc/store(?:/v\d+)?/#' );
	}

	/**
	 * Returns a product's name in the language of the page: the plugin names a product with its name
	 * in the cart, the checkout and the mini cart, past the_title, where SwiftLing translates a
	 * page's title. A variation is named as the plugin names it, its product's name and the values
	 * of its attributes, as "Majica - Crvena": both parts in the language. A name the plugin did not
	 * build so, as one set by hand, stays.
	 *
	 * @param mixed  $name    Name, as stored.
	 * @param object $product Product or variation.
	 * @return mixed
	 */
	public function filterProductName( $name, $product ) {
		if ( 'translate' !== swiftling_mode() || ! is_string( $name ) || '' === $name || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $name;
		}
		$parent = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		if ( ! $parent ) {
			return swiftling_filter_title( $name, (int) $product->get_id() );
		}

		$original = (string) get_post_field( 'post_title', $parent, 'raw' );
		if ( '' === $original || 0 !== strpos( $name, $original ) ) {
			return $name;
		}
		return swiftling_filter_title( $original, $parent ) . $this->swapTermNames( substr( $name, strlen( $original ) ), $product, true );
	}

	/**
	 * Returns the title the plugin builds for a variation, and stores when it differs from the stored
	 * one, with its terms named as stored. The plugin builds it from the names of the variation's
	 * terms each time it reads the variation; on a page in a language they read translated, and the
	 * stored title would follow the language of the last page that read the variation, the orders
	 * taking whichever it was. The name is translated where it is shown, see filterProductName().
	 *
	 * @param mixed  $title   Title, as the plugin built it.
	 * @param object $product Variation.
	 * @param mixed  $base    Its product's title, as stored.
	 * @return mixed
	 */
	public function filterVariationTitle( $title, $product, $base = '' ) {
		if ( 'translate' !== swiftling_mode() || ! is_string( $title ) || ! is_string( $base ) || '' === $base || 0 !== strpos( $title, $base ) ) {
			return $title;
		}
		return $base . $this->swapTermNames( substr( $title, strlen( $base ) ), $product, false );
	}

	/**
	 * Swaps the values of a variation's attributes in a text, as "Crvena" and "Velika" in " - Crvena,
	 * Velika": each from its name as stored to its name on the page, or back. A value of a list of
	 * terms is its term's name; one of an attribute of the product alone is a text of the product.
	 *
	 * @param string $text    Text, the part of a name after its product's title.
	 * @param object $product Variation.
	 * @param bool   $shown   True for the names on the page, false for the names as stored.
	 */
	private function swapTermNames( string $text, $product, bool $shown ): string {
		foreach ( method_exists( $product, 'get_attributes' ) ? (array) $product->get_attributes() : array() as $taxonomy => $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$names = taxonomy_exists( (string) $taxonomy ) ? $this->termNames( (string) $taxonomy, $slug ) : $this->ownValueNames( $product, (string) $taxonomy, $slug );
			if ( null !== $names ) {
				list( $from, $to ) = $shown ? $names : array_reverse( $names );
				// A callback, so a name such as "Paket $10" is put in as written.
				$text = (string) preg_replace_callback(
					'~(?<![\p{L}\p{N}])' . preg_quote( $from, '~' ) . '(?![\p{L}\p{N}])~u',
					static function () use ( $to ) {
						return $to;
					},
					$text,
					1
				);
			}
		}
		return $text;
	}

	/**
	 * Returns a value of an attribute of one product alone as stored and as it reads on the page.
	 *
	 * @param object $product Product or variation.
	 * @param string $name    The attribute's name or key.
	 * @param string $value   The value, as stored.
	 * @return array{0: string, 1: string}|null Null for no value of the product's own attribute.
	 */
	private function ownValueNames( $product, string $name, string $value ): ?array {
		$own = $this->ownAttribute( $product, $name );
		return $own && in_array( $value, $own['options'], true ) ? array( $value, $this->optionText( $own, $value ) ) : null;
	}

	/**
	 * Returns the name of an order's product in the language of the page, where the customer reads
	 * it: on the page the order is confirmed on, on My account and in the emails. The order keeps
	 * the name in the original, for the shop owner; a name the product no longer has stays.
	 *
	 * @param mixed  $html The name, as the plugin prints it, maybe as a link to the product.
	 * @param object $item Item of the order.
	 * @return mixed
	 */
	public function filterOrderItemName( $html, $item ) {
		if ( 'translate' !== swiftling_mode() || ! is_string( $html ) || ! is_object( $item ) || ! method_exists( $item, 'get_name' ) || ! method_exists( $item, 'get_product' ) ) {
			return $html;
		}
		$name    = (string) $item->get_name();
		$product = $item->get_product();
		if ( '' === $name || ! is_object( $product ) || false === strpos( $html, $name ) ) {
			return $html;
		}
		$shown = $this->filterProductName( $name, $product );
		if ( ! is_string( $shown ) || $shown === $name ) {
			return $html;
		}
		$at = (int) strpos( $html, $name );
		return substr( $html, 0, $at ) . $shown . substr( $html, $at + strlen( $name ) );
	}

	/**
	 * Leaves out of the lines under an order's product the values its name holds, as the plugin does
	 * on a page in the original. The plugin looks for them as they read on the page, in the name the
	 * order kept in the original, and finds none; the name is shown translated, see
	 * filterOrderItemName(), so each would be listed twice.
	 *
	 * @param mixed  $lines The lines, by meta ID.
	 * @param object $item  Item of the order.
	 * @return mixed
	 */
	public function filterOrderItemLines( $lines, $item ) {
		if ( 'translate' !== swiftling_mode() || ! is_array( $lines ) || ! is_object( $item ) || ! method_exists( $item, 'get_name' ) || ! method_exists( $item, 'get_product' ) ) {
			return $lines;
		}
		$product = $item->get_product();
		$parent  = is_object( $product ) && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		if ( ! $parent ) {
			return $lines;
		}
		$name = wp_specialchars_decode( (string) $item->get_name(), ENT_QUOTES );
		foreach ( $lines as $id => $line ) {
			if ( ! is_object( $line ) || ! isset( $line->key, $line->value ) || ! is_string( $line->value ) ) {
				continue;
			}
			$key   = (string) $line->key;
			$names = taxonomy_exists( $key ) ? $this->termNames( $key, $line->value ) : $this->ownValueNames( $product, $key, $line->value );
			if ( null !== $names && $this->inProductName( $names[0], $name ) ) {
				unset( $lines[ $id ] );
			}
		}
		return $lines;
	}

	/**
	 * Translates a value of an attribute of one product alone in the lines under an order's product.
	 *
	 * @param mixed  $value The value, as the plugin shows it.
	 * @param object $meta  The line's meta: key and value as stored.
	 * @param object $item  Item of the order.
	 * @return mixed
	 */
	public function filterOrderItemValue( $value, $meta, $item ) {
		if ( 'translate' !== swiftling_mode() || ! is_string( $value ) || ! is_object( $meta ) || ! isset( $meta->key, $meta->value ) || $value !== $meta->value || ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
			return $value;
		}
		$names = $this->ownValueNames( $item->get_product(), (string) $meta->key, $value );
		return null !== $names ? $names[1] : $value;
	}

	/**
	 * Whether a value is in the name of a variation, as the plugin decides it: after a space and
	 * before a comma, or at the end (wc_is_attribute_in_product_name()).
	 *
	 * @param string $value Value.
	 * @param string $name  Name.
	 */
	private function inProductName( string $value, string $name ): bool {
		return false !== stristr( $name, ' ' . $value . ',' ) || 0 === stripos( strrev( $name ), strrev( ' ' . $value ) );
	}

	/**
	 * Returns a variation's title in the language of the page. The plugin's Store API, which its cart
	 * and checkout blocks ask, names an item with its title, and a variation's title is its product's
	 * title as stored, past the name filterProductName() translates. A product's title is its name,
	 * translated already; a title that is not its product's as stored stays.
	 *
	 * @param mixed  $title   Title, as the plugin read it.
	 * @param object $product Product or variation.
	 * @return mixed
	 */
	public function filterVariationProductTitle( $title, $product ) {
		$parent = is_object( $product ) && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		if ( 'translate' !== swiftling_mode() || ! is_string( $title ) || ! $parent || (string) get_post_field( 'post_title', $parent, 'raw' ) !== $title ) {
			return $title;
		}
		return swiftling_filter_title( $title, $parent );
	}

	/**
	 * Returns the name of a term as stored and as it reads in the language of the page.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug of the term.
	 * @return array{0: string, 1: string}|null
	 */
	private function termNames( string $taxonomy, string $slug ): ?array {
		$state = &swiftling_state();
		$guard = $state['guard'];
		// The name as stored: SwiftLing reads it itself.
		$state['guard'] = true;
		$stored         = get_term_by( 'slug', $slug, $taxonomy );
		$state['guard'] = $guard;
		$shown          = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $stored instanceof \WP_Term || ! $shown instanceof \WP_Term ) {
			return null;
		}
		return array( (string) $stored->name, (string) $shown->name );
	}

	/**
	 * Keeps a product's name in an order as stored, in the original, as the shop's owner reads the
	 * order, though the cart showed it in the visitor's language. SwiftLing Pro writes the emails to
	 * the customer in the order's language from it.
	 *
	 * @param object $item   Item of the order being made.
	 * @param string $key    Key of the item in the cart.
	 * @param array  $values The item in the cart, its product under data.
	 */
	public function keepOriginalItemName( $item, $key, $values ): void {
		unset( $key );
		$product = is_array( $values ) && isset( $values['data'] ) ? $values['data'] : null;
		if ( is_object( $item ) && method_exists( $item, 'set_name' ) && is_object( $product ) && method_exists( $product, 'get_name' ) ) {
			$item->set_name( (string) $product->get_name( 'edit' ) );
		}
	}

	/**
	 * Returns the address of the page an order is received on in the language of the page the order
	 * was placed from. The checkout block places it through the plugin's Store API, a request
	 * SwiftLing answers in the original, so the plugin builds the address without the language; the
	 * classic checkout is answered in the page's language and builds it with it.
	 *
	 * @param mixed $url Address, as the plugin builds it.
	 * @return mixed
	 */
	public function filterOrderReceivedUrl( $url ) {
		if ( ! is_string( $url ) || 'translate' === swiftling_mode() ) {
			return $url;
		}
		$slug = swiftling_address_language( (string) wp_get_raw_referer() );
		return '' === $slug ? $url : swiftling_language_url( $url, $slug );
	}

	/**
	 * Translates the short description of the product shown, as the plugin's template prints it from
	 * the post. Other texts the plugin passes through the same filter are not that post's excerpt and
	 * stay as they are, see swiftling_filter_excerpt().
	 *
	 * @param mixed $text The short description, as stored.
	 * @return mixed
	 */
	public function filterShortDescription( $text ) {
		$post = get_post();
		if ( ! is_string( $text ) || ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return $text;
		}
		return swiftling_filter_excerpt( $text, $post );
	}

	/**
	 * Returns a product's description in the language of the page where the plugin reads it from
	 * the product itself, past the_content: its REST API and Store API, which answer in a language
	 * (see swiftling_rest_reads()), and the structured data of the product's page. As stored, not
	 * rendered: the plugin renders it after. A text that is not the product's content as stored,
	 * such as one another plugin changed, stays.
	 *
	 * @param mixed  $description Description.
	 * @param object $product     Product.
	 * @return mixed
	 */
	public function filterDescription( $description, $product ) {
		$post = $this->storedProduct( $description, $product );
		if ( ! $post || $description !== $post->post_content ) {
			return $description;
		}
		return swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $description );
	}

	/**
	 * Returns a product's short description in the language of the page where the plugin reads it
	 * from the product itself, see filterDescription(). The product's page prints it through
	 * woocommerce_short_description as well, see filterShortDescription(); a translated text is no
	 * longer the excerpt as stored, so it is not translated twice.
	 *
	 * @param mixed  $description Short description.
	 * @param object $product     Product.
	 * @return mixed
	 */
	public function filterProductShortDescription( $description, $product ) {
		$post = $this->storedProduct( $description, $product );
		return $post ? swiftling_filter_excerpt( $description, $post ) : $description;
	}

	/**
	 * Returns the post of a product whose text is read in a language, or null when there is nothing
	 * to translate: the page is in the original, the text is empty, or the product is not a post
	 * of the plugin's products, as a variation is.
	 *
	 * @param mixed  $text    Text the plugin read.
	 * @param object $product Product.
	 * @return \WP_Post|null
	 */
	private function storedProduct( $text, $product ) {
		if ( 'translate' !== swiftling_mode() || ! is_string( $text ) || '' === $text || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return null;
		}
		$post = get_post( (int) $product->get_id() );
		return $post instanceof \WP_Post && 'product' === $post->post_type ? $post : null;
	}

	/**
	 * Translates the name of an attribute wherever the plugin names it: of a list of terms, a text of
	 * the list; of one product alone, a text of the product.
	 *
	 * @param mixed  $label   The name, as the shop owner wrote it.
	 * @param string $name    The attribute: its taxonomy, such as pa_boja, or the name of an attribute
	 *                        of one product alone, as written or as its key.
	 * @param mixed  $product The product or variation, where the plugin passes one.
	 * @return mixed
	 */
	public function filterAttributeLabel( $label, $name = '', $product = null ) {
		if ( ! is_string( $label ) || '' === swiftling_mode() ) {
			return $label;
		}
		if ( isset( $this->attributeLabels()[ (string) $name ] ) ) {
			return swiftling_transform_text( self::listOwner( (string) $name ), 'field/label', 'list:label', $label );
		}
		$own = $this->ownAttribute( $product, (string) $name );
		return $own ? swiftling_transform_text( $own['owner'], $own['path'] . '/name', 'wc:attribute-name', $label ) : $label;
	}

	/**
	 * Translates a value of an attribute of one product alone where the plugin shows it, as an option
	 * of the variation form or in the cart. The value itself, the key of the product's variations,
	 * stays as stored. A term is translated as a term.
	 *
	 * @param mixed $option    The value.
	 * @param mixed $term      The term, for an attribute that is a list of terms.
	 * @param mixed $attribute The attribute, as the plugin names it there.
	 * @param mixed $product   The product or variation.
	 * @return mixed
	 */
	public function filterOptionName( $option, $term = null, $attribute = '', $product = null ) {
		if ( ! is_string( $option ) || null !== $term || '' === swiftling_mode() ) {
			return $option;
		}
		$own = $this->ownAttribute( $product, is_string( $attribute ) ? $attribute : '' );
		return $own && in_array( $option, $own['options'], true ) ? $this->optionText( $own, $option ) : $option;
	}

	/**
	 * Translates the values of an attribute of one product alone in the table of the product's
	 * attributes, on its page. HTML the table no longer holds as the plugin built it, as another
	 * plugin changed it, stays.
	 *
	 * @param mixed $html      The values, as the plugin prints them.
	 * @param mixed $attribute The attribute.
	 * @param mixed $values    Its values, as the plugin made them safe to print.
	 * @return mixed
	 */
	public function filterAttributeValues( $html, $attribute = null, $values = array() ) {
		if ( ! is_string( $html ) || '' === swiftling_mode() || ! is_object( $attribute ) || ! method_exists( $attribute, 'is_taxonomy' ) || $attribute->is_taxonomy() || ! method_exists( $attribute, 'get_options' ) ) {
			return $html;
		}
		$options = array_map( 'strval', (array) $attribute->get_options() );
		$own     = $options ? $this->ownAttribute( null, '', $options ) : null;
		if ( ! $own || wpautop( wptexturize( implode( ', ', (array) $values ) ) ) !== $html ) {
			return $html;
		}
		$shown = array();
		foreach ( $options as $option ) {
			$shown[] = make_clickable( esc_html( $this->optionText( $own, $option ) ) );
		}
		return wpautop( wptexturize( implode( ', ', $shown ) ) );
	}

	/**
	 * Returns a value of an attribute of one product alone in the language of the page, or a probe
	 * marker.
	 *
	 * @param array  $own    The attribute, see ownAttribute().
	 * @param string $option The value, as stored.
	 */
	private function optionText( array $own, string $option ): string {
		return (string) swiftling_transform_text( $own['owner'], $own['path'] . '/options/' . swiftling_escape_segment( $option ), 'wc:attribute-option', $option );
	}

	/**
	 * Returns an attribute of one product alone: the product it belongs to, which the plugin names
	 * differently in each place, and its values as stored. The product is the one passed, the
	 * product of a variation passed, or the product of the page. The attribute is found by its name,
	 * as written or as the plugin's key of it, also as the cart names it (pa_attribute_velicina), or,
	 * where the plugin passes no name, by its values.
	 *
	 * @param mixed    $product Product or variation, or none.
	 * @param string   $name    Name of the attribute, or empty.
	 * @param string[] $options The attribute's values, where the plugin passes no name.
	 * @return array{owner: string, path: string, options: string[]}|null
	 */
	private function ownAttribute( $product, string $name, array $options = array() ): ?array {
		$id = $this->productId( $product );
		if ( ! $id ) {
			return null;
		}
		$attributes = $this->storedAttributes( $id );
		$key        = '';
		if ( '' !== $name ) {
			$name = (string) preg_replace( '~^(?:pa_)?attribute_~', '', rawurldecode( $name ) );
			$key  = sanitize_title( $name );
		} else {
			foreach ( $attributes as $candidate => $attribute ) {
				if ( is_array( $attribute ) && empty( $attribute['is_taxonomy'] ) && $this->attributeOptions( $attribute ) === $options ) {
					$key = (string) $candidate;
					break;
				}
			}
		}
		if ( '' === $key || ! isset( $attributes[ $key ] ) || ! is_array( $attributes[ $key ] ) || ! empty( $attributes[ $key ]['is_taxonomy'] ) ) {
			return null;
		}
		return array(
			'owner'   => swiftling_post_owner( $id ),
			'path'    => 'attribute/' . swiftling_escape_segment( $key ),
			'options' => $this->attributeOptions( $attributes[ $key ] ),
		);
	}

	/**
	 * Returns the ID of the product a product, a variation or the page is: a variation's product.
	 *
	 * @param mixed $product Product or variation, or none for the product of the page.
	 */
	private function productId( $product ): int {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			$global  = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
			$product = is_object( $global ) && method_exists( $global, 'get_id' ) ? $global : null;
		}
		if ( $product ) {
			$parent = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			return $parent ? $parent : (int) $product->get_id();
		}
		$post = get_post();
		return $post instanceof \WP_Post && 'product' === $post->post_type ? (int) $post->ID : 0;
	}

	/**
	 * Returns the attributes of a product as stored, keyed as the plugin keys them, past SwiftLing.
	 *
	 * @param int $id Product ID.
	 * @return array<string, array>
	 */
	private function storedAttributes( int $id ): array {
		$state          = &swiftling_state();
		$guard          = $state['guard'];
		$state['guard'] = true;
		try {
			$attributes = get_post_meta( $id, '_product_attributes', true );
		} finally {
			$state['guard'] = $guard;
		}
		return is_array( $attributes ) ? $attributes : array();
	}

	/**
	 * Returns the values of an attribute of one product alone, as the plugin splits them.
	 *
	 * @param array $attribute Attribute, as stored.
	 * @return string[]
	 */
	private function attributeOptions( array $attribute ): array {
		$value = isset( $attribute['value'] ) && is_string( $attribute['value'] ) ? html_entity_decode( $attribute['value'], ENT_QUOTES, 'UTF-8' ) : '';
		return array_values(
			array_filter(
				array_map( 'trim', explode( '|', $value ) ),
				static function ( string $option ): bool {
					return '' !== $option;
				}
			)
		);
	}

	/**
	 * Returns the texts of a product's images besides its featured image, for its editor, and the
	 * name of an attribute, for the editor of its list.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( 'post' === $subject['kind'] && $subject['post'] instanceof \WP_Post && 'product' === $subject['post']->post_type ) {
			$texts = array();
			foreach ( $this->productImages( (int) $subject['post']->ID ) as $image ) {
				array_push( $texts, ...swiftling_media_texts( $image ) );
			}
			return $texts;
		}

		$labels   = $this->attributeLabels();
		$taxonomy = 'terms' === $subject['kind'] ? substr( (string) $subject['key'], 6 ) : '';
		if ( '' === $taxonomy || ! isset( $labels[ $taxonomy ] ) || '' === trim( $labels[ $taxonomy ] ) ) {
			return array();
		}
		return array(
			array(
				'o'     => self::listOwner( $taxonomy ),
				'p'     => 'field/label',
				'v'     => $labels[ $taxonomy ],
				'r'     => swiftling_rule_key( 'list:label', '', '' ),
				'kind'  => 'name',
				'place' => '',
			),
		);
	}

	/**
	 * Declares the identifiers of a product as no text: its SKU and its GTIN, UPC, EAN or ISBN, the
	 * same in every language. A theme prints them, as "SKU: BENCH-500", and the page check would take
	 * them for texts, which the product then needs translated to count as translated.
	 *
	 * Its attributes as stored are none either: the values of an attribute of one product alone are
	 * the keys of its variations, and translated there no option of the variation form matched a
	 * variation. Their name and values are translated where they are shown, see filterOptionName().
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null False for an identifier or the attributes of a product; null for everything else.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		$attributes = 0 === strpos( $path, 'meta/_product_attributes' );
		if ( ( ! $attributes && ! in_array( $path, array( 'meta/_sku', 'meta/_global_unique_id' ), true ) ) || 0 !== strpos( $owner, 'post:' ) ) {
			return null;
		}
		return in_array( get_post_type( (int) substr( $owner, 5 ) ), array( 'product', 'product_variation' ), true ) ? false : null;
	}

	/**
	 * Whether the current user may translate the name of an attribute: who may edit its terms.
	 *
	 * @param string $owner Object key.
	 * @return bool|null Null when the object is no list of an attribute.
	 */
	public function canTranslate( string $owner ): ?bool {
		if ( 0 !== strpos( $owner, 'list:' ) || ! isset( $this->attributeLabels()[ substr( $owner, 5 ) ] ) ) {
			return null;
		}
		$taxonomy = get_taxonomy( substr( $owner, 5 ) );
		return $taxonomy instanceof \WP_Taxonomy && current_user_can( $taxonomy->cap->edit_terms );
	}

	/**
	 * Returns what the editor calls the name of an attribute, the excerpt of a product, which the
	 * plugin calls its short description, and a review, with the stars it gives, which stay as given.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is none of these.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( ( 0 === strpos( $owner, 'list:' ) && 'field/label' === $path ) || 1 === preg_match( '~^attribute/[^/]+/name$~', $path ) ) {
			return __( 'Name of the attribute', 'swiftling' );
		}
		if ( 1 === preg_match( '~^attribute/[^/]+/options/~', $path ) ) {
			return __( 'Value of the attribute', 'swiftling' );
		}
		if ( 'field/post_excerpt' === $path && 0 === strpos( $owner, 'post:' ) && 'product' === get_post_type( (int) substr( $owner, 5 ) ) ) {
			return __( 'Product short description', 'swiftling' );
		}
		$review = 'field/comment_content' === $path ? swiftling_owner_comment( $owner ) : null;
		if ( $review && 'review' === $review->comment_type ) {
			$stars = (int) get_comment_meta( (int) $review->comment_ID, 'rating', true );
			return $stars > 0
				/* translators: %s: stars a review gives, from 1 to 5. */
				? sprintf( __( 'Review, %s of 5 stars', 'swiftling' ), number_format_i18n( $stars ) )
				: __( 'Review', 'swiftling' );
		}
		return '';
	}

	/**
	 * Returns the object key of the list of an attribute.
	 *
	 * @param string $taxonomy Taxonomy of the attribute, such as pa_boja.
	 */
	private static function listOwner( string $taxonomy ): string {
		return 'list:' . $taxonomy;
	}

	/**
	 * Returns the images of a product besides its featured image: its gallery and the images of its
	 * variations, each once.
	 *
	 * @param int $post_id Post ID of the product.
	 * @return int[] Attachment IDs.
	 */
	protected function productImages( int $post_id ): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return array();
		}
		$images = $product->get_gallery_image_ids();
		foreach ( $product->get_children() as $child ) {
			$variation = wc_get_product( $child );
			if ( $variation instanceof \WC_Product ) {
				$images[] = $variation->get_image_id();
			}
		}
		$featured = (int) $product->get_image_id();
		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $images ),
					static function ( int $id ) use ( $featured ): bool {
						return $id > 0 && $id !== $featured;
					}
				)
			)
		);
	}

	/**
	 * Returns the names of the attributes that are lists of terms, by taxonomy, as the shop owner
	 * wrote them.
	 *
	 * @return array<string, string>
	 */
	protected function attributeLabels(): array {
		$labels = array();
		foreach ( function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array() as $attribute ) {
			$labels[ wc_attribute_taxonomy_name( $attribute->attribute_name ) ] = (string) $attribute->attribute_label;
		}
		return $labels;
	}
}
