<?php
/**
 * Themes & plugins screen: finding the texts of the theme and plugins, and where they are translated.
 *
 * Texts are found only when the user asks: by recording while browsing, by scanning the settings of
 * one theme or plugin, or by scanning everything in its code. See includes/packages.php. With a
 * package in the address the screen shows the scan of its settings (view=choose) or of its code
 * (view=scan).
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;
use SwiftLing\Packages\Catalogue;
use SwiftLing\Packages\CodeScanner;
use SwiftLing\Packages\Packages;
use SwiftLing\Packages\Recorder;
use SwiftLing\Packages\Settings;
use SwiftLing\Packages\Texts;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
$swiftling_package_id = isset( $_GET['package'] ) ? sanitize_text_field( wp_unslash( $_GET['package'] ) ) : '';
$swiftling_view       = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
$swiftling_notice     = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
// phpcs:enable

if ( ! swiftling_languages()['targets'] ) {
	Ui::screenHeader(
		array(
			'crumb' => __( 'Translations', 'swiftling' ),
			'title' => __( 'Themes & plugins', 'swiftling' ),
		)
	);
	Ui::emptyState(
		__( 'Choose the languages first', 'swiftling' ),
		__( 'Texts are translated into the languages you choose.', 'swiftling' ),
		array( __( 'Add languages', 'swiftling' ), admin_url( 'admin.php?page=swiftling-languages' ) )
	);
	return;
}

if ( '' !== $swiftling_package_id ) {
	$swiftling_package = Packages::get( $swiftling_package_id );
	if ( ! $swiftling_package ) {
		Ui::screenHeader(
			array(
				'crumb' => __( 'Translations', 'swiftling' ),
				'title' => __( 'Themes & plugins', 'swiftling' ),
			)
		);
		Ui::emptyState(
			__( 'This theme or plugin is not active', 'swiftling' ),
			__( 'Only the texts of the active theme and plugins are found and translated.', 'swiftling' ),
			array( __( 'Back to themes and plugins', 'swiftling' ), swiftling_package_url() )
		);
		return;
	}
	// Scanning a theme or plugin and choosing its settings are settings of the site, not translating.
	if ( ! current_user_can( 'manage_options' ) ) {
		Ui::screenHeader(
			array(
				'crumb' => __( 'Translations', 'swiftling' ),
				'title' => $swiftling_package['name'],
			)
		);
		Ui::emptyState(
			__( 'Only whoever manages the site scans themes and plugins', 'swiftling' ),
			__( 'The texts found so far are translated from the list of themes and plugins.', 'swiftling' ),
			array( __( 'Back to themes and plugins', 'swiftling' ), swiftling_package_url() )
		);
		return;
	}
	include __DIR__ . '/' . ( 'scan' === $swiftling_view ? 'themes-plugins-scan.php' : 'themes-plugins-choose.php' );
	return;
}

Ui::screenHeader(
	array(
		'crumb'       => __( 'Translations', 'swiftling' ),
		'title'       => __( 'Themes & plugins', 'swiftling' ),
		'description' => __( 'Texts of your theme and plugins: from their settings, such as a footer text or a tracking code, and from their code, such as a button. SwiftLing looks for them only when you ask. Colours, fonts and layout stay the same in every language.', 'swiftling' ),
	)
);

$swiftling_recording = Recorder::current();
$swiftling_last      = Recorder::last();
$swiftling_packages  = Packages::all();
$swiftling_counts    = Catalogue::counts();
$swiftling_scans     = CodeScanner::scans();
/**
 * Prints the heading of a column with an explanation that shows on hover and on focus.
 *
 * @param string $label Heading.
 * @param string $text  What the column shows.
 * @param string $id    ID of the explanation.
 */
$swiftling_tip      = static function ( $label, $text, $id ) {
	?>
	<span class="swiftling-tip">
		<?php echo esc_html( $label ); ?>
		<button type="button" class="swiftling-tip__button" aria-describedby="<?php echo esc_attr( $id ); ?>">
			<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
			<span class="screen-reader-text">
				<?php
				/* translators: %s: heading of a column, e.g. Settings. */
				echo esc_html( sprintf( __( 'What “%s” means', 'swiftling' ), $label ) );
				?>
			</span>
		</button>
		<span class="swiftling-tip__text" role="tooltip" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $text ); ?></span>
	</span>
	<?php
};
$swiftling_messages = array(
	'recording_stopped' => array( 'is-success', __( 'The recording stopped. What it found is listed below.', 'swiftling' ) ),
	'recording_pages'   => array( 'is-info', __( 'Recording pages of the site: open the site as a visitor would, in a page of your own language or in another one. A panel on each page shows what SwiftLing found; stop it there or here.', 'swiftling' ) ),
	'recording_admin'   => array( 'is-info', __( 'Recording settings screens: open the settings of a theme or plugin. A panel there shows what SwiftLing found.', 'swiftling' ) ),
	'recording_nothing' => array( 'is-info', __( 'Choose what to record: pages of the site, settings screens, or both.', 'swiftling' ) ),
);
?>
<?php if ( isset( $swiftling_messages[ $swiftling_notice ] ) ) : ?>
	<?php Ui::notice( substr( $swiftling_messages[ $swiftling_notice ][0], 3 ), array( 'text' => $swiftling_messages[ $swiftling_notice ][1] ) ); ?>
<?php endif; ?>

<div class="swiftling-stack">
<?php
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$swiftling_installed = count( get_plugins() );
$swiftling_active    = count( (array) get_option( 'active_plugins', array() ) );
$swiftling_acf       = \SwiftLing\Builders\Registry::get( 'acf' );
?>
<section class="swiftling-callout" aria-labelledby="swiftling-active-title">
	<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
	<div>
		<h2 id="swiftling-active-title"><?php esc_html_e( 'Only the active theme and active plugins are listed', 'swiftling' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of active plugins, 2: number of installed plugins. */
					_n( '%1$s of %2$s installed plugin is active.', '%1$s of %2$s installed plugins are active.', $swiftling_installed, 'swiftling' ),
					number_format_i18n( $swiftling_active ),
					number_format_i18n( $swiftling_installed )
				)
			);
			?>
			<?php esc_html_e( 'An inactive theme or plugin shows nothing on your site, so it has nothing to translate yet. Activate it first, then find its texts here.', 'swiftling' ); ?>
			<?php if ( $swiftling_acf && $swiftling_acf->isActive() ) : ?>
				<?php esc_html_e( 'The fields and options pages of Advanced Custom Fields are translated on the Templates screen; only its own texts are here.', 'swiftling' ); ?>
			<?php endif; ?>
		</p>
	</div>
	<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
		<?php Ui::button( __( 'Open Plugins', 'swiftling' ), 'secondary', admin_url( 'plugins.php' ) ); ?>
	<?php endif; ?>
</section>

<?php // Finding texts, by recording or scanning, is the right of whoever manages the site. ?>
<?php if ( current_user_can( 'manage_options' ) ) : ?>
<section class="swiftling-card is-flush swiftling-find" aria-labelledby="swiftling-find-title">
	<div class="swiftling-card__head">
		<h2 id="swiftling-find-title"><?php esc_html_e( 'Find texts', 'swiftling' ); ?></h2>
		<p class="swiftling-muted"><?php esc_html_e( 'Opening a page in the translation editor still finds the texts of that page, as before.', 'swiftling' ); ?></p>
	</div>

	<div class="swiftling-find__ways">
		<div class="swiftling-find__way">
			<h3>
				<?php esc_html_e( 'Record while you browse', 'swiftling' ); ?>
				<?php Ui::badge( 'working', __( 'Recommended', 'swiftling' ) ); ?>
			</h3>
			<?php if ( $swiftling_recording ) : ?>
				<?php
				$swiftling_found = 0;
				foreach ( (array) $swiftling_recording['found'] as $swiftling_count ) {
					$swiftling_found += (int) $swiftling_count;
				}
				?>
				<p class="swiftling-find__live" role="status">
					<?php
					echo esc_html(
						$swiftling_recording['paused']
							? __( 'Paused.', 'swiftling' )
							: sprintf(
								/* translators: %s: minutes, e.g. 27. */
								_n( 'Recording: %s minute left.', 'Recording: %s minutes left.', Recorder::minutesLeft(), 'swiftling' ),
								number_format_i18n( Recorder::minutesLeft() )
							)
					);
					?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of texts, 2: number of pages. */
							_n( '%1$s new text on %2$s pages so far.', '%1$s new texts on %2$s pages so far.', $swiftling_found, 'swiftling' ),
							number_format_i18n( $swiftling_found ),
							number_format_i18n( count( (array) $swiftling_recording['checked'] ) )
						)
					);
					?>
				</p>
				<p class="swiftling-muted">
					<?php
					if ( $swiftling_recording['pages'] && $swiftling_recording['admin'] ) {
						esc_html_e( 'Open pages of the site and the settings of your theme and plugins.', 'swiftling' );
					} elseif ( $swiftling_recording['pages'] ) {
						esc_html_e( 'Open pages of the site as a visitor would.', 'swiftling' );
					} else {
						esc_html_e( 'Open the settings of your theme and plugins.', 'swiftling' );
					}
					?>
				</p>
				<div class="swiftling-find__actions">
					<?php
					if ( $swiftling_recording['pages'] ) {
						Ui::button( __( 'Open the site', 'swiftling' ), 'primary', home_url( '/' ), 'external' );
					}
					Ui::button( $swiftling_recording['paused'] ? __( 'Resume', 'swiftling' ) : __( 'Pause', 'swiftling' ), 'secondary', Recorder::actionUrl( $swiftling_recording['paused'] ? 'resume' : 'pause' ) );
					Ui::button( __( 'Stop', 'swiftling' ), 'secondary', Recorder::actionUrl( 'stop' ) );
					?>
				</div>
			<?php else : ?>
				<p class="swiftling-muted"><?php esc_html_e( 'Turn it on, then open pages as usual. SwiftLing notes only the texts of your theme and plugins those pages show, never their content. It stops by itself after 30 minutes.', 'swiftling' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-find__form">
					<input type="hidden" name="action" value="swiftling_recording">
					<input type="hidden" name="operation" value="start">
					<?php wp_nonce_field( 'swiftling_recording' ); ?>
					<label class="swiftling-find__option">
						<input type="checkbox" name="pages" value="1" checked>
						<span><strong><?php esc_html_e( 'Pages of the site', 'swiftling' ); ?></strong><br><span class="swiftling-muted"><?php esc_html_e( 'What visitors see: the shop, forms, a cookie notice.', 'swiftling' ); ?></span></span>
					</label>
					<label class="swiftling-find__option">
						<input type="checkbox" name="admin" value="1">
						<span><strong><?php esc_html_e( 'Settings screens in the admin', 'swiftling' ); ?></strong><br><span class="swiftling-muted"><?php esc_html_e( 'Which settings a theme or plugin keeps there, so a scan lists them all.', 'swiftling' ); ?></span></span>
					</label>
					<button type="submit" class="swiftling-button is-primary"><?php esc_html_e( 'Start recording', 'swiftling' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<div class="swiftling-find__way">
			<h3><?php esc_html_e( 'Scan settings', 'swiftling' ); ?></h3>
			<p class="swiftling-muted"><?php esc_html_e( 'Reads the saved settings of one theme or plugin and lists the values that could be text, also code such as a tracking script. You tick which to translate.', 'swiftling' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="swiftling-find__form">
				<input type="hidden" name="page" value="swiftling-themes-plugins">
				<input type="hidden" name="view" value="choose">
				<input type="hidden" name="check" value="1">
				<label for="swiftling-scan-settings"><?php esc_html_e( 'Theme or plugin', 'swiftling' ); ?></label>
				<select id="swiftling-scan-settings" name="package">
					<?php foreach ( $swiftling_packages as $swiftling_id => $swiftling_item ) : ?>
						<option value="<?php echo esc_attr( $swiftling_id ); ?>"><?php echo esc_html( 'theme' === $swiftling_item['type'] ? sprintf( /* translators: %s: name of a theme. */ __( '%s (theme)', 'swiftling' ), $swiftling_item['name'] ) : $swiftling_item['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Scan settings', 'swiftling' ); ?></button>
			</form>
		</div>

		<div class="swiftling-find__way">
			<h3><?php esc_html_e( 'Scan everything', 'swiftling' ); ?></h3>
			<p class="swiftling-muted"><?php esc_html_e( 'Lists every text in the code of one theme or plugin, also those your site never shows. A shop plugin has thousands; most come translated by its own translators.', 'swiftling' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="swiftling-find__form">
				<input type="hidden" name="page" value="swiftling-themes-plugins">
				<input type="hidden" name="view" value="scan">
				<input type="hidden" name="start" value="1">
				<label for="swiftling-scan-code"><?php esc_html_e( 'Theme or plugin', 'swiftling' ); ?></label>
				<select id="swiftling-scan-code" name="package">
					<?php foreach ( $swiftling_packages as $swiftling_id => $swiftling_item ) : ?>
						<option value="<?php echo esc_attr( $swiftling_id ); ?>"><?php echo esc_html( 'theme' === $swiftling_item['type'] ? sprintf( /* translators: %s: name of a theme. */ __( '%s (theme)', 'swiftling' ), $swiftling_item['name'] ) : $swiftling_item['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Scan everything', 'swiftling' ); ?></button>
			</form>
		</div>
	</div>
</section>
<?php endif; ?>

<section class="swiftling-card is-flush" aria-labelledby="swiftling-found-title">
	<div class="swiftling-card__head">
		<h2 id="swiftling-found-title"><?php esc_html_e( 'Found so far', 'swiftling' ); ?></h2>
		<?php if ( ! $swiftling_recording && $swiftling_last && ! empty( $swiftling_last['started'] ) && ! empty( $swiftling_last['checked'] ) ) : ?>
			<p class="swiftling-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: date and time, 2: number of pages. */
						_n( 'Last recording: %1$s, %2$s page', 'Last recording: %1$s, %2$s pages', count( (array) $swiftling_last['checked'] ), 'swiftling' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $swiftling_last['started'] ),
						number_format_i18n( count( (array) $swiftling_last['checked'] ) )
					)
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<div class="swiftling-table-wrap">
		<table class="swiftling-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Theme or plugin', 'swiftling' ); ?></th>
					<th><?php $swiftling_tip( __( 'On your pages', 'swiftling' ), __( 'Texts from its code that pages of your site showed: found while recording, or when a page was opened in the translation editor. These are the texts visitors read.', 'swiftling' ), 'swiftling-tip-pages' ); ?></th>
					<th><?php $swiftling_tip( __( 'Settings', 'swiftling' ), __( 'Values of its settings you chose to translate, such as a footer text or a tracking code. “Not scanned” until you use Scan settings.', 'swiftling' ), 'swiftling-tip-settings' ); ?></th>
					<th><?php $swiftling_tip( __( 'In the code', 'swiftling' ), __( 'Every text its code can show, also on pages you never opened and in emails, found with Scan everything. Most come translated by its own translators.', 'swiftling' ), 'swiftling-tip-code' ); ?></th>
					<?php foreach ( swiftling_languages()['targets'] as $swiftling_slug => $swiftling_language ) : ?>
						<th class="is-center">
							<?php
							$swiftling_tip(
								$swiftling_language['code'],
								sprintf(
									/* translators: %s: language name, e.g. Deutsch. */
									__( 'How many of its texts on your pages and chosen settings have a translation in %s: yours, or one its own translators made.', 'swiftling' ),
									$swiftling_language['name']
								),
								'swiftling-tip-lang-' . $swiftling_slug
							);
							?>
						</th>
					<?php endforeach; ?>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swiftling' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $swiftling_packages as $swiftling_id => $swiftling_item ) : ?>
					<?php
					$swiftling_seen = isset( $swiftling_counts[ $swiftling_id ] ) ? $swiftling_counts[ $swiftling_id ]['seen'] : 0;
					// The list of all texts in the code holds those pages showed too.
					$swiftling_scanned = isset( $swiftling_counts[ $swiftling_id ] ) ? $swiftling_counts[ $swiftling_id ]['all'] : 0;
					$swiftling_chosen  = count( Settings::chosen( $swiftling_id ) );
					$swiftling_done    = ! empty( $swiftling_scans[ $swiftling_id ]['finished'] );
					$swiftling_any     = $swiftling_seen || $swiftling_chosen;
					$swiftling_first   = $swiftling_seen ? 'strings' : ( $swiftling_chosen ? 'settings' : ( $swiftling_done ? 'code' : '' ) );
					$swiftling_states  = $swiftling_any ? Texts::progress( $swiftling_id ) : array();
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $swiftling_item['name'] ); ?></strong>
							<br><span class="swiftling-muted"><?php echo esc_html( 'theme' === $swiftling_item['type'] ? __( 'Theme', 'swiftling' ) : __( 'Plugin', 'swiftling' ) ); ?></span>
						</td>
						<td><?php echo esc_html( $swiftling_seen ? number_format_i18n( $swiftling_seen ) : '—' ); ?></td>
						<td>
							<?php
							echo esc_html(
								Settings::chosenAt( $swiftling_id )
									/* translators: %s: number of settings. */
									? sprintf( _n( '%s chosen', '%s chosen', $swiftling_chosen, 'swiftling' ), number_format_i18n( $swiftling_chosen ) )
									: __( 'Not scanned', 'swiftling' )
							);
							?>
						</td>
						<td>
							<?php
							if ( ! $swiftling_done ) {
								esc_html_e( 'Not scanned', 'swiftling' );
							} elseif ( (string) ( $swiftling_scans[ $swiftling_id ]['version'] ?? '' ) !== $swiftling_item['version'] ) {
								/* translators: %s: number of texts. */
								echo esc_html( sprintf( __( '%s, before the last update', 'swiftling' ), number_format_i18n( $swiftling_scanned ) ) );
							} else {
								echo esc_html( number_format_i18n( $swiftling_scanned ) );
							}
							?>
						</td>
						<?php foreach ( array_keys( swiftling_languages()['targets'] ) as $swiftling_slug ) : ?>
							<td class="is-center">
								<?php if ( isset( $swiftling_states[ $swiftling_slug ] ) && $swiftling_states[ $swiftling_slug ]['total'] ) : ?>
									<?php
									$swiftling_progress = $swiftling_states[ $swiftling_slug ];
									Ui::statusIcon( $swiftling_progress['translated'] >= $swiftling_progress['total'] ? 'reviewed' : ( $swiftling_progress['translated'] ? 'partial' : 'missing' ) );
									?>
									<span class="swiftling-muted">
										<?php
										/* translators: 1: texts translated, 2: all texts, as in 24/28. */
										echo esc_html( sprintf( __( '%1$s/%2$s', 'swiftling' ), number_format_i18n( $swiftling_progress['translated'] ), number_format_i18n( $swiftling_progress['total'] ) ) );
										?>
									</span>
								<?php else : ?>
									<span class="swiftling-muted">—</span>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
						<td class="is-actions">
							<?php
							if ( '' !== $swiftling_first ) {
								Ui::button( __( 'Translate', 'swiftling' ), 'secondary', swiftling_package_url( $swiftling_id, $swiftling_first ) );
							} elseif ( current_user_can( 'manage_options' ) ) {
								Ui::button( __( 'Scan settings', 'swiftling' ), 'link', add_query_arg( 'check', '1', swiftling_package_url( $swiftling_id, 'choose' ) ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
</div>
