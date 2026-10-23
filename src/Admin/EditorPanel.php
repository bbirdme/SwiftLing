<?php
/**
 * Translations panel in the block editor sidebar, and the same as a box in the classic editor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Translations panel in the block editor sidebar. Where a post is edited in the classic editor, as
 * WooCommerce edits its products, a Translations box in the side shows the same languages and
 * states, each a link; choosing a separate copy or keeping the original is done in the translation editor.
 */
final class EditorPanel {
	/**
	 * Hooks the panel into the block editor, and the box into the classic editor.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( $this, 'addBox' ), 10, 2 );
	}

	/**
	 * Adds the Translations box to the classic editor of a post visitors open, or of a separate copy,
	 * for someone who may translate. The block editor has the panel instead.
	 *
	 * @param string $post_type Post type.
	 * @param mixed  $post      Post being edited.
	 */
	public function addBox( $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post || use_block_editor_for_post( $post ) || ! current_user_can( SWIFTLING_TRANSLATE ) ) {
			return;
		}
		if ( ! swiftling_copy_original_id( $post ) && ( 'attachment' === $post_type || ! is_post_type_viewable( $post_type ) ) ) {
			return;
		}
		SubjectBox::enqueue();
		add_meta_box( 'swiftling-translations', __( 'Translations', 'swiftling' ), array( $this, 'renderBox' ), null, 'side', 'high' );
	}

	/**
	 * Prints the Translations box of the classic editor, with the words of the block editor's panel.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function renderBox( $post ): void {
		$data = $this->data( $post );
		echo '<div class="swiftling-panel">';

		if ( $data['copyOf'] ) {
			$copy = $data['copyOf'];
			?>
			<p>
				<?php
				echo wp_kses(
					$copy['published']
						/* translators: 1: language name, e.g. Deutsch, 2: title of the original. */
						? sprintf( __( 'This is the separate <strong>%1$s</strong> copy of “%2$s”. Visitors who open that page in %1$s see this one.', 'swiftling' ), esc_html( $copy['language'] ), esc_html( $copy['title'] ) )
						/* translators: 1: language name, e.g. Deutsch, 2: title of the original. */
						: sprintf( __( 'This is the separate <strong>%1$s</strong> copy of “%2$s”. Visitors who open that page in %1$s see this one once it is published.', 'swiftling' ), esc_html( $copy['language'] ), esc_html( $copy['title'] ) ),
					array( 'strong' => array() )
				);
				?>
			</p>
			<p class="swiftling-panel__hint"><?php esc_html_e( 'Categories and tags come from the original.', 'swiftling' ); ?></p>
			<a class="button swiftling-panel__wide" href="<?php echo esc_url( $copy['editorUrl'] ); ?>"><?php esc_html_e( 'Open the translations of the original', 'swiftling' ); ?></a>
			<?php
		} elseif ( ! $data['languages'] ) {
			echo '<p>' . esc_html__( 'No languages chosen yet.', 'swiftling' ) . '</p>';
			printf( '<a class="button" href="%s">%s</a>', esc_url( $data['languagesUrl'] ), esc_html__( 'Add languages', 'swiftling' ) );
		} elseif ( 'auto-draft' === $post->post_status ) {
			// Nothing is saved yet, and the translation starts from what is saved.
			echo '<p class="swiftling-panel__hint">' . esc_html__( 'Save it first: the translation starts from what is saved here.', 'swiftling' ) . '</p>';
		} else {
			?>
			<p>
				<?php
				/* translators: %s: language name, e.g. English. */
				echo wp_kses( sprintf( __( 'Written in <strong>%s</strong>.', 'swiftling' ), esc_html( $data['source'] ) ), array( 'strong' => array() ) );
				?>
			</p>
			<ul class="swiftling-panel__languages">
				<?php foreach ( $data['languages'] as $language ) : ?>
					<?php
					$state = self::rowState( $language );
					// A separate copy is edited in its own editor.
					$url = 'manual' === $language['mode'] && $language['copy'] ? $language['copy']['editUrl'] : $language['url'];
					?>
					<li>
						<a class="swiftling-panel__row" href="<?php echo esc_url( $url ); ?>">
							<span class="swiftling-panel__lang"><?php echo esc_html( $language['code'] ); ?></span>
							<span class="swiftling-panel__about">
								<span class="swiftling-panel__name"><?php echo esc_html( $language['name'] ); ?></span>
								<span class="swiftling-panel__state is-<?php echo esc_attr( $state[0] ); ?>"><?php echo esc_html( $state[1] ); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="swiftling-panel__hint"><?php esc_html_e( 'The translation starts from what is saved here: update first, then translate.', 'swiftling' ); ?></p>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Returns what a language's row says, as the block editor's panel says it (rowState() in
	 * assets/js/editor-panel.js): the state of the translation, of the separate copy, or that the
	 * language keeps the original, showing the post as it is.
	 *
	 * @param array $language Language, see language().
	 * @return array{0: string, 1: string} State for the dot, and the words.
	 */
	private static function rowState( array $language ): array {
		if ( 'manual' === $language['mode'] && $language['copy'] && $language['copy']['published'] ) {
			return 'outdated' === $language['state']
				? array( 'outdated', __( 'Copy · original changed', 'swiftling' ) )
				: array( 'reviewed', __( 'Separate copy', 'swiftling' ) );
		}
		if ( 'manual' === $language['mode'] ) {
			return array( 'draft', __( 'Copy in draft', 'swiftling' ) );
		}
		if ( 'duplicate' === $language['mode'] ) {
			return array( 'duplicate', __( 'Keeps the original', 'swiftling' ) );
		}
		$labels = array(
			'reviewed' => __( 'Reviewed', 'swiftling' ),
			'machine'  => __( 'Machine translated', 'swiftling' ),
			'official' => __( 'Reviewed', 'swiftling' ),
			'outdated' => __( 'Original changed', 'swiftling' ),
			'partial'  => __( 'Partly translated', 'swiftling' ),
			'missing'  => __( 'Not translated', 'swiftling' ),
		);
		$state  = isset( $labels[ $language['state'] ] ) ? $language['state'] : 'missing';
		return array( $state, $labels[ $state ] );
	}

	/**
	 * Loads the panel script in the post editor only, not in the site or widget editor.
	 */
	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		wp_enqueue_script(
			'swiftling-editor-panel',
			SWIFTLING_URL . 'assets/js/editor-panel.js',
			array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			SWIFTLING_VERSION,
			true
		);
		wp_set_script_translations( 'swiftling-editor-panel', 'swiftling' );
		wp_enqueue_style( 'swiftling-editor-panel', SWIFTLING_URL . 'assets/css/editor-panel.css', array(), SWIFTLING_VERSION );

		wp_add_inline_script( 'swiftling-editor-panel', 'window.swiftlingEditorPanel = ' . wp_json_encode( $this->data( get_post() ) ) . ';', 'before' );
	}

	/**
	 * Returns what the panel shows: each language of the post, with its state, the address of its
	 * translation editor and its separate copy, if it has one; or, for a copy, its original.
	 *
	 * @param \WP_Post|null $post Post being edited.
	 * @return array<string, mixed>
	 */
	private function data( $post ): array {
		$languages = swiftling_languages();
		$data      = array(
			'source'       => $languages['source']['name'],
			'languages'    => array(),
			'languagesUrl' => admin_url( 'admin.php?page=swiftling-languages' ),
			'canCopy'      => false,
			'canChoose'    => false,
			'copyOf'       => null,
		);
		if ( ! $post ) {
			return $data;
		}

		$original_id = swiftling_copy_original_id( $post );
		if ( $original_id ) {
			$slug           = swiftling_locale_slug( (string) get_post_meta( $post->ID, SWIFTLING_COPY_LANG, true ) );
			$language       = '' === $slug ? null : swiftling_target_language( $slug );
			$data['copyOf'] = array(
				'title'     => get_the_title( $original_id ),
				'published' => 'publish' === $post->post_status,
				'language'  => $language ? $language['name'] : '',
				'editorUrl' => swiftling_editor_url( $original_id, $slug ),
			);
			return $data;
		}

		foreach ( array_keys( $languages['targets'] ) as $slug ) {
			$data['languages'][] = self::language( $post, (string) $slug );
		}
		$data['canCopy']   = swiftling_can_make_copy( $post );
		$data['canChoose'] = swiftling_can_choose_mode( $post );
		return $data;
	}

	/**
	 * Returns one language as the panel shows it: its state, how it shows the post (translation,
	 * manual or duplicate), the address of its translation editor and its separate copy, if any.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $slug Address slug of the language.
	 * @return array<string, mixed>
	 */
	public static function language( $post, string $slug ): array {
		$language = swiftling_target_language( $slug );
		$states   = swiftling_language_states( $post->ID );
		$copy     = swiftling_copy_for( $post->ID, $language['locale'] );
		return array(
			'slug'  => $slug,
			'code'  => $language['code'],
			'name'  => $language['name'],
			'state' => isset( $states[ $slug ] ) ? $states[ $slug ] : 'missing',
			'mode'  => swiftling_language_mode( $post->ID, $language['locale'] ),
			'url'   => swiftling_editor_url( $post->ID, $slug ),
			'copy'  => $copy ? array(
				'published' => 'publish' === $copy->post_status,
				'editUrl'   => \SwiftLing\Builders\Registry::editUrl( $copy->ID ),
			) : null,
		);
	}
}
