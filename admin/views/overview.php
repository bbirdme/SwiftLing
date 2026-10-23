<?php
/**
 * Overview screen: how far each language has come and what needs attention.
 *
 * Counts pages and posts visitors can open, each once per language, in the state the Translations
 * screen shows for it, see swiftling_site_page_progress().
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\ProOffer;
use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();
$swiftling_count     = count( $swiftling_languages['targets'] ) + 1;

Ui::screenHeader(
	array(
		'crumb'   => __( 'Overview', 'swiftling' ),
		/* translators: %s: number of languages, including the one the site is written in. */
		'title'   => sprintf( _n( 'Your site speaks %s language', 'Your site speaks %s languages', $swiftling_count, 'swiftling' ), number_format_i18n( $swiftling_count ) ),
		'actions' => array(
			array( __( 'Open site', 'swiftling' ), 'secondary', home_url( '/' ) ),
		),
	)
);

if ( ! $swiftling_languages['targets'] ) {
	Ui::emptyState(
		__( 'Choose the languages first', 'swiftling' ),
		__( 'Tell SwiftLing which language your content is written in and which languages it should be translated into.', 'swiftling' ),
		array( __( 'Add languages', 'swiftling' ), admin_url( 'admin.php?page=swiftling-languages' ) )
	);
	return;
}

$swiftling_progress = swiftling_site_page_progress();
$swiftling_kinds    = array(
	/* translators: %s: number of pages. */
	'reviewed'  => array( __( 'Reviewed', 'swiftling' ), _n_noop( '%s reviewed', '%s reviewed', 'swiftling' ) ),
	/* translators: %s: number of pages. */
	'machine'   => array( __( 'Machine', 'swiftling' ), _n_noop( '%s machine translated', '%s machine translated', 'swiftling' ) ),
	/* translators: %s: number of pages. */
	'duplicate' => array( __( 'Original kept', 'swiftling' ), _n_noop( '%s shown as the original', '%s shown as the original', 'swiftling' ) ),
	/* translators: %s: number of pages. */
	'partial'   => array( __( 'Partly', 'swiftling' ), _n_noop( '%s partly translated', '%s partly translated', 'swiftling' ) ),
	/* translators: %s: number of pages. */
	'outdated'  => array( __( 'Original changed', 'swiftling' ), _n_noop( '%s with the original changed', '%s with the original changed', 'swiftling' ) ),
	/* translators: %s: number of pages. */
	'missing'   => array( '', _n_noop( '%s not translated', '%s not translated', 'swiftling' ) ),
);
$swiftling_started = false;
foreach ( $swiftling_progress['languages'] as $swiftling_counts ) {
	$swiftling_started = $swiftling_started || $swiftling_counts['missing'] < $swiftling_progress['total'];
}
?>
<div class="swiftling-stack">
	<section class="swiftling-card">
		<div class="swiftling-card__head">
			<h2><?php esc_html_e( 'Pages and posts', 'swiftling' ); ?></h2>
			<ul class="swiftling-legend">
				<?php foreach ( $swiftling_kinds as $swiftling_state => $swiftling_kind ) : ?>
					<?php if ( '' !== $swiftling_kind[0] ) : ?>
						<li class="is-<?php echo esc_attr( $swiftling_state ); ?>"><?php echo esc_html( $swiftling_kind[0] ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</div>
		<table class="swiftling-table swiftling-progress-table">
			<?php
			foreach ( $swiftling_languages['targets'] as $swiftling_slug => $swiftling_language ) :
				$swiftling_counts = $swiftling_progress['languages'][ $swiftling_slug ];
				$swiftling_total  = max( 1, $swiftling_progress['total'] );
				$swiftling_parts  = array();
				foreach ( $swiftling_kinds as $swiftling_state => $swiftling_kind ) {
					if ( $swiftling_counts[ $swiftling_state ] ) {
						$swiftling_parts[] = sprintf( translate_nooped_plural( $swiftling_kind[1], $swiftling_counts[ $swiftling_state ], 'swiftling' ), number_format_i18n( $swiftling_counts[ $swiftling_state ] ) );
					}
				}
				?>
				<tr>
					<td class="swiftling-progress-table__lang"><?php Ui::lang( $swiftling_language['code'] ); ?></td>
					<td>
						<strong><?php echo esc_html( $swiftling_language['name'] ); ?></strong><br>
						<code class="swiftling-muted"><?php echo esc_html( swiftling_language_address( $swiftling_slug ) ); ?></code>
					</td>
					<td class="swiftling-progress-table__bar">
						<span class="swiftling-bar">
							<?php foreach ( array( 'reviewed', 'machine', 'duplicate', 'partial', 'outdated' ) as $swiftling_state ) : ?>
								<span class="is-<?php echo esc_attr( $swiftling_state ); ?>" style="width: <?php echo esc_attr( (string) round( 100 * $swiftling_counts[ $swiftling_state ] / $swiftling_total, 2 ) ); ?>%"></span>
							<?php endforeach; ?>
						</span>
						<span class="swiftling-muted"><?php echo esc_html( implode( ', ', $swiftling_parts ) ); ?></span>
					</td>
					<?php // The share of pages done for the language: translated in full, or the original kept on purpose. ?>
					<td class="swiftling-progress-table__total"><?php echo esc_html( number_format_i18n( (int) floor( 100 * ( $swiftling_counts['reviewed'] + $swiftling_counts['machine'] + $swiftling_counts['duplicate'] ) / $swiftling_total ) ) . '%' ); ?></td>
					<td class="is-numeric"><?php Ui::badge( $swiftling_language['visible'] ? 'published' : 'hidden' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<div class="swiftling-card__foot">
			<span class="swiftling-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: language name, 2: number of pages and posts. */
						_n( 'Original: %1$s. %2$s page or post visitors can open.', 'Original: %1$s. %2$s pages and posts visitors can open.', $swiftling_progress['total'], 'swiftling' ),
						$swiftling_languages['source']['name'],
						number_format_i18n( $swiftling_progress['total'] )
					)
				);
				?>
			</span>
			<?php Ui::button( __( 'Manage languages', 'swiftling' ), 'link', admin_url( 'admin.php?page=swiftling-languages' ) ); ?>
		</div>
	</section>

	<section class="swiftling-card">
		<h2><?php esc_html_e( 'Needs attention', 'swiftling' ); ?></h2>
		<?php if ( ! $swiftling_progress['outdated'] && $swiftling_started ) : ?>
			<p class="swiftling-muted"><?php esc_html_e( 'Nothing right now.', 'swiftling' ); ?></p>
		<?php endif; ?>
		<ul class="swiftling-attention">
			<?php if ( ! $swiftling_started && $swiftling_progress['total'] ) : ?>
				<li>
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<span class="swiftling-attention__text">
						<strong><?php esc_html_e( 'Nothing is translated yet', 'swiftling' ); ?></strong>
						<span class="swiftling-muted"><?php esc_html_e( 'Open a page in the translation editor and translate its texts.', 'swiftling' ); ?></span>
					</span>
					<?php Ui::button( __( 'Open translations', 'swiftling' ), 'secondary', admin_url( 'admin.php?page=swiftling-content' ) ); ?>
				</li>
			<?php endif; ?>
			<?php foreach ( $swiftling_progress['outdated'] as $swiftling_post_id ) : ?>
				<li>
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<span class="swiftling-attention__text">
						<strong><?php echo esc_html( get_the_title( $swiftling_post_id ) ); ?></strong>
						<span class="swiftling-muted"><?php esc_html_e( 'The original changed since it was translated.', 'swiftling' ); ?></span>
					</span>
					<?php Ui::button( __( 'Open translations', 'swiftling' ), 'secondary', swiftling_editor_url( $swiftling_post_id ) ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<?php ProOffer::overviewCard(); ?>
</div>
