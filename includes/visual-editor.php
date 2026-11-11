<?php
/**
 * The visual editor: a page made of blocks laid out as its blocks, translated in place, see
 * admin/views/visual.php and assets/js/visual.js. Texts not translated yet show the original,
 * faded; the original of the chosen block shows beside the page; an image can be another one in a
 * language, with its own alt text.
 *
 * It translates the texts the translation editor lists, under the same keys, see
 * swiftling_subject_texts(), and saves them with the editor's own routes (translations, slug, and
 * fill for machine translation, see includes/rest.php), so storage, states, caches and the page in
 * the language are the translation editor's. A page it cannot lay out, such as one built with a
 * page builder, and a language that shows a separate copy or keeps the original stay with the
 * translation editor.
 *
 * Which of the two Translate opens for a page it can lay out is a choice of the General settings,
 * see swiftling_settings(); each editor has a link to the other.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the visual editor can lay a post out and translate it into a language: made of blocks
 * only, with no block this site does not know, built with no page builder SwiftLing knows (the
 * translation editor knows the builder's fields), and the language shows the post translated here,
 * not as a separate copy or the original kept.
 *
 * @param WP_Post|null $post Post.
 * @param string       $lang Address slug of a target language; empty for the post alone.
 * @return true|WP_Error
 */
function swiftling_visual_editor_supports( $post, $lang = '' ) {
	if ( ! $post instanceof WP_Post || ! swiftling_subject( 'post:' . $post->ID ) ) {
		return new WP_Error( 'swiftling_visual_subject', __( 'Open this screen with Translate, from Translations or the list of pages.', 'swiftling' ) );
	}
	if ( \SwiftLing\Builders\Registry::forPost( (int) $post->ID ) ) {
		return new WP_Error( 'swiftling_visual_builder', __( 'This page is built with a page builder. Translate it in the translation editor, which knows the builder\'s fields.', 'swiftling' ) );
	}
	if ( ! has_blocks( $post->post_content ) ) {
		return new WP_Error( 'swiftling_visual_classic', __( 'This page is not made of blocks. Translate it in the translation editor.', 'swiftling' ) );
	}
	$registry = WP_Block_Type_Registry::get_instance();
	foreach ( swiftling_visual_block_names( parse_blocks( $post->post_content ) ) as $name ) {
		// The core blocks that only the block editor's scripts register are known all the same.
		if ( ! $registry->is_registered( $name ) && 0 !== strpos( $name, 'core/' ) ) {
			/* translators: %s: name of a block, e.g. acme/slider. */
			return new WP_Error( 'swiftling_visual_block', sprintf( __( 'This page holds a block this site does not know (%s). Translate it in the translation editor.', 'swiftling' ), $name ) );
		}
	}
	$language = '' === $lang ? null : swiftling_target_language( $lang );
	if ( $language && 'translation' !== swiftling_language_mode( (int) $post->ID, $language['locale'] ) ) {
		return new WP_Error( 'swiftling_visual_mode', __( 'This language shows a separate copy of this page or keeps the original. See the translation editor.', 'swiftling' ) );
	}
	return true;
}

/**
 * Returns the names of the blocks of a tree, those inside others too.
 *
 * @param array $blocks Parsed blocks.
 * @return string[]
 */
function swiftling_visual_block_names( $blocks ) {
	$names = array();
	foreach ( $blocks as $block ) {
		if ( ! empty( $block['blockName'] ) ) {
			$names[] = $block['blockName'];
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$names = array_merge( $names, swiftling_visual_block_names( $block['innerBlocks'] ) );
		}
	}
	return array_values( array_unique( $names ) );
}

/**
 * Returns the synced patterns a post shows, and those they show in turn: the visual editor lays
 * them out in place. Their texts belong to the pattern, so they are translated once for every
 * page that shows it.
 *
 * @param string $content Content of a post.
 * @param array  $found   Patterns found so far, by ID.
 * @return array<int, array{title: string, content: string}>
 */
function swiftling_visual_patterns( $content, $found = array() ) {
	foreach ( swiftling_visual_pattern_refs( parse_blocks( (string) $content ) ) as $ref ) {
		if ( isset( $found[ $ref ] ) ) {
			continue;
		}
		$pattern = get_post( $ref );
		if ( ! $pattern || 'wp_block' !== $pattern->post_type ) {
			continue;
		}
		$found[ $ref ] = array(
			'title'   => $pattern->post_title,
			'content' => $pattern->post_content,
		);
		$found         = swiftling_visual_patterns( $pattern->post_content, $found );
	}
	return $found;
}

/**
 * Returns the IDs of the synced patterns in a tree of blocks.
 *
 * @param array $blocks Parsed blocks.
 * @return int[]
 */
function swiftling_visual_pattern_refs( $blocks ) {
	$refs = array();
	foreach ( $blocks as $block ) {
		if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
			$refs[] = (int) $block['attrs']['ref'];
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$refs = array_merge( $refs, swiftling_visual_pattern_refs( $block['innerBlocks'] ) );
		}
	}
	return array_values( array_unique( $refs ) );
}

/**
 * Returns the address of the visual editor of a post in a language.
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Address slug of a target language.
 * @return string
 */
function swiftling_visual_editor_url( $post_id, $lang ) {
	return add_query_arg(
		array(
			'page' => 'swiftling-visual',
			'post' => (int) $post_id,
			'lang' => (string) $lang,
		),
		admin_url( 'admin.php' )
	);
}

/**
 * Returns the address of the translation editor of a post in a language that opens it also where
 * the visual editor would open instead, see swiftling_visual_editor_redirect().
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Address slug of a target language.
 * @return string
 */
function swiftling_table_editor_url( $post_id, $lang ) {
	return add_query_arg( 'editor', 'table', swiftling_editor_url( 'post:' . (int) $post_id, $lang ) );
}

/**
 * Returns where the translation editor of the request leads instead: the visual editor, when the
 * settings choose it and it can lay the post out in the language. Every link to the translation
 * editor, from the Translations screen, the list of pages, the block editor or the toolbar, is
 * swiftling_editor_url(), so they all lead there; a link with editor=table, as the visual editor's
 * own, stays.
 *
 * @return string The address, or '' to stay.
 */
function swiftling_visual_editor_redirect() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
	if ( 'visual' !== swiftling_settings()['editor'] || empty( $_GET['post'] ) || isset( $_GET['editor'] ) ) {
		return '';
	}
	$post = get_post( absint( $_GET['post'] ) );
	$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
	// phpcs:enable
	if ( ! swiftling_target_language( $lang ) ) {
		$lang = (string) array_key_first( swiftling_languages()['targets'] );
	}
	if ( '' === $lang || true !== swiftling_visual_editor_supports( $post, $lang ) ) {
		return '';
	}
	return swiftling_visual_editor_url( $post->ID, $lang );
}

/**
 * Returns what the visual editor shows of a post in a language: the post's blocks and synced
 * patterns, its texts with their translations as swiftling_subject_texts() lists them (its page
 * checked on the server first), the languages with their progress, the address, how the language
 * shows the post, machine translation, and the block editor's settings for this theme.
 *
 * @param WP_Post|null $post Post.
 * @param string       $lang Address slug of a target language.
 * @return array|WP_Error Why not, with the address of the translation editor (url) where it can.
 */
function swiftling_visual_editor_data( $post, $lang ) {
	$supported = swiftling_visual_editor_supports( $post, $lang );
	if ( is_wp_error( $supported ) ) {
		if ( $post instanceof WP_Post ) {
			$supported->add_data( array( 'url' => swiftling_table_editor_url( $post->ID, $lang ) ) );
		}
		return $supported;
	}
	$key   = 'post:' . $post->ID;
	$texts = swiftling_subject_texts( $key, $lang );
	if ( is_wp_error( $texts ) ) {
		$texts->add_data( array( 'url' => swiftling_table_editor_url( $post->ID, $lang ) ) );
		return $texts;
	}
	$languages = swiftling_languages();
	$language  = $languages['targets'][ $lang ];
	$subject   = swiftling_subject( $key );

	// The languages, each with its progress, and where it is translated: here, or in the
	// translation editor for a separate copy or the original kept.
	$counts  = swiftling_subject_counts( $key );
	$states  = swiftling_subject_states( $key );
	$targets = array();
	foreach ( $languages['targets'] as $slug => $target ) {
		$mode      = swiftling_language_mode( $post->ID, $target['locale'] );
		$targets[] = array(
			'slug'   => (string) $slug,
			'code'   => $target['code'],
			'name'   => $target['name'],
			'state'  => $states[ $slug ] ?? 'missing',
			'mode'   => $mode,
			'counts' => 'translation' === $mode ? ( $counts[ $slug ] ?? null ) : null,
			'url'    => 'translation' === $mode ? swiftling_visual_editor_url( $post->ID, (string) $slug ) : swiftling_table_editor_url( $post->ID, (string) $slug ),
		);
	}

	// Machine translation with the service that translates the post into the language, as the
	// translation editor offers it.
	$machine = null;
	$service = swiftling_can_machine_translate() ? \SwiftLing\Translation\Services::forTarget( $key, $language ) : null;
	if ( $service ) {
		$off = '';
		foreach ( array( $languages['source'], $language ) as $needed ) {
			if ( '' === $off && null === $service->languageCode( $needed ) ) {
				/* translators: 1: service name, e.g. Google, 2: language name, e.g. Montenegrin. */
				$off = sprintf( __( '%1$s does not translate %2$s, so machine translation is off for this language.', 'swiftling' ), $service->name(), $needed['name'] );
			}
		}
		$machine = array(
			'service' => $service->name(),
			'off'     => $off,
		);
	}

	$address = swiftling_post_address( $post, $lang );
	$frame   = swiftling_post_view_subject( $post );
	$view_in = 'publish' === $post->post_status ? swiftling_post_language_url( $post, $lang ) : swiftling_language_url( (string) $subject['url'], $lang );

	return array(
		'post'     => array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'type'    => (string) $subject['type'],
			'url'     => (string) $subject['url'],
			'viewIn'  => $view_in,
		),
		'subject'  => $texts['subject'],
		'texts'    => $texts['texts'],
		'patterns' => (object) swiftling_visual_patterns( $post->post_content ),
		'lang'     => $lang,
		'language' => $language,
		'source'   => $languages['source'],
		'targets'  => $targets,
		'labels'   => \SwiftLing\Admin\Ui::statusLabels(),
		'address'  => $address ? array(
			'slug'        => rawurldecode( (string) $address['slug'] ),
			'original'    => rawurldecode( (string) $address['original'] ),
			'prefix'      => (string) $address['prefix'],
			'url'         => (string) $address['url'],
			'state'       => (string) $address['state'],
			'directories' => swiftling_directories_supported(),
		) : null,
		'mode'     => array(
			'canChoose' => swiftling_can_choose_mode( $post ),
			'canCopy'   => swiftling_can_make_copy( $post ),
			'action'    => admin_url( 'admin-post.php' ),
			'nonce'     => wp_create_nonce( 'swiftling_mode_' . $post->ID ),
		),
		'machine'  => $machine,
		'frame'    => $frame ? array(
			'title' => (string) $frame['title'],
			'url'   => swiftling_editor_url( $frame['key'], $lang ),
		) : null,
		'links'    => array(
			'back'    => admin_url( 'admin.php?page=swiftling-content' ),
			'table'   => swiftling_table_editor_url( $post->ID, $lang ),
			'service' => admin_url( 'admin.php?page=swiftling-service' ),
			'request' => admin_url( 'admin.php?page=swiftling-request' ),
		),
		'settings' => get_block_editor_settings( array(), new WP_Block_Editor_Context( array( 'name' => 'swiftling/visual' ) ) ),
	);
}
