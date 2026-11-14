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
 * Which of the two Translate opens for a page it can lay out is the one the user last chose with
 * Table view or Visual view, the links each editor has to the other; until they choose, the one of
 * the General settings, see swiftling_editor_choice().
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * User meta: the editor the user last chose with Table view or Visual view, table or visual.
 */
define( 'SWIFTLING_EDITOR_CHOICE', 'swiftling_editor' );

/**
 * Returns which editor Translate opens for a user, for a page the visual editor lays out: the one
 * they last chose with Table view or Visual view, or else the one of the General settings.
 *
 * @param int $user_id User ID; the current user by default.
 * @return string table or visual.
 */
function swiftling_editor_choice( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$chosen  = $user_id ? get_user_meta( $user_id, SWIFTLING_EDITOR_CHOICE, true ) : '';
	return in_array( $chosen, array( 'table', 'visual' ), true ) ? $chosen : swiftling_settings()['editor'];
}

/**
 * Remembers the editor the current user chose with Table view or Visual view, whose links name it
 * (editor=table, editor=visual), so Translate opens it from now on. It only changes how the editor
 * opens for that user, so a link is enough, for whoever may translate.
 */
function swiftling_remember_editor() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which editor opens for this user.
	$editor = isset( $_GET['editor'] ) ? sanitize_key( wp_unslash( $_GET['editor'] ) ) : '';
	if ( in_array( $editor, array( 'table', 'visual' ), true ) && current_user_can( SWIFTLING_TRANSLATE ) ) {
		update_user_meta( get_current_user_id(), SWIFTLING_EDITOR_CHOICE, $editor );
	}
}

/**
 * Whether the visual editor can lay a post out and translate it into a language: made of blocks
 * only, with no classic block and no HTML between the blocks or loose in a block of the layout,
 * built with no page builder SwiftLing knows (the translation editor knows the builder's fields),
 * and the language shows the post translated here, not as a separate copy or the original kept.
 * A page of a builder SwiftLing does not tell by its class is told by its data, see
 * swiftling_visual_builder().
 *
 * @param WP_Post|null $post Post.
 * @param string       $lang Address slug of a target language; empty for the post alone.
 * @return true|WP_Error
 */
function swiftling_visual_editor_supports( $post, $lang = '' ) {
	if ( ! $post instanceof WP_Post || ! swiftling_subject( 'post:' . $post->ID ) ) {
		return new WP_Error( 'swiftling_visual_subject', __( 'Open this screen with Translate, from Translations or the list of pages.', 'swiftling' ) );
	}
	$builders = array_map(
		static function ( $builder ) {
			return $builder->name();
		},
		array_values( \SwiftLing\Builders\Registry::forPost( (int) $post->ID ) )
	);
	$builder  = $builders ? wp_sprintf( '%l', $builders ) : swiftling_visual_builder( $post );
	if ( '' !== $builder ) {
		/* translators: %s: name of a page builder, e.g. Beaver Builder. */
		return new WP_Error( 'swiftling_visual_builder', sprintf( __( 'This page is built with %s. Translate it in the translation editor, which knows the builder\'s fields.', 'swiftling' ), $builder ) );
	}
	if ( ! has_blocks( $post->post_content ) ) {
		return new WP_Error( 'swiftling_visual_classic', __( 'This page is not made of blocks. Translate it in the translation editor.', 'swiftling' ) );
	}
	if ( swiftling_visual_loose_html( parse_blocks( $post->post_content ) ) ) {
		return new WP_Error( 'swiftling_visual_loose', __( 'This page holds text outside its blocks, or a classic block. Translate it in the translation editor.', 'swiftling' ) );
	}
	// A block of a plugin shows as it is saved, its texts beside the page, see assets/js/visual.js.
	$language = '' === $lang ? null : swiftling_target_language( $lang );
	if ( $language && 'translation' !== swiftling_language_mode( (int) $post->ID, $language['locale'] ) ) {
		return new WP_Error( 'swiftling_visual_mode', __( 'This language shows a separate copy of this page or keeps the original. See the translation editor.', 'swiftling' ) );
	}
	return true;
}

/**
 * Returns the name of the page builder that built a post, where SwiftLing has no class that tells,
 * or ''. Breakdance, Oxygen, Brizy, Beaver Builder and SiteOrigin Page Builder keep the page in its
 * meta, and can leave blocks in its content from before; SiteOrigin can also keep a whole layout in
 * one block.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function swiftling_visual_builder( $post ) {
	$meta = array(
		'_breakdance_data'      => 'Breakdance',
		'_oxygen_data'          => 'Oxygen',
		'ct_builder_shortcodes' => 'Oxygen',
		'ct_builder_json'       => 'Oxygen',
		'brizy'                 => 'Brizy',
		'_fl_builder_enabled'   => 'Beaver Builder',
		'panels_data'           => 'SiteOrigin Page Builder',
	);
	foreach ( $meta as $key => $builder ) {
		if ( ! empty( get_post_meta( $post->ID, $key, true ) ) ) {
			return $builder;
		}
	}
	// SiteOrigin can keep a layout in a block of its own; one without a layout, as the empty block
	// Beaver Builder adds, is no builder's page.
	foreach ( parse_blocks( $post->post_content ) as $block ) {
		if ( 'siteorigin-panels/layout-block' === $block['blockName'] && ! empty( $block['attrs']['panelsData'] ) ) {
			return 'SiteOrigin Page Builder';
		}
	}
	return '';
}

/**
 * Whether a tree of blocks holds content that is no block of its own, which the visual editor
 * cannot lay out: a classic block, HTML between the blocks, or text in a block of the layout, as a
 * group or a column, outside the blocks inside it.
 *
 * @param array $blocks Parsed blocks.
 * @return bool
 */
function swiftling_visual_loose_html( $blocks ) {
	$layout = array( 'core/group', 'core/columns', 'core/column', 'core/buttons', 'core/social-links', 'core/row', 'core/stack' );
	foreach ( $blocks as $block ) {
		if ( 'core/freeform' === $block['blockName'] || ( null === $block['blockName'] && '' !== trim( (string) $block['innerHTML'] ) ) ) {
			return true;
		}
		if ( in_array( $block['blockName'], $layout, true ) ) {
			foreach ( $block['innerContent'] as $part ) {
				if ( is_string( $part ) && '' !== preg_replace( '/[\s\x{00A0}]+/u', '', html_entity_decode( wp_strip_all_tags( $part ), ENT_QUOTES, 'UTF-8' ) ) ) {
					return true;
				}
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && swiftling_visual_loose_html( $block['innerBlocks'] ) ) {
			return true;
		}
	}
	return false;
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
 * Whether the visual editor of the request can lay its post out, so its screen loads the block
 * editor; one it cannot lay out says so and leads to the translation editor.
 *
 * @return bool
 */
function swiftling_visual_editor_requested() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
	$post = isset( $_GET['post'] ) ? get_post( absint( $_GET['post'] ) ) : null;
	$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
	// phpcs:enable
	if ( ! swiftling_target_language( $lang ) ) {
		$lang = (string) array_key_first( swiftling_languages()['targets'] );
	}
	return '' !== $lang && true === swiftling_visual_editor_supports( $post, $lang );
}

/**
 * Renders the blocks of plugins of a post and of its synced patterns as its page renders them, for
 * the visual editor, which shows them as they are: their HTML by the object and the place of the
 * block ("post:12|0/3"), and the styles rendering them asks for, as the page would load them: the
 * stylesheets plugins enqueue, with their inline CSS, and the CSS of block supports. What this
 * enqueues for the page is taken out of the admin's queues again.
 *
 * @param WP_Post $post     Post.
 * @param array   $patterns Synced patterns it shows, see swiftling_visual_patterns().
 * @return array{html: array<string, string>, styles: string}
 */
function swiftling_visual_plugin_blocks( $post, $patterns ) {
	$found   = array();
	$collect = static function ( $blocks, $owner, $path ) use ( &$collect, &$found ) {
		foreach ( $blocks as $index => $block ) {
			$here = '' === $path ? (string) $index : $path . '/' . $index;
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			if ( 0 !== strpos( $block['blockName'], 'core/' ) ) {
				$found[ $owner . '|' . $here ] = $block;
				continue;
			}
			$collect( $block['innerBlocks'], $owner, $here );
		}
	};
	$collect( parse_blocks( $post->post_content ), 'post:' . $post->ID, '' );
	foreach ( $patterns as $ref => $pattern ) {
		$collect( parse_blocks( $pattern['content'] ), 'post:' . $ref, '' );
	}
	$rendered = array(
		'html'   => array(),
		'styles' => '',
	);
	if ( ! $found ) {
		return $rendered;
	}

	$styles          = wp_styles();
	$scripts         = wp_scripts();
	$before          = array( $styles->queue, $styles->done, $scripts->queue );
	$current         = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As the page renders its blocks; put back below.
	setup_postdata( $post );
	$handles = array();
	$owners  = array();
	foreach ( $found as $place => $block ) {
		$rendered['html'][ $place ] = render_block( $block );
		$type                       = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );
		$handles                    = array_merge( $handles, $type ? $type->style_handles : array() );
		// Who the block is of: its namespace, and the prefixes of the handles of its styles and
		// scripts, as "kadence", "uagb" or "ugb".
		$names = $type ? array_merge( $type->style_handles, $type->editor_style_handles, $type->view_style_handles, $type->script_handles, $type->editor_script_handles, $type->view_script_handles ) : array();
		foreach ( array_merge( array( strtok( $block['blockName'], '/' ) ), $names ) as $name ) {
			$owners[] = (string) preg_replace( '~[^a-z0-9].*$~is', '', (string) $name );
		}
	}
	$handles = array_filter(
		array_unique( array_merge( $handles, array_diff( $styles->queue, $before[0] ) ) ),
		static function ( $handle ) {
			return 0 !== strpos( $handle, 'swiftling' );
		}
	);
	ob_start();
	$styles->do_items( $handles );
	$css                = wp_style_engine_get_stylesheet_from_context( 'block-supports', array( 'optimize' => true ) );
	$rendered['styles'] = ob_get_clean() . ( '' !== $css ? '<style>' . $css . '</style>' : '' );
	list( $styles->queue, $styles->done, $scripts->queue ) = $before;
	// Many plugins write the styles of a page's blocks only as the page loads, into files of their
	// own or the page's head: those of the page as visitors see it.
	$rendered['styles'] .= swiftling_visual_page_styles( $post, $owners );
	$GLOBALS['post']     = $current; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Put back as it was.
	if ( $current instanceof WP_Post ) {
		setup_postdata( $current );
	}
	return $rendered;
}

/**
 * Returns the stylesheets and the styles in the head of a post's page as visitors see it, as the
 * server checks pages (see swiftling_server_check()), that the visual editor needs to show the
 * blocks of plugins as the page does: those of the plugins whose blocks it shows, by the IDs the
 * page gives them, which start with the plugin's prefix, as "kadence-blocks-accordion-css" or
 * "uagb-style-frontend-12". Not WordPress's own, which the editor has, nor the theme's or another
 * plugin's, which could restyle the whole editor.
 *
 * The page is the one the editor's page check read (see swiftling_checked_page()); only without it
 * is the site asked again, briefly: a page that is slow to come shows the blocks with less style.
 *
 * @param WP_Post  $post   Post.
 * @param string[] $owners The prefixes of the plugins, as "kadence" or "uagb".
 * @return string The link and style elements; '' when the page could not be read.
 */
function swiftling_visual_page_styles( $post, $owners ) {
	$subject = swiftling_subject( 'post:' . $post->ID );
	$owners  = array_diff( array_unique( array_map( 'strtolower', $owners ) ), array( 'wp', 'core', 'global', 'admin', 'block', 'blocks', 'style', 'swiftling' ) );
	if ( ! $subject || '' === (string) $subject['url'] || ! $owners ) {
		return '';
	}
	$page = swiftling_checked_page( $subject['key'], get_current_user_id() );
	if ( null === $page ) {
		$page = swiftling_fetch_check_page( swiftling_check_page_url( $subject, 'control', get_current_user_id() ), 200, 10 );
	}
	if ( is_wp_error( $page ) ) {
		return '';
	}
	preg_match_all( '~<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>|<style\b[^>]*>.*?</style>~is', $page, $found );
	$styles = '';
	foreach ( $found[0] as $element ) {
		$tag = substr( $element, 0, (int) strpos( $element, '>' ) );
		if ( ! preg_match( '~\sid=["\']([a-z0-9]+)~i', $tag, $id ) || strlen( $id[1] ) < 3 ) {
			continue;
		}
		$prefix = strtolower( $id[1] );
		$keep   = false;
		foreach ( $owners as $owner ) {
			// "uag-style-12-css" is Spectra's as "uagb/" blocks are.
			$keep = $keep || ( strlen( $owner ) >= 3 && ( 0 === strpos( $owner, $prefix ) || 0 === strpos( $prefix, $owner ) ) );
		}
		if ( $keep && 0 === stripos( $element, '<link' ) ) {
			$keep = 1 === preg_match( '~href=["\'][^"\']*/wp-content/(?:plugins|uploads)/~i', $tag ) && false === stripos( $tag, '/plugins/swiftling/' );
		}
		$styles .= $keep ? $element : '';
	}
	return $styles;
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
 * Returns the address of the visual editor of a post in a language that the user chose, with
 * Visual view: Translate opens it from now on, see swiftling_remember_editor().
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Address slug of a target language.
 * @return string
 */
function swiftling_visual_view_url( $post_id, $lang ) {
	return add_query_arg( 'editor', 'visual', swiftling_visual_editor_url( $post_id, $lang ) );
}

/**
 * Returns the address of the translation editor of a post in a language that the user chose, with
 * Table view: it opens also where the visual editor would, and Translate opens it from now on, see
 * swiftling_remember_editor() and swiftling_visual_editor_redirect().
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
 * user chose it, or the settings do (see swiftling_editor_choice()), and it can lay the post out in
 * the language. Every link to the translation editor, from the Translations screen, the list of
 * pages, the block editor or the toolbar, is swiftling_editor_url(), so they all lead there; Table
 * view, which names the editor (editor=table), stays. A post the visual editor cannot lay out
 * stays too, and the user's choice with it.
 *
 * @return string The address, or '' to stay.
 */
function swiftling_visual_editor_redirect() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
	if ( empty( $_GET['post'] ) || isset( $_GET['editor'] ) || 'visual' !== swiftling_editor_choice() ) {
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
			$supported->add_data( array( 'url' => swiftling_editor_url( 'post:' . $post->ID, $lang ) ) );
		}
		return $supported;
	}
	$key   = 'post:' . $post->ID;
	$texts = swiftling_subject_texts( $key, $lang );
	if ( is_wp_error( $texts ) ) {
		$texts->add_data( array( 'url' => swiftling_editor_url( 'post:' . $post->ID, $lang ) ) );
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
			'url'    => 'translation' === $mode ? swiftling_visual_editor_url( $post->ID, (string) $slug ) : swiftling_editor_url( 'post:' . $post->ID, (string) $slug ),
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

	// Each text named as the translation editor names its row: its kind, and the field it is in.
	$labels = \SwiftLing\Admin\Ui::partLabels();
	foreach ( $texts['texts'] as &$text ) {
		$place = \SwiftLing\Builders\Registry::fieldLabel( $text['object'], $text['path'] );
		if ( '' === $place && 'field/post_excerpt' === $text['path'] ) {
			$place = __( 'Excerpt', 'swiftling' );
		}
		$text['label'] = $labels[ isset( $labels[ $text['kind'] ] ) ? $text['kind'] : 'text' ];
		// Code is named as in the translation editor: a table, a quote, an image with a caption.
		$text['codeLabel'] = swiftling_live_code_label( $text['original'] );
		// HTML is edited as the translation editor edits it: its text with simple formatting, the
		// tags around it kept as they are, or else as code.
		if ( 'html' === $text['type'] ) {
			$parts = swiftling_rich_parts( $text['original'] );
			if ( $parts ) {
				$text['parts'] = array( $parts[0], $parts[1], $parts[2] );
			} else {
				$text['type']  = 'code';
				$text['label'] = $text['codeLabel'];
			}
		} elseif ( 'code' === $text['type'] ) {
			$text['label'] = $text['codeLabel'];
		}
		// The page's own title, wherever its page check found it.
		if ( $key === $text['object'] && 'field/post_title' === $text['path'] ) {
			$text['label'] = __( 'Title', 'swiftling' );
		}
		$text['place'] = $place;
	}
	unset( $text );

	// The links of the screen, each only for whoever may open it.
	$links = array_filter(
		array(
			'back'    => admin_url( 'admin.php?page=swiftling-content' ),
			'table'   => swiftling_table_editor_url( $post->ID, $lang ),
			'service' => admin_url( 'admin.php?page=swiftling-service' ),
			'request' => admin_url( 'admin.php?page=swiftling-request' ),
		),
		array( \SwiftLing\Admin\Ui::class, 'opens' )
	);

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
		// The blocks of plugins as the page renders them, and the styles that asks for.
		'plugins'  => swiftling_visual_plugin_blocks( $post, swiftling_visual_patterns( $post->post_content ) ),
		'lang'     => $lang,
		'language' => $language,
		// The language and direction of the page in the language, as the canvas shows it.
		'target'   => array(
			'lang' => swiftling_hreflang_code( (string) $language['locale'] ),
			'dir'  => swiftling_language_is_rtl( $language ) ? 'rtl' : 'ltr',
		),
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
			'url'   => swiftling_can_translate_subject( $frame ) ? swiftling_editor_url( $frame['key'], $lang ) : '',
		) : null,
		'links'    => (object) $links,
		'settings' => get_block_editor_settings( array(), new WP_Block_Editor_Context( array( 'name' => 'swiftling/visual' ) ) ),
	);
}
