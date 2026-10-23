<?php
/**
 * Settings screen, the General tab of Settings: what visitors see of content that is not translated
 * into their language, and starting over. Its address and file keep the name settings, from when it
 * was the only screen of settings.
 *
 * The first form posts to swiftling_save_settings() in includes/visibility.php, the confirmation of
 * starting over to swiftling_start_over() in includes/setup.php.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

// Starting over is in the header too, so it is found without scrolling to the end of the screen.
Ui::screenHeader(
	array(
		'crumb'   => __( 'Settings', 'swiftling' ),
		'title'   => __( 'General', 'swiftling' ),
		'actions' => array(
			array( __( 'Start over…', 'swiftling' ), 'danger', admin_url( 'admin.php?page=swiftling-settings&swiftling_confirm=1#start-over' ) ),
		),
	)
);

$swiftling_settings  = swiftling_settings();
$swiftling_languages = swiftling_languages();
$swiftling_types     = swiftling_viewable_types();
$swiftling_counts    = swiftling_untranslated_counts( array_keys( $swiftling_types ) );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the redirect.
$swiftling_notice  = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
$swiftling_removed = swiftling_start_over_counts();
$swiftling_word    = swiftling_start_over_word();

// What the index of translated text holds, for the search in the visitor's language.
$swiftling_index       = swiftling_search_state();
$swiftling_index_state = array(
	/* translators: %s: number of pages. */
	sprintf( _n( '%s page in the index', '%s pages in the index', $swiftling_index['pages'], 'swiftling' ), number_format_i18n( $swiftling_index['pages'] ) ),
	/* translators: %s: number of languages. */
	sprintf( _n( '%s language', '%s languages', $swiftling_index['languages'], 'swiftling' ), number_format_i18n( $swiftling_index['languages'] ) ),
);
if ( $swiftling_index['built'] ) {
	/* translators: %s: date, e.g. 3 April 2025. */
	$swiftling_index_state[] = sprintf( __( 'last built %s', 'swiftling' ), wp_date( get_option( 'date_format' ), $swiftling_index['built'] ) );
}
$swiftling_search_config = array(
	'rest'  => esc_url_raw( rest_url( 'swiftling/v1/' ) ),
	'nonce' => wp_create_nonce( 'wp_rest' ),
);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows the confirmation; the form has its own nonce.
$swiftling_confirm = isset( $_GET['swiftling_confirm'] );
?>
<?php if ( 'saved' === $swiftling_notice ) : ?>
	<?php Ui::notice( 'success', array( 'title' => __( 'Settings saved.', 'swiftling' ) ) ); ?>
<?php elseif ( 'confirm' === $swiftling_notice ) : ?>
	<?php /* translators: %s: the words to type, e.g. START OVER. */ ?>
	<?php Ui::notice( 'warning', array( 'text' => sprintf( __( 'Nothing was removed: type %s to confirm.', 'swiftling' ), $swiftling_word ) ) ); ?>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-settings-form">
	<input type="hidden" name="action" value="swiftling_save_settings">
	<?php wp_nonce_field( 'swiftling_save_settings' ); ?>

	<section class="swiftling-card">
		<h2><?php esc_html_e( 'Content types', 'swiftling' ); ?></h2>
		<p class="swiftling-muted"><?php esc_html_e( 'Which content is translated, and what visitors see of a post or page that is not translated into their language yet.', 'swiftling' ); ?></p>
		<div class="swiftling-table-wrap">
		<table class="swiftling-table swiftling-untranslated">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Content type', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Translated?', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'When it is not translated yet', 'swiftling' ); ?></th>
					<th><?php esc_html_e( 'Not translated now', 'swiftling' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $swiftling_types as $swiftling_type => $swiftling_object ) : ?>
					<?php
					$swiftling_id    = 'swiftling-untranslated-' . sanitize_html_class( $swiftling_type );
					$swiftling_never = in_array( $swiftling_type, $swiftling_settings['never'], true );
					$swiftling_hides = isset( $swiftling_settings['untranslated'][ $swiftling_type ] ) && 'hide' === $swiftling_settings['untranslated'][ $swiftling_type ];
					?>
					<tr class="<?php echo $swiftling_never ? 'is-never' : ''; ?>">
						<td><strong><?php echo esc_html( $swiftling_object->labels->name ); ?></strong></td>
						<td>
							<fieldset class="swiftling-segmented swiftling-untranslated__translated">
								<legend class="screen-reader-text">
									<?php
									/* translators: %s: name of a content type, e.g. Pages. */
									echo esc_html( sprintf( __( 'Are %s translated?', 'swiftling' ), $swiftling_object->labels->name ) );
									?>
								</legend>
								<label><input type="radio" name="translated[<?php echo esc_attr( $swiftling_type ); ?>]" value="yes"<?php echo $swiftling_never ? '' : ' checked'; ?>> <?php esc_html_e( 'Translate', 'swiftling' ); ?></label>
								<label><input type="radio" name="translated[<?php echo esc_attr( $swiftling_type ); ?>]" value="never"<?php echo $swiftling_never ? ' checked' : ''; ?>> <?php esc_html_e( 'Never', 'swiftling' ); ?></label>
							</fieldset>
						</td>
						<?php if ( $swiftling_never ) : ?>
							<td colspan="2" class="swiftling-muted"><?php esc_html_e( 'Visitors see the original in every language.', 'swiftling' ); ?> <?php esc_html_e( 'Not in Translations, the translation editor or the counts.', 'swiftling' ); ?></td>
						<?php else : ?>
							<td>
								<label class="screen-reader-text" for="<?php echo esc_attr( $swiftling_id ); ?>">
									<?php
									/* translators: %s: name of a content type, e.g. Pages. */
									echo esc_html( sprintf( __( 'When %s are not translated yet', 'swiftling' ), $swiftling_object->labels->name ) );
									?>
								</label>
								<select id="<?php echo esc_attr( $swiftling_id ); ?>" name="untranslated[<?php echo esc_attr( $swiftling_type ); ?>]">
									<option value="show" <?php selected( ! $swiftling_hides ); ?>><?php esc_html_e( 'Show it in the original language', 'swiftling' ); ?></option>
									<option value="hide" <?php selected( $swiftling_hides ); ?>><?php esc_html_e( 'Hide it in that language', 'swiftling' ); ?></option>
								</select>
							</td>
							<td>
								<?php // Each language as its code with its count; its name on hover, so many languages stay on a line or two. ?>
								<span class="swiftling-untranslated__counts">
									<?php foreach ( $swiftling_languages['targets'] as $swiftling_slug => $swiftling_language ) : ?>
										<span class="swiftling-untranslated__count" title="<?php echo esc_attr( $swiftling_language['name'] ); ?>">
											<?php Ui::lang( $swiftling_language['code'] ); ?>
											<?php echo esc_html( number_format_i18n( $swiftling_counts[ $swiftling_type ][ $swiftling_slug ] ?? 0 ) ); ?>
										</span>
									<?php endforeach; ?>
								</span>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<div class="swiftling-untranslated__note">
			<fieldset class="swiftling-choice">
				<legend><?php esc_html_e( 'A hidden post leaves the menus, lists, search and the switcher in that language. Opened by its address:', 'swiftling' ); ?></legend>
				<label><input type="radio" name="hidden_address" value="original" <?php checked( 'original', $swiftling_settings['hidden_address'] ); ?>> <?php esc_html_e( 'It opens in the original language', 'swiftling' ); ?></label>
				<label><input type="radio" name="hidden_address" value="404" <?php checked( '404', $swiftling_settings['hidden_address'] ); ?>> <?php esc_html_e( 'Not found (404)', 'swiftling' ); ?></label>
			</fieldset>
			<p class="swiftling-muted"><?php esc_html_e( 'A post counts as translated once every text of its own has a translation, machine or reviewed. Shared parts such as the header do not count. A post never opened in the translation editor is not translated yet.', 'swiftling' ); ?></p>
		</div>
	</section>

	<section class="swiftling-card swiftling-search-card">
		<h2><?php esc_html_e( 'Search', 'swiftling' ); ?></h2>
		<p class="swiftling-muted"><?php esc_html_e( 'What visitors find when they search in their own language.', 'swiftling' ); ?></p>

		<label class="swiftling-pick">
			<input type="checkbox" name="search" value="1" <?php checked( $swiftling_settings['search'] ); ?>>
			<span>
				<strong><?php esc_html_e( 'Find pages by their translated text', 'swiftling' ); ?></strong>
				<span><?php esc_html_e( 'A visitor who searches in their own language finds a page by its translated words. The words of the original keep finding it either way. Switched off, search reads the original language only.', 'swiftling' ); ?></span>
			</span>
		</label>

		<div class="swiftling-search-index">
			<div class="swiftling-search-index__head">
				<div>
					<h3><?php esc_html_e( 'Index of translated text', 'swiftling' ); ?></h3>
					<p class="swiftling-muted" id="swiftling-search-state"><?php echo esc_html( implode( ' · ', $swiftling_index_state ) ); ?></p>
				</div>
				<p class="swiftling-settings__build">
					<span class="swiftling-muted" id="swiftling-search-status" role="status"></span>
					<button type="button" class="swiftling-button is-secondary" id="swiftling-search-build"><?php esc_html_e( 'Build the index again', 'swiftling' ); ?></button>
				</p>
			</div>
			<p class="swiftling-muted"><?php esc_html_e( 'The index is written as you translate. Build it again after adding or removing a language, or if search misses a page you know is translated.', 'swiftling' ); ?></p>
			<?php if ( ! $swiftling_index['fulltext'] ) : ?>
				<p class="swiftling-muted"><?php esc_html_e( 'The database on this server made no full-text index for these texts, so the words are read from the rows themselves. Visitors find the same pages; on a large site it takes longer.', 'swiftling' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="swiftling-card__foot">
			<span></span>
			<button type="submit" class="swiftling-button is-primary"><?php esc_html_e( 'Save changes', 'swiftling' ); ?></button>
		</div>
	</section>
</form>
<script type="application/json" id="swiftling-search-config"><?php echo wp_json_encode( $swiftling_search_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & escaped, inside a JSON script element. ?></script>

<?php
$swiftling_per_language = array();
foreach ( $swiftling_removed['pages'] as $swiftling_slug => $swiftling_number ) {
	$swiftling_per_language[] = $swiftling_languages['targets'][ $swiftling_slug ]['name'] . ' ' . number_format_i18n( $swiftling_number );
}
?>
<section class="swiftling-card swiftling-start-over" id="start-over">
	<div class="swiftling-start-over__head">
		<span class="dashicons dashicons-warning" aria-hidden="true"></span>
		<div>
			<h2><?php esc_html_e( 'Start over', 'swiftling' ); ?></h2>
			<p><?php esc_html_e( 'Clears SwiftLing on this site and opens the first setup again, for example to choose another original language.', 'swiftling' ); ?></p>
		</div>
	</div>
	<div class="swiftling-start-over__lists">
		<div>
			<h3><?php esc_html_e( 'Removed', 'swiftling' ); ?></h3>
			<ul>
				<?php if ( $swiftling_per_language ) : ?>
					<?php // Counted as on the Overview: pages and posts with a translation, per language. ?>
					<li><?php echo esc_html( __( 'Translations of pages and posts:', 'swiftling' ) . ' ' . implode( ' · ', $swiftling_per_language ) ); ?></li>
				<?php endif; ?>
				<?php if ( $swiftling_removed['own'] ) : ?>
					<li><?php esc_html_e( 'Any translations of templates, menus, categories, options pages and texts from themes and plugins', 'swiftling' ); ?></li>
				<?php else : ?>
					<li><?php esc_html_e( 'No translations: none made yet', 'swiftling' ); ?></li>
				<?php endif; ?>
				<li><?php esc_html_e( 'The languages, their addresses and names', 'swiftling' ); ?></li>
				<li><?php esc_html_e( 'The switcher settings and flags, and these settings', 'swiftling' ); ?></li>
				<li><?php esc_html_e( 'The translation service, its key and what it translated this month', 'swiftling' ); ?></li>
				<li><?php esc_html_e( 'The words that are never translated, and which languages menu items show in', 'swiftling' ); ?></li>
				<li><?php esc_html_e( 'Where SwiftLing found the texts of your pages and templates; it looks again when you open them in the editor', 'swiftling' ); ?></li>
				<li><?php esc_html_e( 'The Logs', 'swiftling' ); ?></li>
				<?php
				/**
				 * Filters what Start over removes besides SwiftLing's own, so a plugin built on it,
				 * as SwiftLing Pro, names what it removes too. See docs/hooks.md.
				 *
				 * @param string[] $removed Lines of the list, plain text.
				 */
				foreach ( (array) apply_filters( 'swiftling_start_over_removed', array() ) as $swiftling_line ) :
					?>
					<li><?php echo esc_html( (string) $swiftling_line ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<div>
			<h3><?php esc_html_e( 'Kept', 'swiftling' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Your pages, posts, media and menus, untouched', 'swiftling' ); ?></li>
				<li><?php esc_html_e( 'Switcher blocks, shortcodes and widgets you placed; they show nothing until you add a language again', 'swiftling' ); ?></li>
				<li><?php esc_html_e( 'WordPress language packs, which WordPress itself uses, with their translations of WordPress, themes and plugins', 'swiftling' ); ?></li>
			</ul>
		</div>
	</div>
	<?php
	Ui::notice(
		'warning',
		array(
			'class' => 'swiftling-start-over__warning',
			'title' => __( 'This cannot be undone', 'swiftling' ),
			'text'  => __( 'What is removed is gone for good: there is no way back in SwiftLing. If you may want anything of it again, back up the database first. Deleting the plugin removes nothing; this is the one way to remove what SwiftLing keeps.', 'swiftling' ),
		)
	);
	?>
	<div class="swiftling-card__foot">
		<span></span>
		<?php // The script opens the confirmation as a dialog; without it, the link opens the screen with the confirmation shown in place. ?>
		<a class="swiftling-button is-danger-solid is-large" href="<?php echo esc_url( admin_url( 'admin.php?page=swiftling-settings&swiftling_confirm=1#start-over' ) ); ?>" data-swiftling-open="swiftling-start-over-dialog"><?php esc_html_e( 'Start over…', 'swiftling' ); ?></a>
	</div>

	<dialog id="swiftling-start-over-dialog" class="swiftling-dialog" aria-labelledby="swiftling-start-over-title" <?php echo $swiftling_confirm ? 'open' : ''; ?>>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-dialog__form">
			<input type="hidden" name="action" value="swiftling_start_over">
			<?php wp_nonce_field( 'swiftling_start_over' ); ?>
			<h2 id="swiftling-start-over-title"><?php esc_html_e( 'Start over?', 'swiftling' ); ?></h2>
			<p class="swiftling-muted"><?php esc_html_e( 'The site keeps working in its original language while you set it up again.', 'swiftling' ); ?></p>
			<fieldset class="swiftling-dialog__choices">
				<legend class="screen-reader-text"><?php esc_html_e( 'What to remove', 'swiftling' ); ?></legend>
				<label>
					<input type="radio" name="scope" value="everything" checked>
					<span>
						<strong><?php esc_html_e( 'Remove everything', 'swiftling' ); ?></strong>
						<span class="swiftling-muted">
							<?php
							echo esc_html(
								$swiftling_removed['own']
									? __( 'Every translation, the languages, the settings and the translation service with its key: everything listed. This cannot be undone.', 'swiftling' )
									: __( 'The languages, the settings and the translation service with its key: everything listed. This cannot be undone.', 'swiftling' )
							);
							?>
						</span>
					</span>
				</label>
				<label>
					<input type="radio" name="scope" value="keep">
					<span>
						<strong><?php esc_html_e( 'Keep the translations', 'swiftling' ); ?></strong>
						<span class="swiftling-muted"><?php esc_html_e( 'Only the languages and settings go; the translation service stays. Add a language again and its translations come back.', 'swiftling' ); ?></span>
					</span>
				</label>
			</fieldset>
			<label class="swiftling-dialog__confirm">
				<?php /* translators: %s: the words to type, e.g. START OVER. */ ?>
				<span><?php echo esc_html( sprintf( __( 'Type %s to confirm', 'swiftling' ), $swiftling_word ) ); ?></span>
				<input type="text" class="swiftling-mono" name="confirm" autocomplete="off" data-swiftling-confirm="<?php echo esc_attr( $swiftling_word ); ?>">
			</label>
			<div class="swiftling-dialog__foot">
				<a class="swiftling-button is-link" href="<?php echo esc_url( admin_url( 'admin.php?page=swiftling-settings#start-over' ) ); ?>" data-swiftling-close><?php esc_html_e( 'Cancel', 'swiftling' ); ?></a>
				<button type="submit" class="swiftling-button is-danger-solid" data-swiftling-submit><?php esc_html_e( 'Start over', 'swiftling' ); ?></button>
			</div>
		</form>
	</dialog>
</section>
