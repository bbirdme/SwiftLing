<?php
/**
 * Texts from the code of themes and plugins that SwiftLing found.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * The catalogue table: one row per text a theme or plugin writes in its code, as gettext sees it.
 *
 * A text's place in its object ("gettext:<domain>") is the path the translated view finds it under,
 * see swiftling_string_path(), so a translation made from this list shows wherever WordPress hands
 * the text over. A row notes when the text was last seen on a page of the site (a recording or a
 * page check found it there) and when it was last found in the code by a scan; a text found in
 * neither is gone and its row is removed.
 */
final class Catalogue {
	/**
	 * Saves texts found, or notes them again.
	 *
	 * @param string $package Package ID.
	 * @param array  $texts   Texts: d (text domain), t (text or singular), and where they apply
	 *                        c (context), p (plural), n (number the form is chosen for) and k (the
	 *                        kind of text a page showed, such as button).
	 * @param string $how     seen (on a page) or scanned (in the code).
	 * @param int    $time    Unix time.
	 */
	public static function add( string $package, array $texts, string $how, int $time ): void {
		global $wpdb;

		$rows = array();
		foreach ( $texts as $text ) {
			if ( ! isset( $text['d'], $text['t'] ) || ! is_string( $text['d'] ) || ! is_string( $text['t'] ) || '' === $text['t'] ) {
				continue;
			}
			$source = array( 't' => $text['t'] );
			foreach ( array( 'c', 'p' ) as $key ) {
				if ( isset( $text[ $key ] ) && is_string( $text[ $key ] ) ) {
					$source[ $key ] = $text[ $key ];
				}
			}
			if ( isset( $source['p'] ) ) {
				$source['n'] = isset( $text['n'] ) ? (int) $text['n'] : 1;
			}
			$path = swiftling_string_path( $source );
			// One row per place: the same text twice on a page, or in two files, is one text.
			$rows[ $text['d'] . '|' . $path ] = array(
				substr( $text['d'], 0, 100 ),
				$path,
				$package,
				(string) wp_json_encode( $source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				isset( $text['k'] ) && is_string( $text['k'] ) ? substr( sanitize_key( $text['k'] ), 0, 20 ) : '',
			);
		}

		$seen    = 'seen' === $how ? $time : 0;
		$scanned = 'scanned' === $how ? $time : 0;
		foreach ( array_chunk( array_values( $rows ), 100 ) as $chunk ) {
			$values = array();
			$args   = array( swiftling_table( 'catalogue' ) );
			foreach ( $chunk as $row ) {
				$values[] = '(%s, %s, %s, %s, %s, %d, %d)';
				array_push( $args, $row[0], $row[1], $row[2], $row[3], $row[4], $seen, $scanned );
			}
			$sql = 'INSERT INTO %i (domain, path, package, source, kind, seen, scanned) VALUES ' . implode( ', ', $values )
				. ' ON DUPLICATE KEY UPDATE package = VALUES(package), source = VALUES(source), kind = IF(VALUES(kind) <> \'\', VALUES(kind), kind), seen = GREATEST(seen, VALUES(seen)), scanned = GREATEST(scanned, VALUES(scanned))';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SwiftLing's own table; $sql has one placeholder per value in $args.
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Returns the texts of a package.
	 *
	 * @param string $package Package ID.
	 * @param string $which   seen (on a page), scanned (found in the code) or all.
	 * @return array<int, array{d: string, path: string, source: array, kind: string, seen: int, scanned: int}>
	 *         In no particular order.
	 */
	public static function texts( string $package, string $which = 'all' ): array {
		global $wpdb;

		$where = array(
			'seen'    => ' AND seen > 0',
			'scanned' => ' AND scanned > 0',
		);
		$sql   = 'SELECT domain, path, source, kind, seen, scanned FROM %i WHERE package = %s' . ( isset( $where[ $which ] ) ? $where[ $which ] : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SwiftLing's own table, read on its screens; $sql holds only fixed text besides the placeholders.
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, swiftling_table( 'catalogue' ), $package ) );
		$texts = array();
		foreach ( $rows as $row ) {
			$source = json_decode( (string) $row->source, true );
			if ( ! is_array( $source ) || ! isset( $source['t'] ) || ! is_string( $source['t'] ) ) {
				continue;
			}
			$texts[] = array(
				'd'       => (string) $row->domain,
				'path'    => (string) $row->path,
				'source'  => $source,
				'kind'    => (string) $row->kind,
				'seen'    => (int) $row->seen,
				'scanned' => (int) $row->scanned,
			);
		}
		return $texts;
	}

	/**
	 * Returns how many texts of each package were seen on pages, found in the code, and both
	 * together, as the list of all its texts shows them.
	 *
	 * @return array<string, array{seen: int, scanned: int, all: int}> Package ID => counts.
	 */
	public static function counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, counted on its screen.
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT package, SUM(seen > 0) AS seen, SUM(scanned > 0) AS scanned, COUNT(*) AS total FROM %i GROUP BY package', swiftling_table( 'catalogue' ) ) );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row->package ] = array(
				'seen'    => (int) $row->seen,
				'scanned' => (int) $row->scanned,
				'all'     => (int) $row->total,
			);
		}
		return $counts;
	}

	/**
	 * Starts a new scan of a package's code: texts found by earlier scans count as not found until
	 * this scan finds them again.
	 *
	 * @param string $package Package ID.
	 */
	public static function startScan( string $package ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET scanned = 0 WHERE package = %s', swiftling_table( 'catalogue' ), $package ) );
	}

	/**
	 * Removes the texts of a package that a finished scan did not find and no page showed: the code
	 * no longer has them.
	 *
	 * @param string $package Package ID.
	 */
	public static function finishScan( string $package ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE package = %s AND seen = 0 AND scanned = 0', swiftling_table( 'catalogue' ), $package ) );
	}
}
