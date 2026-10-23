<?php
/**
 * Classic menus, the ones made under Appearance → Menus, translated on a screen of their own.
 *
 * A menu is a subject of the translation editor ("menu:3"), listed on the server like the terms of
 * a taxonomy: no page check, so a menu shows up also when no example page shows it. Nothing is
 * stored with the menu itself; each text belongs to the object a visitor reads it from:
 *
 * - an item's own label and its tooltip (title attribute) belong to the item ("post:<item>");
 * - an item without a label of its own shows the title of its page. Its translation in the editor of
 *   the menu belongs to the item too, so it shows in this menu only and wins over the page's
 *   translated title; without one the menu shows the page's translated title, see
 *   swiftling_filter_menu_item(). Such a text counts as translated when either is, see
 *   swiftling_menu_fallbacks();
 * - an item of a category shows the category's name ("term:<id>"), as in Categories & tags.
 *
 * The order of a menu's items and its submenus are the original's in every language. Which items
 * each language shows can differ, see includes/menu-languages.php. The address of a custom link gets
 * the language by itself, see
 * swiftling_localize_menu_items(); a link to a page follows the page's address in the language.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the classic menus of the site, in the order WordPress lists them.
 *
 * @return WP_Term[]
 */
function swiftling_menus() {
	return array_values(
		array_filter(
			wp_get_nav_menus(),
			static function ( $menu ) {
				return $menu instanceof WP_Term;
			}
		)
	);
}

/**
 * Returns where the theme shows each menu: the names of the places the theme registered, such as
 * "Primary Menu", that the menu is assigned to.
 *
 * @return array<int, string[]> Menu term ID => names of its places.
 */
function swiftling_menu_locations() {
	$names  = get_registered_nav_menus();
	$placed = array();
	foreach ( get_nav_menu_locations() as $location => $menu_id ) {
		if ( $menu_id && isset( $names[ $location ] ) ) {
			$placed[ (int) $menu_id ][] = (string) $names[ $location ];
		}
	}
	return $placed;
}

/**
 * Returns the items of a menu that visitors see, in the menu's order, each with its depth: 0 for
 * the top level, 1 for a submenu item and so on. Items whose page or category is gone, which
 * WordPress leaves out of the menu on the site, are left out here too.
 *
 * @param int $menu_id Menu term ID.
 * @return WP_Post[] Menu items as wp_setup_nav_menu_item() sets them up, with a depth property.
 */
function swiftling_menu_items( $menu_id ) {
	$items = wp_get_nav_menu_items( (int) $menu_id );
	if ( ! is_array( $items ) ) {
		return array();
	}

	$depths = array();
	$listed = array();
	foreach ( $items as $item ) {
		if ( ! empty( $item->_invalid ) ) {
			continue;
		}
		$parent = (int) $item->menu_item_parent;
		// An item under an item that is left out moves up to the level of that item.
		$item->depth = $parent && isset( $depths[ $parent ] ) ? $depths[ $parent ] + 1 : 0;

		$depths[ (int) $item->ID ] = $item->depth;
		$listed[]                  = $item;
	}
	return $listed;
}

/**
 * Returns the subject of the editor for a menu.
 *
 * @param int $menu_id Menu term ID.
 * @return array|null Subject, see swiftling_subject(); null when there is no such menu.
 */
function swiftling_menu_subject( $menu_id ) {
	$menu = wp_get_nav_menu_object( (int) $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		return null;
	}
	$places = swiftling_menu_locations();
	$places = isset( $places[ $menu->term_id ] ) ? $places[ $menu->term_id ] : array();
	return array(
		'key'   => 'menu:' . $menu->term_id,
		'kind'  => 'menu',
		'title' => $menu->name,
		'type'  => $places
			/* translators: %s: places of the theme, e.g. Primary Menu and Footer Menu. */
			? sprintf( __( 'Menu · shown in %s', 'swiftling' ), wp_sprintf( '%l', $places ) )
			: __( 'Menu · not placed in the theme', 'swiftling' ),
		// The home page shows a menu the theme places, so the editor can open it in the language.
		'url'   => $places ? home_url( '/' ) : '',
		'probe' => false,
		'post'  => null,
		'cap'   => 'edit_theme_options',
	);
}

/**
 * Returns the texts of a menu's items, in the menu's order, as swiftling_save_listed_texts() takes
 * them, and what the editor shows between them without a field: SwiftLing's language switcher and
 * items whose name comes from the code of a theme or plugin.
 *
 * @param WP_Term $menu Menu.
 * @return array{texts: array, info: array<int, array[]>} Texts; and rows without a field, by the
 *         number of texts before them.
 */
function swiftling_menu_texts( $menu ) {
	$texts = array();
	$info  = array();
	$terms = swiftling_term_taxonomies();

	foreach ( swiftling_menu_items( $menu->term_id ) as $item ) {
		$owner = 'post:' . $item->ID;
		$own   = '' !== trim( $item->post_title );
		// What the editor says under the field, see swiftling_menu_row_note().
		$base = array(
			'kind'    => 'text',
			'item'    => (int) $item->ID,
			'depth'   => (int) $item->depth,
			'page'    => 'post_type' === $item->type ? (int) $item->object_id : 0,
			'address' => 'custom' === $item->type ? (string) $item->url : '',
		);

		if ( $own ) {
			$texts[] = $base + array(
				'o'     => $owner,
				'p'     => 'field/post_title',
				'v'     => $item->post_title,
				'r'     => 'field:post_title||',
				'label' => __( 'Menu label', 'swiftling' ),
				'place' => swiftling_menu_item_target( $item ),
				'about' => 'label',
			);
		} elseif ( 'post_type' === $item->type ) {
			$page = swiftling_menu_item_page( $item );
			if ( $page ) {
				// The page's title, as the label of this item only: the item's own text.
				$texts[] = $base + array(
					'o'       => $owner,
					'p'       => 'field/post_title',
					'v'       => $page->post_title,
					'r'       => 'field:post_title||',
					'label'   => __( 'Page title', 'swiftling' ),
					'place'   => '',
					'about'   => 'title',
					'follows' => true,
				);
			}
		} elseif ( 'taxonomy' === $item->type ) {
			$term = get_term( (int) $item->object_id, $item->object );
			if ( $term instanceof WP_Term && '' !== trim( $term->name ) ) {
				$taxonomy = get_taxonomy( $term->taxonomy );
				$texts[]  = $base + array(
					'o'     => 'term:' . $term->term_id,
					'p'     => 'field/name',
					'v'     => $term->name,
					'r'     => 'term:name||',
					'label' => __( 'Name', 'swiftling' ),
					'place' => $taxonomy ? $taxonomy->labels->singular_name : '',
					'about' => 'term',
					'list'  => isset( $terms[ $term->taxonomy ] ) ? 'terms:' . $term->taxonomy : '',
				);
			}
		} else {
			// The archive of a kind of content: its name comes from the code that registers it.
			$info[ count( $texts ) ][] = array(
				'title' => __( 'Archive', 'swiftling' ),
				'text'  => $item->title,
				'note'  => __( 'Its name comes from the code of the theme or plugin, and is translated with the texts of that theme or plugin.', 'swiftling' ),
				'link'  => array( __( 'Themes & plugins', 'swiftling' ), admin_url( 'admin.php?page=swiftling-themes-plugins' ) ),
				'depth' => (int) $item->depth,
				'item'  => (int) $item->ID,
			);
		}

		if ( '' !== trim( (string) $item->attr_title ) ) {
			$texts[] = $base + array(
				'o'     => $owner,
				'p'     => 'field/post_excerpt',
				'v'     => (string) $item->attr_title,
				'r'     => 'field:post_excerpt||',
				'label' => __( 'Tooltip', 'swiftling' ),
				'place' => __( 'Title attribute', 'swiftling' ),
				'about' => 'tooltip',
			);
		}
	}

	$switcher = \SwiftLing\Switcher\Settings::get()['menu'];
	if ( $switcher['enabled'] && in_array( (int) $menu->term_id, $switcher['menus'], true ) ) {
		$row = array(
			'title' => __( 'Language switcher', 'swiftling' ),
			'text'  => implode( ' · ', wp_list_pluck( array_merge( array( swiftling_languages()['source'] ), array_values( swiftling_languages()['targets'] ) ), 'name' ) ),
			'note'  => __( 'Added by SwiftLing. Each language shows in its own name, so there is nothing to translate.', 'swiftling' ),
			'link'  => array( __( 'Switcher', 'swiftling' ), admin_url( 'admin.php?page=swiftling-switcher' ) ),
			'depth' => 0,
		);
		if ( 'first' === $switcher['position'] ) {
			$info[0] = array_merge( array( $row ), isset( $info[0] ) ? $info[0] : array() );
		} else {
			$info[ count( $texts ) ][] = $row;
		}
	}

	return array(
		'texts' => $texts,
		'info'  => $info,
	);
}

/**
 * Returns the page whose title an item shows: the page of an item without a label of its own.
 *
 * @param WP_Post $item Menu item.
 * @return WP_Post|null Null for an item with a label of its own, or of no page visitors open.
 */
function swiftling_menu_item_page( $item ) {
	if ( '' !== trim( $item->post_title ) || 'post_type' !== $item->type ) {
		return null;
	}
	$page = get_post( (int) $item->object_id );
	return $page && '' !== trim( $page->post_title ) && is_post_type_viewable( $page->post_type ) ? $page : null;
}

/**
 * Returns the place of a page's translated title, as the page's own editor stores it.
 *
 * @param WP_Post $page Page.
 * @return string Place key.
 */
function swiftling_menu_title_place( $page ) {
	return swiftling_place_key( 'post:' . $page->ID, swiftling_path_key( 'field/post_title' ), swiftling_hash( $page->post_title ) );
}

/**
 * Returns where the texts of a menu fall back to: for an item that shows its page's title, the
 * page's translated title, which visitors read while the menu has no label of its own for the item.
 * Such a text counts as translated when either is, see swiftling_units_state().
 *
 * @param string $key Subject key "menu:<id>".
 * @return array<string, string> Map key of the text in the menu's map => place of the page's title.
 */
function swiftling_menu_fallbacks( $key ) {
	$fallbacks = array();
	foreach ( swiftling_menu_items( (int) substr( $key, 5 ) ) as $item ) {
		$page = swiftling_menu_item_page( $item );
		if ( $page ) {
			$fallbacks[ swiftling_shared_key( 'post:' . $item->ID, swiftling_path_key( 'field/post_title' ) ) ] = swiftling_menu_title_place( $page );
		}
	}
	return $fallbacks;
}

/**
 * Returns what visitors read in a language for an item that shows its page's title while the menu
 * has no label of its own for it: the page's translated title and its state.
 *
 * @param array  $unit Text of the menu, see swiftling_menu_texts().
 * @param string $lang Language slug.
 * @return array{text: string, state: string} The page's translated title, empty with state missing
 *         when the page's title is not translated.
 */
function swiftling_menu_row_fallback( $unit, $lang ) {
	$language = swiftling_target_language( $lang );
	$page     = ! empty( $unit['page'] ) ? get_post( (int) $unit['page'] ) : null;
	if ( ! $language || ! $page ) {
		return array(
			'text'  => '',
			'state' => 'missing',
		);
	}
	$place = swiftling_menu_title_place( $page );
	$found = swiftling_get_translations( $language['locale'], array( $place ) );
	return isset( $found[ $place ] ) ? array(
		'text'  => (string) $found[ $place ]['text'],
		'state' => swiftling_status_state( $found[ $place ]['status'] ),
	) : array(
		'text'  => '',
		'state' => 'missing',
	);
}

/**
 * Returns the label a menu gives an item that shows its page's title, in the language of the
 * request: the item's own translation of the page's title as it is now. Null when there is none,
 * also when the page's title changed since: then the page's translated title shows.
 *
 * @param int    $item_id Menu item ID.
 * @param string $title   The page's title in the original.
 * @return string|null
 */
function swiftling_menu_own_title( $item_id, $title ) {
	$owner = 'post:' . (int) $item_id;
	$key   = swiftling_path_key( 'field/post_title' );
	$map   = swiftling_object_map( $owner );
	if ( ! isset( $map['keys'][ $key ] ) || swiftling_hash( $title ) !== $map['keys'][ $key ] ) {
		return null;
	}
	$translations = swiftling_object_translations( $owner );
	return isset( $translations[ $key ] ) ? swiftling_localize_value( $translations[ $key ] ) : null;
}

/**
 * Says where an item with a label of its own leads: a page, a category, or an address.
 *
 * @param WP_Post $item Menu item.
 * @return string
 */
function swiftling_menu_item_target( $item ) {
	if ( 'custom' === $item->type ) {
		return __( 'Custom link', 'swiftling' );
	}
	if ( 'post_type' === $item->type ) {
		$page = get_post( (int) $item->object_id );
		/* translators: %s: title of a page. */
		return $page ? sprintf( __( 'Leads to %s', 'swiftling' ), '' !== $page->post_title ? $page->post_title : __( 'No title', 'swiftling' ) ) : '';
	}
	if ( 'taxonomy' === $item->type ) {
		$term = get_term( (int) $item->object_id, $item->object );
		/* translators: %s: name of a category or tag. */
		return $term instanceof WP_Term ? sprintf( __( 'Leads to %s', 'swiftling' ), $term->name ) : '';
	}
	return '';
}

/**
 * Stores the texts of a menu and returns everything the editor shows, as swiftling_save_probe()
 * does for a page. A category's name joins the map of the category: Categories & tags shows the
 * same translation. A page's title is the item's own text, see swiftling_menu_texts().
 *
 * @param array $subject Subject "menu:<id>", see swiftling_menu_subject().
 * @return array{sections: array, maps: array, units: array, info: array} Like swiftling_save_probe(),
 *         and the rows without a field, see swiftling_menu_texts().
 */
function swiftling_save_menu_list( $subject ) {
	$menu = wp_get_nav_menu_object( (int) substr( $subject['key'], 5 ) );
	$list = $menu instanceof WP_Term ? swiftling_menu_texts( $menu ) : array(
		'texts' => array(),
		'info'  => array(),
	);
	return swiftling_save_listed_texts( $subject, $list['texts'] ) + array( 'info' => $list['info'] );
}

/**
 * Returns the state of some menus in every language. Each menu's texts are listed and stored
 * first, so the states follow the menu as it is now, also one never opened in the editor.
 *
 * @param WP_Term[] $menus Menus.
 * @return array<string, array<string, string>> Subject key => language slug => state.
 */
function swiftling_menu_states( $menus ) {
	$keys = array();
	foreach ( $menus as $menu ) {
		$subject = swiftling_menu_subject( $menu->term_id );
		if ( $subject ) {
			swiftling_save_menu_list( $subject );
			$keys[] = $subject['key'];
		}
	}
	return $keys ? swiftling_object_states( $keys ) : array();
}

/**
 * Returns what the editor of a menu says under the field of a text, in the language it shows: whose
 * text it is, where a link leads, and why visitors may not see the item yet. What a label of a
 * page's title does is said once, above the rows, see swiftling_live_rows_html().
 *
 * @param array  $unit Text of the menu, see swiftling_menu_texts().
 * @param string $lang Language slug the editor shows.
 * @return array{text: string, links: array}|null Note, with links as array( label, url ).
 */
function swiftling_menu_row_note( $unit, $lang ) {
	$language = swiftling_target_language( $lang );
	if ( ! $language || empty( $unit['about'] ) ) {
		return null;
	}
	$page = ! empty( $unit['page'] ) ? get_post( (int) $unit['page'] ) : null;

	$texts = array();
	$links = array();
	if ( 'term' === $unit['about'] ) {
		$texts[] = __( 'The name of the category: it changes wherever the category shows.', 'swiftling' );
		if ( '' !== $unit['list'] ) {
			$links[] = array( __( 'Categories & tags', 'swiftling' ), swiftling_editor_url( $unit['list'], $lang ) );
		}
	}
	// Only addresses with a host, and paths from the root of the site, see swiftling_localize_url();
	// an anchor such as "#booking" stays on the page it is on.
	$host = ! empty( $unit['address'] ) ? wp_parse_url( $unit['address'], PHP_URL_HOST ) : null;
	if ( 'label' === $unit['about'] && ( is_string( $host ) || ( ! empty( $unit['address'] ) && 0 === strpos( $unit['address'], '/' ) && 0 !== strpos( $unit['address'], '//' ) ) ) ) {
		$texts[] = swiftling_is_own_address( $unit['address'] )
			/* translators: 1: an address, 2: language name, e.g. Deutsch. */
			? sprintf( __( 'Leads to %1$s, which opens in %2$s by itself.', 'swiftling' ), $unit['address'], $language['name'] )
			/* translators: %s: an address. */
			: sprintf( __( 'Leads to another site, %s: the same address in every language.', 'swiftling' ), $unit['address'] );
	}
	if ( in_array( $unit['about'], array( 'title', 'label', 'tooltip' ), true ) && $page && swiftling_is_hidden_post( $page, $language['locale'] ) ) {
		/* translators: %s: language name, e.g. Deutsch. */
		$texts[] = sprintf( __( 'Visitors in %s do not see this item yet: its page stays hidden until it is translated.', 'swiftling' ), $language['name'] );
	}

	return $texts ? array(
		'text'  => implode( ' ', $texts ),
		'links' => $links,
	) : null;
}

/**
 * Returns the menus with an item that shows the title of a page: an item without a label of its
 * own that leads to the page.
 *
 * @param int $post_id Page ID.
 * @return WP_Term[]
 */
function swiftling_menus_showing_title( $post_id ) {
	$items = get_posts(
		array(
			'post_type'      => 'nav_menu_item',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only when the editor of a page opens.
			'meta_query'     => array(
				array(
					'key'   => '_menu_item_object_id',
					'value' => (string) (int) $post_id,
				),
				array(
					'key'   => '_menu_item_type',
					'value' => 'post_type',
				),
			),
		)
	);
	$ids   = array();
	foreach ( $items as $item ) {
		if ( '' === trim( $item->post_title ) ) {
			$ids[] = $item->ID;
		}
	}
	if ( ! $ids ) {
		return array();
	}
	$menus = wp_get_object_terms( $ids, 'nav_menu' );
	return is_array( $menus ) ? $menus : array();
}

/**
 * Returns the note under the title of a page in its editor, naming the menus that show the title,
 * and those that show a label of their own instead in the language.
 *
 * @param int    $post_id Page ID.
 * @param string $lang    Language slug the editor shows.
 * @return array{text: string, links: array, more: string}|null Note, see swiftling_menu_row_note();
 *         more is said after the links.
 */
function swiftling_title_menus_note( $post_id, $lang ) {
	$menus = swiftling_menus_showing_title( $post_id );
	if ( ! $menus || ! swiftling_can_list( 'edit_theme_options' ) ) {
		return null;
	}
	$language = swiftling_target_language( $lang );
	$page     = get_post( $post_id );
	$follow   = array();
	$own      = array();
	foreach ( $menus as $menu ) {
		$link = array( $menu->name, swiftling_editor_url( 'menu:' . $menu->term_id, $lang ) );
		if ( $language && $page && swiftling_menu_has_own_title( $menu, $page, $language['locale'] ) ) {
			$own[] = $menu->name;
		} else {
			$follow[] = $link;
		}
	}
	$more = $own ? sprintf(
		/* translators: 1: names of menus, 2: language name, e.g. Deutsch. */
		_n( '%1$s shows a label of its own in %2$s instead.', '%1$s show labels of their own in %2$s instead.', count( $own ), 'swiftling' ),
		wp_sprintf( '%l', $own ),
		$language['name']
	) : '';
	return array(
		'text'  => $follow ? _n( 'Also the label in the menu:', 'Also the label in the menus:', count( $follow ), 'swiftling' ) : '',
		'links' => $follow,
		'more'  => $more,
	);
}

/**
 * Whether a menu gives a page's title a label of its own in a language, in an item without a label
 * of its own in the original.
 *
 * @param WP_Term $menu   Menu.
 * @param WP_Post $page   Page.
 * @param string  $locale Locale.
 * @return bool
 */
function swiftling_menu_has_own_title( $menu, $page, $locale ) {
	$places = array();
	foreach ( swiftling_menu_items( $menu->term_id ) as $item ) {
		$shown = swiftling_menu_item_page( $item );
		if ( $shown && $shown->ID === $page->ID ) {
			$places[] = swiftling_place_key( 'post:' . $item->ID, swiftling_path_key( 'field/post_title' ), swiftling_hash( $page->post_title ) );
		}
	}
	return $places && swiftling_get_translations( $locale, $places );
}

/**
 * Translates the tooltip (title attribute) of a menu item, which WordPress reads from the item's
 * excerpt without a filter of its own that knows the item. Its label goes through the_title.
 *
 * An item that shows its page's title has that title, translated as the page's, by now. A label the
 * menu gives the item in the language wins over it, see swiftling_menu_own_title(); only in
 * translate mode, so a page check still finds the page's title in the menu.
 *
 * @param object $item Menu item, as wp_setup_nav_menu_item() set it up.
 * @return object
 */
function swiftling_filter_menu_item( $item ) {
	if ( ! is_object( $item ) || empty( $item->ID ) || ! isset( $item->post_type ) || 'nav_menu_item' !== $item->post_type ) {
		return $item;
	}
	if ( isset( $item->attr_title ) && is_string( $item->attr_title ) && '' !== $item->attr_title ) {
		$item->attr_title = swiftling_transform( 'post:' . $item->ID, 'field/post_excerpt', 'field:post_excerpt', $item->attr_title );
	}
	if ( 'translate' === swiftling_mode() && isset( $item->type, $item->object_id, $item->post_title ) && 'post_type' === $item->type && '' === trim( (string) $item->post_title ) ) {
		$page = get_post( (int) $item->object_id );
		$own  = $page ? swiftling_menu_own_title( $item->ID, $page->post_title ) : null;
		if ( null !== $own ) {
			$item->title = $own;
		}
	}
	return $item;
}

/**
 * Whether an object is an item of a classic menu: its texts are translated on the Menus screen.
 *
 * @param string $owner Object key.
 * @return bool
 */
function swiftling_is_menu_item_owner( $owner ) {
	return 0 === strpos( $owner, 'post:' ) && 'nav_menu_item' === get_post_type( (int) substr( $owner, 5 ) );
}

/**
 * Returns the menus whose texts a template's page check saw, and those the theme places, which
 * every page shows: the editor of the template points to them, see swiftling_live_rows_html().
 *
 * @param array $units Texts the page check found.
 * @return WP_Term[]
 */
function swiftling_template_menus( $units ) {
	$ids = array_keys( swiftling_menu_locations() );
	foreach ( $units as $unit ) {
		if ( swiftling_is_menu_item_owner( $unit['o'] ) ) {
			foreach ( wp_get_object_terms( (int) substr( $unit['o'], 5 ), 'nav_menu', array( 'fields' => 'ids' ) ) as $id ) {
				$ids[] = (int) $id;
			}
		}
	}
	$menus = array();
	foreach ( array_unique( $ids ) as $id ) {
		$menu = wp_get_nav_menu_object( $id );
		if ( $menu instanceof WP_Term ) {
			$menus[] = $menu;
		}
	}
	return $menus;
}
