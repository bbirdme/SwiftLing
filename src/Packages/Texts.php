<?php
/**
 * The texts of a theme or plugin as the translation editor lists them.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * The three lists of a theme or plugin on the Themes & plugins screen, each a subject of the
 * translation editor (see swiftling_subject()):
 *
 * - "strings:<package>": texts from its code that pages of the site showed;
 * - "settings:<package>": values of its settings the user chose, see Settings;
 * - "code:<package>": every text in its code, once a scan found them, see CodeScanner.
 *
 * A text from code is kept, like any other, in the object of its text domain ("gettext:<domain>"),
 * under the path the translated view finds it by, so a translation made here shows wherever the
 * theme or plugin writes the text. Many of these texts come translated by the theme's or plugin's
 * own translators; the lists say so and count them as translated.
 *
 * A plugin can have thousands of texts, so the lists of texts from code show one page at a time,
 * and can show only the texts without a translation, or only those its translators translated.
 */
final class Texts {
	/**
	 * Texts on one page of a list.
	 */
	public const PER_PAGE = 50;

	/**
	 * The views of a package, in the order of its tabs.
	 */
	public const VIEWS = array( 'strings', 'settings', 'code' );

	/**
	 * Returns a subject of the editor for a package's texts, or null when the key names none.
	 *
	 * @param string $key Subject key, e.g. "strings:plugin/woocommerce".
	 */
	public static function subject( string $key ): ?array {
		$parts = explode( ':', $key, 2 );
		if ( 2 !== count( $parts ) || ! in_array( $parts[0], self::VIEWS, true ) ) {
			return null;
		}
		$package = Packages::get( $parts[1] );
		if ( ! $package ) {
			return null;
		}
		$types = array(
			'strings'  => __( 'Texts on your pages', 'swiftling' ),
			'settings' => __( 'Settings', 'swiftling' ),
			'code'     => __( 'All texts in the code', 'swiftling' ),
		);
		$notes = array(
			'strings'  => __( 'Texts from the code of this theme or plugin that pages of your site showed while recording or in the translation editor. A translation here shows wherever it writes the text.', 'swiftling' ),
			'settings' => __( 'Values of its settings that you chose to translate. Its other settings stay the same in every language.', 'swiftling' ),
			'code'     => __( 'Every text its code can write, also on pages you never recorded and in emails. Most come translated by its own translators; you only need the rest, or a change to one you do not like.', 'swiftling' ),
		);
		return array(
			'key'     => $parts[0] . ':' . $package['id'],
			'kind'    => 'package',
			'title'   => $package['name'],
			'type'    => $types[ $parts[0] ],
			'url'     => '',
			'probe'   => false,
			'post'    => null,
			'cap'     => 'manage_options',
			'note'    => $notes[ $parts[0] ],
			'package' => $package['id'],
			'view'    => $parts[0],
		);
	}

	/**
	 * Returns the texts of a list from code, with the text the original shows for each.
	 *
	 * @param array $rows Rows of the catalogue, see Catalogue::texts().
	 * @return array<int, array> Texts, see Builder::listTexts(), each with g, what the code writes.
	 */
	public static function fromCode( array $rows ): array {
		$texts = array();
		self::withLocale(
			get_locale(),
			static function () use ( $rows, &$texts ) {
				foreach ( $rows as $row ) {
					$value = self::translate( $row['d'], $row['source'] );
					if ( ! self::hasWords( $value ) ) {
						continue;
					}
					$texts[] = array(
						'o'    => 'gettext:' . $row['d'],
						'p'    => $row['path'],
						'v'    => $value,
						'r'    => swiftling_rule_key( 'gettext', '', '' ),
						// A text a page showed has the kind it showed as, such as a button.
						'kind' => '' !== $row['kind'] && isset( \SwiftLing\Admin\Ui::partLabels()[ $row['kind'] ] ) ? $row['kind'] : 'text',
						'g'    => array( 'd' => $row['d'] ) + $row['source'],
					);
				}
			}
		);
		return $texts;
	}

	/**
	 * Returns, for each text from code, whether it has a translation in a language: one the user
	 * made (translated), one the theme's or plugin's translators made (official), or none (missing).
	 *
	 * @param array  $texts  Texts, see fromCode().
	 * @param string $locale Locale of the language.
	 * @param string $wp     Locale WordPress runs in for the language, see swiftling_languages().
	 * @return array<int, string> Same keys as the texts.
	 */
	public static function states( array $texts, string $locale, string $wp ): array {
		$places = array();
		foreach ( $texts as $index => $text ) {
			$places[ $index ] = swiftling_place_key( $text['o'], swiftling_path_key( $text['p'] ), swiftling_hash( $text['v'] ) );
		}
		$saved  = $places ? swiftling_get_translations( $locale, array_values( $places ) ) : array();
		$states = array();
		$missed = array();
		foreach ( $places as $index => $place ) {
			if ( isset( $saved[ $place ] ) ) {
				$states[ $index ] = SWIFTLING_OFFICIAL === $saved[ $place ]['status'] ? 'official' : 'translated';
			} else {
				$missed[] = $index;
			}
		}
		if ( $missed && in_array( $wp, get_available_languages(), true ) ) {
			self::withLocale(
				$wp,
				static function () use ( $missed, $texts, &$states ) {
					foreach ( $missed as $index ) {
						if ( self::hasOwnTranslation( $texts[ $index ]['g']['d'], $texts[ $index ]['g'] ) ) {
							$states[ $index ] = 'official';
						}
					}
				}
			);
		}
		foreach ( array_keys( $texts ) as $index ) {
			if ( ! isset( $states[ $index ] ) ) {
				$states[ $index ] = 'missing';
			}
		}
		return $states;
	}

	/**
	 * Returns the texts of a package's view for the editor, filtered and one page at a time for the
	 * lists from code.
	 *
	 * @param array  $subject Subject, see subject().
	 * @param string $lang    Address slug of the language shown.
	 * @param array  $args    show (missing, official or all), search and page.
	 * @return array{texts: array, total: int, shown: int, counts: array<string, int>, show: string, page: int, pages: int, progress: array<string, array{translated: int, total: int}>}
	 */
	public static function forEditor( array $subject, string $lang, array $args ): array {
		$id = $subject['package'];
		if ( 'settings' === $subject['view'] ) {
			$texts = Settings::listTexts( $id );
			return array(
				'texts'    => $texts,
				'total'    => count( $texts ),
				'shown'    => count( $texts ),
				'counts'   => array(),
				'show'     => 'all',
				'page'     => 1,
				'pages'    => 1,
				'progress' => array(),
			);
		}

		$all = self::fromCode( Catalogue::texts( $id, 'strings' === $subject['view'] ? 'seen' : 'all' ) );
		// In the order of their words, so a list reads the same every time and texts that belong
		// together, such as "Add to cart" and "Add to wishlist", stand together.
		usort(
			$all,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['v'], $b['v'] );
			}
		);
		$progress = array();
		$states   = array();
		foreach ( swiftling_languages()['targets'] as $slug => $language ) {
			$in_language       = self::states( $all, $language['locale'], (string) ( $language['wp_locale'] ?? $language['locale'] ) );
			$progress[ $slug ] = array(
				'translated' => count( array_diff( $in_language, array( 'missing' ) ) ),
				'total'      => count( $all ),
			);
			if ( $slug === $lang ) {
				$states = $in_language;
			}
		}

		$counts = array(
			'missing'  => count( array_keys( $states, 'missing', true ) ),
			'official' => count( array_keys( $states, 'official', true ) ),
			'all'      => count( $all ),
		);
		$show   = isset( $args['show'] ) && isset( $counts[ $args['show'] ] ) ? $args['show'] : '';
		if ( '' === $show ) {
			// Without a translation first, as long as anything is without one.
			$show = $counts['missing'] ? 'missing' : 'all';
		}
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		$picked = array();
		foreach ( $all as $index => $text ) {
			if ( 'all' !== $show && $states[ $index ] !== $show ) {
				continue;
			}
			if ( '' !== $search && false === mb_stripos( $text['v'], $search ) && false === mb_stripos( $text['g']['t'], $search ) ) {
				continue;
			}
			$picked[] = $text;
		}

		$pages = max( 1, (int) ceil( count( $picked ) / self::PER_PAGE ) );
		$page  = min( $pages, max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ) );
		return array(
			'texts'    => array_slice( $picked, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total'    => count( $all ),
			'shown'    => count( $picked ),
			'counts'   => $counts,
			'show'     => $show,
			'page'     => $page,
			'pages'    => $pages,
			'progress' => $progress,
		);
	}

	/**
	 * Returns how far each language has come for a package: its texts on pages and its chosen
	 * settings, for the Themes & plugins screen.
	 *
	 * @param string $id Package ID.
	 * @return array<string, array{translated: int, total: int}> Language slug => counts.
	 */
	public static function progress( string $id ): array {
		$texts    = self::fromCode( Catalogue::texts( $id, 'seen' ) );
		$settings = Settings::listTexts( $id );
		$places   = array();
		foreach ( $settings as $setting ) {
			if ( ! swiftling_is_address_kind( $setting['kind'] ) ) {
				$places[] = swiftling_place_key( 'options', swiftling_path_key( $setting['p'] ), swiftling_hash( $setting['v'] ) );
			}
		}
		$progress = array();
		foreach ( swiftling_languages()['targets'] as $slug => $language ) {
			$states            = self::states( $texts, $language['locale'], (string) ( $language['wp_locale'] ?? $language['locale'] ) );
			$done              = $places ? count( swiftling_get_translations( $language['locale'], $places ) ) : 0;
			$progress[ $slug ] = array(
				'translated' => count( array_diff( $states, array( 'missing' ) ) ) + $done,
				'total'      => count( $texts ) + count( $places ),
			);
		}
		return $progress;
	}

	/**
	 * Returns a text from code in the locale WordPress runs in now.
	 *
	 * @param string $domain Text domain.
	 * @param array  $source What the code writes: t, and where they apply c, p and n.
	 */
	private static function translate( string $domain, array $source ): string {
		$translations = get_translations_for_domain( $domain );
		$context      = isset( $source['c'] ) && is_string( $source['c'] ) ? $source['c'] : null;
		if ( isset( $source['p'] ) && is_string( $source['p'] ) ) {
			$text = $translations->translate_plural( $source['t'], $source['p'], isset( $source['n'] ) ? (int) $source['n'] : 1, $context );
		} else {
			$text = $translations->translate( $source['t'], $context );
		}
		return is_string( $text ) ? $text : '';
	}

	/**
	 * Whether a text from code has words to translate: a letter outside its placeholders, tags and
	 * entities. "#", "%1$s %2$s" or "%1$s &ndash; %2$s" has none.
	 *
	 * @param string $text Text.
	 */
	public static function hasWords( string $text ): bool {
		$text = (string) preg_replace( '/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/', '', $text );
		return 1 === preg_match( '/\p{L}/u', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Whether the translation files of a theme or plugin for the locale WordPress runs in now have a
	 * translation of a text: from translate.wordpress.org, or from .mo or .po files the theme or
	 * plugin brings along. Also one that reads the same as the original, such as "PayPal" in German,
	 * which comparing the two would take for a missing one.
	 *
	 * @param string $domain Text domain.
	 * @param array  $source What the code writes: t, and where they apply c, p and n.
	 */
	private static function hasOwnTranslation( string $domain, array $source ): bool {
		// Loads the files of the domain for the locale, when they are not loaded yet.
		get_translations_for_domain( $domain );
		$context = isset( $source['c'] ) && is_string( $source['c'] ) ? $source['c'] : '';
		if ( class_exists( 'WP_Translation_Controller' ) ) {
			$controller = \WP_Translation_Controller::get_instance();
			$found      = isset( $source['p'] ) && is_string( $source['p'] )
				? $controller->translate_plural( array( $source['t'], $source['p'] ), isset( $source['n'] ) ? (int) $source['n'] : 1, $context, $domain )
				: $controller->translate( $source['t'], $context, $domain );
			return is_string( $found ) && '' !== $found;
		}
		// Before WordPress 6.5: only a translation that differs from the code can be told apart.
		$code = isset( $source['p'] ) && 1 !== (int) ( $source['n'] ?? 1 ) ? $source['p'] : $source['t'];
		$done = self::translate( $domain, $source );
		return '' !== $done && $done !== $code;
	}

	/**
	 * Runs code with WordPress in a locale, so texts from code come in that language.
	 *
	 * @param string   $locale Locale.
	 * @param callable $code   Code.
	 */
	private static function withLocale( string $locale, callable $code ): void {
		$switched = determine_locale() !== $locale && switch_to_locale( $locale );
		try {
			$code();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
