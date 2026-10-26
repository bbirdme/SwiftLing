<?php
/**
 * The languages the site is written in and translated into.
 *
 * Saved in the swiftling_languages option:
 *
 *     array(
 *         'source'  => array( 'locale' => 'hr', 'name' => 'Hrvatski', 'slug' => 'hr', 'label' => '' ),
 *         'targets' => array(
 *             array( 'locale' => 'de_DE', 'slug' => 'de', 'name' => 'Deutsch', 'visible' => true, 'label' => '' ),
 *             // A custom language: one WordPress does not list, or a regional variant of one.
 *             array( 'locale' => 'cnr', 'slug' => 'cnr', 'name' => 'Crnogorski', 'visible' => true, 'label' => '',
 *                    'custom' => array( 'wp_locale' => 'sr_RS', 'rtl' => false ) ),
 *         ),
 *         'order'   => array( 'hr', 'de_DE', 'cnr' ), // All languages, original included, as the switcher lists them.
 *         'names'   => 'native',    // How the switcher names languages: native, page or both.
 *         'format'  => 'directory', // Or 'parameter', the default, 'subdomain' or 'domain'. See urls.php.
 *         'source_folder' => true,  // The original in its own folder too, in the directory format.
 *         'domains' => array( 'de_DE' => 'example.de' ), // The domain of each language, in the domain format. See hosts.php.
 *     )
 *
 * Translations are stored per locale, so changing the address slug of a language never loses them.
 * A custom language runs WordPress in the locale of a close language it names (wp_locale), whose
 * official texts and dates it then shows; its own code is the lang attribute of its pages.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns what SwiftLing builds from its options once a request, such as the languages and the
 * settings: a page asks for them for every text it shows, hundreds of times.
 *
 * @return array<string, mixed>
 */
function &swiftling_memo_store() {
	static $store = array();
	return $store;
}

/**
 * Returns a value built once a request, see swiftling_forget_memo() for when it is built again.
 *
 * @param string   $key   Name of the value.
 * @param callable $build Builds it.
 * @return mixed
 */
function swiftling_memo( $key, $build ) {
	$store = &swiftling_memo_store();
	if ( ! array_key_exists( $key, $store ) ) {
		$store[ $key ] = $build();
	}
	return $store[ $key ];
}

/**
 * Forgets what was built once a request, so it is built again from what it is made of: whenever an
 * option is saved or deleted, the locale switches, which names languages and dates, or a network
 * switches to another site, which has options of its own.
 */
function swiftling_forget_memo() {
	$store = &swiftling_memo_store();
	$store = array();
}
foreach ( array( 'added_option', 'updated_option', 'deleted_option', 'change_locale', 'switch_blog' ) as $swiftling_hook ) {
	add_action( $swiftling_hook, 'swiftling_forget_memo', 0, 0 );
}
unset( $swiftling_hook );

/**
 * Whether the first setup has been answered: the site has an original language. Here, with the
 * languages, as every request may ask, not only the admin's first setup (includes/setup.php).
 *
 * @return bool
 */
function swiftling_is_set_up() {
	$saved = get_option( 'swiftling_languages', array() );
	return is_array( $saved ) && ! empty( $saved['source']['locale'] );
}

/**
 * Returns the site's own language and the languages it is translated into, in the order the
 * Languages screen sets.
 *
 * Every language carries its locale, address slug, short code (e.g. DE), name in its own language,
 * the name the switcher shows instead when the user wrote one (label), whether it is a custom
 * language, the locale WordPress runs in for it (wp_locale) and, for a custom language, its writing
 * direction (rtl, null otherwise); a target also whether visitors may see it, its host in the
 * subdomain and domain formats (empty otherwise) and whether it is on the site at all: in those
 * formats only once its host led here in a check, see hosts.php. The original's slug is its folder
 * when swiftling_source_folder() puts it in one.
 *
 * @return array{source: array, targets: array<string, array>, order: string[], names: string, name_translations: array<string, array<string, string>>}
 *         Targets are keyed by address slug, e.g. "de"; order lists the locales of all languages.
 *         name_translations: the name the user wrote for a language in the language of a page,
 *         by the locale of each, e.g. de_DE => hr => Njemački; see swiftling_language_name_in().
 */
function swiftling_languages() {
	return swiftling_memo( 'languages', 'swiftling_build_languages' );
}

/**
 * Builds what swiftling_languages() returns, from the option.
 *
 * @return array
 */
function swiftling_build_languages() {
	$saved  = get_option( 'swiftling_languages', array() );
	$saved  = is_array( $saved ) ? $saved : array();
	$source = isset( $saved['source']['locale'] ) ? $saved['source'] : array(
		'locale' => get_locale(),
		'name'   => get_locale(),
	);

	$languages = array(
		'source'            => swiftling_language_entry( $source ) + array(
			'slug' => ! empty( $source['slug'] ) ? $source['slug'] : strtolower( strtok( $source['locale'], '_' ) ),
			'code' => swiftling_language_code( $source['locale'] ),
		),
		'targets'           => array(),
		'order'             => array(),
		'names'             => isset( $saved['names'] ) && in_array( $saved['names'], array( 'native', 'page', 'both' ), true ) ? $saved['names'] : 'native',
		'name_translations' => swiftling_clean_name_translations( isset( $saved['name_translations'] ) ? $saved['name_translations'] : array() ),
	);

	$format  = swiftling_is_host_format() ? swiftling_url_format() : '';
	$domains = isset( $saved['domains'] ) && is_array( $saved['domains'] ) ? $saved['domains'] : array();
	$checks  = '' !== $format ? swiftling_address_checks() : array();
	$targets = array();
	foreach ( isset( $saved['targets'] ) && is_array( $saved['targets'] ) ? $saved['targets'] : array() as $target ) {
		$host                         = swiftling_target_host( $format, (string) $target['slug'], (string) $target['locale'], $domains );
		$targets[ $target['locale'] ] = swiftling_language_entry( $target ) + array(
			'slug'      => $target['slug'],
			'code'      => swiftling_language_code( $target['slug'] ),
			'visible'   => ! empty( $target['visible'] ),
			'host'      => $host,
			'reachable' => '' === $format || ( '' !== $host && isset( $checks[ $host ]['state'] ) && 'ok' === $checks[ $host ]['state'] ),
		);
	}

	// The saved order, then any language it does not name yet, in the order it was added.
	$all   = array_merge( array( $languages['source']['locale'] ), array_keys( $targets ) );
	$order = isset( $saved['order'] ) && is_array( $saved['order'] ) ? array_values( array_intersect( array_map( 'strval', $saved['order'] ), $all ) ) : array();

	$languages['order'] = array_values( array_unique( array_merge( $order, $all ) ) );
	foreach ( $languages['order'] as $locale ) {
		if ( isset( $targets[ $locale ] ) ) {
			$languages['targets'][ $targets[ $locale ]['slug'] ] = $targets[ $locale ];
		}
	}

	return $languages;
}

/**
 * Returns what every language carries, from its saved entry. See swiftling_languages().
 *
 * @param array $saved Saved entry of the language.
 * @return array{locale: string, name: string, label: string, custom: bool, wp_locale: string, rtl: bool|null}
 */
function swiftling_language_entry( $saved ) {
	$custom = isset( $saved['custom'] ) && is_array( $saved['custom'] ) ? $saved['custom'] : null;
	return array(
		'locale'    => (string) $saved['locale'],
		'name'      => (string) $saved['name'],
		'label'     => isset( $saved['label'] ) ? (string) $saved['label'] : '',
		'custom'    => null !== $custom,
		'wp_locale' => $custom && ! empty( $custom['wp_locale'] ) ? (string) $custom['wp_locale'] : (string) $saved['locale'],
		'rtl'       => $custom ? ! empty( $custom['rtl'] ) : null,
	);
}

/**
 * Returns every language in the order the Languages screen sets, the original among them.
 *
 * @return array<int, array> Languages as swiftling_languages() gives them; the original has the
 *                           key "source" set to true, a target the key "source" set to false.
 */
function swiftling_ordered_languages() {
	$languages = swiftling_languages();
	$by_locale = array( $languages['source']['locale'] => array( 'source' => true ) + $languages['source'] );
	foreach ( $languages['targets'] as $target ) {
		$by_locale[ $target['locale'] ] = array( 'source' => false ) + $target;
	}
	$list = array();
	foreach ( $languages['order'] as $locale ) {
		$list[] = $by_locale[ $locale ];
	}
	return $list;
}

/**
 * Turns a language code as people type it into the form locales have: language in lower case,
 * then a script in title case and a country or region in upper case, joined by underscores.
 *
 * @param string $code For example "es-mx", "ES_MX", "cnr" or "sr-latn-rs".
 * @return string For example "es_MX", "cnr" or "sr_Latn_RS"; empty when it is not a language code
 *                or longer than the 12 characters translations are stored under.
 */
function swiftling_normalize_locale( $code ) {
	$code = trim( (string) $code );
	if ( ! preg_match( '/^[a-z]{2,3}(?:[_-][a-z0-9]{2,8})*$/i', $code ) ) {
		return '';
	}
	$parts = preg_split( '/[_-]/', $code );
	foreach ( $parts as $index => $part ) {
		if ( 0 === $index ) {
			$parts[ $index ] = strtolower( $part );
		} elseif ( 2 === strlen( $part ) && ctype_alpha( $part ) ) {
			$parts[ $index ] = strtoupper( $part );
		} elseif ( 4 === strlen( $part ) && ctype_alpha( $part ) ) {
			$parts[ $index ] = ucfirst( strtolower( $part ) );
		} else {
			$parts[ $index ] = strtolower( $part );
		}
	}
	$locale = implode( '_', $parts );
	return strlen( $locale ) <= 12 ? $locale : '';
}

/**
 * Returns the name of a language in another language, such as "Njemački" for de_DE in Croatian.
 * Comes from the language data of PHP's intl extension, which most hosts have.
 *
 * @param string $locale      Locale of the language to name, e.g. "de_DE".
 * @param string $in_locale   Locale of the language to name it in, e.g. "hr".
 * @param bool   $with_region Whether to name the country too, e.g. "Španjolski (Meksiko)".
 * @return string The name with a capital first letter, or an empty string when it is not known.
 */
function swiftling_translated_language_name( $locale, $in_locale, $with_region ) {
	if ( ! class_exists( 'Locale' ) ) {
		return '';
	}
	$name     = $with_region ? \Locale::getDisplayName( $locale, $in_locale ) : \Locale::getDisplayLanguage( $locale, $in_locale );
	$language = strtolower( (string) strtok( $locale, '_' ) );
	// For a language it does not know, intl gives back its code.
	if ( ! is_string( $name ) || '' === $name || strtolower( $name ) === $language || 0 === strpos( strtolower( $name ), $language . ' (' ) ) {
		return '';
	}
	return mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
}

/**
 * Returns the names of languages written for the languages of pages, with only what can be one:
 * a name for another language, keyed by the locales of both. See swiftling_languages().
 *
 * @param mixed    $names   Saved or posted names: locale => locale of the page => name.
 * @param string[] $locales Locales of the site's languages; empty keeps every locale.
 * @return array<string, array<string, string>>
 */
function swiftling_clean_name_translations( $names, $locales = array() ) {
	$clean = array();
	foreach ( is_array( $names ) ? $names : array() as $locale => $in ) {
		$locale = (string) $locale;
		if ( ! is_array( $in ) || ( $locales && ! in_array( $locale, $locales, true ) ) ) {
			continue;
		}
		foreach ( $in as $page => $name ) {
			$page = (string) $page;
			$name = is_string( $name ) ? sanitize_text_field( $name ) : '';
			// A language is named in itself in the table of languages, not here.
			if ( '' === $name || $page === $locale || ( $locales && ! in_array( $page, $locales, true ) ) ) {
				continue;
			}
			$clean[ $locale ][ $page ] = $name;
		}
	}
	return $clean;
}

/**
 * Returns the name the user wrote for a language in the language of a page.
 *
 * @param string $locale      Locale of the language named, e.g. de_DE.
 * @param string $page_locale Locale of the language of the page, e.g. hr.
 * @return string Empty when none was written.
 */
function swiftling_language_name_in( $locale, $page_locale ) {
	$names = swiftling_languages()['name_translations'];
	return isset( $names[ $locale ][ $page_locale ] ) ? $names[ $locale ][ $page_locale ] : '';
}

/**
 * Returns one target language.
 *
 * @param string $slug Address slug, e.g. "de".
 * @return array|null The language, or null when the site is not translated into it.
 */
function swiftling_target_language( $slug ) {
	$languages = swiftling_languages();
	return isset( $languages['targets'][ $slug ] ) ? $languages['targets'][ $slug ] : null;
}

/**
 * Returns the target language of a locale.
 *
 * @param string $locale Locale, e.g. "de_DE".
 * @return array|null The language, or null when the site is not translated into it.
 */
function swiftling_language_by_locale( $locale ) {
	foreach ( swiftling_languages()['targets'] as $language ) {
		if ( $language['locale'] === $locale ) {
			return $language;
		}
	}
	return null;
}

/**
 * Notes in the log which languages were added or removed and whether the address format changed,
 * wherever the languages are saved: the Languages screen, the first setup or the URLs screen.
 *
 * @param mixed $before_saved The languages before, as saved.
 * @param mixed $now_saved    The languages now, as saved.
 */
function swiftling_log_language_changes( $before_saved, $now_saved ) {
	$names  = static function ( $saved ) {
		$found = array();
		foreach ( is_array( $saved ) && isset( $saved['targets'] ) && is_array( $saved['targets'] ) ? $saved['targets'] : array() as $target ) {
			if ( isset( $target['locale'] ) ) {
				$found[ (string) $target['locale'] ] = isset( $target['name'] ) && '' !== (string) $target['name'] ? (string) $target['name'] : (string) $target['locale'];
			}
		}
		return $found;
	};
	$before = $names( $before_saved );
	$after  = $names( $now_saved );
	foreach ( array_diff_key( $after, $before ) as $name ) {
		/* translators: %s: language name, e.g. Deutsch. */
		swiftling_log( 'event', 'languages', sprintf( __( '%s was added.', 'swiftling' ), $name ) );
	}
	foreach ( array_diff_key( $before, $after ) as $name ) {
		/* translators: %s: language name, e.g. Deutsch. */
		swiftling_log( 'event', 'languages', sprintf( __( '%s was removed.', 'swiftling' ), $name ) );
	}
	$format = static function ( $saved ) {
		return is_array( $saved ) && isset( $saved['format'] ) ? (string) $saved['format'] : 'parameter';
	};
	if ( $before && $format( $before_saved ) !== $format( $now_saved ) ) {
		/* translators: 1: address format before, 2: after, e.g. parameter, directory. */
		swiftling_log( 'event', 'addresses', sprintf( __( 'The address format changed from %1$s to %2$s.', 'swiftling' ), $format( $before_saved ), $format( $now_saved ) ) );
	}
}
add_action( 'update_option_swiftling_languages', 'swiftling_log_language_changes', 10, 2 );

/**
 * Returns the short code shown on language chips: the language part of a slug or locale.
 *
 * @param string $slug_or_locale For example "de", "pt-br" or "en_US".
 * @return string For example "DE", "PT" or "EN".
 */
function swiftling_language_code( $slug_or_locale ) {
	$parts = preg_split( '/[-_]/', $slug_or_locale );
	return strtoupper( $parts[0] );
}

/**
 * Returns the languages a site can be translated into, from the list WordPress.org translates
 * WordPress into. Without a connection, the installed languages and the ones already chosen.
 *
 * @param bool $with_chosen Whether to add the chosen languages WordPress does not list, such as
 *                          custom ones. Without them, the list holds only locales WordPress knows.
 * @return array<string, array{name: string, english: string, slug: string}> Keyed by locale, sorted by English name.
 */
function swiftling_available_languages( $with_chosen = true ) {
	require_once ABSPATH . 'wp-admin/includes/translation-install.php';

	$list = array(
		'en_US' => array(
			'name'    => 'English (United States)',
			'english' => 'English (United States)',
			'slug'    => 'en',
		),
	);

	foreach ( wp_get_available_translations() as $locale => $translation ) {
		$list[ $locale ] = array(
			'name'    => $translation['native_name'],
			'english' => $translation['english_name'],
			'slug'    => isset( $translation['iso'][1] ) ? $translation['iso'][1] : strtolower( strtok( $locale, '_' ) ),
		);
	}

	foreach ( get_available_languages() as $locale ) {
		if ( ! isset( $list[ $locale ] ) ) {
			$list[ $locale ] = array(
				'name'    => $locale,
				'english' => $locale,
				'slug'    => strtolower( strtok( $locale, '_' ) ),
			);
		}
	}

	$languages = swiftling_languages();
	foreach ( $with_chosen ? array_merge( array( $languages['source'] ), $languages['targets'] ) : array() as $chosen ) {
		if ( ! isset( $list[ $chosen['locale'] ] ) ) {
			$list[ $chosen['locale'] ] = array(
				'name'    => $chosen['name'],
				'english' => $chosen['name'],
				'slug'    => isset( $chosen['slug'] ) ? $chosen['slug'] : strtolower( strtok( $chosen['locale'], '_' ) ),
			);
		}
	}

	uasort(
		$list,
		function ( $a, $b ) {
			return strcasecmp( $a['english'], $b['english'] );
		}
	);

	return $list;
}

/**
 * Returns how a language from swiftling_available_languages() is named in a list: its own name,
 * with the English one after it when they differ.
 *
 * @param array $language Language with name and english.
 * @return string For example "Deutsch (German)" or "English (United States)".
 */
function swiftling_language_option_label( $language ) {
	return $language['name'] === $language['english'] ? $language['name'] : $language['name'] . ' (' . $language['english'] . ')';
}

/**
 * Saves the Languages screen: the target languages, their address slugs,
 * names in the switcher and order, whether visitors may see them and how the switcher names
 * languages. Adding a language from the list, adding a custom language, adding again a language no
 * longer on the site and removing a language are buttons of the same form. The address format and the original's folder are kept as they are: the
 * Addresses screen chooses them, see swiftling_save_url_format().
 */
function swiftling_save_languages() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the languages of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_save_languages' );

	$available = swiftling_available_languages();
	$wordpress = swiftling_available_languages( false );
	$current   = swiftling_languages();
	$notice    = 'saved';

	// What the site already has, by locale: custom languages keep their name and settings.
	$known = array( $current['source']['locale'] => $current['source'] );
	foreach ( $current['targets'] as $target ) {
		$known[ $target['locale'] ] = $target;
	}

	// The original is chosen in the first setup and changes only by starting over (setup.php).
	$source = $current['source']['locale'];

	// Rows as submitted: locale => slug, visible, label, and for a custom language wp_locale and rtl.
	$targets = array();
	$posted  = isset( $_POST['targets'] ) && is_array( $_POST['targets'] ) ? map_deep( wp_unslash( $_POST['targets'] ), 'sanitize_text_field' ) : array();
	foreach ( $posted as $locale => $row ) {
		$locale = sanitize_text_field( $locale );
		if ( ! isset( $available[ $locale ] ) || ! is_array( $row ) ) {
			continue;
		}
		$targets[ $locale ] = array(
			'slug'    => isset( $row['slug'] ) ? sanitize_title( $row['slug'] ) : '',
			'visible' => ! empty( $row['visible'] ),
			'label'   => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
			'custom'  => ! empty( $known[ $locale ]['custom'] ) ? array(
				'wp_locale' => isset( $row['wp_locale'] ) && isset( $wordpress[ sanitize_text_field( $row['wp_locale'] ) ] ) ? sanitize_text_field( $row['wp_locale'] ) : $known[ $locale ]['wp_locale'],
				'rtl'       => ! empty( $row['rtl'] ),
			) : null,
		);
	}

	$remove = isset( $_POST['remove'] ) ? sanitize_text_field( wp_unslash( $_POST['remove'] ) ) : '';
	unset( $targets[ $remove ] );

	$add = isset( $_POST['add'] ) && isset( $_POST['new_language'] ) ? sanitize_text_field( wp_unslash( $_POST['new_language'] ) ) : '';
	if ( '' !== $add && isset( $available[ $add ] ) && ! isset( $targets[ $add ] ) ) {
		$targets[ $add ] = array(
			'slug'    => '',
			'visible' => false,
			'label'   => '',
			'custom'  => null,
		);
	}

	// A language no longer on the site, added again from its row: its kept translations come back.
	$again = isset( $_POST['add_again'] ) ? sanitize_text_field( wp_unslash( $_POST['add_again'] ) ) : '';
	if ( '' !== $again && isset( $wordpress[ $again ] ) && ! isset( $targets[ $again ] ) ) {
		$targets[ $again ] = array(
			'slug'    => '',
			'visible' => false,
			'label'   => '',
			'custom'  => null,
		);
	}

	$custom_name = '';
	if ( isset( $_POST['add_custom'] ) ) {
		$custom      = isset( $_POST['custom'] ) && is_array( $_POST['custom'] ) ? map_deep( wp_unslash( $_POST['custom'] ), 'sanitize_text_field' ) : array();
		$custom_name = isset( $custom['name'] ) ? sanitize_text_field( $custom['name'] ) : '';
		$locale      = swiftling_normalize_locale( isset( $custom['locale'] ) ? sanitize_text_field( $custom['locale'] ) : '' );
		$wp_locale   = isset( $custom['wp_locale'] ) ? sanitize_text_field( $custom['wp_locale'] ) : '';
		if ( '' === $custom_name || '' === $locale ) {
			$notice = 'custom_invalid';
		} elseif ( isset( $targets[ $locale ] ) || $locale === $source ) {
			$notice = 'custom_exists';
		} else {
			$targets[ $locale ] = array(
				'slug'    => isset( $custom['slug'] ) ? sanitize_title( $custom['slug'] ) : '',
				'visible' => false,
				'label'   => '',
				'custom'  => array(
					// A close language WordPress has texts for; a locale WordPress itself lists is its own.
					'wp_locale' => isset( $wordpress[ $wp_locale ] ) ? $wp_locale : ( isset( $wordpress[ $locale ] ) ? $locale : 'en_US' ),
					'rtl'       => ! empty( $custom['rtl'] ),
					'name'      => $custom_name,
				),
			);
		}
	}

	// The original language is never also a target.
	unset( $targets[ $source ] );

	// The original's folder; empty keeps the one it has.
	$source_slug = isset( $_POST['source_slug'] ) ? sanitize_title( wp_unslash( $_POST['source_slug'] ) ) : '';
	if ( '' === $source_slug ) {
		$source_slug = sanitize_title( $current['source']['slug'] );
	}

	$names = isset( $_POST['names'] ) ? sanitize_key( wp_unslash( $_POST['names'] ) ) : 'native';
	$names = in_array( $names, array( 'native', 'page', 'both' ), true ) ? $names : 'native';

	$saved = array(
		'source'            => swiftling_saved_language( $source, $known, $available ) + array(
			'slug'  => $source_slug,
			'label' => isset( $_POST['source_label'] ) ? sanitize_text_field( wp_unslash( $_POST['source_label'] ) ) : '',
		),
		'targets'           => array(),
		'order'             => array(),
		'names'             => $names,
		'name_translations' => array(),
		'format'            => swiftling_saved_url_format(),
		'source_folder'     => swiftling_saved_source_folder(),
		'domains'           => swiftling_saved_domains(),
	);
	// The original keeps its folder; a target with the same one gets its full locale.
	$slugs = array( $source_slug => true );
	foreach ( $targets as $locale => $row ) {
		$slug = '' !== $row['slug'] ? $row['slug'] : sanitize_title( $available[ $locale ]['slug'] ?? strtolower( (string) strtok( $locale, '_' ) ) );
		// Two languages cannot share an address: the second one gets its full locale.
		if ( isset( $slugs[ $slug ] ) ) {
			$slug   = sanitize_title( str_replace( '_', '-', strtolower( $locale ) ) );
			$notice = 'slug';
		}
		$slugs[ $slug ] = true;
		$entry          = array(
			'locale'  => $locale,
			'slug'    => $slug,
			'name'    => isset( $row['custom']['name'] ) ? $row['custom']['name'] : swiftling_saved_language( $locale, $known, $available )['name'],
			'visible' => $row['visible'],
			'label'   => $row['label'],
		);
		if ( $row['custom'] ) {
			$entry['custom'] = array(
				'wp_locale' => $row['custom']['wp_locale'],
				'rtl'       => $row['custom']['rtl'],
			);
		}
		$saved['targets'][] = $entry;
	}

	// The order as the rows were dragged; a language just added goes last.
	$chosen         = array_merge( array( $source ), wp_list_pluck( $saved['targets'], 'locale' ) );
	$posted         = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['order'] ) ) : $current['order'];
	$saved['order'] = array_values( array_unique( array_merge( array_values( array_intersect( $posted, $chosen ) ), $chosen ) ) );

	// Names written for each language of a page, for the languages the site has once saved.
	$saved['name_translations'] = swiftling_clean_name_translations(
		isset( $_POST['name_translations'] ) && is_array( $_POST['name_translations'] ) ? map_deep( wp_unslash( $_POST['name_translations'] ), 'sanitize_text_field' ) : array(),
		$chosen
	);

	update_option( 'swiftling_languages', $saved, true );

	// The flags of the Flag column, for the languages the site has now. A form without the column
	// posts none and leaves the flags as they are.
	if ( isset( $_POST['flags'] ) && is_array( $_POST['flags'] ) ) {
		// Every language the form can name, so one removed in the same save loses its choice too.
		\SwiftLing\Switcher\Flags::save( \SwiftLing\Switcher\Flags::posted( array_keys( $available ) ), $chosen );
	}

	$args = array( 'swiftling_notice' => $notice );
	if ( in_array( $notice, array( 'custom_invalid', 'custom_exists' ), true ) && '' !== $custom_name ) {
		$args['swiftling_custom'] = rawurlencode( $custom_name );
	}
	$packs = swiftling_install_wordpress_translations( array_unique( wp_list_pluck( swiftling_languages()['targets'], 'wp_locale' ) ) );
	if ( $packs['installed'] ) {
		$args['swiftling_wp_installed'] = implode( ',', $packs['installed'] );
	}
	if ( $packs['missing'] ) {
		$args['swiftling_wp_missing'] = implode( ',', $packs['missing'] );
	}
	$taken = swiftling_taken_directories();
	if ( $taken ) {
		$args['swiftling_taken'] = implode( ',', $taken );
	}

	// Which posts count as translated depends on the languages.
	if ( wp_list_pluck( $current['targets'], 'locale' ) !== wp_list_pluck( $saved['targets'], 'locale' ) ) {
		swiftling_refresh_all_translated();
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=swiftling-languages' ) ) );
	exit;
}

/**
 * Returns how a language is saved: its locale, name and, for a custom language, its settings.
 *
 * @param string $locale    Locale.
 * @param array  $known     Languages the site has now, by locale.
 * @param array  $available Languages from swiftling_available_languages().
 * @return array{locale: string, name: string, custom?: array}
 */
function swiftling_saved_language( $locale, $known, $available ) {
	if ( ! empty( $known[ $locale ]['custom'] ) ) {
		return array(
			'locale' => $locale,
			'name'   => $known[ $locale ]['name'],
			'custom' => array(
				'wp_locale' => $known[ $locale ]['wp_locale'],
				'rtl'       => (bool) $known[ $locale ]['rtl'],
			),
		);
	}
	return array(
		'locale' => $locale,
		'name'   => $available[ $locale ]['name'],
	);
}
add_action( 'admin_post_swiftling_save_languages', 'swiftling_save_languages' );

/**
 * Downloads WordPress's own translations for the languages that do not have them yet, as Settings >
 * General does when a site changes its language: WordPress first, then the active themes and the
 * plugins, from translate.wordpress.org. A page in such a language then shows the texts of
 * WordPress, the theme and plugins translated, and its dates in the language.
 *
 * @param string[] $locales Locales, e.g. de_DE.
 * @return array{installed: string[], missing: string[]} Locales downloaded now, and locales still
 *         without translations because the site may not download them or none exist.
 */
function swiftling_install_wordpress_translations( $locales ) {
	$result = array(
		'installed' => array(),
		'missing'   => array(),
	);
	$needed = array_values( array_diff( $locales, array_merge( array( 'en_US' ), get_available_languages() ) ) );
	if ( ! $needed ) {
		return $result;
	}

	require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	if ( ! current_user_can( 'install_languages' ) || ! wp_can_install_language_pack() ) {
		$result['missing'] = $needed;
		return $result;
	}

	// WordPress's upgrader prints its progress and, when a download fails, leaves its output buffer
	// open: what it printed would end up in the answer of a REST request. Whatever it opens is
	// closed again, and what it printed dropped.
	$level = ob_get_level();
	ob_start();
	foreach ( $needed as $locale ) {
		if ( wp_download_language_pack( $locale ) ) {
			$result['installed'][] = $locale;
		} else {
			$result['missing'][] = $locale;
		}
	}

	if ( $result['installed'] ) {
		// The themes and plugins: WordPress offers their translations for every installed language.
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_clean_update_cache();
		wp_update_themes();
		wp_update_plugins();
		$updates = wp_get_translation_updates();
		if ( $updates ) {
			$upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
			$upgrader->bulk_upgrade( $updates );
		}
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}

	return $result;
}

/**
 * Returns the language folders that already lead to a post or page of the site, such as a page
 * with the slug "de". In the directory format the language takes that address over, so the post
 * can no longer be opened there. The original's folder counts when it has one.
 *
 * @return string[] Address slugs, e.g. array( 'de' ). Empty in the parameter format.
 */
function swiftling_taken_directories() {
	if ( 'directory' !== swiftling_url_format() ) {
		return array();
	}

	$taken = array();
	foreach ( swiftling_folders() as $slug ) {
		if ( url_to_postid( home_url( '/' . $slug . '/' ) ) ) {
			$taken[] = (string) $slug;
		}
	}
	return $taken;
}
