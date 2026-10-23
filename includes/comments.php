<?php
/**
 * Comments and reviews visitors read under a page.
 *
 * A comment is an object of its own, "comment:<ID>", with one text: what its author wrote, at the
 * path "field/comment_content". Its author's name stays as written, and so do the stars of a review.
 * A page can have thousands of comments, so they are not texts of the page's editor: each kind of
 * content has a list of its own in the translation editor, "comments:<post type>", one page of
 * SWIFTLING_COMMENTS_PER_PAGE at a time, opened from the Comments screen or from the page's editor.
 *
 * The list is read from the database a page at a time. Which comments have a translation is told
 * by the database too: a translation is saved under a digest of the comment's place and text, see
 * swiftling_place_key(), which MySQL computes for every comment with MD5() as PHP does with md5(),
 * so a list of thousands of comments is never read whole to count it.
 *
 * Translated are the comments visitors read: approved ones on published pages of the kinds of
 * content SwiftLing translates, see swiftling_content_types(). Pingbacks and trackbacks quote
 * another site, notes are for the site's editors only, and comments waiting or marked as spam are
 * not shown; none of them is listed. Nothing is sent to a translation service unless the user asks
 * for it in the editor. A comment shows translated once its translation in the language is saved,
 * see swiftling_filter_comment_text(); a comment that changed since shows as written again.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comments the editor of a list shows on one page.
 */
const SWIFTLING_COMMENTS_PER_PAGE = 20;

/**
 * Comments one batch of "translate every comment without a translation" reads.
 */
const SWIFTLING_COMMENT_BATCH = 50;

/**
 * The kinds of comments visitors read: comments, WooCommerce's reviews, and comments saved before
 * WordPress 5.5, which have no kind.
 */
const SWIFTLING_COMMENT_TYPES = array( 'comment', 'review', '' );

/**
 * Path of a comment's text inside its object.
 */
const SWIFTLING_COMMENT_PATH = 'field/comment_content';

/**
 * Returns the object key of a comment.
 *
 * @param int $comment_id Comment ID.
 * @return string
 */
function swiftling_comment_owner( $comment_id ) {
	return 'comment:' . (int) $comment_id;
}

/**
 * Returns the comment of an object key, or null when the key names none.
 *
 * @param string $owner Object key.
 * @return WP_Comment|null
 */
function swiftling_owner_comment( $owner ) {
	if ( 0 !== strpos( (string) $owner, 'comment:' ) || ! ctype_digit( substr( (string) $owner, 8 ) ) ) {
		return null;
	}
	$comment = get_comment( (int) substr( (string) $owner, 8 ) );
	return $comment instanceof WP_Comment ? $comment : null;
}

/**
 * Whether a comment is of a kind visitors read, see SWIFTLING_COMMENT_TYPES.
 *
 * @param WP_Comment $comment Comment.
 * @return bool
 */
function swiftling_is_translated_comment_type( $comment ) {
	return in_array( (string) $comment->comment_type, SWIFTLING_COMMENT_TYPES, true );
}

/**
 * Returns the subject of the editor for the comments of a kind of content.
 *
 * @param string $post_type Post type.
 * @return array|null Null for a kind of content SwiftLing does not translate.
 */
function swiftling_comments_subject( $post_type ) {
	$types = swiftling_content_types();
	if ( ! isset( $types[ $post_type ] ) ) {
		return null;
	}
	return array(
		'key'   => 'comments:' . $post_type,
		'kind'  => 'comments',
		/* translators: %s: kind of content, e.g. Posts or Products. */
		'title' => sprintf( __( 'Comments on %s', 'swiftling' ), $types[ $post_type ]->labels->name ),
		'type'  => __( 'Comments and reviews', 'swiftling' ),
		'url'   => '',
		'probe' => false,
		'post'  => null,
		'cap'   => 'moderate_comments',
		'note'  => __( 'Each comment is translated on its own and shows translated under its page in the language. The names of the authors and the stars of a review stay as written. A comment without a translation shows as it was written.', 'swiftling' ),
	);
}

/**
 * Returns the part of a query that picks the comments of a list: FROM, the join of their
 * translations into a language when one is given, and WHERE, with its values.
 *
 * @param string $post_type Post type.
 * @param array  $args      for (post ID, 0 for all), search (words in the text or the author's
 *                          name), locale (whose translations to join, empty for none), missing (only
 *                          those without a translation) and before (only comments with a lower ID).
 * @return array{0: string, 1: array} SQL, and the values of its placeholders in order.
 */
function swiftling_comment_query( $post_type, $args ) {
	global $wpdb;
	$args   = wp_parse_args(
		$args,
		array(
			'for'     => 0,
			'search'  => '',
			'locale'  => '',
			'missing' => false,
			'before'  => 0,
		)
	);
	$types  = implode( ',', array_fill( 0, count( SWIFTLING_COMMENT_TYPES ), '%s' ) );
	$sql    = 'FROM %i AS c INNER JOIN %i AS p ON p.ID = c.comment_post_ID';
	$values = array( $wpdb->comments, $wpdb->posts );
	if ( '' !== $args['locale'] ) {
		// The place of the comment's text as it is now, as swiftling_place_key() makes it.
		$sql     .= ' LEFT JOIN %i AS s ON s.lang = %s AND s.source_hash = UNHEX( MD5( CONCAT( %s, c.comment_ID, %s, MD5( c.comment_content ) ) ) )';
		$values[] = swiftling_table( 'strings' );
		$values[] = $args['locale'];
		$values[] = 'comment:';
		$values[] = '|' . swiftling_path_key( SWIFTLING_COMMENT_PATH ) . '|';
	}
	$sql   .= " WHERE c.comment_approved = '1' AND c.comment_type IN ({$types}) AND p.post_status = 'publish' AND p.post_type = %s";
	$values = array_merge( $values, SWIFTLING_COMMENT_TYPES, array( $post_type ) );
	if ( $args['for'] ) {
		$sql     .= ' AND c.comment_post_ID = %d';
		$values[] = (int) $args['for'];
	}
	if ( '' !== trim( $args['search'] ) ) {
		$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
		$sql     .= ' AND ( c.comment_content LIKE %s OR c.comment_author LIKE %s )';
		$values[] = $like;
		$values[] = $like;
	}
	if ( $args['missing'] && '' !== $args['locale'] ) {
		$sql .= ' AND s.source_hash IS NULL';
	}
	if ( $args['before'] ) {
		$sql     .= ' AND c.comment_ID < %d';
		$values[] = (int) $args['before'];
	}
	return array( $sql, $values );
}

/**
 * Counts the comments of a list, those of them with a translation into a language, and those
 * translated by machine and not reviewed yet.
 *
 * @param string $post_type Post type.
 * @param array  $args      See swiftling_comment_query().
 * @return array{total: int, translated: int, machine: int}
 */
function swiftling_comment_counts( $post_type, $args ) {
	global $wpdb;
	list( $sql, $values ) = swiftling_comment_query( $post_type, $args );
	$joined               = '' !== ( $args['locale'] ?? '' );
	$query                = 'SELECT COUNT(*) AS total, ' . ( $joined ? 'COUNT( s.source_hash )' : '0' ) . ' AS translated, ' . ( $joined ? 'SUM( s.status = ' . (int) SWIFTLING_MACHINE . ' )' : '0' ) . ' AS machine ' . $sql;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Counted for one screen; $query has one placeholder per value.
	$row = $wpdb->get_row( $wpdb->prepare( $query, $values ) );
	return array(
		'total'      => $row ? (int) $row->total : 0,
		'translated' => $row ? (int) $row->translated : 0,
		'machine'    => $row ? (int) $row->machine : 0,
	);
}

/**
 * Returns the kinds of content with comments visitors read, and how many each has.
 *
 * @return array<string, int> Post type => number of comments, in the order of the kinds of content.
 */
function swiftling_comment_kinds() {
	$kinds = array();
	foreach ( array_keys( swiftling_content_types() ) as $post_type ) {
		$total = swiftling_comment_counts( $post_type, array() )['total'];
		if ( $total ) {
			$kinds[ $post_type ] = $total;
		}
	}
	return $kinds;
}

/**
 * Returns how far each language has come with the comments of a kind of content, or of one page.
 *
 * @param string $post_type Post type.
 * @param int    $post_id   ID of the page, or 0 for all of the kind.
 * @return array<string, array{translated: int, machine: int, total: int}> Language slug => counts.
 */
function swiftling_comment_progress( $post_type, $post_id = 0 ) {
	$progress = array();
	foreach ( swiftling_languages()['targets'] as $slug => $language ) {
		$progress[ $slug ] = swiftling_comment_counts(
			$post_type,
			array(
				'for'    => $post_id,
				'locale' => $language['locale'],
			)
		);
		$progress[ $slug ] = array(
			'translated' => $progress[ $slug ]['translated'],
			'machine'    => $progress[ $slug ]['machine'],
			'total'      => $progress[ $slug ]['total'],
		);
	}
	return $progress;
}

/**
 * Returns one page of the comments of a kind of content for the editor: those without a translation
 * into the language, or all, the newest first, found by a search or not, of one page or all.
 *
 * @param string $post_type Post type.
 * @param string $lang      Address slug of the language.
 * @param array  $args      show (missing or all; missing while any is), search, for (post ID) and page.
 * @return array{comments: WP_Comment[], shown: int, counts: array{missing: int, all: int}, show: string, search: string, for: int, page: int, pages: int}
 */
function swiftling_comment_list( $post_type, $lang, $args ) {
	global $wpdb;
	$language = swiftling_target_language( $lang );
	$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
	$for      = isset( $args['for'] ) ? (int) $args['for'] : 0;
	$scope    = array(
		'for'    => $for,
		'locale' => $language ? $language['locale'] : '',
	);

	$all    = swiftling_comment_counts( $post_type, $scope );
	$counts = array(
		'missing' => $all['total'] - $all['translated'],
		'all'     => $all['total'],
	);
	$show   = isset( $args['show'] ) && isset( $counts[ $args['show'] ] ) ? (string) $args['show'] : '';
	if ( '' === $show ) {
		// Without a translation first, as long as any is without one.
		$show = $counts['missing'] ? 'missing' : 'all';
	}

	$picked = $scope + array(
		'search'  => $search,
		'missing' => 'missing' === $show,
	);
	$shown  = swiftling_comment_counts( $post_type, $picked )['total'];
	$pages  = max( 1, (int) ceil( $shown / SWIFTLING_COMMENTS_PER_PAGE ) );
	$page   = min( $pages, max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ) );

	list( $sql, $values ) = swiftling_comment_query( $post_type, $picked );
	$query                = 'SELECT c.comment_ID ' . $sql . ' ORDER BY c.comment_date_gmt DESC, c.comment_ID DESC LIMIT %d OFFSET %d';
	$values[]             = SWIFTLING_COMMENTS_PER_PAGE;
	$values[]             = ( $page - 1 ) * SWIFTLING_COMMENTS_PER_PAGE;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One page of the list; $query has one placeholder per value.
	$ids = $wpdb->get_col( $wpdb->prepare( $query, $values ) );

	return array(
		'comments' => swiftling_comments_by_id( $ids ),
		'shown'    => $shown,
		'counts'   => $counts,
		'show'     => $show,
		'search'   => $search,
		'for'      => $for,
		'page'     => $page,
		'pages'    => $pages,
	);
}

/**
 * Returns comments in the order of their IDs, read at once.
 *
 * @param array $ids Comment IDs.
 * @return WP_Comment[]
 */
function swiftling_comments_by_id( $ids ) {
	$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	if ( ! $ids ) {
		return array();
	}
	return get_comments(
		array(
			'comment__in' => $ids,
			'orderby'     => 'comment__in',
			'status'      => 'all',
			'type'        => '',
			'number'      => count( $ids ),
		)
	);
}

/**
 * Returns what the editor shows of comments: the text of each, under its author's name, with what
 * it answers, the page it was written on and when; a review as its plugin describes it, such as
 * with its stars, see Builder::fieldLabel().
 *
 * @param WP_Comment[] $comments Comments.
 * @return array<int, array> Texts, see Builder::listTexts().
 */
function swiftling_comment_texts( $comments ) {
	$texts = array();
	foreach ( $comments as $comment ) {
		if ( '' === trim( (string) $comment->comment_content ) ) {
			continue;
		}
		$owner = swiftling_comment_owner( $comment->comment_ID );
		$about = array();
		$own   = \SwiftLing\Builders\Registry::fieldLabel( $owner, SWIFTLING_COMMENT_PATH );
		if ( '' !== $own ) {
			$about[] = $own;
		}
		$parent = $comment->comment_parent ? get_comment( (int) $comment->comment_parent ) : null;
		if ( $parent instanceof WP_Comment ) {
			/* translators: %s: name of the author of the comment answered. */
			$about[] = sprintf( __( 'Reply to %s', 'swiftling' ), swiftling_comment_author( $parent ) );
		}
		$post = get_post( (int) $comment->comment_post_ID );
		if ( $post ) {
			/* translators: %s: title of the page the comment is written on. */
			$about[] = sprintf( __( 'on %s', 'swiftling' ), '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'swiftling' ) );
		}
		$about[] = mysql2date( (string) get_option( 'date_format' ), $comment->comment_date );

		$texts[] = array(
			'o'     => $owner,
			'p'     => SWIFTLING_COMMENT_PATH,
			'v'     => (string) $comment->comment_content,
			'r'     => swiftling_rule_key( 'comment:content', '', '' ),
			'kind'  => 'paragraph',
			'label' => swiftling_comment_author( $comment ),
			'place' => implode( ' · ', $about ),
		);
	}
	return $texts;
}

/**
 * Returns the name of a comment's author as written.
 *
 * @param WP_Comment $comment Comment.
 * @return string
 */
function swiftling_comment_author( $comment ) {
	$name = trim( (string) $comment->comment_author );
	return '' !== $name ? $name : __( 'Anonymous', 'swiftling' );
}

/**
 * Stores the texts of one page of a list of comments and returns everything the editor shows, as
 * swiftling_save_term_list() does for terms, with the list and how far each language has come with
 * the whole list.
 *
 * @param array  $subject Subject "comments:<post type>".
 * @param string $lang    Address slug of the language.
 * @param array  $args    See swiftling_comment_list().
 * @return array{sections: array, maps: array, units: array, list: array, progress: array}
 */
function swiftling_save_comment_list( $subject, $lang, $args ) {
	$post_type = substr( $subject['key'], 9 );
	$list      = swiftling_comment_list( $post_type, $lang, $args );
	return swiftling_save_listed_texts( $subject, swiftling_comment_texts( $list['comments'] ) ) + array(
		'list'     => $list,
		'progress' => swiftling_comment_progress( $post_type, $list['for'] ),
	);
}

/**
 * Returns the next batch of comments without a translation into a language, to translate every one
 * of a list, the newest first: their texts as a machine translation run sends them, see
 * swiftling_machine_items(). The batch is stored as the page of the editor is, so the routes
 * of the editor accept its texts.
 *
 * @param array  $subject Subject "comments:<post type>".
 * @param string $lang    Address slug of the language.
 * @param array  $args    after (ID of the last comment of the batch before, 0 to start), size,
 *                        for (post ID) and search.
 * @return array{items: array, after: int, left: int} The texts, the ID to go on after, and how many
 *                                                    comments without a translation come after.
 */
function swiftling_comment_batch( $subject, $lang, $args ) {
	global $wpdb;
	$post_type = substr( $subject['key'], 9 );
	$language  = swiftling_target_language( $lang );
	$size      = isset( $args['size'] ) ? max( 1, min( SWIFTLING_COMMENT_BATCH, (int) $args['size'] ) ) : SWIFTLING_COMMENT_BATCH;
	$picked    = array(
		'for'     => isset( $args['for'] ) ? (int) $args['for'] : 0,
		'search'  => isset( $args['search'] ) ? (string) $args['search'] : '',
		'locale'  => $language['locale'],
		'missing' => true,
		'before'  => isset( $args['after'] ) ? (int) $args['after'] : 0,
	);

	// By ID, the newest first, so the next batch goes on where this one ends.
	list( $sql, $values ) = swiftling_comment_query( $post_type, $picked );
	$query                = 'SELECT c.comment_ID ' . $sql . ' ORDER BY c.comment_ID DESC LIMIT %d';
	$values[]             = $size;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One batch; $query has one placeholder per value.
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $query, $values ) ) );
	if ( ! $ids ) {
		return array(
			'items' => array(),
			'after' => $picked['before'],
			'left'  => 0,
		);
	}

	$result = swiftling_save_listed_texts( $subject, swiftling_comment_texts( swiftling_comments_by_id( $ids ) ) );
	$after  = min( $ids );
	return array(
		'items' => swiftling_machine_items( $subject, $lang, $result ),
		'after' => $after,
		'left'  => swiftling_comment_counts( $post_type, array( 'before' => $after ) + $picked )['total'],
	);
}

/**
 * Registers the route that reads the next batch of comments without a translation.
 */
function swiftling_register_comment_routes() {
	register_rest_route(
		'swiftling/v1',
		'/comment-batch',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_comment_batch',
			'permission_callback' => 'swiftling_rest_can_edit',
			'args'                => array(
				'subject' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static function ( $key ) {
						return 0 === strpos( (string) $key, 'comments:' );
					},
				),
				'lang'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static function ( $lang ) {
						return null !== swiftling_target_language( sanitize_key( $lang ) );
					},
				),
				'after'   => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'for'     => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'find'    => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'swiftling_register_comment_routes' );

/**
 * Returns the next batch of comments without a translation, see swiftling_comment_batch().
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function swiftling_rest_comment_batch( $request ) {
	return swiftling_comment_batch(
		swiftling_subject( (string) $request['subject'] ),
		(string) $request['lang'],
		array(
			'after'  => (int) $request['after'],
			'for'    => (int) $request['for'],
			'search' => (string) $request['find'],
		)
	);
}

/**
 * Shows a comment's text in the language of the page: its translation, when it has one of the text
 * as it is now. Only the text as stored is translated, as get_comment_text() hands it over; what
 * other plugins made of it is left alone.
 *
 * @param mixed $text    The comment's text.
 * @param mixed $comment The comment.
 * @return mixed
 */
function swiftling_filter_comment_text( $text, $comment = null ) {
	if ( 'translate' !== swiftling_mode() || ! is_string( $text ) || ! $comment instanceof WP_Comment || $text !== $comment->comment_content || ! swiftling_is_translated_comment_type( $comment ) ) {
		return $text;
	}
	return swiftling_transform_text( swiftling_comment_owner( $comment->comment_ID ), SWIFTLING_COMMENT_PATH, 'comment:content', $text );
}
add_filter( 'get_comment_text', 'swiftling_filter_comment_text', 5, 2 );

/**
 * Reads the translations of the comments a page shows at once, when they are read, so a page with
 * many comments does not ask the database once for each of them.
 *
 * @param mixed $comments Comments a query found.
 * @return mixed The same comments.
 */
function swiftling_preload_comments( $comments ) {
	if ( 'translate' !== swiftling_mode() || ! is_array( $comments ) ) {
		return $comments;
	}
	$owners = array();
	foreach ( $comments as $comment ) {
		if ( $comment instanceof WP_Comment && swiftling_is_translated_comment_type( $comment ) ) {
			$owners[] = swiftling_comment_owner( $comment->comment_ID );
		}
	}
	swiftling_preload_objects( $owners );
	return $comments;
}
add_filter( 'the_comments', 'swiftling_preload_comments' );
