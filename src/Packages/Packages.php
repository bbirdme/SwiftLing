<?php
/**
 * The themes and plugins of the site whose texts SwiftLing can translate.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * The active theme (and its parent) and the active plugins, each a "package" with an ID such as
 * "theme/divi" or "plugin/woocommerce": the folder it lives in. SwiftLing itself is not one.
 *
 * Texts of a package are of two kinds: settings it keeps in options, see Settings, and texts
 * written into its code, which WordPress hands over through gettext under a text domain, see
 * Catalogue. A package says which text domain is its own in its header, but its code can use
 * others, such as a library it brings along; SwiftLing learns those while it records pages and
 * when it scans the code.
 */
final class Packages {
	/**
	 * Option that maps text domains to the packages seen using them.
	 */
	public const DOMAINS_OPTION = 'swiftling_package_domains';

	/**
	 * Packages of this request, keyed by ID.
	 *
	 * @var array<string, array>|null
	 */
	private static $all = null;

	/**
	 * Returns the active theme, its parent and the active plugins, keyed by package ID.
	 *
	 * @return array<string, array{id: string, type: string, slug: string, name: string, version: string, dir: string, domain: string}>
	 *         The directory ends with a slash; a plugin of one file has its file as directory.
	 */
	public static function all(): array {
		if ( null === self::$all ) {
			$packages = array();

			foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $slug ) {
				$theme = wp_get_theme( $slug );
				if ( ! $theme->exists() ) {
					continue;
				}
				$id              = 'theme/' . $slug;
				$packages[ $id ] = array(
					'id'      => $id,
					'type'    => 'theme',
					'slug'    => (string) $slug,
					'name'    => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
					'dir'     => trailingslashit( wp_normalize_path( $theme->get_stylesheet_directory() ) ),
					'domain'  => (string) $theme->get( 'TextDomain' ),
				);
			}

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$active = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			$data = get_plugins();
			$own  = wp_normalize_path( SWIFTLING_DIR );
			foreach ( array_unique( array_map( 'strval', $active ) ) as $file ) {
				if ( ! isset( $data[ $file ] ) ) {
					continue;
				}
				$single = false === strpos( $file, '/' );
				$slug   = $single ? $file : dirname( $file );
				$dir    = wp_normalize_path( WP_PLUGIN_DIR . '/' . $slug ) . ( $single ? '' : '/' );
				if ( $dir === $own ) {
					continue;
				}
				$id              = 'plugin/' . $slug;
				$packages[ $id ] = array(
					'id'      => $id,
					'type'    => 'plugin',
					'slug'    => $slug,
					'name'    => (string) $data[ $file ]['Name'],
					'version' => (string) $data[ $file ]['Version'],
					'dir'     => $dir,
					'domain'  => (string) $data[ $file ]['TextDomain'],
				);
			}

			self::$all = $packages;
		}
		return self::$all;
	}

	/**
	 * Returns one package.
	 *
	 * @param string $id Package ID, e.g. "plugin/woocommerce".
	 */
	public static function get( string $id ): ?array {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Forgets the packages of this request, for the tests.
	 */
	public static function forget(): void {
		self::$all = null;
	}

	/**
	 * Returns the package a PHP file belongs to, from its folder alone: a theme in the folder of
	 * themes, a plugin in the folder of plugins. Light enough for every request, as it reads no
	 * headers; whether the package is active is checked where the answer is kept.
	 *
	 * @param string $file Path of a PHP file.
	 * @return string Package ID, or an empty string for WordPress itself, SwiftLing and anything else.
	 */
	public static function ofFile( string $file ): string {
		static $roots = null;
		if ( null === $roots ) {
			$roots = array(
				'plugin' => trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ),
				'theme'  => trailingslashit( wp_normalize_path( get_theme_root() ) ),
			);
		}
		$file = wp_normalize_path( $file );
		foreach ( $roots as $type => $root ) {
			if ( 0 === strpos( $file, $root ) ) {
				$rest = (string) substr( $file, strlen( $root ) );
				$slug = (string) strtok( $rest, '/' );
				// SwiftLing is no package of its own.
				if ( '' === $slug || wp_normalize_path( SWIFTLING_DIR ) === $root . $slug . '/' ) {
					return '';
				}
				return $type . '/' . $slug;
			}
		}
		return '';
	}

	/**
	 * Returns the package that runs the code calling WordPress right now: the first file on the call
	 * stack that is not WordPress's or SwiftLing's own.
	 *
	 * @return string Package ID, or an empty string when only WordPress and SwiftLing are calling.
	 */
	public static function caller(): string {
		static $core = null;
		if ( null === $core ) {
			$core = array(
				wp_normalize_path( ABSPATH . WPINC ) . '/',
				wp_normalize_path( ABSPATH . 'wp-admin' ) . '/',
			);
			foreach ( array( 'includes/', 'src/', 'admin/', 'swiftling.php' ) as $own ) {
				$core[] = wp_normalize_path( SWIFTLING_DIR ) . $own;
			}
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Which theme or plugin reads a setting; only while recording.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 24 ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			foreach ( $core as $folder ) {
				if ( 0 === strpos( $file, $folder ) ) {
					continue 2;
				}
			}
			return self::ofFile( $file );
		}
		return '';
	}

	/**
	 * Returns the package whose own code called a WordPress function, such as get_option(): the file
	 * the call is written in. A call WordPress makes for a theme, such as get_option( 'blogname' )
	 * inside get_bloginfo(), is WordPress's own, whoever asked for the site title.
	 *
	 * @param string $function_name Name of the WordPress function.
	 * @return string Package ID, or an empty string when WordPress or SwiftLing called it.
	 */
	public static function callerOf( string $function_name ): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Which theme or plugin reads a setting; only while recording.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 24 ) as $frame ) {
			if ( isset( $frame['function'], $frame['file'] ) && $function_name === $frame['function'] && ! isset( $frame['class'] ) ) {
				return self::ofFile( $frame['file'] );
			}
		}
		return '';
	}

	/**
	 * Returns the text domains of a package: the one its header names, and those it was seen using.
	 *
	 * @param string $id Package ID.
	 * @return string[]
	 */
	public static function domains( string $id ): array {
		$package = self::get( $id );
		$domains = array();
		if ( $package && '' !== $package['domain'] ) {
			$domains[] = $package['domain'];
		}
		foreach ( self::learnedDomains() as $domain => $owner ) {
			if ( $owner === $id ) {
				$domains[] = (string) $domain;
			}
		}
		return array_values( array_unique( $domains ) );
	}

	/**
	 * Returns the package a text domain belongs to: the one seen using it, else the one whose header
	 * names it, else the one whose folder has its name.
	 *
	 * @param string $domain Text domain.
	 * @return string Package ID, or an empty string for a domain of no active package.
	 */
	public static function ofDomain( string $domain ): string {
		$learned = self::learnedDomains();
		if ( isset( $learned[ $domain ] ) && self::get( $learned[ $domain ] ) ) {
			return $learned[ $domain ];
		}
		foreach ( self::all() as $id => $package ) {
			if ( $package['domain'] === $domain ) {
				return $id;
			}
		}
		foreach ( self::all() as $id => $package ) {
			if ( strtolower( $package['slug'] ) === strtolower( $domain ) ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Remembers which package uses which text domains. A domain WordPress itself uses ("default") or
	 * SwiftLing's are never a package's.
	 *
	 * @param array<string, string> $domains Text domain => package ID.
	 */
	public static function learnDomains( array $domains ): void {
		$learned = self::learnedDomains();
		$changed = false;
		foreach ( $domains as $domain => $id ) {
			$domain = (string) $domain;
			if ( in_array( $domain, array( '', 'default', 'swiftling' ), true ) || ! self::get( (string) $id ) ) {
				continue;
			}
			if ( ! isset( $learned[ $domain ] ) || $learned[ $domain ] !== $id ) {
				$learned[ $domain ] = (string) $id;
				$changed            = true;
			}
		}
		if ( $changed ) {
			update_option( self::DOMAINS_OPTION, $learned, false );
		}
	}

	/**
	 * Returns the text domains seen in use, each with its package.
	 *
	 * @return array<string, string>
	 */
	private static function learnedDomains(): array {
		$learned = swiftling_raw_option( self::DOMAINS_OPTION, array() );
		return is_array( $learned ) ? $learned : array();
	}
}
