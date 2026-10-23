<?php
/**
 * Tests of the WooCommerce class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\WooCommerce;

require_once __DIR__ . '/stubs/FakeWooCommerce.php';

/**
 * What SwiftLing translates of a shop: the images of a product besides its featured image, and the
 * name of an attribute, such as "Boja", which the shop owner writes once for the attribute.
 */
class WooCommerceTest extends TestCase {

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Puts the fake in the registry, with products and an attribute as the plugin registers them.
	 *
	 * @return FakeWooCommerce
	 */
	private function shop() {
		register_post_type(
			'product',
			array(
				'public'   => true,
				'show_ui'  => true,
				'supports' => array( 'title', 'editor', 'thumbnail' ),
			)
		);
		register_taxonomy(
			'pa_boja',
			array( 'product' ),
			array(
				'public'  => false,
				'show_ui' => true,
				'labels'  => array( 'name' => 'Proizvod Boja' ),
			)
		);
		$shop     = new FakeWooCommerce();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof WooCommerce ? $shop : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$shop->labels = array( 'pa_boja' => 'Boja' );
		return $shop;
	}

	/**
	 * Makes an image of the media library.
	 *
	 * @param string $file Path of the file in the uploads.
	 * @param string $alt  Alt text.
	 * @return int Attachment ID.
	 */
	private function image( $file, $alt = '' ) {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
			)
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	/**
	 * The editor of a product offers the images of its gallery and of its variations with its own
	 * fields, each with its alt text; the plugin shows them outside the product's description.
	 */
	public function test_product_images_are_offered_with_the_product() {
		$shop                     = $this->shop();
		$gallery                  = $this->image( '2026/10/stražnja.jpg', 'Auto straga' );
		$variant                  = $this->image( '2026/10/crveni.jpg' );
		$product                  = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$shop->images[ $product ] = array( $gallery, $variant );

		$texts = array();
		foreach ( $shop->listTexts( swiftling_subject( 'post:' . $product ) ) as $text ) {
			$texts[ $text['o'] . '|' . $text['p'] ] = $text;
		}
		$this->assertSame( 'image', $texts[ 'post:' . $gallery . '|file' ]['kind'] );
		$this->assertSame( wp_get_attachment_url( $gallery ), $texts[ 'post:' . $gallery . '|file' ]['v'] );
		$this->assertSame( 'Auto straga', $texts[ 'post:' . $gallery . '|' . swiftling_meta_path( '_wp_attachment_image_alt', 0 ) ]['v'] );
		$this->assertArrayHasKey( 'post:' . $variant . '|file', $texts );

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( array(), $shop->listTexts( swiftling_subject( 'post:' . $page ) ), 'A page is no product' );
	}

	/**
	 * The name of an attribute is a text of its list, offered first in the editor of the list on the
	 * screen of categories and tags, and translated where the shop shows it, with the right to edit
	 * the terms of the list.
	 */
	public function test_name_of_an_attribute() {
		$shop = $this->shop();
		self::factory()->term->create(
			array(
				'taxonomy' => 'pa_boja',
				'name'     => 'Crvena',
			)
		);
		$subject = swiftling_subject( 'terms:pa_boja' );
		$this->assertNotNull( $subject, 'The attribute is a list of terms' );

		$result = swiftling_save_term_list( $subject );
		$first  = $result['sections']['page'][0];
		$this->assertSame( 'list:pa_boja', $first['o'] );
		$this->assertSame( 'Boja', $first['v'] );
		$this->assertArrayHasKey( 'list:pa_boja', $result['terms'], 'The list has a group of its own' );
		$this->translate( 'list:pa_boja', $first['p'], 'Boja', 'de', 'Farbe' );

		$this->show_in( 'de' );
		$this->assertSame( 'Farbe', $shop->filterAttributeLabel( 'Boja', 'pa_boja' ) );
		$this->assertSame( 'Veličina', $shop->filterAttributeLabel( 'Veličina', 'Veličina' ), 'An attribute of the product alone is no list' );
		$this->reset_state();
		$this->assertSame( 'Boja', $shop->filterAttributeLabel( 'Boja', 'pa_boja' ) );

		$this->become( 'subscriber' );
		$this->assertFalse( Registry::canTranslate( 'list:pa_boja' ) );
		$this->become( 'administrator' );
		$this->assertTrue( Registry::canTranslate( 'list:pa_boja' ) );
	}

	/**
	 * The short description of a product, its excerpt, which the plugin prints from the post as
	 * stored, past the excerpt's filter: a page check finds it and a page in another language shows
	 * its translation. The editor names it as the plugin does.
	 */
	public function test_short_description_of_a_product() {
		$shop = $this->shop();
		$shop->register();
		$product         = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'product',
				'post_excerpt' => 'Kratki opis auta.',
			)
		);
		$GLOBALS['post'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The plugin's template reads the global post.
		$render          = static function () use ( $product ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as its template applies it.
			return apply_filters( 'woocommerce_short_description', $product->post_excerpt );
		};

		$found = array();
		foreach ( $this->probe( $render ) as $leaf ) {
			$found[] = $leaf['o'] . '|' . $leaf['p'];
		}
		$this->assertContains( 'post:' . $product->ID . '|field/post_excerpt', $found );

		$this->check( 'post:' . $product->ID, $render );
		$this->translate( 'post:' . $product->ID, 'field/post_excerpt', 'Kratki opis auta.', 'de', 'Kurze Beschreibung.' );
		$this->show_in( 'de' );
		$this->assertSame( 'Kurze Beschreibung.', $render() );
		$this->reset_state();

		$this->assertSame( 'Product short description', Registry::fieldLabel( 'post:' . $product->ID, 'field/post_excerpt' ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( '', Registry::fieldLabel( 'post:' . $page, 'field/post_excerpt' ), 'Of a product only' );
	}

	/**
	 * A review is named in the list of comments with its stars, which stay as given; a comment
	 * without stars, or of another plugin, is not.
	 */
	public function test_review_named_with_its_stars() {
		$this->shop();
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$review  = self::factory()->comment->create(
			array(
				'comment_post_ID' => $product,
				'comment_type'    => 'review',
			)
		);
		update_comment_meta( $review, 'rating', '4' );
		$plain   = self::factory()->comment->create(
			array(
				'comment_post_ID' => $product,
				'comment_type'    => 'review',
			)
		);
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $product ) );
		update_comment_meta( $comment, 'rating', '5' );

		$this->assertSame( 'Review, 4 of 5 stars', Registry::fieldLabel( 'comment:' . $review, 'field/comment_content' ) );
		$this->assertSame( 'Review', Registry::fieldLabel( 'comment:' . $plain, 'field/comment_content' ) );
		$this->assertSame( '', Registry::fieldLabel( 'comment:' . $comment, 'field/comment_content' ) );
	}

	/**
	 * A product's SKU and GTIN are identifiers, the same in every language: never a text to
	 * translate, also where the page prints them, as "SKU: BENCH-500". A text field of the same
	 * product still is.
	 */
	public function test_sku_and_gtin_are_no_text() {
		$this->shop();
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product, '_sku', 'BENCH-500' );
		update_post_meta( $product, '_global_unique_id', 'Ean 4006381333931' );
		update_post_meta( $product, 'subtitle', 'Lagani ogrtač za kišu' );

		$found = wp_list_pluck(
			$this->probe(
				static function () use ( $product ) {
					get_post_meta( $product, '_sku', true );
					get_post_meta( $product, '_global_unique_id', true );
					get_post_meta( $product, 'subtitle', true );
				}
			),
			'p'
		);
		$this->assertNotContains( 'meta/_sku', $found );
		$this->assertNotContains( 'meta/_global_unique_id', $found );
		$this->assertContains( 'meta/subtitle', $found, 'A text field still is one' );
	}

	/**
	 * The checkout block places an order through WooCommerce's Store API, which SwiftLing answers
	 * in the original: the page the order is received on is in the language of the page it was
	 * placed from, as the classic checkout's. A request already in a language, and one from a page
	 * in the original, keep the address as WooCommerce builds it.
	 */
	public function test_order_received_page_in_the_language_of_the_checkout() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$shop     = new WooCommerce();
		$received = home_url( '/checkout/order-received/17/?key=wc_order_abc' );

		$_SERVER['HTTP_REFERER'] = home_url( '/de/checkout/' );
		$this->assertSame( home_url( '/de/checkout/order-received/17/?key=wc_order_abc' ), $shop->filterOrderReceivedUrl( $received ) );

		$_SERVER['HTTP_REFERER'] = home_url( '/checkout/' );
		$this->assertSame( $received, $shop->filterOrderReceivedUrl( $received ), 'From the original' );

		$_SERVER['HTTP_REFERER'] = home_url( '/de/checkout/' );
		$this->show_in( 'de' );
		$localized = home_url( '/de/checkout/order-received/17/?key=wc_order_abc' );
		$this->assertSame( $localized, $shop->filterOrderReceivedUrl( $localized ), 'Built in the language already' );
		unset( $_SERVER['HTTP_REFERER'] );

		$shop->register();
		$this->assertNotFalse( has_filter( 'woocommerce_get_checkout_order_received_url', array( $shop, 'filterOrderReceivedUrl' ) ) );
	}

	/**
	 * Returns an object as WooCommerce's product or variation: its ID, parent, name as stored, and
	 * a variation's attributes.
	 *
	 * @param int    $id         Post ID.
	 * @param string $name       Name as stored.
	 * @param int    $of         Parent product, or 0.
	 * @param array  $attributes Taxonomy => term slug.
	 * @return object
	 */
	private function product_object( $id, $name, $of = 0, $attributes = array() ) {
		return new class( $id, $name, $of, $attributes ) {
			/**
			 * The product.
			 *
			 * @var array
			 */
			private $data;

			/**
			 * Keeps the product.
			 *
			 * @param int    $id         Post ID.
			 * @param string $name       Name.
			 * @param int    $of         Parent.
			 * @param array  $attributes Attributes.
			 */
			public function __construct( $id, $name, $of, $attributes ) {
				$this->data = array(
					'id'         => $id,
					'name'       => $name,
					'parent'     => $of,
					'attributes' => $attributes,
				);
			}

			/**
			 * Post ID.
			 */
			public function get_id() {
				return $this->data['id'];
			}

			/**
			 * Parent product.
			 */
			public function get_parent_id() {
				return $this->data['parent'];
			}

			/**
			 * Attributes of a variation.
			 */
			public function get_attributes() {
				return $this->data['attributes'];
			}

			/**
			 * Name as stored.
			 *
			 * @param string $context Context.
			 */
			public function get_name( $context = 'view' ) {
				unset( $context );
				return $this->data['name'];
			}
		};
	}

	/**
	 * On a page in a language a product is named as translated, also in the cart, where the plugin
	 * names it with its name, not the page's title: a product, and a variation, named its product's
	 * name and the value of its attribute. In the original, and as the plugin edits it, the stored
	 * name stays.
	 */
	public function test_product_names_in_a_language() {
		$this->shop();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$shirt = self::factory()->post->create(
			array(
				'post_type'  => 'product',
				'post_title' => 'Majica',
			)
		);
		$red   = wp_insert_term( 'Crvena', 'pa_boja', array( 'slug' => 'crvena' ) );
		$this->check(
			'post:' . $shirt,
			static function () use ( $shirt ) {
				get_the_title( $shirt );
			}
		);
		$this->check(
			'terms:pa_boja',
			static function () use ( $red ) {
				get_term( $red['term_id'], 'pa_boja' );
			}
		);
		$this->translate( 'post:' . $shirt, 'field/post_title', 'Majica', 'de', 'T-Shirt' );
		$this->translate( 'term:' . $red['term_id'], 'field/name', 'Crvena', 'de', 'Rot' );
		$shop      = new WooCommerce();
		$product   = $this->product_object( $shirt, 'Majica' );
		$variation = $this->product_object( $shirt + 1000, 'Majica - Crvena', $shirt, array( 'pa_boja' => 'crvena' ) );

		$this->assertSame( 'Majica', $shop->filterProductName( 'Majica', $product ), 'In the original' );
		$this->show_in( 'de' );
		$this->assertSame( 'T-Shirt', $shop->filterProductName( 'Majica', $product ) );
		$this->assertSame( 'T-Shirt - Rot', $shop->filterProductName( 'Majica - Crvena', $variation ) );
		$this->assertSame( 'Majica - Plava', $shop->filterProductName( 'Majica - Plava', $this->product_object( 5, 'Majica - Plava', 99999 ) ), 'A name not built from its product stays' );

		// An order keeps the name as stored, as the shop's owner reads it.
		$item = new class() {
			/**
			 * Name of the item.
			 *
			 * @var string
			 */
			public $name = 'T-Shirt - Rot';

			/**
			 * Sets the name.
			 *
			 * @param string $name Name.
			 */
			public function set_name( $name ) {
				$this->name = $name;
			}
		};
		$shop->keepOriginalItemName( $item, 'key', array( 'data' => $variation ) );
		$this->assertSame( 'Majica - Crvena', $item->name );
	}

	/**
	 * On the page an order is confirmed on, and on My account, the customer reads the products in the
	 * language of the page they bought on, though the order keeps their names in the original for the
	 * shop owner: a variation named its product's name and its values, also of an attribute of the
	 * product alone, and a product. Under a name, the values it holds are not listed again; another
	 * value of the product's own attribute is translated. A name the product no longer has stays.
	 */
	public function test_order_confirmation_in_the_language() {
		$shop = $this->shop();
		$shop->register();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$shirt = self::factory()->post->create(
			array(
				'post_type'  => 'product',
				'post_title' => 'Majica',
			)
		);
		update_post_meta(
			$shirt,
			'_product_attributes',
			array(
				'velicina' => array(
					'name'         => 'Veličina',
					'value'        => 'Mala | Velika',
					'position'     => 1,
					'is_visible'   => 1,
					'is_variation' => 1,
					'is_taxonomy'  => 0,
				),
			)
		);
		$red    = wp_insert_term( 'Crvena', 'pa_boja', array( 'slug' => 'crvena' ) );
		$parent = $this->product_object( $shirt, 'Majica' );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters, as it applies them.
		$this->check(
			'post:' . $shirt,
			static function () use ( $shirt, $parent ) {
				get_the_title( $shirt );
				apply_filters( 'woocommerce_variation_option_name', 'Velika', null, 'Veličina', $parent );
			}
		);
		$this->check(
			'terms:pa_boja',
			static function () use ( $red ) {
				get_term( $red['term_id'], 'pa_boja' );
			}
		);
		$this->translate( 'post:' . $shirt, 'field/post_title', 'Majica', 'de', 'T-Shirt' );
		$this->translate( 'term:' . $red['term_id'], 'field/name', 'Crvena', 'de', 'Rot' );
		$this->translate( 'post:' . $shirt, 'attribute/velicina/options/Velika', 'Velika', 'de', 'Groß' );

		$variation = $this->product_object(
			$shirt + 1000,
			'Majica - Crvena, Velika',
			$shirt,
			array(
				'pa_boja'  => 'crvena',
				'velicina' => 'Velika',
			)
		);
		// An item of an order, as WooCommerce's: its name as the order keeps it, and its product.
		$item   = static function ( $name, $product ) {
			return new class( $name, $product ) {
				/**
				 * Name and product.
				 *
				 * @var array
				 */
				private $data;

				/**
				 * Keeps them.
				 *
				 * @param string $name    Name.
				 * @param object $product Product.
				 */
				public function __construct( $name, $product ) {
					$this->data = array( $name, $product );
				}

				/**
				 * Name as the order keeps it.
				 */
				public function get_name() {
					return $this->data[0];
				}

				/**
				 * The product or variation.
				 */
				public function get_product() {
					return $this->data[1];
				}
			};
		};
		$bought = $item( 'Majica - Crvena, Velika', $variation );
		$link   = static function ( $name ) {
			return '<a href="http://example.org/de/product/t-shirt/">' . $name . '</a>';
		};
		// The lines under a name, as WooCommerce lists them for the variation.
		$lines = static function ( $of ) {
			return apply_filters(
				'woocommerce_order_item_get_formatted_meta_data',
				array(
					7 => (object) array(
						'key'           => 'pa_boja',
						'value'         => 'crvena',
						'display_key'   => 'Farbe',
						'display_value' => "<p>Rot</p>\n",
					),
					8 => (object) array(
						'key'           => 'Poruka',
						'value'         => 'Sretan rođendan',
						'display_key'   => 'Poruka',
						'display_value' => "<p>Sretan rođendan</p>\n",
					),
				),
				$of
			);
		};

		$this->assertSame( $link( 'Majica - Crvena, Velika' ), apply_filters( 'woocommerce_order_item_name', $link( 'Majica - Crvena, Velika' ), $bought, true ), 'In the original' );
		$this->show_in( 'de' );
		$this->assertSame( $link( 'T-Shirt - Rot, Groß' ), apply_filters( 'woocommerce_order_item_name', $link( 'Majica - Crvena, Velika' ), $bought, true ) );
		$this->assertSame( 'T-Shirt', apply_filters( 'woocommerce_order_item_name', 'Majica', $item( 'Majica', $parent ), false ), 'A product' );
		$this->assertSame( 'Stara majica', apply_filters( 'woocommerce_order_item_name', 'Stara majica', $item( 'Stara majica', $parent ), false ), 'A name the product no longer has' );
		$this->assertSame( 'T-Shirt - Rot, Groß', $shop->filterProductName( 'Majica - Crvena, Velika', $variation ), 'The classic cart names it so too' );

		$this->assertSame( array( 8 ), array_keys( $lines( $bought ) ), 'Rot is in the name already' );
		$this->assertSame( array( 7, 8 ), array_keys( $lines( $item( 'Majica', $variation ) ) ), 'A name without its values lists them' );
		$this->assertSame(
			'Groß',
			apply_filters(
				'woocommerce_order_item_display_meta_value',
				'Velika',
				(object) array(
					'key'   => 'velicina',
					'value' => 'Velika',
				),
				$bought
			)
		);
		$this->assertSame(
			'Sretan rođendan',
			apply_filters(
				'woocommerce_order_item_display_meta_value',
				'Sretan rođendan',
				(object) array(
					'key'   => 'Poruka',
					'value' => 'Sretan rođendan',
				),
				$bought
			),
			'Not an attribute'
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * The cart and checkout blocks name an item with its title, through the plugin's Store API: a
	 * variation's title is its product's title as stored, past the name the plugin translates, so a
	 * variation in the cart read in the original beside a translated product. A product's title is
	 * its name, already translated; a title that is not its product's as stored stays.
	 */
	public function test_variation_title_in_the_cart() {
		$this->shop();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$shirt = self::factory()->post->create(
			array(
				'post_type'  => 'product',
				'post_title' => 'Majica',
			)
		);
		$this->check(
			'post:' . $shirt,
			static function () use ( $shirt ) {
				get_the_title( $shirt );
			}
		);
		$this->translate( 'post:' . $shirt, 'field/post_title', 'Majica', 'de', 'T-Shirt' );
		$shop = new WooCommerce();
		$shop->register();
		$variation = $this->product_object( $shirt + 1000, 'Majica - Crvena', $shirt, array( 'pa_boja' => 'crvena' ) );
		$product   = $this->product_object( $shirt, 'Majica' );
		// As WC_Product_Variation::get_title() and WC_Product::get_title() name an item for the Store API.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		$title = static function ( $stored, $item ) {
			return apply_filters( 'woocommerce_product_title', $stored, $item );
		};

		$this->assertSame( 'Majica', $title( 'Majica', $variation ), 'In the original' );
		$this->show_in( 'de' );
		$this->assertSame( 'T-Shirt', $title( 'Majica', $variation ) );
		$this->assertSame( 'T-Shirt', $title( 'T-Shirt', $product ), 'A product\'s title is its name, translated already' );
		$this->assertSame( 'Druga majica', $title( 'Druga majica', $variation ), 'Not its product\'s title as stored' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * WooCommerce builds a variation's title from its product's title and the names of its terms
	 * each time it reads the variation, and stores it when it differs from the stored one. On a page
	 * in a language the names of the terms read translated, so the title it builds must still be the
	 * one of the original: else the stored title follows the language of the last page that read the
	 * variation, and an order takes whichever it was. The name shown is translated where it is shown,
	 * see filterProductName().
	 */
	public function test_variation_title_is_built_in_the_original() {
		$this->shop();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$shirt = self::factory()->post->create(
			array(
				'post_type'  => 'product',
				'post_title' => 'Majica',
			)
		);
		$red   = wp_insert_term( 'Crvena', 'pa_boja', array( 'slug' => 'crvena' ) );
		$this->check(
			'terms:pa_boja',
			static function () use ( $red ) {
				get_term( $red['term_id'], 'pa_boja' );
			}
		);
		$this->translate( 'term:' . $red['term_id'], 'field/name', 'Crvena', 'de', 'Rot' );
		$shop = new WooCommerce();
		$shop->register();
		$variation = $this->product_object( $shirt + 1000, 'Majica - Crvena', $shirt, array( 'pa_boja' => 'crvena' ) );
		// As WooCommerce's variation data store builds the title: the product's title as stored, and
		// the names of the variation's terms as they read on the page.
		$generate = static function () use ( $shirt, $variation ) {
			$base   = get_post_field( 'post_title', $shirt );
			$suffix = get_term_by( 'slug', 'crvena', 'pa_boja' )->name;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
			return apply_filters( 'woocommerce_product_variation_title', $base . ' - ' . $suffix, $variation, $base, $suffix );
		};

		$this->assertSame( 'Majica - Crvena', $generate(), 'In the original' );
		$this->show_in( 'de' );
		$this->assertSame( 'Rot', get_term_by( 'slug', 'crvena', 'pa_boja' )->name, 'The term reads translated on the page' );
		$this->assertSame( 'Majica - Crvena', $generate(), 'The title WooCommerce stores stays in the original' );
		$this->assertSame( 'Majica - Plava', apply_filters( 'woocommerce_product_variation_title', 'Majica - Plava', $this->product_object( 5, 'Majica - Plava', 99999 ), 'Majica', 'Plava' ), 'A title not built from its product stays' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		$this->assertSame( 'Majica - Rot', $shop->filterProductName( 'Majica - Crvena', $variation ), 'Shown translated still' );

		// A name with what a pattern's replacement would read as a reference is put in as written.
		$this->reset_state();
		$set = wp_insert_term( 'Komplet $1', 'pa_boja', array( 'slug' => 'komplet' ) );
		$this->check(
			'terms:pa_boja',
			static function () use ( $set ) {
				get_term( $set['term_id'], 'pa_boja' );
			}
		);
		$this->translate( 'term:' . $set['term_id'], 'field/name', 'Komplet $1', 'de', 'Set \\0 $1' );
		$this->show_in( 'de' );
		$kit = $this->product_object( $shirt + 1001, 'Majica - Komplet $1', $shirt, array( 'pa_boja' => 'komplet' ) );
		$this->assertSame( 'Majica - Set \\0 $1', $shop->filterProductName( 'Majica - Komplet $1', $kit ) );
		$this->assertSame( 'Majica - Komplet $1', apply_filters( 'woocommerce_product_variation_title', 'Majica - Set \\0 $1', $kit, 'Majica', 'Set \\0 $1' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
	}

	/**
	 * An attribute of one product alone, as "Veličina" with "Mala | Velika": its values are the keys
	 * of the product's variations, so they stay as stored wherever the plugin reads them, also with a
	 * translation saved for them before; else on a page in a language no option matches a variation
	 * and the product cannot be bought. Its name and each value are texts of the product of their
	 * own, translated where the plugin shows them: the variation form, the cart and the checkout
	 * (which name the attribute oddly, as pa_attribute_velicina), the table of the product's
	 * attributes.
	 */
	public function test_attributes_of_a_product_alone() {
		$shop = $this->shop();
		$shop->register();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$pot        = self::factory()->post->create(
			array(
				'post_type'  => 'product',
				'post_title' => 'Lonac',
			)
		);
		$attributes = array(
			'velicina' => array(
				'name'         => 'Veličina',
				'value'        => 'Mala | Velika',
				'position'     => 0,
				'is_visible'   => 1,
				'is_variation' => 1,
				'is_taxonomy'  => 0,
			),
		);
		update_post_meta( $pot, '_product_attributes', $attributes );
		$variable  = $this->product_object( $pot, 'Lonac' );
		$variation = $this->product_object( $pot + 1000, 'Lonac', $pot, array( 'velicina' => 'Velika' ) );
		// As WooCommerce's attribute of a product, in the table of the product's attributes.
		$attribute = new class() {
			/**
			 * Whether it is a list of terms.
			 */
			public function is_taxonomy() {
				return false;
			}

			/**
			 * Its values, as stored.
			 */
			public function get_options() {
				return array( 'Mala', 'Velika' );
			}
		};
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters, as it applies them.
		$table  = static function () use ( $attribute ) {
			return apply_filters( 'woocommerce_attribute', wpautop( wptexturize( 'Mala, Velika' ) ), $attribute, array( 'Mala', 'Velika' ) );
		};
		$render = static function () use ( $pot, $variable, $table ) {
			$GLOBALS['post'] = get_post( $pot ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The product's page sets it.
			// The plugin reads the product, then prints its variation form and its attributes.
			get_post_meta( $pot, '_product_attributes', true );
			apply_filters( 'woocommerce_attribute_label', 'Veličina', 'Veličina', $variable );
			apply_filters( 'woocommerce_variation_option_name', 'Mala', null, 'Veličina', $variable );
			apply_filters( 'woocommerce_variation_option_name', 'Velika', null, 'Veličina', $variable );
			apply_filters( 'woocommerce_attribute_label', 'Veličina', 'Veličina', '' );
			$table();
		};

		$paths = array();
		foreach ( $this->probe( $render ) as $leaf ) {
			$paths[] = $leaf['o'] . '|' . $leaf['p'];
		}
		$owner = 'post:' . $pot;
		$this->assertContains( $owner . '|attribute/velicina/name', $paths );
		$this->assertContains( $owner . '|attribute/velicina/options/Mala', $paths );
		$this->assertContains( $owner . '|attribute/velicina/options/Velika', $paths );
		$this->assertEmpty( preg_grep( '~\|meta/_product_attributes~', $paths ), 'The stored values are no text' );
		$this->assertSame( 'Name of the attribute', Registry::fieldLabel( $owner, 'attribute/velicina/name' ) );
		$this->assertSame( 'Value of the attribute', Registry::fieldLabel( $owner, 'attribute/velicina/options/Mala' ) );

		$this->check( $owner, $render );
		$this->translate( $owner, 'attribute/velicina/name', 'Veličina', 'de', 'Größe' );
		$this->translate( $owner, 'attribute/velicina/options/Mala', 'Mala', 'de', 'Klein' );
		$this->translate( $owner, 'attribute/velicina/options/Velika', 'Velika', 'de', 'Groß' );
		// A translation of the stored values, as the editor offered them before.
		$this->translate( $owner, 'meta/_product_attributes/velicina/value', 'Mala | Velika', 'de', 'Klein | Groß' );
		unset( $GLOBALS['post'] );

		$this->show_in( 'de' );
		$this->assertSame( 'Mala | Velika', get_post_meta( $pot, '_product_attributes', true )['velicina']['value'], 'The keys of the variations stay' );
		$this->assertSame( 'Größe', apply_filters( 'woocommerce_attribute_label', 'Veličina', 'Veličina', $variable ) );
		$this->assertSame( 'Groß', apply_filters( 'woocommerce_variation_option_name', 'Velika', null, 'Veličina', $variable ) );
		// The cart and the checkout, with the variation in the cart.
		$this->assertSame( 'Groß', apply_filters( 'woocommerce_variation_option_name', 'Velika', null, 'pa_attribute_velicina', $variation ) );
		$this->assertSame( 'Größe', apply_filters( 'woocommerce_attribute_label', 'Veličina', 'velicina', $variable ) );
		$this->assertSame( 'Ogromna', apply_filters( 'woocommerce_variation_option_name', 'Ogromna', null, 'Veličina', $variable ), 'No value of the product' );
		$term = get_term( wp_insert_term( 'Crvena', 'pa_boja' )['term_id'], 'pa_boja' );
		$this->assertSame( 'Crvena', apply_filters( 'woocommerce_variation_option_name', 'Crvena', $term, 'pa_boja', $variable ), 'A term is translated as a term' );
		$GLOBALS['post'] = get_post( $pot ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The product's page sets it.
		$this->assertSame( "<p>Klein, Groß</p>\n", $table() );
		$this->assertSame( 'Größe', apply_filters( 'woocommerce_attribute_label', 'Veličina', 'Veličina', '' ), 'The table names it without the product' );
		unset( $GLOBALS['post'] );

		$this->reset_state();
		$this->assertSame( 'Velika', apply_filters( 'woocommerce_variation_option_name', 'Velika', null, 'Veličina', $variable ), 'In the original' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Where the plugin reads a product's description and short description itself, as its REST API
	 * and its Store API do, past the_content and get_the_excerpt, they are in the language too: the
	 * blocks of a description each in its place, a description without blocks as one text. In the
	 * original, and for a text that is not the product's as stored, they stay as they are.
	 */
	public function test_descriptions_read_by_the_plugin() {
		$shop = $this->shop();
		$shop->register();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$classic = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'product',
				'post_content' => '<p>Opis auta.</p>',
				'post_excerpt' => 'Kratki opis auta.',
			)
		);
		$blocks  = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'product',
				'post_content' => "<!-- wp:paragraph -->\n<p>Prvi odlomak.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Drugi odlomak.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$words   = array(
			'Opis auta.'        => 'Beschreibung des Autos.',
			'Kratki opis auta.' => 'Kurze Beschreibung.',
			'Prvi odlomak.'     => 'Erster Absatz.',
			'Drugi odlomak.'    => 'Zweiter Absatz.',
		);
		foreach ( array( $classic, $blocks ) as $product ) {
			$render = static function () use ( $product ) {
				$GLOBALS['post'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The product's page sets it.
				setup_postdata( $product );
				// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's and the plugin's own filters, as the product's page runs them.
				apply_filters( 'the_content', $product->post_content );
				apply_filters( 'woocommerce_short_description', $product->post_excerpt );
				// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			};
			$owner  = 'post:' . $product->ID;
			$leaves = $this->probe( $render );
			$this->check( $owner, $render );
			foreach ( $leaves as $leaf ) {
				foreach ( $words as $original => $translation ) {
					if ( $owner === $leaf['o'] && false !== strpos( $leaf['v'], $original ) ) {
						$this->translate( $owner, $leaf['p'], $leaf['v'], 'de', str_replace( $original, $translation, $leaf['v'] ) );
					}
				}
			}
		}
		unset( $GLOBALS['post'] );

		// A product, as the plugin hands it to its filters.
		$product_of = static function ( $id ) {
			return new class( $id ) {
				/**
				 * Post ID.
				 *
				 * @var int
				 */
				private $id;

				/**
				 * Keeps the product's ID.
				 *
				 * @param int $id Post ID.
				 */
				public function __construct( $id ) {
					$this->id = $id;
				}

				/**
				 * Post ID.
				 */
				public function get_id() {
					return $this->id;
				}
			};
		};
		// As WC_Data::get_prop() reads a product's data for the shop's pages and APIs.
		$read = static function ( $prop, $post ) use ( $product_of ) {
			$stored = 'description' === $prop ? $post->post_content : $post->post_excerpt;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
			return apply_filters( 'woocommerce_product_get_' . $prop, $stored, $product_of( $post->ID ) );
		};

		$this->assertSame( '<p>Opis auta.</p>', $read( 'description', $classic ), 'In the original' );
		$this->show_in( 'de' );
		$this->assertSame( '<p>Beschreibung des Autos.</p>', $read( 'description', $classic ) );
		$this->assertSame( 'Kurze Beschreibung.', $read( 'short_description', $classic ) );
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Erster Absatz.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Zweiter Absatz.</p>\n<!-- /wp:paragraph -->",
			$read( 'description', $blocks )
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		$this->assertSame( 'Drugačiji opis.', apply_filters( 'woocommerce_product_get_description', 'Drugačiji opis.', $product_of( $classic->ID ) ), 'Not as stored' );
	}

	/**
	 * The plugin's Store API, which its cart and checkout blocks use, is answered in the language of
	 * the page it is asked from.
	 */
	public function test_store_api_in_the_page_language() {
		$this->assertTrue( (bool) preg_match( ( new WooCommerce() )->sendingRoutes()[0], '/wc/store/v1/cart/update-item' ) );
		$this->assertTrue( (bool) preg_match( ( new WooCommerce() )->sendingRoutes()[0], '/wc/store/batch' ) );
		$this->assertFalse( (bool) preg_match( ( new WooCommerce() )->sendingRoutes()[0], '/wc/v3/products' ), 'The plugin\'s API for shops\' own systems stays in the original' );
	}
}
