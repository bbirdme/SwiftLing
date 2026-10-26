<?php
/**
 * Language switcher screen, in three steps: where visitors find the switcher (the menus and the end
 * of every page it joins, and the other places it can go), what it shows (with the flags of the
 * languages), and what happens for a visitor whose browser prefers another language; with a
 * preview alongside. Choices that wait on another, such as the place of the note, show with CSS
 * alone.
 *
 * The form posts to SwiftLing\Switcher\Settings::save(). The preview is the switcher itself, as the
 * site prints it; assets/js/switcher-settings.js follows the choices in it before they are saved.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;
use SwiftLing\Switcher\Block;
use SwiftLing\Switcher\Settings;
use SwiftLing\Switcher\Suggestion;
use SwiftLing\Switcher\Switcher;

defined( 'ABSPATH' ) || exit;

Ui::screenHeader(
	array(
		'crumb'       => __( 'Settings', 'swiftling' ),
		'title'       => __( 'Language switcher', 'swiftling' ),
		'description' => __( 'How visitors change the language. It takes the fonts and colours of your theme.', 'swiftling' ),
	)
);

$swiftling_languages = swiftling_languages();
if ( ! $swiftling_languages['targets'] ) {
	Ui::emptyState(
		__( 'No language to switch to yet', 'swiftling' ),
		__( 'Add a language the site is translated into, and the switcher appears wherever you put it.', 'swiftling' ),
		array( __( 'Manage languages', 'swiftling' ), admin_url( 'admin.php?page=swiftling-languages' ) )
	);
	return;
}

$swiftling_settings = Settings::get();
$swiftling_all      = array_merge( array( $swiftling_languages['source'] ), array_values( $swiftling_languages['targets'] ) );
$swiftling_menus    = wp_get_nav_menus();
$swiftling_places   = get_registered_nav_menus();
$swiftling_located  = get_nav_menu_locations();
// Where the Language switcher block sits: in a block theme, its menus are the Navigation block's.
$swiftling_blocks = Block::places();
$swiftling_hidden = wp_list_filter( $swiftling_languages['targets'], array( 'visible' => false ) );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the redirect.
$swiftling_saved = isset( $_GET['swiftling_notice'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) );

$swiftling_show_labels = array(
	'flag'    => array( __( 'Flag', 'swiftling' ), __( 'Flags stand for countries, not languages. Choose them in step 3.', 'swiftling' ) ),
	'name'    => array( __( 'Name', 'swiftling' ), __( 'In its own language, in the language of the page, or both: see Language names on the Languages screen.', 'swiftling' ) ),
	'code'    => array( __( 'Code, such as DE', 'swiftling' ), __( 'Short codes such as DE.', 'swiftling' ) ),
	'current' => array( __( 'The language shown, in a list', 'swiftling' ), __( 'A dropdown always shows it as its button.', 'swiftling' ) ),
);
$swiftling_styles      = array(
	'dropdown'   => __( 'Dropdown', 'swiftling' ),
	'horizontal' => __( 'Horizontal list', 'swiftling' ),
	'vertical'   => __( 'Vertical list', 'swiftling' ),
);
// Step 2 has a tab for each place with a style and a "show" of its own; the form posts each under
// its own name. A block, widget or shortcode chooses its style itself.
$swiftling_show_places = array(
	'menu'      => array(
		'label'  => __( 'In the menu', 'swiftling' ),
		'field'  => 'menu[show]',
		'show'   => $swiftling_settings['menu']['show'],
		'styles' => array(
			'dropdown' => array( __( 'Dropdown', 'swiftling' ), __( 'The others open under the language shown.', 'swiftling' ) ),
			'list'     => array( __( 'One item per language', 'swiftling' ), __( 'Side by side, like the other items.', 'swiftling' ) ),
			'globe'    => array( __( 'Globe', 'swiftling' ), __( 'A globe that leads nowhere: a click or a tap opens the languages.', 'swiftling' ) ),
		),
	),
	'footer'    => array(
		'label'  => __( 'At the end of the page', 'swiftling' ),
		'field'  => 'footer[show]',
		'show'   => $swiftling_settings['footer']['show'],
		'styles' => array(
			'horizontal' => array( __( 'Horizontal list', 'swiftling' ), __( 'One row, in the middle.', 'swiftling' ) ),
			'vertical'   => array( __( 'Vertical list', 'swiftling' ), __( 'One language under another.', 'swiftling' ) ),
			'dropdown'   => array( __( 'Dropdown', 'swiftling' ), __( 'The others open from the language shown.', 'swiftling' ) ),
		),
	),
	'elsewhere' => array(
		'label'  => __( 'Block, widget, shortcode', 'swiftling' ),
		'field'  => 'show',
		'show'   => $swiftling_settings['show'],
		'styles' => array(),
	),
);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which tab opens, after the redirect.
$swiftling_tab = isset( $_GET['swiftling_tab'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_tab'] ) ) : '';
$swiftling_tab = isset( $swiftling_show_places[ $swiftling_tab ] ) ? $swiftling_tab : 'menu';

$swiftling_flag_sizes        = array(
	'small'  => __( 'Small', 'swiftling' ),
	'medium' => __( 'Medium', 'swiftling' ),
	'large'  => __( 'Large', 'swiftling' ),
);
$swiftling_suggest_positions = array(
	'bar'   => array( __( 'Bar along the bottom', 'swiftling' ), __( 'Across the whole window.', 'swiftling' ) ),
	'right' => array( __( 'Card, bottom right', 'swiftling' ), __( 'Above a floating switcher in the same corner.', 'swiftling' ) ),
	'left'  => array( __( 'Card, bottom left', 'swiftling' ), __( 'Above a floating switcher in the same corner.', 'swiftling' ) ),
);
?>
<?php if ( $swiftling_saved ) : ?>
	<?php Ui::notice( 'success', array( 'title' => __( 'Switcher saved.', 'swiftling' ) ) ); ?>
<?php endif; ?>
<?php if ( $swiftling_hidden ) : ?>
	<?php
	Ui::notice(
		'info',
		array(
			/* translators: %s: names of languages, e.g. Deutsch, Dansk. */
			'text' => sprintf( __( 'Hidden languages are in the switcher only for people who can edit content, faded: %s.', 'swiftling' ), implode( ', ', wp_list_pluck( $swiftling_hidden, 'name' ) ) ),
		)
	);
	?>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-switcher-form">
	<input type="hidden" name="action" value="swiftling_save_switcher">
	<?php wp_nonce_field( 'swiftling_save_switcher' ); ?>

	<div class="swiftling-switcher-layout">
		<div class="swiftling-stack">
			<section class="swiftling-card swiftling-step">
				<header class="swiftling-step__head">
					<span class="swiftling-step__number" aria-hidden="true">1</span>
					<div>
						<h2><?php esc_html_e( 'Where visitors find it', 'swiftling' ); ?></h2>
						<p class="swiftling-muted"><?php esc_html_e( 'Choose the places. What the switcher shows and how it looks comes in step 2.', 'swiftling' ); ?></p>
					</div>
				</header>

				<div class="swiftling-switcher-where is-menu">
					<label class="swiftling-switcher-where__head">
						<svg class="swiftling-switcher-where__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
						<span class="swiftling-switcher-where__text">
							<strong><?php esc_html_e( 'In a menu', 'swiftling' ); ?></strong>
							<span class="swiftling-muted"><?php esc_html_e( 'The languages join the menus you tick, as their first or last items. The menus stay as they are on the Menus screen, and a new language shows up on its own.', 'swiftling' ); ?></span>
						</span>
						<input type="checkbox" class="swiftling-toggle" name="menu[enabled]" value="1" <?php checked( $swiftling_settings['menu']['enabled'] ); ?>>
					</label>
					<div class="swiftling-switcher-where__body">
					<?php if ( $swiftling_menus ) : ?>
						<fieldset class="swiftling-picks">
							<legend class="screen-reader-text"><?php esc_html_e( 'Menus', 'swiftling' ); ?></legend>
							<?php foreach ( $swiftling_menus as $swiftling_menu ) : ?>
								<?php
								$swiftling_where = array();
								foreach ( $swiftling_located as $swiftling_location => $swiftling_menu_id ) {
									if ( (int) $swiftling_menu_id === (int) $swiftling_menu->term_id && isset( $swiftling_places[ $swiftling_location ] ) ) {
										$swiftling_where[] = $swiftling_places[ $swiftling_location ];
									}
								}
								?>
								<label class="swiftling-pick">
									<input type="checkbox" name="menu[menus][]" value="<?php echo esc_attr( (string) $swiftling_menu->term_id ); ?>" <?php checked( in_array( (int) $swiftling_menu->term_id, $swiftling_settings['menu']['menus'], true ) ); ?>>
									<span>
										<strong><?php echo esc_html( $swiftling_menu->name ); ?></strong>
										<span class="swiftling-muted">
											<?php
											echo esc_html(
												$swiftling_where
													/* translators: %s: menu locations of the theme, e.g. Header, Footer. */
													? sprintf( __( 'Shown in: %s', 'swiftling' ), implode( ', ', $swiftling_where ) )
													: __( 'Not in a place of the theme; a block or widget may still show it.', 'swiftling' )
											);
											?>
										</span>
									</span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<div class="swiftling-switcher-fields">
							<fieldset>
								<legend><?php esc_html_e( 'Position', 'swiftling' ); ?></legend>
								<span class="swiftling-segmented swiftling-radios">
									<label><input type="radio" name="menu[position]" value="first" <?php checked( 'first', $swiftling_settings['menu']['position'] ); ?>> <?php esc_html_e( 'First item', 'swiftling' ); ?></label>
									<label><input type="radio" name="menu[position]" value="last" <?php checked( 'last', $swiftling_settings['menu']['position'] ); ?>> <?php esc_html_e( 'Last item', 'swiftling' ); ?></label>
								</span>
							</fieldset>
						</div>
					<?php elseif ( $swiftling_blocks ) : ?>
						<?php
						Ui::notice(
							'info',
							array(
								/* translators: %l: names of menus and template parts, e.g. Header and Footer. */
								'text'    => wp_sprintf( __( 'The Language switcher block is in: %l. Its look is set in its sidebar in the Site Editor.', 'swiftling' ), $swiftling_blocks ),
								'compact' => true,
							)
						);
						?>
					<?php else : ?>
						<?php
						Ui::notice(
							'info',
							array(
								'html'    => current_theme_supports( 'menus' )
									? sprintf(
										/* translators: %s: link to the Menus screen. */
										esc_html__( 'The site has no menus yet. Make one on the %s screen and come back to add the languages to it.', 'swiftling' ),
										'<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Menus', 'swiftling' ) . '</a>'
									)
									: esc_html__( 'Your theme keeps its menus in the Navigation block. Open it in the Site Editor and add the Language switcher block to it.', 'swiftling' ),
								'compact' => true,
							)
						);
						?>
					<?php endif; ?>
					<?php if ( $swiftling_menus && wp_is_block_theme() ) : ?>
						<?php
						Ui::notice(
							'info',
							array(
								'text'    => $swiftling_blocks
									/* translators: %l: names of menus and template parts, e.g. Header and Footer. */
									? wp_sprintf( __( 'Your theme keeps its menus in the Navigation block, which does not use these menus. The Language switcher block is in: %l.', 'swiftling' ), $swiftling_blocks )
									: __( 'Your theme keeps its menus in the Navigation block, which does not use these menus. Add the Language switcher block to the Navigation block in the Site Editor instead.', 'swiftling' ),
								'compact' => true,
							)
						);
						?>
					<?php endif; ?>
					</div>
				</div>

				<div class="swiftling-switcher-where is-footer">
					<label class="swiftling-switcher-where__head">
						<svg class="swiftling-switcher-where__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 16h16"/></svg>
						<span class="swiftling-switcher-where__text">
							<strong><?php esc_html_e( 'At the end of every page', 'swiftling' ); ?></strong>
							<span class="swiftling-muted"><?php esc_html_e( 'Below the footer of the theme, or floating in a corner while visitors scroll.', 'swiftling' ); ?></span>
						</span>
						<input type="checkbox" class="swiftling-toggle" name="footer[enabled]" value="1" <?php checked( $swiftling_settings['footer']['enabled'] ); ?>>
					</label>
					<div class="swiftling-switcher-where__body swiftling-switcher-footer">
						<fieldset class="swiftling-tiles">
							<legend class="screen-reader-text"><?php esc_html_e( 'Position', 'swiftling' ); ?></legend>
							<?php
							$swiftling_footer_tiles = array(
								'end'            => array( __( 'Below the footer', 'swiftling' ), __( 'A row across the end of the page.', 'swiftling' ) ),
								'floating-right' => array( __( 'Floating, bottom right', 'swiftling' ), __( 'A small dropdown in the corner.', 'swiftling' ) ),
								'floating-left'  => array( __( 'Floating, bottom left', 'swiftling' ), __( 'The same, on the left.', 'swiftling' ) ),
							);
							?>
							<?php foreach ( $swiftling_footer_tiles as $swiftling_value => $swiftling_tile ) : ?>
								<label class="swiftling-tile">
									<input type="radio" name="footer[position]" value="<?php echo esc_attr( $swiftling_value ); ?>" <?php checked( $swiftling_value, $swiftling_settings['footer']['position'] ); ?>>
									<span class="swiftling-tile__art is-footer-<?php echo esc_attr( $swiftling_value ); ?>" aria-hidden="true"><span></span></span>
									<strong><?php echo esc_html( $swiftling_tile[0] ); ?></strong>
									<span class="swiftling-muted"><?php echo esc_html( $swiftling_tile[1] ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</div>
				</div>

				<div class="swiftling-switcher-where is-elsewhere">
					<div class="swiftling-switcher-where__head">
						<svg class="swiftling-switcher-where__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>
						<span class="swiftling-switcher-where__text">
							<strong><?php esc_html_e( 'Anywhere else', 'swiftling' ); ?></strong>
							<span class="swiftling-muted"><?php esc_html_e( 'Always there: put it where you want it.', 'swiftling' ); ?></span>
						</span>
					</div>
					<div class="swiftling-switcher-where__body">
						<dl class="swiftling-switcher-places">
						<dt><?php esc_html_e( 'Block', 'swiftling' ); ?></dt>
						<dd><?php esc_html_e( 'Language switcher, in the block editor, the Site Editor, inside the Navigation block and in widget areas. Its sidebar has its own style and what it shows.', 'swiftling' ); ?></dd>
						<dt><?php esc_html_e( 'Widget', 'swiftling' ); ?></dt>
						<dd><?php esc_html_e( 'Language switcher, in the sidebars of classic themes and among the WordPress widgets of page builders such as Elementor.', 'swiftling' ); ?></dd>
						<dt><?php esc_html_e( 'Shortcode', 'swiftling' ); ?></dt>
						<dd>
							<code>[swiftling_switcher]</code>
							<code>[swiftling_switcher style="horizontal" flag="yes" code="yes"]</code>
							<code>[swiftling_switcher flag="yes" flag_size="large"]</code>
						</dd>
						<dt><?php esc_html_e( 'PHP, in a theme', 'swiftling' ); ?></dt>
						<dd>
							<code>&lt;?php do_action( 'swiftling_language_switcher' ); ?&gt;</code>
							<code>&lt;?php do_action( 'swiftling_language_switcher', array( 'style' =&gt; 'vertical', 'flag' =&gt; true ) ); ?&gt;</code>
							<span class="swiftling-muted"><?php esc_html_e( 'Styles: dropdown, horizontal, vertical. Options: flag, name, code, current, class, flag_size (small, medium, large). The action prints nothing while SwiftLing is inactive.', 'swiftling' ); ?></span>
						</dd>
					</dl>
					</div>
				</div>
			</section>

			<section class="swiftling-card swiftling-step">
				<header class="swiftling-step__head">
					<span class="swiftling-step__number" aria-hidden="true">2</span>
					<div>
						<h2><?php esc_html_e( 'How it looks', 'swiftling' ); ?></h2>
						<p class="swiftling-muted"><?php esc_html_e( 'Each place has its own style and parts, such as flags in the menu and names below the footer. It takes the fonts and colours of your theme.', 'swiftling' ); ?></p>
					</div>
				</header>

				<input type="hidden" name="tab" value="<?php echo esc_attr( $swiftling_tab ); ?>" data-swiftling-tab-field>
				<div class="swiftling-segmented swiftling-switcher-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Place', 'swiftling' ); ?>">
					<?php foreach ( $swiftling_show_places as $swiftling_place => $swiftling_place_info ) : ?>
						<?php $swiftling_selected_tab = $swiftling_place === $swiftling_tab; ?>
						<button type="button" role="tab" id="swiftling-tab-<?php echo esc_attr( $swiftling_place ); ?>" class="<?php echo $swiftling_selected_tab ? 'is-current' : ''; ?>" aria-selected="<?php echo $swiftling_selected_tab ? 'true' : 'false'; ?>" aria-controls="swiftling-place-<?php echo esc_attr( $swiftling_place ); ?>" tabindex="<?php echo $swiftling_selected_tab ? '0' : '-1'; ?>" data-swiftling-tab="<?php echo esc_attr( $swiftling_place ); ?>"><?php echo esc_html( $swiftling_place_info['label'] ); ?></button>
					<?php endforeach; ?>
				</div>

				<?php foreach ( $swiftling_show_places as $swiftling_place => $swiftling_place_info ) : ?>
					<div class="swiftling-switcher-place" id="swiftling-place-<?php echo esc_attr( $swiftling_place ); ?>" role="tabpanel" aria-labelledby="swiftling-tab-<?php echo esc_attr( $swiftling_place ); ?>" data-swiftling-place="<?php echo esc_attr( $swiftling_place ); ?>" <?php echo $swiftling_place === $swiftling_tab ? '' : 'hidden'; ?>>
						<?php if ( 'menu' === $swiftling_place ) : ?>
							<p class="swiftling-switcher-place__off is-menu"><?php esc_html_e( 'No menu shows the switcher: switch it on and tick a menu in step 1.', 'swiftling' ); ?></p>
						<?php elseif ( 'footer' === $swiftling_place ) : ?>
							<p class="swiftling-switcher-place__off is-footer"><?php esc_html_e( 'The switcher at the end of the page is off in step 1.', 'swiftling' ); ?></p>
						<?php endif; ?>

						<?php if ( $swiftling_place_info['styles'] ) : ?>
							<fieldset class="swiftling-switcher-style swiftling-switcher-<?php echo esc_attr( $swiftling_place ); ?>-style">
								<legend><?php esc_html_e( 'Style', 'swiftling' ); ?></legend>
								<span class="swiftling-tiles">
									<?php foreach ( $swiftling_place_info['styles'] as $swiftling_style => $swiftling_tile ) : ?>
										<label class="swiftling-tile">
											<input type="radio" name="<?php echo esc_attr( $swiftling_place . '[style]' ); ?>" value="<?php echo esc_attr( $swiftling_style ); ?>" <?php checked( $swiftling_style, $swiftling_settings[ $swiftling_place ]['style'] ); ?>>
											<span class="swiftling-tile__art is-style is-style-<?php echo esc_attr( $swiftling_style ); ?>" aria-hidden="true">
												<?php if ( 'globe' === $swiftling_style ) : ?>
													<?php echo wp_kses( Switcher::globe( 22 ), Ui::ICON_HTML ); ?>
												<?php else : ?>
													<i></i><i></i><i></i>
												<?php endif; ?>
											</span>
											<strong><?php echo esc_html( $swiftling_tile[0] ); ?></strong>
											<span class="swiftling-muted"><?php echo esc_html( $swiftling_tile[1] ); ?></span>
										</label>
									<?php endforeach; ?>
								</span>
							</fieldset>
						<?php endif; ?>
						<?php if ( 'footer' === $swiftling_place ) : ?>
							<p class="swiftling-muted swiftling-switcher-floating-note"><?php esc_html_e( 'Floating in a corner, it is a dropdown, so it covers little of the page.', 'swiftling' ); ?></p>
						<?php elseif ( 'elsewhere' === $swiftling_place ) : ?>
							<p class="swiftling-muted swiftling-switcher-place__note"><?php esc_html_e( 'Each block, widget and shortcode chooses its own style, and can choose what it shows as well. This is what they show when they do not.', 'swiftling' ); ?></p>
						<?php endif; ?>

						<fieldset class="swiftling-switcher-shows">
							<legend><?php esc_html_e( 'Shows', 'swiftling' ); ?></legend>
							<span class="swiftling-chips">
								<?php foreach ( $swiftling_show_labels as $swiftling_part => $swiftling_label ) : ?>
									<label class="swiftling-chip-toggle" data-swiftling-part="<?php echo esc_attr( $swiftling_part ); ?>" title="<?php echo esc_attr( $swiftling_label[1] ); ?>">
										<input type="checkbox" name="<?php echo esc_attr( $swiftling_place_info['field'] . '[' . $swiftling_part . ']' ); ?>" value="1" data-swiftling-show="<?php echo esc_attr( $swiftling_part ); ?>" <?php checked( $swiftling_place_info['show'][ $swiftling_part ] ); ?>>
										<?php echo esc_html( $swiftling_label[0] ); ?>
									</label>
								<?php endforeach; ?>
							</span>
						</fieldset>

						<fieldset class="swiftling-switcher-flag-size">
							<legend><?php esc_html_e( 'Flag size', 'swiftling' ); ?></legend>
							<span class="swiftling-segmented swiftling-radios">
								<?php foreach ( $swiftling_flag_sizes as $swiftling_size => $swiftling_label ) : ?>
									<label><input type="radio" name="<?php echo esc_attr( $swiftling_place_info['field'] . '[flag_size]' ); ?>" value="<?php echo esc_attr( $swiftling_size ); ?>" data-swiftling-flag-size <?php checked( $swiftling_size, $swiftling_place_info['show']['flag_size'] ); ?>> <?php echo esc_html( $swiftling_label ); ?></label>
								<?php endforeach; ?>
							</span>
							<span class="swiftling-muted"><?php esc_html_e( 'Next to the text around it, so it grows with the font of your theme.', 'swiftling' ); ?></span>
						</fieldset>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="swiftling-card swiftling-step">
				<header class="swiftling-step__head">
					<span class="swiftling-step__number" aria-hidden="true">3</span>
					<div>
						<h2><?php esc_html_e( 'Flags', 'swiftling' ); ?></h2>
						<p class="swiftling-muted"><?php esc_html_e( 'Flags stand for countries, not languages. Each language shows the flag of the country in its locale; choose another country, no flag, or an image of your own.', 'swiftling' ); ?></p>
					</div>
				</header>
				<table class="swiftling-table swiftling-switcher-flags">
						<tbody>
							<?php foreach ( $swiftling_all as $swiftling_language ) : ?>
								<?php $swiftling_locale = (string) $swiftling_language['locale']; ?>
								<tr>
									<td>
										<strong lang="<?php echo esc_attr( str_replace( '_', '-', $swiftling_locale ) ); ?>"><?php echo esc_html( $swiftling_language['name'] ); ?></strong>
										<code><?php echo esc_html( $swiftling_locale ); ?></code>
									</td>
									<td><?php Ui::flagChoice( $swiftling_locale, (string) $swiftling_language['name'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
			</section>

			<section class="swiftling-card swiftling-step">
				<header class="swiftling-step__head">
					<span class="swiftling-step__number" aria-hidden="true">4</span>
					<div>
						<h2><?php esc_html_e( 'The visitor\'s language', 'swiftling' ); ?></h2>
						<p class="swiftling-muted"><?php esc_html_e( 'When a visitor\'s browser prefers another language the page is in. Every page keeps its address, so search engines see every language and page caches keep working.', 'swiftling' ); ?></p>
					</div>
				</header>
				<fieldset class="swiftling-tiles">
					<legend class="screen-reader-text"><?php esc_html_e( 'The visitor\'s language', 'swiftling' ); ?></legend>
					<?php
					$swiftling_modes = array(
						'off'     => array( __( 'Nothing', 'swiftling' ), __( 'Every page shows the language of its address.', 'swiftling' ) ),
						'suggest' => array( __( 'Suggest it', 'swiftling' ), __( 'A short note in the visitor\'s language offers the page in it.', 'swiftling' ) ),
						'open'    => array( __( 'Open the home page in it', 'swiftling' ), __( 'The first time a visitor arrives. Other pages suggest it.', 'swiftling' ) ),
					);
					?>
					<?php foreach ( $swiftling_modes as $swiftling_value => $swiftling_tile ) : ?>
						<label class="swiftling-tile">
							<input type="radio" name="suggest[mode]" value="<?php echo esc_attr( $swiftling_value ); ?>" <?php checked( $swiftling_value, $swiftling_settings['suggest']['mode'] ); ?>>
							<span class="swiftling-tile__art is-mode-<?php echo esc_attr( $swiftling_value ); ?>" aria-hidden="true"><span></span></span>
							<strong><?php echo esc_html( $swiftling_tile[0] ); ?></strong>
							<span class="swiftling-muted"><?php echo esc_html( $swiftling_tile[1] ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<div class="swiftling-switcher-open">
					<strong><?php esc_html_e( 'The home page opens in the browser\'s language only when', 'swiftling' ); ?></strong>
					<ul>
						<li><?php esc_html_e( 'the visitor comes from outside the site: a search, a link, the address typed;', 'swiftling' ); ?></li>
						<li><?php esc_html_e( 'it is the first time: a language the visitor chose in the switcher or the note always wins;', 'swiftling' ); ?></li>
						<li><?php esc_html_e( 'it is a person: search engines and link previews stay on the page they asked for.', 'swiftling' ); ?></li>
					</ul>
				</div>

				<div class="swiftling-switcher-suggest">
					<h3><?php esc_html_e( 'Where the note sits', 'swiftling' ); ?></h3>
					<fieldset class="swiftling-tiles">
						<legend class="screen-reader-text"><?php esc_html_e( 'Position of the note', 'swiftling' ); ?></legend>
						<?php foreach ( $swiftling_suggest_positions as $swiftling_position => $swiftling_label ) : ?>
							<label class="swiftling-tile">
								<input type="radio" name="suggest[position]" value="<?php echo esc_attr( $swiftling_position ); ?>" <?php checked( $swiftling_position, $swiftling_settings['suggest']['position'] ); ?>>
								<span class="swiftling-tile__art is-note-<?php echo esc_attr( $swiftling_position ); ?>" aria-hidden="true"><span></span></span>
								<strong><?php echo esc_html( $swiftling_label[0] ); ?></strong>
								<span class="swiftling-muted"><?php echo esc_html( $swiftling_label[1] ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="swiftling-muted"><?php esc_html_e( 'On phones the note sits at the top of the page, so it covers nothing. A visitor who closes it does not see it again for that language; their browser remembers it, without cookies.', 'swiftling' ); ?></p>

					<details class="swiftling-step__more">
						<summary><?php esc_html_e( 'The texts of the note', 'swiftling' ); ?></summary>
						<table class="swiftling-table swiftling-switcher-suggest-texts">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Language', 'swiftling' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Note', 'swiftling' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Link', 'swiftling' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $swiftling_all as $swiftling_language ) : ?>
									<?php
									$swiftling_locale  = (string) $swiftling_language['locale'];
									$swiftling_default = Suggestion::defaults( $swiftling_language );
									$swiftling_own     = isset( $swiftling_settings['suggest']['texts'][ $swiftling_locale ] ) ? (array) $swiftling_settings['suggest']['texts'][ $swiftling_locale ] : array();
									$swiftling_field   = 'suggest[texts][' . $swiftling_locale . ']';
									$swiftling_suggest = 'swiftling-suggest-' . sanitize_html_class( $swiftling_locale );
									?>
									<tr>
										<td><strong lang="<?php echo esc_attr( $swiftling_default['lang'] ); ?>" dir="<?php echo esc_attr( $swiftling_default['dir'] ); ?>"><?php echo esc_html( $swiftling_language['name'] ); ?></strong></td>
										<td>
											<label class="screen-reader-text" for="<?php echo esc_attr( $swiftling_suggest . '-text' ); ?>">
												<?php
												/* translators: %s: language name, e.g. Deutsch. */
												echo esc_html( sprintf( __( 'Note in %s', 'swiftling' ), $swiftling_language['name'] ) );
												?>
											</label>
											<input type="text" class="regular-text" id="<?php echo esc_attr( $swiftling_suggest . '-text' ); ?>" name="<?php echo esc_attr( $swiftling_field . '[text]' ); ?>" value="<?php echo esc_attr( isset( $swiftling_own['text'] ) ? (string) $swiftling_own['text'] : '' ); ?>" placeholder="<?php echo esc_attr( $swiftling_default['text'] ); ?>" lang="<?php echo esc_attr( $swiftling_default['lang'] ); ?>" dir="<?php echo esc_attr( $swiftling_default['dir'] ); ?>">
										</td>
										<td>
											<label class="screen-reader-text" for="<?php echo esc_attr( $swiftling_suggest . '-show' ); ?>">
												<?php
												/* translators: %s: language name, e.g. Deutsch. */
												echo esc_html( sprintf( __( 'Link in %s', 'swiftling' ), $swiftling_language['name'] ) );
												?>
											</label>
											<input type="text" id="<?php echo esc_attr( $swiftling_suggest . '-show' ); ?>" name="<?php echo esc_attr( $swiftling_field . '[show]' ); ?>" value="<?php echo esc_attr( isset( $swiftling_own['show'] ) ? (string) $swiftling_own['show'] : '' ); ?>" placeholder="<?php echo esc_attr( $swiftling_default['show'] ); ?>" lang="<?php echo esc_attr( $swiftling_default['lang'] ); ?>" dir="<?php echo esc_attr( $swiftling_default['dir'] ); ?>">
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="swiftling-muted"><?php esc_html_e( 'Empty fields show the text in grey: the plugin\'s own translation into that language.', 'swiftling' ); ?></p>
					</details>
				</div>
			</section>
		</div>

		<aside class="swiftling-switcher-aside">
			<section class="swiftling-card swiftling-switcher-preview" data-swiftling-preview>
				<div class="swiftling-card__head">
					<h2><?php esc_html_e( 'Preview', 'swiftling' ); ?></h2>
					<a class="swiftling-button is-secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the site', 'swiftling' ); ?></a>
				</div>
				<?php // One group for each tab of step 2; the script shows the group of the tab that is open. ?>
				<div class="swiftling-switcher-preview__place" data-swiftling-preview-place="menu" <?php echo 'menu' === $swiftling_tab ? '' : 'hidden'; ?>>
				<div class="swiftling-switcher-preview__row">
					<span class="swiftling-muted"><?php esc_html_e( 'In your menu', 'swiftling' ); ?></span>
					<?php // A menu of the site, with the switcher in the style and the place chosen in step 1; CSS shows the one chosen. ?>
					<div class="swiftling-preview-menu">
						<span class="swiftling-preview-menu__item"><?php esc_html_e( 'Home', 'swiftling' ); ?></span>
						<span class="swiftling-preview-menu__item"><?php esc_html_e( 'About', 'swiftling' ); ?></span>
						<span class="swiftling-preview-menu__item"><?php esc_html_e( 'Contact', 'swiftling' ); ?></span>
						<span class="swiftling-preview-menu__switcher" data-swiftling-menu-style="dropdown">
							<?php
							$swiftling_preview = Switcher::render(
								array(
									'style'   => 'dropdown',
									'flag'    => true,
									'name'    => true,
									'code'    => true,
									'current' => true,
								)
							);
							echo wp_kses( $swiftling_preview, Switcher::allowedHtml() );
							?>
						</span>
						<span class="swiftling-preview-menu__switcher" data-swiftling-menu-style="list">
							<?php
							$swiftling_preview = Switcher::render(
								array(
									'style'   => 'horizontal',
									'flag'    => true,
									'name'    => true,
									'code'    => true,
									'current' => true,
								)
							);
							echo wp_kses( $swiftling_preview, Switcher::allowedHtml() );
							?>
						</span>
						<span class="swiftling-preview-menu__switcher swiftling-preview-globe" data-swiftling-menu-style="globe">
							<span class="swiftling-preview-globe__button">
								<?php echo wp_kses( Switcher::globe(), Ui::ICON_HTML ); ?>
								<span class="swiftling-switcher__code" data-swiftling-globe-code><?php echo esc_html( (string) $swiftling_languages['source']['code'] ); ?></span>
							</span>
							<span class="swiftling-preview-globe__list">
								<?php
								$swiftling_preview = Switcher::render(
									array(
										'style'   => 'vertical',
										'flag'    => true,
										'name'    => true,
										'code'    => true,
										'current' => false,
									)
								);
								echo wp_kses( $swiftling_preview, Switcher::allowedHtml() );
								?>
							</span>
						</span>
					</div>
				</div>
				</div>
				<div class="swiftling-switcher-preview__place" data-swiftling-preview-place="footer" <?php echo 'footer' === $swiftling_tab ? '' : 'hidden'; ?>>
					<div class="swiftling-switcher-preview__row">
						<span class="swiftling-muted"><?php esc_html_e( 'At the end of the page', 'swiftling' ); ?></span>
						<?php // Each style of step 1; CSS shows the one chosen, and a floating switcher is a dropdown. ?>
						<?php foreach ( array_keys( $swiftling_styles ) as $swiftling_style ) : ?>
							<div class="swiftling-preview-footer" data-swiftling-footer-style="<?php echo esc_attr( $swiftling_style ); ?>">
								<?php
								$swiftling_preview = Switcher::render(
									array(
										'style'   => $swiftling_style,
										'flag'    => true,
										'name'    => true,
										'code'    => true,
										'current' => true,
									)
								);
								echo wp_kses( $swiftling_preview, Switcher::allowedHtml() );
								?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="swiftling-switcher-preview__place" data-swiftling-preview-place="elsewhere" <?php echo 'elsewhere' === $swiftling_tab ? '' : 'hidden'; ?>>
				<?php foreach ( $swiftling_styles as $swiftling_style => $swiftling_label ) : ?>
					<div class="swiftling-switcher-preview__row">
						<span class="swiftling-muted"><?php echo esc_html( $swiftling_label ); ?></span>
						<?php
						// Every part is printed; the preview hides what the switches leave out.
						$swiftling_preview = Switcher::render(
							array(
								'style'   => $swiftling_style,
								'flag'    => true,
								'name'    => true,
								'code'    => true,
								'current' => true,
							)
						);
						echo wp_kses( $swiftling_preview, Switcher::allowedHtml() );
						?>
					</div>
				<?php endforeach; ?>
				</div>
			</section>
			<?php
			// What the site does now, as saved: the choices above change it only once they are saved.
			$swiftling_ticked = wp_list_pluck( $swiftling_menus, 'name', 'term_id' );
			$swiftling_ticked = array_values( array_intersect_key( $swiftling_ticked, array_flip( $swiftling_settings['menu']['menus'] ) ) );
			$swiftling_in     = array();
			if ( $swiftling_settings['menu']['enabled'] && $swiftling_ticked ) {
				/* translators: %l: menu names, e.g. Main menu and Footer links. */
				$swiftling_in[] = wp_sprintf( __( 'In the menu: %l', 'swiftling' ), $swiftling_ticked );
			}
			if ( $swiftling_blocks ) {
				/* translators: %l: names of menus and template parts, e.g. Header and Footer. */
				$swiftling_in[] = wp_sprintf( __( 'As a block in: %l', 'swiftling' ), $swiftling_blocks );
			}
			$swiftling_now = array_merge(
				$swiftling_in ? $swiftling_in : array( __( 'In no menu', 'swiftling' ) ),
				array(
					$swiftling_settings['footer']['enabled']
						? $swiftling_footer_tiles[ $swiftling_settings['footer']['position'] ][0]
						: __( 'Not at the end of the page', 'swiftling' ),
					/* translators: %s: what happens for a visitor whose browser prefers another language, e.g. Suggest it. */
					sprintf( __( 'The visitor\'s language: %s', 'swiftling' ), $swiftling_modes[ $swiftling_settings['suggest']['mode'] ][0] ),
				)
			);
			?>
			<section class="swiftling-card swiftling-switcher-summary">
				<h2><?php esc_html_e( 'On this site now', 'swiftling' ); ?></h2>
				<ul>
					<?php foreach ( $swiftling_now as $swiftling_line ) : ?>
						<li><?php echo esc_html( $swiftling_line ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		</aside>
	</div>

	<div class="swiftling-card swiftling-switcher-save">
		<span class="swiftling-muted"><?php esc_html_e( 'The preview follows your choices; the site changes once you save.', 'swiftling' ); ?></span>
		<button type="submit" class="swiftling-button is-primary"><?php esc_html_e( 'Save changes', 'swiftling' ); ?></button>
	</div>
</form>
