<?php
/**
 * Finding every text in the code of a theme or plugin.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the PHP files of a theme or plugin and finds every text its code hands to gettext, as tools
 * that make translation templates (.pot files) find them: calls of __(), _e(), _x(), _n() and their
 * escaping variants whose text and text domain are written out in the code.
 *
 * A text built at run time, such as __( $label, 'shop' ), cannot be found this way; a page shows it
 * when it is used, so a recording still finds it. Texts in JavaScript files are translated by
 * WordPress separately and are not read.
 *
 * A large plugin has thousands of files, so a scan runs in steps of a few seconds each; the list of
 * files is made once and kept for the steps that follow.
 */
final class CodeScanner {
	/**
	 * Most seconds one step reads files for.
	 */
	public const STEP_SECONDS = 4;

	/**
	 * Largest file read, in bytes. Bigger PHP files are built data, not code that writes texts.
	 */
	private const MAX_FILE_SIZE = 2097152;

	/**
	 * Folders never read: tests, dependencies of the build and version control.
	 */
	private const SKIPPED_FOLDERS = array( 'node_modules', 'tests', 'test', '.git', '.github', 'bin' );

	/**
	 * Gettext functions and where their arguments are: text (t), plural (p), context (c), domain (d).
	 */
	private const FUNCTIONS = array(
		'__'         => array(
			't' => 0,
			'd' => 1,
		),
		'_e'         => array(
			't' => 0,
			'd' => 1,
		),
		'esc_html__' => array(
			't' => 0,
			'd' => 1,
		),
		'esc_html_e' => array(
			't' => 0,
			'd' => 1,
		),
		'esc_attr__' => array(
			't' => 0,
			'd' => 1,
		),
		'esc_attr_e' => array(
			't' => 0,
			'd' => 1,
		),
		'_x'         => array(
			't' => 0,
			'c' => 1,
			'd' => 2,
		),
		'_ex'        => array(
			't' => 0,
			'c' => 1,
			'd' => 2,
		),
		'esc_html_x' => array(
			't' => 0,
			'c' => 1,
			'd' => 2,
		),
		'esc_attr_x' => array(
			't' => 0,
			'c' => 1,
			'd' => 2,
		),
		'_n'         => array(
			't' => 0,
			'p' => 1,
			'd' => 3,
		),
		'_nx'        => array(
			't' => 0,
			'p' => 1,
			'c' => 3,
			'd' => 4,
		),
		'_n_noop'    => array(
			't' => 0,
			'p' => 1,
			'd' => 2,
		),
		'_nx_noop'   => array(
			't' => 0,
			'p' => 1,
			'c' => 2,
			'd' => 3,
		),
	);

	/**
	 * Option that keeps, per package, when its code was last scanned and what was found.
	 */
	public const SCANS_OPTION = 'swiftling_package_scans';

	/**
	 * Runs one step of a scan of a package's code: reads the next files, for a few seconds at most,
	 * and saves the texts found. The first step (offset 0) starts the scan; the last one finishes it.
	 *
	 * @param string $id     Package ID.
	 * @param int    $offset Number of files read by the steps before.
	 * @return array{offset: int, total: int, done: bool, texts: int} Where the next step starts, how
	 *         many files there are, whether the scan is done and how many texts this step found.
	 */
	public static function step( string $id, int $offset ): array {
		$package = Packages::get( $id );
		if ( ! $package ) {
			return array(
				'offset' => 0,
				'total'  => 0,
				'done'   => true,
				'texts'  => 0,
			);
		}

		$key = 'swiftling_scan_files_' . md5( $id );
		if ( 0 === $offset ) {
			$files = self::files( $package['dir'] );
			set_transient( $key, $files, HOUR_IN_SECONDS );
			Catalogue::startScan( $id );
			self::note( $id, array( 'started' => time() ) );
		} else {
			$files = get_transient( $key );
			if ( ! is_array( $files ) ) {
				// The list of files expired: the scan starts again.
				return self::step( $id, 0 );
			}
		}

		$total   = count( $files );
		$started = microtime( true );
		$scan    = self::scans()[ $id ]['started'] ?? time();
		$found   = 0;
		while ( $offset < $total && microtime( true ) - $started < self::STEP_SECONDS ) {
			$texts = self::textsIn( $package['dir'] . $files[ $offset ] );
			++$offset;
			if ( ! $texts ) {
				continue;
			}
			$found += count( $texts );
			Catalogue::add( $id, $texts, 'scanned', (int) $scan );
			$domains = array();
			foreach ( $texts as $text ) {
				$domains[ $text['d'] ] = $id;
			}
			Packages::learnDomains( $domains );
		}

		$done = $offset >= $total;
		if ( $done ) {
			Catalogue::finishScan( $id );
			delete_transient( $key );
			self::note(
				$id,
				array(
					'finished' => time(),
					'version'  => $package['version'],
				)
			);
		}
		return array(
			'offset' => $offset,
			'total'  => $total,
			'done'   => $done,
			'texts'  => $found,
		);
	}

	/**
	 * Returns what is known of the scans of every package: when each started and finished, and the
	 * version of the package the last finished scan read.
	 *
	 * @return array<string, array{started?: int, finished?: int, version?: string}>
	 */
	public static function scans(): array {
		$scans = swiftling_raw_option( self::SCANS_OPTION, array() );
		return is_array( $scans ) ? $scans : array();
	}

	/**
	 * Notes facts about the scan of a package.
	 *
	 * @param string $id    Package ID.
	 * @param array  $facts Facts.
	 */
	private static function note( string $id, array $facts ): void {
		$scans        = self::scans();
		$scans[ $id ] = array_merge( isset( $scans[ $id ] ) ? $scans[ $id ] : array(), $facts );
		update_option( self::SCANS_OPTION, $scans, false );
	}

	/**
	 * Returns the PHP files of a package, relative to its folder, in a fixed order.
	 *
	 * @param string $dir Folder of the package, with a slash at the end, or the file of a plugin of one file.
	 * @return string[]
	 */
	public static function files( string $dir ): array {
		if ( is_file( $dir ) ) {
			return array( '' );
		}
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $file ): bool {
					if ( $file->isDir() ) {
						return ! in_array( strtolower( $file->getFilename() ), self::SKIPPED_FOLDERS, true ) && ! $file->isLink();
					}
					return 'php' === strtolower( $file->getExtension() ) && $file->getSize() <= self::MAX_FILE_SIZE;
				}
			)
		);
		foreach ( $iterator as $file ) {
			$files[] = substr( wp_normalize_path( $file->getPathname() ), strlen( $dir ) );
		}
		sort( $files );
		return $files;
	}

	/**
	 * Returns the texts the code of one PHP file hands to gettext.
	 *
	 * @param string $file Path of the file.
	 * @return array<int, array{d: string, t: string, c?: string, p?: string, n?: int}> A text with a
	 *         plural is listed twice, once per form, as a page shows one or the other.
	 */
	public static function textsIn( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local PHP file of an active theme or plugin.
		$code = is_readable( $file ) ? file_get_contents( $file ) : false;
		return false === $code ? array() : self::textsInCode( $code );
	}

	/**
	 * Returns the texts PHP code hands to gettext, see textsIn().
	 *
	 * @param string $code PHP code.
	 * @return array
	 */
	public static function textsInCode( string $code ): array {
		if ( false === strpos( $code, '_' ) ) {
			return array();
		}
		try {
			$tokens = token_get_all( $code );
		} catch ( \ParseError $error ) {
			return array();
		}

		$texts = array();
		$count = count( $tokens );
		for ( $index = 0; $index < $count; $index++ ) {
			$name = self::functionName( $tokens, $index );
			if ( null === $name ) {
				continue;
			}
			$args = self::arguments( $tokens, $index + 1 );
			if ( null === $args ) {
				continue;
			}
			$found = self::text( self::FUNCTIONS[ $name ], $args );
			if ( null === $found ) {
				continue;
			}
			if ( isset( $found['p'] ) ) {
				// The singular and the plural are the two places a page can show.
				$texts[] = $found + array( 'n' => 1 );
				$texts[] = $found + array( 'n' => 2 );
			} else {
				$texts[] = $found;
			}
		}
		return $texts;
	}

	/**
	 * Returns the name of a gettext function called at a token, when it is one: a plain call, not a
	 * method or a function of the same name being declared.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index  Position of the token.
	 */
	private static function functionName( array $tokens, int $index ): ?string {
		$token = $tokens[ $index ];
		if ( ! is_array( $token ) ) {
			return null;
		}
		$name = $token[1];
		// PHP 8 reads "\__" as one name.
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] ) {
			$name = ltrim( $name, '\\' );
		} elseif ( T_STRING !== $token[0] ) {
			return null;
		}
		if ( ! isset( self::FUNCTIONS[ $name ] ) ) {
			return null;
		}

		$before = self::significant( $tokens, $index, -1 );
		if ( null !== $before ) {
			$previous = $tokens[ $before ];
			if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
				return null;
			}
			if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && is_array( $previous ) && T_NULLSAFE_OBJECT_OPERATOR === $previous[0] ) {
				return null;
			}
		}
		$after = self::significant( $tokens, $index, 1 );
		return null !== $after && '(' === $tokens[ $after ] ? $name : null;
	}

	/**
	 * Returns the position of the next token in a direction that is not whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index  Position to start from.
	 * @param int   $step   1 forward, -1 backward.
	 */
	private static function significant( array $tokens, int $index, int $step ): ?int {
		for ( $at = $index + $step; isset( $tokens[ $at ] ); $at += $step ) {
			if ( is_array( $tokens[ $at ] ) && in_array( $tokens[ $at ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $at;
		}
		return null;
	}

	/**
	 * Returns the arguments of a call, each as the value of its string literals, or null for an
	 * argument that is not made of literals only.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index  Position of the token after the function's name.
	 * @return array<int, string|null>|null Null when the call is not closed.
	 */
	private static function arguments( array $tokens, int $index ): ?array {
		$open = self::significant( $tokens, $index - 1, 1 );
		if ( null === $open || '(' !== $tokens[ $open ] ) {
			return null;
		}
		$args    = array();
		$current = '';
		$literal = true;
		$empty   = true;
		$depth   = 0;
		$count   = count( $tokens );
		for ( $at = $open + 1; $at < $count; $at++ ) {
			$token = $tokens[ $at ];
			if ( in_array( $token, array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $token, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth ) {
					$args[] = $literal && ! $empty ? $current : null;
					return $args;
				}
				--$depth;
			}
			if ( 0 === $depth && ',' === $token ) {
				$args[]  = $literal && ! $empty ? $current : null;
				$current = '';
				$literal = true;
				$empty   = true;
				continue;
			}
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( '.' === $token ) {
				continue;
			}
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$current .= self::literal( $token[1] );
				$empty    = false;
				continue;
			}
			$literal = false;
			$empty   = false;
		}
		return null;
	}

	/**
	 * Returns the value of a PHP string literal without variables, quoted as in the code.
	 *
	 * @param string $quoted Literal with its quotes.
	 */
	private static function literal( string $quoted ): string {
		$inner = substr( $quoted, 1, -1 );
		if ( "'" === $quoted[0] ) {
			return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $inner );
		}
		return stripcslashes( $inner );
	}

	/**
	 * Returns the text of a call, from its arguments.
	 *
	 * @param array $positions Where the function keeps text, plural, context and domain.
	 * @param array $args      Arguments, see arguments().
	 * @return array|null Null when the text or the domain is not written out, the text has no
	 *         letter, or the domain is WordPress's own.
	 */
	private static function text( array $positions, array $args ): ?array {
		$found = array();
		foreach ( $positions as $part => $position ) {
			if ( ! isset( $args[ $position ] ) || null === $args[ $position ] ) {
				return null;
			}
			$found[ $part ] = $args[ $position ];
		}
		// A text without words, such as "#" or "%1$s %2$s", has nothing to translate.
		if ( ! Texts::hasWords( $found['t'] ) || '' === $found['d'] || 'default' === $found['d'] ) {
			return null;
		}
		return $found;
	}
}
