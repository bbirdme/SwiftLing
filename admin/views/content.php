<?php
/**
 * Pages & posts, the first tab of Translations: pages, posts and other public content, with the state
 * of each translation. Its address keeps the slug swiftling-content, from when the screen was called
 * Content.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;
use WP_Query;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();
// A type set to Never in Settings › General is not translated, so not listed.
$swiftling_types    = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ), swiftling_settings()['never'] ) );
$swiftling_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
$swiftling_legend   = array(
	'reviewed'  => __( 'Reviewed', 'swiftling' ),
	'machine'   => __( 'Machine translated', 'swiftling' ),
	'outdated'  => __( 'Original changed since translation', 'swiftling' ),
	'partial'   => __( 'Partly translated: some texts are still empty', 'swiftling' ),
	'missing'   => __( 'Not translated', 'swiftling' ),
	'duplicate' => __( 'Keeps the original: shown as it is, untranslated', 'swiftling' ),
);

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters of a list.
$swiftling_type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
$swiftling_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$swiftling_paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
if ( ! in_array( $swiftling_type, $swiftling_types, true ) ) {
	$swiftling_type = '';
}

Ui::screenHeader(
	array(
		'crumb' => __( 'Translations', 'swiftling' ),
		'title' => __( 'Pages & posts', 'swiftling' ),
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

$swiftling_query  = new WP_Query(
	array(
		'post_type'      => '' === $swiftling_type ? $swiftling_types : $swiftling_type,
		'post_status'    => $swiftling_statuses,
		's'              => $swiftling_search,
		'posts_per_page' => 20,
		'paged'          => $swiftling_paged,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		// A separate copy is listed as the language of its original.
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One condition on an indexed key.
		'meta_query'     => array(
			array(
				'key'     => SWIFTLING_COPY_OF,
				'compare' => 'NOT EXISTS',
			),
		),
	)
);
$swiftling_states = swiftling_post_states( wp_list_pluck( $swiftling_query->posts, 'ID' ) );

$swiftling_tabs = array( '' => __( 'Everything', 'swiftling' ) );
foreach ( $swiftling_types as $swiftling_type_name ) {
	$swiftling_tabs[ $swiftling_type_name ] = get_post_type_object( $swiftling_type_name )->labels->name;
}
?>
<form class="swiftling-toolbar" method="get">
	<input type="hidden" name="page" value="swiftling-content">
	<nav class="swiftling-segmented" aria-label="<?php esc_attr_e( 'Kind of content', 'swiftling' ); ?>">
		<?php foreach ( $swiftling_tabs as $swiftling_key => $swiftling_tab ) : ?>
			<?php
			$swiftling_current = $swiftling_key === $swiftling_type;
			$swiftling_counted = 0;
			foreach ( '' === $swiftling_key ? $swiftling_types : array( $swiftling_key ) as $swiftling_counted_type ) {
				$swiftling_counts = wp_count_posts( $swiftling_counted_type );
				foreach ( $swiftling_statuses as $swiftling_status ) {
					$swiftling_counted += isset( $swiftling_counts->$swiftling_status ) ? (int) $swiftling_counts->$swiftling_status : 0;
				}
			}
			if ( ! $swiftling_counted && ! $swiftling_current && '' !== $swiftling_key ) {
				continue;
			}
			?>
			<a class="<?php echo $swiftling_current ? 'is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'type', $swiftling_key, admin_url( 'admin.php?page=swiftling-content' ) ) ); ?>"<?php echo $swiftling_current ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $swiftling_tab ); ?>
				<span class="swiftling-muted"><?php echo esc_html( number_format_i18n( $swiftling_counted ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php if ( '' !== $swiftling_type ) : ?>
		<input type="hidden" name="type" value="<?php echo esc_attr( $swiftling_type ); ?>">
	<?php endif; ?>
	<label class="screen-reader-text" for="swiftling-content-search"><?php esc_html_e( 'Search content', 'swiftling' ); ?></label>
	<input type="search" id="swiftling-content-search" class="swiftling-search" name="s" value="<?php echo esc_attr( $swiftling_search ); ?>" placeholder="<?php esc_attr_e( 'Search by title', 'swiftling' ); ?>">
</form>

<div class="swiftling-card is-flush">
	<div class="swiftling-table-wrap">
		<table class="swiftling-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Type', 'swiftling' ); ?></th>
					<?php foreach ( $swiftling_languages['targets'] as $swiftling_language ) : ?>
						<th class="is-center"><?php Ui::lang( $swiftling_language['code'] ); ?></th>
					<?php endforeach; ?>
					<th><?php esc_html_e( 'Changed', 'swiftling' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swiftling' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $swiftling_query->posts ) : ?>
					<tr><td colspan="<?php echo esc_attr( (string) ( 4 + count( $swiftling_languages['targets'] ) ) ); ?>" class="swiftling-muted"><?php esc_html_e( 'Nothing found.', 'swiftling' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $swiftling_query->posts as $swiftling_post ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( '' === $swiftling_post->post_title ? __( 'No title', 'swiftling' ) : $swiftling_post->post_title ); ?></strong>
							<?php if ( 'publish' !== $swiftling_post->post_status ) : ?>
								<span class="swiftling-muted"><?php echo esc_html( get_post_status_object( $swiftling_post->post_status )->label ); ?></span>
							<?php endif; ?>
							<br><code class="swiftling-muted"><?php echo esc_html( wp_make_link_relative( get_permalink( $swiftling_post ) ) ); ?></code>
						</td>
						<td><?php echo esc_html( get_post_type_object( $swiftling_post->post_type )->labels->singular_name ); ?></td>
						<?php foreach ( array_keys( $swiftling_languages['targets'] ) as $swiftling_slug ) : ?>
							<td class="is-center"><?php Ui::statusIcon( $swiftling_states[ $swiftling_post->ID ][ $swiftling_slug ] ); ?></td>
						<?php endforeach; ?>
						<td>
							<?php
							/* translators: %s: time span, e.g. 2 days. */
							echo esc_html( sprintf( __( '%s ago', 'swiftling' ), human_time_diff( get_post_modified_time( 'U', true, $swiftling_post ) ) ) );
							?>
						</td>
						<td class="is-numeric"><?php Ui::button( __( 'Translate', 'swiftling' ), 'secondary', swiftling_editor_url( $swiftling_post->ID ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<?php
$swiftling_pages = paginate_links(
	array(
		'base'    => add_query_arg( 'paged', '%#%' ),
		'format'  => '',
		'current' => $swiftling_paged,
		'total'   => $swiftling_query->max_num_pages,
	)
);
if ( $swiftling_pages ) :
	?>
	<p class="swiftling-pagination"><?php echo wp_kses_post( $swiftling_pages ); ?></p>
<?php endif; ?>

<ul class="swiftling-legend is-icons">
	<?php foreach ( $swiftling_legend as $swiftling_state => $swiftling_label ) : ?>
		<li><?php Ui::statusIcon( $swiftling_state ); ?> <?php echo esc_html( $swiftling_label ); ?></li>
	<?php endforeach; ?>
</ul>
