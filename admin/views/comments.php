<?php
/**
 * Comments screen: the comments and reviews visitors read, by the kind of content they are written
 * on, each with how far every language has come. See includes/comments.php for which comments these
 * are and how they are translated.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();

Ui::screenHeader(
	array(
		'crumb'       => __( 'Translations', 'swiftling' ),
		'title'       => __( 'Comments', 'swiftling' ),
		'description' => __( 'Comments and reviews visitors wrote under your pages and products. Each is translated on its own, when you choose: nothing is sent to a translation service until you press its button. A comment without a translation shows as it was written.', 'swiftling' ),
	)
);

if ( ! $swiftling_languages['targets'] ) {
	Ui::emptyState(
		__( 'Choose the languages first', 'swiftling' ),
		__( 'Content is translated into the languages you choose.', 'swiftling' ),
		array( __( 'Add languages', 'swiftling' ), admin_url( 'admin.php?page=swiftling-languages' ) )
	);
	return;
}

$swiftling_kinds = swiftling_can_list( 'moderate_comments' ) ? swiftling_comment_kinds() : array();
$swiftling_types = swiftling_content_types();
?>
<div class="swiftling-stack">
<?php if ( ! $swiftling_kinds ) : ?>
	<?php Ui::emptyState( __( 'No comments yet', 'swiftling' ), __( 'Approved comments and reviews on your published pages show here once visitors write them.', 'swiftling' ) ); ?>
<?php else : ?>
	<section class="swiftling-card">
		<div class="swiftling-table-wrap">
			<table class="swiftling-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Written on', 'swiftling' ); ?></th>
						<th><?php esc_html_e( 'Comments', 'swiftling' ); ?></th>
						<?php foreach ( $swiftling_languages['targets'] as $swiftling_language ) : ?>
							<th class="is-center"><?php Ui::lang( $swiftling_language['code'] ); ?></th>
						<?php endforeach; ?>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swiftling' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $swiftling_kinds as $swiftling_type => $swiftling_count ) : ?>
						<?php $swiftling_progress = swiftling_comment_progress( $swiftling_type ); ?>
						<tr>
							<td><strong><?php echo esc_html( $swiftling_types[ $swiftling_type ]->labels->name ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $swiftling_count ) ); ?></td>
							<?php foreach ( array_keys( $swiftling_languages['targets'] ) as $swiftling_slug ) : ?>
								<?php $swiftling_done = $swiftling_progress[ $swiftling_slug ]; ?>
								<td class="is-center">
									<span class="swiftling-segmented__count">
										<?php
										/* translators: 1: comments translated, 2: all comments, as in 24/28. */
										echo esc_html( sprintf( __( '%1$s/%2$s', 'swiftling' ), number_format_i18n( $swiftling_done['translated'] ), number_format_i18n( $swiftling_done['total'] ) ) );
										?>
									</span>
									<span class="swiftling-bar swiftling-segmented__meter" aria-hidden="true"><span class="is-reviewed" style="width: <?php echo esc_attr( (string) round( 100 * $swiftling_done['translated'] / max( 1, $swiftling_done['total'] ), 1 ) ); ?>%"></span></span>
								</td>
							<?php endforeach; ?>
							<td class="is-numeric"><?php Ui::button( __( 'Translate', 'swiftling' ), 'secondary', swiftling_editor_url( 'comments:' . $swiftling_type ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
<?php endif; ?>
</div>
