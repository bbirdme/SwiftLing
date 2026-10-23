<?php
/**
 * Menus screen: every classic menu, made under Appearance → Menus, with where the theme shows it and
 * its state in every language. Each menu is translated in the editor, see includes/menus.php.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();

// A menu in the address: its table of items by language, see admin/views/menu-items.php.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen parameter.
$swiftling_items_menu = isset( $_GET['items'] ) ? wp_get_nav_menu_object( absint( wp_unslash( $_GET['items'] ) ) ) : false;
if ( $swiftling_items_menu instanceof \WP_Term && $swiftling_languages['targets'] && current_user_can( 'edit_theme_options' ) ) {
	include __DIR__ . '/menu-items.php';
	return;
}

$swiftling_legend = array(
	'reviewed' => __( 'Reviewed', 'swiftling' ),
	'machine'  => __( 'Machine translated', 'swiftling' ),
	'outdated' => __( 'Original changed since translation', 'swiftling' ),
	'partial'  => __( 'Partly translated: some items are still empty', 'swiftling' ),
	'missing'  => __( 'Not translated', 'swiftling' ),
);

Ui::screenHeader(
	array(
		'crumb'       => __( 'Translations', 'swiftling' ),
		'title'       => __( 'Menus', 'swiftling' ),
		'description' => __( 'The menus you build under Appearance → Menus. Translate the texts of their items, and choose which items each language shows.', 'swiftling' ),
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

$swiftling_menus = swiftling_can_list( 'edit_theme_options' ) ? swiftling_menus() : array();
if ( ! $swiftling_menus ) {
	if ( wp_is_block_theme() ) {
		Ui::emptyState(
			__( 'This theme has no classic menus', 'swiftling' ),
			__( 'This theme builds its menu with the Navigation block in the Site Editor. Its items are translated with the header, once for the whole site, in Templates.', 'swiftling' ),
			array( __( 'Open Templates', 'swiftling' ), admin_url( 'admin.php?page=swiftling-templates' ) )
		);
	} else {
		Ui::emptyState(
			__( 'No menus yet', 'swiftling' ),
			__( 'Once you build a menu under Appearance → Menus, it shows here to translate.', 'swiftling' ),
			array( __( 'Build a menu', 'swiftling' ), admin_url( 'nav-menus.php' ) )
		);
	}
	return;
}

$swiftling_places = swiftling_menu_locations();
$swiftling_states = swiftling_menu_states( $swiftling_menus );

?>
<div class="swiftling-stack">
	<section class="swiftling-card">
		<div class="swiftling-card__head">
			<h2><?php esc_html_e( 'Menus', 'swiftling' ); ?></h2>
		</div>
		<div class="swiftling-table-wrap">
			<table class="swiftling-table swiftling-menus-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Menu', 'swiftling' ); ?></th>
						<th><?php esc_html_e( 'Where visitors see it', 'swiftling' ); ?></th>
						<th class="swiftling-menus-table__group is-texts">
							<span class="swiftling-menus-table__name"><?php esc_html_e( 'Texts', 'swiftling' ); ?></span>
							<span class="swiftling-menus-table__what"><?php esc_html_e( 'the labels of the items, in each language', 'swiftling' ); ?></span>
						</th>
						<th class="swiftling-menus-table__group is-items">
							<span class="swiftling-menus-table__name"><?php esc_html_e( 'Items', 'swiftling' ); ?></span>
							<span class="swiftling-menus-table__what"><?php esc_html_e( 'which ones each language shows', 'swiftling' ); ?></span>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $swiftling_menus as $swiftling_menu ) : ?>
						<?php
						$swiftling_key    = 'menu:' . $swiftling_menu->term_id;
						$swiftling_items  = swiftling_menu_items( $swiftling_menu->term_id );
						$swiftling_titles = count(
							array_filter(
								$swiftling_items,
								static function ( $item ): bool {
									return '' === trim( $item->post_title ) && 'post_type' === $item->type;
								}
							)
						);
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $swiftling_menu->name ); ?></strong>
								<?php if ( $swiftling_items ) : ?>
									<br><span class="swiftling-muted">
										<?php
										/* translators: %s: number of menu items. */
										echo esc_html( sprintf( _n( '%s item', '%s items', count( $swiftling_items ), 'swiftling' ), number_format_i18n( count( $swiftling_items ) ) ) );
										if ( $swiftling_titles ) {
											echo ' · ';
											/* translators: %s: number of menu items. */
											echo esc_html( sprintf( _n( '%s shows a page’s title', '%s show a page’s title', $swiftling_titles, 'swiftling' ), number_format_i18n( $swiftling_titles ) ) );
										}
										?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( isset( $swiftling_places[ $swiftling_menu->term_id ] ) ) : ?>
									<span class="swiftling-menu-places">
										<?php foreach ( $swiftling_places[ $swiftling_menu->term_id ] as $swiftling_place ) : ?>
											<span class="swiftling-chip"><?php echo esc_html( $swiftling_place ); ?></span>
										<?php endforeach; ?>
									</span>
								<?php else : ?>
									<span class="swiftling-muted"><?php esc_html_e( 'Not placed in the theme. If a widget or a builder shows it, translate it anyway.', 'swiftling' ); ?></span>
								<?php endif; ?>
							</td>
							<?php if ( ! $swiftling_items ) : ?>
								<td colspan="2" class="swiftling-muted swiftling-menus-table__texts"><?php esc_html_e( 'No items yet', 'swiftling' ); ?></td>
							<?php else : ?>
								<td class="swiftling-menus-table__texts">
									<?php // One cell for every language, so the table keeps its width however many there are. ?>
									<span class="swiftling-menus-table__languages">
										<?php foreach ( $swiftling_languages['targets'] as $swiftling_slug => $swiftling_language ) : ?>
											<span class="swiftling-menus-table__language"><?php Ui::statusIcon( $swiftling_states[ $swiftling_key ][ $swiftling_slug ] ); ?><?php echo esc_html( $swiftling_language['code'] ); ?></span>
										<?php endforeach; ?>
									</span>
									<a class="swiftling-button is-primary" href="<?php echo esc_url( swiftling_editor_url( $swiftling_key ) ); ?>"><?php esc_html_e( 'Translate texts', 'swiftling' ); ?></a>
								</td>
								<td class="swiftling-menus-table__items">
									<?php $swiftling_differing = swiftling_menu_differing_count( $swiftling_menu->term_id ); ?>
									<?php if ( $swiftling_differing ) : ?>
										<strong>
											<?php
											/* translators: %s: number of menu items. */
											echo esc_html( sprintf( _n( '%s item not in every language', '%s items not in every language', $swiftling_differing, 'swiftling' ), number_format_i18n( $swiftling_differing ) ) );
											?>
										</strong>
									<?php else : ?>
										<span class="swiftling-muted"><?php esc_html_e( 'Same items in every language', 'swiftling' ); ?></span>
									<?php endif; ?>
									<a class="swiftling-button is-secondary" href="<?php echo esc_url( swiftling_menu_items_url( $swiftling_menu->term_id ) ); ?>"><?php esc_html_e( 'Choose items', 'swiftling' ); ?></a>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
</div>

<ul class="swiftling-legend is-icons">
	<?php foreach ( $swiftling_legend as $swiftling_state => $swiftling_label ) : ?>
		<li><?php Ui::statusIcon( $swiftling_state ); ?> <?php echo esc_html( $swiftling_label ); ?></li>
	<?php endforeach; ?>
</ul>
