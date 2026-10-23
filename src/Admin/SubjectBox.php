<?php
/**
 * Translations box on a builder's settings page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The "Translations" box a builder puts on a settings page of its own, such as an options page of
 * Advanced Custom Fields: every language with its state, each a link to the translation editor of
 * that page. It looks like the Translations panel of the block editor (assets/css/editor-panel.css).
 */
final class SubjectBox {
	/**
	 * Loads the box's styles: those of the block editor's panel.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( 'swiftling-editor-panel', SWIFTLING_URL . 'assets/css/editor-panel.css', array(), SWIFTLING_VERSION );
	}

	/**
	 * Prints the box for a subject of the translation editor.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public static function render( array $subject ): void {
		$languages = swiftling_languages();
		echo '<div class="swiftling-panel">';

		if ( ! $languages['targets'] ) {
			echo '<p>' . esc_html__( 'No languages chosen yet.', 'swiftling' ) . '</p>';
			printf( '<a class="button" href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=swiftling-languages' ) ), esc_html__( 'Add languages', 'swiftling' ) );
			echo '</div>';
			return;
		}

		// The same words for the states as the block editor's panel.
		$labels = array(
			'reviewed' => __( 'Reviewed', 'swiftling' ),
			'machine'  => __( 'Machine translated', 'swiftling' ),
			'official' => __( 'Reviewed', 'swiftling' ),
			'outdated' => __( 'Original changed', 'swiftling' ),
			'partial'  => __( 'Partly translated', 'swiftling' ),
			'missing'  => __( 'Not translated', 'swiftling' ),
		);
		$states = swiftling_subject_states( $subject['key'] );
		?>
		<p>
			<?php
			/* translators: %s: language name, e.g. English. */
			echo wp_kses( sprintf( __( 'Written in <strong>%s</strong>.', 'swiftling' ), esc_html( $languages['source']['name'] ) ), array( 'strong' => array() ) );
			?>
		</p>
		<ul class="swiftling-panel__languages">
			<?php foreach ( $languages['targets'] as $slug => $language ) : ?>
				<?php $state = isset( $states[ $slug ], $labels[ $states[ $slug ] ] ) ? $states[ $slug ] : 'missing'; ?>
				<li>
					<a class="swiftling-panel__row" href="<?php echo esc_url( swiftling_editor_url( $subject['key'], (string) $slug ) ); ?>">
						<span class="swiftling-panel__lang"><?php echo esc_html( $language['code'] ); ?></span>
						<span class="swiftling-panel__about">
							<span class="swiftling-panel__name"><?php echo esc_html( $language['name'] ); ?></span>
							<span class="swiftling-panel__state is-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $labels[ $state ] ); ?></span>
						</span>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="swiftling-panel__hint"><?php esc_html_e( 'The translation starts from what is saved here: update first, then translate.', 'swiftling' ); ?></p>
		<a class="button swiftling-panel__wide" href="<?php echo esc_url( swiftling_editor_url( $subject['key'], (string) key( $languages['targets'] ) ) ); ?>">
			<?php
			// Named after what it translates, as the menu names it: "Translate “Theme settings”".
			/* translators: %s: name of a settings page, e.g. Theme settings. */
			echo esc_html( sprintf( __( 'Translate “%s”', 'swiftling' ), $subject['title'] ) );
			?>
		</a>
		</div>
		<?php
	}
}
