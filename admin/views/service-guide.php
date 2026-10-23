<?php
/**
 * Part of the Translation service screen, see service.php: the guide to getting a key that a
 * service gives, see Service::guide(). Nothing when the service gives none.
 *
 * Expects $swiftling_guide_service, the service, from the file that includes it.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

defined( 'ABSPATH' ) || exit;

$swiftling_guide = $swiftling_guide_service->guide();
if ( empty( $swiftling_guide['steps'] ) ) {
	return;
}
$swiftling_guide_id = 'swiftling-guide-' . $swiftling_guide_service->id();
?>
<section class="swiftling-card swiftling-service-guide" aria-labelledby="<?php echo esc_attr( $swiftling_guide_id ); ?>">
	<h2 id="<?php echo esc_attr( $swiftling_guide_id ); ?>"><?php echo esc_html( $swiftling_guide['title'] ); ?></h2>
	<?php if ( '' !== $swiftling_guide['intro'] ) : ?>
		<p class="swiftling-muted"><?php echo esc_html( $swiftling_guide['intro'] ); ?></p>
	<?php endif; ?>
	<ol>
		<?php foreach ( $swiftling_guide['steps'] as $swiftling_step ) : ?>
			<li>
				<?php // A step may name the one thing to click in bold. ?>
				<?php echo wp_kses( $swiftling_step[0], array( 'strong' => array() ) ); ?>
				<?php if ( '' !== $swiftling_step[1] ) : ?>
					<a href="<?php echo esc_url( $swiftling_step[1] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open', 'swiftling' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'swiftling' ); ?></span></a>
				<?php endif; ?>
				<?php if ( ! empty( $swiftling_step[2] ) ) : ?>
					<span class="swiftling-muted swiftling-service-guide__note"><?php echo esc_html( $swiftling_step[2] ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php if ( ! empty( $swiftling_guide['link'][1] ) ) : ?>
		<p class="swiftling-muted">
			<a href="<?php echo esc_url( $swiftling_guide['link'][1] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $swiftling_guide['link'][0] ); ?></a>
		</p>
	<?php endif; ?>
</section>
