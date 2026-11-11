<?php
/**
 * REST routes of the translation editor. They are internal to the editor and are not the public
 * API for importing translations, which is still to be designed.
 *
 * - POST swiftling/v1/probe: stores what a probe found and returns the editor's rows, or the empty
 *   texts of the page when the whole-site run asks for them, see includes/translate-all.php. For a list
 *   of terms, it learns which fields of terms its page shows as text, and which theme or plugin
 *   reads them, see includes/terms.php.
 * - POST swiftling/v1/translations: saves translations typed in the editor.
 * - POST swiftling/v1/machine: translates texts of the editor with the machine translation service.
 *
 * The translations and machine routes do what includes/api.php does for translating without the
 * editor's screen, with the same functions.
 * - POST swiftling/v1/slug: saves the address of a post or a term in a language, see slugs.php.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the routes.
 */
function swiftling_register_rest_routes() {
	$subject_arg = array(
		'type'              => 'string',
		'required'          => true,
		'sanitize_callback' => 'sanitize_text_field',
	);
	$lang_arg    = array(
		'type'              => 'string',
		'required'          => true,
		'sanitize_callback' => 'sanitize_key',
		'validate_callback' => function ( $lang ) {
			return null !== swiftling_target_language( sanitize_key( $lang ) );
		},
	);

	register_rest_route(
		'swiftling/v1',
		'/probe',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_probe',
			'permission_callback' => 'swiftling_rest_can_edit',
			'args'                => array(
				'subject'   => $subject_arg,
				'lang'      => $lang_arg,
				'registry'  => array(
					'type'     => 'string',
					'required' => true,
				),
				'signature' => array(
					'type'     => 'string',
					'required' => true,
				),
				'found'     => array(
					'type'     => 'object',
					'required' => true,
				),
				'control'   => array(
					'type'     => 'array',
					'required' => true,
				),
				'scoped'    => array(
					'type'    => 'boolean',
					'default' => false,
				),
				// The whole-site run asks for the empty texts of the page instead of the
				// editor's rows, see includes/translate-all.php.
				'fill'      => array(
					'type'    => 'boolean',
					'default' => false,
				),
				// With fill: the translated texts in these states instead of the empty ones, for
				// SwiftLing Pro's "Translate again", see swiftling_machine_items().
				'again'     => array(
					'type'    => 'array',
					'default' => array(),
					'items'   => array(
						'type' => 'string',
						'enum' => array( 'machine', 'reviewed' ),
					),
				),
				// The page of a list of terms the editor shows, and its search.
				'paged'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'find'      => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'swiftling/v1',
		'/translations',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_translations',
			'permission_callback' => 'swiftling_rest_can_edit',
			'args'                => array(
				'subject' => $subject_arg,
				'lang'    => $lang_arg,
				'status'  => array(
					'type'     => 'string',
					'enum'     => array( 'machine', 'reviewed' ),
					'required' => true,
				),
				'items'   => array(
					'type'     => 'array',
					'required' => true,
				),
			),
		)
	);

	// A page check runs in the editor's browser; when it fails there, the editor says so here, for the log.
	register_rest_route(
		'swiftling/v1',
		'/check-failed',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_check_failed',
			'permission_callback' => 'swiftling_rest_can_edit',
			'args'                => array(
				'subject' => $subject_arg,
				'lang'    => $lang_arg,
				'reason'  => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'swiftling_register_rest_routes' );

/**
 * Notes in the log that a page check in the editor failed, with what the browser said.
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function swiftling_rest_check_failed( $request ) {
	$subject  = swiftling_subject( (string) $request['subject'] );
	$language = swiftling_target_language( (string) $request['lang'] );
	swiftling_log(
		'error',
		'check',
		/* translators: %s: title of a page or template. */
		sprintf( __( 'The page check of “%s” failed, so the editor could not list its texts. Reloading the editor checks the page again.', 'swiftling' ), $subject['title'] ),
		array(
			'detail' => trim( substr( (string) $request['reason'], 0, 200 ) . ( $language ? ' · ' . $language['name'] : '' ), ' ·' ),
			'object' => $subject['key'],
		)
	);
	return array( 'logged' => true );
}

/**
 * Registers the route that translates texts of the editor with the machine translation service.
 */
function swiftling_register_machine_route() {
	register_rest_route(
		'swiftling/v1',
		'/machine',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_machine',
			'permission_callback' => static function ( $request ) {
				return swiftling_rest_can_edit( $request ) && swiftling_can_machine_translate();
			},
			'args'                => array(
				'subject' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'lang'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static function ( $lang ) {
						return null !== swiftling_target_language( sanitize_key( $lang ) );
					},
				),
				'items'   => array(
					'type'     => 'array',
					'required' => true,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'swiftling_register_machine_route' );

/**
 * Registers the route that chooses how a language shows a post, for the panel in the block editor.
 */
function swiftling_register_mode_route() {
	register_rest_route(
		'swiftling/v1',
		'/mode',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_language_mode',
			'permission_callback' => static function ( $request ) {
				return swiftling_can_choose_mode( get_post( absint( $request['post'] ) ) );
			},
			'args'                => array(
				'post' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'lang' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static function ( $lang ) {
						return null !== swiftling_target_language( sanitize_key( $lang ) );
					},
				),
				'mode' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'translation', 'manual', 'duplicate' ),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'swiftling_register_mode_route' );

/**
 * Chooses how a language shows a post, see swiftling_set_language_mode(), and returns the language
 * as the panel shows it.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function swiftling_rest_language_mode( $request ) {
	$post = get_post( $request['post'] );
	$done = swiftling_set_language_mode( $post, $request['lang'], $request['mode'] );
	if ( is_wp_error( $done ) ) {
		$done->add_data( array( 'status' => 400 ) );
		return $done;
	}
	return array(
		'done'     => $done,
		'language' => \SwiftLing\Admin\EditorPanel::language( $post, $request['lang'] ),
	);
}

/**
 * Whether the current user may use the editor for the subject in the request: a post, a template
 * or the terms of a taxonomy.
 *
 * @param WP_REST_Request $request Request.
 * @return bool
 */
function swiftling_rest_can_edit( $request ) {
	$subject = swiftling_subject( (string) $request['subject'] );
	return $subject && swiftling_can_translate_subject( $subject );
}

/**
 * Stores what a probe found and returns the editor's rows.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function swiftling_rest_probe( $request ) {
	$subject = swiftling_subject( (string) $request['subject'] );
	if ( ! $subject ) {
		swiftling_log( 'warning', 'check', __( 'A page check could not be verified, so what it found was not saved. Reloading the editor checks the page again.', 'swiftling' ), array( 'object' => (string) $request['subject'] ) );
		return new WP_Error( 'swiftling_probe_invalid', __( 'The page check could not be verified. Reload the editor.', 'swiftling' ), array( 'status' => 400 ) );
	}
	$stored = swiftling_store_page_check( $subject, (string) $request['registry'], (string) $request['signature'], $request['found'], $request['control'], (bool) $request['scoped'] );
	if ( is_wp_error( $stored ) ) {
		return $stored;
	}
	$result = $stored['result'];

	if ( 'terms' === $subject['kind'] ) {
		$result = swiftling_term_list_page(
			$result,
			array(
				'page'   => (int) $request['paged'],
				'search' => (string) $request['find'],
			)
		);
		return array(
			'rows'   => swiftling_live_rows_html( $subject, $request['lang'], $result ),
			'extras' => swiftling_live_extras_count( $result ),
		);
	}

	// The whole-site run fills the empty texts itself, so it asks for them instead of the rows.
	if ( $request['fill'] ) {
		$items = swiftling_translate_all_items( $subject, $request['lang'], $result, array_values( array_unique( (array) $request['again'] ) ) );
		return array(
			'items' => $items,
			'texts' => count( $items ),
		);
	}

	return array(
		'rows'   => swiftling_live_rows_html( $subject, $request['lang'], $result ),
		'extras' => swiftling_live_extras_count( $result ),
	);
}

/**
 * Stores what a page check found, whether the browser of the editor or the server checked the
 * page, see swiftling_server_check(): only a list of marked values the probe printed itself, of
 * the subject asked for, and for a template only from a page that shows it.
 *
 * @param array  $subject   Subject, see swiftling_subject().
 * @param string $payload   The list of marked values, as the probe printed it.
 * @param string $signature Its signature.
 * @param mixed  $found     Landings of the markers, see swiftling_clean_landings().
 * @param mixed  $control   Texts of the page as visitors see it, see swiftling_clean_control().
 * @param bool   $scoped    Whether the page showed where the content of posts is.
 * @return array{result: array}|WP_Error What was stored: for a post or template the editor's texts,
 *         see swiftling_save_probe(); for a list of terms its texts, see swiftling_save_term_list().
 */
function swiftling_store_page_check( $subject, $payload, $signature, $found, $control, $scoped ) {
	$registry = json_decode( (string) $payload, true );
	if ( ! $subject['probe'] || ! hash_equals( swiftling_probe_signature( (string) $payload ), (string) $signature ) || ! is_array( $registry ) || ! isset( $registry['subject'] ) || $registry['subject'] !== $subject['key'] ) {
		swiftling_log( 'warning', 'check', __( 'A page check could not be verified, so what it found was not saved. Reloading the editor checks the page again.', 'swiftling' ), array( 'object' => (string) $subject['key'] ) );
		return new WP_Error( 'swiftling_probe_invalid', __( 'The page check could not be verified. Reload the editor.', 'swiftling' ), array( 'status' => 400 ) );
	}
	// The example page of a template must show that template; otherwise its texts would be saved for another one.
	if ( 'template' === $subject['kind'] && 'template:' . ( isset( $registry['template'] ) ? $registry['template'] : '' ) !== $subject['key'] ) {
		swiftling_log( 'warning', 'check', __( 'The example page of a template now shows another template, so its texts were not saved for this one.', 'swiftling' ), array( 'object' => $subject['key'] ) );
		return new WP_Error( 'swiftling_probe_template', __( 'The example page shows another template now. Reload the editor.', 'swiftling' ), array( 'status' => 409 ) );
	}

	$registry['leaves'] = is_array( $registry['leaves'] ) ? $registry['leaves'] : array();

	// The page of a list's term shows which fields of terms are text. Only that is learned; the
	// texts of the list are its terms', not the page's.
	if ( 'terms' === $subject['kind'] ) {
		$verdicts = swiftling_probe_verdicts( $registry['leaves'], swiftling_clean_landings( $found ), swiftling_clean_control( $control ) );
		swiftling_save_learned_rules( $verdicts['rules'] );
		swiftling_note_term_field_sources( substr( $subject['key'], 6 ), isset( $registry['term_fields'] ) && is_array( $registry['term_fields'] ) ? $registry['term_fields'] : array() );
		$result = swiftling_save_term_list( $subject );
		swiftling_note_term_fields_checked( substr( $subject['key'], 6 ), array_keys( $result['fields'] ) );
		return array( 'result' => $result );
	}

	$result = swiftling_save_probe( $subject, $registry, swiftling_clean_landings( $found ), swiftling_clean_control( $control ), (bool) $scoped );
	swiftling_note_official_translations( $result['units'], $result['maps'] );
	if ( 'post' === $subject['kind'] ) {
		// A new text in the post can make it untranslated again.
		swiftling_refresh_translated( array( (int) substr( $subject['key'], 5 ) ) );
	}
	return array( 'result' => $result );
}

/**
 * Cleans the landings the browser reported.
 *
 * @param mixed $found Leaf number => list of landings.
 * @return array
 */
function swiftling_clean_landings( $found ) {
	$clean = array();
	foreach ( (array) $found as $id => $landings ) {
		foreach ( (array) $landings as $landing ) {
			if ( ! is_array( $landing ) ) {
				continue;
			}
			$clean[ (int) $id ][] = array(
				'where'   => isset( $landing['where'] ) ? sanitize_key( $landing['where'] ) : '',
				'kind'    => isset( $landing['kind'] ) ? sanitize_key( $landing['kind'] ) : 'text',
				'region'  => isset( $landing['region'] ) ? sanitize_key( $landing['region'] ) : '',
				'order'   => isset( $landing['order'] ) ? (int) $landing['order'] : 0,
				'el'      => isset( $landing['el'] ) ? (int) $landing['el'] : -1,
				'link'    => isset( $landing['link'] ) ? (int) $landing['link'] : -1,
				'content' => ! empty( $landing['content'] ),
			);
		}
	}
	return $clean;
}

/**
 * Cleans the texts of the page rendered without markers.
 *
 * @param mixed $control List of array( t, k, r ).
 * @return array
 */
function swiftling_clean_control( $control ) {
	$clean = array();
	foreach ( array_slice( (array) $control, 0, 3000 ) as $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['t'] ) || ! is_string( $entry['t'] ) ) {
			continue;
		}
		$clean[] = array(
			't' => wp_html_excerpt( trim( wp_strip_all_tags( wp_check_invalid_utf8( $entry['t'] ) ) ), 2000 ),
			'k' => isset( $entry['k'] ) ? sanitize_key( $entry['k'] ) : 'text',
			'r' => isset( $entry['r'] ) ? sanitize_key( $entry['r'] ) : '',
			'c' => ! empty( $entry['c'] ),
		);
	}
	return $clean;
}

/**
 * Saves translations typed in the editor, see swiftling_save_texts(): the editor sends the
 * original of each text with its translation, for the check of placeholders.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function swiftling_rest_translations( $request ) {
	$saved = swiftling_save_texts( (string) $request['subject'], (string) $request['lang'], (array) $request['items'], (string) $request['status'] );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	unset( $saved['ignored'] );
	return $saved;
}

/**
 * Registers the route that saves the address of a post in a language.
 */
function swiftling_register_slug_route() {
	register_rest_route(
		'swiftling/v1',
		'/slug',
		array(
			'methods'             => 'POST',
			'callback'            => 'swiftling_rest_slug',
			'permission_callback' => 'swiftling_rest_can_edit',
			'args'                => array(
				'subject' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'lang'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static function ( $lang ) {
						return null !== swiftling_target_language( sanitize_key( $lang ) );
					},
				),
				'slug'    => array(
					'type'     => 'string',
					'required' => true,
				),
				// The term whose slug it is, in the editor of a list of terms.
				'term'    => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'swiftling_register_slug_route' );

/**
 * Saves the slug of a post in a language, typed in the editor, and returns the address it gives,
 * see swiftling_save_address(). In the editor of a list of terms, the slug of one of its terms.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function swiftling_rest_slug( $request ) {
	$subject = swiftling_subject( (string) $request['subject'] );
	if ( 'terms' === $subject['kind'] ) {
		$term = get_term( (int) $request['term'] );
		if ( ! $term instanceof WP_Term || 'terms:' . $term->taxonomy !== $subject['key'] ) {
			return new WP_Error( 'swiftling_slug_subject', __( 'This has no address of its own.', 'swiftling' ), array( 'status' => 400 ) );
		}
		return swiftling_save_address( 'term:' . $term->term_id, (string) $request['lang'], (string) $request['slug'] );
	}
	return swiftling_save_address( $subject['key'], (string) $request['lang'], (string) $request['slug'] );
}

/**
 * Translates texts of the editor with the service that translates the subject into the language,
 * see swiftling_machine_translate(). It only translates: the editor puts the translations into
 * their fields and saves them as machine translations with the translations route, which checks
 * them as it checks typed ones. One request holds at most SWIFTLING_MACHINE_MAX_TEXTS texts.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function swiftling_rest_machine( $request ) {
	$result = swiftling_machine_translate( (string) $request['subject'], (string) $request['lang'], (array) $request['items'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return array(
		'translations' => (object) $result['translations'],
		'failed'       => $result['failed'],
		// The words of the list each text kept as written, for the note under its field.
		'kept'         => (object) $result['kept'],
		'error'        => $result['error'],
	);
}
