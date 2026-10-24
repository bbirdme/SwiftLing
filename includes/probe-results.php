<?php
/**
 * What the editor learns from a probe.
 *
 * The browser reports where each marker ended up on the page. From that, every marked value gets
 * a verdict:
 *
 * - text, when its marker landed in visible text, in an alt, title, placeholder or label, in the
 *   document title, in a meta description or in structured data;
 * - not text, when its marker landed only in a class, an ID, a style, an address or a script;
 * - not seen, when its marker landed nowhere. The page rendered without markers settles some of
 *   these: a value whose text shows there was hidden by a marker on another value.
 *
 * Texts are stored in the maps of their objects, what was learned is stored as rules, and the
 * editor gets the rows to show.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Places on a page where a marker means the value is text.
 */
const SWIFTLING_TEXT_PLACES = array( 'text', 'alt', 'title', 'placeholder', 'aria-label', 'label', 'value', 'head-title', 'meta', 'jsonld' );

/**
 * Kinds of addresses the editor offers: a link, an image and a video or audio. Each may point
 * somewhere else in another language; empty means the same address as in the original.
 */
const SWIFTLING_ADDRESS_KINDS = array( 'url', 'image', 'video' );

/**
 * Decides, for every marked value, whether it is text.
 *
 * A value that shows as text but also ends up in a class is a setting that happens to be printed
 * somewhere, such as an icon name, and is not translated. A value that also ends up in an ID is
 * text the page uses as an anchor; it is translated and listed for checking.
 *
 * A value whose marker landed nowhere is rescued when its text shows on the page rendered without
 * markers, unless another value already accounts for that text: a site name kept both in the
 * options and in a builder's settings shows once, from one of them. Many block plugins keep a text
 * both as an attribute and in the block's saved HTML, which is what shows; such an attribute is
 * left out, because translating it would change nothing on the page.
 *
 * A link address whose marker landed in a link becomes a link of its own. It joins the text inside
 * the same link, such as a button's label, and otherwise gets a row of its own. An address whose
 * marker landed in an image or a video, or in a style or a data attribute when the address itself
 * is one of an image or a video, becomes an image or a video of its own.
 *
 * @param array $leaves  Leaf number => array( o: owner, p: path, v: value, r: rule ), as the probe printed it.
 * @param array $found   Leaf number => list of landings: array( where, kind, region, order, el, link ).
 * @param array $control Texts of the page rendered without markers: list of array( t: text, k: kind, r: region ).
 * @return array{units: array, links: array, not_seen: array, classed: array, rules: array}
 *         Units carry the leaf's fields plus kind, region, order, mixed, rescued and, when they sit in a link, the link.
 */
function swiftling_probe_verdicts( $leaves, $found, $control ) {
	$units        = array();
	$links        = array();
	$unseen       = array();
	$classed      = array();
	$rules        = array();
	$learn        = function ( $rule, $verdict ) use ( &$rules ) {
		// Texts from code have no element type or field to learn about.
		if ( 0 === strpos( $rule, 'gettext|' ) ) {
			return;
		}
		$rules[ $rule ] = isset( $rules[ $rule ] ) && $rules[ $rule ] !== $verdict ? SWIFTLING_RULE_MIXED : $verdict;
	};
	$explains     = array();
	$block_pieces = array();

	foreach ( $leaves as $id => $leaf ) {
		$landings = isset( $found[ $id ] ) ? $found[ $id ] : array();
		// A field the builder declares as text is text also where a script reads it from a data
		// attribute, and a declared text that looks like an address, such as "/month", is no link.
		$declared = swiftling_is_declared_text( $leaf['v'], $leaf['r'], $leaf['o'], $leaf['p'] );

		// An address the builder keeps in a text field, such as the address of a video, is an address
		// all the same. A short path like "/month" in a text field is text.
		if ( swiftling_is_url_like( $leaf['v'] ) && ( ! $declared || 1 === preg_match( '~^(?:https?:)?//~i', $leaf['v'] ) ) ) {
			list( $kind, $landing ) = swiftling_address_landing( $leaf['v'], $landings );
			if ( null !== $landing ) {
				$address = $leaf + array(
					'kind'    => $kind,
					'region'  => $landing['region'],
					'order'   => $landing['order'],
					'el'      => $landing['el'],
					'anchor'  => -1,
					'content' => $landing['content'],
					'mixed'   => false,
					'rescued' => false,
				);
				if ( 'url' === $kind ) {
					$links[] = $address;
				} else {
					$units[] = $address;
				}
				continue;
			}
			if ( ! $declared ) {
				continue;
			}
		}

		$best     = null;
		$in_class = false;
		$in_id    = false;

		foreach ( $landings as $landing ) {
			if ( in_array( $landing['where'], SWIFTLING_TEXT_PLACES, true ) || ( $declared && 'data' === $landing['where'] ) ) {
				if ( null === $best || swiftling_landing_rank( $landing ) < swiftling_landing_rank( $best ) ) {
					$best = $landing;
				}
			} elseif ( 'class' === $landing['where'] ) {
				$in_class = true;
			} elseif ( 'id' === $landing['where'] ) {
				$in_id = true;
			}
		}

		if ( null !== $best && $in_class ) {
			$classed[] = $leaf;
			$learn( $leaf['r'], SWIFTLING_RULE_NOT_TEXT );
		} elseif ( null !== $best ) {
			$units[]                                      = $leaf + array(
				'kind'    => $best['kind'],
				'region'  => $best['region'],
				'order'   => $best['order'],
				'el'      => $best['el'],
				'anchor'  => $best['link'],
				'content' => $best['content'],
				'mixed'   => $in_id,
				'rescued' => false,
			);
			$explains[ swiftling_text_key( $leaf['v'] ) ] = true;

			$block = swiftling_block_path( $leaf['p'] );
			if ( '' !== $block ) {
				foreach ( swiftling_text_pieces( $leaf['v'] ) as $piece ) {
					$block_pieces[ $leaf['o'] . '|' . $block ][ $piece ] = true;
				}
			}
			$learn( $leaf['r'], SWIFTLING_RULE_TEXT );
		} elseif ( $landings ) {
			$learn( $leaf['r'], SWIFTLING_RULE_NOT_TEXT );
		} else {
			$unseen[] = $leaf;
		}
	}

	$control_index = array();
	foreach ( $control as $position => $entry ) {
		$key = swiftling_text_key( $entry['t'] );
		if ( '' !== $key && ! isset( $control_index[ $key ] ) && ! isset( $explains[ $key ] ) ) {
			$control_index[ $key ] = $entry + array( 'o' => $position );
		}
	}

	$not_seen = array();
	foreach ( $unseen as $leaf ) {
		$key   = swiftling_text_key( $leaf['v'] );
		$block = swiftling_block_path( $leaf['p'] );
		if ( '' !== $block && isset( $block_pieces[ $leaf['o'] . '|' . $block ][ $key ] ) ) {
			continue;
		}
		if ( '' === $key || ! isset( $control_index[ $key ] ) ) {
			$not_seen[] = $leaf;
			continue;
		}
		$entry   = $control_index[ $key ];
		$units[] = $leaf + array(
			'kind'    => $entry['k'],
			'region'  => $entry['r'],
			'order'   => 1000000 + $entry['o'],
			'el'      => -1,
			'anchor'  => -1,
			'content' => $entry['c'],
			'mixed'   => false,
			'rescued' => true,
		);
		// One value accounts for a text; a second value with the same text is not rescued as well.
		unset( $control_index[ $key ] );
		$learn( $leaf['r'], SWIFTLING_RULE_TEXT );
	}

	// A link joins the first text inside it; a link without text of its own gets its own row.
	$in_link = array();
	foreach ( $units as $index => $unit ) {
		$key = $unit['o'] . '|' . $unit['anchor'];
		if ( $unit['anchor'] >= 0 && ! isset( $in_link[ $key ] ) ) {
			$in_link[ $key ] = $index;
		}
	}
	foreach ( $links as $link ) {
		$key = $link['o'] . '|' . $link['el'];
		if ( isset( $in_link[ $key ] ) && ! isset( $units[ $in_link[ $key ] ]['link'] ) ) {
			$units[ $in_link[ $key ] ]['link'] = $link;
		} else {
			$units[] = $link;
		}
	}

	usort(
		$units,
		function ( $a, $b ) {
			return $a['order'] - $b['order'];
		}
	);

	return array(
		'units'    => $units,
		'links'    => $links,
		'not_seen' => $not_seen,
		'classed'  => $classed,
		'rules'    => $rules,
	);
}

/**
 * Picks where an address shows: in an image or a video first, then in a link, then in a frame, then
 * in a style or a data attribute when the address is one of an image or a video, as a background or
 * a video player reads it.
 *
 * @param string $address  The address.
 * @param array  $landings Landings of its marker.
 * @return array{0: string, 1: array|null} Kind (url, image or video) and the landing; null when it shows as none of them.
 */
function swiftling_address_landing( $address, $landings ) {
	$found = array();
	foreach ( $landings as $landing ) {
		if ( ! isset( $found[ $landing['where'] ] ) ) {
			$found[ $landing['where'] ] = $landing;
		}
	}
	foreach ( array( 'image', 'video' ) as $kind ) {
		if ( isset( $found[ $kind ] ) ) {
			return array( $kind, $found[ $kind ] );
		}
	}
	if ( isset( $found['href'] ) ) {
		return array( 'url', $found['href'] );
	}
	$kind = swiftling_media_kind( $address );
	// A frame shows a video player, a map or another page.
	if ( isset( $found['frame'] ) ) {
		return array( '' !== $kind ? $kind : 'url', $found['frame'] );
	}
	foreach ( array( 'style', 'data' ) as $where ) {
		if ( '' !== $kind && isset( $found[ $where ] ) ) {
			return array( $kind, $found[ $where ] );
		}
	}
	return array( '', null );
}

/**
 * Tells from an address alone whether it is one of an image or of a video or audio: by the
 * extension of the file, or by the site, for video sites a player embeds.
 *
 * @param string $address Address.
 * @return string "image", "video" or empty.
 */
function swiftling_media_kind( $address ) {
	$path = strtolower( (string) wp_parse_url( $address, PHP_URL_PATH ) );
	$host = strtolower( (string) wp_parse_url( $address, PHP_URL_HOST ) );
	if ( 1 === preg_match( '~\.(?:jpe?g|png|gif|webp|avif|svg|bmp|ico)$~', $path ) ) {
		return 'image';
	}
	if ( 1 === preg_match( '~\.(?:mp4|m4v|webm|ogv|ogg|mov|mp3|m4a|wav)$~', $path )
		|| 1 === preg_match( '~(?:^|\.)(?:youtube\.com|youtube-nocookie\.com|youtu\.be|vimeo\.com|dailymotion\.com|wistia\.com)$~', $host ) ) {
		return 'video';
	}
	return '';
}

/**
 * Whether a kind of text is an address: a link, an image or a video.
 *
 * @param string $kind Kind.
 * @return bool
 */
function swiftling_is_address_kind( $kind ) {
	return in_array( $kind, SWIFTLING_ADDRESS_KINDS, true );
}

/**
 * Returns the block a value belongs to: "b/3/0" for an attribute at "b/3/0/a/title" and for the
 * saved HTML at "b/3/0/c1".
 *
 * @param string $path Path of the value inside its object.
 * @return string Empty for a value outside blocks.
 */
function swiftling_block_path( $path ) {
	return 1 === preg_match( '~^(b(?:/\d+)+)/(?:a/|c\d+$)~', $path, $match ) ? $match[1] : '';
}

/**
 * Ranks landings: visible text first, then attributes a person reads, then the head of the page.
 *
 * @param array $landing Landing.
 * @return int Lower is better.
 */
function swiftling_landing_rank( $landing ) {
	// A data attribute, which counts only for declared texts, ranks after every place a person reads.
	$rank = array_search( $landing['where'], SWIFTLING_TEXT_PLACES, true );
	$rank = false === $rank ? count( SWIFTLING_TEXT_PLACES ) : $rank;
	return (int) $rank * 1000000 + (int) $landing['order'];
}

/**
 * Returns a value's visible text in lowercase, for comparing texts.
 *
 * @param string $value Plain text or HTML.
 * @return string
 */
function swiftling_text_key( $value ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( swiftling_visible_text( $value ), 'UTF-8' ) : strtolower( swiftling_visible_text( $value ) );
}

/**
 * Stores what a probe found and returns everything the editor shows.
 *
 * Every text is stored in the map of the object it belongs to, so the translated view finds it
 * wherever it appears. The editor of a page shows only the page's text: its own texts, and shared
 * texts shown inside its content, such as a widget's default text, a synced pattern or the alt
 * text of an image. The header, the footer, menus and the site title are translated in the
 * editor of the page's template. The editor of a template shows everything around the content of
 * the posts on its example page, with what every page shows (the header, the footer, the menu
 * and the site title) in a section of its own. The subject's map also lists the shared texts it
 * shows, so its state counts them and the editor may save their translations.
 *
 * @param array $subject  Subject the editor is open for, see swiftling_subject().
 * @param array $registry The signed list the probe printed.
 * @param array $found    Landings reported by the browser.
 * @param array $control  Texts of the page rendered without markers.
 * @param bool  $scoped   Whether the page showed where the content of posts is.
 * @return array{sections: array, maps: array, units: array} The texts by section of the editor, the saved maps and every text found.
 */
function swiftling_save_probe( $subject, $registry, $found, $control, $scoped ) {
	$owner    = $subject['key'];
	$verdicts = swiftling_probe_verdicts( $registry['leaves'], $found, $control );

	$stored   = array( $owner => array() );
	$sections = 'post' === $subject['kind']
		? array(
			'page'   => array(),
			'fields' => array(),
			'lists'  => array(),
			'search' => array(),
		)
		: array(
			'page' => array(),
			'site' => array(),
		);
	// The header or the footer lists only its own texts, as the page of its subject.
	if ( 'site' === $subject['kind'] ) {
		$sections = array( 'page' => array() );
	}

	$units = $verdicts['units'];
	if ( 'post' === $subject['kind'] ) {
		$excerpt = swiftling_excerpt_unit( $subject['post'], $units );
		if ( $excerpt ) {
			$units[] = $excerpt;
		}
		$title = swiftling_title_unit( $subject['post'], $units );
		if ( $title ) {
			array_unshift( $units, $title );
		}
	}

	$shown = array();
	foreach ( $units as $unit ) {
		$entries = isset( $unit['link'] ) ? array( $unit, $unit['link'] ) : array( $unit );
		foreach ( $entries as $entry ) {
			$stored[ $entry['o'] ][] = $entry;
		}

		if ( 'post' === $subject['kind'] ) {
			$section = swiftling_unit_section( $unit, $owner, $scoped );
		} elseif ( 'site' === $subject['kind'] ) {
			$section = swiftling_is_site_part_unit( $unit, substr( $owner, 5 ) ) ? 'page' : '';
		} else {
			$section = swiftling_template_unit_section( $unit );
		}
		if ( '' === $section ) {
			continue;
		}
		$sections[ $section ][] = $unit;
		foreach ( $entries as $entry ) {
			$shown[ $entry['o'] . '|' . $entry['p'] ] = true;
		}

		// A shared text the subject shows is listed in its map too, under a key of its own.
		if ( $unit['o'] !== $owner ) {
			foreach ( $entries as $entry ) {
				$stored[ $owner ][] = $entry;
			}
		}
	}

	// Texts of the post's fields that the editor would not list otherwise: those its page does not
	// show, such as a teaser for lists, and images of fields shown outside the page's content.
	if ( 'post' === $subject['kind'] ) {
		foreach ( swiftling_unshown_field_texts( $subject, $registry['leaves'], $found, $verdicts['units'], $shown ) as $unit ) {
			$sections['fields'][]   = $unit;
			$stored[ $unit['o'] ][] = $unit;
			if ( $unit['o'] !== $owner ) {
				$stored[ $owner ][] = $unit;
			}
		}
	}

	swiftling_save_learned_rules( $verdicts['rules'] );

	return array(
		'sections' => $sections,
		'maps'     => swiftling_save_unit_maps( $owner, $stored ),
		'units'    => $units,
	);
}

/**
 * Returns a post's title, as a text of the post, when the probe did not find it on the page: every
 * post has its title to translate, also one whose page does not show it, such as a page a builder
 * makes without it. WordPress prints the title elsewhere too, such as in the browser's tab, in
 * menus and in lists of posts, and the translation shows there.
 *
 * @param WP_Post $post  Post.
 * @param array   $units Texts the probe found.
 * @return array|null The title as a text of the post, first on the page, or null.
 */
function swiftling_title_unit( $post, $units ) {
	$owner = 'post:' . $post->ID;
	if ( '' === trim( $post->post_title ) ) {
		return null;
	}
	foreach ( $units as $unit ) {
		if ( $owner === $unit['o'] && 'field/post_title' === $unit['p'] ) {
			return null;
		}
	}
	return array(
		'o'       => $owner,
		'p'       => 'field/post_title',
		'v'       => $post->post_title,
		'r'       => 'field:post_title||',
		'kind'    => 'heading',
		'region'  => '',
		'order'   => -1,
		'el'      => -1,
		'anchor'  => -1,
		'content' => true,
		'mixed'   => false,
		'rescued' => false,
	);
}

/**
 * Returns the excerpt a post's author wrote, as a text of the post, when the probe did not find it
 * on the page. Lists of posts and search results show it; the post's own page often does not.
 *
 * @param WP_Post $post  Post.
 * @param array   $units Texts the probe found.
 * @return array|null The excerpt as a text of the post, or null.
 */
function swiftling_excerpt_unit( $post, $units ) {
	$owner = 'post:' . $post->ID;
	if ( '' === trim( $post->post_excerpt ) || ! post_type_supports( $post->post_type, 'excerpt' ) ) {
		return null;
	}
	foreach ( $units as $unit ) {
		if ( $owner === $unit['o'] && 'field/post_excerpt' === $unit['p'] ) {
			return null;
		}
	}
	return array(
		'o'       => $owner,
		'p'       => 'field/post_excerpt',
		'v'       => $post->post_excerpt,
		'r'       => 'field:post_excerpt||',
		'kind'    => 'paragraph',
		'region'  => '',
		'order'   => PHP_INT_MAX,
		'el'      => -1,
		'anchor'  => -1,
		'content' => true,
		'mixed'   => false,
		'rescued' => false,
		'section' => 'lists',
	);
}

/**
 * Returns the texts of a post's fields that the editor lists although its page did not show them
 * in a place the editor lists: texts of fields the theme shows only elsewhere, such as where the
 * page is listed, and images of fields shown outside the page's content. See Builder::listTexts().
 *
 * A value the page showed but not as text, such as a text field the theme prints into a class, is
 * left out: the page check judged it a setting.
 *
 * @param array $subject Subject of a post.
 * @param array $leaves  Values the probe marked.
 * @param array $found   Their landings.
 * @param array $units   Texts and links the probe found.
 * @param array $shown   "object|path" of every text or link the editor shows already.
 * @return array Texts, in the section "fields".
 */
function swiftling_unshown_field_texts( $subject, $leaves, $found, $units, $shown ) {
	$judged = array();
	foreach ( $leaves as $id => $leaf ) {
		if ( ! empty( $found[ $id ] ) ) {
			$judged[ $leaf['o'] . '|' . $leaf['p'] ] = true;
		}
	}
	// What landed as text or as an address is not judged a setting, wherever it showed.
	foreach ( $units as $unit ) {
		foreach ( isset( $unit['link'] ) ? array( $unit, $unit['link'] ) : array( $unit ) as $entry ) {
			unset( $judged[ $entry['o'] . '|' . $entry['p'] ] );
		}
	}

	$texts  = array();
	$listed = \SwiftLing\Builders\Registry::listTexts( $subject );
	// The featured image shows outside the page's content, where the theme prints it.
	$image = 'post' === $subject['kind'] && $subject['post'] instanceof WP_Post ? (int) get_post_thumbnail_id( $subject['post'] ) : 0;
	if ( $image ) {
		$listed = array_merge( swiftling_media_texts( $image ), $listed );
	}
	foreach ( $listed as $order => $text ) {
		$key = $text['o'] . '|' . $text['p'];
		if ( isset( $shown[ $key ] ) || isset( $judged[ $key ] ) ) {
			continue;
		}
		$shown[ $key ] = true;
		$texts[]       = swiftling_listed_unit( $text, 2000000 + $order ) + array( 'section' => 'fields' );
	}
	return $texts;
}

/**
 * Returns the texts of a file of the media library, as the probe finds them where a page shows the
 * file: its address and, for an image, its alt text. Both belong to the file, so they change
 * wherever it is used. For the images of a page's fields, which a page shows outside its content:
 * its featured image, an image field of a builder, the gallery of a product.
 *
 * @param int $attachment_id Attachment ID.
 * @return array Texts, see Builder::listTexts().
 */
function swiftling_media_texts( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$file          = swiftling_attachment_file( $attachment_id );
	if ( '' === $file ) {
		return array();
	}
	$owner = 'post:' . $attachment_id;
	$image = wp_attachment_is_image( $attachment_id );
	$title = get_the_title( $attachment_id );
	$texts = array(
		array(
			'o'     => $owner,
			'p'     => 'file',
			'v'     => $file,
			'r'     => swiftling_rule_key( 'attachment:file', '', '' ),
			'kind'  => $image ? 'image' : 'url',
			'place' => $title,
		),
	);
	$alt   = $image ? swiftling_raw_post_meta( $attachment_id, '_wp_attachment_image_alt' ) : '';
	if ( '' !== trim( $alt ) && 1 === preg_match( '/\p{L}/u', $alt ) ) {
		$texts[] = array(
			'o'     => $owner,
			'p'     => swiftling_meta_path( '_wp_attachment_image_alt', 0 ),
			'v'     => $alt,
			'r'     => swiftling_rule_key( 'meta:_wp_attachment_image_alt', '', '' ),
			'kind'  => 'alt',
			'place' => $title,
		);
	}
	return $texts;
}

/**
 * Completes a text a builder lists, see Builder::listTexts(), with what the editor needs of a text
 * the probe found.
 *
 * @param array $text  Text: o, p, v, r, kind and optionally place.
 * @param int   $order Position among the texts.
 * @return array
 */
function swiftling_listed_unit( $text, $order ) {
	return array_merge(
		array(
			'region'  => '',
			'order'   => $order,
			'el'      => -1,
			'anchor'  => -1,
			'content' => true,
			'mixed'   => false,
			'rescued' => false,
		),
		$text
	);
}

/**
 * Stores texts listed without a page check, as for the terms of a taxonomy or a settings page of a
 * builder, and returns everything the editor shows, as swiftling_save_probe() does for a page. The
 * texts belong to their own objects; the subject's map lists them as shared texts, so its state
 * counts them.
 *
 * @param array $subject Subject.
 * @param array $texts   Texts, see Builder::listTexts().
 * @return array{sections: array, maps: array, units: array}
 */
function swiftling_save_listed_texts( $subject, $texts ) {
	$owner  = $subject['key'];
	$units  = array();
	$stored = array( $owner => array() );
	foreach ( array_values( $texts ) as $order => $text ) {
		$unit                   = swiftling_listed_unit( $text, $order );
		$units[]                = $unit;
		$stored[ $unit['o'] ][] = $unit;
		$stored[ $owner ][]     = $unit;
	}

	return array(
		'sections' => array( 'page' => $units ),
		'maps'     => swiftling_save_unit_maps( $owner, $stored ),
		'units'    => $units,
	);
}

/**
 * Returns where the editor of a page shows a text: with the page's text, under lists of posts,
 * under search results, or not at all.
 *
 * @param array  $unit       Text found by the probe.
 * @param string $post_owner Object key of the page.
 * @param bool   $scoped     Whether the page showed where its content is.
 * @return string "page", "lists", "search" or empty.
 */
function swiftling_unit_section( $unit, $post_owner, $scoped ) {
	if ( isset( $unit['section'] ) ) {
		return $unit['section'];
	}
	if ( $unit['o'] === $post_owner ) {
		// The page's own texts that show only in the head of the page: its title for search
		// engines, its description, what shows when it is shared and structured data.
		$search = in_array( $unit['kind'], array( 'seo_title', 'meta_description', 'social_title', 'social_description', 'structured' ), true );
		return $search && empty( $unit['content'] ) ? 'search' : 'page';
	}

	// Shared texts count only where they show inside the page's content.
	if ( ! $scoped || empty( $unit['content'] ) ) {
		return '';
	}

	// Texts from code, a template part or the site settings placed inside the content, and the
	// content of a pattern, a template or an image used in the page.
	if ( 0 !== strpos( $unit['o'], 'post:' ) || 0 !== strpos( $unit['p'], 'field/' ) ) {
		return 'page';
	}

	// The title or excerpt of another page listed in this one belongs to that page and is translated there.
	return swiftling_is_post_text( $unit['o'] ) ? '' : 'page';
}

/**
 * Returns where the editor of a template shows a text: with what every page shows, with the
 * template's own text, or not at all.
 *
 * Texts of posts belong to the posts and are translated in their editors: their content, and their
 * titles and excerpts wherever the template lists them. What every page shows is what comes from
 * a template part, a navigation menu of the Site Editor or the site settings, and texts of the theme
 * and plugins in the header, the footer or the menu. The items of a classic menu are translated on
 * the Menus screen, see includes/menus.php.
 *
 * @param array $unit Text found by the probe.
 * @return string "site", "page" or empty.
 */
function swiftling_template_unit_section( $unit ) {
	if ( ! empty( $unit['content'] ) ) {
		return '';
	}
	// A builder's header or footer is what every page shows, also when its post can be opened on
	// its own, as one of Header Footer Elementor, see Builder::sitePart().
	if ( '' !== \SwiftLing\Builders\Registry::sitePart( $unit['o'] ) ) {
		return 'site';
	}
	// An item of a classic menu is translated on the Menus screen, which the template points to.
	if ( swiftling_is_post_text( $unit['o'] ) || swiftling_is_menu_item_owner( $unit['o'] ) ) {
		return '';
	}
	if ( 0 === strpos( $unit['o'], 'part:' ) || 'options' === $unit['o'] || swiftling_is_menu_owner( $unit['o'] ) ) {
		return 'site';
	}
	if ( 0 === strpos( $unit['o'], 'gettext:' ) && in_array( $unit['region'], array( 'header', 'footer', 'navigation' ), true ) ) {
		return 'site';
	}
	return 'page';
}

/**
 * Returns the part of every page a text is in: the header or the footer, which the Templates screen
 * lists as rows of their own.
 *
 * What the text's object says comes first: a builder's header or footer post, see
 * Builder::sitePart(), or the area of a template part of the Site Editor. Otherwise the landmark
 * around it decides, a header or footer tag or role, as the page check read it. A menu, also one in
 * the header, is neither: its items have the Menus screen.
 *
 * @param array $unit Text found by the probe.
 * @return string header, footer, or empty.
 */
function swiftling_unit_site_part( $unit ) {
	$part = \SwiftLing\Builders\Registry::sitePart( $unit['o'] );
	if ( '' !== $part ) {
		return $part;
	}
	if ( 0 === strpos( $unit['o'], 'part:' ) ) {
		$template = get_block_template( substr( $unit['o'], 5 ), 'wp_template_part' );
		if ( $template && in_array( $template->area, array( 'header', 'footer' ), true ) ) {
			return $template->area;
		}
	}
	return in_array( $unit['region'], array( 'header', 'footer' ), true ) ? $unit['region'] : '';
}

/**
 * Whether a text a page check found belongs to the editor of a part of every page: a text every
 * page shows, see swiftling_template_unit_section(), in that part, see swiftling_unit_site_part().
 *
 * @param array  $unit Text found by the probe.
 * @param string $part header or footer.
 * @return bool
 */
function swiftling_is_site_part_unit( $unit, $part ) {
	return 'site' === swiftling_template_unit_section( $unit ) && swiftling_unit_site_part( $unit ) === $part;
}

/**
 * Whether an object is a post visitors open on its own, whose texts are translated in its editor.
 * Images, synced patterns and menus are posts too, but are translated where they are used.
 *
 * @param string $owner Object key.
 * @return bool
 */
function swiftling_is_post_text( $owner ) {
	if ( 0 !== strpos( $owner, 'post:' ) ) {
		return false;
	}
	$post = get_post( (int) substr( $owner, 5 ) );
	return $post && 'attachment' !== $post->post_type && is_post_type_viewable( $post->post_type );
}

/**
 * Whether an object is a navigation menu of the Site Editor. Items of classic menus are not: see
 * swiftling_is_menu_item_owner().
 *
 * @param string $owner Object key.
 * @return bool
 */
function swiftling_is_menu_owner( $owner ) {
	return 0 === strpos( $owner, 'post:' ) && 'wp_navigation' === get_post_type( (int) substr( $owner, 5 ) );
}

/**
 * Saves rules. A field seen as text on one page and as something else on another is marked mixed.
 *
 * @param array<string, int> $rules Rule key => verdict from this probe.
 */
function swiftling_save_learned_rules( $rules ) {
	if ( ! $rules ) {
		return;
	}
	$known   = swiftling_get_rules( array_keys( $rules ) );
	$changed = array();
	foreach ( $rules as $key => $verdict ) {
		$merged = isset( $known[ $key ] ) && $known[ $key ] !== $verdict ? SWIFTLING_RULE_MIXED : $verdict;
		if ( ! isset( $known[ $key ] ) || $known[ $key ] !== $merged ) {
			$changed[ $key ] = $merged;
		}
	}
	swiftling_save_rules( $changed );
}

/**
 * Saves the maps of every object seen on the page.
 *
 * The page's own map is replaced, because all of the page's data went through the probe. The maps
 * of shared objects, such as a header or the site title, keep what other pages found and only gain
 * what this page found. A shared text in the page's own list is listed under a shared key, see
 * swiftling_shared_key(), and the objects of those texts are the page's references.
 *
 * A text that changed since it was translated keeps the hash of its previous version, so the
 * editor can show the old translation as outdated, until every language that had the old version
 * has the new one.
 *
 * @param string $post_owner Object key of the page; empty when only shared objects gain texts, as
 *                           when a recording finds texts of themes and plugins on a page.
 * @param array  $groups     Object key => texts found.
 * @return array Object key => saved map.
 */
function swiftling_save_unit_maps( $post_owner, $groups ) {
	$old   = swiftling_get_maps( array_keys( $groups ) );
	$now   = time();
	$saved = array();

	// The page comes last, so a shared text it lists can copy the entry just saved for its object.
	$owners = array_values( array_diff( array_keys( $groups ), array( $post_owner ) ) );
	if ( isset( $groups[ $post_owner ] ) ) {
		$owners[] = $post_owner;
	}

	foreach ( $owners as $owner ) {
		$units    = $groups[ $owner ];
		$previous = isset( $old[ $owner ] ) ? $old[ $owner ]['units'] : array();
		$map      = $owner === $post_owner ? array() : $previous;
		$changed  = false;

		foreach ( $units as $unit ) {
			if ( $unit['o'] !== $owner ) {
				// The same hash, and the same previous version, as in the map of the text's object.
				$key         = swiftling_shared_key( $unit['o'], swiftling_unit_key( $unit ) );
				$map[ $key ] = $saved[ $unit['o'] ]['units'][ swiftling_unit_key( $unit ) ];
				$changed     = $changed || ! isset( $previous[ $key ] ) || $previous[ $key ] !== $map[ $key ];
				continue;
			}

			$key  = swiftling_unit_key( $unit );
			$hash = swiftling_hash( $unit['v'] );
			$was  = isset( $previous[ $key ] ) ? $previous[ $key ] : null;

			if ( null === $was ) {
				$map[ $key ] = $hash;
				$changed     = true;
				continue;
			}

			$was_hash = is_array( $was ) ? $was[0] : $was;
			$was_prev = is_array( $was ) ? $was[1] : '';

			if ( $was_hash === $hash ) {
				$map[ $key ] = $was;
				continue;
			}

			// The text changed: remember the version that was translated.
			$map[ $key ] = array( $hash, '' !== $was_prev ? $was_prev : $was_hash );
			$changed     = true;
		}

		if ( $owner === $post_owner && array_diff_key( $previous, $map ) ) {
			$changed = true;
		}

		$map = swiftling_settle_outdated( $owner, $map );

		$sources = $owner === $post_owner || ! isset( $old[ $owner ] ) ? array() : $old[ $owner ]['sources'];
		$refs    = $owner === $post_owner || ! isset( $old[ $owner ] ) ? array() : $old[ $owner ]['refs'];
		foreach ( $units as $unit ) {
			if ( $unit['o'] === $owner ) {
				$sources[] = swiftling_path_source( $unit['p'] );
			} else {
				$refs[] = $unit['o'];
			}
		}
		$sources = array_values( array_unique( $sources ) );
		$refs    = array_values( array_unique( $refs ) );
		$time    = $changed || ! isset( $old[ $owner ] ) ? $now : $old[ $owner ]['changed'];

		swiftling_save_map( $owner, $map, $sources, $refs, $time );
		$saved[ $owner ] = array(
			'units'   => $map,
			'sources' => $sources,
			'refs'    => $refs,
			'changed' => $time,
		);
	}

	return $saved;
}

/**
 * Returns the map key of a text or link found by the probe.
 *
 * @param array $unit Text or link.
 * @return string
 */
function swiftling_unit_key( $unit ) {
	return swiftling_is_address_kind( $unit['kind'] ) ? swiftling_link_key( $unit['p'] ) : swiftling_path_key( $unit['p'] );
}

/**
 * Forgets the previous version of a changed text once no language still shows only that version.
 * A shared text a page lists mirrors the entry of its own object, which settles there.
 *
 * @param string $owner Object key of the map.
 * @param array  $map   Map key => hash or array( hash, previous hash ).
 * @return array
 */
function swiftling_settle_outdated( $owner, $map ) {
	$places = array();
	foreach ( $map as $unit_key => $entry ) {
		if ( is_array( $entry ) && ! swiftling_is_shared_key( $unit_key ) ) {
			$places[ $unit_key ] = array( swiftling_place_key( $owner, $unit_key, $entry[0] ), swiftling_place_key( $owner, $unit_key, $entry[1] ) );
		}
	}
	if ( ! $places ) {
		return $map;
	}

	$keys = array_merge( ...array_values( $places ) );

	$languages = swiftling_languages();
	$known     = array();
	foreach ( $languages['targets'] as $language ) {
		$known[ $language['locale'] ] = swiftling_get_translations( $language['locale'], $keys );
	}

	foreach ( $places as $unit_key => $place ) {
		$waiting = false;
		foreach ( $known as $translations ) {
			if ( isset( $translations[ $place[1] ] ) && ! isset( $translations[ $place[0] ] ) ) {
				$waiting = true;
				break;
			}
		}
		if ( ! $waiting ) {
			$map[ $unit_key ] = $map[ $unit_key ][0];
		}
	}

	return $map;
}

/**
 * Returns a value's text, the text of each paragraph, list item or heading in it, and its alt
 * texts and titles, in lowercase.
 *
 * @param string $value Plain text or HTML.
 * @return string[]
 */
function swiftling_text_pieces( $value ) {
	$pieces = array( swiftling_text_key( $value ) );

	// Alt texts and titles inside the HTML are translated with it.
	if ( preg_match_all( '~\s(?:alt|title)="([^"]+)"~i', $value, $attributes ) ) {
		foreach ( $attributes[1] as $attribute ) {
			$pieces[] = swiftling_text_key( $attribute );
		}
	}

	$split = preg_replace( '~</(?:p|li|h[1-6]|div|figcaption|blockquote|td|th|dd|dt)>|<br\s*/?>~i', "\n", $value );
	foreach ( explode( "\n", (string) $split ) as $line ) {
		$piece = swiftling_text_key( $line );
		if ( '' !== $piece ) {
			$pieces[] = $piece;
		}
	}
	return $pieces;
}

/**
 * Returns the places whose translation the editor of a subject may save: the subject's own texts
 * and links, when the user may edit the subject, and the shared texts it shows, each when the
 * user may edit the object it belongs to.
 *
 * @param string $owner Key of the subject, see swiftling_subject().
 * @return array<string, string> Place key => "text", "url", or "format" for a text from code, which the
 *         code may hand to sprintf(), so its translation must keep the original's placeholders.
 */
function swiftling_translatable_keys( $owner ) {
	$maps = swiftling_get_maps( array( $owner ) );
	if ( ! isset( $maps[ $owner ] ) ) {
		return array();
	}

	$allowed = array();
	foreach ( swiftling_map_places( $owner, $maps[ $owner ] ) as $unit_key => $place ) {
		$target = swiftling_is_shared_key( $unit_key ) ? swiftling_shared_key_target( $unit_key, $maps[ $owner ]['refs'] ) : array( $owner );
		if ( swiftling_can_translate_object( $target[0] ) ) {
			$allowed[ $place[0] ] = swiftling_is_link_key( $unit_key ) ? 'url' : ( 0 === strpos( $target[0], 'gettext:' ) ? 'format' : 'text' );
		}
	}
	return $allowed;
}

/**
 * Whether the current user may translate an object: the same right it takes to edit the object.
 *
 * @param string $owner Object key.
 * @return bool
 */
function swiftling_can_translate_object( $owner ) {
	// A translator translates everything, also what they may not edit.
	if ( swiftling_is_translator() ) {
		return true;
	}
	if ( 0 === strpos( $owner, 'post:' ) ) {
		return current_user_can( 'edit_post', (int) substr( $owner, 5 ) );
	}
	if ( 0 === strpos( $owner, 'term:' ) ) {
		return current_user_can( 'edit_term', (int) substr( $owner, 5 ) );
	}
	if ( 0 === strpos( $owner, 'comment:' ) ) {
		return current_user_can( 'edit_comment', (int) substr( $owner, 8 ) );
	}
	if ( 'options' === $owner || 0 === strpos( $owner, 'gettext:' ) ) {
		return current_user_can( 'manage_options' );
	}
	// Objects a builder keeps, such as the forms of a form plugin: the right to edit them.
	$can = \SwiftLing\Builders\Registry::canTranslate( $owner );
	if ( null !== $can ) {
		return $can;
	}
	return current_user_can( 'edit_theme_options' );
}

/**
 * Notes WordPress's own translations of the texts from code a probe found, in every language
 * that has none of the user's: the translation WordPress, the theme or the plugin ships, from
 * translate.wordpress.org. The translated view shows that translation anyway; noting it lets the
 * editor show it in the field and count the text as translated.
 *
 * @param array $units Texts the probe found.
 * @param array $maps  Maps saved for them, see swiftling_save_unit_maps().
 */
function swiftling_note_official_translations( $units, $maps ) {
	$texts = array();
	foreach ( $units as $unit ) {
		if ( empty( $unit['g'] ) || ! is_array( $unit['g'] ) || 0 !== strpos( $unit['o'], 'gettext:' ) || ! isset( $maps[ $unit['o'] ] ) ) {
			continue;
		}
		list( $place )   = swiftling_live_places( $unit, $maps[ $unit['o'] ]['units'] );
		$texts[ $place ] = $unit['g'];
	}
	if ( ! $texts ) {
		return;
	}

	foreach ( swiftling_languages()['targets'] as $language ) {
		$missing = array_diff_key( $texts, swiftling_get_translations( $language['locale'], array_keys( $texts ) ) );
		$items   = array();
		foreach ( swiftling_official_translations( $language['locale'], $missing ) as $place => $text ) {
			$items[ $place ] = array(
				'text'   => $text,
				'status' => SWIFTLING_OFFICIAL,
			);
		}
		if ( $items ) {
			swiftling_save_translations( $language['locale'], $items );
		}
	}
}

/**
 * Returns WordPress's own translations of texts from code into a locale, for those that have one.
 *
 * @param string $locale Locale, e.g. de_DE.
 * @param array  $texts  Place key => the text in the code: d (text domain), t (text or singular),
 *                       and where they apply c (context), p (plural) and n (number).
 * @return array<string, string> Place key => translation.
 */
function swiftling_official_translations( $locale, $texts ) {
	if ( ! $texts || ! in_array( $locale, get_available_languages(), true ) || ! switch_to_locale( $locale ) ) {
		return array();
	}

	$found = array();
	foreach ( $texts as $place => $text ) {
		if ( ! isset( $text['d'], $text['t'] ) || ! is_string( $text['d'] ) || ! is_string( $text['t'] ) ) {
			continue;
		}
		$context      = isset( $text['c'] ) && is_string( $text['c'] ) ? $text['c'] : null;
		$translations = get_translations_for_domain( $text['d'] );
		if ( isset( $text['p'] ) && is_string( $text['p'] ) ) {
			$number      = isset( $text['n'] ) ? (int) $text['n'] : 1;
			$translation = $translations->translate_plural( $text['t'], $text['p'], $number, $context );
			$code        = 1 === $number ? $text['t'] : $text['p'];
		} else {
			$translation = $translations->translate( $text['t'], $context );
			$code        = $text['t'];
		}
		// Without a translation WordPress hands back the text as the code wrote it.
		if ( ! is_string( $translation ) || '' === $translation || $translation === $code ) {
			continue;
		}
		// Typographic quotes and dashes as characters, so the field shows them as a person types them.
		$plain           = html_entity_decode( $translation, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$found[ $place ] = false === strpos( $translation, '<' ) && ! preg_match( '/[<>&]/', $plain ) ? $plain : $translation;
	}

	restore_previous_locale();
	return $found;
}

/**
 * Stores the texts of the terms of a taxonomy and returns everything the editor shows, as
 * swiftling_save_probe() does for a page: the name and description of each term, and the fields
 * of terms that are translated, see swiftling_term_fields(). The editor shows the texts of each
 * term under its name, see swiftling_live_rows_html(); a field is called by its label, with the
 * theme or plugin it comes from where that is known.
 *
 * @param array $subject Subject "terms:<taxonomy>", see swiftling_subject().
 * @return array{sections: array, maps: array, units: array, more: int, fields: array, terms: array} Like swiftling_save_probe(), how many terms were left out, the fields of the terms, and each term's place in its list ("Sport › Football"), by object key.
 */
function swiftling_save_term_list( $subject ) {
	$listed = swiftling_listed_terms( substr( $subject['key'], 6 ) );
	$fields = swiftling_term_fields( $subject, $listed['terms'] );

	// The texts of the fields that are translated, by term.
	$by_term = array();
	foreach ( $fields as $field ) {
		if ( ! $field['translated'] ) {
			continue;
		}
		foreach ( $field['texts'] as $text ) {
			$by_term[ $text['o'] ][] = array(
				'o'     => $text['o'],
				'p'     => $text['p'],
				'v'     => $text['v'],
				'r'     => $text['r'],
				'kind'  => 'text',
				'label' => $field['label'],
				'place' => $field['source'],
			);
		}
	}

	// Texts of the list itself come first, under a group of their own: the name of an attribute of a
	// shop, see SwiftLing\Builders\WooCommerce.
	$texts = array();
	$terms = array();
	foreach ( \SwiftLing\Builders\Registry::listTexts( $subject ) as $text ) {
		$texts[]             = $text;
		$terms[ $text['o'] ] = __( 'The list itself', 'swiftling' );
	}
	foreach ( $listed['terms'] as $term ) {
		$owner           = 'term:' . $term->term_id;
		$terms[ $owner ] = implode( ' › ', array_merge( swiftling_term_parents( $term ), array( $term->name ) ) );
		foreach ( array( 'name', 'description' ) as $field ) {
			if ( '' === trim( $term->$field ) ) {
				continue;
			}
			$texts[] = array(
				'o'     => $owner,
				'p'     => 'field/' . $field,
				'v'     => $term->$field,
				'r'     => 'term:' . $field . '||',
				'kind'  => $field,
				'place' => '',
			);
		}
		foreach ( isset( $by_term[ $owner ] ) ? $by_term[ $owner ] : array() as $text ) {
			$texts[] = $text;
		}
	}

	return swiftling_save_listed_texts( $subject, $texts ) + array(
		'more'   => max( 0, $listed['total'] - count( $listed['terms'] ) ),
		'fields' => $fields,
		'terms'  => $terms,
	);
}

/**
 * Stores the texts of a subject a builder adds, such as a settings page of its own, and returns
 * everything the editor shows. See Builder::subject() and Builder::listTexts().
 *
 * @param array $subject Subject of the kind "fields".
 * @return array{sections: array, maps: array, units: array}
 */
function swiftling_save_field_list( $subject ) {
	return swiftling_save_listed_texts( $subject, \SwiftLing\Builders\Registry::listTexts( $subject ) );
}
