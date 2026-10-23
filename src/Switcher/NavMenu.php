<?php
/**
 * The switcher in menus.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * The switcher in the menus chosen on the Switcher screen, as their first or last items: one item
 * for the language being shown with the others as its submenu, a globe that leads nowhere with the
 * other languages as its submenu, or one item per language.
 *
 * The items are added while the menu renders and are never saved, so the menu screens stay as
 * they are and a new language shows up in the menu without editing it. The theme draws them like
 * its other items, submenu included.
 *
 * Their ID is 0, as for items that are not saved: the_title then gives the probe no post to read
 * the language names from, so they are never offered for translation.
 */
final class NavMenu {
	/**
	 * Hooks the items into menus.
	 */
	public function register(): void {
		add_filter( 'wp_nav_menu_objects', array( $this, 'addItems' ), 10, 2 );
		add_filter( 'nav_menu_item_id', array( $this, 'itemId' ), 10, 2 );
		add_filter( 'nav_menu_css_class', array( $this, 'itemClasses' ), 10, 2 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'linkAttributes' ), 10, 2 );
	}

	/**
	 * Adds the languages to a menu chosen on the Switcher screen.
	 *
	 * @param array<int, \WP_Post> $items Menu items, sorted.
	 * @param \stdClass            $args  Arguments of wp_nav_menu().
	 * @return array<int, \WP_Post>
	 */
	public function addItems( $items, $args ) {
		$settings = Settings::get()['menu'];
		if ( ! $settings['enabled'] || ! $settings['menus'] || ! is_array( $items ) || ! is_object( $args ) || empty( $args->menu ) ) {
			return $items;
		}
		// wp_nav_menu() hands over the menu it found by location; a menu asked for by ID, slug or name stays as asked.
		$menu = $args->menu instanceof \WP_Term ? $args->menu : wp_get_nav_menu_object( $args->menu );
		if ( ! $menu || ! in_array( (int) $menu->term_id, $settings['menus'], true ) ) {
			return $items;
		}

		$languages = Switcher::languages();
		if ( count( $languages ) < 2 ) {
			return $items;
		}
		wp_enqueue_style( 'swiftling-switcher' );

		$show  = Switcher::args( array( 'place' => 'menu' ) );
		$added = array();
		// A menu printed one level deep has no submenus, so there the languages stand side by side.
		if ( 'globe' === $settings['style'] && 1 !== (int) $args->depth ) {
			wp_enqueue_script( 'swiftling-switcher' );
			$current = wp_list_filter( $languages, array( 'current' => true ) );
			$parent  = $this->globe( $current ? reset( $current ) : $languages[0], $show );
			$added[] = $parent;
			foreach ( wp_list_filter( $languages, array( 'current' => false ) ) as $language ) {
				$added[] = $this->item( $language, $show, $parent->db_id );
			}
		} elseif ( 'dropdown' === $settings['style'] && 1 !== (int) $args->depth ) {
			$current = wp_list_filter( $languages, array( 'current' => true ) );
			$current = $current ? reset( $current ) : $languages[0];
			$parent  = $this->item( $current, $show, '' );

			$parent->classes[] = 'menu-item-has-children';
			$added[]           = $parent;
			foreach ( wp_list_filter( $languages, array( 'current' => false ) ) as $language ) {
				$added[] = $this->item( $language, $show, $parent->db_id );
			}
		} else {
			foreach ( $languages as $language ) {
				if ( ! $language['current'] || $show['current'] ) {
					$added[] = $this->item( $language, $show, '' );
				}
			}
		}

		return 'first' === $settings['position'] ? array_merge( $added, $items ) : array_merge( $items, $added );
	}

	/**
	 * Returns a menu item for a language.
	 *
	 * @param array<string, mixed> $language  Language from Switcher::languages().
	 * @param array<string, mixed> $show      Options from Switcher::args().
	 * @param string               $parent_id db_id of the item it hangs under, or empty at the top.
	 */
	private function item( array $language, array $show, string $parent_id ): \WP_Post {
		$key     = '' !== $language['slug'] ? $language['slug'] : 'original';
		$classes = array( 'menu-item', 'swiftling-menu-language', 'swiftling-menu-language-' . $key );
		if ( $language['current'] ) {
			$classes[] = 'swiftling-menu-language-current';
		}
		if ( $language['hidden'] ) {
			$classes[] = 'is-hidden-language';
		}
		// Its submenu is then as narrow as a flag, whatever width the theme gives submenus.
		if ( Switcher::flagOnly( $show ) ) {
			$classes[] = 'swiftling-menu-language-flag-only';
		}

		return new \WP_Post(
			(object) array(
				'ID'                    => 0,
				'db_id'                 => 'swiftling-' . $key,
				'menu_item_parent'      => $parent_id,
				'object_id'             => 0,
				'object'                => 'swiftling_language',
				'type'                  => 'swiftling_language',
				'type_label'            => __( 'Language', 'swiftling' ),
				'post_type'             => 'nav_menu_item',
				'post_status'           => 'publish',
				'post_title'            => $language['name'],
				// In a span of its own: themes style menu links, and the flag must stay beside the name.
				'title'                 => '<span class="swiftling-switcher__label">' . Switcher::label( $language, $show ) . '</span>',
				'url'                   => $language['url'],
				'target'                => '',
				'attr_title'            => $language['hidden'] ? __( 'Hidden from visitors. You see it because you can edit content.', 'swiftling' ) : '',
				'description'           => '',
				'classes'               => $classes,
				'xfn'                   => '',
				'current'               => false,
				'current_item_ancestor' => false,
				'current_item_parent'   => false,
				'menu_order'            => 0,
				'swiftling_language'    => $language,
			)
		);
	}

	/**
	 * Returns the globe: an item that leads nowhere and opens the other languages, its submenu.
	 * Beside it, the code of the language shown, where the switcher shows codes.
	 *
	 * @param array<string, mixed> $current Language shown, from Switcher::languages().
	 * @param array<string, mixed> $show    Options from Switcher::args().
	 */
	private function globe( array $current, array $show ): \WP_Post {
		$globe             = $this->item( $current, $show, '' );
		$globe->db_id      = 'swiftling-globe';
		$globe->url        = '#';
		$globe->title      = '<span class="swiftling-switcher__label">' . Switcher::globe()
			. ( $show['code'] ? '<span class="swiftling-switcher__code" lang="' . esc_attr( (string) $current['tag'] ) . '">' . esc_html( (string) $current['code'] ) . '</span>' : '' )
			. '</span>';
		$globe->attr_title = '';
		$globe->classes    = array( 'menu-item', 'swiftling-menu-language', 'swiftling-menu-language-globe', 'menu-item-has-children' );
		if ( Switcher::flagOnly( $show ) ) {
			$globe->classes[] = 'swiftling-menu-language-flag-only';
		}
		$globe->swiftling_globe = true;
		return $globe;
	}

	/**
	 * Gives a language item an HTML ID of its own; items that are not saved would all be menu-item-0.
	 *
	 * @param string   $id   HTML ID of the item.
	 * @param \WP_Post $item Menu item.
	 * @return string
	 */
	public function itemId( $id, $item ) {
		return isset( $item->swiftling_language ) ? 'menu-item-' . $item->db_id : $id;
	}

	/**
	 * Leaves out the class every item gets from its ID, which would be menu-item-0.
	 *
	 * @param string[] $classes Classes of the item.
	 * @param \WP_Post $item    Menu item.
	 * @return string[]
	 */
	public function itemClasses( $classes, $item ) {
		return isset( $item->swiftling_language ) ? array_values( array_diff( (array) $classes, array( 'menu-item-0' ) ) ) : $classes;
	}

	/**
	 * Keeps the label of a language item out of translation: language names stay in their own
	 * language. Search engines learn the languages of a page from the head, see hreflang.php.
	 *
	 * @param array<string, string> $atts Attributes of the link.
	 * @param \WP_Post              $item Menu item.
	 * @return array<string, string>
	 */
	public function linkAttributes( $atts, $item ) {
		if ( ! isset( $item->swiftling_language ) ) {
			return $atts;
		}
		// The name in the label carries its own lang, as it may be in the language of the page.
		$atts['translate'] = 'no';
		// The globe opens the languages, as a button does; assets/js/switcher.js keeps it from leading anywhere.
		if ( ! empty( $item->swiftling_globe ) ) {
			$atts['role']          = 'button';
			$atts['aria-haspopup'] = 'true';
			$atts['aria-expanded'] = 'false';
			$atts['aria-label']    = __( 'Choose a language', 'swiftling' );
		}
		return $atts;
	}
}
