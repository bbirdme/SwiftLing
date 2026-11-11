<?php
/**
 * Visual editor: a page made of blocks laid out as its blocks, translated in place, see
 * includes/visual-editor.php. assets/js/visual.js draws the screen from the configuration printed
 * here, in the markup of the translation editor (its bar, the language tabs, the cards beside it),
 * so both read as one editor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
$swiftling_post = isset( $_GET['post'] ) ? get_post( absint( $_GET['post'] ) ) : null;
$swiftling_lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
// phpcs:enable
if ( ! swiftling_target_language( $swiftling_lang ) ) {
	$swiftling_lang = (string) array_key_first( swiftling_languages()['targets'] );
}
$swiftling_subject = $swiftling_post ? swiftling_subject( 'post:' . $swiftling_post->ID ) : null;
if ( $swiftling_subject && ! swiftling_can_translate_subject( $swiftling_subject ) ) {
	wp_die( esc_html__( 'You may not edit this content, so you cannot translate it either.', 'swiftling' ), '', array( 'response' => 403 ) );
}

$swiftling_data = '' === $swiftling_lang ? new \WP_Error( 'swiftling_visual_languages', __( 'Tell SwiftLing which languages this content should be translated into.', 'swiftling' ) ) : swiftling_visual_editor_data( $swiftling_post, $swiftling_lang );

if ( is_wp_error( $swiftling_data ) ) {
	Ui::screenHeader(
		array(
			'crumb' => __( 'Visual editor', 'swiftling' ),
			'title' => $swiftling_post ? $swiftling_post->post_title : __( 'Visual editor', 'swiftling' ),
		)
	);
	$swiftling_table = (array) $swiftling_data->get_error_data();
	Ui::emptyState(
		__( 'This page opens in the translation editor', 'swiftling' ),
		$swiftling_data->get_error_message(),
		! empty( $swiftling_table['url'] ) ? array( __( 'Open the translation editor', 'swiftling' ), $swiftling_table['url'] ) : array( __( 'Open translations', 'swiftling' ), admin_url( 'admin.php?page=swiftling-content' ) )
	);
	return;
}
?>
<div id="swiftling-visual" class="swiftling-visual">
	<div class="swiftling-card swiftling-visual-loading" role="status"><span class="swiftling-visual-loading__spinner" aria-hidden="true"></span><?php esc_html_e( 'Loading the page…', 'swiftling' ); ?></div>
</div>
<script type="application/json" id="swiftling-visual-config"><?php echo wp_json_encode( $swiftling_data, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & escaped, inside a JSON script element. ?></script>
