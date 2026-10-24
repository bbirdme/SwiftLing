<?php
/**
 * Templates screen: what pages show around the content of posts, and texts builders keep outside
 * posts, such as the options pages of Advanced Custom Fields. Categories and tags have a screen of
 * their own, see terms.php.
 *
 * A block theme lists the templates of the Site Editor; a classic theme the kinds of pages it
 * shows, such as search results and the page for an address that does not exist. Each is
 * translated on an example page made from it, see includes/subjects.php. The header, footer,
 * menu and site title show in every template and are translated once, in any of them.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();
$swiftling_block     = wp_is_block_theme();
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
		'title'       => __( 'Templates', 'swiftling' ),
		'description' => $swiftling_block
			? __( 'What every page shows around its content: the header, the footer, the menu, the site title and the texts of each template, such as the heading of search results. The header, footer and menu are the same in every template, so they are translated once.', 'swiftling' )
			: __( 'What every kind of page shows around its content: the header, the footer, the menu, the site title and texts of the theme, such as the heading of search results. The header, footer and menu are the same everywhere, so they are translated once.', 'swiftling' ),
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

$swiftling_views = swiftling_can_list( 'edit_theme_options' ) ? swiftling_site_views() : array();
// The header and the footer, each a row of its own, translated once for every page.
$swiftling_parts        = $swiftling_views ? swiftling_site_parts() : array();
$swiftling_part_example = swiftling_site_part_example();
// Texts builders keep outside posts, such as ACF's options pages, each a subject of the editor;
// only those the user may translate.
$swiftling_builders = array();
foreach ( \SwiftLing\Builders\Registry::subjects() as $swiftling_builder => $swiftling_groups ) {
	foreach ( $swiftling_groups as $swiftling_group ) {
		$swiftling_group['subjects'] = array_values(
			array_filter(
				$swiftling_group['subjects'],
				static function ( $entry ): bool {
					$subject = swiftling_subject( $entry['key'] );
					return $subject && swiftling_can_translate_subject( $subject );
				}
			)
		);
		if ( $swiftling_group['subjects'] ) {
			$swiftling_builders[ $swiftling_builder ][] = $swiftling_group;
		}
	}
}
$swiftling_keys = array_merge(
	array_map(
		static function ( $part ): string {
			return 'site:' . $part;
		},
		array_keys( $swiftling_parts )
	),
	wp_list_pluck( $swiftling_views, 'key' )
);
foreach ( $swiftling_builders as $swiftling_groups ) {
	foreach ( $swiftling_groups as $swiftling_group ) {
		$swiftling_keys = array_merge( $swiftling_keys, wp_list_pluck( $swiftling_group['subjects'], 'key' ) );
	}
}
$swiftling_states  = swiftling_object_states( $swiftling_keys );
$swiftling_columns = 4 + count( $swiftling_languages['targets'] );
?>
<div class="swiftling-stack">
<?php if ( $swiftling_parts ) : ?>
<section class="swiftling-card">
	<div class="swiftling-card__head">
		<h2><?php esc_html_e( 'Header and footer', 'swiftling' ); ?></h2>
	</div>
	<div class="swiftling-table-wrap">
		<table class="swiftling-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Part', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Checked on', 'swiftling' ); ?></th>
					<?php foreach ( $swiftling_languages['targets'] as $swiftling_language ) : ?>
						<th class="is-center"><?php Ui::lang( $swiftling_language['code'] ); ?></th>
					<?php endforeach; ?>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swiftling' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $swiftling_parts as $swiftling_part => $swiftling_part_name ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $swiftling_part_name ); ?></strong>
							<br><span class="swiftling-muted"><?php esc_html_e( 'The same on every page, translated once', 'swiftling' ); ?></span>
						</td>
						<td>
							<?php echo esc_html( $swiftling_part_example['label'] ); ?>
							<br><code class="swiftling-muted"><?php echo esc_html( wp_make_link_relative( $swiftling_part_example['url'] ) ); ?></code>
						</td>
						<?php foreach ( array_keys( $swiftling_languages['targets'] ) as $swiftling_slug ) : ?>
							<td class="is-center"><?php Ui::statusIcon( $swiftling_states[ 'site:' . $swiftling_part ][ $swiftling_slug ] ); ?></td>
						<?php endforeach; ?>
						<td class="is-numeric"><?php Ui::button( __( 'Translate', 'swiftling' ), 'secondary', swiftling_editor_url( 'site:' . $swiftling_part ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>
<section class="swiftling-card">
	<div class="swiftling-card__head">
		<h2><?php echo esc_html( $swiftling_block ? __( 'Templates', 'swiftling' ) : __( 'Kinds of pages', 'swiftling' ) ); ?></h2>
	</div>
	<div class="swiftling-table-wrap">
		<table class="swiftling-table">
			<thead>
				<tr>
					<th><?php echo esc_html( $swiftling_block ? __( 'Template', 'swiftling' ) : __( 'Kind of page', 'swiftling' ) ); ?></th>
					<th><?php esc_html_e( 'Checked on', 'swiftling' ); ?></th>
					<?php foreach ( $swiftling_languages['targets'] as $swiftling_language ) : ?>
						<th class="is-center"><?php Ui::lang( $swiftling_language['code'] ); ?></th>
					<?php endforeach; ?>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swiftling' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $swiftling_views ) : ?>
					<tr><td colspan="<?php echo esc_attr( (string) $swiftling_columns ); ?>" class="swiftling-muted"><?php esc_html_e( 'You may not edit the design of this site, so you cannot translate its templates either.', 'swiftling' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $swiftling_views as $swiftling_view ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $swiftling_view['title'] ); ?></strong>
							<?php if ( '' !== $swiftling_view['description'] ) : ?>
								<br><span class="swiftling-muted"><?php echo esc_html( $swiftling_view['description'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( '' === $swiftling_view['url'] ) : ?>
								<span class="swiftling-muted"><?php esc_html_e( 'No page shows it', 'swiftling' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $swiftling_view['example'] ); ?>
								<br><code class="swiftling-muted"><?php echo esc_html( wp_make_link_relative( $swiftling_view['url'] ) ); ?></code>
								<?php if ( count( $swiftling_view['uses'] ) > 1 ) : ?>
									<br><span class="swiftling-muted">
										<?php
										/* translators: %s: kinds of pages, e.g. Tag, Author and Posts of a month. */
										echo esc_html( sprintf( __( 'Also shows: %s', 'swiftling' ), wp_sprintf( '%l', array_slice( $swiftling_view['uses'], 1 ) ) ) );
										?>
									</span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<?php foreach ( array_keys( $swiftling_languages['targets'] ) as $swiftling_slug ) : ?>
							<td class="is-center">
								<?php
								if ( '' !== $swiftling_view['url'] ) {
									Ui::statusIcon( $swiftling_states[ $swiftling_view['key'] ][ $swiftling_slug ] );
								}
								?>
							</td>
						<?php endforeach; ?>
						<td class="is-numeric">
							<?php
							if ( '' !== $swiftling_view['url'] ) {
								Ui::button( __( 'Translate', 'swiftling' ), 'secondary', swiftling_editor_url( $swiftling_view['key'] ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<?php foreach ( $swiftling_builders as $swiftling_builder => $swiftling_groups ) : ?>
	<section class="swiftling-card">
		<div class="swiftling-card__head">
			<h2><?php echo esc_html( $swiftling_builder ); ?></h2>
		</div>
		<div class="swiftling-table-wrap">
			<table class="swiftling-table">
				<?php foreach ( $swiftling_groups as $swiftling_group ) : ?>
					<thead>
						<tr>
							<th>
								<?php echo esc_html( $swiftling_group['title'] ); ?>
								<br><span class="swiftling-muted"><?php echo esc_html( $swiftling_group['description'] ); ?></span>
							</th>
							<?php foreach ( $swiftling_languages['targets'] as $swiftling_language ) : ?>
								<th class="is-center"><?php Ui::lang( $swiftling_language['code'] ); ?></th>
							<?php endforeach; ?>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swiftling' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $swiftling_group['subjects'] as $swiftling_entry ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $swiftling_entry['title'] ); ?></strong>
									<?php if ( '' !== $swiftling_entry['description'] ) : ?>
										<br><span class="swiftling-muted"><?php echo esc_html( $swiftling_entry['description'] ); ?></span>
									<?php endif; ?>
								</td>
								<?php foreach ( array_keys( $swiftling_languages['targets'] ) as $swiftling_slug ) : ?>
									<td class="is-center"><?php Ui::statusIcon( $swiftling_states[ $swiftling_entry['key'] ][ $swiftling_slug ] ); ?></td>
								<?php endforeach; ?>
								<td class="is-numeric"><?php Ui::button( __( 'Translate', 'swiftling' ), 'secondary', swiftling_editor_url( $swiftling_entry['key'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				<?php endforeach; ?>
			</table>
		</div>
	</section>
<?php endforeach; ?>
</div>

<ul class="swiftling-legend is-icons">
	<?php foreach ( $swiftling_legend as $swiftling_state => $swiftling_label ) : ?>
		<li><?php Ui::statusIcon( $swiftling_state ); ?> <?php echo esc_html( $swiftling_label ); ?></li>
	<?php endforeach; ?>
</ul>
