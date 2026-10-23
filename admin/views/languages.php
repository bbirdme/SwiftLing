<?php
/**
 * Languages screen: the language the site is written in and the languages it is translated into.
 *
 * The form posts to swiftling_save_languages() in includes/languages.php. Adding and removing a
 * language are buttons of the same form, so no script is needed. The address column follows the
 * chosen address format with CSS alone.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();
$swiftling_available = swiftling_available_languages();
$swiftling_chosen    = array_merge( array( $swiftling_languages['source']['locale'] ), wp_list_pluck( $swiftling_languages['targets'], 'locale' ) );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the redirect.
$swiftling_notice   = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
$swiftling_messages = array(
	'saved'          => __( 'Languages saved.', 'swiftling' ),
	/* translators: %s: language name, e.g. Hrvatski. */
	'setup'          => sprintf( __( 'Your content is written in %s. Now add the languages to translate it into.', 'swiftling' ), $swiftling_languages['source']['name'] ),
	'slug'           => __( 'Two languages had the same address, so one of them now uses its full locale.', 'swiftling' ),
	'custom_invalid' => __( 'A custom language needs a name and a language code such as cnr or es_MX, of at most 12 characters.', 'swiftling' ),
	'custom_exists'  => __( 'The site already has a language with that code.', 'swiftling' ),
	'forget_refused' => __( 'Nothing was deleted: that language is on the site again.', 'swiftling' ),
);
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: which language's warning is open, and which one was deleted.
$swiftling_forget    = isset( $_GET['swiftling_forget'] ) ? sanitize_text_field( wp_unslash( $_GET['swiftling_forget'] ) ) : '';
$swiftling_last_step = isset( $_GET['swiftling_step'] ) && 'last' === sanitize_key( wp_unslash( $_GET['swiftling_step'] ) );
$swiftling_forgotten = isset( $_GET['swiftling_forgotten'] ) ? sanitize_text_field( wp_unslash( $_GET['swiftling_forgotten'] ) ) : '';
$swiftling_copies    = isset( $_GET['swiftling_copies'] ) ? absint( $_GET['swiftling_copies'] ) : 0;
// phpcs:enable
if ( 'forgotten' === $swiftling_notice && '' !== $swiftling_forgotten ) {
	/* translators: %s: language name, e.g. Shqip. */
	$swiftling_messages['forgotten'] = sprintf( __( 'The translations of %s were deleted.', 'swiftling' ), swiftling_kept_language_name( $swiftling_forgotten ) );
	if ( $swiftling_copies ) {
		$swiftling_messages['forgotten'] .= ' ' . sprintf(
			/* translators: %s: number of separate copies. */
			EMPTY_TRASH_DAYS ? _n( 'Its %s separate copy is in the Trash.', 'Its %s separate copies are in the Trash.', $swiftling_copies, 'swiftling' ) : _n( 'Its %s separate copy was deleted.', 'Its %s separate copies were deleted.', $swiftling_copies, 'swiftling' ),
			number_format_i18n( $swiftling_copies )
		);
	}
}
$swiftling_kept = swiftling_kept_languages();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the redirect.
$swiftling_taken = isset( $_GET['swiftling_taken'] ) ? array_filter( array_map( 'sanitize_title', explode( ',', sanitize_text_field( wp_unslash( $_GET['swiftling_taken'] ) ) ) ) ) : array();
// The address column shows the format the Addresses screen chose.
$swiftling_folders   = 'directory' === swiftling_saved_url_format();
$swiftling_hosts     = swiftling_is_host_format() ? swiftling_url_format() : '';
$swiftling_folder_on = $swiftling_folders && swiftling_saved_source_folder();
$swiftling_source    = $swiftling_languages['source'];
$swiftling_wordpress = swiftling_available_languages( false );
$swiftling_ordered   = swiftling_ordered_languages();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the name typed before a failed add, to type it again.
$swiftling_custom = isset( $_GET['swiftling_custom'] ) ? sanitize_text_field( wp_unslash( $_GET['swiftling_custom'] ) ) : '';
$swiftling_intl   = class_exists( 'Locale' );

Ui::screenHeader(
	array(
		'crumb'       => __( 'Settings', 'swiftling' ),
		'title'       => __( 'Languages', 'swiftling' ),
		'description' => __( 'The language your content is written in, and the languages it is translated into.', 'swiftling' ),
	)
);
?>
<?php if ( isset( $swiftling_messages[ $swiftling_notice ] ) ) : ?>
	<?php Ui::notice( in_array( $swiftling_notice, array( 'saved', 'forgotten' ), true ) ? 'success' : ( 'forget_refused' === $swiftling_notice ? 'warning' : 'info' ), array( 'text' => $swiftling_messages[ $swiftling_notice ] ) ); ?>
<?php elseif ( 'forget_unticked' === $swiftling_notice ) : ?>
	<?php Ui::notice( 'warning', array( 'text' => __( 'Nothing was deleted: tick that you understand the translations cannot be brought back.', 'swiftling' ) ) ); ?>
<?php endif; ?>
<?php
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notices after the redirect.
$swiftling_wp_names = array();
foreach ( array( 'installed', 'missing' ) as $swiftling_wp_state ) {
	$swiftling_wp_locales                      = isset( $_GET[ 'swiftling_wp_' . $swiftling_wp_state ] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET[ 'swiftling_wp_' . $swiftling_wp_state ] ) ) ) : array();
	$swiftling_wp_names[ $swiftling_wp_state ] = array();
	foreach ( $swiftling_wp_locales as $swiftling_wp_locale ) {
		if ( isset( $swiftling_available[ $swiftling_wp_locale ] ) ) {
			$swiftling_wp_names[ $swiftling_wp_state ][] = $swiftling_available[ $swiftling_wp_locale ]['name'];
		}
	}
}
// phpcs:enable
?>
<?php if ( $swiftling_wp_names['installed'] ) : ?>
	<?php
	Ui::notice(
		'success',
		array(
			/* translators: %s: language names, e.g. Deutsch, Dansk. */
			'text' => sprintf( __( 'WordPress, the theme and plugins now show their own texts translated in %s, with dates in the language. Your translations still come first.', 'swiftling' ), wp_sprintf( '%l', $swiftling_wp_names['installed'] ) ),
		)
	);
	?>
<?php endif; ?>
<?php if ( $swiftling_wp_names['missing'] ) : ?>
	<?php
	Ui::notice(
		'warning',
		array(
			/* translators: %s: language names, e.g. Deutsch. */
			'text' => sprintf( __( 'WordPress\'s own translations for %s could not be downloaded, so texts of WordPress, the theme and plugins stay in English there until you translate them. A site administrator can add the language under Settings > General.', 'swiftling' ), wp_sprintf( '%l', $swiftling_wp_names['missing'] ) ),
		)
	);
	?>
<?php endif; ?>
<?php Ui::takenFolders( $swiftling_taken ); ?>
<?php
// In a host format, a language whose host has not led here in a check is not on the site yet.
$swiftling_unreached = array();
foreach ( '' !== $swiftling_hosts ? $swiftling_languages['targets'] : array() as $swiftling_target ) {
	if ( ! $swiftling_target['reachable'] ) {
		$swiftling_unreached[] = $swiftling_target['name'];
	}
}
?>
<?php if ( $swiftling_unreached ) : ?>
	<?php
	Ui::notice(
		'warning',
		array(
			/* translators: %s: language names, e.g. Deutsch and Français. */
			'text' => sprintf( _n( '%s is not on the site until its address leads here.', '%s are not on the site until their addresses lead here.', count( $swiftling_unreached ), 'swiftling' ), wp_sprintf( '%l', $swiftling_unreached ) ),
			'link' => array( __( 'Check on URLs', 'swiftling' ), admin_url( 'admin.php?page=swiftling-addresses#swiftling-format' ) ),
		)
	);
	?>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-languages-form">
	<input type="hidden" name="action" value="swiftling_save_languages">
	<?php wp_nonce_field( 'swiftling_save_languages' ); ?>
	<?php // Enter in a field submits with the first button of the form, which must save, not remove a language. ?>
	<button type="submit" class="screen-reader-text" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Save changes', 'swiftling' ); ?></button>

	<section class="swiftling-card">
		<table class="swiftling-table swiftling-languages" data-swiftling-order>
			<thead>
				<tr>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Order', 'swiftling' ); ?></span></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Code', 'swiftling' ); ?></span></th>
					<th><?php esc_html_e( 'Language', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Flag', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Name in the switcher', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Locale', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Address', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Visible', 'swiftling' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swiftling' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $swiftling_ordered as $swiftling_row ) : ?>
					<?php
					$swiftling_is_source = $swiftling_row['source'];
					$swiftling_field     = $swiftling_is_source ? '' : 'targets[' . $swiftling_row['locale'] . ']';
					/* translators: %s: language name, e.g. Deutsch. */
					$swiftling_move_label = sprintf( __( 'Move %s. Drag it, or use the arrow keys.', 'swiftling' ), $swiftling_row['name'] );
					/* translators: %s: language name, e.g. Deutsch. */
					$swiftling_name_label = sprintf( __( 'Name of %s in the switcher', 'swiftling' ), $swiftling_row['name'] );
					?>
					<tr data-swiftling-language="<?php echo esc_attr( $swiftling_row['name'] ); ?>">
						<td class="swiftling-languages__handle">
							<input type="hidden" name="order[]" value="<?php echo esc_attr( $swiftling_row['locale'] ); ?>">
							<button type="button" class="swiftling-icon-button" draggable="true" data-swiftling-move aria-label="<?php echo esc_attr( $swiftling_move_label ); ?>"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>
						</td>
						<td><?php Ui::lang( $swiftling_row['code'], $swiftling_is_source ); ?></td>
						<td>
							<strong lang="<?php echo esc_attr( str_replace( '_', '-', $swiftling_row['locale'] ) ); ?>"><?php echo esc_html( $swiftling_row['name'] ); ?></strong>
							<?php if ( $swiftling_row['custom'] ) : ?>
								<span class="swiftling-badge is-custom"><?php esc_html_e( 'Custom', 'swiftling' ); ?></span>
								<?php if ( ! $swiftling_is_source ) : ?>
									<span class="swiftling-languages__custom">
										<label>
											<span><?php esc_html_e( 'WordPress texts and dates from', 'swiftling' ); ?></span>
											<select name="<?php echo esc_attr( $swiftling_field . '[wp_locale]' ); ?>">
												<?php foreach ( $swiftling_wordpress as $swiftling_locale => $swiftling_language ) : ?>
													<option value="<?php echo esc_attr( $swiftling_locale ); ?>" <?php selected( $swiftling_locale, $swiftling_row['wp_locale'] ); ?>><?php echo esc_html( $swiftling_language['name'] . ' (' . $swiftling_locale . ')' ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label class="swiftling-languages__rtl">
											<input type="checkbox" name="<?php echo esc_attr( $swiftling_field . '[rtl]' ); ?>" value="1" <?php checked( (bool) $swiftling_row['rtl'] ); ?>>
											<?php esc_html_e( 'Right to left', 'swiftling' ); ?>
										</label>
									</span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td><?php Ui::flagChoice( (string) $swiftling_row['locale'], (string) $swiftling_row['name'] ); ?></td>
						<td>
							<input type="text" name="<?php echo esc_attr( $swiftling_is_source ? 'source_label' : $swiftling_field . '[label]' ); ?>" value="<?php echo esc_attr( $swiftling_row['label'] ); ?>" placeholder="<?php echo esc_attr( $swiftling_row['name'] ); ?>" aria-label="<?php echo esc_attr( $swiftling_name_label ); ?>" size="16">
						</td>
						<td><code><?php echo esc_html( $swiftling_row['locale'] ); ?></code></td>
						<td>
							<?php if ( $swiftling_is_source && ! $swiftling_folder_on ) : ?>
								<code>/</code>
							<?php elseif ( $swiftling_is_source ) : ?>
								<span class="swiftling-prefixed">
									<code>/</code>
									<?php
									/* translators: %s: language name, e.g. Hrvatski. */
									$swiftling_slug_label = sprintf( __( 'Address of %s', 'swiftling' ), $swiftling_row['name'] );
									?>
									<input type="text" class="swiftling-mono" name="source_slug" value="<?php echo esc_attr( $swiftling_row['slug'] ); ?>" aria-label="<?php echo esc_attr( $swiftling_slug_label ); ?>" size="8">
								</span>
							<?php else : ?>
								<span class="swiftling-prefixed<?php echo '' !== $swiftling_hosts ? ' is-unprefixed' : ''; ?><?php echo 'subdomain' === $swiftling_hosts ? ' is-suffixed' : ''; ?>">
									<?php if ( '' === $swiftling_hosts ) : ?>
										<code><?php echo esc_html( $swiftling_folders ? '/' : '?lang=' ); ?></code>
									<?php endif; ?>
									<?php
									/* translators: %s: language name, e.g. Deutsch. */
									$swiftling_slug_label = sprintf( __( 'Address of %s', 'swiftling' ), $swiftling_row['name'] );
									?>
									<input type="text" class="swiftling-mono" name="<?php echo esc_attr( $swiftling_field . '[slug]' ); ?>" value="<?php echo esc_attr( $swiftling_row['slug'] ); ?>" aria-label="<?php echo esc_attr( $swiftling_slug_label ); ?>" size="8">
									<?php if ( 'subdomain' === $swiftling_hosts ) : ?>
										<code><?php echo esc_html( '.' . swiftling_subdomain_base() ); ?></code>
									<?php endif; ?>
								</span>
								<?php if ( 'domain' === $swiftling_hosts ) : ?>
									<span class="swiftling-muted swiftling-languages__domain"><?php echo esc_html( '' !== $swiftling_row['host'] ? $swiftling_row['host'] : __( 'No domain yet', 'swiftling' ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $swiftling_is_source ) : ?>
								<span class="swiftling-badge is-source"><?php esc_html_e( 'Original', 'swiftling' ); ?></span>
							<?php else : ?>
								<?php
								/* translators: %s: language name, e.g. Deutsch. */
								$swiftling_toggle_label = sprintf( __( 'Show %s to visitors', 'swiftling' ), $swiftling_row['name'] );
								?>
								<input type="checkbox" class="swiftling-toggle" name="<?php echo esc_attr( $swiftling_field . '[visible]' ); ?>" value="1" aria-label="<?php echo esc_attr( $swiftling_toggle_label ); ?>" <?php checked( $swiftling_row['visible'] ); ?>>
							<?php endif; ?>
						</td>
						<td class="is-numeric">
							<?php if ( ! $swiftling_is_source ) : ?>
								<?php
								/* translators: %s: language name, e.g. Deutsch. */
								$swiftling_remove_label = sprintf( __( 'Remove %s', 'swiftling' ), $swiftling_row['name'] );
								?>
								<button type="submit" class="swiftling-icon-button" name="remove" value="<?php echo esc_attr( $swiftling_row['locale'] ); ?>" aria-label="<?php echo esc_attr( $swiftling_remove_label ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="swiftling-muted swiftling-languages__hint"><?php esc_html_e( 'Drag a row to change the order. The switcher and the editor follow it.', 'swiftling' ); ?> <?php esc_html_e( 'A hidden language opens only for people who can edit content.', 'swiftling' ); ?></p>
		<?php // Translations are made from the original, so it changes only by starting over. Below the table, not in its narrow name column. ?>
		<p class="swiftling-languages__original">
			<a class="swiftling-languages__locked" href="<?php echo esc_url( admin_url( 'admin.php?page=swiftling-settings#start-over' ) ); ?>">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<?php esc_html_e( 'Change the original language', 'swiftling' ); ?>
			</a>
		</p>
		<p class="screen-reader-text" aria-live="polite" data-swiftling-order-status></p>

		<div class="swiftling-card__foot">
			<span class="swiftling-language-add">
				<label class="screen-reader-text" for="swiftling-new-language"><?php esc_html_e( 'Language to add', 'swiftling' ); ?></label>
				<select id="swiftling-new-language" name="new_language">
					<?php foreach ( $swiftling_available as $swiftling_locale => $swiftling_language ) : ?>
						<?php
						if ( in_array( $swiftling_locale, $swiftling_chosen, true ) ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $swiftling_locale ); ?>"><?php echo esc_html( swiftling_language_option_label( $swiftling_language ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="swiftling-button is-secondary" name="add" value="1"><span class="swiftling-spinner" aria-hidden="true"></span><?php esc_html_e( 'Add language', 'swiftling' ); ?></button>
			</span>
			<button type="submit" class="swiftling-button is-primary"><?php esc_html_e( 'Save changes', 'swiftling' ); ?></button>
		</div>
		<?php // Filled by languages.js while a language is being added; empty, it takes no room. ?>
		<p class="swiftling-muted swiftling-languages__progress" role="status" data-swiftling-progress></p>
	</section>

	<details class="swiftling-card swiftling-custom-language" <?php echo '' !== $swiftling_custom ? 'open' : ''; ?>>
		<summary>
			<h2><?php esc_html_e( 'Add a custom language', 'swiftling' ); ?></h2>
			<span class="swiftling-muted"><?php esc_html_e( 'For a language WordPress does not list, or a regional variant of one.', 'swiftling' ); ?></span>
		</summary>
		<div class="swiftling-custom-language__fields">
			<label>
				<span><?php esc_html_e( 'Name, as its speakers write it', 'swiftling' ); ?></span>
				<input type="text" name="custom[name]" value="<?php echo esc_attr( $swiftling_custom ); ?>" placeholder="Crnogorski">
			</label>
			<label>
				<span><?php esc_html_e( 'Language code', 'swiftling' ); ?></span>
				<input type="text" class="swiftling-mono" name="custom[locale]" placeholder="cnr" maxlength="12">
				<span class="swiftling-muted"><?php esc_html_e( 'The language, then a country or region if any: cnr, es_MX, pt_AO. Browsers and search engines read it.', 'swiftling' ); ?></span>
			</label>
			<label>
				<span><?php esc_html_e( 'Address', 'swiftling' ); ?></span>
				<input type="text" class="swiftling-mono" name="custom[slug]" placeholder="cnr" size="8">
				<span class="swiftling-muted"><?php esc_html_e( 'Empty: the language code.', 'swiftling' ); ?></span>
			</label>
			<label>
				<span><?php esc_html_e( 'WordPress texts and dates from', 'swiftling' ); ?></span>
				<select name="custom[wp_locale]">
					<option value=""><?php esc_html_e( 'The language itself, if WordPress has it', 'swiftling' ); ?></option>
					<?php foreach ( $swiftling_wordpress as $swiftling_locale => $swiftling_language ) : ?>
						<option value="<?php echo esc_attr( $swiftling_locale ); ?>"><?php echo esc_html( $swiftling_language['name'] . ' (' . $swiftling_locale . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="swiftling-muted"><?php esc_html_e( 'WordPress has no texts of its own for a custom language, so it uses those of a close one.', 'swiftling' ); ?></span>
			</label>
			<label class="swiftling-custom-language__rtl">
				<input type="checkbox" name="custom[rtl]" value="1">
				<?php esc_html_e( 'Written right to left', 'swiftling' ); ?>
			</label>
		</div>
		<div class="swiftling-card__foot">
			<span class="swiftling-muted"><?php esc_html_e( 'It starts hidden, like every new language.', 'swiftling' ); ?></span>
			<button type="submit" class="swiftling-button is-secondary" name="add_custom" value="1"><span class="swiftling-spinner" aria-hidden="true"></span><?php esc_html_e( 'Add custom language', 'swiftling' ); ?></button>
		</div>
	</details>

	<?php if ( $swiftling_kept ) : ?>
		<?php // In the form, so Add again saves what the rows above hold too. Deleting is a form of its own, below. ?>
		<section class="swiftling-card swiftling-kept" id="kept-languages">
			<div class="swiftling-kept__head">
				<span class="swiftling-kept__icon" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><path d="M1.5 2.5h13v3h-13zM2.5 5.5v8h11v-8M6 8.5h4"/></svg></span>
				<div>
					<h2><?php esc_html_e( 'Languages no longer on the site', 'swiftling' ); ?></h2>
					<p class="swiftling-muted"><?php esc_html_e( 'Their translations are kept, so adding a language again brings them back. Delete them only when you are sure the site will not speak that language again.', 'swiftling' ); ?></p>
				</div>
			</div>
			<div class="swiftling-table-wrap">
				<table class="swiftling-table swiftling-kept__table">
					<tbody>
						<?php foreach ( $swiftling_kept as $swiftling_locale => $swiftling_kept_one ) : ?>
							<tr>
								<td>
									<strong lang="<?php echo esc_attr( str_replace( '_', '-', $swiftling_locale ) ); ?>"><?php echo esc_html( $swiftling_kept_one['name'] ); ?></strong>
									<span class="swiftling-mono swiftling-muted"><?php echo esc_html( $swiftling_locale ); ?></span>
								</td>
								<td class="swiftling-muted"><?php echo esc_html( swiftling_kept_language_summary( $swiftling_kept_one ) ); ?></td>
								<td class="swiftling-kept__actions">
									<?php if ( $swiftling_kept_one['can_add'] ) : ?>
										<button type="submit" class="swiftling-button is-secondary" name="add_again" value="<?php echo esc_attr( $swiftling_locale ); ?>" data-swiftling-name="<?php echo esc_attr( $swiftling_kept_one['name'] ); ?>"><?php esc_html_e( 'Add again', 'swiftling' ); ?></button>
									<?php else : ?>
										<span class="swiftling-muted"><?php esc_html_e( 'Add it again as a custom language with the same code', 'swiftling' ); ?></span>
									<?php endif; ?>
									<?php // The script opens the warning as a dialog; without it, the link opens the screen with the warning shown in place. ?>
									<a class="swiftling-kept__delete" href="<?php echo esc_url( add_query_arg( 'swiftling_forget', rawurlencode( $swiftling_locale ), admin_url( 'admin.php?page=swiftling-languages' ) ) . '#kept-languages' ); ?>" data-swiftling-forget="<?php echo esc_attr( $swiftling_locale ); ?>">
										<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2.5 4h11M6 4V2.5h4V4M4 4l.7 9.5h6.6L12 4"/></svg>
										<?php esc_html_e( 'Delete…', 'swiftling' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endif; ?>

	<section class="swiftling-card">
		<fieldset class="swiftling-format">
			<legend><h2><?php esc_html_e( 'Language names', 'swiftling' ); ?></h2></legend>
			<p class="swiftling-muted"><?php esc_html_e( 'How the switcher names each language. A name you write in the table wins.', 'swiftling' ); ?></p>
			<?php
			// Examples from the site's own languages, named on a page in the original language.
			$swiftling_examples = array(
				'native' => array(),
				'page'   => array(),
				'both'   => array(),
			);
			foreach ( $swiftling_ordered as $swiftling_row ) {
				// As the switcher names it on a page in the original: the name written for it first.
				$swiftling_translated = $swiftling_row['source'] ? '' : swiftling_language_name_in( $swiftling_row['locale'], $swiftling_source['locale'] );
				$swiftling_translated = '' !== $swiftling_translated || $swiftling_row['source'] ? $swiftling_translated : swiftling_translated_language_name( $swiftling_row['locale'], $swiftling_source['wp_locale'], false );
				$swiftling_translated = '' !== $swiftling_translated ? $swiftling_translated : $swiftling_row['name'];

				$swiftling_examples['native'][] = $swiftling_row['name'];
				$swiftling_examples['page'][]   = $swiftling_translated;
				$swiftling_examples['both'][]   = $swiftling_translated !== $swiftling_row['name'] ? $swiftling_row['name'] . ' (' . $swiftling_translated . ')' : $swiftling_row['name'];
			}
			$swiftling_name_choices = array(
				'native' => __( 'In their own language', 'swiftling' ),
				'page'   => __( 'In the language of the page', 'swiftling' ),
				'both'   => __( 'Both', 'swiftling' ),
			);
			?>
			<?php foreach ( $swiftling_name_choices as $swiftling_choice => $swiftling_choice_label ) : ?>
				<label>
					<input type="radio" name="names" value="<?php echo esc_attr( $swiftling_choice ); ?>" <?php checked( $swiftling_choice, $swiftling_languages['names'] ); ?> <?php disabled( 'native' !== $swiftling_choice && ! $swiftling_intl ); ?>>
					<span>
						<strong><?php echo esc_html( $swiftling_choice_label ); ?></strong>
						<span class="swiftling-muted">
							<?php
							/* translators: 1: language names, e.g. Hrvatski · Deutsch, 2: language name of the original, e.g. Hrvatski. */
							echo esc_html( 'native' === $swiftling_choice ? implode( ' · ', $swiftling_examples['native'] ) : sprintf( __( '%1$s on a page in %2$s', 'swiftling' ), implode( ' · ', $swiftling_examples[ $swiftling_choice ] ), $swiftling_source['name'] ) );
							?>
						</span>
					</span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
		// The name of each language in the language of each page, as the user writes it; empty, the
		// automatic one, shown as the placeholder. A language is named in itself in the table above.
		$swiftling_written = $swiftling_languages['name_translations'];
		?>
		<details class="swiftling-step__more swiftling-language-names" <?php echo $swiftling_written ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'Names in each language', 'swiftling' ); ?></summary>
			<p class="swiftling-muted"><?php esc_html_e( 'Write how a language is called on a page in another language, such as German on an English page. An empty field shows the automatic name.', 'swiftling' ); ?></p>
			<div class="swiftling-language-names__scroll">
				<table class="swiftling-table swiftling-language-names__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Language', 'swiftling' ); ?></th>
							<?php foreach ( $swiftling_ordered as $swiftling_page ) : ?>
								<th scope="col">
									<?php
									/* translators: %s: language name, e.g. Deutsch. */
									echo esc_html( sprintf( __( 'On a page in %s', 'swiftling' ), $swiftling_page['name'] ) );
									?>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $swiftling_ordered as $swiftling_row ) : ?>
							<tr>
								<th scope="row" lang="<?php echo esc_attr( str_replace( '_', '-', $swiftling_row['locale'] ) ); ?>"><?php echo esc_html( $swiftling_row['name'] ); ?></th>
								<?php foreach ( $swiftling_ordered as $swiftling_page ) : ?>
									<td>
										<?php if ( $swiftling_page['locale'] === $swiftling_row['locale'] ) : ?>
											<span class="swiftling-muted" aria-label="<?php esc_attr_e( 'Its own name, in the table above', 'swiftling' ); ?>">—</span>
										<?php else : ?>
											<?php
											$swiftling_automatic = swiftling_translated_language_name( $swiftling_row['locale'], $swiftling_page['wp_locale'], false );
											$swiftling_value     = isset( $swiftling_written[ $swiftling_row['locale'] ][ $swiftling_page['locale'] ] ) ? $swiftling_written[ $swiftling_row['locale'] ][ $swiftling_page['locale'] ] : '';
											?>
											<input type="text" name="<?php echo esc_attr( 'name_translations[' . $swiftling_row['locale'] . '][' . $swiftling_page['locale'] . ']' ); ?>" value="<?php echo esc_attr( $swiftling_value ); ?>" placeholder="<?php echo esc_attr( '' !== $swiftling_automatic ? $swiftling_automatic : $swiftling_row['name'] ); ?>" lang="<?php echo esc_attr( str_replace( '_', '-', $swiftling_page['locale'] ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: language name, e.g. Deutsch, 2: language name, e.g. Hrvatski. */ __( '%1$s on a page in %2$s', 'swiftling' ), $swiftling_row['name'], $swiftling_page['name'] ) ); ?>">
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
		<?php if ( ! $swiftling_intl ) : ?>
			<?php
			Ui::notice(
				'info',
				array(
					'text'    => __( 'Names in the language of the page come from the intl extension of PHP, which this server does not have. Your host can switch it on.', 'swiftling' ),
					'compact' => true,
				)
			);
			?>
		<?php endif; ?>
		<div class="swiftling-card__foot">
			<span></span>
			<button type="submit" class="swiftling-button is-primary"><?php esc_html_e( 'Save changes', 'swiftling' ); ?></button>
		</div>
	</section>
</form>

<?php foreach ( $swiftling_kept as $swiftling_locale => $swiftling_kept_one ) : ?>
	<?php
	$swiftling_open = $swiftling_forget === $swiftling_locale;
	$swiftling_last = $swiftling_open && $swiftling_last_step;
	$swiftling_id   = 'swiftling-forget-' . $swiftling_locale;
	$swiftling_name = $swiftling_kept_one['name'];
	?>
	<dialog id="<?php echo esc_attr( $swiftling_id ); ?>" class="swiftling-dialog swiftling-forget" aria-labelledby="<?php echo esc_attr( $swiftling_id . '-title' ); ?>" <?php echo $swiftling_open ? 'open' : ''; ?>>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-dialog__form">
			<input type="hidden" name="action" value="swiftling_forget_language">
			<input type="hidden" name="locale" value="<?php echo esc_attr( $swiftling_locale ); ?>">
			<?php wp_nonce_field( 'swiftling_forget_language' ); ?>

			<?php // The first warning: what goes, and that it does not come back. ?>
			<div class="swiftling-forget__step" data-swiftling-step="check" <?php echo $swiftling_last ? 'hidden' : ''; ?>>
				<div class="swiftling-forget__title">
					<span class="swiftling-forget__sign" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><path d="M8 2l6.5 11.5h-13zM8 6.5v3M8 11.6h.01"/></svg></span>
					<?php /* translators: %s: language name, e.g. Shqip. */ ?>
					<h2 id="<?php echo esc_attr( $swiftling_id . '-title' ); ?>"><?php echo esc_html( sprintf( __( 'Delete everything kept for %s?', 'swiftling' ), $swiftling_name ) ); ?></h2>
				</div>
				<p class="swiftling-forget__warning">
					<strong><?php esc_html_e( 'This is not removing a language.', 'swiftling' ); ?></strong>
					<?php /* translators: %s: language name, e.g. Shqip. */ ?>
					<?php echo esc_html( sprintf( __( 'Removing keeps the translations; this deletes them for good. Adding %s again later starts from nothing, and machine translations are paid for again.', 'swiftling' ), $swiftling_name ) ); ?>
				</p>
				<ul class="swiftling-forget__parts">
					<?php foreach ( swiftling_kept_language_parts( $swiftling_kept_one ) as $swiftling_part ) : ?>
						<li><span><?php echo esc_html( $swiftling_part[0] ); ?></span><span class="swiftling-muted"><?php echo esc_html( $swiftling_part[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<label class="swiftling-forget__understood">
					<input type="checkbox" name="understood" value="1" required <?php checked( $swiftling_last ); ?>>
					<?php /* translators: %s: language name, e.g. Shqip. */ ?>
					<span><?php echo esc_html( sprintf( __( 'I understand that the translations of %s cannot be brought back.', 'swiftling' ), $swiftling_name ) ); ?></span>
				</label>
				<div class="swiftling-dialog__foot">
					<a class="swiftling-button is-link" href="<?php echo esc_url( admin_url( 'admin.php?page=swiftling-languages' ) . '#kept-languages' ); ?>" data-swiftling-close><?php esc_html_e( 'Cancel', 'swiftling' ); ?></a>
					<button type="submit" class="swiftling-button is-danger" data-swiftling-continue><?php esc_html_e( 'Delete…', 'swiftling' ); ?></button>
				</div>
			</div>

			<?php // The last warning, before anything goes. ?>
			<div class="swiftling-forget__step is-last" data-swiftling-step="last" <?php echo $swiftling_last ? '' : 'hidden'; ?>>
				<div class="swiftling-forget__title">
					<span class="swiftling-forget__sign is-solid" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><path d="M8 2l6.5 11.5h-13zM8 6.5v3M8 11.6h.01"/></svg></span>
					<h2><?php esc_html_e( 'Last check', 'swiftling' ); ?></h2>
				</div>
				<?php /* translators: 1: language name, e.g. Shqip, 2: what is deleted, e.g. 251 texts · 4 separate copies. */ ?>
				<p><?php echo esc_html( sprintf( __( 'You are about to delete the translations of %1$s: %2$s.', 'swiftling' ), $swiftling_name, swiftling_kept_language_summary( $swiftling_kept_one ) ) ); ?></p>
				<p class="swiftling-forget__warning"><strong><?php esc_html_e( 'There is no way back.', 'swiftling' ); ?></strong> <?php echo esc_html( $swiftling_kept_one['copies'] && EMPTY_TRASH_DAYS ? __( 'Only the separate copies can still be restored from the Trash; the texts cannot.', 'swiftling' ) : __( 'Neither SwiftLing nor WordPress can restore them.', 'swiftling' ) ); ?></p>
				<div class="swiftling-dialog__foot">
					<a class="swiftling-button is-link" href="<?php echo esc_url( add_query_arg( 'swiftling_forget', rawurlencode( $swiftling_locale ), admin_url( 'admin.php?page=swiftling-languages' ) ) . '#kept-languages' ); ?>" data-swiftling-back><?php esc_html_e( 'Back', 'swiftling' ); ?></a>
					<?php /* translators: %s: language name, e.g. Shqip. */ ?>
					<button type="submit" class="swiftling-button is-danger-solid" name="last" value="1"><?php echo esc_html( sprintf( __( 'Delete %s for good', 'swiftling' ), $swiftling_name ) ); ?></button>
				</div>
			</div>
		</form>
	</dialog>
<?php endforeach; ?>
