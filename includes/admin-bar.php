<?php
/**
 * From a page of the site to where its translation is edited: "Edit translation", with the
 * SwiftLing bird, in the toolbar WordPress shows signed-in people at the top of every page.
 *
 * - A post or page: the translation editor for it in the language the page is shown in. A
 *   published separate copy is changed in its own editor, so there the button opens that, and the
 *   translation editor is in its menu.
 * - The original has no translation to edit, so it has no button: WordPress's own Edit is there.
 * - The menu also leads to the template around the page, where the header, footer, menu and site
 *   title are translated, and to every translation.
 * - A page without a post of its own, such as an archive or the search, leads to its template.
 *
 * Only for people who may translate, and never in a page check of the translation editor.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Edit translation" and its menu to the toolbar of a page of the site.
 *
 * @param WP_Admin_Bar $bar Toolbar.
 */
function swiftling_admin_bar_menu( $bar ) {
	$state = &swiftling_state();
	if ( is_admin() || swiftling_is_probe() || 'translate' !== swiftling_mode() || '' === (string) $state['language'] || ! current_user_can( SWIFTLING_TRANSLATE ) ) {
		return;
	}

	$items = swiftling_admin_bar_items( (string) $state['language'] );
	if ( ! $items ) {
		return;
	}
	$main = reset( $items );

	$bar->add_node(
		array(
			'id'    => 'swiftling',
			'title' => '<span class="ab-icon" aria-hidden="true">' . swiftling_admin_bar_icon() . '</span><span class="ab-label">' . esc_html__( 'Edit translation', 'swiftling' ) . '</span>',
			'href'  => $main['href'],
			'meta'  => array( 'title' => $main['title'] ),
		)
	);
	foreach ( $items as $id => $item ) {
		$bar->add_node(
			array(
				'parent' => 'swiftling',
				'id'     => 'swiftling-' . $id,
				'title'  => esc_html( $item['title'] ),
				'href'   => $item['href'],
			)
		);
	}
}
add_action( 'admin_bar_menu', 'swiftling_admin_bar_menu', 81 );

/**
 * Returns where the translation of the page being shown is edited, the first being where the
 * button itself leads.
 *
 * @param string $lang Address slug of the language the page is shown in.
 * @return array<string, array{title: string, href: string}> Keyed by a short name of the place.
 */
function swiftling_admin_bar_items( $lang ) {
	$target = swiftling_target_language( $lang );
	if ( ! $target ) {
		return array();
	}

	$items  = array();
	$object = get_queried_object();
	// The post the page shows: the original of a separate copy.
	$post = null;
	if ( is_singular() && $object instanceof WP_Post ) {
		$original_id = swiftling_copy_original_id( $object );
		$post        = $original_id ? get_post( $original_id ) : $object;
		if ( $original_id && current_user_can( 'edit_post', $object->ID ) ) {
			$items['copy'] = array(
				/* translators: %s: language name, e.g. Deutsch. */
				'title' => sprintf( __( 'Edit the %s copy', 'swiftling' ), $target['name'] ),
				'href'  => \SwiftLing\Builders\Registry::editUrl( $object->ID ),
			);
		}
		$subject = $post ? swiftling_subject( 'post:' . $post->ID ) : null;
		if ( $subject && swiftling_can_translate_subject( $subject ) ) {
			$items['editor'] = array(
				/* translators: %s: language name, e.g. Deutsch. */
				'title' => sprintf( __( 'Translate into %s', 'swiftling' ), $target['name'] ),
				'href'  => swiftling_editor_url( $subject['key'], $lang ),
			);
		}
	}

	// The template WordPress made the page from, in a block theme, or the kind of page of a post in a
	// classic theme. Other pages of a classic theme: its kinds of pages, on the Templates screen.
	global $_wp_current_template_id;
	$view = $post && ! wp_is_block_theme() ? swiftling_post_view_key( $post ) : '';
	if ( '' !== $view && current_user_can( 'edit_theme_options' ) ) {
		$items['template'] = array(
			'title' => __( 'Header, footer and menu', 'swiftling' ),
			'href'  => swiftling_editor_url( $view, $lang ),
		);
	} elseif ( wp_is_block_theme() && is_string( $_wp_current_template_id ) && '' !== $_wp_current_template_id && current_user_can( 'edit_theme_options' ) ) {
		$template          = get_block_template( $_wp_current_template_id );
		$items['template'] = array(
			'title' => $template && '' !== $template->title
				/* translators: %s: name of a template, e.g. Pages. */
				? sprintf( __( 'Header, footer and menu: template “%s”', 'swiftling' ), $template->title )
				: __( 'Header, footer and menu', 'swiftling' ),
			'href'  => swiftling_editor_url( 'template:' . $_wp_current_template_id, $lang ),
		);
	} elseif ( current_user_can( 'edit_theme_options' ) ) {
		$items['template'] = array(
			'title' => __( 'Header, footer and menu', 'swiftling' ),
			'href'  => admin_url( 'admin.php?page=swiftling-templates' ),
		);
	}

	$items['all'] = array(
		'title' => __( 'All translations', 'swiftling' ),
		'href'  => admin_url( 'admin.php?page=swiftling-content' ),
	);
	return $items;
}

/**
 * Returns the SwiftLing bird for the toolbar.
 *
 * @return string SVG.
 */
function swiftling_admin_bar_icon() {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64.86 36.57" width="20" height="12" focusable="false"><path fill="#E8552D" d="' . \SwiftLing\Admin\Ui::BIRD . '"/></svg>';
}

/**
 * Places the bird in the toolbar as WordPress places its own icons, also on a phone, where the
 * toolbar shows icons only.
 */
function swiftling_admin_bar_style() {
	if ( is_admin() || 'translate' !== swiftling_mode() || ! is_admin_bar_showing() || ! current_user_can( SWIFTLING_TRANSLATE ) ) {
		return;
	}
	wp_add_inline_style(
		'admin-bar',
		'#wpadminbar #wp-admin-bar-swiftling > .ab-item .ab-icon{display:inline-flex;align-items:center;float:none;height:32px;margin:0 6px 0 0;padding:0;vertical-align:top}'
		. '#wpadminbar #wp-admin-bar-swiftling > .ab-item .ab-icon:before{content:none}'
		. '@media screen and (max-width:782px){#wpadminbar li#wp-admin-bar-swiftling{display:block}'
		. '#wpadminbar #wp-admin-bar-swiftling > .ab-item{width:52px;text-align:center}'
		. '#wpadminbar #wp-admin-bar-swiftling > .ab-item .ab-icon{height:46px;margin:0}'
		. '#wpadminbar #wp-admin-bar-swiftling > .ab-item .ab-icon svg{width:28px;height:17px}'
		. '#wpadminbar #wp-admin-bar-swiftling .ab-label{display:none}}'
	);
}
add_action( 'wp_enqueue_scripts', 'swiftling_admin_bar_style' );
