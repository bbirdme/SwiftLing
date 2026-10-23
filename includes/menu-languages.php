<?php
/**
 * The items a classic menu shows in each language.
 *
 * Every item shows in every language until someone chooses otherwise: on the Menus screen, in a
 * table of the menu's items by language (admin/views/menu-items.php), or in the settings of the item
 * under Appearance → Menus, saved with the menu. So a menu can leave out an item in some languages,
 * and have an item of its own for one language, the original included.
 *
 * The choice is kept with the item, in the meta value _swiftling_show_in: locale => 1 or 0 for every
 * language of the site when it was saved. A language added later is not named there and shows the
 * item when the original does. An item shown in every language has no meta value.
 *
 * An item left out in a language is left out there with its whole submenu. An item whose page is
 * hidden until it is translated is another matter: its submenu moves up a level, see
 * swiftling_filter_hidden_menu_items(). Texts of items left out in a language do not count toward
 * the menu's state there, and its editor has no fields for them.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta key of a menu item's choice of languages.
 */
const SWIFTLING_MENU_LANGUAGES = '_swiftling_show_in';

/**
 * User meta key: the person hid the panel that says how a menu's items by language work.
 */
const SWIFTLING_MENU_HELP_HIDDEN = 'swiftling_menu_help_hidden';

/**
 * Returns the locales of every language of the site, the original first.
 *
 * @return string[]
 */
function swiftling_menu_language_locales() {
	$languages = swiftling_languages();
	return array_merge( array( $languages['source']['locale'] ), array_values( wp_list_pluck( $languages['targets'], 'locale' ) ) );
}

/**
 * Returns a menu item's choice of languages as it is saved.
 *
 * @param int $item_id Menu item ID.
 * @return array<string, bool> Locale => whether visitors see the item; empty when it shows everywhere.
 */
function swiftling_menu_item_choices( $item_id ) {
	$saved = get_post_meta( (int) $item_id, SWIFTLING_MENU_LANGUAGES, true );
	if ( ! is_array( $saved ) ) {
		return array();
	}
	$choices = array();
	foreach ( $saved as $locale => $shown ) {
		$choices[ (string) $locale ] = (bool) $shown;
	}
	return $choices;
}

/**
 * Whether visitors see a menu item in a language, by its own choice. A language its choice does not
 * name follows the original.
 *
 * @param int         $item_id Menu item ID.
 * @param string      $locale  Locale.
 * @param string|null $source  Locale of the original; read from the languages when null.
 * @return bool
 */
function swiftling_menu_item_shows( $item_id, $locale, $source = null ) {
	$choices = swiftling_menu_item_choices( $item_id );
	if ( isset( $choices[ $locale ] ) ) {
		return $choices[ $locale ];
	}
	$source = null === $source ? swiftling_languages()['source']['locale'] : $source;
	return isset( $choices[ $source ] ) ? $choices[ $source ] : true;
}

/**
 * Saves the languages a menu item shows in. Every language of the site is named, so one added later
 * follows the original; an item shown everywhere loses its meta value.
 *
 * @param int      $item_id Menu item ID.
 * @param string[] $locales Locales visitors see the item in.
 */
function swiftling_save_menu_item_languages( $item_id, $locales ) {
	$all   = swiftling_menu_language_locales();
	$shown = array_intersect( $all, array_map( 'strval', (array) $locales ) );
	if ( count( $shown ) === count( $all ) ) {
		delete_post_meta( (int) $item_id, SWIFTLING_MENU_LANGUAGES );
		return;
	}
	$map = array();
	foreach ( $all as $locale ) {
		$map[ $locale ] = in_array( $locale, $shown, true ) ? 1 : 0;
	}
	update_post_meta( (int) $item_id, SWIFTLING_MENU_LANGUAGES, $map );
}

/**
 * Returns the ID of a menu item as wp_setup_nav_menu_item() sets it up.
 *
 * @param object $item Menu item.
 * @return int
 */
function swiftling_menu_item_id( $item ) {
	return isset( $item->db_id ) ? (int) $item->db_id : ( isset( $item->ID ) ? (int) $item->ID : 0 );
}

/**
 * Returns the items of a menu left out in a language: those that leave themselves out there, and
 * every item of their submenus.
 *
 * @param object[] $items  Menu items.
 * @param string   $locale Locale.
 * @return int[] Item IDs, in the menu's order.
 */
function swiftling_menu_left_out( $items, $locale ) {
	$source  = swiftling_languages()['source']['locale'];
	$parents = array();
	$own     = array();
	foreach ( $items as $item ) {
		$id = swiftling_menu_item_id( $item );
		if ( $id <= 0 ) {
			continue;
		}
		$parents[ $id ] = isset( $item->menu_item_parent ) ? (int) $item->menu_item_parent : 0;
		$own[ $id ]     = ! swiftling_menu_item_shows( $id, $locale, $source );
	}
	if ( ! in_array( true, $own, true ) ) {
		return array();
	}

	$left = array();
	foreach ( array_keys( $parents ) as $id ) {
		$seen = array();
		for ( $at = $id; $at && isset( $parents[ $at ] ) && ! isset( $seen[ $at ] ); $at = $parents[ $at ] ) {
			if ( $own[ $at ] ) {
				$left[] = $id;
				break;
			}
			$seen[ $at ] = true;
		}
	}
	return $left;
}

/**
 * Returns the language whose menu items the current request shows: the language of the page in
 * translate mode, and the original on the site otherwise. A page check and the admin show every item.
 *
 * @return string Locale, or an empty string when nothing is left out.
 */
function swiftling_menu_request_locale() {
	$mode = swiftling_mode();
	if ( 'translate' === $mode ) {
		$state = &swiftling_state();
		return (string) $state['locale'];
	}
	return '' === $mode && ! is_admin() ? swiftling_languages()['source']['locale'] : '';
}

/**
 * Takes the items left out in the language of the page out of a menu, with their submenus.
 *
 * @param array<int, object> $items Menu items.
 * @return array<int, object>
 */
function swiftling_filter_menu_languages( $items ) {
	$locale = swiftling_menu_request_locale();
	if ( '' === $locale || ! is_array( $items ) || ! $items ) {
		return $items;
	}
	$left = array_flip( swiftling_menu_left_out( $items, $locale ) );
	if ( ! $left ) {
		return $items;
	}
	return array_values(
		array_filter(
			$items,
			static function ( $item ) use ( $left ) {
				return ! isset( $left[ swiftling_menu_item_id( $item ) ] );
			}
		)
	);
}
// Before the links to hidden pages go, which move their submenus up a level.
add_filter( 'wp_nav_menu_objects', 'swiftling_filter_menu_languages', 4 );

/**
 * Returns the items of a menu left out in a language, with the names visitors would read.
 *
 * @param int    $menu_id Menu term ID.
 * @param string $locale  Locale.
 * @return array<int, string> Item ID => name, in the menu's order.
 */
function swiftling_menu_left_out_names( $menu_id, $locale ) {
	$items = swiftling_menu_items( $menu_id );
	$left  = array_flip( swiftling_menu_left_out( $items, $locale ) );
	$names = array();
	foreach ( $items as $item ) {
		if ( isset( $left[ (int) $item->ID ] ) ) {
			$names[ (int) $item->ID ] = (string) $item->title;
		}
	}
	return $names;
}

/**
 * Leaves the texts of items left out in a language out of a menu's places, so they do not count
 * toward its state there. A text that an item shown in the language shows too, such as the name
 * of a category in two items, still counts.
 *
 * @param string $key    Subject key "menu:<id>".
 * @param array  $places Places of the menu's texts, see swiftling_object_places().
 * @param string $locale Locale.
 * @return array
 */
function swiftling_menu_places_in( $key, $places, $locale ) {
	$menu = wp_get_nav_menu_object( (int) substr( $key, 5 ) );
	if ( ! $menu instanceof WP_Term ) {
		return $places;
	}
	$left = array_flip( swiftling_menu_left_out( swiftling_menu_items( $menu->term_id ), $locale ) );
	if ( ! $left ) {
		return $places;
	}

	$hidden = array();
	$shown  = array();
	foreach ( swiftling_menu_texts( $menu )['texts'] as $text ) {
		if ( isset( $left[ $text['item'] ] ) ) {
			$hidden[ $text['o'] ] = true;
		} else {
			$shown[ $text['o'] ] = true;
		}
	}
	// A menu's map names the owner of each shared text by the start of its digest, see swiftling_shared_key().
	$digests = array();
	foreach ( array_keys( array_diff_key( $hidden, $shown ) ) as $owner ) {
		$digests[ substr( md5( (string) $owner ), 0, 6 ) ] = true;
	}
	return array_filter(
		$places,
		static function ( $unit_key ) use ( $digests ) {
			$unit_key = (string) $unit_key;
			$start    = '~^' === substr( $unit_key, 0, 2 ) ? 2 : ( '^' === substr( $unit_key, 0, 1 ) ? 1 : 0 );
			return ! $start || ! isset( $digests[ substr( $unit_key, $start, 6 ) ] );
		},
		ARRAY_FILTER_USE_KEY
	);
}

/**
 * Returns how many items of a menu are not shown in every language by their own choice.
 *
 * @param int $menu_id Menu term ID.
 * @return int
 */
function swiftling_menu_differing_count( $menu_id ) {
	$locales = swiftling_menu_language_locales();
	$count   = 0;
	foreach ( swiftling_menu_items( $menu_id ) as $item ) {
		foreach ( $locales as $locale ) {
			if ( ! swiftling_menu_item_shows( $item->ID, $locale, $locales[0] ) ) {
				++$count;
				break;
			}
		}
	}
	return $count;
}

/**
 * Returns the address of a menu's table of items by language on the Menus screen.
 *
 * @param int $menu_id Menu term ID.
 * @return string
 */
function swiftling_menu_items_url( $menu_id ) {
	return add_query_arg( 'items', (int) $menu_id, admin_url( 'admin.php?page=swiftling-menus' ) );
}

/**
 * Saves the table of a menu's items by language, from the Menus screen. A cell under an item left
 * out in that language sends nothing, and the item keeps its own choice there.
 */
function swiftling_save_menu_items() {
	$menu_id = isset( $_POST['menu'] ) ? absint( wp_unslash( $_POST['menu'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce names the menu, so it is checked right below.
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'You may not change the menus of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_save_menu_items_' . $menu_id );

	$menu = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		wp_die( esc_html__( 'This menu no longer exists.', 'swiftling' ), '', array( 'response' => 404 ) );
	}

	$posted = isset( $_POST['shown'] ) && is_array( $_POST['shown'] ) ? wp_unslash( $_POST['shown'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every locale is compared with the site's languages below.
	$listed = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? array_map( 'absint', wp_unslash( $_POST['items'] ) ) : array();
	$items  = swiftling_menu_items( $menu->term_id );
	$listed = array_flip( $listed );

	// What the form shows: each item's ticks, by its own ID.
	$ticked = array();
	foreach ( $items as $item ) {
		$id            = (int) $item->ID;
		$ticked[ $id ] = isset( $posted[ $id ] ) && is_array( $posted[ $id ] ) ? array_map( 'sanitize_text_field', $posted[ $id ] ) : array();
	}

	$locales = swiftling_menu_language_locales();
	$parents = wp_list_pluck( $items, 'menu_item_parent', 'ID' );
	foreach ( $items as $item ) {
		$id = (int) $item->ID;
		// An item added since the screen was opened keeps what it has.
		if ( ! isset( $listed[ $id ] ) ) {
			continue;
		}
		$shown = array();
		foreach ( $locales as $locale ) {
			// A cell under an item left out in the language: no tick was sent; the item keeps its own choice.
			$locked = false;
			for ( $at = (int) $parents[ $id ]; $at && isset( $ticked[ $at ] ); $at = (int) $parents[ $at ] ) {
				if ( ! in_array( $locale, $ticked[ $at ], true ) ) {
					$locked = true;
					break;
				}
			}
			if ( $locked ? swiftling_menu_item_shows( $id, $locale, $locales[0] ) : in_array( $locale, $ticked[ $id ], true ) ) {
				$shown[] = $locale;
			}
		}
		swiftling_save_menu_item_languages( $id, $shown );
	}

	// Menus show on every page.
	swiftling_purge_all_later();
	wp_safe_redirect( add_query_arg( 'swiftling_notice', 'saved', swiftling_menu_items_url( $menu->term_id ) ) );
	exit;
}
add_action( 'admin_post_swiftling_save_menu_items', 'swiftling_save_menu_items' );

/**
 * Hides or shows again, for the person, the panel above a menu's items by language that says how
 * it works.
 */
function swiftling_save_menu_help() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'You may not change the menus of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_menu_help' );
	if ( empty( $_POST['show'] ) ) {
		update_user_meta( get_current_user_id(), SWIFTLING_MENU_HELP_HIDDEN, 1 );
	} else {
		delete_user_meta( get_current_user_id(), SWIFTLING_MENU_HELP_HIDDEN );
	}
	$menu_id = isset( $_POST['menu'] ) ? absint( wp_unslash( $_POST['menu'] ) ) : 0;
	wp_safe_redirect( $menu_id ? swiftling_menu_items_url( $menu_id ) : admin_url( 'admin.php?page=swiftling-menus' ) );
	exit;
}
add_action( 'admin_post_swiftling_save_menu_help', 'swiftling_save_menu_help' );

/**
 * Prints "Show in", a tick per language, in the settings of an item under Appearance → Menus.
 *
 * @param int $item_id Menu item ID.
 */
function swiftling_menu_item_fields( $item_id ) {
	$languages = swiftling_languages();
	if ( ! $languages['targets'] || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	$item_id = (int) $item_id;
	$list    = array_merge( array( $languages['source'] ), array_values( $languages['targets'] ) );
	?>
	<fieldset class="swiftling-show-in description description-wide" data-swiftling-show-in>
		<legend><?php esc_html_e( 'Show in', 'swiftling' ); ?></legend>
		<input type="hidden" name="swiftling_show_in_present[<?php echo esc_attr( (string) $item_id ); ?>]" value="1">
		<span class="swiftling-show-in__languages">
			<?php foreach ( $list as $index => $language ) : ?>
				<label>
					<input type="checkbox" name="swiftling_show_in[<?php echo esc_attr( (string) $item_id ); ?>][]" value="<?php echo esc_attr( $language['locale'] ); ?>"<?php echo swiftling_menu_item_shows( $item_id, $language['locale'], $languages['source']['locale'] ) ? ' checked' : ''; ?> data-code="<?php echo esc_attr( $language['code'] ); ?>">
					<?php echo esc_html( $language['name'] ); ?>
					<?php if ( 0 === $index ) : ?>
						<span class="swiftling-show-in__original"><?php esc_html_e( 'original', 'swiftling' ); ?></span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</span>
		<span class="swiftling-show-in__note"><?php esc_html_e( 'Its submenu is left out with it in the languages you untick.', 'swiftling' ); ?></span>
	</fieldset>
	<?php
}
add_action( 'wp_nav_menu_item_custom_fields', 'swiftling_menu_item_fields' );

/**
 * Saves "Show in" of an item when its menu is saved under Appearance → Menus. A save without those
 * fields, such as an import or the Customizer, leaves the choice as it is.
 *
 * @param int $menu_id Menu term ID.
 * @param int $item_id Menu item ID.
 */
function swiftling_save_menu_item_fields( $menu_id, $item_id ) {
	$item_id = (int) $item_id;
	if ( ! isset( $_POST['swiftling_show_in_present'][ $item_id ], $_POST['update-nav-menu-nonce'] ) || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['update-nav-menu-nonce'] ) ), 'update-nav_menu' ) ) {
		return;
	}
	$before  = swiftling_menu_item_choices( $item_id );
	$locales = isset( $_POST['swiftling_show_in'][ $item_id ] ) && is_array( $_POST['swiftling_show_in'][ $item_id ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['swiftling_show_in'][ $item_id ] ) ) : array();
	swiftling_save_menu_item_languages( $item_id, $locales );
	if ( swiftling_menu_item_choices( $item_id ) !== $before ) {
		// Menus show on every page.
		swiftling_purge_all_later();
	}
}
add_action( 'wp_update_nav_menu_item', 'swiftling_save_menu_item_fields', 10, 2 );

/**
 * Loads the badge script and style of "Show in" under Appearance → Menus.
 *
 * @param string $hook_suffix Admin screen.
 */
function swiftling_menu_item_assets( $hook_suffix ) {
	if ( 'nav-menus.php' !== $hook_suffix || ! swiftling_languages()['targets'] ) {
		return;
	}
	wp_enqueue_style( 'swiftling-nav-menus', SWIFTLING_URL . 'assets/css/nav-menus.css', array(), SWIFTLING_VERSION );
	wp_enqueue_script( 'swiftling-nav-menus', SWIFTLING_URL . 'assets/js/nav-menus.js', array( 'wp-i18n' ), SWIFTLING_VERSION, true );
	wp_set_script_translations( 'swiftling-nav-menus', 'swiftling' );
}
add_action( 'admin_enqueue_scripts', 'swiftling_menu_item_assets' );
