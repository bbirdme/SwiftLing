<?php
/**
 * What the translation editor can be opened for.
 *
 * A subject is one thing a person translates in one go, named by the key of the map that lists its
 * texts:
 *
 * - a post or page ("post:12"): its own texts, and shared texts shown inside its content;
 * - a template of a block theme ("template:twentytwentyfive//archive"): everything a page made
 *   from the template shows around the content of its posts, such as the header, the footer, the
 *   menu, the site title, headings of archives and texts of the theme;
 * - a kind of page of a classic theme ("view:search"): the same, for themes without block templates;
 * - the terms of a taxonomy ("terms:category"): the name, description and fields of every
 *   category, see includes/terms.php. Its page check is of the page of its most used term, and only
 *   finds out which fields of terms show as text;
 * - a classic menu ("menu:3"): the labels and tooltips of its items, the titles of the pages and the
 *   names of the categories they show, see includes/menus.php. It has no page check;
 * - texts a builder keeps outside posts, such as an options page of Advanced Custom Fields
 *   ("acf-options:theme-settings"), see SwiftLing\Builders\Builder::subject();
 * - one form of a form plugin ("cf7-form:12"): the labels of its fields, its choices, its button
 *   and the messages a visitor reads after sending, see SwiftLing\Forms\Provider. It has no page
 *   check: a form shows inside other pages, and the Forms screen lists them;
 * - the texts of a theme or plugin: those its code wrote on pages ("strings:plugin/woocommerce"),
 *   the values of its settings the user chose ("settings:theme/divi") and every text in its code
 *   ("code:plugin/woocommerce"), see SwiftLing\Packages\Texts.
 *
 * A page the user opens while recording is a subject too ("record:/shop/"): only its page check
 * uses it, to find the texts of themes and plugins on it, see includes/packages.php.
 *
 * A template has no address of its own, so the editor checks it on an example page made from it:
 * the newest post for a template of single posts, a category with posts for an archive, a search,
 * an address that does not exist for the 404 page. Which template WordPress picks for an example
 * page is worked out with WordPress's own resolve_block_template(), from the same list of
 * candidate templates WordPress tries for that kind of page.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the address of the translation editor for a post or another subject. Here, with the
 * subjects, because the site links to the editor too, from the toolbar.
 *
 * @param int|string $subject Post ID, or the key of a subject such as "template:twentytwentyfive//404", see swiftling_subject().
 * @param string     $lang    Optional language slug.
 * @return string
 */
function swiftling_editor_url( $subject, $lang = '' ) {
	$args = array( 'page' => 'swiftling-translation' );
	if ( is_int( $subject ) || ctype_digit( (string) $subject ) ) {
		$args['post'] = (int) $subject;
	} elseif ( 0 === strpos( (string) $subject, 'post:' ) ) {
		$args['post'] = (int) substr( (string) $subject, 5 );
	} else {
		$args['subject'] = (string) $subject;
	}
	if ( '' !== $lang ) {
		$args['lang'] = $lang;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Returns a subject of the translation editor.
 *
 * @param string $key Subject key, e.g. "post:12", "template:twentytwentyfive//404", "view:search", "terms:category" or "menu:3".
 * @return array{key: string, kind: string, title: string, type: string, url: string, probe: bool, post: WP_Post|null}|null
 *         Null when the key names nothing that can be translated.
 */
function swiftling_subject( $key ) {
	$key = (string) $key;

	if ( 0 === strpos( $key, 'post:' ) ) {
		$post = get_post( (int) substr( $key, 5 ) );
		// A separate copy is edited in its own editor; it is the translation of its original. A type
		// set to Never on the Settings screen is not translated at all.
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) || 'attachment' === $post->post_type || swiftling_copy_original_id( $post ) || swiftling_is_never_translated( $post->post_type ) ) {
			return null;
		}
		$type = get_post_type_object( $post->post_type );
		return array(
			'key'   => 'post:' . $post->ID,
			'kind'  => 'post',
			'title' => '' === $post->post_title ? __( 'No title', 'swiftling' ) : $post->post_title,
			'type'  => $type ? $type->labels->singular_name : $post->post_type,
			'url'   => 'publish' === $post->post_status ? (string) get_permalink( $post ) : (string) get_preview_post_link( $post ),
			'probe' => true,
			'post'  => $post,
		);
	}

	if ( 0 === strpos( $key, 'template:' ) || 0 === strpos( $key, 'view:' ) ) {
		foreach ( swiftling_site_views() as $view ) {
			if ( $view['key'] === $key ) {
				return array(
					'key'   => $key,
					'kind'  => 0 === strpos( $key, 'template:' ) ? 'template' : 'view',
					'title' => $view['title'],
					'type'  => 0 === strpos( $key, 'template:' ) ? __( 'Template', 'swiftling' ) : __( 'Kind of page', 'swiftling' ),
					'url'   => $view['url'],
					'probe' => '' !== $view['url'],
					'post'  => null,
				);
			}
		}
		return null;
	}

	if ( 0 === strpos( $key, 'terms:' ) ) {
		$lists = swiftling_term_taxonomies();
		$name  = substr( $key, 6 );
		if ( ! isset( $lists[ $name ] ) ) {
			return null;
		}
		$taxonomy = $lists[ $name ];
		// The page of the list's most used term, where the fields of terms show, see includes/terms.php.
		$url = swiftling_term_check_url( $taxonomy );
		return array(
			'key'   => 'terms:' . $taxonomy->name,
			'kind'  => 'terms',
			'title' => $taxonomy->labels->name,
			'type'  => __( 'Names, descriptions and fields', 'swiftling' ),
			'url'   => $url,
			'probe' => '' !== $url,
			'post'  => null,
		);
	}

	if ( 0 === strpos( $key, 'comments:' ) ) {
		return swiftling_comments_subject( substr( $key, 9 ) );
	}

	if ( 0 === strpos( $key, 'menu:' ) ) {
		return ctype_digit( substr( $key, 5 ) ) ? swiftling_menu_subject( (int) substr( $key, 5 ) ) : null;
	}

	if ( 0 === strpos( $key, 'record:' ) ) {
		return swiftling_record_subject( substr( $key, 7 ) );
	}

	$package = \SwiftLing\Packages\Texts::subject( $key );
	if ( null !== $package ) {
		return $package;
	}

	return \SwiftLing\Builders\Registry::subject( $key );
}

/**
 * Returns the subject of a page the user opens while recording, see SwiftLing\Packages\Recorder.
 * Its page check finds the texts of themes and plugins on the page; nothing else uses it.
 *
 * @param string $path Path and query of a page of this site, as "/shop/?orderby=price".
 * @return array|null Null for anything that is not a path of this site.
 */
function swiftling_record_subject( $path ) {
	if ( '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) || strlen( $path ) > 2000 || preg_match( '/[\s\\\\<>"]/', $path ) ) {
		return null;
	}
	$home   = wp_parse_url( set_url_scheme( (string) get_option( 'home' ) ) );
	$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	return array(
		'key'   => 'record:' . $path,
		'kind'  => 'record',
		'title' => $path,
		'type'  => __( 'Recorded page', 'swiftling' ),
		'url'   => $origin . $path,
		'probe' => true,
		'post'  => null,
		'cap'   => 'manage_options',
	);
}

/**
 * Whether the current user may translate a subject: the right it takes to edit it. Shared texts
 * the subject shows are checked one by one when they are saved, see swiftling_translatable_keys().
 *
 * @param array $subject Subject, see swiftling_subject().
 * @return bool
 */
function swiftling_can_translate_subject( $subject ) {
	if ( ! current_user_can( SWIFTLING_TRANSLATE ) ) {
		return false;
	}
	// A translator translates everything, also what they may not edit.
	if ( swiftling_is_translator() ) {
		return true;
	}
	if ( 'post' === $subject['kind'] ) {
		return current_user_can( 'edit_post', $subject['post']->ID );
	}
	if ( 'terms' === $subject['kind'] ) {
		$taxonomy = get_taxonomy( substr( $subject['key'], 6 ) );
		return $taxonomy && current_user_can( $taxonomy->cap->edit_terms );
	}
	// A builder's subject names the right it takes, such as the one its options page asks for.
	if ( isset( $subject['cap'] ) ) {
		return '' !== $subject['cap'] && current_user_can( $subject['cap'] );
	}
	return current_user_can( 'edit_theme_options' );
}

/**
 * Returns the example address of the "Page not found" kind of page: one no page has, which answers
 * 404, see swiftling_fetch_check_page().
 *
 * @return string
 */
function swiftling_not_found_url() {
	return home_url( '/swiftling-page-not-found/' );
}

/**
 * Returns the kinds of pages of the site, each with an example address and, for a block theme, the
 * template WordPress makes it from.
 *
 * For a block theme there is one entry per template that some example page uses; a template used
 * by several kinds of pages, such as All Archives for categories, tags and authors, is checked on
 * the first of them and names all of them, so the screen says what the template shows. For a
 * classic theme every kind of page is an entry of its own.
 *
 * @return array<int, array{key: string, title: string, description: string, url: string, example: string, uses: string[]}>
 */
function swiftling_site_views() {
	return swiftling_memo( 'site_views', 'swiftling_build_site_views' );
}

/**
 * Builds the kinds of pages of the site, see swiftling_site_views().
 *
 * @return array
 */
function swiftling_build_site_views() {
	$views = array();
	$block = wp_is_block_theme();

	foreach ( swiftling_example_pages() as $example ) {
		if ( ! $block ) {
			$views[] = array(
				'key'         => 'view:' . $example['type'],
				'title'       => $example['label'],
				'description' => '',
				'url'         => $example['url'],
				'example'     => $example['label'],
				'uses'        => array( $example['label'] ),
			);
			continue;
		}

		// A page template of a plugin, such as Elementor's full width, builds the page with its own
		// PHP file: no block template shows such a page, so the plugin's frame is not listed here.
		if ( 0 === strpos( $example['type'], 'template-' ) && ! get_block_templates( array( 'slug__in' => array( $example['hierarchy'][0] ) ) ) ) {
			continue;
		}

		$template = resolve_block_template( $example['hierarchy'][0], $example['hierarchy'], '' );
		if ( ! $template instanceof WP_Block_Template ) {
			continue;
		}
		$key = 'template:' . $template->id;
		foreach ( $views as $index => $view ) {
			if ( $view['key'] === $key ) {
				$views[ $index ]['uses'][] = $example['label'];
				continue 2;
			}
		}
		$views[] = array(
			'key'         => $key,
			'title'       => '' !== $template->title ? $template->title : $template->slug,
			'description' => (string) $template->description,
			'url'         => $example['url'],
			'example'     => $example['label'],
			'uses'        => array( $example['label'] ),
		);
	}

	if ( $block ) {
		// Templates no example page uses, so visitors never see them: listed, without an example.
		foreach ( get_block_templates( array(), 'wp_template' ) as $template ) {
			$key = 'template:' . $template->id;
			if ( ! in_array( $key, wp_list_pluck( $views, 'key' ), true ) ) {
				$views[] = array(
					'key'         => $key,
					'title'       => '' !== $template->title ? $template->title : $template->slug,
					'description' => (string) $template->description,
					'url'         => '',
					'example'     => '',
					'uses'        => array(),
				);
			}
		}
	}

	return $views;
}

/**
 * Returns the subject of the template or kind of page a post is shown with, where its header,
 * footer and menu are translated.
 *
 * @param WP_Post $post Post.
 * @return array|null Subject, see swiftling_subject(); null when there is none.
 */
function swiftling_post_view_subject( $post ) {
	$key = swiftling_post_view_key( $post );
	return '' === $key ? null : swiftling_subject( $key );
}

/**
 * Returns the key of the template or kind of page a post is shown with, without looking up every
 * template of the site as swiftling_subject() does, for the toolbar of a page.
 *
 * @param WP_Post $post Post.
 * @return string For example "template:twentytwentyfive//single" or "view:page"; empty when there is none.
 */
function swiftling_post_view_key( $post ) {
	$front  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	$is_cpt = ! in_array( $post->post_type, array( 'post', 'page' ), true );
	if ( $front === $post->ID ) {
		$type      = 'front';
		$hierarchy = array_merge( array( 'front-page' ), swiftling_singular_hierarchy( $post ) );
	} elseif ( (int) get_option( 'page_for_posts' ) === $post->ID ) {
		$type      = 'home';
		$hierarchy = array( 'home', 'index' );
	} else {
		$type      = 'page' === $post->post_type ? 'page' : ( $is_cpt ? 'single-' . $post->post_type : 'single' );
		$hierarchy = swiftling_singular_hierarchy( $post );
	}

	if ( ! wp_is_block_theme() ) {
		return 'view:' . $type;
	}
	$template = resolve_block_template( $hierarchy[0], $hierarchy, '' );
	return $template instanceof WP_Block_Template ? 'template:' . $template->id : '';
}

/**
 * Returns the templates WordPress tries for a single post or page, in order, as get_single_template()
 * and get_page_template() list them.
 *
 * @param WP_Post $post Post.
 * @return string[] Template slugs.
 */
function swiftling_singular_hierarchy( $post ) {
	$hierarchy = array();
	$custom    = (string) get_page_template_slug( $post );
	if ( '' !== $custom ) {
		$hierarchy[] = preg_replace( '/\.php$/', '', $custom );
	}
	if ( 'page' === $post->post_type ) {
		$slug = urldecode( $post->post_name );
		if ( '' !== $slug ) {
			$hierarchy[] = 'page-' . $slug;
		}
		$hierarchy[] = 'page-' . $post->ID;
		$hierarchy[] = 'page';
	} else {
		$slug = urldecode( $post->post_name );
		if ( '' !== $slug ) {
			$hierarchy[] = 'single-' . $post->post_type . '-' . $slug;
		}
		$hierarchy[] = 'single-' . $post->post_type;
		$hierarchy[] = 'single';
	}
	$hierarchy[] = 'singular';
	$hierarchy[] = 'index';
	return $hierarchy;
}

/**
 * Returns one example address for every kind of page the site has, with the templates WordPress
 * tries for it, in the order of the template hierarchy.
 *
 * @return array<int, array{type: string, label: string, url: string, hierarchy: string[]}>
 */
function swiftling_example_pages() {
	$examples = array();
	$front    = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	$blog     = $front ? (int) get_option( 'page_for_posts' ) : 0;

	if ( $front && get_post( $front ) ) {
		$examples[] = array(
			'type'      => 'front',
			'label'     => __( 'Front page', 'swiftling' ),
			'url'       => home_url( '/' ),
			'hierarchy' => array_merge( array( 'front-page' ), swiftling_singular_hierarchy( get_post( $front ) ) ),
		);
		if ( $blog && get_post( $blog ) ) {
			$examples[] = array(
				'type'      => 'home',
				'label'     => __( 'Page of posts', 'swiftling' ),
				'url'       => (string) get_permalink( $blog ),
				'hierarchy' => array( 'home', 'index' ),
			);
		}
	} else {
		$examples[] = array(
			'type'      => 'home',
			'label'     => __( 'Front page with the newest posts', 'swiftling' ),
			'url'       => home_url( '/' ),
			'hierarchy' => array( 'front-page', 'home', 'index' ),
		);
	}

	// The newest post of every public post type that uses no page template of its own, and the
	// newest one of every page template in use.
	$latest = null;
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		// Types a site does not link to, such as the saved templates of a page builder.
		if ( 'attachment' === $type->name || ( ! in_array( $type->name, array( 'post', 'page' ), true ) && ! $type->show_in_nav_menus ) ) {
			continue;
		}
		$posts = get_posts(
			array(
				'post_type'      => $type->name,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'post__not_in'   => array_filter( array( $front, $blog ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Read only on the Templates screen.
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_wp_page_template',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_wp_page_template',
						'value' => array( '', 'default' ),
					),
				),
			)
		);
		if ( $posts ) {
			$is_page    = 'page' === $type->name;
			$examples[] = array(
				'type'      => $is_page ? 'page' : ( 'post' === $type->name ? 'single' : 'single-' . $type->name ),
				'label'     => $type->labels->singular_name,
				'url'       => (string) get_permalink( $posts[0] ),
				'hierarchy' => swiftling_singular_hierarchy( $posts[0] ),
			);
			if ( 'post' === $type->name ) {
				$latest = $posts[0];
			}
		}
		if ( $type->has_archive && ! in_array( $type->name, array( 'post', 'page' ), true ) ) {
			$examples[] = array(
				'type'      => 'archive-' . $type->name,
				'label'     => $type->labels->name,
				'url'       => (string) get_post_type_archive_link( $type->name ),
				'hierarchy' => array( 'archive-' . $type->name, 'archive', 'index' ),
			);
		}
	}

	foreach ( swiftling_custom_template_examples( array_filter( array( $front, $blog ) ) ) as $example ) {
		$examples[] = $example;
	}

	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
		if ( 'post_format' === $taxonomy->name ) {
			continue;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy->name,
				'hide_empty' => true,
				'number'     => 1,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( ! $terms || is_wp_error( $terms ) ) {
			continue;
		}
		$term = $terms[0];
		$slug = urldecode( $term->slug );
		if ( 'category' === $taxonomy->name || 'post_tag' === $taxonomy->name ) {
			$base      = 'category' === $taxonomy->name ? 'category' : 'tag';
			$hierarchy = array( $base . '-' . $slug, $base . '-' . $term->term_id, $base, 'archive', 'index' );
		} else {
			$base      = 'taxonomy-' . $taxonomy->name;
			$hierarchy = array( $base . '-' . $slug, $base, 'taxonomy', 'archive', 'index' );
		}
		$examples[] = array(
			'type'      => $base,
			'label'     => $taxonomy->labels->singular_name,
			'url'       => (string) get_term_link( $term ),
			'hierarchy' => $hierarchy,
		);
	}

	// The newest post whose author still exists, for the page of an author's posts.
	$written = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'author__not_in' => array( 0 ),
		)
	);
	$author  = $written ? get_userdata( (int) $written[0]->post_author ) : false;
	if ( $author ) {
		$examples[] = array(
			'type'      => 'author',
			'label'     => __( 'Author', 'swiftling' ),
			'url'       => get_author_posts_url( $author->ID, $author->user_nicename ),
			'hierarchy' => array( 'author-' . $author->user_nicename, 'author-' . $author->ID, 'author', 'archive', 'index' ),
		);
	}
	if ( $latest ) {
		$examples[] = array(
			'type'      => 'date',
			'label'     => __( 'Posts of a month', 'swiftling' ),
			'url'       => get_month_link( (int) get_the_date( 'Y', $latest ), (int) get_the_date( 'm', $latest ) ),
			'hierarchy' => array( 'date', 'archive', 'index' ),
		);
	}

	// A search that finds something, with a word from the newest post's title.
	$word       = $latest ? (string) strtok( wp_strip_all_tags( $latest->post_title ), ' ' ) : '';
	$examples[] = array(
		'type'      => 'search',
		'label'     => __( 'Search results', 'swiftling' ),
		'url'       => add_query_arg( 's', rawurlencode( '' !== $word ? $word : 'a' ), home_url( '/' ) ),
		'hierarchy' => array( 'search', 'index' ),
	);
	$examples[] = array(
		'type'      => '404',
		'label'     => __( 'Page not found', 'swiftling' ),
		'url'       => swiftling_not_found_url(),
		'hierarchy' => array( '404', 'index' ),
	);

	return $examples;
}

/**
 * Returns an example for every page template some published post or page uses, such as a page
 * without a title.
 *
 * @param int[] $exclude Posts already used as examples: the front page and the page of posts.
 * @return array<int, array{type: string, label: string, url: string, hierarchy: string[]}>
 */
function swiftling_custom_template_examples( $exclude ) {
	$examples = array();
	$found    = array();
	$posts    = get_posts(
		array(
			'post_type'      => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'post__not_in'   => $exclude,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Read only on the Templates screen.
			'meta_query'     => array(
				array(
					'key'     => '_wp_page_template',
					'value'   => array( '', 'default' ),
					'compare' => 'NOT IN',
				),
			),
		)
	);
	foreach ( $posts as $post ) {
		$slug = (string) get_page_template_slug( $post );
		if ( '' === $slug || isset( $found[ $slug ] ) ) {
			continue;
		}
		$found[ $slug ] = true;
		$names          = wp_get_theme()->get_page_templates( $post, $post->post_type );
		$examples[]     = array(
			'type'      => 'template-' . sanitize_key( $slug ),
			/* translators: %s: name of a page template, e.g. Full width. */
			'label'     => sprintf( __( 'Page template: %s', 'swiftling' ), isset( $names[ $slug ] ) ? $names[ $slug ] : $slug ),
			'url'       => (string) get_permalink( $post ),
			'hierarchy' => swiftling_singular_hierarchy( $post ),
		);
	}
	return $examples;
}
