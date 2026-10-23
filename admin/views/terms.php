<?php
/**
 * Categories & tags screen: every list of terms visitors read, such as categories, tags, product
 * categories and WooCommerce's attributes, grouped by the content it belongs to, each with its
 * state in every language. See includes/terms.php for which lists these are.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();
$swiftling_legend    = array(
	'reviewed' => __( 'Reviewed', 'swiftling' ),
	'machine'  => __( 'Machine translated', 'swiftling' ),
	'outdated' => __( 'Original changed since translation', 'swiftling' ),
	'partial'  => __( 'Partly translated: some texts are still empty', 'swiftling' ),
	'missing'  => __( 'Not translated', 'swiftling' ),
);

Ui::screenHeader(
	array(
		'crumb'       => __( 'Translations', 'swiftling' ),
		'title'       => __( 'Categories & tags', 'swiftling' ),
		'description' => __( 'Names, descriptions and fields of categories, tags, product attributes and other lists. Each is translated once and shows translated everywhere: on posts and products, in menus, filters and breadcrumbs.', 'swiftling' ),
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

// Each list under the content it belongs to: the first of its kinds of content visitors open.
$swiftling_types  = swiftling_content_types();
$swiftling_lists  = swiftling_term_taxonomies();
$swiftling_groups = array();
foreach ( $swiftling_lists as $swiftling_name => $swiftling_taxonomy ) {
	if ( ! swiftling_can_list( $swiftling_taxonomy->cap->edit_terms ) ) {
		continue;
	}
	$swiftling_type = (string) current( array_intersect( (array) $swiftling_taxonomy->object_type, array_keys( $swiftling_types ) ) );

	$swiftling_groups[ $swiftling_type ][ $swiftling_name ] = array(
		'taxonomy' => $swiftling_taxonomy,
		'count'    => (int) wp_count_terms(
			array(
				'taxonomy'   => $swiftling_name,
				'hide_empty' => false,
			)
		),
	);
}
$swiftling_states  = swiftling_object_states(
	array_map(
		static function ( $name ): string {
			return 'terms:' . $name;
		},
		array_keys( array_merge( array(), ...array_values( $swiftling_groups ) ) )
	)
);
$swiftling_columns = 3 + count( $swiftling_languages['targets'] );

// Lists left out: those plugins keep for their own work, which no page a visitor opens shows. Each
// with what it belongs to, as "Categories (Layouts)", since its name alone can be a list's above.
$swiftling_left_out = array();
foreach ( get_taxonomies( array(), 'objects' ) as $swiftling_name => $swiftling_taxonomy ) {
	if ( isset( $swiftling_lists[ $swiftling_name ] ) || 'post_format' === $swiftling_name || ! ( $swiftling_taxonomy->show_ui || $swiftling_taxonomy->public ) ) {
		continue;
	}
	$swiftling_owner = get_post_type_object( (string) current( (array) $swiftling_taxonomy->object_type ) );

	$swiftling_left_out[] = $swiftling_owner
		/* translators: 1: name of a list, e.g. Categories, 2: what it belongs to, e.g. Layouts. */
		? sprintf( __( '%1$s (%2$s)', 'swiftling' ), $swiftling_taxonomy->labels->name, $swiftling_owner->labels->name )
		: $swiftling_taxonomy->labels->name;
}
$swiftling_left_out = array_values( array_unique( $swiftling_left_out ) );
?>
<div class="swiftling-stack">
<?php if ( ! $swiftling_groups ) : ?>
	<?php Ui::emptyState( __( 'No categories or tags', 'swiftling' ), __( 'This site has no lists of terms you may edit.', 'swiftling' ) ); ?>
<?php endif; ?>

<?php foreach ( $swiftling_groups as $swiftling_type => $swiftling_entries ) : ?>
	<section class="swiftling-card">
		<div class="swiftling-card__head">
			<h2><?php echo esc_html( $swiftling_types[ $swiftling_type ]->labels->name ); ?></h2>
		</div>
		<div class="swiftling-table-wrap">
			<table class="swiftling-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'List', 'swiftling' ); ?></th>
						<th><?php esc_html_e( 'Terms', 'swiftling' ); ?></th>
						<?php foreach ( $swiftling_languages['targets'] as $swiftling_language ) : ?>
							<th class="is-center"><?php Ui::lang( $swiftling_language['code'] ); ?></th>
						<?php endforeach; ?>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swiftling' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $swiftling_entries as $swiftling_name => $swiftling_entry ) : ?>
						<?php
						$swiftling_key   = 'terms:' . $swiftling_name;
						$swiftling_other = array_values( array_diff( array_intersect( (array) $swiftling_entry['taxonomy']->object_type, array_keys( $swiftling_types ) ), array( $swiftling_type ) ) );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $swiftling_entry['taxonomy']->labels->name ); ?></strong>
								<?php if ( $swiftling_other ) : ?>
									<br><span class="swiftling-muted">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: kinds of content, e.g. Pages and Projects. */
												__( 'Also used by: %s', 'swiftling' ),
												wp_sprintf(
													'%l',
													array_map(
														static function ( $type ) use ( $swiftling_types ): string {
															return $swiftling_types[ $type ]->labels->name;
														},
														$swiftling_other
													)
												)
											)
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<?php if ( ! $swiftling_entry['count'] ) : ?>
								<td colspan="<?php echo esc_attr( (string) ( $swiftling_columns - 1 ) ); ?>" class="swiftling-muted"><?php esc_html_e( 'None yet', 'swiftling' ); ?></td>
							<?php else : ?>
								<td><?php echo esc_html( number_format_i18n( $swiftling_entry['count'] ) ); ?></td>
								<?php foreach ( array_keys( $swiftling_languages['targets'] ) as $swiftling_slug ) : ?>
									<td class="is-center"><?php Ui::statusIcon( $swiftling_states[ $swiftling_key ][ $swiftling_slug ] ); ?></td>
								<?php endforeach; ?>
								<td class="is-numeric"><?php Ui::button( __( 'Translate', 'swiftling' ), 'secondary', swiftling_editor_url( $swiftling_key ) ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
<?php endforeach; ?>

<?php if ( $swiftling_left_out ) : ?>
	<details class="swiftling-card swiftling-details">
		<summary>
			<?php esc_html_e( 'Why are some lists not here?', 'swiftling' ); ?>
			<span class="swiftling-muted">
				<?php
				/* translators: %s: number of lists. */
				echo esc_html( sprintf( _n( '%s left out', '%s left out', count( $swiftling_left_out ), 'swiftling' ), number_format_i18n( count( $swiftling_left_out ) ) ) );
				?>
			</span>
		</summary>
		<p class="swiftling-muted">
			<?php
			/* translators: %s: names of lists, e.g. Pattern Categories, Packs and Scope. */
			echo esc_html( sprintf( __( 'Lists themes and plugins keep for their own work, which no page a visitor opens shows: %s.', 'swiftling' ), wp_sprintf( '%l', $swiftling_left_out ) ) );
			?>
		</p>
	</details>
<?php endif; ?>
</div>

<ul class="swiftling-legend is-icons">
	<?php foreach ( $swiftling_legend as $swiftling_state => $swiftling_label ) : ?>
		<li><?php Ui::statusIcon( $swiftling_state ); ?> <?php echo esc_html( $swiftling_label ); ?></li>
	<?php endforeach; ?>
</ul>
