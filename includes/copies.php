<?php
/**
 * Separate copies: a page that needs its own layout or content in one language gets a copy for
 * that language, as WPML's manual translation does, but only where it is asked for.
 *
 * A copy is an ordinary post of the same type, made from the translation editor, edited in the
 * post's own editor (Elementor, the block editor) and linked to its original by two meta values:
 * _swiftling_copy_of (the original's ID) and _swiftling_copy_lang (the locale). It starts as a
 * draft that holds the translations already made; once it is published, visitors of the original's
 * address in that language see the copy instead of the translated original.
 *
 * Visitors never meet the copy anywhere else: the request for the original's address is answered
 * with the copy itself, so builders render it as the page it is, with its own styles; every address
 * WordPress builds for the copy is the original's in that language, so its own address leads there;
 * and lists, searches, feeds and sitemaps leave copies out. The header, footer, menus and texts of
 * the theme and of plugins around it are translated as on every page in that language.
 *
 * Categories, tags and other taxonomies are the original's. A copy has none of its own: it shows the
 * original's terms wherever terms are read, so the two cannot drift apart and the number of posts
 * of a category counts the original once. The names of the terms are translated as everywhere.
 *
 * Not for WooCommerce products, whose prices and stock would have to be kept in sync.
 *
 * A language can also show a post as a duplicate of its original: the post as it is, untranslated,
 * so it is always in sync with the original. Nothing is copied: the post's own texts simply stay
 * untranslated in that language, while the header, footer and menus around it are translated. Its
 * translations stay stored, so translating it again brings them back. Together with translating
 * in the editor and a separate copy, that is the three ways a language can show a post, see
 * swiftling_language_mode().
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

const SWIFTLING_COPY_OF   = '_swiftling_copy_of';
const SWIFTLING_COPY_LANG = '_swiftling_copy_lang';
// The original's last change (GMT) when the copy was made, to tell that the original changed since.
const SWIFTLING_COPY_FROM = '_swiftling_copy_from';
// On an original, once per locale it shows as it is in, untranslated: a duplicate of itself there.
const SWIFTLING_DUPLICATE = '_swiftling_duplicate';

/**
 * Whether a post may get a separate copy: a post or page of a type visitors open, other than one a
 * plugin keeps from copies, such as a WooCommerce product or a Tutor LMS course (see
 * Builder::noCopyTypes()), the page of posts or a copy itself.
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function swiftling_can_have_copies( $post ) {
	if ( ! $post instanceof WP_Post || swiftling_copy_original_id( $post ) ) {
		return false;
	}
	$types = array_diff( array_keys( swiftling_content_types() ), \SwiftLing\Builders\Registry::noCopyTypes() );
	return in_array( $post->post_type, $types, true ) && (int) get_option( 'page_for_posts' ) !== $post->ID;
}

/**
 * Returns the original of a copy.
 *
 * @param WP_Post|int $post Post.
 * @return int The original's ID, or 0 when the post is no copy.
 */
function swiftling_copy_original_id( $post ) {
	$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
	$copies  = swiftling_copy_map();
	return isset( $copies[ $post_id ] ) ? $copies[ $post_id ] : 0;
}

/**
 * Returns every copy of the site with its original, read once per request: addresses and terms are
 * built for many posts on a page, and only a few posts are copies.
 *
 * @param bool $refresh Whether to read them again, after a copy was made.
 * @return array<int, int> Copy's ID => original's ID.
 */
function swiftling_copy_map( $refresh = false ) {
	global $wpdb;
	static $map = null;
	if ( null === $map || $refresh ) {
		$map = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One small query per request; meta_key is indexed.
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_value FROM %i WHERE meta_key = %s', $wpdb->postmeta, SWIFTLING_COPY_OF ) ) as $row ) {
			$map[ (int) $row->post_id ] = (int) $row->meta_value;
		}
	}
	return $map;
}

/**
 * Returns the copies of a post, published or not, out of the trash.
 *
 * @param int $post_id Post ID of the original.
 * @return array<string, WP_Post> Keyed by locale.
 */
function swiftling_copies_of( $post_id ) {
	$copies = array();
	// Most posts have no copy, and the list of copies is read once per request anyway.
	if ( ! $post_id || ! in_array( (int) $post_id, swiftling_copy_map(), true ) ) {
		return $copies;
	}
	$found = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Few posts are copies; read for one original at a time.
			'meta_key'       => SWIFTLING_COPY_OF,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			'meta_value'     => (string) (int) $post_id,
		)
	);
	foreach ( $found as $copy ) {
		$locale = (string) get_post_meta( $copy->ID, SWIFTLING_COPY_LANG, true );
		if ( '' !== $locale && ! isset( $copies[ $locale ] ) ) {
			$copies[ $locale ] = $copy;
		}
	}
	return $copies;
}

/**
 * Returns the copy of a post for a language.
 *
 * @param int    $post_id Post ID of the original.
 * @param string $locale  Locale.
 * @return WP_Post|null
 */
function swiftling_copy_for( $post_id, $locale ) {
	$copies = swiftling_copies_of( $post_id );
	return isset( $copies[ $locale ] ) ? $copies[ $locale ] : null;
}

/**
 * Returns the published copies for a language: what the translated view answers with.
 *
 * @param string $locale  Locale.
 * @param bool   $refresh Whether to read them again, after a copy changed.
 * @return array<int, int> Original's ID => copy's ID.
 */
function swiftling_published_copies( $locale, $refresh = false ) {
	global $wpdb;
	static $found = array();
	if ( ! isset( $found[ $locale ] ) || $refresh ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read once per request in another language; few posts are copies.
		$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS copy_id, o.meta_value AS original_id FROM %i p JOIN %i o ON o.post_id = p.ID AND o.meta_key = %s JOIN %i l ON l.post_id = p.ID AND l.meta_key = %s AND l.meta_value = %s WHERE p.post_status = 'publish'", $wpdb->posts, $wpdb->postmeta, SWIFTLING_COPY_OF, $wpdb->postmeta, SWIFTLING_COPY_LANG, $locale ) );
		$found[ $locale ] = array();
		foreach ( $rows as $row ) {
			$found[ $locale ][ (int) $row->original_id ] = (int) $row->copy_id;
		}
	}
	return $found[ $locale ];
}

/**
 * Whether the original changed after its copy was made and after the copy was last edited, so the
 * copy may need the same change.
 *
 * @param WP_Post $copy Copy.
 * @return bool
 */
function swiftling_copy_is_outdated( $copy ) {
	$original = get_post( swiftling_copy_original_id( $copy ) );
	if ( ! $original ) {
		return false;
	}
	$seen = max( (string) get_post_meta( $copy->ID, SWIFTLING_COPY_FROM, true ), $copy->post_modified_gmt );
	return $original->post_modified_gmt > $seen;
}

/**
 * Returns the state of a language whose page is a published copy, for the state icons.
 *
 * @param WP_Post $copy Copy.
 * @return string reviewed or outdated.
 */
function swiftling_copy_state( $copy ) {
	return swiftling_copy_is_outdated( $copy ) ? 'outdated' : 'reviewed';
}

/**
 * Makes the copy of a post for a language, with the translations made so far in place of the
 * originals, as a draft.
 *
 * @param WP_Post $post Original.
 * @param string  $slug Address slug of the language.
 * @return int|WP_Error The copy's ID.
 */
function swiftling_make_copy( $post, $slug ) {
	$language = swiftling_target_language( $slug );
	if ( ! $language || ! swiftling_can_have_copies( $post ) ) {
		return new WP_Error( 'swiftling_copy_refused', __( 'This content cannot have a separate copy.', 'swiftling' ) );
	}
	if ( swiftling_copy_for( $post->ID, $language['locale'] ) ) {
		return new WP_Error( 'swiftling_copy_exists', __( 'This language already has a separate copy.', 'swiftling' ) );
	}

	$owner = 'post:' . $post->ID;
	$copy  = swiftling_in_language(
		$slug,
		static function () use ( $post, $owner ) {
			$content = swiftling_translate_post_content( $owner, $post->post_content );
			$meta    = array();
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( swiftling_copy_leaves_out_meta( (string) $key ) ) {
					continue;
				}
				foreach ( $values as $index => $stored ) {
					$meta[ $key ][] = swiftling_transform( $owner, swiftling_meta_path( (string) $key, $index ), 'meta:' . $key, maybe_unserialize( $stored ) );
				}
			}
			return array(
				'title'   => swiftling_transform( $owner, 'field/post_title', 'field:post_title', $post->post_title ),
				'excerpt' => '' === $post->post_excerpt ? '' : swiftling_transform( $owner, 'field/post_excerpt', 'field:post_excerpt', $post->post_excerpt ),
				'content' => $content,
				'meta'    => $meta,
			);
		}
	);

	// Slashed: WordPress removes one level of backslashes on the way in, and builders keep JSON.
	$copy_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'draft',
				'post_title'     => $copy['title'],
				'post_content'   => $copy['content'],
				'post_excerpt'   => $copy['excerpt'],
				'post_name'      => $post->post_name . '-' . $slug,
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_author'    => get_current_user_id(),
			)
		),
		true
	);
	if ( is_wp_error( $copy_id ) ) {
		swiftling_log(
			'error',
			'copies',
			/* translators: 1: language name, e.g. Deutsch, 2: title of a page, 3: what WordPress said. */
			sprintf( __( 'The separate copy in %1$s of “%2$s” could not be made: %3$s', 'swiftling' ), $language['name'], get_the_title( $post ), $copy_id->get_error_message() ),
			array(
				'detail' => $copy_id->get_error_code(),
				'object' => 'post:' . $post->ID,
			)
		);
		return $copy_id;
	}

	foreach ( $copy['meta'] as $key => $values ) {
		foreach ( $values as $value ) {
			add_post_meta( $copy_id, $key, wp_slash( $value ) );
		}
	}
	update_post_meta( $copy_id, SWIFTLING_COPY_LANG, $language['locale'] );
	update_post_meta( $copy_id, SWIFTLING_COPY_FROM, $post->post_modified_gmt );
	update_post_meta( $copy_id, SWIFTLING_COPY_OF, $post->ID );
	swiftling_copy_map( true );
	swiftling_refresh_translated( array( $post->ID ) );

	// The original's terms are the copy's; WordPress gave the new post the default category.
	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		wp_delete_object_term_relationships( $copy_id, $taxonomy );
	}

	return $copy_id;
}

/**
 * Runs code as if the page were shown in a language, so swiftling_transform() replaces texts with
 * their translations, and puts everything back afterwards. No filter is added: only the values the
 * code itself passes on are translated.
 *
 * @param string   $slug Address slug of the language.
 * @param callable $code Code to run.
 * @return mixed What the code returns.
 */
function swiftling_in_language( $slug, $code ) {
	$state = &swiftling_state();
	$saved = $state;

	$language          = swiftling_target_language( $slug );
	$state['mode']     = 'translate';
	$state['language'] = $slug;
	$state['locale']   = $language ? $language['locale'] : '';
	$state['hashes']   = array();
	$state['strings']  = array();
	$state['partial']  = array();
	$state['values']   = array();
	try {
		return $code();
	} finally {
		$state = $saved;
	}
}

/**
 * Puts the translations of a post's blocks in place, at the paths the translated view gives them as
 * the blocks render, see swiftling_filter_block(). Blocks that show another object, such as a synced
 * pattern, are left as they are: the copy shows that object, translated as everywhere.
 *
 * @param string $owner       Object key of the post.
 * @param array  $blocks      Parsed blocks.
 * @param string $parent_path Path of the parent block; empty for the top level.
 * @return array
 */
function swiftling_translate_blocks( $owner, $blocks, $parent_path ) {
	foreach ( $blocks as $index => $block ) {
		$path = ltrim( $parent_path . '/' . $index, '/' );
		$name = empty( $block['blockName'] ) ? 'freeform' : $block['blockName'];
		$base = 'b/' . $path;

		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			$attrs = $block['attrs'];
			unset( $attrs['metadata'] );
			$new = swiftling_transform( $owner, $base . '/a', 'block:' . $name, $attrs );
			if ( $new !== $attrs ) {
				$block['attrs'] = isset( $block['attrs']['metadata'] ) ? array_merge( $new, array( 'metadata' => $block['attrs']['metadata'] ) ) : $new;
			}
		}
		if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $chunk_index => $chunk ) {
				if ( is_string( $chunk ) ) {
					$block['innerContent'][ $chunk_index ] = swiftling_transform( $owner, $base . '/c' . $chunk_index, 'block:' . $name . '#html', $chunk );
				}
			}
			$block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
		}
		if ( ! empty( $block['innerBlocks'] ) && swiftling_block_child_owner( $block, $owner ) === $owner ) {
			$block['innerBlocks'] = swiftling_translate_blocks( $owner, $block['innerBlocks'], $path );
		}
		$blocks[ $index ] = $block;
	}
	return $blocks;
}

/**
 * Whether a meta value of the original stays out of its copy: WordPress's bookkeeping, SwiftLing's
 * own values, and what a builder writes again by itself, such as Elementor's CSS.
 *
 * @param string $key Meta key.
 * @return bool
 */
function swiftling_copy_leaves_out_meta( $key ) {
	static $bookkeeping = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_wp_desired_post_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' );
	return in_array( $key, $bookkeeping, true ) || 0 === strpos( $key, '_swiftling_' ) || in_array( $key, \SwiftLing\Builders\Registry::regeneratedMeta(), true );
}

/**
 * Whether the current user may make a separate copy of a post: someone who may translate it and
 * create posts of its type, for a post that can have copies.
 *
 * @param WP_Post|null $post Post.
 * @return bool
 */
function swiftling_can_make_copy( $post ) {
	$type = $post instanceof WP_Post ? get_post_type_object( $post->post_type ) : null;
	return $type && swiftling_can_have_copies( $post ) && current_user_can( SWIFTLING_TRANSLATE ) && current_user_can( 'edit_post', $post->ID ) && current_user_can( $type->cap->create_posts );
}

/* How a language shows a post: translated, as a separate copy, or as a duplicate of the original. */

/**
 * Returns the locales a post is a duplicate in: there it shows the original as it is.
 *
 * @param int $post_id Post ID.
 * @return string[]
 */
function swiftling_duplicate_locales( $post_id ) {
	return array_values( array_filter( array_map( 'strval', (array) get_post_meta( (int) $post_id, SWIFTLING_DUPLICATE, false ) ) ) );
}

/**
 * Whether a language shows a page of its own for a post: a published separate copy, or the original
 * as it is, a duplicate. There is nothing to translate of the original in that language.
 *
 * @param int    $post_id Post ID.
 * @param string $locale  Locale.
 * @return bool
 */
function swiftling_shows_own_page( $post_id, $locale ) {
	$copy = swiftling_copy_for( (int) $post_id, $locale );
	return ( $copy && 'publish' === $copy->post_status ) || in_array( $locale, swiftling_duplicate_locales( (int) $post_id ), true );
}

/**
 * Returns the posts that show the original as it is in a language, read once per request.
 *
 * @param string $locale  Locale.
 * @param bool   $refresh Whether to read them again, after a mode changed.
 * @return array<int, true> Post ID => true.
 */
function swiftling_duplicate_ids( $locale, $refresh = false ) {
	global $wpdb;
	static $found = array();
	if ( ! isset( $found[ $locale ] ) || $refresh ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One small query per request in another language; meta_key is indexed.
		$ids              = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, SWIFTLING_DUPLICATE, $locale ) );
		$found[ $locale ] = array_fill_keys( array_map( 'intval', $ids ), true );
	}
	return $found[ $locale ];
}

/**
 * Whether the texts of an object show untranslated on the page being shown: a post that is a
 * duplicate in the language of the page. Its translations stay stored, for when it is translated
 * again.
 *
 * @param string $owner Object key, e.g. "post:12".
 * @return bool
 */
function swiftling_shows_original( $owner ) {
	$state = &swiftling_state();
	if ( 'translate' !== $state['mode'] || '' === $state['locale'] || 0 !== strpos( $owner, 'post:' ) ) {
		return false;
	}
	return isset( swiftling_duplicate_ids( $state['locale'] )[ (int) substr( $owner, 5 ) ] );
}

/**
 * Returns how a language shows a post: translated in the translation editor, as a separate copy
 * edited by hand (manual, also while the copy is a draft), or as a duplicate of the original.
 *
 * @param int    $post_id Post ID.
 * @param string $locale  Locale.
 * @return string translation, manual or duplicate.
 */
function swiftling_language_mode( $post_id, $locale ) {
	if ( swiftling_copy_for( $post_id, $locale ) ) {
		return 'manual';
	}
	return in_array( $locale, swiftling_duplicate_locales( $post_id ), true ) ? 'duplicate' : 'translation';
}

/**
 * Whether the current user may choose how a language shows a post: someone who may translate it.
 * A separate copy also needs the rights swiftling_can_make_copy() names.
 *
 * @param WP_Post|null $post Post.
 * @return bool
 */
function swiftling_can_choose_mode( $post ) {
	return $post instanceof WP_Post && ! swiftling_copy_original_id( $post ) && current_user_can( SWIFTLING_TRANSLATE ) && current_user_can( 'edit_post', $post->ID );
}

/**
 * Chooses how a language shows a post. Moving away from a separate copy puts the copy in the trash,
 * where it can be restored; translations made in the editor always stay stored.
 *
 * @param WP_Post $post Post.
 * @param string  $slug Address slug of the language.
 * @param string  $mode translation, manual or duplicate.
 * @return string|WP_Error What happened: translation, copy_made, copy_kept or duplicate.
 */
function swiftling_set_language_mode( $post, $slug, $mode ) {
	$language = swiftling_target_language( $slug );
	if ( ! $language || ! swiftling_can_choose_mode( $post ) || ! in_array( $mode, array( 'translation', 'manual', 'duplicate' ), true ) ) {
		return new WP_Error( 'swiftling_mode_refused', __( 'This cannot be changed here.', 'swiftling' ) );
	}
	$locale = $language['locale'];
	$copy   = swiftling_copy_for( $post->ID, $locale );

	if ( 'manual' === $mode ) {
		if ( $copy ) {
			return 'copy_kept';
		}
		if ( ! swiftling_can_make_copy( $post ) ) {
			return new WP_Error( 'swiftling_copy_refused', __( 'This content cannot have a separate copy.', 'swiftling' ) );
		}
		// The copy starts with the translations, not with the original a duplicate shows.
		delete_post_meta( $post->ID, SWIFTLING_DUPLICATE, $locale );
		swiftling_duplicate_ids( $locale, true );
		$made = swiftling_make_copy( $post, $slug );
		return is_wp_error( $made ) ? $made : 'copy_made';
	}

	if ( $copy ) {
		if ( ! current_user_can( 'delete_post', $copy->ID ) ) {
			return new WP_Error( 'swiftling_copy_kept', __( 'You may not remove the separate copy.', 'swiftling' ) );
		}
		wp_trash_post( $copy->ID );
	}
	delete_post_meta( $post->ID, SWIFTLING_DUPLICATE, $locale );
	if ( 'duplicate' === $mode ) {
		add_post_meta( $post->ID, SWIFTLING_DUPLICATE, $locale );
	}
	swiftling_duplicate_ids( $locale, true );
	swiftling_refresh_translated( array( $post->ID ) );
	swiftling_purge_post( $post, array( $slug ) );
	return $mode;
}

/**
 * Chooses how a language shows a post from the translation editor, then opens the editor of the
 * language again.
 */
function swiftling_handle_language_mode() {
	$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
	$slug    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
	$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
	check_admin_referer( 'swiftling_mode_' . $post_id );

	$post = get_post( $post_id );
	if ( ! swiftling_can_choose_mode( $post ) ) {
		wp_die( esc_html__( 'You may not change how this content is translated.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	$done = swiftling_set_language_mode( $post, $slug, $mode );
	wp_safe_redirect( add_query_arg( 'swiftling_notice', is_wp_error( $done ) ? $done->get_error_code() : 'mode_' . $done, swiftling_editor_url( $post->ID, $slug ) ) );
	exit;
}
add_action( 'admin_post_swiftling_language_mode', 'swiftling_handle_language_mode' );

/* The translated view: the copy answers for its original. */

/**
 * Answers a request for a post in a language with its published copy for that language: WordPress
 * then looks up the copy itself, so the theme, the builder and plugins show it as the page it is.
 *
 * @param array $vars Query variables of the request.
 * @return array
 */
function swiftling_request_copy( $vars ) {
	$state = &swiftling_state();
	if ( 'translate' !== $state['mode'] || '' === $state['locale'] || ! is_array( $vars ) ) {
		return $vars;
	}
	foreach ( swiftling_published_copies( $state['locale'] ) as $original_id => $copy_id ) {
		$original = get_post( $original_id );
		if ( $original && 'publish' === $original->post_status && swiftling_request_names_post( $vars, $original ) ) {
			return swiftling_request_vars_for( $vars, get_post( $copy_id ) );
		}
	}
	return $vars;
}
add_filter( 'request', 'swiftling_request_copy', 20 );

/**
 * Whether the query variables of a request name a post: by ID, by its path for a page, or by its
 * slug and type.
 *
 * @param array   $vars Query variables.
 * @param WP_Post $post Post.
 * @return bool
 */
function swiftling_request_names_post( $vars, $post ) {
	foreach ( array( 'p', 'page_id' ) as $key ) {
		if ( ! empty( $vars[ $key ] ) ) {
			return (int) $vars[ $key ] === $post->ID;
		}
	}
	$type = get_post_type_object( $post->post_type );
	if ( ! $type ) {
		return false;
	}
	$named = '';
	if ( 'page' === $post->post_type && isset( $vars['pagename'] ) ) {
		$named = (string) $vars['pagename'];
	} elseif ( $type->query_var && isset( $vars[ $type->query_var ] ) ) {
		$named = (string) $vars[ $type->query_var ];
	} elseif ( 'post' === $post->post_type && isset( $vars['name'] ) && ( empty( $vars['post_type'] ) || 'post' === $vars['post_type'] ) ) {
		$named = (string) $vars['name'];
	}
	if ( '' === $named ) {
		return false;
	}
	$path = $type->hierarchical ? swiftling_original_page_uri( $post ) : $post->post_name;
	return trim( urldecode( $named ), '/' ) === trim( urldecode( (string) $path ), '/' );
}

/**
 * Returns the path of a page with the original slugs of the page and its parents, as WordPress
 * reads a request in another language once SwiftLing put the original slugs back into it (see
 * swiftling_untranslate_request()). get_page_uri() gives the slugs of the language being shown.
 *
 * @param WP_Post $post Page, or a post of another hierarchical type.
 * @return string Path, such as "kako-do-nas/parking".
 */
function swiftling_original_page_uri( $post ) {
	$uri = (string) $post->post_name;
	foreach ( get_post_ancestors( $post ) as $ancestor ) {
		$parent = get_post( $ancestor );
		if ( $parent && '' !== $parent->post_name ) {
			$uri = $parent->post_name . '/' . $uri;
		}
	}
	return $uri;
}

/**
 * Returns query variables that look up another post the way the request looked up its original:
 * the same page of a paginated post, comment page and preview, the other post. Everything that
 * found the original, such as its date or category in the address, is left out: the copy was made
 * on another day.
 *
 * @param array   $vars Query variables.
 * @param WP_Post $copy Copy.
 * @return array
 */
function swiftling_request_vars_for( $vars, $copy ) {
	$vars = array_intersect_key( $vars, array_flip( array( 'page', 'paged', 'cpage', 'preview', 'preview_id', 'preview_nonce', 'embed' ) ) );
	if ( 'page' === $copy->post_type ) {
		$vars['page_id'] = $copy->ID;
	} else {
		$vars['p']         = $copy->ID;
		$vars['post_type'] = $copy->post_type;
	}
	return $vars;
}

/**
 * Returns the front page the site shows, as its settings say, ignoring the copy of a language.
 *
 * @return int Page ID, or 0 when the front page lists the newest posts.
 */
function swiftling_real_front_page() {
	static $reading = false;
	if ( $reading ) {
		return 0;
	}
	$reading = true;
	$front   = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	$reading = false;
	return $front;
}

/**
 * Shows the published copy of the front page in its language: the front page of that language is
 * the copy, so WordPress picks the front page's template for it and its links lead to the site
 * address in that language.
 *
 * @param mixed $pre Value from an earlier filter, or false.
 * @return mixed
 */
function swiftling_front_page_copy( $pre ) {
	$state = &swiftling_state();
	if ( false !== $pre || 'translate' !== $state['mode'] || '' === $state['locale'] ) {
		return $pre;
	}
	$front  = swiftling_real_front_page();
	$copies = $front ? swiftling_published_copies( $state['locale'] ) : array();
	return isset( $copies[ $front ] ) ? $copies[ $front ] : $pre;
}
add_filter( 'pre_option_page_on_front', 'swiftling_front_page_copy' );

/**
 * Gives every address of a published copy as its original's address in the copy's language, so
 * links, canonical addresses and the copy's own address lead there. A draft keeps its own address,
 * for previews.
 *
 * @param string      $link Address.
 * @param WP_Post|int $post Post.
 * @return string
 */
function swiftling_copy_permalink( $link, $post ) {
	$post = get_post( $post );
	// A builder that asks for an address with plain permalinks for a moment, as Elementor does for the
	// preview in its editor (Document::get_preview_url()), gets the copy's own: the address of a
	// language cannot be written that way on a site with language folders.
	if ( ! $post || 'publish' !== $post->post_status || has_filter( 'pre_option_permalink_structure', '__return_empty_string' ) ) {
		return $link;
	}
	$original_id = swiftling_copy_original_id( $post );
	if ( ! $original_id ) {
		return $link;
	}
	$slug = swiftling_locale_slug( (string) get_post_meta( $post->ID, SWIFTLING_COPY_LANG, true ) );
	if ( '' === $slug ) {
		return (string) get_permalink( $original_id );
	}

	// The original's address with the slugs of the copy's language, without the folder of the page being shown.
	$base = swiftling_in_link_language(
		$slug,
		static function () use ( $original_id ) {
			return swiftling_unlocalized_url(
				static function () use ( $original_id ) {
					return swiftling_real_front_page() === $original_id ? home_url( '/' ) : get_permalink( $original_id );
				}
			);
		}
	);
	return swiftling_language_url( $base, $slug );
}
add_filter( 'page_link', 'swiftling_copy_permalink', 20, 2 );
add_filter( 'post_link', 'swiftling_copy_permalink', 20, 2 );
add_filter( 'post_type_link', 'swiftling_copy_permalink', 20, 2 );

/**
 * Sends a request for a published copy at any other address, such as its own slug or the original's
 * address in another language, to the original's address in the copy's language. WordPress itself
 * shows a page at the address it is asked for. Previews, also in a builder's editor, page checks
 * and the Customizer stay.
 *
 * Temporary (302), as SwiftLing's other redirects: the copy may be taken back tomorrow.
 */
function swiftling_redirect_copy() {
	if ( ! is_singular() || is_preview() || is_customize_preview() || swiftling_is_probe() || \SwiftLing\Builders\Registry::isEditorPreview() ) {
		return;
	}
	$copy = get_queried_object();
	if ( ! $copy instanceof WP_Post || 'publish' !== $copy->post_status || ! swiftling_copy_original_id( $copy ) ) {
		return;
	}
	$target = (string) get_permalink( $copy );
	$asked  = isset( $_SERVER['REQUEST_URI'] ) ? (string) strtok( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '#' ) : '';
	// Both in one spelling, see swiftling_normalize_address(): a proxy may write %D0%BE for %d0%be.
	$same = untrailingslashit( (string) wp_parse_url( swiftling_normalize_address( $target ), PHP_URL_PATH ) ) === untrailingslashit( (string) wp_parse_url( swiftling_normalize_address( $asked ), PHP_URL_PATH ) );
	// The language of the parameter format sits in the query.
	$lang = (string) wp_parse_url( $target, PHP_URL_QUERY );
	if ( $same && ( '' === $lang || false !== strpos( (string) wp_parse_url( $asked, PHP_URL_QUERY ), $lang ) ) ) {
		return;
	}
	wp_safe_redirect( $target, 302, 'SwiftLing' );
	exit;
}
add_action( 'template_redirect', 'swiftling_redirect_copy', 3 );

/**
 * Previews a copy in its language, with the header, footer and menus of that language around it.
 *
 * @param string  $link Preview address.
 * @param WP_Post $post Post.
 * @return string
 */
function swiftling_copy_preview_link( $link, $post ) {
	$slug = swiftling_copy_original_id( $post ) ? swiftling_locale_slug( (string) get_post_meta( $post->ID, SWIFTLING_COPY_LANG, true ) ) : '';
	return '' === $slug ? $link : swiftling_language_url( $link, $slug );
}
add_filter( 'preview_post_link', 'swiftling_copy_preview_link', 20, 2 );

/**
 * Returns the address slug of a target language.
 *
 * @param string $locale Locale.
 * @return string Empty when the site no longer has the language.
 */
function swiftling_locale_slug( $locale ) {
	foreach ( swiftling_languages()['targets'] as $slug => $language ) {
		if ( $language['locale'] === $locale ) {
			return (string) $slug;
		}
	}
	return '';
}

/**
 * Keeps copies out of every list of posts visitors see: archives, search, feeds, sitemaps, widgets
 * and blocks that list posts, and the REST API. The admin and WP-CLI list them, so they can be
 * edited.
 *
 * @param string   $where WHERE clause of the query.
 * @param WP_Query $query Query.
 * @return string
 */
function swiftling_filter_copies_where( $where, $query ) {
	global $wpdb;
	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( $query->is_main_query() && $query->is_singular() ) || ! swiftling_copy_map() ) {
		return $where;
	}
	return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s )", SWIFTLING_COPY_OF );
}
add_filter( 'posts_where', 'swiftling_filter_copies_where', 10, 2 );

/**
 * Keeps copies out of lists built with get_pages(), such as wp_list_pages() and the Page List block.
 *
 * @param WP_Post[] $pages Pages.
 * @return WP_Post[]
 */
function swiftling_filter_copy_pages( $pages ) {
	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! is_array( $pages ) ) {
		return $pages;
	}
	return array_values(
		array_filter(
			$pages,
			static function ( $page ) {
				return ! swiftling_copy_original_id( $page );
			}
		)
	);
}
add_filter( 'get_pages', 'swiftling_filter_copy_pages' );

/**
 * Marks the menu items of the original as the current page while its copy is shown.
 *
 * @param array<int, WP_Post> $items Menu items.
 * @return array<int, WP_Post>
 */
function swiftling_mark_copy_menu_items( $items ) {
	$original_id = is_singular() ? swiftling_copy_original_id( get_queried_object_id() ) : 0;
	if ( ! $original_id || ! is_array( $items ) ) {
		return $items;
	}
	foreach ( $items as $item ) {
		if ( isset( $item->object_id, $item->type ) && 'post_type' === $item->type && (int) $item->object_id === $original_id ) {
			$item->current = true;
			$item->classes = array_merge( (array) $item->classes, array( 'current-menu-item', 'current_page_item' ) );
		}
	}
	return $items;
}
add_filter( 'wp_nav_menu_objects', 'swiftling_mark_copy_menu_items', 20 );

/* Categories, tags and other taxonomies: the original's. */

/**
 * Gives a copy the terms of its original wherever they are read through get_the_terms(): the
 * categories and tags of a theme, blocks and builder widgets.
 *
 * @param WP_Term[]|false|WP_Error $terms    Terms.
 * @param int                      $post_id  Post ID.
 * @param string                   $taxonomy Taxonomy.
 * @return WP_Term[]|false|WP_Error
 */
function swiftling_copy_terms( $terms, $post_id, $taxonomy ) {
	$original_id = swiftling_copy_original_id( (int) $post_id );
	return $original_id ? get_the_terms( $original_id, $taxonomy ) : $terms;
}
add_filter( 'get_the_terms', 'swiftling_copy_terms', 10, 3 );

/**
 * Gives a copy the terms of its original where they are read with wp_get_object_terms(): one post
 * at a time, as the REST API does, or several at once, as WordPress fills its cache of terms, which
 * has_term() reads.
 *
 * @param WP_Term[]|int[]|string[] $terms      Terms found.
 * @param int[]                    $object_ids Objects asked for.
 * @param string[]                 $taxonomies Taxonomies.
 * @param array                    $args       Arguments of the query.
 * @return array
 */
function swiftling_copy_object_terms( $terms, $object_ids, $taxonomies, $args ) {
	static $asking = false;
	if ( $asking || ! is_array( $terms ) ) {
		return $terms;
	}
	$object_ids = array_map( 'intval', (array) $object_ids );
	$copies     = array();
	foreach ( $object_ids as $object_id ) {
		$original_id = swiftling_copy_original_id( $object_id );
		if ( $original_id ) {
			$copies[ $object_id ] = $original_id;
		}
	}
	if ( ! $copies ) {
		return $terms;
	}

	// Each copy gets its original's terms, marked as its own where terms say whose they are.
	$asking = true;
	foreach ( $copies as $copy_id => $original_id ) {
		$found = wp_get_object_terms( $original_id, $taxonomies, $args );
		if ( is_wp_error( $found ) ) {
			continue;
		}
		foreach ( $found as $term ) {
			if ( $term instanceof WP_Term && isset( $term->object_id ) ) {
				$term            = clone $term;
				$term->object_id = $copy_id;
			}
			$terms[] = $term;
		}
	}
	$asking = false;
	return $terms;
}
add_filter( 'get_object_terms', 'swiftling_copy_object_terms', 10, 4 );

/**
 * Takes away terms given to a copy, as the block editor does when it saves the terms it shows,
 * which are the original's: the copy keeps showing those.
 *
 * @param int    $object_id Post ID.
 * @param array  $terms     Terms given.
 * @param array  $tt_ids    Term taxonomy IDs given.
 * @param string $taxonomy  Taxonomy.
 */
function swiftling_keep_copy_without_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
	static $removing = false;
	if ( $removing || ! $tt_ids || ! swiftling_copy_original_id( (int) $object_id ) ) {
		return;
	}
	$removing = true;
	wp_delete_object_term_relationships( (int) $object_id, $taxonomy );
	$removing = false;
}
add_action( 'set_object_terms', 'swiftling_keep_copy_without_terms', 10, 4 );

/* Life of a copy. */

/**
 * Moves the copies of a post to the trash with it, and deletes them with it.
 *
 * @param int $post_id Post ID.
 */
function swiftling_trash_copies( $post_id ) {
	foreach ( swiftling_copies_of( (int) $post_id ) as $copy ) {
		wp_trash_post( $copy->ID );
	}
}
add_action( 'wp_trash_post', 'swiftling_trash_copies' );

/**
 * Deletes the copies of a post that is deleted for good, also those in the trash.
 *
 * @param int $post_id Post ID.
 */
function swiftling_delete_copies( $post_id ) {
	$copies = get_posts(
		array(
			'post_type'      => 'any',
			// "any" leaves out the trash, where a copy waits after its original went there first.
			'post_status'    => array( 'any', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Only when a post is deleted.
			'meta_key'       => SWIFTLING_COPY_OF,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			'meta_value'     => (string) (int) $post_id,
		)
	);
	foreach ( $copies as $copy_id ) {
		wp_delete_post( (int) $copy_id, true );
	}
}
add_action( 'before_delete_post', 'swiftling_delete_copies' );

/**
 * Writes again whether the original is translated when one of its copies is published, taken back,
 * moved to the trash or deleted: a published copy counts as its language's translation.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function swiftling_copy_status_changed( $new_status, $old_status, $post ) {
	$original_id = $new_status !== $old_status ? swiftling_copy_original_id( $post ) : 0;
	if ( $original_id ) {
		swiftling_refresh_translated( array( $original_id ) );
	}
}
add_action( 'transition_post_status', 'swiftling_copy_status_changed', 10, 3 );

/**
 * Names a copy in the admin's list of pages, next to its title.
 *
 * @param string[] $states States shown after the title.
 * @param WP_Post  $post   Post.
 * @return string[]
 */
function swiftling_copy_post_state( $states, $post ) {
	$original_id = swiftling_copy_original_id( $post );
	if ( ! $original_id ) {
		return $states;
	}
	$locale   = (string) get_post_meta( $post->ID, SWIFTLING_COPY_LANG, true );
	$slug     = swiftling_locale_slug( $locale );
	$language = '' === $slug ? null : swiftling_target_language( $slug );
	/* translators: 1: language name, e.g. Deutsch, 2: title of the original. */
	$states['swiftling_copy'] = sprintf( __( '%1$s copy of “%2$s”', 'swiftling' ), $language ? $language['name'] : $locale, get_the_title( $original_id ) );
	return $states;
}
add_filter( 'display_post_states', 'swiftling_copy_post_state', 10, 2 );
