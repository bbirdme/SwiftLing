<?php
/**
 * Themes & plugins screen, the scan of one package's settings: the values that could be text, in
 * groups, each with a box to tick when visitors read it. See SwiftLing\Packages\Settings.
 *
 * Opened from "Scan settings", the screen first checks the front page of the site in the browser,
 * as a recording does, so it can tell which settings show there and which code reads them; see
 * assets/js/themes-plugins.js. Included by themes-plugins.php, which sets $swiftling_package.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\PackageTabs;
use SwiftLing\Admin\Ui;
use SwiftLing\Packages\Settings;

defined( 'ABSPATH' ) || exit;

$swiftling_id         = $swiftling_package['id'];
$swiftling_candidates = Settings::candidates( $swiftling_id );
$swiftling_names      = Settings::optionNames( $swiftling_id );
$swiftling_chosen     = Settings::chosen( $swiftling_id );
$swiftling_decided    = (bool) Settings::chosenAt( $swiftling_id );
$swiftling_groups     = array_fill_keys( Settings::GROUPS, array() );
foreach ( $swiftling_candidates as $swiftling_candidate ) {
	$swiftling_groups[ $swiftling_candidate['group'] ][] = $swiftling_candidate;
}
$swiftling_titles = array(
	'shown'     => array( __( 'Shown on your pages', 'swiftling' ), __( 'Pages of your site showed these, so visitors read them.', 'swiftling' ) ),
	'code'      => array( __( 'Code', 'swiftling' ), __( 'Code the pages include, such as a tracking script or a pixel. Tick it when another language needs other code, such as a different analytics property; it is then translated as a whole.', 'swiftling' ) ),
	'addresses' => array( __( 'Images and links', 'swiftling' ), __( 'Addresses, such as a logo. Tick one when a language should show another image or link somewhere else.', 'swiftling' ) ),
	'maybe'     => array( __( 'Maybe text', 'swiftling' ), __( 'Words no page showed so far. They may still show somewhere, such as in an email or on a page you did not open.', 'swiftling' ) ),
	'settings'  => array( __( 'Look like settings', 'swiftling' ), __( 'A single word or a choice from a list. A theme compares such a value as it is, so translating it would break the setting in that language.', 'swiftling' ) ),
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether to check the front page first.
$swiftling_check = isset( $_GET['check'] );
$swiftling_home  = swiftling_subject( 'record:' . \SwiftLing\Packages\Recorder::pathOf( home_url( '/' ) ) );
$swiftling_setup = array(
	'auto'    => $swiftling_check,
	'reload'  => swiftling_package_url( $swiftling_id, 'choose' ),
	'subject' => $swiftling_home ? $swiftling_home['key'] : '',
	'probes'  => $swiftling_home ? array(
		'marked'  => swiftling_probe_url( $swiftling_home, 'marked' ),
		'control' => swiftling_probe_url( $swiftling_home, 'control' ),
	) : null,
	'rest'    => esc_url_raw( rest_url( 'swiftling/v1/' ) ),
	'nonce'   => wp_create_nonce( 'wp_rest' ),
);

Ui::screenHeader(
	array(
		'crumb'       => __( 'Themes & plugins', 'swiftling' ),
		'title'       => $swiftling_package['name'],
		'description' => 'theme' === $swiftling_package['type'] ? __( 'Theme', 'swiftling' ) : __( 'Plugin', 'swiftling' ),
	)
);
PackageTabs::render( $swiftling_package, 'choose' );
?>
<div class="swiftling-stack">
	<section class="swiftling-card swiftling-scan-intro" aria-live="polite">
		<div>
			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of settings read, 2: name of a theme or plugin, 3: number of values found. */
							_n( 'The scan read %1$s setting of %2$s and found %3$s values that could be text.', 'The scan read %1$s settings of %2$s and found %3$s values that could be text.', count( $swiftling_names ), 'swiftling' ),
							number_format_i18n( count( $swiftling_names ) ),
							$swiftling_package['name'],
							number_format_i18n( count( $swiftling_candidates ) )
						)
					);
					?>
				</strong>
			</p>
			<p class="swiftling-muted"><?php esc_html_e( 'Tick the ones visitors read. Values you leave unticked stay the same in every language. The scan only reads; it changes nothing on your site.', 'swiftling' ); ?></p>
			<p class="swiftling-muted swiftling-scan-check" id="swiftling-scan-check" role="status"></p>
		</div>
		<button type="button" class="swiftling-button is-secondary" id="swiftling-scan-again"<?php echo $swiftling_home ? '' : ' disabled'; ?>><?php esc_html_e( 'Check the front page again', 'swiftling' ); ?></button>
	</section>

	<?php if ( ! $swiftling_candidates ) : ?>
		<section class="swiftling-card swiftling-empty">
			<h2><?php esc_html_e( 'No settings with text found', 'swiftling' ); ?></h2>
			<p class="swiftling-muted"><?php esc_html_e( 'Its settings hold no value that could be text, or SwiftLing does not know them yet. Record its settings screens: open them while recording, and SwiftLing notes which settings the theme or plugin reads there.', 'swiftling' ); ?></p>
			<?php Ui::button( __( 'Back to themes and plugins', 'swiftling' ), 'primary', swiftling_package_url() ); ?>
		</section>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-scan" id="swiftling-scan">
			<input type="hidden" name="action" value="swiftling_package_settings">
			<input type="hidden" name="package" value="<?php echo esc_attr( $swiftling_id ); ?>">
			<?php wp_nonce_field( 'swiftling_package_settings_' . $swiftling_id ); ?>
			<?php foreach ( $swiftling_groups as $swiftling_group => $swiftling_values ) : ?>
				<?php
				if ( ! $swiftling_values ) {
					continue;
				}
				// Settings are folded away: they are offered only so nothing is hidden.
				$swiftling_folded = 'settings' === $swiftling_group;
				?>
				<section class="swiftling-card is-flush swiftling-scan__group is-<?php echo esc_attr( $swiftling_group ); ?>">
					<?php if ( $swiftling_folded ) : ?>
						<details>
						<summary class="swiftling-card__head">
					<?php else : ?>
						<div class="swiftling-card__head">
					<?php endif; ?>
						<h2>
							<?php echo esc_html( $swiftling_titles[ $swiftling_group ][0] ); ?>
							<span class="swiftling-muted"><?php echo esc_html( number_format_i18n( count( $swiftling_values ) ) ); ?></span>
						</h2>
						<p class="swiftling-muted"><?php echo esc_html( $swiftling_titles[ $swiftling_group ][1] ); ?></p>
					<?php if ( $swiftling_folded ) : ?>
						</summary>
					<?php else : ?>
						</div>
					<?php endif; ?>
					<?php foreach ( $swiftling_values as $swiftling_index => $swiftling_value ) : ?>
						<?php
						$swiftling_input = 'swiftling-value-' . md5( $swiftling_value['p'] );
						$swiftling_on    = $swiftling_decided ? isset( $swiftling_chosen[ $swiftling_value['p'] ] ) : 'shown' === $swiftling_group;
						?>
						<div class="swiftling-scan__row">
							<input type="checkbox" id="<?php echo esc_attr( $swiftling_input ); ?>" name="paths[]" value="<?php echo esc_attr( $swiftling_value['p'] ); ?>"<?php checked( $swiftling_on ); ?>>
							<label for="<?php echo esc_attr( $swiftling_input ); ?>">
								<strong><?php echo esc_html( $swiftling_value['label'] ); ?></strong>
								<code class="swiftling-muted"><?php echo esc_html( $swiftling_value['key'] ); ?></code>
							</label>
							<div class="swiftling-scan__value">
								<?php if ( 'image' === $swiftling_value['kind'] ) : ?>
									<img class="swiftling-live-thumb" src="<?php echo esc_url( $swiftling_value['v'] ); ?>" alt="" loading="lazy">
									<code><?php echo esc_html( $swiftling_value['v'] ); ?></code>
								<?php elseif ( 'code' === $swiftling_value['kind'] || swiftling_is_address_kind( $swiftling_value['kind'] ) ) : ?>
									<pre class="swiftling-code"><?php echo esc_html( mb_strlen( $swiftling_value['v'] ) > 600 ? mb_substr( $swiftling_value['v'], 0, 600 ) . '…' : $swiftling_value['v'] ); ?></pre>
								<?php else : ?>
									<?php echo esc_html( wp_html_excerpt( swiftling_visible_text( $swiftling_value['v'] ), 300, '…' ) ); ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
					<?php if ( $swiftling_folded ) : ?>
						</details>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<div class="swiftling-card swiftling-scan__bar">
				<span class="swiftling-muted" id="swiftling-scan-count" aria-live="polite"></span>
				<?php Ui::button( __( 'Cancel', 'swiftling' ), 'secondary', swiftling_package_url() ); ?>
				<button type="submit" class="swiftling-button is-accent"><?php esc_html_e( 'Save and translate', 'swiftling' ); ?></button>
			</div>
		</form>
	<?php endif; ?>
</div>
<script type="application/json" id="swiftling-scan-config"><?php echo wp_json_encode( $swiftling_setup, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & escaped, inside a JSON script element. ?></script>
