<?php
/**
 * The language switcher block.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * The language switcher block, defined in blocks/language-switcher/block.json. It renders on the
 * server with the same code as every other switcher; the editor shows that render.
 *
 * Besides content, template parts and widget areas, the block can go inside the Navigation block,
 * where block themes keep their menus: assets/js/switcher-block.js allows it there, and the
 * Navigation block then puts it in a list item like its links.
 */
final class Block {
	/**
	 * Hooks the block into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'registerBlock' ) );
		add_filter( 'block_core_navigation_listable_blocks', array( $this, 'listInNavigation' ) );
	}

	/**
	 * Registers the editor script and the block.
	 */
	public function registerBlock(): void {
		wp_register_script(
			'swiftling-switcher-block',
			SWIFTLING_URL . 'assets/js/switcher-block.js',
			array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-server-side-render' ),
			SWIFTLING_VERSION,
			true
		);
		wp_set_script_translations( 'swiftling-switcher-block', 'swiftling' );
		wp_add_inline_script(
			'swiftling-switcher-block',
			'window.swiftlingSwitcherBlock = ' . wp_json_encode(
				array(
					'show'        => Settings::get()['show'],
					'settingsUrl' => admin_url( 'admin.php?page=swiftling-switcher' ),
				)
			) . ';',
			'before'
		);

		register_block_type(
			SWIFTLING_DIR . 'blocks/language-switcher',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$args       = array_intersect_key( $attributes, array_flip( array( 'flag', 'name', 'code' ) ) );
		if ( isset( $attributes['flagSize'] ) ) {
			$args['flag_size'] = $attributes['flagSize'];
		}
		// "type", because blocks keep their colours and spacing in an attribute named "style".
		$args['style'] = isset( $attributes['type'] ) ? $attributes['type'] : 'dropdown';
		return Switcher::render( $args, get_block_wrapper_attributes( array( 'class' => 'is-block' ) ) );
	}

	/**
	 * Returns where the block sits in the site, as the Switcher screen tells it: the menus of the
	 * Navigation block and the templates and template parts of the active theme, published, by their
	 * names. Content and widget areas are not looked through.
	 *
	 * @return string[] Names.
	 */
	public static function places(): array {
		$posts  = array_merge(
			get_posts(
				array(
					'post_type'   => 'wp_navigation',
					'post_status' => 'publish',
					'numberposts' => -1,
				)
			),
			get_posts(
				array(
					'post_type'   => array( 'wp_template_part', 'wp_template' ),
					'post_status' => 'publish',
					'numberposts' => -1,
					'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The few templates of the active theme, on the Switcher screen only.
						array(
							'taxonomy' => 'wp_theme',
							'field'    => 'name',
							'terms'    => get_stylesheet(),
						),
					),
				)
			)
		);
		$places = array();
		foreach ( $posts as $post ) {
			if ( has_block( 'swiftling/language-switcher', $post->post_content ) ) {
				$places[] = '' !== $post->post_title ? $post->post_title : $post->post_name;
			}
		}
		return $places;
	}

	/**
	 * Puts the block in a list item when it sits in the Navigation block, like a link.
	 *
	 * @param string[] $blocks Names of blocks the Navigation block wraps in a list item.
	 * @return string[]
	 */
	public function listInNavigation( $blocks ) {
		$blocks   = (array) $blocks;
		$blocks[] = 'swiftling/language-switcher';
		return $blocks;
	}
}
