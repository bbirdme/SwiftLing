<?php
/**
 * SwiftLing on the screenshot site, after content.php: four languages, the translations of
 * translations.json, the switcher in the header's menu and at the end of every page, and the
 * translation service shown as connected.
 *
 *   wp eval-file tools/wordpress-org/site.php --user=admin
 *
 * German is left as a page in progress shows: two texts of the home page saved as machine
 * translations and one not translated. Italian is hidden and translated only in part.
 *
 * The Google key is a placeholder marked as checked, so Translate everything shows its estimate.
 * Nothing is sent to Google while no run is started; do not start one on this site.
 *
 * @package SwiftLing
 */

$home = (int) get_option( 'page_on_front' );
update_post_meta( $home, '_wp_page_template', 'page-no-title' );

$result = swiftling_import_languages(
	array(
		'locale' => 'en_US',
		'slug'   => 'en',
		'name'   => 'English',
	),
	array(
		array( 'locale' => 'de_DE', 'slug' => 'de', 'visible' => true ),
		array( 'locale' => 'fr_FR', 'slug' => 'fr', 'visible' => true ),
		array( 'locale' => 'es_ES', 'slug' => 'es', 'visible' => true ),
		array( 'locale' => 'it_IT', 'slug' => 'it', 'visible' => false ),
	),
	array(
		'format'        => 'directory',
		'source_folder' => false,
	)
);
if ( is_wp_error( $result ) ) {
	WP_CLI::error( $result->get_error_message() );
}

// The translations, each text found by its original. A text with HTML is translated by replacing
// the sentences in it it holds, longest first, so its markup stays as it is.
$dict = json_decode( (string) file_get_contents( __DIR__ . '/translations.json' ), true );
uksort(
	$dict,
	static function ( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	}
);
$keys = array();
foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'numberposts' => -1 ) ) as $post ) {
	$keys[] = 'post:' . $post->ID;
}
foreach ( swiftling_site_views() as $view ) {
	$keys[] = $view['key'];
}
$machine = array( 'A family nursery on the edge', 'Glossy leaves and long-lasting' );
$left    = 'Open Tuesday to Sunday';
foreach ( array( 'de', 'fr', 'es', 'it' ) as $lang ) {
	$count = 0;
	foreach ( $keys as $key ) {
		$texts = swiftling_subject_texts( $key, $lang );
		if ( is_wp_error( $texts ) ) {
			continue;
		}
		$in_progress = 'de' === $lang && 'post:' . $home === $key;
		$items       = array(
			'reviewed' => array(),
			'machine'  => array(),
		);
		foreach ( $texts['texts'] as $text ) {
			if ( 'missing' !== $text['state'] || 'url' === $text['type'] ) {
				continue;
			}
			$original = $text['original'];
			$plain    = trim( wp_strip_all_tags( $original ) );
			if ( isset( $dict[ $plain ][ $lang ] ) ) {
				$translation = str_replace( $plain, $dict[ $plain ][ $lang ], $original );
			} else {
				$translation = $original;
				foreach ( $dict as $english => $languages ) {
					if ( strlen( $english ) >= 20 && isset( $languages[ $lang ] ) ) {
						$translation = str_replace( $english, $languages[ $lang ], $translation );
					}
				}
				if ( $translation === $original ) {
					continue;
				}
			}
			if ( $in_progress && false !== strpos( $original, $left ) ) {
				continue;
			}
			$state = 'reviewed';
			foreach ( $in_progress ? $machine : array() as $start ) {
				if ( false !== strpos( $original, $start ) ) {
					$state = 'machine';
				}
			}
			$items[ $state ][] = array(
				'key'      => $text['key'],
				'text'     => $translation,
				'original' => $original,
			);
		}
		foreach ( $items as $state => $list ) {
			if ( $list ) {
				$saved  = swiftling_save_texts( $key, $lang, $list, $state );
				$count += is_wp_error( $saved ) ? 0 : count( $saved['states'] );
			}
		}
	}
	WP_CLI::log( $lang . ': ' . $count . ' texts translated' );
}

// The switcher: a block in the header's menu, and flags with names at the end of every page.
$nav = get_posts( array( 'post_type' => 'wp_navigation', 'numberposts' => 1 ) )[0];
if ( false === strpos( $nav->post_content, 'swiftling/language-switcher' ) ) {
	wp_update_post(
		array(
			'ID'           => $nav->ID,
			'post_content' => $nav->post_content . '<!-- wp:swiftling/language-switcher {"type":"dropdown","flag":true,"name":true} /-->',
		)
	);
}
$switcher           = get_option( \SwiftLing\Switcher\Settings::OPTION, array() );
$switcher           = is_array( $switcher ) ? $switcher : array();
$switcher['footer'] = array(
	'enabled'  => true,
	'position' => 'end',
	'style'    => 'horizontal',
	'show'     => array(
		'flag'      => true,
		'name'      => true,
		'code'      => false,
		'current'   => true,
		'flag_size' => 'medium',
	),
);
update_option( \SwiftLing\Switcher\Settings::OPTION, $switcher );

$placeholder = 'AIzaSyD-screenshot-placeholder-key-000';
\SwiftLing\Translation\Services::update(
	'google',
	array(
		'key'         => $placeholder,
		'fingerprint' => \SwiftLing\Translation\Services::get( 'google' )->fingerprint( $placeholder ),
		'state'       => 'working',
		'languages'   => array( 'en', 'de', 'fr', 'es', 'it' ),
	)
);

WP_CLI::success( 'SwiftLing is set up for the screenshots.' );
