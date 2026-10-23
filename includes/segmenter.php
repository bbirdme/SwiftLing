<?php
/**
 * The segmenter: finds the pieces of text in any stored value, whatever wrote it.
 *
 * A value is walked down to its string leaves. JSON stored inside a string and shortcodes are
 * decoded on the way, so the text inside them becomes a leaf as well. Every leaf gets a path inside
 * the value and a rule key: where the value comes from, the nearest element type above the leaf
 * and the field, e.g. "meta:_elementor_data|heading|settings/title".
 *
 * Nothing here knows any builder. Which leaves really are text is not decided here either: leaves
 * that look like human text are only candidates, and the probe decides by looking at where they
 * end up on the rendered page.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the hash of an original text, as the strings table stores it.
 *
 * @param string $text Original text, exactly as stored.
 * @return string 32 lowercase hex characters.
 */
function swiftling_hash( $text ) {
	return md5( $text );
}

/**
 * Returns the text a visitor would read in a value: tags, scripts and styles removed, entities
 * decoded and whitespace collapsed.
 *
 * @param string $value Plain text or HTML.
 * @return string
 */
function swiftling_visible_text( $value ) {
	$text = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Whether a leaf looks like text a person wrote, as opposed to a setting.
 *
 * Settings are never marked, because a marker on a setting can change what the page shows: a
 * builder compares a value such as "custom" or "yes" with a fixed string, and a marked value no
 * longer matches. The cost is that a one-word text in lowercase, such as "prijava", is not a
 * candidate; the editor lists such text as visible on the page but not found as text.
 *
 * @param mixed $value Leaf.
 * @return bool
 */
function swiftling_is_text_like( $value ) {
	if ( ! is_string( $value ) || '' === $value || swiftling_has_marker( $value ) ) {
		return false;
	}

	$text = swiftling_visible_text( $value );

	// No letters at all: numbers, sizes, dates, symbols.
	if ( '' === $text || ! preg_match( '/\p{L}/u', $text ) ) {
		return false;
	}

	// A link, a path or an anchor.
	if ( preg_match( '~^(?:(?:https?|ftp|mailto|tel|sms|data|javascript|whatsapp|viber|skype|callto):|//|/|#|\?|www\.)\S*$~i', $text ) || is_email( $text ) ) {
		return false;
	}

	// One word with an underscore or a backslash is an identifier, such as the class of a widget a
	// builder looks up by name: "SiteOrigin_Widget_Headline_Widget". With a marker it is not found,
	// and the widget does not show at all.
	if ( false === strpos( $text, ' ' ) && strpbrk( $text, '_\\' ) ) {
		return false;
	}

	// A date and time in the form machines exchange, e.g. the end of a countdown: "2027-06-01T00:00:00Z".
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $text ) ) {
		return false;
	}

	// CSS: colours, functions and rules.
	if ( preg_match( '/^(?:rgba?|hsla?|var|calc|url)\(/i', $text ) || ( false !== strpos( $text, '{' ) && false !== strpos( $text, '}' ) && false !== strpos( $text, ':' ) ) ) {
		return false;
	}

	// Capitals or letters outside ASCII mark text a person wrote.
	if ( preg_match( '/\p{Lu}|[^\x00-\x7F]/u', $text ) ) {
		return true;
	}

	// Lowercase ASCII is text only when it has a real word in it. A single token ("yes", "center",
	// "h2") or a list of identifiers ("fas fa-check", "is-style-outline has-text-color") is a setting.
	foreach ( explode( ' ', $text ) as $token ) {
		if ( preg_match( '/^[a-z]{4,}[.,!?:;]?$/', $token ) ) {
			return false !== strpos( $text, ' ' );
		}
	}
	return false;
}

/**
 * Whether a leaf is a link address: an absolute address, a path on the site, or a mail, phone or
 * text-message link. Such a leaf is not text, but where it ends up in a link it is offered for
 * translation, so a page in another language can link somewhere else.
 *
 * @param mixed $value Leaf.
 * @return bool
 */
function swiftling_is_url_like( $value ) {
	return is_string( $value ) && strlen( $value ) < 2048 && ! swiftling_has_marker( $value )
		&& 1 === preg_match( '~^(?:(?:https?:)?//[^\s/]+|/(?!/)|(?:mailto|tel|sms):)\S*$~i', $value );
}

/**
 * Returns the marker the probe appends to a leaf: letters and digits only, so it survives
 * escaping, JSON, slugs and capitals.
 *
 * @param int $id Leaf number in the probe.
 * @return string
 */
function swiftling_marker( $id ) {
	return 'zqk' . $id . 'kqz';
}

/**
 * Whether a value already carries a probe marker.
 *
 * @param string $value Value.
 * @return bool
 */
function swiftling_has_marker( $value ) {
	return 1 === preg_match( '/zqk\d+kqz/i', $value );
}

/**
 * Adds a marker to a leaf. In HTML the marker goes after the last visible character, inside
 * the closing tags, so it lands in the element that holds the text.
 *
 * @param string $value Leaf.
 * @param int    $id    Leaf number in the probe.
 * @return string
 */
function swiftling_add_marker( $value, $id ) {
	$marker = swiftling_marker( $id );

	if ( false !== strpos( $value, '<' ) && preg_match( '~(?:\s*</[a-zA-Z][^>]*>|\s*<br\s*/?>|\s+)+$~', $value, $match, PREG_OFFSET_CAPTURE ) && $match[0][1] > 0 ) {
		return substr( $value, 0, $match[0][1] ) . $marker . substr( $value, $match[0][1] );
	}

	return $value . $marker;
}

/**
 * Walks a stored value down to its string leaves and returns it rebuilt from what the callback
 * returns. Arrays and objects keep their shape. A string that holds JSON or shortcodes comes back
 * untouched when nothing inside it changed.
 *
 * @param mixed    $value    Stored value: string, array or object.
 * @param callable $callback Receives ( string $leaf, string $path, string $rule ) and returns the new leaf.
 * @param string   $path     Path of the value inside its object, e.g. "meta/_elementor_data".
 * @param string   $source   Where the value comes from, for rule keys, e.g. "meta:_elementor_data".
 * @return mixed
 */
function swiftling_walk( $value, $callback, $path, $source ) {
	return swiftling_walk_node( $value, $callback, $path, $source, '', '', true );
}

/**
 * Walks one node of a stored value.
 *
 * @param mixed    $value    Node.
 * @param callable $callback Leaf callback.
 * @param string   $path     Path of the node.
 * @param string   $source   Where the value comes from.
 * @param string   $type     Nearest element type above the node.
 * @param string   $rel      Path from that element to the node, with list positions as "*".
 * @param bool     $is_root  Whether the node is the value itself.
 * @return mixed
 */
function swiftling_walk_node( $value, $callback, $path, $source, $type, $rel, $is_root ) {
	if ( is_string( $value ) ) {
		return swiftling_walk_string( $value, $callback, $path, $source, $type, $rel );
	}

	if ( ! is_array( $value ) && ! $value instanceof stdClass ) {
		return $value;
	}

	$is_object = is_object( $value );
	$items     = $is_object ? get_object_vars( $value ) : $value;

	if ( ! $is_root ) {
		$own_type = swiftling_element_type( $items );
		if ( '' !== $own_type ) {
			$type = $own_type;
			$rel  = '';
		}
	}

	$changed = false;
	foreach ( $items as $key => $item ) {
		if ( ! is_string( $item ) && ! is_array( $item ) && ! $item instanceof stdClass ) {
			continue;
		}
		$segment = is_int( $key ) ? swiftling_list_segment( $key, $item ) : swiftling_escape_segment( (string) $key );
		$new     = swiftling_walk_node(
			$item,
			$callback,
			$path . '/' . $segment,
			$source,
			$type,
			ltrim( $rel . '/' . ( is_int( $key ) ? '*' : swiftling_escape_segment( (string) $key ) ), '/' ),
			false
		);
		if ( $new !== $item ) {
			$items[ $key ] = $new;
			$changed       = true;
		}
	}

	if ( ! $changed ) {
		return $value;
	}

	return $is_object ? (object) $items : $items;
}

/**
 * Walks a string: JSON and shortcodes inside it are decoded, anything else is a leaf.
 *
 * @param string   $value    String.
 * @param callable $callback Leaf callback.
 * @param string   $path     Path of the string.
 * @param string   $source   Where the value comes from.
 * @param string   $type     Nearest element type above the string.
 * @param string   $rel      Path from that element to the string.
 * @return string
 */
function swiftling_walk_string( $value, $callback, $path, $source, $type, $rel ) {
	// A value the user chose as one text on the Themes & plugins screen, such as a tracking script
	// in a plugin's settings, is one leaf: what it holds is never split into JSON, shortcodes or
	// attributes, so its translation replaces it as a whole.
	if ( \SwiftLing\Packages\Settings::isWhole( $path ) ) {
		return call_user_func( $callback, $value, $path, swiftling_rule_key( $source, $type, $rel ) );
	}

	$trimmed = ltrim( $value );

	if ( '' !== $trimmed && ( '[' === $trimmed[0] || '{' === $trimmed[0] ) ) {
		$decoded = json_decode( $value );
		if ( is_array( $decoded ) || is_object( $decoded ) ) {
			$new = swiftling_walk_node( $decoded, $callback, $path . '/@json', $source, $type, $rel, false );
			return $new === $decoded ? $value : wp_json_encode( $new );
		}
	}

	$with_shortcodes = swiftling_walk_shortcodes( $value, $callback, $path, $source );
	if ( null !== $with_shortcodes ) {
		return $with_shortcodes;
	}

	$with_attributes = swiftling_walk_html_attributes( $value, $callback, $path, $source, $type, $rel );
	if ( null !== $with_attributes ) {
		return $with_attributes;
	}

	return call_user_func( $callback, $value, $path, swiftling_rule_key( $source, $type, $rel ) );
}

/**
 * Walks HTML without visible text whose text is in attributes: the alt text of an image, a title
 * or a label, as in the HTML a cover or image block saves around its image. Such HTML is no text
 * as a whole, so each of these attributes is a leaf of its own; the rest of the HTML stays as it is.
 * So is the address of an image, a video or audio, and of a link around them (src and href), so a
 * language can show another image or link elsewhere.
 *
 * Text attributes and addresses are numbered apart in their paths, as alt0, src0: the paths of
 * text attributes stay as they were before addresses became leaves, and so do their translations.
 *
 * HTML with visible text is a leaf as a whole, and its attributes are translated with it.
 *
 * @param string   $value    String.
 * @param callable $callback Leaf callback.
 * @param string   $path     Path of the string.
 * @param string   $source   Where the value comes from.
 * @param string   $type     Nearest element type above the string.
 * @param string   $rel      Path from that element to the string.
 * @return string|null The rebuilt string, or null when it is not HTML with text only in such attributes.
 */
function swiftling_walk_html_attributes( $value, $callback, $path, $source, $type, $rel ) {
	if ( false === strpos( $value, '<' ) || '' !== swiftling_visible_text( $value ) ) {
		return null;
	}
	if ( ! preg_match_all( '~(\s(alt|title|aria-label|src|href)=)(["\'])([^"\']*)\3~i', $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	$result  = '';
	$offset  = 0;
	$changed = false;
	$counts  = array(
		'text'    => 0,
		'address' => 0,
	);
	foreach ( $matches as $match ) {
		$text  = html_entity_decode( $match[4][0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name  = strtolower( $match[2][0] );
		$group = in_array( $name, array( 'src', 'href' ), true ) ? 'address' : 'text';
		// Only addresses visitors see or follow; a marker in the address of a script or a style
		// would keep it from loading while the page is checked.
		if ( 'address' === $group && ! in_array( swiftling_html_tag_at( $value, $match[0][1] ), array( 'img', 'video', 'audio', 'source', 'a', 'iframe' ), true ) ) {
			continue;
		}
		$index = $counts[ $group ]++;
		$new   = '' === trim( $text ) ? $text : call_user_func( $callback, $text, $path . '/@' . $name . $index, swiftling_rule_key( $source, $type, ltrim( $rel . '/@' . $name, '/' ) ) );
		if ( $new === $text ) {
			continue;
		}
		$start   = $match[4][1];
		$result .= substr( $value, $offset, $start - $offset ) . esc_attr( $new );
		$offset  = $start + strlen( $match[4][0] );
		$changed = true;
	}

	return $changed ? $result . substr( $value, $offset ) : $value;
}

/**
 * Returns the name of the tag a position in HTML is inside, such as "img" for an attribute of an image.
 *
 * @param string $html     HTML.
 * @param int    $position Byte offset.
 * @return string Tag name in lower case, or an empty string outside a tag.
 */
function swiftling_html_tag_at( $html, $position ) {
	return 1 === preg_match( '~<([a-z][a-z0-9-]*)\b[^<>]*$~i', substr( $html, 0, $position ), $found ) ? strtolower( $found[1] ) : '';
}

/**
 * Walks a string that contains registered shortcodes. The text between shortcodes, every
 * attribute and every enclosed content become leaves; a shortcode is written again only when
 * something inside it changed.
 *
 * @param string   $value    String.
 * @param callable $callback Leaf callback.
 * @param string   $path     Path of the string.
 * @param string   $source   Where the value comes from.
 * @return string|null The rebuilt string, or null when the string holds no registered shortcode.
 */
function swiftling_walk_shortcodes( $value, $callback, $path, $source ) {
	global $shortcode_tags;

	if ( empty( $shortcode_tags ) || false === strpos( $value, '[' ) ) {
		return null;
	}

	$pattern = get_shortcode_regex();
	if ( ! preg_match_all( "/$pattern/", $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	$result  = '';
	$offset  = 0;
	$changed = false;

	foreach ( $matches as $index => $match ) {
		$whole = $match[0][0];
		$start = $match[0][1];

		// Text before this shortcode.
		$before  = substr( $value, $offset, $start - $offset );
		$result .= swiftling_walk_text_segment( $before, $callback, $path . '/@t' . $index, $source, $changed );
		$offset  = $start + strlen( $whole );

		// [[tag]] is an escaped shortcode, shown literally.
		if ( '[' === $match[1][0] && ']' === $match[6][0] ) {
			$result .= $whole;
			continue;
		}

		$tag   = $match[2][0];
		$attrs = shortcode_parse_atts( $match[3][0] );
		$attrs = is_array( $attrs ) ? $attrs : array();
		$base  = $path . '/@sc' . $index;

		$new_attrs = array();
		foreach ( $attrs as $name => $attr ) {
			// An attribute that names what the shortcode has to find is no leaf: a marker in it would
			// keep the shortcode from finding it, and the page would show nothing of it.
			if ( ! is_string( $attr ) || ( is_string( $name ) && swiftling_skip_shortcode_attribute( $name ) ) ) {
				$new_attrs[ $name ] = $attr;
				continue;
			}
			$new_attrs[ $name ] = swiftling_walk_string( $attr, $callback, $base . '/' . swiftling_escape_segment( (string) $name ), $source, $tag, 'attr/' . ( is_int( $name ) ? '*' : $name ) );
		}

		$content     = $match[5][0];
		$has_closing = false !== strpos( $whole, '[/' . $tag . ']' );
		$new_content = '' === $content ? '' : swiftling_walk_string( $content, $callback, $base . '/content', $source, $tag, 'content' );

		if ( $new_attrs === $attrs && $new_content === $content ) {
			$result .= $whole;
			continue;
		}

		$changed = true;
		$result .= '[' . $tag;
		foreach ( $new_attrs as $name => $attr ) {
			$attr    = str_replace( '"', '&quot;', (string) $attr );
			$result .= is_int( $name ) ? ' "' . $attr . '"' : ' ' . $name . '="' . $attr . '"';
		}
		$result .= ( $has_closing ? ']' . $new_content . '[/' . $tag . ']' : ( '/' === $match[4][0] ? ' /]' : ']' ) );
	}

	$result .= swiftling_walk_text_segment( substr( $value, $offset ), $callback, $path . '/@t' . count( $matches ), $source, $changed );

	return $changed ? $result : $value;
}

/**
 * Whether an attribute of a shortcode names what the shortcode has to find, instead of holding
 * text a visitor reads: the id of a form or a post, a list of ids, a key, a slug or a hash, and
 * the class or the style the theme matches. Such a value is left as it is, as the address of a
 * script or a style is in swiftling_walk_html_attributes(): a marker in it keeps the shortcode
 * from finding what it names, so the page a check looks at shows nothing of it, and the check
 * finds none of its texts.
 *
 * Attributes that hold text, such as a title or a label, stay leaves. A value that only looks
 * like a setting is left alone by swiftling_is_text_like() anyway, also inside a block, where what
 * the block saved between its tags counts as text but the attributes of a shortcode only by look,
 * see swiftling_is_declared_text(). This rule is about the names: an identifier can look like a
 * word, as a form named "contact".
 *
 * @param string $name Attribute name, as shortcode_parse_atts() returns it, in lower case.
 * @return bool
 */
function swiftling_skip_shortcode_attribute( $name ) {
	return 1 === preg_match( '/(?:^|[_-])(?:ids?|keys?|slugs?|hash|uid|uuid|guid|class|style)$/i', $name );
}

/**
 * Walks the text between shortcodes.
 *
 * @param string   $text     Text segment.
 * @param callable $callback Leaf callback.
 * @param string   $path     Path of the segment.
 * @param string   $source   Where the value comes from.
 * @param bool     $changed  Set to true when the segment changed.
 * @return string
 */
function swiftling_walk_text_segment( $text, $callback, $path, $source, &$changed ) {
	if ( '' === trim( $text ) ) {
		return $text;
	}
	$new = call_user_func( $callback, $text, $path, swiftling_rule_key( $source, 'shortcode-text', '' ) );
	if ( $new !== $text ) {
		$changed = true;
	}
	return $new;
}

/**
 * Returns the type of an element in a builder's data, when the node carries one.
 *
 * Builders name the kind of each element in a field next to its settings: "widgetType" in
 * Elementor, "blockName" in blocks, "type" in Beaver Builder settings, "name" in Bricks. The type
 * only groups what the probe learns; the probe works without it.
 *
 * @param array $node Array or object properties.
 * @return string
 */
function swiftling_element_type( $node ) {
	foreach ( array( 'widgetType', 'blockName', 'elType', 'type', 'name' ) as $key ) {
		if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && preg_match( '~^[a-z][a-z0-9_\-/]{0,60}$~i', $node[ $key ] ) ) {
			return $node[ $key ];
		}
	}
	return '';
}

/**
 * Returns the path segment of a list item: its id when it has one, so the path survives
 * reordering, otherwise its position.
 *
 * @param int   $key  Position.
 * @param mixed $item List item.
 * @return string
 */
function swiftling_list_segment( $key, $item ) {
	$props = is_object( $item ) ? get_object_vars( $item ) : $item;
	if ( is_array( $props ) ) {
		foreach ( array( 'id', '_id' ) as $id_key ) {
			if ( isset( $props[ $id_key ] ) && ( is_string( $props[ $id_key ] ) || is_int( $props[ $id_key ] ) ) && '' !== (string) $props[ $id_key ] ) {
				return '#' . swiftling_escape_segment( (string) $props[ $id_key ] );
			}
		}
	}
	return (string) $key;
}

/**
 * Escapes a key for use in a path.
 *
 * @param string $key Key.
 * @return string
 */
function swiftling_escape_segment( $key ) {
	return str_replace( array( '%', '/' ), array( '%25', '%2F' ), $key );
}

/**
 * Builds a rule key, shortened with a hash when it would not fit the rules table.
 *
 * @param string $source Where the value comes from.
 * @param string $type   Element type.
 * @param string $rel    Path from the element to the leaf.
 * @return string
 */
function swiftling_rule_key( $source, $type, $rel ) {
	$key = $source . '|' . $type . '|' . $rel;
	return strlen( $key ) > 191 ? substr( $key, 0, 150 ) . '|' . md5( $key ) : $key;
}
