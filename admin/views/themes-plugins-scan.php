<?php
/**
 * Themes & plugins screen, the scan of one package's code: every text its code hands to gettext.
 * The scan runs in steps from the browser, see assets/js/themes-plugins.js and
 * SwiftLing\Packages\CodeScanner, and opens the list of all texts in the code when it is done.
 * Included by themes-plugins.php, which sets $swiftling_package.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\PackageTabs;
use SwiftLing\Admin\Ui;
use SwiftLing\Packages\CodeScanner;

defined( 'ABSPATH' ) || exit;

$swiftling_id    = $swiftling_package['id'];
$swiftling_scans = CodeScanner::scans();
$swiftling_last  = isset( $swiftling_scans[ $swiftling_id ] ) ? $swiftling_scans[ $swiftling_id ] : array();
$swiftling_setup = array(
	// Started from "Scan everything": the scan starts at once.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether to start at once.
	'auto'    => isset( $_GET['start'] ),
	'package' => $swiftling_id,
	'rest'    => esc_url_raw( rest_url( 'swiftling/v1/' ) ),
	'nonce'   => wp_create_nonce( 'wp_rest' ),
	'done'    => swiftling_package_url( $swiftling_id, 'code' ),
	'strings' => array(
		/* translators: 1: files read, 2: all files, 3: texts found. */
		'progress' => __( 'Reading the code: %1$s of %2$s files, %3$s texts so far.', 'swiftling' ),
		'failed'   => __( 'The scan stopped. Start it again to go on.', 'swiftling' ),
	),
);

Ui::screenHeader(
	array(
		'crumb'       => __( 'Themes & plugins', 'swiftling' ),
		'title'       => $swiftling_package['name'],
		'description' => 'theme' === $swiftling_package['type'] ? __( 'Theme', 'swiftling' ) : __( 'Plugin', 'swiftling' ),
	)
);
PackageTabs::render( $swiftling_package, 'code' );
?>
<section class="swiftling-card swiftling-code-scan" aria-labelledby="swiftling-code-scan-title">
	<h2 id="swiftling-code-scan-title"><?php esc_html_e( 'Scan everything in the code', 'swiftling' ); ?></h2>
	<p class="swiftling-muted">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: name of a theme or plugin. */
				__( 'SwiftLing reads the PHP files of %s and lists every text its code can show visitors, also on pages you never recorded and in emails. Texts the code builds while it runs are not in this list; a recording finds them on the pages that show them.', 'swiftling' ),
				$swiftling_package['name']
			)
		);
		?>
	</p>
	<?php if ( ! empty( $swiftling_last['finished'] ) ) : ?>
		<p class="swiftling-muted">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: date and time, 2: version of the theme or plugin. */
					__( 'Last scanned %1$s, version %2$s.', 'swiftling' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $swiftling_last['finished'] ),
					(string) ( $swiftling_last['version'] ?? '' )
				)
			);
			?>
		</p>
	<?php endif; ?>
	<div class="swiftling-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Progress', 'swiftling' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="swiftling-code-scan-bar"><span class="is-progress"></span></div>
	<p role="status" id="swiftling-code-scan-status"></p>
	<div class="swiftling-code-scan__actions">
		<button type="button" class="swiftling-button is-primary" id="swiftling-code-scan-start"><?php echo esc_html( empty( $swiftling_last['finished'] ) ? __( 'Start the scan', 'swiftling' ) : __( 'Scan again', 'swiftling' ) ); ?></button>
		<?php
		if ( ! empty( $swiftling_last['finished'] ) ) {
			Ui::button( __( 'Translate the texts found', 'swiftling' ), 'secondary', swiftling_package_url( $swiftling_id, 'code' ) );
		}
		?>
	</div>
</section>
<script type="application/json" id="swiftling-code-scan-config"><?php echo wp_json_encode( $swiftling_setup, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & escaped, inside a JSON script element. ?></script>
