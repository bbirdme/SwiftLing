<?php
/**
 * Tests of readme.txt against the plugin's header.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * What wordpress.org reads of the plugin, in readme.txt and in the header of swiftling.php, says
 * the same in both, and keeps to what wordpress.org takes.
 */
class ReadmeTest extends TestCase {

	/**
	 * Returns the headers of readme.txt, the lines before the short description.
	 *
	 * @return array<string, string> Header => value.
	 */
	private function readme_headers() {
		$headers = array();
		foreach ( (array) file( SWIFTLING_DIR . 'readme.txt', FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( '' === trim( $line ) && $headers ) {
				break;
			}
			if ( preg_match( '/^([A-Za-z ]+):\s*(.+)$/', $line, $match ) ) {
				$headers[ trim( $match[1] ) ] = trim( $match[2] );
			}
		}
		return $headers;
	}

	/**
	 * Returns the short description: the first paragraph after the headers.
	 */
	private function short_description() {
		$parts = preg_split( '/\R\s*\R/', (string) file_get_contents( SWIFTLING_DIR . 'readme.txt' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin itself.
		return trim( (string) $parts[1] );
	}

	/**
	 * The versions WordPress checks before it activates the plugin are the same in both, and the
	 * stable tag is the plugin's version.
	 */
	public function test_readme_and_header_agree() {
		$readme = $this->readme_headers();
		$header = get_file_data(
			SWIFTLING_DIR . 'swiftling.php',
			array(
				'version' => 'Version',
				'wp'      => 'Requires at least',
				'php'     => 'Requires PHP',
			)
		);
		$this->assertSame( $header['wp'], $readme['Requires at least'] );
		$this->assertSame( $header['php'], $readme['Requires PHP'] );
		$this->assertSame( $header['version'], $readme['Stable tag'] );
		$this->assertSame( SWIFTLING_VERSION, $header['version'] );
	}

	/**
	 * What wordpress.org takes: at most five tags, a short description of at most 150 characters,
	 * and the wordpress.org usernames of the contributors.
	 */
	public function test_readme_keeps_to_what_wordpress_org_takes() {
		$readme = $this->readme_headers();
		$this->assertLessThanOrEqual( 5, count( array_filter( array_map( 'trim', explode( ',', $readme['Tags'] ) ) ) ) );
		$this->assertLessThanOrEqual( 150, strlen( $this->short_description() ) );
		$this->assertNotEmpty( $readme['Tested up to'] );
		foreach ( array_map( 'trim', explode( ',', $readme['Contributors'] ) ) as $contributor ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $contributor, 'A wordpress.org username, in lower case' );
		}
	}

	/**
	 * Every address the plugin itself sends to is named under External services, with what is sent
	 * and when, as wordpress.org asks: Google's translation, and swiftling.pro, which receives the
	 * requests to support a plugin, theme or form.
	 */
	public function test_external_services_name_every_address() {
		$readme   = (string) file_get_contents( SWIFTLING_DIR . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin itself.
		$services = (string) preg_replace( '/^.*== External services ==(.*?)\n== .*$/s', '$1', $readme );
		$this->assertNotSame( $readme, $services, 'readme.txt has no External services' );
		$google = ( new \ReflectionClassConstant( \SwiftLing\Translation\Google::class, 'ENDPOINT' ) )->getValue();
		foreach ( array( 'https://' . wp_parse_url( $google, PHP_URL_HOST ), SWIFTLING_REQUEST_URL, SWIFTLING_REQUEST_EMAIL, 'https://api.wordpress.org' ) as $address ) {
			$this->assertStringContainsString( $address, $services );
		}
	}

	/**
	 * The plugin's page on wordpress.org shows an image for each caption under Screenshots, and no
	 * image without one: wordpress.org pairs them by number, screenshot-1.png with the first
	 * caption. The banner and the icon are there in the sizes wordpress.org shows. They are kept in
	 * .wordpress-org, the folder wordpress.org's assets are uploaded from, and stay out of the
	 * package (see .distignore).
	 */
	public function test_wordpress_org_images_match_the_readme() {
		$readme   = (string) file_get_contents( SWIFTLING_DIR . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin itself.
		$section  = (string) preg_replace( '/^.*== Screenshots ==(.*?)\n== .*$/s', '$1', $readme );
		$captions = preg_match_all( '/^(\d+)\. \S/m', $section, $numbers ) ? array_map( 'intval', $numbers[1] ) : array();
		$this->assertNotEmpty( $captions, 'readme.txt has no screenshots' );
		$this->assertSame( range( 1, count( $captions ) ), $captions, 'Captions numbered 1, 2, 3…' );

		$assets = SWIFTLING_DIR . '.wordpress-org/';
		$images = array();
		foreach ( (array) glob( $assets . 'screenshot-*.{png,jpg}', GLOB_BRACE ) as $file ) {
			$images[] = (int) preg_replace( '/^screenshot-(\d+)\..*$/', '$1', basename( $file ) );
		}
		sort( $images );
		$this->assertSame( $captions, $images, 'One image for each caption' );

		$sizes = array(
			'banner-772x250.png'  => array( 772, 250 ),
			'banner-1544x500.png' => array( 1544, 500 ),
			'icon-128x128.png'    => array( 128, 128 ),
			'icon-256x256.png'    => array( 256, 256 ),
		);
		foreach ( $sizes as $file => $size ) {
			$this->assertFileExists( $assets . $file );
			$this->assertSame( $size, array_slice( (array) getimagesize( $assets . $file ), 0, 2 ), $file );
		}
		$this->assertFileExists( $assets . 'icon.svg' );
	}
}
