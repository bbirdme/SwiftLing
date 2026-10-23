<?php
/**
 * Forms screen: every form of a form plugin SwiftLing knows, with how many pages show it and its
 * state in every language. Each form is translated in the editor, as its texts are stored, see
 * SwiftLing\Forms\Provider. A form of a plugin SwiftLing does not know is found on the page it is
 * on, see admin/views/form-not-listed.php.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;
use SwiftLing\Builders\Registry;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();
$swiftling_providers = Registry::forms();

// How a form of a plugin SwiftLing does not know is found, see admin/views/form-not-listed.php.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen parameter.
if ( isset( $_GET['help'] ) && 'not-listed' === sanitize_key( wp_unslash( $_GET['help'] ) ) ) {
	include __DIR__ . '/form-not-listed.php';
	return;
}

Ui::screenHeader(
	array(
		'crumb'       => __( 'Translations', 'swiftling' ),
		'title'       => __( 'Forms', 'swiftling' ),
		'description' => __( 'The texts of your forms: labels, choices, buttons and messages. A form is translated once and shows in every page it is on.', 'swiftling' ),
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

$swiftling_not_listed = add_query_arg(
	array(
		'page' => 'swiftling-forms',
		'help' => 'not-listed',
	),
	admin_url( 'admin.php' )
);

// The forms of every form plugin on the site, by plugin, and their state in every language.
$swiftling_forms   = array();
$swiftling_plugins = 0;
foreach ( $swiftling_providers as $swiftling_provider ) {
	$swiftling_found = $swiftling_provider->forms();
	if ( ! $swiftling_found ) {
		continue;
	}
	++$swiftling_plugins;
	foreach ( $swiftling_found as $swiftling_form ) {
		$swiftling_forms[] = array_merge( $swiftling_form, array( 'plugin' => $swiftling_provider->name() ) );
	}
}

if ( ! $swiftling_forms ) {
	echo '<div class="swiftling-stack swiftling-forms-nothing">';
	Ui::emptyState(
		__( 'No forms found on this site', 'swiftling' ),
		__( 'When you add a form with a form plugin, it appears here and you can translate its labels, buttons and messages.', 'swiftling' )
	);
	?>
	<section class="swiftling-card">
		<div class="swiftling-card__head">
			<h2><?php esc_html_e( 'Works with', 'swiftling' ); ?></h2>
		</div>
		<p class="swiftling-forms-plugins">
			<?php foreach ( $swiftling_providers as $swiftling_provider ) : ?>
				<span class="swiftling-chip"><?php echo esc_html( $swiftling_provider->name() ); ?></span>
			<?php endforeach; ?>
		</p>
		<p class="swiftling-muted"><?php esc_html_e( 'More form plugins are added one at a time.', 'swiftling' ); ?></p>
		<?php
		Ui::notice(
			'info',
			array(
				'text'    => __( 'A form built into a page with the block editor, Divi or Elementor is not listed here. Its texts are part of that page, so you translate them in the page’s own editor.', 'swiftling' ),
				'compact' => true,
			)
		);
		Ui::button( __( 'My form is not listed', 'swiftling' ), 'secondary', $swiftling_not_listed );
		?>
	</section>
	<?php
	echo '</div>';
	return;
}

$swiftling_legend = array(
	'reviewed' => __( 'Reviewed', 'swiftling' ),
	'machine'  => __( 'Machine translated', 'swiftling' ),
	'outdated' => __( 'Original changed since translation', 'swiftling' ),
	'partial'  => __( 'Partly translated: some texts are still empty', 'swiftling' ),
	'missing'  => __( 'Not translated', 'swiftling' ),
);
$swiftling_states = swiftling_object_states( wp_list_pluck( $swiftling_forms, 'key' ) );
$swiftling_count  = count( $swiftling_forms );
?>
<div class="swiftling-stack">
	<section class="swiftling-card">
		<div class="swiftling-card__head">
			<h2><?php esc_html_e( 'Forms', 'swiftling' ); ?></h2>
		</div>
		<div class="swiftling-table-wrap">
			<table class="swiftling-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Form', 'swiftling' ); ?></th>
						<th><?php esc_html_e( 'Plugin', 'swiftling' ); ?></th>
						<th><?php esc_html_e( 'On pages', 'swiftling' ); ?></th>
						<?php foreach ( $swiftling_languages['targets'] as $swiftling_language ) : ?>
							<th class="is-center"><?php Ui::lang( $swiftling_language['code'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $swiftling_forms as $swiftling_form ) : ?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( swiftling_editor_url( $swiftling_form['key'] ) ); ?>"><?php echo esc_html( $swiftling_form['title'] ); ?></a></strong>
							</td>
							<td><?php echo esc_html( $swiftling_form['plugin'] ); ?></td>
							<td>
								<?php if ( $swiftling_form['pages'] ) : ?>
									<?php echo esc_html( number_format_i18n( $swiftling_form['pages'] ) ); ?>
								<?php else : ?>
									<span class="swiftling-muted"><?php esc_html_e( 'No page found', 'swiftling' ); ?></span>
								<?php endif; ?>
							</td>
							<?php foreach ( array_keys( $swiftling_languages['targets'] ) as $swiftling_slug ) : ?>
								<td class="is-center"><?php Ui::statusIcon( $swiftling_states[ $swiftling_form['key'] ][ $swiftling_slug ] ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="swiftling-card__foot">
			<span class="swiftling-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of forms, e.g. 3 forms, 2: number of plugins, e.g. 2 plugins. */
						__( '%1$s from %2$s', 'swiftling' ),
						/* translators: %s: number of forms. */
						sprintf( _n( '%s form', '%s forms', $swiftling_count, 'swiftling' ), number_format_i18n( $swiftling_count ) ),
						/* translators: %s: number of form plugins. */
						sprintf( _n( '%s plugin', '%s plugins', $swiftling_plugins, 'swiftling' ), number_format_i18n( $swiftling_plugins ) )
					)
				);
				?>
			</span>
			<span class="swiftling-muted">
				<?php esc_html_e( 'Don’t see a form here?', 'swiftling' ); ?>
				<a href="<?php echo esc_url( $swiftling_not_listed ); ?>"><?php esc_html_e( 'My form is not listed', 'swiftling' ); ?></a>
			</span>
		</div>
	</section>
</div>

<ul class="swiftling-legend is-icons">
	<?php foreach ( $swiftling_legend as $swiftling_state => $swiftling_label ) : ?>
		<li><?php Ui::statusIcon( $swiftling_state ); ?> <?php echo esc_html( $swiftling_label ); ?></li>
	<?php endforeach; ?>
</ul>
