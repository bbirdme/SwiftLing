<?php
/**
 * Menus screen, one menu: its items by language, the original first. A tick means visitors see the
 * item in that language. A cell under an item left out in that language has no tick: the item goes
 * with the one above it. The form posts to swiftling_save_menu_items() in includes/menu-languages.php.
 *
 * Above the table, a panel says how it works until the person hides it, see
 * swiftling_save_menu_help(); a link opens it again.
 *
 * Expects $swiftling_items_menu and $swiftling_languages from admin/views/menus.php.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the redirect.
$swiftling_notice = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
$swiftling_list   = array_merge( array( $swiftling_languages['source'] ), array_values( $swiftling_languages['targets'] ) );
$swiftling_source = $swiftling_languages['source']['locale'];
$swiftling_items  = swiftling_menu_items( $swiftling_items_menu->term_id );
$swiftling_titles = array();
$swiftling_own    = array();
foreach ( $swiftling_items as $swiftling_item ) {
	$swiftling_titles[ (int) $swiftling_item->ID ] = (string) $swiftling_item->title;
	foreach ( $swiftling_list as $swiftling_language ) {
		$swiftling_own[ (int) $swiftling_item->ID ][ $swiftling_language['locale'] ] = swiftling_menu_item_shows( $swiftling_item->ID, $swiftling_language['locale'], $swiftling_source );
	}
}

// The item above an item that leaves itself out in a language, for each item and language.
$swiftling_parents = wp_list_pluck( $swiftling_items, 'menu_item_parent', 'ID' );
$swiftling_above   = static function ( $id, $locale ) use ( $swiftling_parents, $swiftling_own ) {
	for ( $at = (int) $swiftling_parents[ $id ]; $at && isset( $swiftling_own[ $at ] ); $at = (int) $swiftling_parents[ $at ] ) {
		if ( ! $swiftling_own[ $at ][ $locale ] ) {
			return $at;
		}
	}
	return 0;
};

Ui::screenHeader(
	array(
		'crumb'   => __( 'Menus', 'swiftling' ),
		/* translators: %s: name of a menu. */
		'title'   => sprintf( __( '%s: choose items', 'swiftling' ), $swiftling_items_menu->name ),
		'actions' => array(
			array( __( 'All menus', 'swiftling' ), 'secondary', admin_url( 'admin.php?page=swiftling-menus' ) ),
			array( __( 'Translate texts', 'swiftling' ), 'secondary', swiftling_editor_url( 'menu:' . $swiftling_items_menu->term_id ) ),
		),
	)
);
?>
<?php if ( 'saved' === $swiftling_notice ) : ?>
	<?php
	Ui::notice(
		'success',
		array(
			'title' => __( 'Saved.', 'swiftling' ),
			'text'  => __( 'Visitors see the menu this way in every language now.', 'swiftling' ),
		)
	);
	?>
<?php endif; ?>

<?php
$swiftling_menu_edit = admin_url( 'nav-menus.php?menu=' . $swiftling_items_menu->term_id );
$swiftling_example   = $swiftling_languages['targets'] ? reset( $swiftling_languages['targets'] )['code'] : '';
?>
<?php if ( ! get_user_meta( get_current_user_id(), SWIFTLING_MENU_HELP_HIDDEN, true ) ) : ?>
	<section class="swiftling-menu-help" aria-labelledby="swiftling-menu-help-title">
		<span class="swiftling-menu-help__bird" aria-hidden="true">
			<svg viewBox="0 0 64.86 36.57" focusable="false"><path fill="currentColor" d="<?php echo esc_attr( Ui::BIRD ); ?>"/></svg>
		</span>
		<div class="swiftling-menu-help__main">
			<div class="swiftling-menu-help__head">
				<h2 id="swiftling-menu-help-title"><?php esc_html_e( 'One menu, a different mix in each language', 'swiftling' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="swiftling_save_menu_help">
					<input type="hidden" name="show" value="0">
					<input type="hidden" name="menu" value="<?php echo esc_attr( (string) $swiftling_items_menu->term_id ); ?>">
					<?php wp_nonce_field( 'swiftling_menu_help' ); ?>
					<button type="submit" class="swiftling-menu-help__hide"><?php esc_html_e( 'Got it, hide this', 'swiftling' ); ?></button>
				</form>
			</div>
			<div class="swiftling-menu-help__tiles">
				<div class="swiftling-menu-help__tile">
					<span class="swiftling-menu-help__art is-ticks" aria-hidden="true"><i></i><i></i><i class="is-empty"></i><i></i><i class="is-empty"></i><i></i></span>
					<span>
						<strong><?php esc_html_e( 'Tick where visitors see it', 'swiftling' ); ?></strong>
						<?php esc_html_e( 'An empty box leaves the item out of the menu in that language.', 'swiftling' ); ?>
					</span>
				</div>
				<div class="swiftling-menu-help__tile">
					<span class="swiftling-menu-help__art is-tree" aria-hidden="true"><i></i><i></i><i></i></span>
					<span>
						<strong><?php esc_html_e( 'A submenu goes along', 'swiftling' ); ?></strong>
						<?php esc_html_e( 'Leave out an item, and the items under it are left out with it.', 'swiftling' ); ?>
					</span>
				</div>
				<div class="swiftling-menu-help__tile">
					<span class="swiftling-menu-help__art is-one" aria-hidden="true">+<?php echo esc_html( $swiftling_example ); ?></span>
					<span>
						<strong><?php esc_html_e( 'An item for one language', 'swiftling' ); ?></strong>
						<?php
						printf(
							/* translators: %s: link to Appearance → Menus. */
							esc_html__( 'Add it under %s with only that language ticked.', 'swiftling' ),
							'<a href="' . esc_url( $swiftling_menu_edit ) . '">' . esc_html__( 'Appearance → Menus', 'swiftling' ) . '</a>'
						);
						?>
					</span>
				</div>
			</div>
		</div>
	</section>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-menu-help-show">
		<input type="hidden" name="action" value="swiftling_save_menu_help">
		<input type="hidden" name="show" value="1">
		<input type="hidden" name="menu" value="<?php echo esc_attr( (string) $swiftling_items_menu->term_id ); ?>">
		<?php wp_nonce_field( 'swiftling_menu_help' ); ?>
		<button type="submit" class="swiftling-button is-link"><?php esc_html_e( 'How it works', 'swiftling' ); ?></button>
	</form>
<?php endif; ?>

<?php if ( ! $swiftling_items ) : ?>
	<?php Ui::emptyState( __( 'No items yet', 'swiftling' ), __( 'Once the menu has items, they show here.', 'swiftling' ), array( __( 'Build the menu', 'swiftling' ), $swiftling_menu_edit ) ); ?>
	<?php return; ?>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-card swiftling-menu-langs" data-swiftling-menu-langs>
	<input type="hidden" name="action" value="swiftling_save_menu_items">
	<input type="hidden" name="menu" value="<?php echo esc_attr( (string) $swiftling_items_menu->term_id ); ?>">
	<?php wp_nonce_field( 'swiftling_save_menu_items_' . $swiftling_items_menu->term_id ); ?>
	<div class="swiftling-table-wrap">
		<table class="swiftling-table swiftling-menu-langs__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'swiftling' ); ?></th>
					<?php foreach ( $swiftling_list as $swiftling_index => $swiftling_language ) : ?>
						<th class="is-center">
							<?php Ui::lang( $swiftling_language['code'], 0 === $swiftling_index ); ?>
							<span class="swiftling-menu-langs__name"><?php echo esc_html( $swiftling_language['name'] ); ?></span>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $swiftling_items as $swiftling_item ) : ?>
					<?php
					$swiftling_id    = (int) $swiftling_item->ID;
					$swiftling_page  = 'post_type' === $swiftling_item->type ? get_post( (int) $swiftling_item->object_id ) : null;
					$swiftling_cells = array();
					foreach ( $swiftling_list as $swiftling_language ) {
						$swiftling_cells[] = array(
							'language' => $swiftling_language,
							'above'    => $swiftling_above( $swiftling_id, $swiftling_language['locale'] ),
							'on'       => $swiftling_own[ $swiftling_id ][ $swiftling_language['locale'] ],
						);
					}
					?>
					<tr class="is-depth-<?php echo esc_attr( (string) min( 3, (int) $swiftling_item->depth ) ); ?>" data-item="<?php echo esc_attr( (string) $swiftling_id ); ?>" data-parent="<?php echo esc_attr( (string) (int) $swiftling_item->menu_item_parent ); ?>" data-name="<?php echo esc_attr( $swiftling_item->title ); ?>">
						<th scope="row">
							<input type="hidden" name="items[]" value="<?php echo esc_attr( (string) $swiftling_id ); ?>">
							<span class="swiftling-menu-langs__item"><?php echo esc_html( '' !== trim( $swiftling_item->title ) ? $swiftling_item->title : __( 'No title', 'swiftling' ) ); ?></span>
							<span class="swiftling-muted"><?php echo esc_html( (string) $swiftling_item->type_label ); ?></span>
						</th>
						<?php foreach ( $swiftling_cells as $swiftling_cell ) : ?>
							<?php $swiftling_locale = $swiftling_cell['language']['locale']; ?>
							<td class="is-center" data-locale="<?php echo esc_attr( $swiftling_locale ); ?>" data-language="<?php echo esc_attr( $swiftling_cell['language']['name'] ); ?>" data-own="<?php echo $swiftling_cell['on'] ? '1' : '0'; ?>">
								<?php if ( $swiftling_cell['above'] ) : ?>
									<span class="swiftling-menu-langs__locked">
										<?php
										/* translators: %s: name of the menu item above, e.g. Blog. */
										echo esc_html( sprintf( __( 'with %s', 'swiftling' ), $swiftling_titles[ $swiftling_cell['above'] ] ) );
										?>
									</span>
								<?php else : ?>
									<label class="swiftling-menu-langs__tick">
										<input type="checkbox" name="shown[<?php echo esc_attr( (string) $swiftling_id ); ?>][]" value="<?php echo esc_attr( $swiftling_locale ); ?>"<?php echo $swiftling_cell['on'] ? ' checked' : ''; ?>>
										<span class="screen-reader-text">
											<?php
											/* translators: 1: name of a menu item, 2: language name. */
											echo esc_html( sprintf( __( 'Show %1$s in %2$s', 'swiftling' ), $swiftling_item->title, $swiftling_cell['language']['name'] ) );
											?>
										</span>
									</label>
									<?php if ( $swiftling_cell['on'] && $swiftling_page && $swiftling_locale !== $swiftling_source && swiftling_is_hidden_post( $swiftling_page, $swiftling_locale ) ) : ?>
										<span class="swiftling-menu-langs__waiting"><?php esc_html_e( 'Hidden until its page is translated', 'swiftling' ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="swiftling-menu-langs__foot">
		<p class="swiftling-muted"><?php echo esc_html( sprintf( /* translators: %s: name of the original language, e.g. Hrvatski. */ __( 'A language you add later starts with the same items as %s.', 'swiftling' ), $swiftling_languages['source']['name'] ) ); ?></p>
		<span class="swiftling-menu-langs__unsaved" hidden><?php esc_html_e( 'Unsaved changes', 'swiftling' ); ?></span>
		<button type="submit" class="swiftling-button is-primary"><?php esc_html_e( 'Save', 'swiftling' ); ?></button>
	</div>
</form>
