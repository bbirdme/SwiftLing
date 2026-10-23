<?php
/**
 * Page caches: emptying the addresses a change makes stale.
 *
 * Cache plugins and CDNs keep each language at its own address. They empty what they know is stale
 * after WordPress changes something, but they know nothing of a translation saved in the editor,
 * which WordPress does not see, nor of a changed post's addresses in the other languages. So
 * SwiftLing collects, during a request, the addresses a change makes stale, and empties them once,
 * at its end:
 *
 * - translations of a post saved, or how a language shows it chosen: its address in that language,
 *   with the one it had before when its slug changed, and the front page of that language;
 * - a post published, changed, unpublished or deleted: its addresses in every language, before and
 *   after the change, and the front page of each language;
 * - translations of something shared saved: every page, as every page can show a template, a part
 *   of one, the site's texts, texts from code and terms. Only for a synced pattern or another post
 *   shown inside posts are the pages known, from the page checks that saw it (the refs of each
 *   map), and then only those are emptied, as long as every published post had its page check;
 * - the languages, their address format, the switcher or the settings of untranslated content
 *   changed: every page.
 *
 * At the end of the request SwiftLing fires swiftling_purge_urls with the addresses and the posts
 * they belong to, or swiftling_purge_all, for hosting and other plugins to empty their own cache;
 * cache-plugins.php empties the cache plugins SwiftLing knows.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns what the end of the request empties.
 *
 * @return array{all: bool, urls: array<string, true>, posts: array<int, true>}
 */
function &swiftling_purge_queue() {
	static $queue = array(
		'all'   => false,
		'urls'  => array(),
		'posts' => array(),
	);
	return $queue;
}

/**
 * Empties addresses at the end of the request.
 *
 * @param string[] $urls     Addresses.
 * @param int[]    $post_ids Posts they belong to, for caches that empty by post.
 */
function swiftling_purge_urls_later( $urls, $post_ids = array() ) {
	$queue = &swiftling_purge_queue();
	foreach ( $urls as $url ) {
		if ( is_string( $url ) && '' !== $url ) {
			$queue['urls'][ $url ] = true;
		}
	}
	foreach ( $post_ids as $post_id ) {
		$queue['posts'][ (int) $post_id ] = true;
	}
	swiftling_schedule_purge();
}

/**
 * Empties every page at the end of the request.
 */
function swiftling_purge_all_later() {
	$queue        = &swiftling_purge_queue();
	$queue['all'] = true;
	swiftling_schedule_purge();
}

/**
 * Empties a post's addresses in some languages at the end of the request, and the front page of
 * each of those languages, which lists the newest posts. A separate copy has one address: its
 * original's in its language. A post that is not published has none.
 *
 * @param WP_Post  $post  Post, also as it was before a change.
 * @param string[] $slugs Address slugs of languages, an empty string for the original.
 */
function swiftling_purge_post( $post, $slugs ) {
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return;
	}
	$urls = array();
	if ( swiftling_copy_original_id( $post ) ) {
		$urls[] = (string) get_permalink( $post );
		$slugs  = array( swiftling_locale_slug( (string) get_post_meta( $post->ID, SWIFTLING_COPY_LANG, true ) ) );
	} else {
		foreach ( $slugs as $slug ) {
			$urls[] = swiftling_post_language_url( $post, (string) $slug );
		}
	}
	foreach ( $slugs as $slug ) {
		$urls[] = swiftling_language_url( swiftling_unlocalized_url( 'swiftling_purge_home' ), (string) $slug );
	}
	swiftling_purge_urls_later( $urls, array( $post->ID ) );
}

/**
 * Returns the site address, for swiftling_unlocalized_url().
 *
 * @return string
 */
function swiftling_purge_home() {
	return home_url( '/' );
}

/**
 * Returns the address slugs of every language: the original's, as an empty string, and the targets'.
 *
 * @return string[]
 */
function swiftling_purge_languages() {
	return array_merge( array( '' ), array_map( 'strval', array_keys( swiftling_languages()['targets'] ) ) );
}

/**
 * Empties the queued addresses once, at the end of the request.
 */
function swiftling_schedule_purge() {
	if ( false === has_action( 'shutdown', 'swiftling_run_purge' ) ) {
		add_action( 'shutdown', 'swiftling_run_purge', 5 );
	}
}

/**
 * Empties what the request queued: everything, or the queued addresses.
 */
function swiftling_run_purge() {
	$queue = &swiftling_purge_queue();
	$done  = $queue;
	$queue = array(
		'all'   => false,
		'urls'  => array(),
		'posts' => array(),
	);

	$plugins = swiftling_active_cache_plugins();
	if ( $done['all'] ) {
		/**
		 * Fires once at the end of a request after which every page may be stale, for page caches
		 * to empty everything.
		 */
		do_action( 'swiftling_purge_all' );
		if ( $plugins ) {
			/* translators: %s: names of cache plugins, e.g. WP Rocket. */
			swiftling_log( 'event', 'cache', sprintf( __( 'Emptied the whole cache of %s, as a change shows on every page.', 'swiftling' ), wp_sprintf( '%l', $plugins ) ) );
		}
		return;
	}
	if ( $done['urls'] ) {
		/**
		 * Fires once at the end of a request after which some pages are stale, for page caches to
		 * empty them.
		 *
		 * @param string[] $urls     Addresses, in every language they are stale in.
		 * @param int[]    $post_ids Posts the addresses belong to, for caches that empty by post.
		 */
		do_action( 'swiftling_purge_urls', array_keys( $done['urls'] ), array_keys( $done['posts'] ) );
		if ( $plugins ) {
			swiftling_log(
				'event',
				'cache',
				/* translators: 1: number of addresses, 2: names of cache plugins, e.g. WP Rocket. */
				sprintf( _n( 'Emptied %1$s address from the cache of %2$s after a translation changed.', 'Emptied %1$s addresses from the cache of %2$s after a translation changed.', count( $done['urls'] ), 'swiftling' ), number_format_i18n( count( $done['urls'] ) ), wp_sprintf( '%l', $plugins ) )
			);
		}
	}
}

/*
 * What makes pages stale.
 */

/**
 * Empties the pages saved translations change, see the top of this file.
 *
 * @param array    $subject Subject of the editor, see swiftling_subject().
 * @param string   $slug    Address slug of the language.
 * @param string[] $places  Keys of the places whose translations were saved.
 */
function swiftling_purge_translations( $subject, $slug, $places ) {
	foreach ( swiftling_place_owners( $subject['key'], $places ) as $owner ) {
		if ( 'post' === $subject['kind'] && $owner === $subject['key'] ) {
			swiftling_purge_post( $subject['post'], array( $slug ) );
		} else {
			swiftling_purge_shown( $owner, $slug );
		}
	}
}

/**
 * Returns the objects some places of a subject's texts belong to: the subject's own, or a shared
 * object seen on its page.
 *
 * @param string   $key    Subject key.
 * @param string[] $places Place keys.
 * @return string[] Object keys.
 */
function swiftling_place_owners( $key, $places ) {
	$maps = swiftling_get_maps( array( $key ) );
	if ( ! isset( $maps[ $key ] ) ) {
		return array();
	}
	$places = array_flip( $places );
	$owners = array();
	foreach ( swiftling_map_places( $key, $maps[ $key ] ) as $unit_key => $place ) {
		if ( ! isset( $places[ $place[0] ] ) && ! isset( $places[ $place[1] ] ) ) {
			continue;
		}
		$target               = swiftling_is_shared_key( $unit_key ) ? swiftling_shared_key_target( $unit_key, $maps[ $key ]['refs'] ) : array( $key );
		$owners[ $target[0] ] = true;
	}
	return array_keys( $owners );
}

/**
 * Empties the pages that show a shared object in a language: those whose page checks saw it, when
 * they are known, otherwise every page.
 *
 * @param string $owner Object key.
 * @param string $slug  Address slug of the language.
 */
function swiftling_purge_shown( $owner, $slug ) {
	$pages = swiftling_pages_showing( $owner );
	if ( null === $pages ) {
		swiftling_purge_all_later();
		return;
	}
	foreach ( $pages as $page ) {
		swiftling_purge_post( $page, array( $slug ) );
	}
}

/**
 * Returns the posts that show a shared object, when they are known: the object is a post, such as
 * a synced pattern, only posts that visitors open saw it in their page checks, and every published
 * one of those had its page check. A post never checked may show it too.
 *
 * @param string $owner Object key.
 * @return WP_Post[]|null The posts, the object itself among them when it is one; null when unknown.
 */
function swiftling_pages_showing( $owner ) {
	global $wpdb;
	// A comment shows on the page it is written on, and on no other, see includes/comments.php.
	$comment = swiftling_owner_comment( $owner );
	if ( $comment ) {
		$page = get_post( (int) $comment->comment_post_ID );
		return $page ? array( $page ) : array();
	}
	$object = 0 === strpos( $owner, 'post:' ) ? get_post( (int) substr( $owner, 5 ) ) : null;
	if ( ! $object ) {
		return null;
	}

	$types = array_keys( swiftling_content_types() );
	$pages = in_array( $object->post_type, $types, true ) ? array( $object ) : array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read once after a save.
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT object_key FROM %i WHERE CONCAT( ' ', refs, ' ' ) LIKE %s", swiftling_table( 'objects' ), '% ' . $wpdb->esc_like( $owner ) . ' %' ) );
	foreach ( $keys as $key ) {
		$page = 0 === strpos( $key, 'post:' ) ? get_post( (int) substr( $key, 5 ) ) : null;
		// A template, or another page made from one, shows it on pages of its own.
		if ( ! $page || ! in_array( $page->post_type, $types, true ) ) {
			return null;
		}
		$pages[] = $page;
	}

	if ( ! $types ) {
		return $pages;
	}
	$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Read once after a save; $in has one placeholder per type.
	$unchecked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i AS p WHERE p.post_status = 'publish' AND p.post_type IN ({$in}) AND NOT EXISTS ( SELECT 1 FROM %i AS o WHERE o.object_key = CONCAT( 'post:', p.ID ) )", ...array_merge( array( $wpdb->posts ), $types, array( swiftling_table( 'objects' ) ) ) ) );
	return $unchecked ? null : $pages;
}

/**
 * Empties a post's addresses in every language before and after a change: its content, its slug or
 * its status, also into the trash.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $after   The post now.
 * @param WP_Post $before  The post before.
 */
function swiftling_purge_changed_post( $post_id, $after, $before ) {
	if ( swiftling_purges_type( $after ) ) {
		swiftling_purge_post( $before, swiftling_purge_languages() );
		swiftling_purge_post( $after, swiftling_purge_languages() );
	}
}
add_action( 'post_updated', 'swiftling_purge_changed_post', 10, 3 );

/**
 * Empties a post's addresses in every language when it is published, also by wp_publish_post(),
 * which changes no other field and fires no post_updated.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function swiftling_purge_published_post( $new_status, $old_status, $post ) {
	if ( 'publish' === $new_status && 'publish' !== $old_status && swiftling_purges_type( $post ) ) {
		swiftling_purge_post( $post, swiftling_purge_languages() );
	}
}
add_action( 'transition_post_status', 'swiftling_purge_published_post', 10, 3 );

/**
 * Empties a post's addresses in every language before it is deleted.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 */
function swiftling_purge_deleted_post( $post_id, $post = null ) {
	$post = $post instanceof WP_Post ? $post : get_post( $post_id );
	if ( $post && swiftling_purges_type( $post ) ) {
		swiftling_purge_post( $post, swiftling_purge_languages() );
	}
}
add_action( 'before_delete_post', 'swiftling_purge_deleted_post', 10, 2 );

/**
 * Whether a change of a post makes pages in other languages stale: its type is one visitors open,
 * and the site has other languages. A type set to Never shows its original there, so it counts too.
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function swiftling_purges_type( $post ) {
	return $post instanceof WP_Post && isset( swiftling_viewable_types()[ $post->post_type ] ) && swiftling_languages()['targets'];
}

/**
 * Empties every page when the languages, the switcher or the settings of untranslated content change.
 */
function swiftling_purge_after_settings() {
	swiftling_purge_all_later();
}
foreach ( array( 'swiftling_languages', 'swiftling_settings', 'swiftling_switcher' ) as $swiftling_option ) {
	add_action( 'add_option_' . $swiftling_option, 'swiftling_purge_after_settings' );
	add_action( 'update_option_' . $swiftling_option, 'swiftling_purge_after_settings' );
}
unset( $swiftling_option );
