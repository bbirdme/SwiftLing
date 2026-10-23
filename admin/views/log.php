<?php
/**
 * Log screen: what failed, and what SwiftLing did, see includes/log.php. Filters by level, area
 * and words; clearing the log after a confirmation, how long entries are kept, and the report to
 * copy for support.
 *
 * The forms post to swiftling_handle_clear_log() and swiftling_save_log_keep() in includes/log.php.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters of the screen and notices after a redirect.
$swiftling_level   = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
$swiftling_area    = isset( $_GET['area'] ) ? sanitize_key( wp_unslash( $_GET['area'] ) ) : '';
$swiftling_search  = isset( $_GET['find'] ) ? sanitize_text_field( wp_unslash( $_GET['find'] ) ) : '';
$swiftling_paged   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$swiftling_notice  = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
$swiftling_confirm = isset( $_GET['swiftling_confirm'] );
// phpcs:enable

$swiftling_levels = swiftling_log_levels();
$swiftling_areas  = swiftling_log_areas();
$swiftling_log    = swiftling_log_entries(
	array(
		'level'  => $swiftling_level,
		'area'   => $swiftling_area,
		'search' => $swiftling_search,
		'page'   => $swiftling_paged,
	)
);
$swiftling_total  = array_sum( $swiftling_log['counts'] );
$swiftling_base   = admin_url( 'admin.php?page=swiftling-log' );
$swiftling_link   = static function ( array $more ) use ( $swiftling_base, $swiftling_level, $swiftling_area, $swiftling_search ) {
	$query = array_merge(
		array(
			'level' => $swiftling_level,
			'area'  => $swiftling_area,
			'find'  => $swiftling_search,
		),
		$more
	);
	return add_query_arg( array_map( 'rawurlencode', array_filter( array_map( 'strval', $query ) ) ), $swiftling_base );
};

$swiftling_actions = array( array( __( 'Copy for support', 'swiftling' ), 'secondary', '#swiftling-support-report' ) );
if ( $swiftling_total ) {
	$swiftling_actions[] = array( __( 'Clear logs…', 'swiftling' ), 'danger', add_query_arg( 'swiftling_confirm', '1', $swiftling_base ) );
}
Ui::screenHeader(
	array(
		'crumb'       => __( 'Logs', 'swiftling' ),
		'title'       => __( 'Logs', 'swiftling' ),
		'description' => __( 'What went wrong, and what SwiftLing did that you may want to know later. No content of your pages, keys or visitors’ data is kept here.', 'swiftling' ),
		'actions'     => $swiftling_actions,
	)
);
?>
<?php if ( 'saved' === $swiftling_notice ) : ?>
	<?php Ui::notice( 'success', array( 'title' => __( 'Settings saved.', 'swiftling' ) ) ); ?>
<?php elseif ( 'cleared' === $swiftling_notice ) : ?>
	<?php Ui::notice( 'success', array( 'title' => __( 'The logs are empty.', 'swiftling' ) ) ); ?>
<?php endif; ?>

<?php if ( $swiftling_total ) : ?>
	<?php // The script opens the confirmation as a dialog; without it, the link opens the screen with the confirmation shown in place. ?>
	<dialog id="swiftling-clear-log-dialog" class="swiftling-dialog" aria-labelledby="swiftling-clear-log-title" <?php echo $swiftling_confirm ? 'open' : ''; ?>>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-dialog__form">
			<input type="hidden" name="action" value="swiftling_clear_log">
			<?php wp_nonce_field( 'swiftling_clear_log' ); ?>
			<h2 id="swiftling-clear-log-title">
				<?php
				/* translators: %s: number of entries. */
				echo esc_html( sprintf( _n( 'Clear %s entry?', 'Clear all %s entries?', $swiftling_total, 'swiftling' ), number_format_i18n( $swiftling_total ) ) );
				?>
			</h2>
			<p><?php esc_html_e( 'This cannot be undone.', 'swiftling' ); ?></p>
			<div class="swiftling-dialog__foot">
				<a class="swiftling-button is-link" href="<?php echo esc_url( $swiftling_base ); ?>" data-swiftling-close><?php esc_html_e( 'Cancel', 'swiftling' ); ?></a>
				<button type="submit" class="swiftling-button is-danger-solid"><?php esc_html_e( 'Clear logs', 'swiftling' ); ?></button>
			</div>
		</form>
	</dialog>
<?php endif; ?>

<div class="swiftling-stack">
	<section class="swiftling-card is-flush">
		<div class="swiftling-list-filters swiftling-log-filters">
			<div class="swiftling-list-filters__shows" role="group" aria-label="<?php esc_attr_e( 'Show', 'swiftling' ); ?>">
				<a class="swiftling-filter-pill<?php echo '' === $swiftling_level ? ' is-current' : ''; ?>" href="<?php echo esc_url( $swiftling_link( array( 'level' => '' ) ) ); ?>"<?php echo '' === $swiftling_level ? ' aria-current="true"' : ''; ?>>
					<?php esc_html_e( 'All', 'swiftling' ); ?>
					<span class="swiftling-filter-pill__count"><?php echo esc_html( number_format_i18n( $swiftling_total ) ); ?></span>
				</a>
				<?php foreach ( swiftling_log_level_filters() as $swiftling_key => $swiftling_name ) : ?>
					<a class="swiftling-filter-pill<?php echo $swiftling_key === $swiftling_level ? ' is-current' : ''; ?>" href="<?php echo esc_url( $swiftling_link( array( 'level' => $swiftling_key ) ) ); ?>"<?php echo $swiftling_key === $swiftling_level ? ' aria-current="true"' : ''; ?>>
						<?php echo esc_html( $swiftling_name ); ?>
						<span class="swiftling-filter-pill__count"><?php echo esc_html( number_format_i18n( $swiftling_log['counts'][ $swiftling_key ] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="swiftling-list-filters__search" role="search">
				<input type="hidden" name="page" value="swiftling-log">
				<?php if ( '' !== $swiftling_level ) : ?>
					<input type="hidden" name="level" value="<?php echo esc_attr( $swiftling_level ); ?>">
				<?php endif; ?>
				<label class="screen-reader-text" for="swiftling-log-area"><?php esc_html_e( 'Area', 'swiftling' ); ?></label>
				<select id="swiftling-log-area" name="area">
					<option value=""><?php esc_html_e( 'All areas', 'swiftling' ); ?></option>
					<?php foreach ( $swiftling_areas as $swiftling_key => $swiftling_name ) : ?>
						<option value="<?php echo esc_attr( $swiftling_key ); ?>" <?php selected( $swiftling_area, $swiftling_key ); ?>><?php echo esc_html( $swiftling_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="swiftling-log-find"><?php esc_html_e( 'Search the logs', 'swiftling' ); ?></label>
				<input type="search" id="swiftling-log-find" name="find" value="<?php echo esc_attr( $swiftling_search ); ?>" placeholder="<?php esc_attr_e( 'Search the logs', 'swiftling' ); ?>">
				<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Show', 'swiftling' ); ?></button>
			</form>
		</div>

		<?php if ( ! $swiftling_log['entries'] ) : ?>
			<p class="swiftling-log-empty swiftling-muted">
				<?php
				echo esc_html(
					$swiftling_total
						? __( 'No entry matches. Show all to see every entry.', 'swiftling' )
						: __( 'Nothing logged yet. Failures and what SwiftLing did show here once they happen.', 'swiftling' )
				);
				?>
			</p>
		<?php else : ?>
			<div class="swiftling-table-wrap">
				<table class="swiftling-table swiftling-log-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'swiftling' ); ?></th>
							<th><?php esc_html_e( 'Level', 'swiftling' ); ?></th>
							<th><?php esc_html_e( 'Area', 'swiftling' ); ?></th>
							<th><?php esc_html_e( 'What happened', 'swiftling' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $swiftling_log['entries'] as $swiftling_entry ) : ?>
							<?php $swiftling_edit = 0 === strpos( (string) $swiftling_entry->object_key, 'post:' ) ? swiftling_editor_url( (string) $swiftling_entry->object_key ) : ''; ?>
							<tr>
								<td class="swiftling-log-when"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $swiftling_entry->logged ) ); ?></td>
								<td><span class="swiftling-log-level is-<?php echo esc_attr( $swiftling_entry->level ); ?>"><?php echo esc_html( isset( $swiftling_levels[ $swiftling_entry->level ] ) ? $swiftling_levels[ $swiftling_entry->level ] : $swiftling_entry->level ); ?></span></td>
								<td class="swiftling-muted"><?php echo esc_html( isset( $swiftling_areas[ $swiftling_entry->area ] ) ? $swiftling_areas[ $swiftling_entry->area ] : $swiftling_entry->area ); ?></td>
								<td>
									<?php echo esc_html( $swiftling_entry->message ); ?>
									<?php if ( (int) $swiftling_entry->repeats > 1 ) : ?>
										<span class="swiftling-log-repeats">
											<?php
											/* translators: %s: how many times the same entry happened. */
											echo esc_html( sprintf( __( '×%s', 'swiftling' ), number_format_i18n( (int) $swiftling_entry->repeats ) ) );
											?>
										</span>
									<?php endif; ?>
									<?php if ( '' !== $swiftling_entry->detail || '' !== $swiftling_entry->object_key ) : ?>
										<span class="swiftling-log-detail">
											<?php echo esc_html( trim( $swiftling_entry->object_key . ' · ' . $swiftling_entry->detail, ' ·' ) ); ?>
											<?php if ( '' !== $swiftling_edit ) : ?>
												· <a href="<?php echo esc_url( $swiftling_edit ); ?>"><?php esc_html_e( 'Open in the editor', 'swiftling' ); ?></a>
											<?php endif; ?>
										</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $swiftling_log['pages'] > 1 ) : ?>
				<nav class="swiftling-list-pages" aria-label="<?php esc_attr_e( 'Pages of the logs', 'swiftling' ); ?>">
					<span class="swiftling-muted">
						<?php
						/* translators: 1: page shown, 2: pages in all. */
						echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'swiftling' ), number_format_i18n( $swiftling_log['page'] ), number_format_i18n( $swiftling_log['pages'] ) ) );
						?>
					</span>
					<?php if ( $swiftling_log['page'] > 1 ) : ?>
						<a class="swiftling-button is-secondary" href="<?php echo esc_url( $swiftling_link( array( 'paged' => $swiftling_log['page'] - 1 ) ) ); ?>"><?php esc_html_e( 'Newer', 'swiftling' ); ?></a>
					<?php endif; ?>
					<?php if ( $swiftling_log['page'] < $swiftling_log['pages'] ) : ?>
						<a class="swiftling-button is-secondary" href="<?php echo esc_url( $swiftling_link( array( 'paged' => $swiftling_log['page'] + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'swiftling' ); ?></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<section class="swiftling-card">
		<h2><?php esc_html_e( 'Keep the logs', 'swiftling' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-log-keep">
			<input type="hidden" name="action" value="swiftling_save_log_keep">
			<?php wp_nonce_field( 'swiftling_save_log_keep' ); ?>
			<label for="swiftling-log-keep"><?php esc_html_e( 'Keep entries for', 'swiftling' ); ?></label>
			<select id="swiftling-log-keep" name="keep">
				<?php foreach ( SWIFTLING_LOG_KEEP_CHOICES as $swiftling_days ) : ?>
					<option value="<?php echo esc_attr( (string) $swiftling_days ); ?>" <?php selected( swiftling_log_keep_days(), $swiftling_days ); ?>>
						<?php
						/* translators: %s: number of days. */
						echo esc_html( sprintf( _n( '%s day', '%s days', $swiftling_days, 'swiftling' ), number_format_i18n( $swiftling_days ) ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Save', 'swiftling' ); ?></button>
		</form>
		<p class="swiftling-muted">
			<?php
			/* translators: %s: most entries the log keeps, e.g. 1,000. */
			echo esc_html( sprintf( __( 'Older ones are deleted once a day, and the logs never hold more than the last %s.', 'swiftling' ), number_format_i18n( SWIFTLING_LOG_LIMIT ) ) );
			?>
		</p>
	</section>

	<?php $swiftling_report = swiftling_log_report(); ?>
	<section class="swiftling-card swiftling-support" id="swiftling-support-report">
		<div class="swiftling-support__head">
			<div>
				<h2><?php esc_html_e( 'Copy for support', 'swiftling' ); ?></h2>
				<p class="swiftling-muted"><?php esc_html_e( 'The versions and setup of this site and the latest errors and warnings, to paste into a question on the support forum or an email. Read it, and change it if you like, before you send it.', 'swiftling' ); ?></p>
			</div>
			<p class="swiftling-support__copy">
				<span class="swiftling-muted" id="swiftling-support-copied" role="status"></span>
				<button type="button" class="swiftling-button is-primary" id="swiftling-support-copy">
					<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M5.5 5.5V3a1 1 0 0 1 1-1H13a1 1 0 0 1 1 1v6.5a1 1 0 0 1-1 1h-2.5M3 5.5h6.5a1 1 0 0 1 1 1V13a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1z"/></svg>
					<?php esc_html_e( 'Copy', 'swiftling' ); ?>
				</button>
			</p>
		</div>
		<div class="swiftling-support__report">
			<div class="swiftling-support__bar">
				<svg class="swiftling-support__bird" viewBox="0 0 64.86 36.57" aria-hidden="true" focusable="false"><path d="<?php echo esc_attr( Ui::BIRD ); ?>"/></svg>
				<span class="swiftling-mono"><?php esc_html_e( 'Report for support', 'swiftling' ); ?></span>
				<span class="swiftling-support__safe">
					<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3.5 8.4l2.9 2.9L12.5 5"/></svg>
					<?php esc_html_e( 'No content, keys, passwords or email addresses', 'swiftling' ); ?>
				</span>
			</div>
			<label class="screen-reader-text" for="swiftling-support-text"><?php esc_html_e( 'Report for support', 'swiftling' ); ?></label>
			<?php // As many rows as the report has lines, so it reads without scrolling. It can be changed: Copy copies what it holds. ?>
			<textarea id="swiftling-support-text" class="swiftling-support__text" rows="<?php echo esc_attr( (string) ( substr_count( $swiftling_report, "\n" ) + 2 ) ); ?>" spellcheck="false"><?php echo esc_textarea( $swiftling_report ); ?></textarea>
		</div>
	</section>
</div>
