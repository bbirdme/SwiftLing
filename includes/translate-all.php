<?php
/**
 * Filling every empty text of the site with the machine translation service, for one language in
 * one go: the Translate everything screen.
 *
 * The texts of a page are known only after a page check in the browser, see assets/js/page-check.js,
 * so the run is a loop in the admin tab: for each page it checks the page, saves what the check
 * found with the probe route, asks the machine route for the empty texts and saves them as machine
 * translations with the translations route, see assets/js/translate-all.js. These routes only plan
 * the run and keep what it did:
 *
 * - POST swiftling/v1/translate-all/plan: the pages of one language with their counts.
 * - POST swiftling/v1/translate-all/progress: what the run did with a page, and that it is finished.
 *
 * Comments and reviews are never part of the run, in the Free and the Pro version alike: a site can
 * have thousands, the run sends everything it finds to the service, which bills characters, and the
 * user chooses when to translate them, on the Comments screen, see includes/comments.php. A comment
 * is no text of any page, and the run goes through pages only; tests/TranslateAllTest.php fails if
 * the plan, the scan or the run's routes ever take one, or if this file, its script or its screen
 * name a comment function or route. Code that extends the run reads this first.
 *
 * A language is done once: the state of every run is kept per locale in the option
 * swiftling_translate_all, and the routes refuse a language that is done. A run that stopped, with
 * the tab closed or the Stop button, stays running: it goes on with the pages it has not done.
 *
 * Another plugin may drive the same run on the server, as SwiftLing Pro does so the tab can be
 * closed: it records what it did with swiftling_translate_all_record(), the function the progress
 * route calls. One drives a language at a time, see SWIFTLING_TRANSLATE_ALL_BROWSER_HOLD.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the state of the runs is kept, per locale.
 */
define( 'SWIFTLING_TRANSLATE_ALL_OPTION', 'swiftling_translate_all' );

/**
 * What a page that was never checked is guessed to hold, so the screen can say about how much a
 * language costs before anything runs: texts of a page, when no page is checked yet, and
 * characters of a text. Both are honest guesses; the screen says "about".
 */
define( 'SWIFTLING_TRANSLATE_ALL_PAGE_TEXTS', 20 );
define( 'SWIFTLING_TRANSLATE_ALL_TEXT_CHARACTERS', 60 );

/**
 * Seconds a run keeps a language for whoever drives it, the browser or the server, after it last
 * moved: the other is refused meanwhile, so the same texts are never sent twice. A browser tab
 * that was closed, or a server that stopped, frees the language once this has passed. A page with
 * many texts takes a while; the server works in steps and waits between them.
 */
define( 'SWIFTLING_TRANSLATE_ALL_BROWSER_HOLD', 3 * MINUTE_IN_SECONDS );
define( 'SWIFTLING_TRANSLATE_ALL_SERVER_HOLD', 5 * MINUTE_IN_SECONDS );

/**
 * Registers the routes of the screen. They take the rights of the SwiftLing screens, as the
 * machine route takes those of the subject it translates; what the run then saves goes through the
 * probe, machine and translations routes, which check the rights of each page again.
 */
function swiftling_register_translate_all_routes() {
	$lang_arg = array(
		'type'              => 'string',
		'required'          => true,
		'sanitize_callback' => 'sanitize_key',
		'validate_callback' => static function ( $lang ) {
			return null !== swiftling_target_language( sanitize_key( $lang ) );
		},
	);

	register_rest_route(
		'swiftling/v1',
		'/translate-all/plan',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_translate_all_plan',
			'permission_callback' => 'swiftling_rest_can_translate_all',
			'args'                => array( 'lang' => $lang_arg ),
		)
	);

	register_rest_route(
		'swiftling/v1',
		'/translate-all/progress',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_translate_all_progress',
			'permission_callback' => 'swiftling_rest_can_translate_all',
			'args'                => array(
				'lang'       => $lang_arg,
				'page'       => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'outcome'    => array(
					'type'    => 'string',
					'default' => '',
					'enum'    => array( '', 'done', 'skipped' ),
				),
				'reason'     => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'texts'      => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'characters' => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				// The pages the run set out to do, so a run that stopped can say how far it came.
				'total'      => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'finish'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				// The tab stopped the run, by its Stop button or because something went wrong: the
				// language is free for the server at once.
				'stopped'    => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'swiftling_register_translate_all_routes' );

/**
 * Whether the current user may run the whole site through the service: the right the SwiftLing
 * screens take. Each page is translated with the probe, machine and translations routes, which ask
 * for the rights of that page.
 *
 * @return bool
 */
function swiftling_rest_can_translate_all() {
	return current_user_can( 'manage_options' );
}

/**
 * Answers with the plan of one language: the pages to do with their counts.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function swiftling_rest_translate_all_plan( $request ) {
	$lang = (string) $request['lang'];
	if ( swiftling_translate_all_is_done( $lang ) ) {
		return swiftling_translate_all_done_error();
	}
	if ( 'server' === swiftling_translate_all_state( $lang )['driver'] ) {
		return swiftling_translate_all_busy_error( 'server' );
	}
	return swiftling_translate_all_plan( $lang );
}

/**
 * Records what the run in the browser did with a page, or that the language is finished, and
 * answers with the state of the run, see swiftling_translate_all_record().
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function swiftling_rest_translate_all_progress( $request ) {
	return swiftling_translate_all_record(
		(string) $request['lang'],
		array(
			'page'       => (string) $request['page'],
			'outcome'    => (string) $request['outcome'],
			'reason'     => (string) $request['reason'],
			'texts'      => (int) $request['texts'],
			'characters' => (int) $request['characters'],
			'total'      => (int) $request['total'],
			'finish'     => (bool) $request['finish'],
			'stopped'    => (bool) $request['stopped'],
			'driver'     => 'browser',
		)
	);
}

/**
 * Records what a run did with a page, or that the language is finished, and returns the state of
 * the run: for the browser through the progress route, and for another plugin that drives the run
 * on the server, as SwiftLing Pro.
 *
 * Whoever records drives the language from then on, until it stops (stopped), finishes, or has not
 * moved for SWIFTLING_TRANSLATE_ALL_BROWSER_HOLD or SWIFTLING_TRANSLATE_ALL_SERVER_HOLD; the other
 * is refused meanwhile. A call that records nothing only reads.
 *
 * @param string $lang Language slug.
 * @param array  $args page (post:12), outcome (done or skipped), reason (why it was skipped), texts
 *                     and characters (sent for the page), total (pages the run set out to do),
 *                     finish (the language is done), stopped (the driver stopped), driver
 *                     (browser or server).
 * @return array|WP_Error State, see swiftling_translate_all_state(); an error for a language that is
 *                        done, or that the other one drives.
 */
function swiftling_translate_all_record( $lang, $args = array() ) {
	$args     = wp_parse_args(
		$args,
		array(
			'page'       => '',
			'outcome'    => '',
			'reason'     => '',
			'texts'      => 0,
			'characters' => 0,
			'total'      => 0,
			'finish'     => false,
			'stopped'    => false,
			'driver'     => 'browser',
		)
	);
	$lang     = (string) $lang;
	$language = swiftling_target_language( $lang );
	if ( null === $language ) {
		return new WP_Error( 'swiftling_language', __( 'The site is not translated into this language.', 'swiftling' ), array( 'status' => 400 ) );
	}
	if ( swiftling_translate_all_is_done( $lang ) ) {
		return swiftling_translate_all_done_error();
	}

	$driver = 'server' === $args['driver'] ? 'server' : 'browser';
	$run    = swiftling_translate_all_run( $language['locale'] );
	$other  = swiftling_translate_all_driver( $run );
	if ( '' !== $other && $other !== $driver ) {
		return swiftling_translate_all_busy_error( $other );
	}
	$page = 0 === strpos( (string) $args['page'], 'post:' ) ? (int) substr( (string) $args['page'], 5 ) : 0;

	if ( $args['total'] > 0 ) {
		$run['total'] = max( (int) $args['total'], count( $run['pages'] ) + count( $run['skipped'] ) );
	}
	if ( $page && 'done' === $args['outcome'] ) {
		$run['pages'] = array_values( array_unique( array_merge( $run['pages'], array( $page ) ) ) );
		unset( $run['skipped'][ $page ] );
	}
	if ( $page && 'skipped' === $args['outcome'] ) {
		$run['skipped'][ $page ] = '' === (string) $args['reason'] ? __( 'The page was skipped.', 'swiftling' ) : (string) $args['reason'];
		swiftling_log(
			'warning',
			'all',
			/* translators: 1: title of a page, 2: language name, e.g. Deutsch, 3: why it was skipped. */
			sprintf( __( 'Translate everything skipped “%1$s” in %2$s: %3$s', 'swiftling' ), get_the_title( $page ), $language['name'], $run['skipped'][ $page ] ),
			array( 'object' => 'post:' . $page )
		);
	}
	$run['texts']      += (int) $args['texts'];
	$run['characters'] += (int) $args['characters'];
	$records            = $page || $args['total'] > 0 || $args['finish'];
	// A run starts with the first page it works on; a call that records nothing only reads.
	if ( '' === $run['status'] && $records ) {
		$run['status']  = 'running';
		$run['started'] = time();
	}
	if ( $records ) {
		$run['driver'] = $driver;
		$run['beat']   = time();
	}
	if ( $args['stopped'] || $args['finish'] ) {
		$run['driver'] = '';
	}
	if ( $args['finish'] ) {
		$run['status'] = 'done';
		$run['date']   = time();
		swiftling_log(
			'event',
			'all',
			sprintf(
				/* translators: 1: language name, e.g. Deutsch, 2: pages, 3: texts, 4: characters, 5: pages skipped. */
				__( '%1$s finished: %2$s pages, %3$s texts, %4$s characters sent to the translation service, %5$s pages skipped.', 'swiftling' ),
				$language['name'],
				number_format_i18n( count( $run['pages'] ) ),
				number_format_i18n( $run['texts'] ),
				number_format_i18n( $run['characters'] ),
				number_format_i18n( count( $run['skipped'] ) )
			)
		);
	}

	if ( '' !== $run['status'] ) {
		swiftling_translate_all_save( $language['locale'], $run );
	}

	return swiftling_translate_all_state( $lang );
}

/**
 * Returns who drives a run now: browser or server, while it has moved lately; an empty string when
 * no one does, as a run that stopped or finished.
 *
 * @param array $run Run, see swiftling_translate_all_run().
 * @return string
 */
function swiftling_translate_all_driver( $run ) {
	if ( '' === $run['driver'] || 'running' !== $run['status'] ) {
		return '';
	}
	$hold = 'server' === $run['driver'] ? SWIFTLING_TRANSLATE_ALL_SERVER_HOLD : SWIFTLING_TRANSLATE_ALL_BROWSER_HOLD;
	return $run['beat'] > time() - $hold ? $run['driver'] : '';
}

/**
 * The answer for a language another one drives now: the browser while the server does, or the
 * other way round.
 *
 * @param string $driver Who drives it: browser or server.
 * @return WP_Error
 */
function swiftling_translate_all_busy_error( $driver ) {
	$message = 'server' === $driver
		? __( 'This language is being translated on the server. Wait until it is done, or stop it there.', 'swiftling' )
		: __( 'This language is being translated in a browser tab. Stop it there, or wait a few minutes if that tab was closed.', 'swiftling' );
	return new WP_Error( 'swiftling_translate_all_busy', $message, array( 'status' => 409 ) );
}

/**
 * The answer for a language that is already done: it cannot be done again.
 *
 * @return WP_Error
 */
function swiftling_translate_all_done_error() {
	return new WP_Error( 'swiftling_translate_all_done', __( 'This language is already translated this way. Later texts are translated in the translation editor, page by page.', 'swiftling' ), array( 'status' => 409 ) );
}

/**
 * Returns the state of every run, per locale.
 *
 * @return array<string, array> Locale => run, see swiftling_translate_all_run().
 */
function swiftling_translate_all_runs() {
	$saved = get_option( SWIFTLING_TRANSLATE_ALL_OPTION, array() );
	return is_array( $saved ) ? $saved : array();
}

/**
 * Returns the state of the run of one language.
 *
 * @param string $locale Locale.
 * @return array{status: string, date: int, started: int, total: int, pages: int[], skipped: array<int, string>, texts: int, characters: int, driver: string, beat: int}
 *         Status is "" for a language never run, "running" for one under way or that stopped
 *         before it was finished, and "done" once it is finished; driver is who drove it last,
 *         browser or server, and beat when, see swiftling_translate_all_driver().
 */
function swiftling_translate_all_run( $locale ) {
	$runs  = swiftling_translate_all_runs();
	$saved = isset( $runs[ $locale ] ) && is_array( $runs[ $locale ] ) ? $runs[ $locale ] : array();
	$pages = isset( $saved['pages'] ) && is_array( $saved['pages'] ) ? array_map( 'intval', $saved['pages'] ) : array();

	$skipped = array();
	foreach ( isset( $saved['skipped'] ) && is_array( $saved['skipped'] ) ? $saved['skipped'] : array() as $page => $reason ) {
		$skipped[ (int) $page ] = (string) $reason;
	}

	return array(
		'status'     => isset( $saved['status'] ) && in_array( $saved['status'], array( 'running', 'done' ), true ) ? (string) $saved['status'] : '',
		'date'       => isset( $saved['date'] ) ? (int) $saved['date'] : 0,
		'started'    => isset( $saved['started'] ) ? (int) $saved['started'] : 0,
		'total'      => isset( $saved['total'] ) ? (int) $saved['total'] : 0,
		'pages'      => array_values( $pages ),
		'skipped'    => $skipped,
		'texts'      => isset( $saved['texts'] ) ? (int) $saved['texts'] : 0,
		'characters' => isset( $saved['characters'] ) ? (int) $saved['characters'] : 0,
		'driver'     => isset( $saved['driver'] ) && in_array( $saved['driver'], array( 'browser', 'server' ), true ) ? (string) $saved['driver'] : '',
		'beat'       => isset( $saved['beat'] ) ? (int) $saved['beat'] : 0,
	);
}

/**
 * Saves the state of the run of one language.
 *
 * @param string $locale Locale.
 * @param array  $run    Run, see swiftling_translate_all_run().
 */
function swiftling_translate_all_save( $locale, $run ) {
	$runs            = swiftling_translate_all_runs();
	$runs[ $locale ] = $run;
	update_option( SWIFTLING_TRANSLATE_ALL_OPTION, $runs );
}

/**
 * Whether a language was translated this way already. Only a finished language is done; one that
 * stopped goes on with the pages it has not done.
 *
 * @param string $lang Language slug.
 * @return bool
 */
function swiftling_translate_all_is_done( $lang ) {
	$language = swiftling_target_language( $lang );
	return null !== $language && 'done' === swiftling_translate_all_run( $language['locale'] )['status'];
}

/**
 * Returns the state of the run of a language as the screen and the runner read it: with the pages
 * it skipped named, and a link to each one's translation editor.
 *
 * @param string $lang Language slug.
 * @return array
 */
function swiftling_translate_all_state( $lang ) {
	$language = swiftling_target_language( $lang );
	$run      = swiftling_translate_all_run( $language['locale'] );

	$skipped = array();
	foreach ( $run['skipped'] as $page => $reason ) {
		$subject   = swiftling_subject( 'post:' . $page );
		$skipped[] = array(
			'key'    => 'post:' . $page,
			'title'  => null === $subject ? get_the_title( $page ) : $subject['title'],
			'reason' => $reason,
			'editor' => swiftling_editor_url( 'post:' . $page, $lang ),
		);
	}

	return array(
		'status'     => $run['status'],
		'date'       => $run['date'],
		'total'      => $run['total'],
		'pages'      => $run['pages'],
		'texts'      => $run['texts'],
		'characters' => $run['characters'],
		'skipped'    => $skipped,
		// Who drives the run now: browser, server, or "" for no one.
		'driver'     => swiftling_translate_all_driver( $run ),
	);
}

/**
 * Returns the plan of one language: the pages the run still has to do, with how many empty texts
 * each one holds, and what the whole language comes to.
 *
 * A page that was never checked has no texts known yet: it counts as a page to do, and its texts
 * are guessed from the pages that were checked, so every number of such a plan is about right.
 *
 * @param string     $lang Language slug.
 * @param array|null $scan Pages of the site with their counts, see swiftling_translate_all_scan().
 * @return array{language: array, status: string, date: int, pages: array[], texts: int, characters: int, estimated: bool, run: array}
 */
function swiftling_translate_all_plan( $lang, $scan = null ) {
	$language = swiftling_target_language( $lang );
	$state    = swiftling_translate_all_state( $lang );
	$scan     = null === $scan ? swiftling_translate_all_scan() : $scan;
	$done     = array_flip( $state['pages'] );
	$run      = swiftling_translate_all_run( $language['locale'] );

	// What a page that was never checked is guessed to hold: what the checked pages hold on average.
	$known = array();
	foreach ( $scan as $page ) {
		if ( null !== $page['empty'][ $lang ] && $page['empty'][ $lang ] > 0 ) {
			$known[] = $page['empty'][ $lang ];
		}
	}
	$guess = $known ? max( 1, (int) round( array_sum( $known ) / count( $known ) ) ) : SWIFTLING_TRANSLATE_ALL_PAGE_TEXTS;

	$pages     = array();
	$texts     = 0;
	$estimated = false;
	foreach ( $scan as $page ) {
		$empty = $page['empty'][ $lang ];
		// Pages the run already did, and pages with nothing empty, are left as they are.
		if ( isset( $done[ $page['id'] ] ) || isset( $run['skipped'][ $page['id'] ] ) || 0 === $empty ) {
			continue;
		}
		$count     = null === $empty ? $guess : $empty;
		$estimated = $estimated || null === $empty;
		$texts    += $count;
		$pages[]   = array(
			'key'     => $page['key'],
			'title'   => $page['title'],
			'type'    => $page['type'],
			'texts'   => $count,
			'checked' => null !== $empty,
			'editor'  => $page['editor'],
			'probes'  => $page['probes'],
		);
	}

	return array(
		'language'   => array(
			'slug' => $lang,
			'name' => $language['name'],
		),
		'status'     => $state['status'],
		'date'       => $state['date'],
		'pages'      => $pages,
		'texts'      => $texts,
		'characters' => $texts * SWIFTLING_TRANSLATE_ALL_TEXT_CHARACTERS,
		'estimated'  => $estimated,
		'run'        => $state,
	);
}

/**
 * Returns the pages and posts visitors can open, each with how many of its texts are still empty in
 * every language, and how many are translated by state, for a run that translates them again,
 * from what SwiftLing knows: the maps of the pages that were checked and the translations saved for
 * them. A page never checked has no count; a page whose language shows a separate copy or a
 * duplicate has nothing to translate there.
 *
 * @return array<int, array{id: int, key: string, title: string, type: string, editor: string, probes: array{marked: string, control: string}, empty: array<string, int|null>, translated: array<string, array{machine: int, reviewed: int}|null>}>
 */
function swiftling_translate_all_scan() {
	$languages = swiftling_languages()['targets'];
	$pages     = array();

	foreach ( array_chunk( swiftling_site_pages(), 100 ) as $chunk ) {
		$subjects = array();
		foreach ( $chunk as $post_id ) {
			$subject = swiftling_subject( 'post:' . $post_id );
			// A type set to Never, a separate copy or a page nobody may translate is left out.
			if ( null === $subject || ! $subject['probe'] || ! swiftling_can_translate_subject( $subject ) ) {
				continue;
			}
			$subjects[ $post_id ] = $subject;
		}
		if ( ! $subjects ) {
			continue;
		}

		list( $maps, $places, $translations ) = swiftling_object_places( wp_list_pluck( $subjects, 'key' ) );

		foreach ( $subjects as $post_id => $subject ) {
			$key        = $subject['key'];
			$empty      = array();
			$translated = array();
			foreach ( $languages as $slug => $language ) {
				if ( swiftling_shows_own_page( $post_id, $language['locale'] ) ) {
					// The language shows a page of its own here: nothing to fill in.
					$empty[ $slug ]      = 0;
					$translated[ $slug ] = array(
						'machine'  => 0,
						'reviewed' => 0,
					);
					continue;
				}
				$empty[ $slug ]      = isset( $maps[ $key ] )
					? swiftling_translate_all_empty_count( $key, swiftling_places_in( $key, $places[ $key ], $language['locale'] ), $translations[ $slug ] )
					: null;
				$translated[ $slug ] = isset( $maps[ $key ] )
					? swiftling_translate_all_state_counts( swiftling_places_in( $key, $places[ $key ], $language['locale'] ), $translations[ $slug ] )
					: null;
			}

			$pages[] = array(
				'id'         => $post_id,
				'key'        => $key,
				'title'      => $subject['title'],
				'type'       => $subject['type'],
				'editor'     => swiftling_editor_url( $key ),
				'probes'     => array(
					'marked'  => swiftling_probe_url( $subject, 'marked' ),
					'control' => swiftling_probe_url( $subject, 'control' ),
				),
				'empty'      => $empty,
				'translated' => $translated,
			);
		}
	}

	return $pages;
}

/**
 * Counts the texts of a page that the run would fill in: those without a translation. A link
 * address keeps the original unless someone types another one, and a text whose original changed
 * since it was translated is fixed in the translation editor, where the previous translation shows.
 *
 * @param string $key          Subject key.
 * @param array  $places       Places of its texts, see swiftling_object_places().
 * @param array  $translations Translations into the language, keyed by place.
 * @return int
 */
function swiftling_translate_all_empty_count( $key, $places, $translations ) {
	$count = 0;
	foreach ( $places as $unit_key => $place ) {
		if ( swiftling_is_link_key( $unit_key ) || isset( $translations[ $place[0] ] ) ) {
			continue;
		}
		// Outdated: the text waits for the editor, not for the service.
		if ( '' !== $place[1] && isset( $translations[ $place[1] ] ) ) {
			continue;
		}
		// A text that falls back to another, such as a menu's label of a page's title, follows it.
		if ( isset( $place[2] ) && isset( $translations[ $place[2] ] ) ) {
			continue;
		}
		++$count;
	}
	return $count;
}

/**
 * Counts the texts of a page translated in each state a run can translate again: machine and
 * reviewed, at the text's current original. Link addresses, texts that follow another one and
 * translations from WordPress, a theme or a plugin are left out.
 *
 * @param array $places       Places of its texts, see swiftling_object_places().
 * @param array $translations Translations into the language, keyed by place.
 * @return array{machine: int, reviewed: int}
 */
function swiftling_translate_all_state_counts( $places, $translations ) {
	$counts = array(
		'machine'  => 0,
		'reviewed' => 0,
	);
	foreach ( $places as $unit_key => $place ) {
		if ( swiftling_is_link_key( $unit_key ) || isset( $place[2] ) || ! isset( $translations[ $place[0] ] ) ) {
			continue;
		}
		$state = swiftling_status_state( $translations[ $place[0] ]['status'] );
		if ( isset( $counts[ $state ] ) ) {
			++$counts[ $state ];
		}
	}
	return $counts;
}

/**
 * Returns the ways Translate everything runs, as tabs of its screen: filling the empty texts, and
 * those another plugin adds, as SwiftLing Pro's "Translate again". SwiftLing alone has one, and
 * shows no tabs.
 *
 * @return array<string, array{label: string, description: string, hint: string, icon: string, tint: string, file?: string}>
 *         Keyed by mode; fill first. Each shows as a card: its picture (icon, a 16 by 16 line
 *         drawing, on a tile of tint), its name and what it does (hint). An added mode prints its
 *         screen with its file, below the cards.
 */
function swiftling_translate_all_modes() {
	$fill = array(
		'fill' => array(
			'label'       => __( 'Fill empty texts', 'swiftling' ),
			'description' => '',
			'hint'        => __( 'Translate the texts that have no translation yet.', 'swiftling' ),
			'icon'        => 'M2.5 2.5h11v11h-11zM8 5.5v5M5.5 8h5',
			'tint'        => 'teal',
		),
	);

	/**
	 * Filters the ways Translate everything runs, shown as cards: another plugin adds one with a
	 * name (label), a line under the screen's title (description), what it does (hint), its
	 * picture (icon, tint) and the PHP file that prints it (file). See docs/hooks.md.
	 *
	 * @param array $modes Mode => array( label, description, hint, icon, tint, file ).
	 */
	$modes = apply_filters( 'swiftling_translate_all_modes', $fill );

	$clean = $fill;
	foreach ( is_array( $modes ) ? $modes : array() as $mode => $about ) {
		if ( 'fill' === $mode || ! is_array( $about ) || empty( $about['label'] ) || empty( $about['file'] ) || ! is_readable( (string) $about['file'] ) ) {
			continue;
		}
		$clean[ sanitize_key( (string) $mode ) ] = array(
			'label'       => (string) $about['label'],
			'description' => isset( $about['description'] ) ? (string) $about['description'] : '',
			'hint'        => isset( $about['hint'] ) ? (string) $about['hint'] : '',
			'icon'        => isset( $about['icon'] ) ? (string) $about['icon'] : '',
			'tint'        => isset( $about['tint'] ) && in_array( $about['tint'], array( 'orange', 'blue', 'green', 'teal', 'amber', 'violet', 'ink' ), true ) ? (string) $about['tint'] : 'ink',
			'file'        => (string) $about['file'],
		);
	}
	return $clean;
}

/**
 * Returns what each language comes to, for the tiles and the numbers of the Translate everything
 * screen: the pages to check, the empty texts, about how many characters they are, and what a run
 * of that language did already.
 *
 * @return array<string, array{language: array, pages: int, texts: int, characters: int, estimated: bool, status: string, date: int, run: array}>
 *         Keyed by language slug.
 */
function swiftling_translate_all_summary() {
	$scan    = swiftling_translate_all_scan();
	$summary = array();
	foreach ( swiftling_languages()['targets'] as $slug => $language ) {
		$plan             = swiftling_translate_all_plan( $slug, $scan );
		$summary[ $slug ] = array(
			'language'   => $language,
			'pages'      => count( $plan['pages'] ),
			'texts'      => $plan['texts'],
			'characters' => $plan['characters'],
			'estimated'  => $plan['estimated'],
			'status'     => $plan['status'],
			'date'       => $plan['date'],
			'run'        => $plan['run'],
		);
	}
	return $summary;
}

/**
 * Returns the empty texts of a page the run has just checked, as the runner sends them to the
 * machine route and saves them with the translations route: the same fields the translation editor
 * would fill in with "Translate empty fields", see assets/js/editor.js.
 *
 * Left out are texts that already have a translation, outdated ones, link addresses, code, and
 * texts whose field the editor shows as code, which are translated by hand. A text inside tags,
 * such as a paragraph or a heading, is sent without them: they are put back around the translation,
 * as the editor does.
 *
 * Asked for texts to translate again, as SwiftLing Pro does, it lists instead the texts translated
 * in those states, see swiftling_machine_items().
 *
 * @param array    $subject Subject of the page, see swiftling_subject().
 * @param string   $lang    Language slug.
 * @param array    $result  Result of swiftling_save_probe().
 * @param string[] $again   States whose translations are listed instead of the empty texts:
 *                          machine, reviewed. Empty for the empty texts.
 * @return array<int, array{key: string, text: string, html: bool, prefix: string, suffix: string, escape: bool, state?: string}>
 */
function swiftling_translate_all_items( $subject, $lang, $result, $again = array() ) {
	// Comments are never part of the run: see the file's note.
	if ( 'comments' === $subject['kind'] ) {
		return array();
	}
	return swiftling_machine_items( $subject, $lang, $result, $again );
}

/**
 * Returns the empty texts of a stored subject as a machine translation run sends them: the texts
 * of one page for the Translate everything run, which asks through swiftling_translate_all_items(),
 * or of a batch of comments for the Comments screen.
 *
 * With states to translate again, it lists the texts translated in those states instead, each
 * with its state: machine, reviewed. A translation from WordPress, a theme or a plugin is never
 * listed, nor a text whose original changed since it was translated.
 *
 * @param array    $subject Subject, see swiftling_subject().
 * @param string   $lang    Language slug.
 * @param array    $result  Result of swiftling_save_probe() or swiftling_save_listed_texts().
 * @param string[] $again   States whose translations are listed instead: machine, reviewed.
 * @return array<int, array{key: string, text: string, html: bool, prefix: string, suffix: string, escape: bool, state?: string}>
 */
function swiftling_machine_items( $subject, $lang, $result, $again = array() ) {
	$allowed  = swiftling_translatable_keys( $subject['key'] );
	$language = swiftling_target_language( $lang );

	$keys = array();
	foreach ( $result['sections'] as $units ) {
		foreach ( $units as $unit ) {
			$map = isset( $result['maps'][ $unit['o'] ] ) ? $result['maps'][ $unit['o'] ]['units'] : array();
			array_push( $keys, ...array_filter( swiftling_live_places( $unit, $map ) ) );
		}
	}
	$translations = $keys ? swiftling_get_translations( $language['locale'], $keys ) : array();

	$items = array();
	foreach ( $result['sections'] as $units ) {
		foreach ( $units as $unit ) {
			// Addresses keep their original, and a text that follows another one follows its
			// translation, as a menu's label of a page's title follows the page's.
			if ( swiftling_is_address_kind( $unit['kind'] ) || isset( $unit['fallback'] ) || ! empty( $unit['follows'] ) || swiftling_live_is_code( $unit ) ) {
				continue;
			}
			$map                      = isset( $result['maps'][ $unit['o'] ] ) ? $result['maps'][ $unit['o'] ]['units'] : array();
			list( $place, $previous ) = swiftling_live_places( $unit, $map );
			if ( ! isset( $allowed[ $place ] ) || 'url' === $allowed[ $place ] || isset( $items[ $place ] ) ) {
				continue;
			}
			$state = isset( $translations[ $place ] ) ? swiftling_status_state( $translations[ $place ]['status'] ) : '';
			if ( $again ? ! in_array( $state, $again, true ) : ( '' !== $state || ( '' !== $previous && isset( $translations[ $previous ] ) ) ) ) {
				continue;
			}

			$item = swiftling_translate_all_item( $place, $unit['v'] );
			if ( null !== $item ) {
				$items[ $place ] = $item + ( $again ? array( 'state' => $state ) : array() );
			}
		}
	}

	return array_values( $items );
}

/**
 * Returns one text as the run sends it to the service, or null for a text it leaves alone: HTML
 * the editor shows as code, and a text without words.
 *
 * @param string $place Place key.
 * @param string $value Original.
 * @return array{key: string, text: string, html: bool, prefix: string, suffix: string, escape: bool}|null
 */
function swiftling_translate_all_item( $place, $value ) {
	$item = array(
		'key'    => $place,
		'text'   => $value,
		'html'   => false,
		'prefix' => '',
		'suffix' => '',
		'escape' => false,
	);

	if ( 1 === preg_match( '~<[a-z/!][^>]*>~i', $value ) ) {
		$parts = swiftling_rich_parts( $value );
		if ( null === $parts ) {
			// HTML the editor shows as a code field, which machine translation leaves alone.
			return null;
		}
		list( $prefix, $middle, $suffix ) = $parts;
		$plain                            = swiftling_plain_text( $middle );
		$item['prefix']                   = $prefix;
		$item['suffix']                   = $suffix;
		// Plain text inside tags, such as a heading: the tags go back around the translation, which
		// is escaped first. Formatted text goes to the service as HTML and keeps its formatting.
		$item['text']   = null === $plain ? $middle : $plain;
		$item['html']   = null === $plain;
		$item['escape'] = null !== $plain;
	}

	return '' === trim( $item['text'] ) ? null : $item;
}
