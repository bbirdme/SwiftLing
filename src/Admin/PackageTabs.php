<?php
/**
 * The tabs of a theme or plugin on the Themes & plugins screen and in the translation editor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin;

use SwiftLing\Packages\Catalogue;
use SwiftLing\Packages\CodeScanner;
use SwiftLing\Packages\Settings;
use SwiftLing\Packages\Texts;

defined( 'ABSPATH' ) || exit;

/**
 * The three lists of a theme or plugin, as tabs: its texts on your pages, its settings and all
 * texts in its code, each with how many it holds. A list nothing was found for yet leads to where
 * its texts are found: the scan of its settings, or of its code.
 */
final class PackageTabs {
	/**
	 * Prints the tabs.
	 *
	 * @param array  $package Package, see SwiftLing\Packages\Packages::all().
	 * @param string $current strings, settings, choose or code.
	 * @param string $lang    Language slug of the editor, for links between its lists.
	 */
	public static function render( array $package, string $current, string $lang = '' ): void {
		$id     = $package['id'];
		$counts = Catalogue::counts();
		$counts = isset( $counts[ $id ] ) ? $counts[ $id ] : array(
			'seen'    => 0,
			'scanned' => 0,
			'all'     => 0,
		);
		$chosen = count( Settings::chosen( $id ) );
		$scans  = CodeScanner::scans();
		$tabs   = array(
			'strings'  => array(
				__( 'Texts on your pages', 'swiftling' ),
				number_format_i18n( $counts['seen'] ),
				swiftling_package_url( $id, 'strings', $lang ),
			),
			'settings' => array(
				__( 'Settings', 'swiftling' ),
				Settings::chosenAt( $id )
					/* translators: %s: number of settings chosen. */
					? sprintf( __( '%s chosen', 'swiftling' ), number_format_i18n( $chosen ) )
					: __( 'not scanned', 'swiftling' ),
				$chosen ? swiftling_package_url( $id, 'settings', $lang ) : swiftling_package_url( $id, 'choose' ),
			),
			'code'     => array(
				__( 'All texts in the code', 'swiftling' ),
				empty( $scans[ $id ]['finished'] ) ? __( 'not scanned', 'swiftling' ) : number_format_i18n( $counts['all'] ),
				empty( $scans[ $id ]['finished'] ) ? swiftling_package_url( $id, 'scan' ) : swiftling_package_url( $id, 'code', $lang ),
			),
		);
		$active = 'choose' === $current ? 'settings' : $current;
		?>
		<nav class="swiftling-package-tabs" aria-label="<?php echo esc_attr( $package['name'] ); ?>">
			<?php foreach ( $tabs as $view => $tab ) : ?>
				<a href="<?php echo esc_url( $tab[2] ); ?>" class="<?php echo $view === $active ? 'is-current' : ''; ?>"<?php echo $view === $active ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $tab[0] ); ?>
					<span class="swiftling-muted"><?php echo esc_html( $tab[1] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Prints which texts of a list from code show, a search, and the pages of the list, for the
	 * translation editor. Each is a link or a form that reloads the editor.
	 *
	 * @param array  $subject Subject, see SwiftLing\Packages\Texts::subject().
	 * @param string $lang    Language slug shown.
	 * @param array  $texts   The list, see SwiftLing\Packages\Texts::forEditor().
	 * @param string $search  Words searched for.
	 */
	public static function filters( array $subject, string $lang, array $texts, string $search ): void {
		Ui::listFilters(
			array(
				'subject'  => $subject['key'],
				'lang'     => $lang,
				'shows'    => array(
					'missing'  => array( __( 'Without a translation', 'swiftling' ), $texts['counts']['missing'] ),
					'official' => array( __( 'From its own translators', 'swiftling' ), $texts['counts']['official'] ),
					'all'      => array( __( 'All', 'swiftling' ), $texts['counts']['all'] ),
				),
				'show'     => $texts['show'],
				'search'   => $search,
				'page'     => $texts['page'],
				'pages'    => $texts['pages'],
				'per_page' => Texts::PER_PAGE,
				'shown'    => $texts['shown'],
			)
		);
	}
}
