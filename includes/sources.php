<?php
/**
 * The places where WordPress hands stored data to whatever renders the page.
 *
 * Builders and themes read their data through these places, so watching them covers every
 * builder without code for any single one. What a builder needs beyond that is in its class, see
 * src/Builders:
 *
 * - post meta: Elementor, Beaver Builder, Bricks, ACF, SEO plugins;
 * - post fields: the title, the excerpt and content without blocks (classic content, shortcodes);
 * - blocks, each one just before it renders, whether it comes from a post, a template file, a
 *   template part or a synced pattern;
 * - navigation links, which core renders without the general block filter;
 * - the name and description of categories, tags and other terms, and term meta: fields themes
 *   and plugins keep with a term, such as Rank Math's SEO title of a category;
 * - options such as the site title, the Customizer and classic widgets, while the page renders;
 * - the files of the media library, which builders keep only by ID, such as an image;
 * - gettext: text written into the code of themes and plugins, including the default texts of
 *   builder widgets, which builders do not store with the page.
 *
 * Each value belongs to an object: a post ("post:12"), a template ("template:theme//page"), a
 * template part ("part:theme//header"), a term ("term:5"), the site settings ("options") or the
 * texts of one theme or plugin ("gettext:elementor").
 *
 * The current mode decides what happens to a value. In probe mode every leaf that looks like
 * text or a link address gets a marker and is remembered, so the editor can see where it ended up
 * on the page. In translate mode a leaf the probe found is replaced with its translation. In control
 * mode nothing changes; the page only renders under the same conditions as the probe.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the state of the current request.
 *
 * @return array
 */
function &swiftling_state() {
	static $state = array(
		'mode'      => '',      // probe, control or translate.
		'language'  => '',      // Target language slug in translate mode.
		'locale'    => '',      // Its locale, under which translations are stored.
		'guard'     => false,   // True while SwiftLing reads a raw value itself.
		'registry'  => array(), // Probe: leaf number => owner, path, value and rule.
		'ids'       => array(), // Probe: owner, path and value hash => leaf number.
		'frames'    => array(), // Blocks being rendered, each with the object its children belong to.
		'roots'     => array(), // Number of top-level blocks rendered so far, per object.
		'frame_seq' => 0,
		'content'   => array(), // Frames opened by the_content, to close them again.
		'excerpts'  => array(), // Frames opened for excerpts built from blocks, to close them again.
		'hashes'    => array(), // Translate: object key => hashes of its texts and their sources.
		'strings'   => array(), // Translate: object key => hash => translation.
		'partial'   => array(), // Translate: translations read value by value, see swiftling_value_translations().
		'values'    => array(), // Transformed meta and option values of this request.
		'owners'    => array(), // Post ID => object key.
		'probe'     => 0,       // Post a page check renders, -1 for a template; 0 when the request is not a page check.
		'subject'   => '',      // Probe: key of the editor's subject, see swiftling_subject().
		'collect'   => false,   // True while swiftling_collect() reads stored values outside a page.
	);
	return $state;
}

/**
 * Starts a mode for the rest of the request.
 *
 * @param string $mode     probe, control or translate.
 * @param string $language Target language slug, for translate mode.
 */
function swiftling_start( $mode, $language = '' ) {
	$state             = &swiftling_state();
	$state['mode']     = $mode;
	$state['language'] = $language;
	$target            = '' === $language ? null : swiftling_target_language( $language );
	$state['locale']   = $target ? $target['locale'] : '';

	// A builder's editor edits the original, also at a language's address, where Bricks opens it
	// from a translated page: it reads every stored value as it is, so it never saves a translation
	// over the original. The address keeps its language, so the page and its slugs are found.
	if ( 'translate' === $mode && \SwiftLing\Builders\Registry::isEditorPreview() ) {
		return;
	}

	\SwiftLing\Builders\Registry::start( $mode );

	if ( 'control' === $mode ) {
		return;
	}
	foreach ( swiftling_source_hooks( $mode ) as $hook ) {
		add_filter( $hook[0], $hook[1], $hook[2], $hook[3] );
	}
}

/**
 * Returns the places SwiftLing watches in a mode, see swiftling_start(): hook, callback, priority
 * and number of arguments. Actions are among them, which WordPress keeps as filters.
 *
 * @param string $mode probe or translate.
 * @return array<int, array{0: string, 1: string, 2: int, 3: int}>
 */
function swiftling_source_hooks( $mode ) {
	$hooks = array(
		array( 'get_post_metadata', 'swiftling_filter_post_meta', 100, 5 ),
		array( 'added_post_meta', 'swiftling_forget_post_meta', 10, 2 ),
		array( 'updated_post_meta', 'swiftling_forget_post_meta', 10, 2 ),
		array( 'deleted_post_meta', 'swiftling_forget_post_meta', 10, 2 ),

		array( 'the_posts', 'swiftling_preload_posts', 10, 1 ),

		array( 'the_title', 'swiftling_filter_title', 1, 2 ),
		array( 'single_post_title', 'swiftling_filter_single_title', 1, 2 ),
		array( 'get_the_excerpt', 'swiftling_filter_excerpt', 1, 2 ),
		// Around wp_trim_excerpt(), which builds an excerpt from the content at priority 10.
		array( 'get_the_excerpt', 'swiftling_excerpt_start', 9, 2 ),
		array( 'get_the_excerpt', 'swiftling_excerpt_end', 11, 1 ),
		array( 'the_content', 'swiftling_content_start', 1, 1 ),
		array( 'the_content', 'swiftling_content_end', PHP_INT_MAX, 1 ),

		array( 'render_block_data', 'swiftling_filter_block', 100, 3 ),
		array( 'render_block', 'swiftling_block_end', 100, 2 ),
		array( 'block_core_navigation_render_inner_blocks', 'swiftling_filter_navigation', 100, 1 ),

		array( 'get_term', 'swiftling_filter_term', 100, 1 ),
		array( 'get_term_metadata', 'swiftling_filter_term_meta', 100, 5 ),
		array( 'added_term_meta', 'swiftling_forget_term_meta', 10, 2 ),
		array( 'updated_term_meta', 'swiftling_forget_term_meta', 10, 2 ),
		array( 'deleted_term_meta', 'swiftling_forget_term_meta', 10, 2 ),

		array( 'wp_setup_nav_menu_item', 'swiftling_filter_menu_item', 100, 1 ),
	);
	if ( 'translate' === $mode ) {
		$hooks[] = array( 'wp_nav_menu_objects', 'swiftling_localize_menu_items', 100, 1 );
		$hooks[] = array( 'get_pages', 'swiftling_filter_page_titles', 100, 1 );
	}
	return array_merge(
		$hooks,
		array(
			array( 'wp_get_attachment_url', 'swiftling_filter_attachment_url', 100, 2 ),
			array( 'wp_get_attachment_image_src', 'swiftling_filter_attachment_image_src', 100, 4 ),
			array( 'wp_calculate_image_srcset_meta', 'swiftling_filter_srcset_meta', 100, 4 ),

			array( 'pre_option', 'swiftling_filter_option', 100, 3 ),
			array( 'updated_option', 'swiftling_forget_option', 10, 1 ),
			array( 'added_option', 'swiftling_forget_option', 10, 1 ),
			array( 'deleted_option', 'swiftling_forget_option', 10, 1 ),

			array( 'gettext', 'swiftling_filter_gettext', 100, 3 ),
			array( 'gettext_with_context', 'swiftling_filter_gettext_with_context', 100, 4 ),
			array( 'ngettext', 'swiftling_filter_ngettext', 100, 5 ),
			array( 'ngettext_with_context', 'swiftling_filter_ngettext_with_context', 100, 6 ),
		)
	);
}

/**
 * Reads stored values as a page check reads them, without a page: runs code that reads post meta,
 * term meta or options, such as a plugin loading the fields of its settings page, and returns every value
 * that may be text, with its object, path and rule key, as the probe lists them. So the paths are
 * those the translated view finds the values under.
 *
 * The code gets the values with markers, so it must only read, and a cache it keeps them in must be
 * emptied by the caller. The mode of the request is put back afterwards.
 *
 * @param callable $code Code that reads values.
 * @return array<int, array{o: string, p: string, v: string, r: string}> Leaf number => value.
 */
function swiftling_collect( $code ) {
	$state = &swiftling_state();
	$saved = $state;
	$state = array_merge(
		$state,
		array(
			'mode'     => 'probe',
			'collect'  => true,
			'guard'    => false,
			'registry' => array(),
			'ids'      => array(),
			'values'   => array(),
		)
	);

	$hooks = array(
		array( 'get_post_metadata', 'swiftling_filter_post_meta', 100, 5 ),
		array( 'get_term_metadata', 'swiftling_filter_term_meta', 100, 5 ),
		array( 'pre_option', 'swiftling_filter_option', 100, 3 ),
	);
	$added = array();
	foreach ( $hooks as $hook ) {
		if ( false === has_filter( $hook[0], $hook[1] ) ) {
			add_filter( $hook[0], $hook[1], $hook[2], $hook[3] );
			$added[] = $hook;
		}
	}

	try {
		$code();
		return $state['registry'];
	} finally {
		foreach ( $added as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$state = $saved;
	}
}

/**
 * Returns the current mode, or an empty string when SwiftLing leaves the request alone.
 *
 * @return string
 */
function swiftling_mode() {
	$state = &swiftling_state();
	return $state['mode'];
}

/**
 * Runs a stored value through the current mode.
 *
 * @param string $owner  Object key the value belongs to.
 * @param string $path   Path of the value inside the object, e.g. "meta/_elementor_data".
 * @param string $source Where the value comes from, for rule keys, e.g. "meta:_elementor_data".
 * @param mixed  $value  Stored value.
 * @return mixed The value with markers or translations, or the value itself.
 */
function swiftling_transform( $owner, $path, $source, $value ) {
	$state = &swiftling_state();

	if ( 'probe' === $state['mode'] ) {
		// Link addresses come from data only; an address in a theme's or plugin's code is not content.
		$links = 'gettext' !== $source;
		return swiftling_walk(
			$value,
			function ( $leaf, $leaf_path, $rule ) use ( $owner, $links ) {
				if ( ! swiftling_is_candidate( $leaf, $owner, $leaf_path, $rule, $links ) ) {
					return $leaf;
				}
				if ( \SwiftLing\Builders\Registry::holdsNoText( $leaf_path ) ) {
					return $leaf;
				}
				// Code the user chose as a whole, such as a tracking script, is translated on the
				// Themes & plugins screen; a marker inside a script would only stop it.
				if ( \SwiftLing\Packages\Settings::isWhole( $leaf_path ) && '' === swiftling_visible_text( $leaf ) ) {
					return $leaf;
				}
				return swiftling_add_marker( $leaf, swiftling_register_leaf( $owner, $leaf_path, $leaf, $rule ) );
			},
			$path,
			$source
		);
	}

	if ( 'translate' === $state['mode'] ) {
		// A post of a type set to Never shows its original, also where it has translations from before.
		if ( ! swiftling_object_has_source( $owner, swiftling_path_source( $path ) ) || swiftling_is_never_translated_owner( $owner ) ) {
			return $value;
		}
		$hashes = swiftling_object_hashes( $owner );
		// A duplicate shows its own texts as the original has them, with links in the language.
		$original = swiftling_shows_original( $owner );
		// The options list far more texts than a page reads: only the texts of this value are read.
		$translations = swiftling_reads_by_value( $owner ) ? swiftling_value_translations( $owner, $value, $path, $source ) : null;
		return swiftling_walk(
			$value,
			function ( $leaf, $leaf_path, $rule ) use ( $owner, $hashes, $original, $translations ) {
				if ( ! swiftling_is_candidate( $leaf, $owner, $leaf_path, $rule, true ) ) {
					return $leaf;
				}
				$hash = swiftling_hash( $leaf );
				if ( ! isset( $hashes[ $hash ] ) ) {
					return $leaf;
				}
				if ( $original ) {
					return swiftling_localize_value( $leaf );
				}
				// Each place has its own translation, of the version of the text the probe found
				// there: a text, or an address, at this path.
				$map          = swiftling_object_map( $owner );
				$translations = null !== $translations ? $translations : swiftling_object_translations( $owner );
				foreach ( array( swiftling_path_key( $leaf_path ), swiftling_link_key( $leaf_path ) ) as $unit_key ) {
					if ( isset( $map['keys'][ $unit_key ], $translations[ $unit_key ] ) && $map['keys'][ $unit_key ] === $hash ) {
						return swiftling_localize_value( $translations[ $unit_key ] );
					}
				}
				return swiftling_localize_value( $leaf );
			},
			$path,
			$source
		);
	}

	return $value;
}

/**
 * Runs a value through the current mode that is text by its nature, such as the name of a
 * category: also a single word in lowercase, which swiftling_transform() would take for a setting.
 *
 * @param string $owner  Object key the value belongs to.
 * @param string $path   Path of the value inside the object, e.g. "field/name".
 * @param string $source Where the value comes from, for rule keys, e.g. "term:name".
 * @param string $value  The text.
 * @return string The text with a marker or its translation, or the text itself.
 */
function swiftling_transform_text( $owner, $path, $source, $value ) {
	$state = &swiftling_state();
	if ( ! is_string( $value ) || ! preg_match( '/\p{L}/u', $value ) || swiftling_has_marker( $value ) ) {
		return $value;
	}

	if ( 'probe' === $state['mode'] ) {
		return swiftling_add_marker( $value, swiftling_register_leaf( $owner, $path, $value, $source . '||' ) );
	}

	if ( 'translate' === $state['mode'] ) {
		if ( swiftling_is_never_translated_owner( $owner ) ) {
			return $value;
		}
		$map = swiftling_object_map( $owner );
		$key = swiftling_path_key( $path );
		if ( ! isset( $map['keys'][ $key ] ) || swiftling_hash( $value ) !== $map['keys'][ $key ] ) {
			return $value;
		}
		$translations = swiftling_object_translations( $owner );
		return isset( $translations[ $key ] ) ? $translations[ $key ] : $value;
	}

	return $value;
}

/**
 * Keeps the links of a translated value in the language of the page: a link address, or the links
 * inside HTML. A link the user translated to an address in another language keeps it.
 *
 * @param string $value Text or address, translated or not.
 * @return string
 */
function swiftling_localize_value( $value ) {
	if ( swiftling_is_url_like( $value ) ) {
		return swiftling_localize_url( $value );
	}
	return false === strpos( $value, '<' ) ? $value : swiftling_localize_links( $value );
}

/**
 * Whether a leaf may be text or a link address, so the probe marks it and the translated view
 * replaces it: it looks like text or an address, or a builder declares it as text. A leaf whose
 * field a builder declares as no text, such as the value of a choice, never is.
 *
 * @param mixed  $leaf  Leaf.
 * @param string $owner Object key of the leaf.
 * @param string $path  Path of the leaf inside its object.
 * @param string $rule  Rule key of the leaf.
 * @param bool   $links Whether a link address counts.
 * @return bool
 */
function swiftling_is_candidate( $leaf, $owner, $path, $rule, $links ) {
	if ( ! is_string( $leaf ) || '' === $leaf || swiftling_has_marker( $leaf ) ) {
		return false;
	}
	$declared = swiftling_field_holds_text( $owner, $path );
	if ( false === $declared ) {
		return false;
	}
	return swiftling_is_text_like( $leaf ) || ( $links && swiftling_is_url_like( $leaf ) ) || swiftling_is_declared_text( $leaf, $rule, $owner, $path );
}

/**
 * Whether a leaf is text although it does not look like text: a builder declares it, such as "/month"
 * or "m" beside a price, or "welcome" in a text field, which still needs a letter, so a separator or
 * a number is left alone; or a block saved it between its tags.
 *
 * @param mixed  $leaf  Leaf.
 * @param string $rule  Rule key of the leaf.
 * @param string $owner Object key of the leaf, when known.
 * @param string $path  Path of the leaf inside its object, when known.
 * @return bool
 */
function swiftling_is_declared_text( $leaf, $rule, $owner = '', $path = '' ) {
	if ( ! is_string( $leaf ) || swiftling_has_marker( $leaf ) ) {
		return false;
	}
	// What a block saved between its tags is text whatever it says; the attributes of a shortcode in
	// it are settings of the shortcode, such as a button's hover effect "none", text only by look.
	if ( swiftling_is_block_html_rule( $rule ) && ! swiftling_is_shortcode_attribute_rule( $rule ) ) {
		return swiftling_shows_text( $leaf );
	}
	if ( 1 !== preg_match( '/\p{L}/u', $leaf ) ) {
		return false;
	}
	return \SwiftLing\Builders\Registry::declaresText( $rule ) || ( '' !== $path && true === swiftling_field_holds_text( $owner, $path ) );
}

/**
 * Whether a stored value is text by declaration, from the field it belongs to: the title or the
 * excerpt of a post, a field a builder declares, such as a text field of ACF, or a value the user
 * chose on the Themes & plugins screen, as a setting of a theme or plugin that visitors read. See
 * Builder::fieldHoldsText() and SwiftLing\Packages\Settings::holdsText().
 *
 * The title and the excerpt are text by their nature, as the name of a category is, whatever they
 * look like: a product called "varijacija" or "sdf" looked like a setting, so its page check never
 * found its title and its editor had no Title row.
 *
 * @param string $owner Object key of the value.
 * @param string $path  Path of the value inside its object.
 * @return bool|null True for text, false for no text, null when nothing declares it.
 */
function swiftling_field_holds_text( $owner, $path ) {
	if ( in_array( $path, array( 'field/post_title', 'field/post_excerpt' ), true ) && 0 === strpos( (string) $owner, 'post:' ) ) {
		return true;
	}
	$declared = \SwiftLing\Builders\Registry::fieldHoldsText( $owner, $path );
	return null !== $declared ? $declared : \SwiftLing\Packages\Settings::holdsText( $owner, $path );
}

/**
 * Whether a rule key names what a block saved between its tags, see swiftling_filter_block(). That
 * is content someone wrote, whatever it says: one word in a code block, a line of HTML, CSS. A
 * block keeps its settings in its attributes, never there, so nothing there is mistaken for one.
 *
 * @param string $rule Rule key.
 * @return bool
 */
function swiftling_is_block_html_rule( $rule ) {
	return 1 === preg_match( '~^block:[^|#]+#html\|~', (string) $rule );
}

/**
 * Whether a rule key names an attribute of a shortcode, as "block:freeform#html|fusion_button|attr/hover_transition":
 * the walk of shortcodes names it attr/ and the tag, where an attribute of HTML is named @alt.
 *
 * @param string $rule Rule key.
 * @return bool
 */
function swiftling_is_shortcode_attribute_rule( $rule ) {
	return 1 === preg_match( '~^[^|]*\|[^|]+\|attr/~', (string) $rule );
}

/**
 * Whether a value shows a visitor anything to read: some character that is not space, also a
 * number or a sign, once tags are taken away.
 *
 * @param string $value Plain text or HTML.
 * @return bool
 */
function swiftling_shows_text( $value ) {
	return 1 === preg_match( '/[^\s\x{00A0}]/u', swiftling_visible_text( $value ) );
}

/**
 * Remembers a leaf the probe marked and returns its number. The same leaf read twice keeps its number.
 *
 * @param string $owner Object key.
 * @param string $path  Path inside the object.
 * @param string $leaf  Original value.
 * @param string $rule  Rule key.
 * @return int
 */
function swiftling_register_leaf( $owner, $path, $leaf, $rule ) {
	$state = &swiftling_state();
	$key   = $owner . '|' . $path . '|' . md5( $leaf );

	if ( ! isset( $state['ids'][ $key ] ) ) {
		$id                       = count( $state['registry'] ) + 1;
		$state['registry'][ $id ] = array(
			'o' => $owner,
			'p' => $path,
			'v' => $leaf,
			'r' => $rule,
		);
		$state['ids'][ $key ]     = $id;
	}

	return $state['ids'][ $key ];
}

/**
 * Returns the object key of a post. Revisions and autosaves belong to the post they were made from.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function swiftling_post_owner( $post_id ) {
	$state   = &swiftling_state();
	$post_id = (int) $post_id;

	if ( ! isset( $state['owners'][ $post_id ] ) ) {
		$parent                      = wp_is_post_revision( $post_id );
		$state['owners'][ $post_id ] = 'post:' . ( $parent ? $parent : $post_id );
	}

	return $state['owners'][ $post_id ];
}

/**
 * Returns what translate mode needs to know about an object: the texts the probe found in it, by
 * their key in the map and by hash, and the sources those texts come from.
 *
 * @param string $owner Object key.
 * @return array{hashes: array<string, true>, keys: array<string, string>, sources: array<string, true>}
 *         All empty when the object was never probed. Keys: map key => hash of the current version.
 *         Empty sources mean any source, for maps saved before sources were recorded.
 */
function swiftling_object_map( $owner ) {
	$state = &swiftling_state();

	if ( ! isset( $state['hashes'][ $owner ] ) ) {
		// Texts of themes and plugins are read many times per page, from many text domains, and a
		// page can list many categories and tags, so all of their maps are loaded together.
		$group = $owner;
		foreach ( array( 'gettext:', 'term:' ) as $prefix ) {
			if ( 0 === strpos( $owner, $prefix ) ) {
				$group = $prefix;
			}
		}
		$cache_key = 'map:' . $group . ':' . swiftling_cache_version();
		$known     = wp_cache_get( $cache_key, 'swiftling' );

		if ( false === $known ) {
			$maps  = $group !== $owner ? swiftling_get_maps_by_prefix( $group ) : swiftling_get_maps( array( $owner ) );
			$known = array_map( 'swiftling_known_map', $maps );
			wp_cache_set( $cache_key, $known, 'swiftling', HOUR_IN_SECONDS );
		}

		if ( $group !== $owner ) {
			foreach ( $known as $key => $map ) {
				$state['hashes'][ $key ] = $map;
			}
		}
		$state['hashes'][ $owner ] = isset( $known[ $owner ] ) ? $known[ $owner ] : swiftling_known_map( null );
	}

	return $state['hashes'][ $owner ];
}

/**
 * Returns what translate mode needs to know of a saved map, see swiftling_object_map().
 *
 * @param array|null $map Map as swiftling_get_maps() returns it, or null for an object never probed.
 * @return array{hashes: array<string, true>, keys: array<string, string>, sources: array<string, true>}
 */
function swiftling_known_map( $map ) {
	$known = array(
		'hashes'  => array(),
		'keys'    => array(),
		'sources' => $map ? array_fill_keys( $map['sources'], true ) : array(),
	);
	foreach ( $map ? $map['units'] : array() as $unit_key => $unit ) {
		// A shared text a page lists is replaced through its own object.
		if ( ! swiftling_is_shared_key( $unit_key ) ) {
			$hash                                = is_array( $unit ) ? $unit[0] : $unit;
			$known['hashes'][ $hash ]            = true;
			$known['keys'][ (string) $unit_key ] = $hash;
		}
	}
	return $known;
}

/**
 * Reads what translate mode needs of many objects at once: their maps and their translations into
 * the current language, as swiftling_object_map() and swiftling_object_translations() would read
 * them one object at a time. For objects a page shows many of, such as its comments.
 *
 * @param string[] $owners Object keys.
 */
function swiftling_preload_objects( $owners ) {
	$state  = &swiftling_state();
	$owners = array_values(
		array_filter(
			array_unique( array_map( 'strval', $owners ) ),
			static function ( $owner ) use ( $state ) {
				return ! isset( $state['strings'][ $owner ] );
			}
		)
	);
	if ( ! $owners ) {
		return;
	}

	// The maps not read yet; a map already read, such as the maps of every term, is kept.
	$unread = array_values(
		array_filter(
			$owners,
			static function ( $owner ) use ( $state ) {
				return ! isset( $state['hashes'][ $owner ] );
			}
		)
	);
	$maps   = $unread ? swiftling_get_maps( $unread ) : array();
	$places = array();
	foreach ( $owners as $owner ) {
		if ( ! isset( $state['hashes'][ $owner ] ) ) {
			$state['hashes'][ $owner ] = swiftling_known_map( isset( $maps[ $owner ] ) ? $maps[ $owner ] : null );
		}
		$state['strings'][ $owner ] = array();
		foreach ( $state['hashes'][ $owner ]['keys'] as $unit_key => $hash ) {
			$places[ swiftling_place_key( $owner, $unit_key, $hash ) ] = array( $owner, $unit_key );
		}
	}
	foreach ( $places ? swiftling_get_translations( $state['locale'], array_keys( $places ) ) : array() as $place => $row ) {
		// WordPress's own translation shows as WordPress hands it over, as swiftling_object_translations() does.
		if ( SWIFTLING_OFFICIAL !== $row['status'] ) {
			list( $owner, $unit_key )                = $places[ $place ];
			$state['strings'][ $owner ][ $unit_key ] = $row['text'];
		}
	}
}

/**
 * Reads the translations of the posts a query found at once, so a list of posts, such as a shop's,
 * does not read the texts of each product one query at a time as it shows them.
 *
 * @param mixed $posts Posts a query found.
 * @return mixed The same posts.
 */
function swiftling_preload_posts( $posts ) {
	if ( 'translate' !== swiftling_mode() || ! is_array( $posts ) || count( $posts ) < 2 ) {
		return $posts;
	}
	$owners = array();
	foreach ( $posts as $post ) {
		if ( $post instanceof WP_Post ) {
			$owners[] = swiftling_post_owner( $post->ID );
		}
	}
	swiftling_preload_objects( $owners );
	return $posts;
}

/**
 * Returns the hashes of the texts the probe found in an object, for translate mode.
 *
 * @param string $owner Object key.
 * @return array<string, true> Empty when the object was never probed.
 */
function swiftling_object_hashes( $owner ) {
	$map = swiftling_object_map( $owner );
	return $map['hashes'];
}

/**
 * Whether texts of an object come from a source, for translate mode. Every other source is left
 * alone, so reading a setting costs nothing.
 *
 * @param string $owner  Object key.
 * @param string $source Source, e.g. "meta/_elementor_data", "option/blogname" or "b".
 * @return bool
 */
function swiftling_object_has_source( $owner, $source ) {
	$map = swiftling_object_map( $owner );
	return $map['hashes'] && ( ! $map['sources'] || isset( $map['sources'][ $source ] ) );
}

/**
 * Whether the translations of an object are read value by value, as the value is read, rather than
 * all at once: the object of options, which lists every text recording found in the settings of
 * themes and plugins, thousands on some sites, while a page reads a few options. With a persistent
 * object cache all of them are one read from the cache, which is cheaper.
 *
 * @param string $owner Object key.
 * @return bool
 */
function swiftling_reads_by_value( $owner ) {
	return 'options' === $owner && ! wp_using_ext_object_cache();
}

/**
 * Returns the translations of the texts in one value of an object into the current language, read
 * once for the request: one query for the texts of the value that are not read yet.
 *
 * @param string $owner  Object key.
 * @param mixed  $value  The value, as stored.
 * @param string $path   Path of the value inside the object.
 * @param string $source Where the value comes from, for rule keys.
 * @return array<string, string> Map key => translation, of every text read so far.
 */
function swiftling_value_translations( $owner, $value, $path, $source ) {
	$state = &swiftling_state();
	if ( ! isset( $state['partial'][ $owner ] ) ) {
		$state['partial'][ $owner ] = array(
			'read'         => array(),
			'translations' => array(),
		);
	}
	$known  = &$state['partial'][ $owner ];
	$map    = swiftling_object_map( $owner );
	$places = array();
	swiftling_walk(
		$value,
		static function ( $leaf, $leaf_path, $rule ) use ( $owner, $map, &$known, &$places ) {
			if ( ! swiftling_is_candidate( $leaf, $owner, $leaf_path, $rule, true ) ) {
				return $leaf;
			}
			$hash = swiftling_hash( $leaf );
			foreach ( array( swiftling_path_key( $leaf_path ), swiftling_link_key( $leaf_path ) ) as $unit_key ) {
				if ( isset( $map['keys'][ $unit_key ] ) && $map['keys'][ $unit_key ] === $hash && ! isset( $known['read'][ $unit_key ] ) ) {
					$places[ swiftling_place_key( $owner, $unit_key, $hash ) ] = $unit_key;
					$known['read'][ $unit_key ]                                = true;
				}
			}
			return $leaf;
		},
		$path,
		$source
	);
	foreach ( $places ? swiftling_get_translations( $state['locale'], array_keys( $places ) ) : array() as $place => $row ) {
		// WordPress's own translation shows as WordPress hands it over, as swiftling_object_translations() does.
		if ( SWIFTLING_OFFICIAL !== $row['status'] ) {
			$known['translations'][ $places[ $place ] ] = $row['text'];
		}
	}
	return $known['translations'];
}

/**
 * Returns the translations of an object's texts into the current language, each of the version of
 * the text its place holds now.
 *
 * @param string $owner Object key.
 * @return array<string, string> Map key => translation.
 */
function swiftling_object_translations( $owner ) {
	$state = &swiftling_state();

	// The terms a page lists are found one after another, each read through get_term(): the maps of
	// all terms are read together, see swiftling_object_map(), and so are their translations.
	if ( 0 === strpos( $owner, 'term:' ) && ! isset( $state['strings'][ $owner ] ) ) {
		swiftling_object_map( $owner );
		swiftling_preload_objects(
			array_filter(
				array_keys( $state['hashes'] ),
				static function ( $key ) use ( $state ) {
					return 0 === strpos( (string) $key, 'term:' ) && ! isset( $state['strings'][ $key ] );
				}
			)
		);
	}

	if ( ! isset( $state['strings'][ $owner ] ) ) {
		$cache_key    = 'strings:' . $owner . ':' . $state['locale'] . ':' . swiftling_cache_version();
		$translations = wp_cache_get( $cache_key, 'swiftling' );

		if ( false === $translations ) {
			$translations = array();
			$places       = array();
			foreach ( swiftling_object_map( $owner )['keys'] as $unit_key => $hash ) {
				$places[ swiftling_place_key( $owner, $unit_key, $hash ) ] = $unit_key;
			}
			foreach ( swiftling_get_translations( $state['locale'], array_keys( $places ) ) as $place => $row ) {
				// WordPress's own translation shows as WordPress hands it over, from its current files.
				if ( SWIFTLING_OFFICIAL !== $row['status'] ) {
					$translations[ $places[ $place ] ] = $row['text'];
				}
			}
			wp_cache_set( $cache_key, $translations, 'swiftling', HOUR_IN_SECONDS );
		}

		$state['strings'][ $owner ] = $translations;
	}

	return $state['strings'][ $owner ];
}

/**
 * Post meta: replaces the value a builder or theme reads.
 *
 * @param mixed  $check     Value from an earlier filter, or null.
 * @param int    $object_id Post ID.
 * @param string $meta_key  Meta key, or empty for all keys.
 * @param bool   $single    Whether one value was asked for.
 * @return mixed Null to let WordPress read the value itself.
 */
function swiftling_filter_post_meta( $check, $object_id, $meta_key, $single ) {
	return swiftling_filter_meta( 'post', $check, $object_id, $meta_key, $single );
}

/**
 * Term meta: replaces the value of a field a theme or plugin keeps with a term, such as Rank Math's
 * SEO title of a category. In a page check only while the page renders, as for the term's name:
 * fields read while WordPress and plugins start up are no content of the page.
 *
 * @param mixed  $check     Value from an earlier filter, or null.
 * @param int    $object_id Term ID.
 * @param string $meta_key  Meta key, or empty for all keys.
 * @param bool   $single    Whether one value was asked for.
 * @return mixed Null to let WordPress read the value itself.
 */
function swiftling_filter_term_meta( $check, $object_id, $meta_key, $single ) {
	$state = &swiftling_state();
	if ( 'probe' === $state['mode'] && ! $state['collect'] && ! did_action( 'template_redirect' ) ) {
		return $check;
	}
	// Which theme or plugin reads the field, so the editor can say where the field comes from.
	if ( 'probe' === $state['mode'] && ! $state['collect'] && '' !== $meta_key && null === $check && ! $state['guard'] ) {
		swiftling_note_term_meta_caller( $meta_key );
	}
	return swiftling_filter_meta( 'term', $check, $object_id, $meta_key, $single );
}

/**
 * Meta of a post or a term: replaces the value a builder, theme or plugin reads, through the
 * current mode. The value belongs to the post ("post:12") or the term ("term:5"), under the path
 * "meta/<key>".
 *
 * @param string $type      post or term.
 * @param mixed  $check     Value from an earlier filter, or null.
 * @param int    $object_id Post or term ID.
 * @param string $meta_key  Meta key, or empty for all keys.
 * @param bool   $single    Whether one value was asked for.
 * @return mixed Null to let WordPress read the value itself.
 */
function swiftling_filter_meta( $type, $check, $object_id, $meta_key, $single ) {
	$state = &swiftling_state();

	if ( null !== $check || $state['guard'] || ! $object_id ) {
		return $check;
	}

	$is_post = 'post' === $type;
	$owner   = $is_post ? swiftling_post_owner( $object_id ) : 'term:' . (int) $object_id;
	$source  = $is_post ? 'meta:' : 'termmeta:';
	$skips   = $is_post ? 'swiftling_skips_meta' : 'swiftling_skips_term_meta';
	if ( '' !== $meta_key && $skips( $owner, $meta_key ) ) {
		return $check;
	}
	if ( 'translate' === $state['mode'] && ( ! swiftling_object_hashes( $owner ) || ( '' !== $meta_key && ! swiftling_object_has_source( $owner, swiftling_path_source( swiftling_meta_path( $meta_key, 0 ) ) ) ) ) ) {
		return $check;
	}

	$cache_key = ( $is_post ? 'meta|' : 'termmeta|' ) . $object_id . '|' . $meta_key . '|' . ( $single ? 1 : 0 );
	if ( array_key_exists( $cache_key, $state['values'] ) ) {
		return $state['values'][ $cache_key ];
	}

	$state['guard'] = true;
	$raw            = get_metadata_raw( $type, $object_id, $meta_key, $single );
	$state['guard'] = false;

	$result = null;

	if ( null !== $raw && '' === $meta_key ) {
		// All keys at once, as ACF reads them. WordPress returns these values still serialized.
		$changed = false;
		foreach ( $raw as $key => $values ) {
			if ( $skips( $owner, (string) $key ) ) {
				continue;
			}
			foreach ( $values as $index => $stored ) {
				$value = maybe_unserialize( $stored );
				$new   = swiftling_transform( $owner, swiftling_meta_path( (string) $key, $index ), $source . $key, $value );
				if ( $new !== $value ) {
					$raw[ $key ][ $index ] = is_serialized( $stored ) ? maybe_serialize( $new ) : $new;
					$changed               = true;
				}
			}
		}
		// For one value WordPress takes the first of what a filter returns, so the array of every key is
		// handed in an array, as WordPress gives it itself.
		$result = $changed ? ( $single ? array( $raw ) : $raw ) : null;
	} elseif ( null !== $raw && $single ) {
		$new    = swiftling_transform( $owner, swiftling_meta_path( $meta_key, 0 ), $source . $meta_key, $raw );
		$result = $new !== $raw ? array( $new ) : null;
	} elseif ( null !== $raw ) {
		$changed = false;
		foreach ( $raw as $index => $value ) {
			$new = swiftling_transform( $owner, swiftling_meta_path( $meta_key, $index ), $source . $meta_key, $value );
			if ( $new !== $value ) {
				$raw[ $index ] = $new;
				$changed       = true;
			}
		}
		$result = $changed ? $raw : null;
	}

	$state['values'][ $cache_key ] = $result;
	return $result;
}

/**
 * Returns the path of a meta value inside its post or term.
 *
 * @param string $meta_key Meta key.
 * @param int    $index    Position among the values of the key.
 * @return string
 */
function swiftling_meta_path( $meta_key, $index ) {
	return 'meta/' . swiftling_escape_segment( $meta_key ) . ( $index ? '/@' . (int) $index : '' );
}

/**
 * Forgets transformed meta of a post after it was written in this request.
 *
 * @param int $meta_id   Meta ID, unused.
 * @param int $object_id Post ID.
 */
function swiftling_forget_post_meta( $meta_id, $object_id ) {
	$state  = &swiftling_state();
	$prefix = 'meta|' . (int) $object_id . '|';
	foreach ( array_keys( $state['values'] ) as $key ) {
		if ( 0 === strpos( $key, $prefix ) ) {
			unset( $state['values'][ $key ] );
		}
	}
}

/**
 * Forgets transformed meta of a term after it was written in this request.
 *
 * @param int $meta_id   Meta ID, unused.
 * @param int $object_id Term ID.
 */
function swiftling_forget_term_meta( $meta_id, $object_id ) {
	$state  = &swiftling_state();
	$prefix = 'termmeta|' . (int) $object_id . '|';
	foreach ( array_keys( $state['values'] ) as $key ) {
		if ( 0 === strpos( $key, $prefix ) ) {
			unset( $state['values'][ $key ] );
		}
	}
}

/**
 * Whether a meta value of a post is left alone: its key names a setting (see
 * swiftling_skip_meta_key()), and no plugin declares the value as text. A field named
 * "usage_notes" is a text all the same when its plugin says so.
 *
 * @param string $owner    Object key of the post.
 * @param string $meta_key Meta key.
 * @return bool
 */
function swiftling_skips_meta( $owner, $meta_key ) {
	return swiftling_skip_meta_key( $meta_key ) && true !== swiftling_field_holds_text( $owner, swiftling_meta_path( $meta_key, 0 ) );
}

/**
 * Whether a field of a term is left alone: its key names a setting (see
 * swiftling_skip_term_meta_key()) and no plugin declares it as text, or the user chose to keep the
 * original of the field for the term's list, see swiftling_choose_term_field(). While the editor
 * lists the fields, a kept one is read all the same, so the editor can show the choice.
 *
 * @param string $owner    Object key of the term, "term:5".
 * @param string $meta_key Meta key.
 * @return bool
 */
function swiftling_skips_term_meta( $owner, $meta_key ) {
	if ( swiftling_skip_term_meta_key( $meta_key ) && true !== swiftling_field_holds_text( $owner, swiftling_meta_path( $meta_key, 0 ) ) ) {
		return true;
	}
	$state = &swiftling_state();
	// Asked for every field a page reads: the term is looked up only when the user kept a field.
	if ( $state['collect'] || ! swiftling_term_field_settings()['choices'] ) {
		return false;
	}
	return 'keep' === swiftling_term_field_choice( swiftling_term_taxonomy_of( (int) substr( $owner, 5 ) ), $meta_key );
}

/**
 * Whether a key of term meta never holds text a visitor reads: what swiftling_skip_meta_key()
 * leaves out, and the settings WooCommerce keeps with a category: its order, its image and
 * whether its page shows products or subcategories. "products" in the display type looks like a
 * word, and translating it would change what the page shows.
 *
 * @param string $meta_key Meta key.
 * @return bool
 */
function swiftling_skip_term_meta_key( $meta_key ) {
	return swiftling_skip_meta_key( $meta_key ) || in_array( $meta_key, array( 'order', 'display_type', 'thumbnail_id' ), true ) || 0 === strpos( $meta_key, 'product_count_' );
}

/**
 * Whether a meta key never holds text a visitor reads: WordPress bookkeeping, caches, CSS and
 * other technical values. This only saves work; the probe would reach the same verdict.
 *
 * @param string $meta_key Meta key.
 * @return bool
 */
function swiftling_skip_meta_key( $meta_key ) {
	static $bookkeeping = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_encloseme',
		'_pingme',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_thumbnail_id',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_menu_item_type',
		'_menu_item_object',
		'_menu_item_object_id',
		'_menu_item_menu_item_parent',
		'_menu_item_target',
		'_menu_item_classes',
		'_menu_item_xfn',
		'_menu_item_url',
	);

	return in_array( $meta_key, $bookkeeping, true ) || 0 === strpos( $meta_key, '_swiftling_' ) || 1 === preg_match( '/cache|css|_lock|version|hash|nonce|token|usage|assets/i', $meta_key );
}

/**
 * Post title, wherever a theme or block shows it.
 *
 * @param string $title   Title.
 * @param int    $post_id Post ID.
 * @return string
 */
function swiftling_filter_title( $title, $post_id = 0 ) {
	if ( ! $post_id || ! is_string( $title ) ) {
		return $title;
	}
	return swiftling_transform( swiftling_post_owner( $post_id ), 'field/post_title', 'field:post_title', $title );
}

/**
 * Titles of the pages get_pages() hands over, in the language of the page. The Page List block,
 * the menu a block theme shows before a menu is made, prints each title as stored, without
 * the_title, while its link already leads to the page in the language. Each page is a copy, so
 * the post as cached keeps its own title.
 *
 * @param array $pages Pages.
 * @return array
 */
function swiftling_filter_page_titles( $pages ) {
	if ( ! is_array( $pages ) ) {
		return $pages;
	}
	foreach ( $pages as $index => $page ) {
		if ( ! $page instanceof WP_Post ) {
			continue;
		}
		$title = swiftling_filter_title( $page->post_title, $page->ID );
		if ( $title !== $page->post_title ) {
			$copy             = clone $page;
			$copy->post_title = $title;
			$pages[ $index ]  = $copy;
		}
	}
	return $pages;
}

/**
 * Post title in the document title.
 *
 * @param string       $title Title.
 * @param WP_Post|null $post  Post.
 * @return string
 */
function swiftling_filter_single_title( $title, $post = null ) {
	if ( ! $post instanceof WP_Post || ! is_string( $title ) ) {
		return $title;
	}
	return swiftling_transform( swiftling_post_owner( $post->ID ), 'field/post_title', 'field:post_title', $title );
}

/**
 * Excerpt written by hand. A generated excerpt comes from the content and is not a text of its own.
 *
 * @param string       $excerpt Excerpt.
 * @param WP_Post|null $post    Post.
 * @return string
 */
function swiftling_filter_excerpt( $excerpt, $post = null ) {
	if ( ! $post instanceof WP_Post || '' === $excerpt || $excerpt !== $post->post_excerpt ) {
		return $excerpt;
	}
	return swiftling_transform( swiftling_post_owner( $post->ID ), 'field/post_excerpt', 'field:post_excerpt', $excerpt );
}

/**
 * Returns a post's content as stored, with its texts in the language of the page: each block's at
 * the place it renders at, see swiftling_translate_blocks(), and content without blocks as one
 * value, as swiftling_content_start() reads it. For code that reads the content itself, past
 * the_content: a separate copy made of the post, and WooCommerce reading a product's description.
 *
 * @param string $owner   Object key of the post.
 * @param string $content Content, as stored.
 * @return string
 */
function swiftling_translate_post_content( $owner, $content ) {
	if ( has_blocks( $content ) ) {
		return serialize_blocks( swiftling_translate_blocks( $owner, parse_blocks( $content ), '' ) );
	}
	return swiftling_transform( $owner, 'field/post_content', 'field:post_content', $content );
}

/**
 * Post content, before WordPress renders it. Content with blocks is handled block by block, so
 * here it only opens a frame that ties those blocks to the post. Content without blocks is one
 * value: classic HTML, or shortcodes from builders such as WPBakery, Divi 4 and Avada.
 *
 * Content without blocks still goes through do_blocks() after this, as one freeform block that is
 * the same content. Its frame says so, and swiftling_filter_block() leaves that block alone: walked
 * a second time, outside any frame, every attribute of every shortcode was taken for a setting of
 * the site, and one taken for a text there once, such as a button's hover effect in data-hover,
 * stayed in the site's map and was translated on the page. Blocks a shortcode renders later are
 * the post's, in the same frame.
 *
 * @param string $content Content.
 * @return string
 */
function swiftling_content_start( $content ) {
	$state = &swiftling_state();
	$post  = get_post();

	if ( ! $post || ! is_string( $content ) ) {
		$state['content'][] = 0;
		return $content;
	}

	$owner = swiftling_post_owner( $post->ID );

	if ( has_blocks( $content ) ) {
		$state['content'][] = swiftling_push_frame( $owner, '' );
		return $content;
	}

	$state['content'][] = swiftling_push_frame( $owner, '', true );
	return swiftling_transform( $owner, 'field/post_content', 'field:post_content', $content );
}

/**
 * Closes the frame swiftling_content_start() opened.
 *
 * @param string $content Rendered content.
 * @return string
 */
function swiftling_content_end( $content ) {
	$state = &swiftling_state();
	$frame = array_pop( $state['content'] );
	if ( $frame ) {
		swiftling_pop_frame( $frame );
	}
	return $content;
}

/**
 * Opens a frame for the children of a block or for the blocks of an object.
 *
 * @param string $owner   Object key the children belong to.
 * @param string $path    Path of the parent block inside that object; empty for the object's top level.
 * @param bool   $classic Whether the frame is of content without blocks, already taken whole: the
 *                        freeform block do_blocks() makes of it is that content, see
 *                        swiftling_content_start().
 * @return int Frame number.
 */
function swiftling_push_frame( $owner, $path, $classic = false ) {
	$state             = &swiftling_state();
	$id                = ++$state['frame_seq'];
	$state['frames'][] = array(
		'id'      => $id,
		'owner'   => $owner,
		'path'    => $path,
		'next'    => 0,
		'classic' => $classic,
	);
	return $id;
}

/**
 * Whether a block is the freeform block do_blocks() makes of content without blocks that
 * swiftling_content_start() already took whole: the first freeform block in such a frame. The
 * frame is told it has been seen.
 *
 * @param array $parsed_block Block about to render.
 * @return bool
 */
function swiftling_is_classic_content_block( $parsed_block ) {
	$state = &swiftling_state();
	$last  = count( $state['frames'] ) - 1;
	if ( $last < 0 || empty( $state['frames'][ $last ]['classic'] ) || ! empty( $parsed_block['blockName'] ) ) {
		return false;
	}
	$state['frames'][ $last ]['classic'] = false;
	return true;
}

/**
 * Closes a frame when it is the innermost one.
 *
 * @param int $id Frame number.
 */
function swiftling_pop_frame( $id ) {
	$state = &swiftling_state();
	$top   = end( $state['frames'] );
	if ( $top && $top['id'] === $id ) {
		array_pop( $state['frames'] );
	}
}

/**
 * An excerpt built from the content of a post with blocks: opens a frame that ties its blocks to
 * the post, so they show their translations, as on the post's own page.
 *
 * WordPress builds it in wp_trim_excerpt(), which renders the blocks of the content before the
 * content's own filter, so swiftling_content_start() opens no frame for them. It also leaves some
 * out, such as an image, or a group whose blocks it renders one by one. A block counted by the
 * blocks rendered before it would take the place of another and match no translation, so here each
 * block takes its place from where it stands in the post, see swiftling_next_block_position().
 *
 * Only on a page in another language: a page check reads the content on the post's own page.
 *
 * @param string       $excerpt The excerpt written by hand; empty when WordPress builds one.
 * @param WP_Post|null $post    Post.
 * @return string
 */
function swiftling_excerpt_start( $excerpt, $post = null ) {
	$state = &swiftling_state();
	if ( 'translate' !== $state['mode'] || '' !== $excerpt || ! $post instanceof WP_Post || post_password_required( $post ) || ! has_blocks( $post->post_content ) ) {
		$state['excerpts'][] = 0;
		return $excerpt;
	}
	$frame = swiftling_push_frame( swiftling_post_owner( $post->ID ), '' );

	$state['frames'][ count( $state['frames'] ) - 1 ]['places'] = swiftling_block_places( parse_blocks( $post->post_content ), '' );
	$state['excerpts'][]                                        = $frame;
	return $excerpt;
}

/**
 * Closes the frame swiftling_excerpt_start() opened.
 *
 * @param string $excerpt Excerpt.
 * @return string
 */
function swiftling_excerpt_end( $excerpt ) {
	$state = &swiftling_state();
	$frame = array_pop( $state['excerpts'] );
	if ( $frame ) {
		swiftling_pop_frame( $frame );
	}
	return $excerpt;
}

/**
 * Returns where each block of a post stands, for a frame that places blocks by what they are
 * rather than by how many rendered before them, see swiftling_excerpt_start().
 *
 * @param array  $blocks Parsed blocks.
 * @param string $within Path of the block they are in; empty at the top.
 * @return array<string, string[]> Block signature => paths, in the order of the post.
 */
function swiftling_block_places( $blocks, $within ) {
	$places = array();
	foreach ( $blocks as $index => $block ) {
		$path = ltrim( $within . '/' . $index, '/' );

		$places[ swiftling_block_signature( $block ) ][] = $path;
		if ( ! empty( $block['innerBlocks'] ) ) {
			$places = array_merge_recursive( $places, swiftling_block_places( $block['innerBlocks'], $path ) );
		}
	}
	return $places;
}

/**
 * Returns what tells a block apart, the same before and after WordPress hands it to the filter
 * that renders it: its name and its own HTML.
 *
 * @param array $block Parsed block.
 * @return string
 */
function swiftling_block_signature( $block ) {
	$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
	$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
	return md5( $name . "\0" . $html );
}

/**
 * Takes the next position in the innermost frame and returns the object and path it gives.
 *
 * Outside any frame a block belongs to the template being rendered. A classic theme has no
 * template, and blocks outside the content come from widgets, which are site settings.
 *
 * A frame that knows where its blocks stand, see swiftling_excerpt_start(), gives a block its own
 * place; one it does not know takes the next position, as in any frame.
 *
 * @param array|null $parsed_block Block about to render, when there is one.
 * @return array{0: string, 1: string} Object key and block path.
 */
function swiftling_next_block_position( $parsed_block = null ) {
	$state = &swiftling_state();
	$last  = count( $state['frames'] ) - 1;

	if ( $last >= 0 ) {
		$frame = &$state['frames'][ $last ];
		if ( is_array( $parsed_block ) && isset( $frame['places'] ) ) {
			$signature = swiftling_block_signature( $parsed_block );
			if ( ! empty( $frame['places'][ $signature ] ) ) {
				return array( $frame['owner'], array_shift( $frame['places'][ $signature ] ) );
			}
		}
		$index = $frame['next']++;
		return array( $frame['owner'], ltrim( $frame['path'] . '/' . $index, '/' ) );
	}

	global $_wp_current_template_id;
	$owner                    = $_wp_current_template_id ? 'template:' . $_wp_current_template_id : 'options';
	$index                    = isset( $state['roots'][ $owner ] ) ? $state['roots'][ $owner ] : 0;
	$state['roots'][ $owner ] = $index + 1;

	return array( $owner, (string) $index );
}

/**
 * A block, just before it renders: its attributes and its own HTML.
 *
 * @param array         $parsed_block Block.
 * @param array         $source_block Block as it was before any filter.
 * @param WP_Block|null $parent_block Block that renders this one, if any.
 * @return array
 */
function swiftling_filter_block( $parsed_block, $source_block = array(), $parent_block = null ) {
	if ( swiftling_is_classic_content_block( $parsed_block ) ) {
		return $parsed_block;
	}
	list( $owner, $path ) = swiftling_next_block_position( $parsed_block );

	$name = empty( $parsed_block['blockName'] ) ? 'freeform' : $parsed_block['blockName'];
	$base = 'b/' . $path;

	if ( ! empty( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ) {
		// "metadata" holds the block's name in the editor's list view and its bindings; visitors never see it.
		$attrs = $parsed_block['attrs'];
		unset( $attrs['metadata'] );
		$new = swiftling_transform( $owner, $base . '/a', 'block:' . $name, $attrs );
		if ( $new !== $attrs ) {
			$parsed_block['attrs'] = isset( $parsed_block['attrs']['metadata'] ) ? array_merge( $new, array( 'metadata' => $parsed_block['attrs']['metadata'] ) ) : $new;
			swiftling_forget_block_attributes( $parent_block, $source_block );
		}
	}

	if ( ! empty( $parsed_block['innerContent'] ) && is_array( $parsed_block['innerContent'] ) ) {
		$changed = false;
		foreach ( $parsed_block['innerContent'] as $index => $chunk ) {
			if ( ! is_string( $chunk ) ) {
				continue;
			}
			$new = swiftling_transform( $owner, $base . '/c' . $index, 'block:' . $name . '#html', $chunk );
			if ( $new !== $chunk ) {
				$parsed_block['innerContent'][ $index ] = $new;
				$changed                                = true;
			}
		}
		if ( $changed ) {
			$parsed_block['innerHTML'] = implode( '', array_filter( $parsed_block['innerContent'], 'is_string' ) );
		}
	}

	$child_owner                     = swiftling_block_child_owner( $parsed_block, $owner );
	$parsed_block['swiftling_frame'] = swiftling_push_frame( $child_owner, $child_owner === $owner ? $path : '' );

	return $parsed_block;
}

/**
 * Makes an inner block read its attributes again.
 *
 * WordPress prepares a block's attributes the first time anything reads them and does not prepare
 * them again after this filter. The navigation block, for one, reads the attributes of every link
 * in a submenu before the submenu renders, so a changed label would not show.
 *
 * @param WP_Block|null $parent_block Block that renders the inner block.
 * @param array         $source_block The inner block as it was before the filter.
 */
function swiftling_forget_block_attributes( $parent_block, $source_block ) {
	if ( ! $parent_block instanceof WP_Block || empty( $parent_block->inner_blocks ) ) {
		return;
	}
	foreach ( $parent_block->inner_blocks as $child ) {
		if ( $child->parsed_block === $source_block ) {
			unset( $child->attributes );
			return;
		}
	}
}

/**
 * Closes the frame of a block once it has rendered.
 *
 * @param string $content      Rendered block.
 * @param array  $parsed_block Block.
 * @return string
 */
function swiftling_block_end( $content, $parsed_block ) {
	if ( isset( $parsed_block['swiftling_frame'] ) ) {
		swiftling_pop_frame( $parsed_block['swiftling_frame'] );
	}
	return $content;
}

/**
 * Returns the object the children of a block belong to. Blocks that pull in another object
 * hand their children over to it: a template part, a synced pattern or a navigation menu.
 *
 * @param array  $parsed_block Block.
 * @param string $owner        Object the block itself belongs to.
 * @return string
 */
function swiftling_block_child_owner( $parsed_block, $owner ) {
	$attrs = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();

	switch ( $parsed_block['blockName'] ) {
		case 'core/template-part':
			if ( ! empty( $attrs['slug'] ) ) {
				return 'part:' . ( empty( $attrs['theme'] ) ? get_stylesheet() : $attrs['theme'] ) . '//' . $attrs['slug'];
			}
			break;
		case 'core/block':
			if ( ! empty( $attrs['ref'] ) ) {
				return 'post:' . (int) $attrs['ref'];
			}
			break;
		case 'core/navigation':
			if ( ! empty( $attrs['ref'] ) ) {
				return 'post:' . (int) $attrs['ref'];
			}
			// Without a menu or links of its own, the block shows the site's fallback menu, which core finds the same way.
			if ( empty( $parsed_block['innerBlocks'] ) && class_exists( 'WP_Navigation_Fallback' ) ) {
				$fallback = WP_Navigation_Fallback::get_fallback();
				if ( $fallback instanceof WP_Post ) {
					return 'post:' . $fallback->ID;
				}
			}
			break;
	}

	return $owner;
}

/**
 * Navigation links. Core renders them straight from the menu, without the general block filter,
 * but hands them over here first.
 *
 * @param WP_Block_List $inner_blocks Links of one navigation block.
 * @return WP_Block_List
 */
function swiftling_filter_navigation( $inner_blocks ) {
	foreach ( $inner_blocks as $block ) {
		list( $owner, $path ) = swiftling_next_block_position();

		$attrs = isset( $block->parsed_block['attrs'] ) && is_array( $block->parsed_block['attrs'] ) ? $block->parsed_block['attrs'] : array();
		$new   = swiftling_transform( $owner, 'b/' . $path . '/a', 'block:' . $block->name, $attrs );

		if ( $new !== $attrs ) {
			$block->parsed_block['attrs'] = $new;
			// Attributes are prepared from the parsed block the first time they are read.
			unset( $block->attributes );
		}
	}
	return $inner_blocks;
}

/**
 * A category, tag or other term, wherever WordPress hands one over: in archive titles, lists of
 * categories, the terms of a post and menus. Its name and description are its texts.
 *
 * In probe mode only terms read while the page renders are marked, as for options: terms read
 * while WordPress sets up the request are looked up by name or slug, not shown.
 *
 * @param WP_Term|mixed $term Term.
 * @return WP_Term|mixed
 */
function swiftling_filter_term( $term ) {
	$state = &swiftling_state();
	if ( ! $term instanceof WP_Term || $state['guard'] || ( 'probe' === $state['mode'] && ! did_action( 'template_redirect' ) ) ) {
		return $term;
	}

	$owner = 'term:' . $term->term_id;
	if ( 'translate' === $state['mode'] && ! swiftling_object_hashes( $owner ) ) {
		return $term;
	}

	$name        = swiftling_transform_text( $owner, 'field/name', 'term:name', $term->name );
	$description = swiftling_transform_text( $owner, 'field/description', 'term:description', $term->description );
	if ( $name === $term->name && $description === $term->description ) {
		return $term;
	}

	// WordPress hands every caller a term of its own, but a copy keeps the cached one untouched for sure.
	$term              = clone $term;
	$term->name        = $name;
	$term->description = $description;
	return $term;
}

/**
 * Whether files of the media library are read now. In probe mode only while the page renders, as
 * for options: addresses asked for while WordPress and plugins start up are no content.
 *
 * @return bool
 */
function swiftling_reads_attachments() {
	$state = &swiftling_state();
	return ! $state['guard'] && ( 'translate' === $state['mode'] || ( 'probe' === $state['mode'] && did_action( 'template_redirect' ) ) );
}

/**
 * Returns the address of an attachment's file as WordPress stores it, without SwiftLing.
 *
 * @param int $attachment_id Attachment ID.
 * @return string Empty when it has none.
 */
function swiftling_attachment_file( $attachment_id ) {
	$state          = &swiftling_state();
	$guard          = $state['guard'];
	$state['guard'] = true;
	$url            = wp_get_attachment_url( $attachment_id );
	$state['guard'] = $guard;
	return is_string( $url ) ? $url : '';
}

/**
 * Runs the address of an attachment's file through the current mode. The file is a value of the
 * attachment, so an image used on many pages is translated once, as its alt text is.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $file          Address of the file.
 * @return string The address with a marker, its translation, or the address itself.
 */
function swiftling_transform_attachment_file( $attachment_id, $file ) {
	return swiftling_transform( swiftling_post_owner( $attachment_id ), 'file', 'attachment:file', $file );
}

/**
 * Returns the attachment a translated address points to, when it is another image of the media
 * library, so the translated page can show it in the size and with the sources it asks for.
 *
 * @param string $url           Translated address.
 * @param int    $attachment_id Attachment the original address belongs to.
 * @return int Attachment ID, or 0 for an address outside the media library.
 */
function swiftling_translated_attachment( $url, $attachment_id ) {
	static $found = array();
	if ( ! isset( $found[ $url ] ) ) {
		$found[ $url ] = (int) attachment_url_to_postid( $url );
	}
	$other = $found[ $url ];
	return $other && $other !== (int) $attachment_id && wp_attachment_is_image( $other ) ? $other : 0;
}

/**
 * The address of a file of the media library, as a link to a document or the full size of an image.
 *
 * @param string $url           Address.
 * @param int    $attachment_id Attachment ID.
 * @return string
 */
function swiftling_filter_attachment_url( $url, $attachment_id ) {
	if ( ! is_string( $url ) || '' === $url || swiftling_has_marker( $url ) || ! swiftling_reads_attachments() ) {
		return $url;
	}
	return swiftling_transform_attachment_file( $attachment_id, $url );
}

/**
 * An image of the media library in one size, as builders and themes show an image they keep by ID.
 *
 * The probe marks the address of the size with the marker of the attachment's file. A translated
 * page shows the other image in the same size, or the translated address as it is when it is no
 * image of the media library.
 *
 * @param array|false  $image         Address, width, height and whether it is a resized copy.
 * @param int          $attachment_id Attachment ID.
 * @param string|int[] $size          Size asked for.
 * @param bool         $icon          Whether an icon stands for a file that is no image.
 * @return array|false
 */
function swiftling_filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
	if ( ! is_array( $image ) || empty( $image[0] ) || ! swiftling_reads_attachments() ) {
		return $image;
	}
	$file  = swiftling_attachment_file( $attachment_id );
	$value = '' === $file ? $file : swiftling_transform_attachment_file( $attachment_id, $file );
	if ( $value === $file ) {
		return $image;
	}

	if ( 'probe' === swiftling_mode() ) {
		// The full size already carries the marker, from the address of the file.
		if ( ! swiftling_has_marker( $image[0] ) ) {
			$image[0] .= substr( $value, strlen( $file ) );
		}
		return $image;
	}

	$other = swiftling_translated_attachment( $value, $attachment_id );
	if ( $other ) {
		// The other image is shown as it is, without translations of its own, so two images
		// translated into each other cannot call this again and again.
		$state          = &swiftling_state();
		$state['guard'] = true;
		$replaced       = wp_get_attachment_image_src( $other, $size, $icon );
		$state['guard'] = false;
		if ( $replaced ) {
			return $replaced;
		}
	}
	$image[0] = $value;
	return $image;
}

/**
 * The sizes of an image a translated page chooses from (srcset): those of the other image, or
 * none for an address outside the media library, so the page cannot load the original after all.
 *
 * @param array  $image_meta    Sizes and file of the image.
 * @param int[]  $size_array    Width and height shown.
 * @param string $image_src     Address shown.
 * @param int    $attachment_id Attachment ID.
 * @return array
 */
function swiftling_filter_srcset_meta( $image_meta, $size_array, $image_src, $attachment_id ) {
	if ( 'translate' !== swiftling_mode() || ! swiftling_reads_attachments() ) {
		return $image_meta;
	}
	$file  = swiftling_attachment_file( $attachment_id );
	$value = '' === $file ? $file : swiftling_transform_attachment_file( $attachment_id, $file );
	if ( $value === $file ) {
		return $image_meta;
	}
	$other = swiftling_translated_attachment( $value, $attachment_id );
	$meta  = $other ? wp_get_attachment_metadata( $other ) : false;
	return is_array( $meta ) ? $meta : array();
}

/**
 * Options: the site title and tagline, the Customizer, classic widgets and plugin texts.
 *
 * In probe mode only options read while the page renders are marked; options read while
 * WordPress and plugins start up hold settings far more often than text.
 *
 * @param mixed  $pre           Value from an earlier filter, or false.
 * @param string $option        Option name.
 * @param mixed  $default_value Default value.
 * @return mixed False to let WordPress read the option itself.
 */
function swiftling_filter_option( $pre, $option, $default_value ) {
	$state = &swiftling_state();

	if ( false !== $pre || $state['guard'] ) {
		return $pre;
	}
	if ( 'probe' === $state['mode'] && ! did_action( 'template_redirect' ) && ! $state['collect'] ) {
		return $pre;
	}
	// What was decided for the option in this request: a page reads thousands of options, most of
	// them many times. Saving the option forgets it, see swiftling_forget_option().
	$cache_key = 'option|' . $option;
	if ( array_key_exists( $cache_key, $state['values'] ) ) {
		return $state['values'][ $cache_key ];
	}
	$path = 'option/' . swiftling_escape_segment( $option );
	if ( 'translate' === $state['mode'] && ! swiftling_object_has_source( 'options', $path ) ) {
		$state['values'][ $cache_key ] = $pre;
		return $pre;
	}
	// An option whose name looks like a setting's is a text all the same when its plugin says so,
	// as an options field named "block_title", or when the user chose it as a text, as a code
	// snippet kept under a name with "_key" in it.
	if ( swiftling_skip_option( $option ) && ! swiftling_option_holds_text( $option ) ) {
		$state['values'][ $cache_key ] = $pre;
		return $pre;
	}

	$state['guard'] = true;
	$raw            = get_option( $option, $default_value );
	$state['guard'] = false;

	$result = false;
	if ( false !== $raw && null !== $raw && '' !== $raw ) {
		$new = swiftling_transform( 'options', $path, 'option:' . $option, $raw );
		if ( $new !== $raw ) {
			$result = $new;
		}
	}

	$state['values'][ $cache_key ] = $result;
	return $result;
}

/**
 * Returns an option as it is stored, without SwiftLing: SwiftLing's own settings and what it reads
 * to judge another value. Reading SwiftLing's own options through swiftling_filter_option() would
 * ask them again, for ever.
 *
 * @param string $option        Option name.
 * @param mixed  $default_value Value when the option is not saved.
 * @return mixed
 */
function swiftling_raw_option( $option, $default_value = false ) {
	$state          = &swiftling_state();
	$guard          = $state['guard'];
	$state['guard'] = true;
	try {
		return get_option( $option, $default_value );
	} finally {
		$state['guard'] = $guard;
	}
}

/**
 * Returns a field of a post as it is stored, without SwiftLing: a text the editor offers as the
 * original, which a translation must not reach.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @return string The value, or an empty string when it is not a string.
 */
function swiftling_raw_post_meta( $post_id, $key ) {
	$state          = &swiftling_state();
	$guard          = $state['guard'];
	$state['guard'] = true;
	try {
		$value = get_post_meta( $post_id, $key, true );
	} finally {
		$state['guard'] = $guard;
	}
	return is_string( $value ) ? $value : '';
}

/**
 * Forgets a transformed option after it was written in this request.
 *
 * @param string $option Option name.
 */
function swiftling_forget_option( $option ) {
	$state = &swiftling_state();
	unset( $state['values'][ 'option|' . $option ] );
}

/**
 * Whether an option holds a text by declaration, as a whole or somewhere inside it: its plugin
 * declares it, or the user chose a value in it on the Themes & plugins screen.
 *
 * @param string $option Option name.
 * @return bool
 */
function swiftling_option_holds_text( $option ) {
	return true === \SwiftLing\Builders\Registry::fieldHoldsText( 'options', 'option/' . swiftling_escape_segment( $option ) )
		|| \SwiftLing\Packages\Settings::holdsTextIn( $option );
}

/**
 * Whether an option never holds text a visitor reads. WordPress settings whose values look like
 * text, such as the character set "UTF-8" or the date format "F j, Y", would otherwise be marked
 * and could break the page the probe looks at.
 *
 * @param string $option Option name.
 * @return bool
 */
function swiftling_skip_option( $option ) {
	global $wpdb;

	static $settings = array(
		'siteurl',
		'home',
		'blog_charset',
		'html_type',
		'date_format',
		'time_format',
		'links_updated_date_format',
		'timezone_string',
		'gmt_offset',
		'start_of_week',
		'WPLANG',
		'permalink_structure',
		'category_base',
		'tag_base',
		'template',
		'stylesheet',
		'current_theme',
		'admin_email',
		'new_admin_email',
		'upload_path',
		'upload_url_path',
		'rewrite_rules',
		'cron',
		'active_plugins',
		'recently_activated',
		'uninstall_plugins',
		'sidebars_widgets',
		'default_role',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'blog_public',
		'avatar_default',
		'avatar_rating',
		'default_comment_status',
		'default_ping_status',
		'comment_order',
		'default_comments_page',
	);

	// SwiftLing's own options start with its name. The settings of its widget do not
	// (widget_swiftling_switcher) and hold a title a person wrote.
	return in_array( $option, $settings, true )
		|| $wpdb->prefix . 'user_roles' === $option
		|| 1 === preg_match( '/^_|^swiftling|transient|cache|lock|hash|nonce|token|secret|salt|_key|version|license/i', $option );
}

/**
 * Text from the code of a theme or plugin, through gettext.
 *
 * @param string $translation Text as the site shows it.
 * @param string $text        Original text in the code.
 * @param string $domain      Text domain of the theme or plugin.
 * @return string
 */
function swiftling_filter_gettext( $translation, $text, $domain ) {
	return swiftling_transform_string( $translation, $domain, array( 't' => $text ) );
}

/**
 * Text with a context, from the code of a theme or plugin.
 *
 * @param string $translation Text as the site shows it.
 * @param string $text        Original text in the code.
 * @param string $context     Context of the text.
 * @param string $domain      Text domain.
 * @return string
 */
function swiftling_filter_gettext_with_context( $translation, $text, $context, $domain ) {
	return swiftling_transform_string(
		$translation,
		$domain,
		array(
			't' => $text,
			'c' => $context,
		)
	);
}

/**
 * Text with singular and plural forms, from the code of a theme or plugin.
 *
 * @param string $translation Text as the site shows it.
 * @param string $single      Singular in the code.
 * @param string $plural      Plural in the code.
 * @param int    $number      Number the form is chosen for.
 * @param string $domain      Text domain.
 * @return string
 */
function swiftling_filter_ngettext( $translation, $single, $plural, $number, $domain ) {
	return swiftling_transform_string(
		$translation,
		$domain,
		array(
			't' => $single,
			'p' => $plural,
			'n' => (int) $number,
		)
	);
}

/**
 * Text with plural forms and a context, from the code of a theme or plugin.
 *
 * @param string $translation Text as the site shows it.
 * @param string $single      Singular in the code.
 * @param string $plural      Plural in the code.
 * @param int    $number      Number the form is chosen for.
 * @param string $context     Context of the text.
 * @param string $domain      Text domain.
 * @return string
 */
function swiftling_filter_ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
	return swiftling_transform_string(
		$translation,
		$domain,
		array(
			't' => $single,
			'p' => $plural,
			'n' => (int) $number,
			'c' => $context,
		)
	);
}

/**
 * Runs a text from code through the current mode. Each theme or plugin is one shared object, so a
 * text such as a widget's default "Click here" is translated once for every page that shows it.
 *
 * A text's place is the text written in the code (its msgid, with its context, and for a text
 * with plural forms which form shows), not the text the site shows: a translated page runs in the
 * language's locale, where WordPress already hands over its own translation, and the user's
 * translation must still be found. Where the user translated nothing, that translation of
 * WordPress, the theme or the plugin shows.
 *
 * In probe mode only text produced while the page renders is marked: text translated while
 * WordPress and plugins start up mostly names settings, post types and screens. The probe notes
 * what the code wrote, so the editor can look up WordPress's own translation of it.
 *
 * @param string $translation Text as the site shows it, in the site's language.
 * @param string $domain      Text domain.
 * @param array  $source      The text in the code: t (text or singular), and where they apply
 *                            c (context), p (plural) and n (number the form is chosen for).
 * @return string
 */
function swiftling_transform_string( $translation, $domain, $source ) {
	$state = &swiftling_state();

	if ( $state['guard'] || 'swiftling' === $domain || ! is_string( $translation ) || '' === $translation ) {
		return $translation;
	}
	if ( 'probe' === $state['mode'] && ! did_action( 'template_redirect' ) ) {
		return $translation;
	}

	$owner = 'gettext:' . $domain;

	if ( 'translate' === $state['mode'] ) {
		$map = swiftling_object_map( $owner );
		// Most themes and plugins have no text translated by the user: nothing to look up.
		if ( ! $map['keys'] ) {
			return $translation;
		}
		// The same text is asked for many times on a page, such as "Add to cart" for each product:
		// what was found for it is kept for the request, the user's translation or none.
		$memo = 'gettext|' . $domain . "\x01" . swiftling_string_id( $source );
		if ( ! array_key_exists( $memo, $state['values'] ) ) {
			$state['values'][ $memo ] = swiftling_user_string( $owner, $map, $source );
		}
		return null === $state['values'][ $memo ] ? $translation : $state['values'][ $memo ];
	}

	$path   = swiftling_string_path( $source );
	$marked = swiftling_transform( $owner, $path, 'gettext', $translation );
	if ( 'probe' === $state['mode'] && $marked !== $translation ) {
		$id = $state['ids'][ $owner . '|' . $path . '|' . md5( $translation ) ];
		// What the code wrote, and its text domain.
		$state['registry'][ $id ]['g'] = array( 'd' => $domain ) + $source;
	}
	return $marked;
}

/**
 * One placeholder of PHP's sprintf() in a text, as "%s", "%2$d" or "%.2f", or "%%" for a percent
 * sign. A space as a flag ("% d") is left out on purpose: in a text such as "50% off", which no
 * code formats, it would make "% o" a placeholder.
 */
const SWIFTLING_PLACEHOLDER = '/%(?:%|(?:(\\d+)\\$)?((?:[-+0]|\\\'.)*)(\\d*)(?:\\.(\\d+))?([bcdeEfFgGhHosuxX]))/';

/**
 * Returns the placeholders of sprintf() in a text from code, each with the value it takes: the
 * number written in it ("%2$s" takes the second), or for one without a number its place among
 * those ("%s and %s" take the first and the second).
 *
 * @param string $text Text.
 * @return array<int, array{raw: string, position: int, type: string}>|null Null when a "%" is no
 *         placeholder, which sprintf() refuses: the text is no format then, or a broken one.
 */
function swiftling_format_placeholders( $text ) {
	if ( false === strpos( $text, '%' ) ) {
		return array();
	}
	$found = array();
	$next  = 0;
	$rest  = preg_replace_callback(
		SWIFTLING_PLACEHOLDER,
		static function ( $placeholder ) use ( &$found, &$next ) {
			if ( '%%' !== $placeholder[0] ) {
				$found[] = array(
					'raw'      => $placeholder[0],
					'position' => '' !== $placeholder[1] ? (int) $placeholder[1] : ++$next,
					'type'     => $placeholder[5],
				);
			}
			return '';
		},
		$text
	);
	return false !== strpos( (string) $rest, '%' ) ? null : $found;
}

/**
 * Checks the placeholders of a translation of a text from code against the original's. The code
 * hands the text to sprintf() with the values the original's placeholders take, so a translation
 * may put them in another order, as a right-to-left language does, but must not ask for a value
 * the original does not take or for it as another type, and must not hold a "%" that is no
 * placeholder: on PHP 8, sprintf() stops the page then. A placeholder the translation leaves out
 * breaks nothing, but the value does not show.
 *
 * An original with no placeholder, or with a "%" that is none, is no format, and anything goes.
 *
 * @param string $original    The text in the code.
 * @param string $translation Its translation.
 * @return array{breaks: bool, missing: string[]} Whether the translation would stop the page, and
 *         the original's placeholders it leaves out.
 */
function swiftling_format_problems( $original, $translation ) {
	$source = swiftling_format_placeholders( $original );
	if ( ! $source ) {
		return array(
			'breaks'  => false,
			'missing' => array(),
		);
	}
	$types = array();
	foreach ( $source as $placeholder ) {
		$types[ $placeholder['position'] ] = $placeholder['type'];
	}
	$target = swiftling_format_placeholders( $translation );
	$breaks = null === $target;
	$used   = array();
	foreach ( (array) $target as $placeholder ) {
		if ( ! isset( $types[ $placeholder['position'] ] ) || $types[ $placeholder['position'] ] !== $placeholder['type'] ) {
			$breaks = true;
		}
		$used[ $placeholder['position'] ] = true;
	}
	$missing = array();
	foreach ( $source as $placeholder ) {
		if ( ! isset( $used[ $placeholder['position'] ] ) ) {
			$missing[] = $placeholder['raw'];
		}
	}
	return array(
		'breaks'  => $breaks,
		'missing' => array_values( array_unique( $missing ) ),
	);
}

/**
 * Returns the path of a text from code inside the object of its theme or plugin.
 *
 * @param array $source The text in the code, see swiftling_transform_string().
 * @return string
 */
function swiftling_string_path( $source ) {
	return 'string/' . md5( swiftling_string_id( $source ) );
}

/**
 * Returns what tells a text from code from every other text of its theme or plugin: its context,
 * its text and, for a text with plural forms, the form shown.
 *
 * @param array $source The text in the code, see swiftling_transform_string().
 * @return string
 */
function swiftling_string_id( $source ) {
	$id = ( isset( $source['c'] ) ? $source['c'] . "\x04" : '' ) . $source['t'];
	if ( isset( $source['p'] ) ) {
		// The singular and the plural are one text; the user translates the form the page showed.
		$id .= "\x00" . $source['p'] . "\x00" . ( 1 === $source['n'] ? 'one' : 'other' );
	}
	return $id;
}

/**
 * Returns the user's translation of a text from code into the language shown, or null when there is
 * none to show.
 *
 * The code puts values into the text with sprintf(): a translation whose placeholders do not fit
 * would stop the page. Such a translation is never shown; the text of WordPress, the theme or the
 * plugin is, in its own translation where it has one.
 *
 * @param string $owner  Object key of the text domain, "gettext:<domain>".
 * @param array  $map    Its map, see swiftling_object_map().
 * @param array  $source The text in the code, see swiftling_transform_string().
 * @return string|null
 */
function swiftling_user_string( $owner, $map, $source ) {
	$key = swiftling_path_key( swiftling_string_path( $source ) );
	if ( ! isset( $map['keys'][ $key ] ) ) {
		return null;
	}
	$translations = swiftling_object_translations( $owner );
	if ( ! isset( $translations[ $key ] ) ) {
		return null;
	}
	$code = isset( $source['p'] ) && 1 !== $source['n'] ? $source['p'] : $source['t'];
	return swiftling_format_problems( $code, $translations[ $key ] )['breaks'] ? null : $translations[ $key ];
}
