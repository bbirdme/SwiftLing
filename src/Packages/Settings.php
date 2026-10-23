<?php
/**
 * Settings of themes and plugins that visitors read.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * Values a theme or plugin keeps in its settings (WordPress options) that the user chose to
 * translate: a footer text, a notice, the address of a logo, or code such as a tracking script
 * that differs per language.
 *
 * A scan lists the values of a package's settings that could be text, and the user ticks which
 * are. Colours, sizes, choices and other settings are never offered as text, and the user never
 * has to translate one. A chosen value is text by declaration, see swiftling_field_holds_text(): it
 * is replaced in every language that has its translation, also when it is one word in lowercase.
 * Chosen code is replaced as a whole, see swiftling_walk_string().
 *
 * Which settings belong to which package is learned while recording: the code that reads an option
 * is the package's, see Recorder. Until then, the options named after the package are its.
 *
 * Values of fields that a builder declares, such as an options page of Advanced Custom Fields, are
 * left to that builder and never listed here.
 */
final class Settings {
	/**
	 * Option with the choice of every package: package ID => chosen (path => kind) and scanned (time).
	 */
	public const OPTION = 'swiftling_package_settings';

	/**
	 * Option that maps option names to the packages whose code reads them.
	 */
	public const OWNERS_OPTION = 'swiftling_option_owners';

	/**
	 * Option with short digests of texts seen on pages of the site while recording.
	 */
	public const SEEN_OPTION = 'swiftling_seen_texts';

	/**
	 * Most texts seen on pages that are kept; the oldest go first.
	 */
	private const SEEN_LIMIT = 10000;

	/**
	 * Most options a package's settings are read from.
	 */
	private const OPTION_LIMIT = 300;

	/**
	 * What WordPress itself keeps among a theme's Customizer settings (theme_mods_<theme>): the
	 * sidebars of a classic theme, the places of menus, the post of custom CSS. Never the theme's text.
	 */
	private const WORDPRESS_THEME_MODS = array( 'wp_classic_sidebars', 'nav_menu_locations', 'custom_css_post_id', 'sidebars_widgets' );

	/**
	 * WordPress's own settings that hold text: translated where the site shows them, such as the
	 * site title in the editor of a template, never as a theme's or plugin's.
	 */
	private const WORDPRESS_OPTIONS = array( 'blogname', 'blogdescription', 'sidebars_widgets', 'nav_menu_options', 'site_icon', 'admin_email', 'new_admin_email', 'mailserver_login', 'mailserver_url', 'default_category', 'wp_page_for_privacy_policy' );

	/**
	 * Groups of a scan, in the order the screen shows them.
	 */
	public const GROUPS = array( 'shown', 'code', 'addresses', 'maybe', 'settings' );

	/**
	 * Chosen values of all packages: path => kind.
	 *
	 * @var array<string, string>|null
	 */
	private static $chosen = null;

	/**
	 * Whether the user chose a value as text, see swiftling_field_holds_text().
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null True for a chosen value; null for any other, which is left to the probe.
	 */
	public static function holdsText( string $owner, string $path ): ?bool {
		if ( 'options' !== $owner || 0 !== strncmp( $path, 'option/', 7 ) ) {
			return null;
		}
		return isset( self::index()[ $path ] ) ? true : null;
	}

	/**
	 * Whether the user chose a value somewhere inside an option.
	 *
	 * @param string $option Option name.
	 */
	public static function holdsTextIn( string $option ): bool {
		$prefix = 'option/' . swiftling_escape_segment( $option );
		foreach ( array_keys( self::index() ) as $path ) {
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a value was chosen as code, which is translated as a whole, see swiftling_walk_string().
	 * Asked for every value the translated view reads, so it costs one comparison for any other.
	 *
	 * @param string $path Path of the value inside its object.
	 */
	public static function isWhole( string $path ): bool {
		if ( 0 !== strncmp( $path, 'option/', 7 ) ) {
			return false;
		}
		$index = self::index();
		return isset( $index[ $path ] ) && 'code' === $index[ $path ];
	}

	/**
	 * Returns the values chosen in a package's settings.
	 *
	 * @param string $id Package ID.
	 * @return array<string, string> Path => kind.
	 */
	public static function chosen( string $id ): array {
		$saved = self::saved();
		return isset( $saved[ $id ]['chosen'] ) && is_array( $saved[ $id ]['chosen'] ) ? $saved[ $id ]['chosen'] : array();
	}

	/**
	 * Returns whether, and when, the user chose values of a package's settings.
	 *
	 * @param string $id Package ID.
	 * @return int Unix time of the last choice, or 0 when the user never chose.
	 */
	public static function chosenAt( string $id ): int {
		$saved = self::saved();
		return isset( $saved[ $id ]['scanned'] ) ? (int) $saved[ $id ]['scanned'] : 0;
	}

	/**
	 * Saves which values of a package's settings are translated. Only values the scan lists count.
	 *
	 * @param string   $id    Package ID.
	 * @param string[] $paths Paths of the chosen values.
	 * @return array<string, string> The values chosen: path => kind.
	 */
	public static function choose( string $id, array $paths ): array {
		$offered = array();
		foreach ( self::candidates( $id ) as $candidate ) {
			$offered[ $candidate['p'] ] = $candidate['kind'];
		}
		$chosen = array();
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && isset( $offered[ $path ] ) ) {
				$chosen[ $path ] = 'code' === $offered[ $path ] ? 'code' : ( swiftling_is_address_kind( $offered[ $path ] ) ? 'address' : 'text' );
			}
		}

		$saved        = self::saved();
		$saved[ $id ] = array(
			'chosen'  => $chosen,
			'scanned' => time(),
		);
		// Read on every page in another language, see isWhole(), so it loads with the other options.
		update_option( self::OPTION, $saved, true );
		self::$chosen = null;
		return $chosen;
	}

	/**
	 * Forgets the choices kept for this request.
	 */
	public static function forget(): void {
		self::$chosen = null;
	}

	/**
	 * Returns the values of a package's settings that could be text, each in a group of the scan:
	 *
	 * - shown: a page of the site showed it, while recording or in the translation editor;
	 * - code: code such as a tracking script or a pixel, kept as a whole;
	 * - addresses: the address of an image, a video or a link, such as a logo;
	 * - maybe: words not seen on a page, which may still show somewhere;
	 * - settings: a single word or a choice, such as "Medium", which a theme compares as it is.
	 *
	 * @param string $id Package ID.
	 * @return array<int, array{o: string, p: string, v: string, r: string, option: string, kind: string, group: string, label: string, key: string}>
	 */
	public static function candidates( string $id ): array {
		if ( ! Packages::get( $id ) ) {
			return array();
		}
		$names = self::optionNames( $id );
		foreach ( array_keys( self::chosen( $id ) ) as $path ) {
			$names[] = self::optionOf( $path );
		}
		return self::classify( array_values( array_unique( array_filter( $names ) ) ) );
	}

	/**
	 * Returns the texts of a package's chosen settings as they are stored now, for the translation
	 * editor. See Builder::listTexts() for the shape.
	 *
	 * @param string $id Package ID.
	 * @return array
	 */
	public static function listTexts( string $id ): array {
		$chosen = self::chosen( $id );
		if ( ! $chosen ) {
			return array();
		}
		$names = array();
		foreach ( array_keys( $chosen ) as $path ) {
			$names[] = self::optionOf( $path );
		}
		$texts = array();
		foreach ( self::classify( array_values( array_unique( array_filter( $names ) ) ) ) as $value ) {
			if ( ! isset( $chosen[ $value['p'] ] ) ) {
				continue;
			}
			$texts[] = array(
				'o'     => 'options',
				'p'     => $value['p'],
				'v'     => $value['v'],
				'r'     => $value['r'],
				'kind'  => 'text' === $value['kind'] && ( false !== strpos( $value['v'], "\n" ) || strlen( $value['v'] ) > 120 ) ? 'paragraph' : $value['kind'],
				'place' => $value['label'],
			);
		}
		return $texts;
	}

	/**
	 * Returns the names of the options of a package: those its code was seen reading, and those
	 * named after it, as "woocommerce_store_notice" for WooCommerce, "et_divi" for Divi or
	 * "theme_mods_divi" for the Customizer of the Divi theme. An option read by another package's
	 * code is that package's.
	 *
	 * @param string $id Package ID.
	 * @return string[]
	 */
	public static function optionNames( string $id ): array {
		global $wpdb;

		$package = Packages::get( $id );
		if ( ! $package ) {
			return array();
		}
		$owners = self::owners();
		$names  = array_keys( $owners, $id, true );

		$tokens = array();
		foreach ( array( $package['slug'], $package['domain'] ) as $name ) {
			$name = strtolower( (string) preg_replace( '/\.php$/', '', (string) $name ) );
			foreach ( array( $name, str_replace( '-', '_', $name ) ) as $token ) {
				// Short names such as "wc" would match the options of other plugins.
				if ( strlen( $token ) >= 4 ) {
					$tokens[] = $token;
				}
			}
		}
		$likes = array();
		$args  = array( $wpdb->options );
		foreach ( array_unique( $tokens ) as $token ) {
			$escaped = $wpdb->esc_like( $token );
			array_push( $args, $escaped, $escaped . '\_%', $escaped . '-%', '%\_' . $escaped );
			$likes[] = 'option_name = %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s';
		}
		if ( 'theme' === $package['type'] ) {
			$args[]  = 'theme_mods_' . $package['slug'];
			$likes[] = 'option_name = %s';
		}
		if ( $likes ) {
			$args[] = self::OPTION_LIMIT;
			$sql    = 'SELECT option_name FROM %i WHERE (' . implode( ' OR ', $likes ) . ') ORDER BY option_name LIMIT %d';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read when the user scans a package's settings; $sql has one placeholder per value in $args.
			$names = array_merge( $names, $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
		}

		$kept = array();
		foreach ( array_unique( $names ) as $name ) {
			$name = (string) $name;
			if ( ( isset( $owners[ $name ] ) && $owners[ $name ] !== $id ) || ! self::isCandidateName( $name ) ) {
				continue;
			}
			$kept[] = $name;
		}
		return array_slice( $kept, 0, self::OPTION_LIMIT );
	}

	/**
	 * Whether an option may hold a theme's or plugin's text at all, by its name: not WordPress's own,
	 * not SwiftLing's, not a transient, and not a licence, key, token or other secret or bookkeeping,
	 * which a scan must never list. See swiftling_skip_option().
	 *
	 * @param string $option Option name.
	 */
	public static function isCandidateName( string $option ): bool {
		return '' !== $option && '_' !== substr( $option, 0, 1 ) && 0 !== strpos( $option, 'swiftling' ) && ! self::isWordPressOption( $option ) && ! swiftling_skip_option( $option );
	}

	/**
	 * Whether an option is WordPress's own: its settings that hold text, and the classic widgets,
	 * which are translated where the site shows them.
	 *
	 * @param string $option Option name.
	 */
	public static function isWordPressOption( string $option ): bool {
		return in_array( $option, self::WORDPRESS_OPTIONS, true ) || 0 === strpos( $option, 'widget_' );
	}

	/**
	 * Remembers which package's code reads which options. Options of WordPress, of SwiftLing and of
	 * packages that are not active are left out.
	 *
	 * @param array<string, string> $owners Option name => package ID.
	 */
	public static function learnOwners( array $owners ): void {
		$known   = self::owners();
		$changed = false;
		foreach ( $owners as $option => $id ) {
			$option = (string) $option;
			if ( ! self::isCandidateName( $option ) || ! Packages::get( (string) $id ) ) {
				continue;
			}
			if ( ! isset( $known[ $option ] ) || $known[ $option ] !== $id ) {
				$known[ $option ] = (string) $id;
				$changed          = true;
			}
		}
		if ( $changed ) {
			update_option( self::OWNERS_OPTION, $known, false );
		}
	}

	/**
	 * Returns which package's code was seen reading which options.
	 *
	 * @return array<string, string> Option name => package ID.
	 */
	public static function owners(): array {
		$owners = swiftling_raw_option( self::OWNERS_OPTION, array() );
		return is_array( $owners ) ? $owners : array();
	}

	/**
	 * Remembers texts a visitor saw on a page, so a scan can tell which settings show on the site.
	 * Only a short digest of each is kept.
	 *
	 * @param string[] $texts Visible texts.
	 */
	public static function see( array $texts ): void {
		$seen = self::seen();
		$new  = array();
		foreach ( $texts as $text ) {
			$digest = self::digest( (string) $text );
			if ( '' !== $digest && ! isset( $seen[ $digest ] ) ) {
				$new[ $digest ] = true;
			}
		}
		if ( ! $new ) {
			return;
		}
		$kept = array_slice( array_merge( array_keys( $seen ), array_keys( $new ) ), -self::SEEN_LIMIT );
		update_option( self::SEEN_OPTION, $kept, false );
	}

	/**
	 * Returns the digest under which a visible text is remembered.
	 *
	 * @param string $text Text or HTML.
	 * @return string Empty for a value without letters.
	 */
	private static function digest( string $text ): string {
		$key = swiftling_text_key( $text );
		return 1 === preg_match( '/\p{L}/u', $key ) ? substr( md5( $key ), 0, 12 ) : '';
	}

	/**
	 * Returns the digests of the texts seen on pages.
	 *
	 * @return array<string, true>
	 */
	private static function seen(): array {
		$seen = swiftling_raw_option( self::SEEN_OPTION, array() );
		return is_array( $seen ) ? array_fill_keys( array_map( 'strval', $seen ), true ) : array();
	}

	/**
	 * Returns the values of some options that could be text, classified, see candidates().
	 *
	 * @param string[] $names Option names.
	 * @return array
	 */
	public static function classify( array $names ): array {
		$seen   = self::seen();
		$map    = swiftling_get_maps( array( 'options' ) );
		$found  = isset( $map['options'] ) ? $map['options']['units'] : array();
		$values = array();
		foreach ( $names as $name ) {
			$raw = self::raw( $name );
			if ( ! is_string( $raw ) && ! is_array( $raw ) && ! $raw instanceof \stdClass ) {
				continue;
			}
			if ( 0 === strpos( $name, 'theme_mods_' ) && is_array( $raw ) ) {
				$raw = array_diff_key( $raw, array_flip( self::WORDPRESS_THEME_MODS ) );
			}
			$leaves = array();
			self::leaves( $raw, 'option/' . swiftling_escape_segment( $name ), 'option:' . $name, $leaves );
			foreach ( $leaves as list( $path, $value, $rule, $code ) ) {
				// A value a builder declares, such as an ACF field, is that builder's.
				if ( null !== \SwiftLing\Builders\Registry::fieldHoldsText( 'options', $path ) ) {
					continue;
				}
				$kind = self::kind( $value, $code );
				if ( '' === $kind ) {
					continue;
				}
				// A visitor saw the text on a page, or a page check found the address in a link or an
				// image. Code never shows as text, and a value saved because it was chosen is no sign.
				$shown    = 'code' !== $kind && ( isset( $seen[ self::digest( $value ) ] ) || ( 'text' !== $kind && isset( $found[ swiftling_link_key( $path ) ] ) ) );
				$values[] = array(
					'o'      => 'options',
					'p'      => $path,
					'v'      => $value,
					'r'      => $rule,
					'option' => $name,
					'kind'   => $kind,
					'group'  => self::group( $kind, $value, $shown ),
					'label'  => self::label( $path ),
					'key'    => self::keyLabel( $path ),
				);
			}
		}
		return $values;
	}

	/**
	 * Collects the string values inside a stored value, with the paths the translated view finds
	 * them under. Code is one value, as it is translated as a whole; other strings are split as the
	 * translated view splits them, into the values inside JSON, shortcodes and attributes.
	 *
	 * @param mixed  $value  Stored value.
	 * @param string $path   Its path.
	 * @param string $source Where it comes from, for rule keys.
	 * @param array  $found  Collected values: array( path, value, rule, whether it is code ).
	 */
	private static function leaves( $value, string $path, string $source, array &$found ): void {
		if ( is_string( $value ) ) {
			if ( '' === trim( $value ) ) {
				return;
			}
			if ( self::isCode( $value ) ) {
				$found[] = array( $path, $value, swiftling_rule_key( $source, '', '' ), true );
				return;
			}
			swiftling_walk(
				$value,
				static function ( $leaf, $leaf_path, $rule ) use ( &$found ) {
					if ( is_string( $leaf ) && '' !== trim( $leaf ) ) {
						$found[] = array( $leaf_path, $leaf, $rule, false );
					}
					return $leaf;
				},
				$path,
				$source
			);
			return;
		}
		if ( ! is_array( $value ) && ! $value instanceof \stdClass ) {
			return;
		}
		$items = is_object( $value ) ? get_object_vars( $value ) : $value;
		foreach ( $items as $key => $item ) {
			$segment = is_int( $key ) ? swiftling_list_segment( $key, $item ) : swiftling_escape_segment( (string) $key );
			self::leaves( $item, $path . '/' . $segment, $source, $found );
		}
	}

	/**
	 * Whether a value is code a page includes as it is: a script, a pixel or a tag for the head of
	 * the page, as tracking and verification codes are.
	 *
	 * @param string $value Value.
	 */
	public static function isCode( string $value ): bool {
		if ( 1 === preg_match( '~<\s*(?:script|noscript|style|link|meta|iframe)\b~i', $value ) ) {
			return true;
		}
		// An image that shows nothing to read, such as a tracking pixel. Other HTML without text, such
		// as the markup a theme wraps around a widget, is a setting.
		if ( 1 === preg_match( '~<img\b~i', $value ) && '' === swiftling_visible_text( $value ) && false === stripos( $value, ' alt=' ) ) {
			return true;
		}
		// Script without its tags, as some plugins keep it.
		return 1 === preg_match( '~^\s*(?:window\.|document\.|var\s|let\s|const\s|function\s*\(|\(function|gtag\(|fbq\(|_paq\.|dataLayer)~', $value );
	}

	/**
	 * Returns what kind of text a value is, or nothing for a setting.
	 *
	 * @param string $value Value.
	 * @param bool   $code  Whether it is code.
	 * @return string code, text, image, video, url or empty.
	 */
	private static function kind( string $value, bool $code ): string {
		if ( $code ) {
			return 'code';
		}
		// A folder on the server, such as where a theme keeps temporary files, is no address.
		if ( 0 === strpos( wp_normalize_path( $value ), wp_normalize_path( ABSPATH ) ) ) {
			return '';
		}
		if ( swiftling_is_url_like( $value ) ) {
			$media = swiftling_media_kind( $value );
			return '' !== $media ? $media : 'url';
		}
		return swiftling_is_text_like( $value ) ? 'text' : '';
	}

	/**
	 * Returns the group of the scan a value belongs to, see candidates().
	 *
	 * @param string $kind  Kind of the value.
	 * @param string $value Value.
	 * @param bool   $shown Whether a page showed it.
	 */
	private static function group( string $kind, string $value, bool $shown ): string {
		if ( $shown ) {
			return 'shown';
		}
		if ( 'code' === $kind ) {
			return 'code';
		}
		if ( 'text' !== $kind ) {
			return 'addresses';
		}
		$text = swiftling_visible_text( $value );
		// A list of choices ("Title | Site name") or one word ("Medium") is a setting a theme compares.
		if ( false !== strpos( $text, '|' ) || 1 !== preg_match( '/\p{L}{2,}[\s,]+\p{L}{2,}/u', $text ) ) {
			return 'settings';
		}
		return 'maybe';
	}

	/**
	 * Returns what a value is called on the screens, from the last name in its path, as "Custom
	 * footer credits" for "option/et_divi/custom_footer_credits".
	 *
	 * @param string $path Path of the value.
	 */
	public static function label( string $path ): string {
		$names = array();
		foreach ( array_slice( explode( '/', $path ), 1 ) as $segment ) {
			// Not the parts the walker adds, such as "@json", "@sc0" or an attribute.
			if ( '' === $segment || '@' === $segment[0] ) {
				continue;
			}
			$names[] = rawurldecode( '#' === $segment[0] ? substr( $segment, 1 ) : $segment );
		}
		$last = (string) end( $names );
		if ( is_numeric( $last ) && count( $names ) > 1 ) {
			$last = $names[ count( $names ) - 2 ] . ' ' . ( (int) $last + 1 );
		}
		$text = trim( (string) preg_replace( '/[\s_-]+/', ' ', $last ) );
		return '' === $text ? $path : ucfirst( $text );
	}

	/**
	 * Returns where a value is stored, as the screens show it: the option and the keys inside it.
	 *
	 * @param string $path Path of the value.
	 */
	public static function keyLabel( string $path ): string {
		$parts = array();
		foreach ( array_slice( explode( '/', $path ), 1 ) as $segment ) {
			$parts[] = rawurldecode( $segment );
		}
		return implode( ' › ', $parts );
	}

	/**
	 * Returns the name of the option a path is in.
	 *
	 * @param string $path Path, e.g. "option/et_divi/custom_footer_credits".
	 */
	public static function optionOf( string $path ): string {
		$parts = explode( '/', $path, 3 );
		return 'option' === $parts[0] && isset( $parts[1] ) ? rawurldecode( $parts[1] ) : '';
	}

	/**
	 * Returns an option as it is stored, without SwiftLing.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private static function raw( string $name ) {
		return swiftling_raw_option( $name, null );
	}

	/**
	 * Returns the choices of every package.
	 *
	 * @return array
	 */
	private static function saved(): array {
		$saved = swiftling_raw_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Returns every chosen value of every package: path => kind.
	 *
	 * @return array<string, string>
	 */
	private static function index(): array {
		if ( null === self::$chosen ) {
			$index = array();
			foreach ( self::saved() as $choice ) {
				if ( isset( $choice['chosen'] ) && is_array( $choice['chosen'] ) ) {
					foreach ( $choice['chosen'] as $path => $kind ) {
						$index[ (string) $path ] = (string) $kind;
					}
				}
			}
			self::$chosen = $index;
		}
		return self::$chosen;
	}
}
