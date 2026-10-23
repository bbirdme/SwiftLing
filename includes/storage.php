<?php
/**
 * Where translations live: three small tables.
 *
 * - strings: one row per place of a text and language (its locale, e.g. de_DE). The key is a
 *   16-byte digest of the place: the object, where the text sits in it, and the version of the
 *   text (see swiftling_place_key()). The same words in two places are two translations, so each
 *   can be translated differently. The original itself is never stored, so the table holds only
 *   translated text.
 * - objects: one row per object (a post, a template, a template part or the site settings),
 *   shared by all languages. It lists where the object's texts sit in its data and the hash of
 *   each one, so the translated view knows which values to replace and the editor can tell
 *   which texts changed in the original. It also names the sources that hold texts, such as one
 *   meta key or option, so the translated view leaves every other source alone.
 * - rules: what the probe learned per element type and field, e.g. that the title of a heading
 *   widget is text and its CSS ID is not.
 * - translated: one row per post and language it is translated into, kept from the other tables
 *   so a query can leave untranslated posts out (see visibility.php).
 * - slugs: the address of a post or term in a language, and its earlier ones (see slugs.php).
 * - index: the translated text of a post, one row per post and language, so a visitor searching
 *   in their own language finds the page by its translated words, see includes/search.php.
 * - catalogue: one row per text in the code of a theme or plugin that SwiftLing found, as the
 *   code writes it (its msgid), with the theme or plugin it belongs to and when it was last seen on
 *   a page or found in the code. The Themes & plugins screen lists these texts without checking a
 *   page; their translations are kept like every other, by place, in strings.
 *
 * Nothing else is stored: no copies of posts, of builder data or of page structure.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

define( 'SWIFTLING_DB_VERSION', 11 );

// Translation states in the strings table. An official translation is the one WordPress, a theme or
// a plugin ships for a text in its code; it is noted so the editor and the progress know about it,
// and the translated view still takes it from the files, which updates keep current.
define( 'SWIFTLING_MACHINE', 1 );
define( 'SWIFTLING_REVIEWED', 2 );
define( 'SWIFTLING_OFFICIAL', 3 );

// Rule verdicts.
define( 'SWIFTLING_RULE_TEXT', 1 );
define( 'SWIFTLING_RULE_NOT_TEXT', 2 );
define( 'SWIFTLING_RULE_MIXED', 3 );

/**
 * Returns the state the screens show for a stored translation.
 *
 * @param int $status SWIFTLING_MACHINE, SWIFTLING_REVIEWED or SWIFTLING_OFFICIAL.
 * @return string machine, reviewed or official.
 */
function swiftling_status_state( $status ) {
	$states = array(
		SWIFTLING_MACHINE  => 'machine',
		SWIFTLING_OFFICIAL => 'official',
	);
	return isset( $states[ $status ] ) ? $states[ $status ] : 'reviewed';
}

/**
 * Returns the full name of a SwiftLing table.
 *
 * @param string $name One of strings, objects, rules, translated, catalogue.
 * @return string
 */
function swiftling_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'swiftling_' . $name;
}

/**
 * Adds SwiftLing's tables of a site of a network to those WordPress drops when the site is deleted:
 * it drops only the tables it is told of. Only while SwiftLing runs, as when it is active on the
 * whole network. Deleting the plugin keeps every site's tables, as it keeps all it stored.
 *
 * @param mixed      $tables  Names of the tables to drop.
 * @param int|string $site_id ID of the site being deleted.
 * @return mixed
 */
function swiftling_drop_site_tables( $tables, $site_id = 0 ) {
	global $wpdb;
	if ( ! is_array( $tables ) ) {
		return $tables;
	}
	$prefix = $wpdb->get_blog_prefix( (int) $site_id );
	foreach ( SWIFTLING_TABLES as $name ) {
		$tables[] = $prefix . 'swiftling_' . $name;
	}
	return array_values( array_unique( $tables ) );
}
add_filter( 'wpmu_drop_tables', 'swiftling_drop_site_tables', 10, 2 );

/**
 * Creates or updates the tables when the plugin's schema version changes.
 *
 * Running this twice changes nothing: dbDelta only adds what is missing.
 */
function swiftling_maybe_install_tables() {
	if ( (int) get_option( 'swiftling_db_version' ) === SWIFTLING_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$previous        = (int) get_option( 'swiftling_db_version' );
	$charset_collate = $wpdb->get_charset_collate();
	$strings         = swiftling_table( 'strings' );
	$objects         = swiftling_table( 'objects' );
	$rules           = swiftling_table( 'rules' );
	$translated      = swiftling_table( 'translated' );
	$slugs           = swiftling_table( 'slugs' );
	$catalogue       = swiftling_table( 'catalogue' );
	$index           = swiftling_table( 'index' );
	$log             = swiftling_table( 'log' );

	// The keys of catalogue stay under 767 bytes in utf8mb4, the limit of older MySQL: text domains
	// and package names are short, and a path is "string/" and an MD5.
	$wpdb->last_error = '';
	dbDelta(
		"CREATE TABLE {$strings} (
lang varchar(12) NOT NULL,
source_hash binary(16) NOT NULL,
translation mediumtext NOT NULL,
status tinyint(3) unsigned NOT NULL DEFAULT 1,
PRIMARY KEY  (lang,source_hash)
) {$charset_collate};
CREATE TABLE {$objects} (
object_key varchar(191) NOT NULL,
units mediumtext NOT NULL,
sources mediumtext NOT NULL,
refs mediumtext NOT NULL,
changed int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (object_key)
) {$charset_collate};
CREATE TABLE {$rules} (
rule_key varchar(191) NOT NULL,
verdict tinyint(3) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (rule_key)
) {$charset_collate};
CREATE TABLE {$translated} (
post_id bigint(20) unsigned NOT NULL,
lang varchar(12) NOT NULL,
PRIMARY KEY  (post_id,lang),
KEY lang (lang)
) {$charset_collate};
CREATE TABLE {$slugs} (
object_key varchar(64) NOT NULL,
lang varchar(12) NOT NULL,
slug varchar(191) NOT NULL,
current tinyint(1) unsigned NOT NULL DEFAULT 1,
status tinyint(3) unsigned NOT NULL DEFAULT 2,
PRIMARY KEY  (lang,slug,object_key),
KEY object (object_key,lang)
) {$charset_collate};
CREATE TABLE {$index} (
post_id bigint(20) unsigned NOT NULL,
lang varchar(12) NOT NULL,
title text NOT NULL,
body mediumtext NOT NULL,
PRIMARY KEY  (post_id,lang),
KEY lang (lang),
FULLTEXT KEY words (title,body)
) {$charset_collate};
CREATE TABLE {$catalogue} (
domain varchar(100) NOT NULL,
path varchar(40) NOT NULL,
package varchar(150) NOT NULL,
source text NOT NULL,
kind varchar(20) NOT NULL DEFAULT '',
seen int(10) unsigned NOT NULL DEFAULT 0,
scanned int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (domain,path),
KEY package (package)
) {$charset_collate};
CREATE TABLE {$log} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
logged int(10) unsigned NOT NULL DEFAULT 0,
level varchar(10) NOT NULL DEFAULT '',
area varchar(32) NOT NULL DEFAULT '',
message text NOT NULL,
detail text NOT NULL,
object_key varchar(64) NOT NULL DEFAULT '',
repeats int(10) unsigned NOT NULL DEFAULT 1,
PRIMARY KEY  (id),
KEY logged (logged)
) {$charset_collate};"
	);

	// Version 3 stores the language as a locale instead of an address slug, which can change.
	// Translations saved before then used the slugs of the four example languages.
	if ( (int) get_option( 'swiftling_db_version' ) < 3 ) {
		foreach ( array(
			'de' => 'de_DE',
			'en' => 'en_GB',
			'it' => 'it_IT',
			'fr' => 'fr_FR',
		) as $slug => $locale ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time upgrade of SwiftLing's own table; rows already converted no longer match.
			$wpdb->query( $wpdb->prepare( 'UPDATE IGNORE %i SET lang = %s WHERE lang = %s', $strings, $locale, $slug ) );
		}
	}

	// Version 4 keys a translation by the place of its text instead of the text alone.
	if ( (int) get_option( 'swiftling_db_version' ) < 4 ) {
		swiftling_upgrade_to_places();
	}

	// Version 5 keeps which languages each post is translated into, for hiding untranslated posts.
	// Rebuilt in full, so running it again writes the same rows.
	if ( (int) get_option( 'swiftling_db_version' ) < 5 ) {
		require_once SWIFTLING_DIR . 'includes/visibility.php';
		swiftling_refresh_all_translated();
	}

	// Version 6 widens the sources and references of an object from 64 KB: an options page with
	// thousands of fields names more sources than that, and MySQL cut the list off without an
	// error, so its last texts were never translated. dbDelta above widens the columns; a list cut
	// off before is complete again once its subject is opened in the editor.

	// Version 7 adds the table of translated slugs, created above.

	// Version 8 adds the catalogue of texts found in the code of themes and plugins; dbDelta above
	// creates it. Nothing existing changes.

	// Version 9 adds the index of translated text, for searching in the visitor's language. It is
	// written in full once, from the translations the site already has.
	if ( (int) get_option( 'swiftling_db_version' ) < 9 ) {
		require_once SWIFTLING_DIR . 'includes/search.php';
		swiftling_refresh_all_index();
	}

	// Version 10 adds the log, see includes/log.php; dbDelta above creates it.

	// Version 11 deletes the maps of posts deleted for good before such a post took its map with it,
	// see swiftling_delete_post_map().
	if ( (int) get_option( 'swiftling_db_version' ) < 11 ) {
		swiftling_delete_orphan_maps();
	}
	$failure = (string) $wpdb->last_error;

	update_option( 'swiftling_db_version', SWIFTLING_DB_VERSION, true );

	// Written once the log's table is there, so also a failure of this update is kept.
	if ( '' !== $failure ) {
		/* translators: %d: version of SwiftLing's tables. */
		swiftling_log( 'error', 'database', sprintf( __( 'Updating SwiftLing\'s tables to version %d reported an error. Some texts may not be saved until it is fixed.', 'swiftling' ), SWIFTLING_DB_VERSION ), array( 'detail' => $failure ) );
	} elseif ( $previous ) {
		/* translators: 1: version of SwiftLing's tables before, 2: after. */
		swiftling_log( 'event', 'database', sprintf( __( 'SwiftLing\'s tables were updated from version %1$d to %2$d.', 'swiftling' ), $previous, SWIFTLING_DB_VERSION ) );
	}
}

/**
 * Deletes the maps of posts that are no longer on the site. One-time, when the schema version
 * passes 11; also safe to run again: it deletes only maps whose post does not exist, so a second
 * run finds none. The keys are found in one read and deleted in short batches, each committed on
 * its own. A post in the trash still exists and keeps its map.
 */
function swiftling_delete_orphan_maps() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of SwiftLing's own table.
	$orphans = $wpdb->get_col( $wpdb->prepare( 'SELECT o.object_key FROM %i AS o LEFT JOIN %i AS p ON p.ID = CAST( SUBSTRING( o.object_key, 6 ) AS UNSIGNED ) WHERE o.object_key LIKE %s AND p.ID IS NULL', swiftling_table( 'objects' ), $wpdb->posts, $wpdb->esc_like( 'post:' ) . '%' ) );
	foreach ( array_chunk( $orphans, 200 ) as $chunk ) {
		$args = array_merge( array( swiftling_table( 'objects' ) ), $chunk );
		$sql  = 'DELETE FROM %i WHERE object_key IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time cleanup of SwiftLing's own table; $sql has one placeholder per value in $args.
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}
	if ( $orphans ) {
		swiftling_flush_cache();
	}
}

/**
 * Copies every translation saved by text to each place that shows that text, so a page keeps its
 * translations once they are kept by place. One-time, when the schema version passes 4.
 *
 * Running it again changes nothing: INSERT IGNORE leaves a place that already has a translation
 * as it is, also one saved by place in the meantime. The rows saved by text stay in the table,
 * unused, so nothing is lost; they hold only translations that are now copied. A page's list of
 * shared texts in the old form is left alone: the next check of the page writes it again.
 */
function swiftling_upgrade_to_places() {
	global $wpdb;

	$pairs = array();
	foreach ( swiftling_get_maps_by_prefix( '' ) as $owner => $map ) {
		foreach ( $map['units'] as $unit_key => $entry ) {
			if ( swiftling_is_shared_key( $unit_key ) ) {
				continue;
			}
			foreach ( (array) $entry as $hash ) {
				$pairs[] = array( $hash, swiftling_place_key( $owner, $unit_key, $hash ) );
			}
		}
	}

	$table = swiftling_table( 'strings' );
	foreach ( array_chunk( $pairs, 200 ) as $chunk ) {
		$select = array();
		$args   = array( $table, $table );
		foreach ( $chunk as $pair ) {
			$select[] = 'SELECT UNHEX(%s) AS old_key, UNHEX(%s) AS place_key';
			array_push( $args, $pair[0], $pair[1] );
		}
		$sql = 'INSERT IGNORE INTO %i (lang, source_hash, translation, status) SELECT s.lang, m.place_key, s.translation, s.status FROM %i s JOIN (' . implode( ' UNION ALL ', $select ) . ') m ON s.source_hash = m.old_key';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time upgrade of SwiftLing's own table; $sql has one placeholder per value in $args.
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	swiftling_flush_cache();
}
add_action( 'plugins_loaded', 'swiftling_maybe_install_tables' );

/**
 * Whether a string is a hash as SwiftLing writes it: 32 lowercase hex characters.
 *
 * @param mixed $hash Value to check.
 * @return bool
 */
function swiftling_is_hash( $hash ) {
	return is_string( $hash ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $hash );
}

/**
 * Returns the source of a text: the part of its object it comes from, such as one meta key, one
 * option, one post field, "b" for the object's blocks or "string" for texts from code.
 *
 * @param string $path Path of the text inside its object.
 * @return string
 */
function swiftling_path_source( $path ) {
	$parts = explode( '/', $path, 3 );
	return in_array( $parts[0], array( 'b', 'string' ), true ) ? $parts[0] : $parts[0] . '/' . ( isset( $parts[1] ) ? $parts[1] : '' );
}

/**
 * Returns the key under which a map stores a text: a short digest of the text's path inside its
 * object. The map only needs to recognise the same place again, and a digest is much shorter
 * than a path such as "meta/_elementor_data/@json/#a000001/elements/#a000006/settings/title".
 *
 * @param string $path Path of the text inside its object.
 * @return string 8 hex characters.
 */
function swiftling_path_key( $path ) {
	return substr( md5( $path ), 0, 8 );
}

/**
 * Returns the key under which a map stores an address: of a link, an image or a video. It starts
 * with "~", so counts of what is translated leave addresses out: most stay the same in every
 * language.
 *
 * @param string $path Path of the address inside its object.
 * @return string "~" and 7 hex characters.
 */
function swiftling_link_key( $path ) {
	return '~' . substr( md5( $path ), 0, 7 );
}

/**
 * Whether a map key belongs to an address: of a link, an image or a video.
 *
 * @param string $key Map key.
 * @return bool
 */
function swiftling_is_link_key( $key ) {
	return '~' === substr( (string) $key, 0, 1 );
}

/**
 * Returns the key under which a translation is stored: a digest of the place of the text, that is
 * its object and its key in the object's map, and of the version of the text. The same words in
 * two places get two translations. When the original changes, its translation is looked for under
 * a new key, and the old key still finds the translation of the previous version.
 *
 * @param string $owner    Object key.
 * @param string $unit_key Key of the text in the object's map, see swiftling_path_key() and swiftling_link_key().
 * @param string $hash     Hash of the text, see swiftling_hash().
 * @return string 32 hex characters.
 */
function swiftling_place_key( $owner, $unit_key, $hash ) {
	return md5( $owner . '|' . $unit_key . '|' . $hash );
}

/**
 * Returns the key under which a page's map lists a shared text shown inside the page's content,
 * such as a widget's default text or a synced pattern. The text itself is stored in the map of its
 * own object; the page lists it so the state of the page counts it. The key starts with "^", or
 * "~^" for an address, so translate mode and the save route can tell it from the page's own
 * texts; then come six characters of a digest of the object, which is one of the page's
 * references, and the text's key in that object's map, so the place of the text can be found.
 *
 * @param string $owner    Object key of the shared object.
 * @param string $unit_key Key of the text in that object's map.
 * @return string For example "^1a2b3c" and 8 hex characters, or "~^1a2b3c~" and 7 for an address.
 */
function swiftling_shared_key( $owner, $unit_key ) {
	return ( swiftling_is_link_key( $unit_key ) ? '~^' : '^' ) . substr( md5( $owner ), 0, 6 ) . $unit_key;
}

/**
 * Whether a key of a page's map lists a shared text rather than one of the page's own.
 *
 * @param string $key Map key.
 * @return bool
 */
function swiftling_is_shared_key( $key ) {
	$key = (string) $key;
	return '^' === substr( $key, 0, 1 ) || '~^' === substr( $key, 0, 2 );
}

/**
 * Returns the object and key a shared key of a page's map points to.
 *
 * @param string   $key  Shared key, see swiftling_shared_key().
 * @param string[] $refs The page's references: the objects of its shared texts.
 * @return array{0: string, 1: string}|null Object key and key in its map; null for a key that
 *         is not shared, of the form used before version 4, or of an object not referenced.
 */
function swiftling_shared_key_target( $key, $refs ) {
	$key    = (string) $key;
	$start  = '~^' === substr( $key, 0, 2 ) ? 2 : ( '^' === substr( $key, 0, 1 ) ? 1 : 0 );
	$digest = substr( $key, $start, 6 );
	$target = (string) substr( $key, $start + 6 );
	if ( ! $start || strlen( $target ) < 8 ) {
		return null;
	}
	foreach ( $refs as $owner ) {
		if ( substr( md5( $owner ), 0, 6 ) === $digest ) {
			return array( $owner, $target );
		}
	}
	return null;
}

/**
 * Returns where the translations of a map's texts are stored: for each text the key of the
 * current version and, while a changed text waits for its new translation, of the previous one.
 * A shared text a page lists has the key of its own object.
 *
 * @param string $owner Object key of the map.
 * @param array  $map   Map, as swiftling_get_maps() returns it.
 * @return array<string, array{0: string, 1: string}> Map key => key now, key of the previous version or "".
 */
function swiftling_map_places( $owner, $map ) {
	$places = array();
	foreach ( $map['units'] as $unit_key => $entry ) {
		$place = array( $owner, (string) $unit_key );
		if ( swiftling_is_shared_key( $unit_key ) ) {
			$place = swiftling_shared_key_target( $unit_key, $map['refs'] );
			if ( null === $place ) {
				continue;
			}
		}
		$hash                = is_array( $entry ) ? $entry[0] : $entry;
		$previous            = is_array( $entry ) ? $entry[1] : '';
		$places[ $unit_key ] = array(
			swiftling_place_key( $place[0], $place[1], $hash ),
			'' === $previous ? '' : swiftling_place_key( $place[0], $place[1], $previous ),
		);
	}
	return $places;
}

/**
 * Returns the current version of SwiftLing's cache entries. Saving anything changes it, which
 * retires every cached map and translation at once, also in a persistent object cache.
 *
 * @return string
 */
function swiftling_cache_version() {
	$version = wp_cache_get( 'version', 'swiftling' );
	if ( false === $version ) {
		$version = uniqid( '', true );
		wp_cache_set( 'version', $version, 'swiftling' );
	}
	return $version;
}

/**
 * Retires all cached maps and translations.
 */
function swiftling_flush_cache() {
	wp_cache_set( 'version', uniqid( '', true ), 'swiftling' );
}

/**
 * Returns translations for the given places.
 *
 * @param string   $lang   Locale.
 * @param string[] $hashes Keys of the places, see swiftling_place_key().
 * @return array<string, array{text: string, status: int}> Keyed by place key; missing translations are left out.
 */
function swiftling_get_translations( $lang, $hashes ) {
	global $wpdb;

	$hashes = array_values( array_unique( array_filter( $hashes, 'swiftling_is_hash' ) ) );
	$found  = array();

	foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
		$args = array_merge( array( swiftling_table( 'strings' ), $lang ), $chunk );
		$sql  = 'SELECT LOWER(HEX(source_hash)) AS h, translation AS t, status AS s FROM %i WHERE lang = %s AND source_hash IN (' . implode( ',', array_fill( 0, count( $chunk ), 'UNHEX(%s)' ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, cached by callers; $sql has one placeholder per value in $args.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		foreach ( $rows as $row ) {
			$found[ $row->h ] = array(
				'text'   => $row->t,
				'status' => (int) $row->s,
			);
		}
	}

	return $found;
}

/**
 * Saves or removes translations.
 *
 * @param string $lang  Locale.
 * @param array  $items Place key => array( 'text' => string, 'status' => int ). An empty text removes the translation.
 */
function swiftling_save_translations( $lang, $items ) {
	global $wpdb;

	$table  = swiftling_table( 'strings' );
	$rows   = array();
	$remove = array();

	foreach ( $items as $hash => $item ) {
		if ( ! swiftling_is_hash( $hash ) ) {
			continue;
		}
		if ( '' === $item['text'] ) {
			$remove[] = $hash;
		} else {
			$rows[] = array( $hash, $item['text'], (int) $item['status'] );
		}
	}

	foreach ( array_chunk( $rows, 100 ) as $chunk ) {
		$values = array();
		$args   = array( $table );
		foreach ( $chunk as $row ) {
			$values[] = '(%s, UNHEX(%s), %s, %d)';
			array_push( $args, $lang, $row[0], $row[1], $row[2] );
		}
		$sql = 'REPLACE INTO %i (lang, source_hash, translation, status) VALUES ' . implode( ', ', $values );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, cache flushed below; $sql has one placeholder per value in $args.
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	foreach ( array_chunk( $remove, 500 ) as $chunk ) {
		$args = array_merge( array( $table, $lang ), $chunk );
		$sql  = 'DELETE FROM %i WHERE lang = %s AND source_hash IN (' . implode( ',', array_fill( 0, count( $chunk ), 'UNHEX(%s)' ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, cache flushed below; $sql has one placeholder per value in $args.
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	swiftling_flush_cache();
}

/**
 * Returns the stored maps of several objects.
 *
 * @param string[] $keys Object keys, e.g. "post:12" or "part:twentytwentyfive//header".
 * @return array<string, array{units: array, sources: string[], refs: string[], changed: int}> Keyed by object key; objects without a map are left out.
 */
function swiftling_get_maps( $keys ) {
	global $wpdb;

	$keys  = array_values( array_unique( array_filter( $keys, 'is_string' ) ) );
	$found = array();

	foreach ( array_chunk( $keys, 200 ) as $chunk ) {
		$args = array_merge( array( swiftling_table( 'objects' ) ), $chunk );
		$sql  = 'SELECT object_key, units, sources, refs, changed FROM %i WHERE object_key IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, cached by callers; $sql has one placeholder per value in $args.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		foreach ( $rows as $row ) {
			$units                     = json_decode( $row->units, true );
			$found[ $row->object_key ] = array(
				'units'   => is_array( $units ) ? $units : array(),
				'sources' => '' === $row->sources ? array() : explode( ' ', $row->sources ),
				'refs'    => '' === $row->refs ? array() : explode( ' ', $row->refs ),
				'changed' => (int) $row->changed,
			);
		}
	}

	return $found;
}

/**
 * Returns the maps of all objects whose key starts with a prefix, e.g. every "gettext:" object.
 *
 * @param string $prefix Start of the object keys; empty for every object.
 * @return array<string, array{units: array, sources: string[], refs: string[], changed: int}> Keyed by object key.
 */
function swiftling_get_maps_by_prefix( $prefix ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; callers cache the result.
	$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT object_key FROM %i WHERE object_key LIKE %s', swiftling_table( 'objects' ), $wpdb->esc_like( $prefix ) . '%' ) );

	return $keys ? swiftling_get_maps( $keys ) : array();
}

/**
 * Saves the map of one object.
 *
 * @param string   $key     Object key.
 * @param array    $units   Path key => hash, or path key => array( hash, previous hash ) while a changed text waits for its new translation.
 * @param string[] $sources Sources of the object that hold texts, e.g. "meta/_elementor_data", "field/post_title" or "b" for blocks.
 * @param string[] $refs    Keys of the other objects seen on this object's page.
 * @param int      $changed Unix time of the last change to the object's texts.
 */
function swiftling_save_map( $key, $units, $sources, $refs, $changed ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the cache is flushed below.
	$wpdb->query(
		$wpdb->prepare(
			'REPLACE INTO %i (object_key, units, sources, refs, changed) VALUES (%s, %s, %s, %s, %d)',
			swiftling_table( 'objects' ),
			$key,
			wp_json_encode( (object) $units, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			implode( ' ', $sources ),
			implode( ' ', $refs ),
			$changed
		)
	);

	swiftling_flush_cache();
}

/**
 * Deletes the map of one object, and retires what was cached of it.
 *
 * @param string $key Object key.
 */
function swiftling_delete_map( $key ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the cache is flushed below.
	if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE object_key = %s', swiftling_table( 'objects' ), $key ) ) ) {
		swiftling_flush_cache();
	}
}

/**
 * Deletes the map of a post deleted for good. A post in the trash keeps it, as it may come back.
 * The translations of its texts stay: they are kept by text, and other pages may hold the same.
 *
 * @param int $post_id Post ID.
 */
function swiftling_delete_post_map( $post_id ) {
	swiftling_delete_map( 'post:' . (int) $post_id );
}
add_action( 'deleted_post', 'swiftling_delete_post_map' );

/**
 * Returns learned rules.
 *
 * @param string[] $keys Rule keys.
 * @return array<string, int> Rule key => verdict.
 */
function swiftling_get_rules( $keys ) {
	global $wpdb;

	$keys  = array_values( array_unique( $keys ) );
	$found = array();

	foreach ( array_chunk( $keys, 200 ) as $chunk ) {
		$args = array_merge( array( swiftling_table( 'rules' ) ), $chunk );
		$sql  = 'SELECT rule_key, verdict FROM %i WHERE rule_key IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, read only when a probe is saved; $sql has one placeholder per value in $args.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		foreach ( $rows as $row ) {
			$found[ $row->rule_key ] = (int) $row->verdict;
		}
	}

	return $found;
}

/**
 * Saves learned rules.
 *
 * @param array<string, int> $rules Rule key => verdict.
 */
function swiftling_save_rules( $rules ) {
	global $wpdb;

	foreach ( array_chunk( $rules, 200, true ) as $chunk ) {
		$values = array();
		$args   = array( swiftling_table( 'rules' ) );
		foreach ( $chunk as $key => $verdict ) {
			$values[] = '(%s, %d)';
			array_push( $args, $key, $verdict );
		}
		$sql = 'REPLACE INTO %i (rule_key, verdict) VALUES ' . implode( ', ', $values );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, written only when a probe is saved; $sql has one placeholder per value in $args.
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}
}
