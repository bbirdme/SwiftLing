<?php
/**
 * Translating without the editor's screen, since hooks 15: what SwiftLing Pro's REST API, its WP-CLI
 * commands and its abilities build on. Each function takes a step the translation editor or the
 * Translate everything run takes in the browser, on the server, as the current user, with the same
 * rights and checks, and the editor's own routes call the same functions:
 *
 * - swiftling_subject_found(): what the editor shows of a subject, its page checked on the server
 *   first, see swiftling_server_check();
 * - swiftling_subject_texts(): its texts, with their translations into a language and their states,
 *   each under the key the other functions take;
 * - swiftling_save_texts(): saves translations by those keys, as the editor saves what is typed;
 * - swiftling_save_address(): saves the slug of a post or a term in a language;
 * - swiftling_machine_translate(): translates texts with the translation service;
 * - swiftling_machine_translate_subject(): fills the empty texts of a subject in a language with the
 *   service, or translates again those in some states, as the Translate everything run does for a
 *   page;
 * - swiftling_import_languages(): sets the site's languages from another plugin's, for an import.
 *
 * The originals are never stored (see includes/storage.php), so listing the texts of a page checks
 * the page first, which takes a second or two. Subjects are those of swiftling_subject(): posts,
 * templates, kinds of pages, lists of terms, menus and the subjects of builders. Comments and the
 * texts of a theme or plugin are lists their own screens show by pages of hundreds: their texts
 * are saved and translated by key here as the editor does, but not listed or run, and comments are
 * never part of a run, see includes/translate-all.php.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Texts and characters one request to the translation service may hold at most, see
 * swiftling_machine_translate(): the editor's machine route, which sends a page in several requests,
 * takes no more, so each stays short and shows progress.
 */
define( 'SWIFTLING_MACHINE_MAX_TEXTS', 100 );
define( 'SWIFTLING_MACHINE_MAX_CHARACTERS', 20000 );

/**
 * Texts and characters a run sends to the service at once, see swiftling_machine_batches(): well
 * within the limits above, so a batch answers quickly and what it translated is saved before the
 * next. assets/js/machine.js cuts the same batches in the browser; tests/ApiTest.php holds them
 * equal.
 */
define( 'SWIFTLING_MACHINE_BATCH_TEXTS', 50 );
define( 'SWIFTLING_MACHINE_BATCH_CHARACTERS', 4000 );

/**
 * Returns a subject the current user may translate, or why not.
 *
 * @param string $key Subject key, see swiftling_subject().
 * @return array|WP_Error The subject.
 */
function swiftling_api_subject( $key ) {
	\SwiftLing\Plugin::loadEditor();
	$subject = swiftling_subject( (string) $key );
	if ( ! $subject ) {
		return new WP_Error( 'swiftling_subject', __( 'There is nothing to translate by this name.', 'swiftling' ), array( 'status' => 404 ) );
	}
	if ( ! swiftling_can_translate_subject( $subject ) ) {
		return new WP_Error( 'swiftling_subject_rights', __( 'You may not translate this.', 'swiftling' ), array( 'status' => rest_authorization_required_code() ) );
	}
	return $subject;
}

/**
 * Returns a target language by its address slug, or why not.
 *
 * @param string $lang Address slug.
 * @return array|WP_Error The language, as swiftling_languages() gives it.
 */
function swiftling_api_language( $lang ) {
	$language = swiftling_target_language( sanitize_key( (string) $lang ) );
	if ( ! $language ) {
		return new WP_Error( 'swiftling_language', __( 'The site is not translated into this language.', 'swiftling' ), array( 'status' => 400 ) );
	}
	return $language;
}

/**
 * Stores and returns what the translation editor shows of a subject now: for a page or template,
 * what a check of its page on the server finds; for a list of terms, a menu or a builder's subject,
 * its texts as listed, the page of the list's most used term checked first where the editor checks
 * it too, see swiftling_term_list_needs_check(). Comments and the texts of a theme or plugin are
 * lists of their own screens: not here, but a page of a list of comments, as the comments editor
 * lists it (all comments, the newest first), so their translations can be saved by key.
 *
 * @param array $subject Subject, see swiftling_subject(), which the current user may translate.
 * @param array $args    For a list of comments: page, and lang, the address slug of the language
 *                       its counts are for (the first target language by default).
 * @return array|WP_Error Like swiftling_save_probe(): the texts by section and the maps of their objects;
 *                        for a list of comments with list too, see swiftling_comment_list().
 */
function swiftling_subject_found( $subject, $args = array() ) {
	\SwiftLing\Plugin::loadEditor();
	// A page of a list of comments, as the comments editor lists it.
	if ( 'comments' === $subject['kind'] && isset( $args['page'] ) ) {
		$lang = isset( $args['lang'] ) ? (string) $args['lang'] : (string) array_key_first( swiftling_languages()['targets'] );
		return swiftling_save_comment_list(
			$subject,
			$lang,
			array(
				'show' => 'all',
				'page' => max( 1, (int) $args['page'] ),
			)
		);
	}
	if ( in_array( $subject['kind'], array( 'package', 'comments' ), true ) ) {
		return new WP_Error( 'swiftling_subject_list', __( 'This list is translated on its own screen.', 'swiftling' ), array( 'status' => 400 ) );
	}
	if ( 'menu' === $subject['kind'] ) {
		return swiftling_save_menu_list( $subject );
	}
	if ( 'fields' === $subject['kind'] ) {
		return swiftling_save_field_list( $subject );
	}
	if ( 'terms' === $subject['kind'] ) {
		$list = swiftling_save_term_list( $subject );
		if ( swiftling_term_list_needs_check( $subject, $list['fields'] ) ) {
			$checked = swiftling_server_check( $subject['key'] );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$list = $checked['result'];
		}
		return $list;
	}
	$checked = swiftling_server_check( $subject['key'] );
	return is_wp_error( $checked ) ? $checked : $checked['result'];
}

/**
 * Returns the texts of a subject the current user may translate, with their translations into a
 * language, as the translation editor lists them: its page checked on the server first, see
 * swiftling_subject_found(). Each text is listed once, under the key swiftling_save_texts() takes:
 * the key of its place, which names the version of the original too, so a translation saved after
 * the original changed lands nowhere.
 *
 * @param string $key  Subject key, see swiftling_subject().
 * @param string $lang Address slug of a target language.
 * @return array{subject: array{key: string, kind: string, title: string, url: string}, texts: array<int, array>}|WP_Error
 *         Each text: key; object and path, the object it belongs to and where it is in it, such as
 *         post:12 and field/post_title; section of the editor (page, site, fields, search); kind,
 *         as the editor names it (heading, paragraph, link, image…); type: text, html, code, url
 *         for an address, or format for a text from code, whose placeholders the translation
 *         keeps; original; translation; state: reviewed, machine, official, outdated or missing;
 *         previous, the translation of the original before it changed, while outdated; follows,
 *         whether an empty translation shows another text, as a menu's label shows its page's
 *         translated title.
 */
function swiftling_subject_texts( $key, $lang ) {
	$subject = swiftling_api_subject( $key );
	if ( is_wp_error( $subject ) ) {
		return $subject;
	}
	$language = swiftling_api_language( $lang );
	if ( is_wp_error( $language ) ) {
		return $language;
	}
	$found = swiftling_subject_found( $subject );
	if ( is_wp_error( $found ) ) {
		return $found;
	}

	$allowed = swiftling_translatable_keys( $subject['key'] );
	$entries = array();
	$lookup  = array();
	foreach ( $found['sections'] as $section => $units ) {
		foreach ( $units as $unit ) {
			// A text's link is a text of its own, an address.
			$link = isset( $unit['link'] ) && is_array( $unit['link'] ) ? $unit['link'] + array( 'kind' => 'link' ) : null;
			foreach ( $link ? array( $unit, $link ) : array( $unit ) as $entry ) {
				$map                      = isset( $found['maps'][ $entry['o'] ] ) ? $found['maps'][ $entry['o'] ]['units'] : array();
				list( $place, $previous ) = swiftling_live_places( $entry, $map );
				if ( ! isset( $allowed[ $place ] ) || isset( $entries[ $place ] ) ) {
					continue;
				}
				$entries[ $place ] = array( $section, $entry, $previous );
				array_push( $lookup, ...array_filter( array( $place, $previous ) ) );
			}
		}
	}
	$translations = $lookup ? swiftling_get_translations( $language['locale'], $lookup ) : array();

	$texts = array();
	foreach ( $entries as $place => $entry ) {
		list( $section, $unit, $previous ) = $entry;

		$kind  = isset( $unit['kind'] ) ? (string) $unit['kind'] : 'text';
		$type  = $allowed[ $place ];
		$value = (string) $unit['v'];
		if ( 'text' === $type && swiftling_live_is_code( $unit ) ) {
			$type = 'code';
		} elseif ( 'text' === $type && 1 === preg_match( '~<[a-z/!][^>]*>~i', $value ) ) {
			$type = 'html';
		}
		$own     = isset( $translations[ $place ] ) ? $translations[ $place ] : null;
		$before  = null === $own && '' !== $previous && isset( $translations[ $previous ] ) ? $translations[ $previous ]['text'] : '';
		$texts[] = array(
			'key'         => $place,
			'object'      => (string) $unit['o'],
			'path'        => (string) $unit['p'],
			'section'     => (string) $section,
			'kind'        => $kind,
			'type'        => $type,
			'original'    => $value,
			'translation' => null === $own ? '' : (string) $own['text'],
			'state'       => null !== $own ? swiftling_status_state( $own['status'] ) : ( '' !== $before ? 'outdated' : 'missing' ),
			'previous'    => (string) $before,
			'follows'     => ! empty( $unit['follows'] ),
		);
	}

	return array(
		'subject' => array(
			'key'   => $subject['key'],
			'kind'  => $subject['kind'],
			'title' => (string) $subject['title'],
			'url'   => (string) $subject['url'],
		),
		'texts'   => $texts,
	);
}

/**
 * Saves translations of a subject's texts into a language, as the translation editor saves what is
 * typed, each under the key of its place, see swiftling_subject_texts(). Only places of the subject
 * and of the objects seen on its page are taken, each only when the current user may translate the
 * object it belongs to; other keys are ignored. An empty text removes the translation.
 *
 * A translation of a text from code whose placeholders do not fit its original is refused, see
 * swiftling_format_problems(): it would stop the page in that language. The original comes with the
 * item for this; the translated view checks again anyway. A text the user may not write as HTML
 * (unfiltered_html) keeps only the HTML a post may hold; an address only what a link may hold.
 *
 * The first translation of a post's title gives the post its address in the language, and the
 * cached pages of what changed are purged.
 *
 * @param string $key   Subject key, see swiftling_subject().
 * @param string $lang  Address slug of a target language.
 * @param array  $items List of array( key, text, original? ).
 * @param string $state reviewed, or machine for machine translations.
 * @return array{states: array<string, string>, refused: string[], ignored: string[], languages: array<string, string>, address: array|null}|WP_Error
 *         The state of each saved text (reviewed, machine, missing, or the state of the text it
 *         follows); the keys refused for their placeholders; the keys not taken; the subject's state
 *         in every language; a post's address in the language.
 */
function swiftling_save_texts( $key, $lang, $items, $state ) {
	$subject = swiftling_api_subject( $key );
	if ( is_wp_error( $subject ) ) {
		return $subject;
	}
	$language = swiftling_api_language( $lang );
	if ( is_wp_error( $language ) ) {
		return $language;
	}
	$lang    = sanitize_key( (string) $lang );
	$allowed = swiftling_translatable_keys( $subject['key'] );
	$status  = 'machine' === $state ? SWIFTLING_MACHINE : SWIFTLING_REVIEWED;
	$saved   = array();
	$refused = array();
	$ignored = array();

	foreach ( (array) $items as $item ) {
		if ( ! is_array( $item ) || ! isset( $item['key'], $item['text'] ) || ! is_string( $item['key'] ) || ! is_string( $item['text'] ) ) {
			continue;
		}
		if ( ! isset( $allowed[ $item['key'] ] ) ) {
			$ignored[] = $item['key'];
			continue;
		}
		// Kept as typed: the line breaks around a block's HTML are part of it. Only blank means empty.
		$text = $item['text'];
		if ( '' !== trim( $text ) && 'format' === $allowed[ $item['key'] ] && isset( $item['original'] ) && is_string( $item['original'] ) && swiftling_format_problems( $item['original'], $text )['breaks'] ) {
			$refused[] = $item['key'];
			continue;
		}
		if ( '' === trim( $text ) ) {
			$text = '';
		} elseif ( 'url' === $allowed[ $item['key'] ] ) {
			// A link address in another language: only addresses a link may hold.
			$text = esc_url_raw( trim( $text ) );
		} elseif ( ! current_user_can( 'unfiltered_html' ) ) {
			$text = wp_kses_post( $text );
		}
		$saved[ $item['key'] ] = array(
			'text'   => $text,
			'status' => $status,
		);
	}

	// The address the post had in the language, should the title give it a slug now.
	$address = 'post' === $subject['kind'] ? swiftling_post_language_url( $subject['post'], $lang ) : '';
	swiftling_save_translations( $language['locale'], $saved );
	if ( 'post' === $subject['kind'] ) {
		swiftling_refresh_translated( array( (int) $subject['post']->ID ) );
		// The first translation of the title gives the post its address in the language.
		swiftling_slug_from_title( $subject['post'], $language['locale'], $saved );
		swiftling_purge_urls_later( array( $address ), array( $subject['post']->ID ) );
	}
	swiftling_purge_translations( $subject, $lang, array_keys( $saved ) );

	$states = array();
	foreach ( $saved as $place => $item ) {
		$states[ $place ] = '' === $item['text'] ? 'missing' : ( SWIFTLING_MACHINE === $status ? 'machine' : 'reviewed' );
	}
	// An emptied text that falls back to another, such as a menu's label of a page's title, shows
	// that one again, in its state.
	if ( 'menu' === $subject['kind'] && in_array( 'missing', $states, true ) ) {
		list( , $places, $translations ) = swiftling_object_places( array( $subject['key'] ) );
		foreach ( isset( $places[ $subject['key'] ] ) ? $places[ $subject['key'] ] : array() as $place ) {
			if ( isset( $place[2], $states[ $place[0] ], $translations[ $lang ][ $place[2] ] ) && 'missing' === $states[ $place[0] ] ) {
				$states[ $place[0] ] = swiftling_status_state( $translations[ $lang ][ $place[2] ]['status'] );
			}
		}
	}

	return array(
		'states'    => $states,
		'refused'   => $refused,
		'ignored'   => $ignored,
		'languages' => swiftling_subject_states( $subject['key'] ),
		'address'   => 'post' === $subject['kind'] ? swiftling_post_address( $subject['post'], $lang ) : null,
	);
}

/**
 * Saves translations of a subject's texts that were made for an earlier version of their originals,
 * as another plugin kept them, for an import: each text is outdated in the language then, as when
 * its original changed after it was translated here. Visitors read the original until it is
 * translated again, the translation editor shows the earlier translation, Translate everything
 * leaves the text to the editor, and the page counts as translated once its other texts are, see
 * swiftling_units_translated().
 *
 * The earlier version becomes the text's previous one, as a page check records it when an original
 * changes (see swiftling_save_unit_maps()); a text has one previous version for all languages. A
 * text translated in the language now, one whose earlier version is the text now, one another
 * language waits with in another earlier version, and a place of no text of the subject's own are
 * left alone. The subject's page must have been checked, see swiftling_subject_texts().
 *
 * @param string $key   Subject key, see swiftling_subject().
 * @param string $lang  Address slug of a target language.
 * @param array  $items List of array( key, text, earlier ): the key of the text's place now, the
 *                      earlier translation, and the original it translated.
 * @return array{saved: string[], ignored: string[]}|WP_Error The keys saved and those left alone.
 */
function swiftling_save_earlier_texts( $key, $lang, $items ) {
	$subject = swiftling_api_subject( $key );
	if ( is_wp_error( $subject ) ) {
		return $subject;
	}
	$language = swiftling_api_language( $lang );
	if ( is_wp_error( $language ) ) {
		return $language;
	}
	$lang  = sanitize_key( (string) $lang );
	$owner = $subject['key'];
	$maps  = swiftling_get_maps( array( $owner ) );
	$map   = isset( $maps[ $owner ] ) ? $maps[ $owner ] : null;

	// The subject's own texts by the key of their place now: not shared ones, not addresses.
	$units = array();
	foreach ( $map ? swiftling_map_places( $owner, $map ) : array() as $unit_key => $place ) {
		if ( ! swiftling_is_shared_key( (string) $unit_key ) && ! swiftling_is_link_key( (string) $unit_key ) ) {
			$units[ $place[0] ] = (string) $unit_key;
		}
	}
	$now     = $units ? swiftling_get_translations( $language['locale'], array_keys( $units ) ) : array();
	$entries = $map ? $map['units'] : array();
	$saved   = array();
	$keys    = array();
	$ignored = array();

	foreach ( (array) $items as $item ) {
		if ( ! is_array( $item ) || ! isset( $item['key'], $item['text'], $item['earlier'] ) || ! is_string( $item['key'] ) || ! is_string( $item['text'] ) || ! is_string( $item['earlier'] ) ) {
			continue;
		}
		$unit_key = isset( $units[ $item['key'] ] ) ? $units[ $item['key'] ] : '';
		$entry    = '' !== $unit_key ? $entries[ $unit_key ] : '';
		$hash     = is_array( $entry ) ? $entry[0] : (string) $entry;
		$waiting  = is_array( $entry ) ? $entry[1] : '';
		$earlier  = swiftling_hash( $item['earlier'] );
		$text     = '' === trim( $item['text'] ) ? '' : ( current_user_can( 'unfiltered_html' ) ? $item['text'] : wp_kses_post( $item['text'] ) );
		if ( '' === $unit_key || '' === $text || $earlier === $hash || isset( $now[ $item['key'] ] ) || ( '' !== $waiting && $waiting !== $earlier ) ) {
			$ignored[] = $item['key'];
			continue;
		}
		$entries[ $unit_key ] = array( $hash, $earlier );

		$saved[ swiftling_place_key( $owner, $unit_key, $earlier ) ] = array(
			'text'   => $text,
			'status' => SWIFTLING_REVIEWED,
		);
		$keys[] = $item['key'];
	}

	if ( $saved ) {
		swiftling_save_map( $owner, $entries, $map['sources'], $map['refs'], $map['changed'] );
		swiftling_save_translations( $language['locale'], $saved );
		if ( 'post' === $subject['kind'] ) {
			swiftling_refresh_translated( array( (int) $subject['post']->ID ) );
		}
		swiftling_purge_translations( $subject, $lang, $keys );
	}

	return array(
		'saved'   => $keys,
		'ignored' => $ignored,
	);
}

/**
 * Saves the address of a post or a term in a language, as the translation editor saves a typed
 * slug: an empty slug gives it its original slug in the language again, and a slug another post or
 * term has there gets a number, see swiftling_save_slug(). The cached pages of both addresses, the
 * one before, which leads to the new one now, and the new one, are purged.
 *
 * @param string $owner Object key: post:12, or term:5 of a list of terms with pages of their own.
 * @param string $lang  Address slug of a target language.
 * @param string $slug  Slug in the language.
 * @return array|WP_Error The address it gives, see swiftling_post_address() and swiftling_term_address().
 */
function swiftling_save_address( $owner, $lang, $slug ) {
	$owner    = (string) $owner;
	$language = swiftling_api_language( $lang );
	if ( is_wp_error( $language ) ) {
		return $language;
	}
	$lang = sanitize_key( (string) $lang );

	if ( 0 === strpos( $owner, 'term:' ) ) {
		$term    = get_term( (int) substr( $owner, 5 ) );
		$subject = $term instanceof WP_Term ? swiftling_api_subject( 'terms:' . $term->taxonomy ) : null;
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		if ( ! $subject || '' === $subject['url'] ) {
			return new WP_Error( 'swiftling_slug_subject', __( 'This has no address of its own.', 'swiftling' ), array( 'status' => 400 ) );
		}
		$address = swiftling_term_language_url( $term, $lang );
		swiftling_save_slug( 'term:' . $term->term_id, $language['locale'], (string) $slug );
		swiftling_purge_urls_later( array( $address, swiftling_term_language_url( $term, $lang ) ) );
		return swiftling_term_address( $term, $lang );
	}

	$subject = swiftling_api_subject( $owner );
	if ( is_wp_error( $subject ) ) {
		return $subject;
	}
	if ( 'post' !== $subject['kind'] || '' === $subject['post']->post_name ) {
		return new WP_Error( 'swiftling_slug_subject', __( 'This has no address of its own.', 'swiftling' ), array( 'status' => 400 ) );
	}
	$address = swiftling_post_language_url( $subject['post'], $lang );
	swiftling_save_slug( 'post:' . $subject['post']->ID, $language['locale'], (string) $slug );
	swiftling_purge_urls_later( array( $address, swiftling_post_language_url( $subject['post'], $lang ) ), array( $subject['post']->ID ) );
	return swiftling_post_address( $subject['post'], $lang );
}

/**
 * Translates texts of a subject with the service that translates it into the language, see
 * SwiftLing\Translation\Services::forTarget(). It only translates: what comes back is saved with
 * swiftling_save_texts(), which checks it as it checks typed translations.
 *
 * Only texts of the places the current user may translate on this subject are sent, and links and
 * other addresses never are. The placeholders of a text from code, the variables of SEO plugins
 * (see swiftling_seo_variables()) and the words of the list on the Translation service screen stay
 * as written: a translation that changed a placeholder or a variable is not filled in. What was sent is added to this month's count, with
 * the tokens a language model read and wrote; what failed is logged.
 *
 * @param string $key   Subject key, see swiftling_subject().
 * @param string $lang  Address slug of a target language.
 * @param array  $items List of array( key, text, html ): html when the text is HTML.
 * @return array{translations: array<string, string>, failed: string[], kept: array<string, string[]>, error: string}|WP_Error
 *         Translations by key; the keys the service did not translate, or translated with
 *         placeholders that do not fit; the words of the list each text kept as written; what the
 *         service said when it stopped part of the way.
 */
function swiftling_machine_translate( $key, $lang, $items ) {
	$subject = swiftling_api_subject( $key );
	if ( is_wp_error( $subject ) ) {
		return $subject;
	}
	$language = swiftling_api_language( $lang );
	if ( is_wp_error( $language ) ) {
		return $language;
	}
	if ( ! swiftling_can_machine_translate() ) {
		return new WP_Error( 'swiftling_machine_rights', __( 'You may not use machine translation.', 'swiftling' ), array( 'status' => rest_authorization_required_code() ) );
	}
	$service = \SwiftLing\Translation\Services::forTarget( $subject['key'], $language );
	if ( ! $service ) {
		return new WP_Error( 'swiftling_machine_service', __( 'No translation service is connected. Add a key on the Translation service screen.', 'swiftling' ), array( 'status' => 409 ) );
	}

	$source = $service->languageCode( swiftling_languages()['source'] );
	$target = $service->languageCode( $language );
	if ( null === $source || null === $target ) {
		$missing = null === $source ? swiftling_languages()['source'] : $language;
		/* translators: 1: service name, e.g. Google, 2: language name, e.g. Montenegrin. */
		return new WP_Error( 'swiftling_machine_language', sprintf( __( '%1$s does not translate %2$s.', 'swiftling' ), $service->name(), $missing['name'] ), array( 'status' => 409 ) );
	}

	$allowed   = swiftling_translatable_keys( $subject['key'] );
	$sent      = array();
	$kept      = array();
	$variables = array();
	$length    = 0;
	foreach ( (array) $items as $item ) {
		if ( ! is_array( $item ) || ! isset( $item['key'], $item['text'] ) || ! is_string( $item['key'] ) || ! is_string( $item['text'] ) ) {
			continue;
		}
		// Addresses keep their original unless someone types another one.
		if ( ! isset( $allowed[ $item['key'] ] ) || 'url' === $allowed[ $item['key'] ] || '' === trim( $item['text'] ) ) {
			continue;
		}
		$sent[ $item['key'] ] = array(
			'text' => wp_check_invalid_utf8( $item['text'] ),
			'html' => ! empty( $item['html'] ),
		);
		// The placeholders of a text from code go untranslated, see Service::translate(), and so do
		// the variables of SEO plugins and the words of the list on the Translation service screen.
		$placeholders = 'format' === $allowed[ $item['key'] ] ? swiftling_format_placeholders( $item['text'] ) : array();
		$found        = swiftling_seo_variables( $item['text'] );
		$words        = \SwiftLing\Translation\Services::wordsIn( $item['text'], ! empty( $item['html'] ) );
		if ( $placeholders || $found || $words ) {
			$sent[ $item['key'] ]['keep'] = array_values( array_unique( array_merge( wp_list_pluck( $placeholders, 'raw' ), $found, $words ) ) );
		}
		if ( $found ) {
			$variables[ $item['key'] ] = $found;
		}
		if ( $placeholders ) {
			$sent[ $item['key'] ]['format'] = true;
		}
		if ( $words ) {
			$kept[ $item['key'] ] = $words;
		}
		$length += mb_strlen( $item['text'] );
	}
	if ( ! $sent ) {
		return array(
			'translations' => array(),
			'failed'       => array(),
			'kept'         => array(),
			'error'        => '',
		);
	}
	if ( count( $sent ) > SWIFTLING_MACHINE_MAX_TEXTS || $length > SWIFTLING_MACHINE_MAX_CHARACTERS ) {
		return new WP_Error( 'swiftling_machine_size', __( 'Too many texts at once. Reload the editor and try again.', 'swiftling' ), array( 'status' => 413 ) );
	}

	// Another plugin may add to what is sent, such as the words a glossary sets: only to the texts
	// of the page, never changing them or taking away a part SwiftLing keeps.
	$added = apply_filters( 'swiftling_machine_items', $sent, $service, $language );
	foreach ( $sent as $item_key => $item ) {
		$more = is_array( $added ) && isset( $added[ $item_key ] ) && is_array( $added[ $item_key ] ) ? $added[ $item_key ] : array();
		$keep = array_merge( $item['keep'] ?? array(), isset( $more['keep'] ) && is_array( $more['keep'] ) ? array_filter( $more['keep'], 'is_string' ) : array() );
		unset( $more['keep'] );
		$sent[ $item_key ] = array_merge( $more, $item );
		if ( $keep ) {
			$sent[ $item_key ]['keep'] = array_values( array_unique( $keep ) );
		}
	}

	$service->setLanguages( swiftling_languages()['source'], $language );
	$result = $service->translate( $sent, $source, $target );
	if ( is_wp_error( $result ) ) {
		swiftling_log(
			'error',
			'service',
			/* translators: 1: translation service name, e.g. Google, 2: what it said, 3: number of texts. */
			sprintf( _n( '%1$s did not translate: %2$s %3$s text stays empty.', '%1$s did not translate: %2$s %3$s texts stay empty.', count( $sent ), 'swiftling' ), $service->name(), $result->get_error_message(), number_format_i18n( count( $sent ) ) ),
			array(
				'detail' => $service->id() . ' · ' . $result->get_error_code() . ' · ' . $language['name'],
				'object' => $subject['key'],
			)
		);
		return $result;
	}
	\SwiftLing\Translation\Services::addUsage( $service->id(), $result['characters'], isset( $result['tokens'] ) ? (int) $result['tokens'] : 0 );
	if ( '' !== (string) $result['error'] ) {
		swiftling_log(
			'error',
			'service',
			/* translators: 1: translation service name, e.g. Google, 2: what it said. */
			sprintf( __( '%1$s stopped translating: %2$s', 'swiftling' ), $service->name(), $result['error'] ),
			array(
				'detail' => $service->id() . ' · ' . $language['name'],
				'object' => $subject['key'],
			)
		);
	}

	// Another plugin may change what came back, such as putting in the words a glossary sets: only
	// the translations there are, each still a text.
	$changed = apply_filters( 'swiftling_machine_translations', $result['translations'], $sent, $service, $language );
	foreach ( array_keys( $result['translations'] ) as $item_key ) {
		if ( is_array( $changed ) && isset( $changed[ $item_key ] ) && is_string( $changed[ $item_key ] ) ) {
			$result['translations'][ $item_key ] = $changed[ $item_key ];
		}
	}

	// A translation that came back with placeholders that do not fit is not filled in: it would stop
	// the page in that language. It counts as not translated.
	$broken = 0;
	foreach ( $result['translations'] as $item_key => $text ) {
		if ( ! empty( $sent[ $item_key ]['format'] ) && swiftling_format_problems( $sent[ $item_key ]['text'], $text )['breaks'] ) {
			unset( $result['translations'][ $item_key ] );
			$result['failed'][] = (string) $item_key;
			++$broken;
		}
	}
	if ( $broken ) {
		swiftling_log(
			'warning',
			'service',
			/* translators: 1: number of texts, 2: translation service name, e.g. Google. */
			sprintf( _n( '%1$s machine translation by %2$s was not used: its placeholders, such as %%s, would stop the page. The text stays empty.', '%1$s machine translations by %2$s were not used: their placeholders, such as %%s, would stop the page. The texts stay empty.', $broken, 'swiftling' ), number_format_i18n( $broken ), $service->name() ),
			array(
				'detail' => $language['name'],
				'object' => $subject['key'],
			)
		);
	}

	// Nor one that came back with a variable of an SEO plugin changed or missing: the plugin would
	// print it as written, see swiftling_seo_variables().
	$lost_variables = array();
	foreach ( $result['translations'] as $item_key => $text ) {
		$lost = isset( $variables[ $item_key ] ) ? array_diff( $variables[ $item_key ], swiftling_seo_variables( $text ) ) : array();
		if ( $lost ) {
			unset( $result['translations'][ $item_key ] );
			$result['failed'][] = (string) $item_key;
			$lost_variables[]   = reset( $lost );
		}
	}
	if ( $lost_variables ) {
		swiftling_log(
			'warning',
			'service',
			/* translators: 1: number of texts, 2: translation service name, e.g. Google, 3: a variable of an SEO plugin, e.g. %%title%%. */
			sprintf( _n( '%1$s machine translation by %2$s was not used: it changed a variable of an SEO plugin, such as %3$s, which the page would show as written. The text stays empty.', '%1$s machine translations by %2$s were not used: they changed variables of an SEO plugin, such as %3$s, which the page would show as written. The texts stay empty.', count( $lost_variables ), 'swiftling' ), number_format_i18n( count( $lost_variables ) ), $service->name(), $lost_variables[0] ),
			array(
				'detail' => $language['name'],
				'object' => $subject['key'],
			)
		);
	}

	return array(
		'translations' => $result['translations'],
		'failed'       => $result['failed'],
		'kept'         => array_intersect_key( $kept, $result['translations'] ),
		'error'        => (string) $result['error'],
	);
}

/**
 * Fills the empty texts of a subject in a language with the translation service, as the Translate
 * everything run fills a page and the editor its empty fields: its page checked on the server first,
 * the texts listed as swiftling_machine_items() lists them, sent in batches (see
 * swiftling_machine_batches()) and each batch saved at once as machine translations, so what the
 * service translated, and charged for, is kept if a later batch fails. Asked for states to translate
 * again, it translates the texts translated in those states instead.
 *
 * A language whose page of a post is a published separate copy or a duplicate shows a page of its
 * own there, so nothing is filled in.
 *
 * @param string   $key   Subject key, see swiftling_subject().
 * @param string   $lang  Address slug of a target language.
 * @param string[] $again States whose translations are translated again: machine, reviewed. Empty
 *                        to fill the empty texts.
 * @return array{texts: int, translated: int, characters: int, failed: string[], refused: string[], error: string, own_page: bool}|WP_Error
 *         How many texts there were to translate, how many were saved and how many characters of
 *         the originals they had; the keys the service did not translate and those refused for their
 *         placeholders; what the service said when it stopped; whether the language shows a page of
 *         its own. An error before anything was saved.
 */
function swiftling_machine_translate_subject( $key, $lang, $again = array() ) {
	$subject = swiftling_api_subject( $key );
	if ( is_wp_error( $subject ) ) {
		return $subject;
	}
	$language = swiftling_api_language( $lang );
	if ( is_wp_error( $language ) ) {
		return $language;
	}
	if ( ! swiftling_can_machine_translate() ) {
		return new WP_Error( 'swiftling_machine_rights', __( 'You may not use machine translation.', 'swiftling' ), array( 'status' => rest_authorization_required_code() ) );
	}
	$lang = sanitize_key( (string) $lang );
	$done = array(
		'texts'      => 0,
		'translated' => 0,
		'characters' => 0,
		'failed'     => array(),
		'refused'    => array(),
		'error'      => '',
		'own_page'   => false,
	);
	if ( 'post' === $subject['kind'] && swiftling_shows_own_page( (int) $subject['post']->ID, $language['locale'] ) ) {
		$done['own_page'] = true;
		return $done;
	}

	$found = swiftling_subject_found( $subject );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	$again         = array_values( array_intersect( array( 'machine', 'reviewed' ), (array) $again ) );
	$items         = swiftling_machine_items( $subject, $lang, $found, $again );
	$done['texts'] = count( $items );

	foreach ( swiftling_machine_batches( $items ) as $batch ) {
		$result = swiftling_machine_translate(
			$subject['key'],
			$lang,
			array_map(
				static function ( $item ) {
					return array(
						'key'  => $item['key'],
						'text' => $item['text'],
						'html' => $item['html'],
					);
				},
				$batch
			)
		);
		if ( is_wp_error( $result ) ) {
			if ( ! $done['translated'] ) {
				return $result;
			}
			$done['error'] = $result->get_error_message();
			break;
		}

		$save       = array();
		$characters = 0;
		foreach ( $batch as $item ) {
			if ( ! isset( $result['translations'][ $item['key'] ] ) || ! is_string( $result['translations'][ $item['key'] ] ) ) {
				continue;
			}
			$text = swiftling_machine_wrapped( $item, $result['translations'][ $item['key'] ] );
			if ( '' === $text ) {
				continue;
			}
			$save[]      = array(
				'key'      => $item['key'],
				'text'     => $text,
				'original' => $item['text'],
			);
			$characters += mb_strlen( $item['text'] );
		}
		$done['failed'] = array_merge( $done['failed'], $result['failed'] );
		if ( $save ) {
			$saved = swiftling_save_texts( $subject['key'], $lang, $save, 'machine' );
			if ( is_wp_error( $saved ) ) {
				$done['error'] = $saved->get_error_message();
				break;
			}
			$done['refused']     = array_merge( $done['refused'], $saved['refused'] );
			$done['translated'] += count( $saved['states'] );
			$done['characters'] += $characters;
		}
		if ( '' !== $result['error'] ) {
			// The service stopped, e.g. because the key was refused: what is saved stays saved.
			$done['error'] = $result['error'];
			break;
		}
	}
	return $done;
}

/**
 * Cuts the texts of a run into batches of at most SWIFTLING_MACHINE_BATCH_TEXTS texts and
 * SWIFTLING_MACHINE_BATCH_CHARACTERS characters, as assets/js/machine.js does in the browser; a
 * longer text is a batch of its own.
 *
 * @param array $items Texts, each with its text.
 * @return array[]
 */
function swiftling_machine_batches( $items ) {
	$batches = array();
	$batch   = array();
	$length  = 0;
	foreach ( $items as $item ) {
		$characters = mb_strlen( (string) $item['text'] );
		if ( $batch && ( count( $batch ) >= SWIFTLING_MACHINE_BATCH_TEXTS || $length + $characters > SWIFTLING_MACHINE_BATCH_CHARACTERS ) ) {
			$batches[] = $batch;
			$batch     = array();
			$length    = 0;
		}
		$batch[] = $item;
		$length += $characters;
	}
	if ( $batch ) {
		$batches[] = $batch;
	}
	return $batches;
}

/**
 * Puts the tags of a text back around its translation, as the translation editor does: plain text
 * inside a heading or a paragraph is escaped first, formatted text keeps its HTML. A blank
 * translation is none.
 *
 * @param array  $item Text as swiftling_translate_all_item() gives it: prefix, suffix, escape.
 * @param string $text Its translation.
 * @return string
 */
function swiftling_machine_wrapped( $item, $text ) {
	if ( '' === trim( $text ) ) {
		return '';
	}
	if ( ! empty( $item['escape'] ) ) {
		$text = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
	return $item['prefix'] . $text . $item['suffix'];
}

/**
 * Sets the site's languages from another plugin's, as SwiftLing Pro's import from Polylang, WPML or
 * TranslatePress does, so the site keeps its addresses. Since hooks 19.
 *
 * - The original: set as the first setup sets it while SwiftLing is not set up; it must be the same
 *   when it is, as changing it means starting over.
 * - Targets are added by locale, with their address slug and whether visitors see them; a locale
 *   WordPress does not list becomes a custom language with the name given. A target the site has
 *   already stays as it is. A slug another language has gets the locale, as on the Languages screen.
 * - A name given for a locale WordPress names otherwise, as "English" for en_GB, which WordPress
 *   calls "English (UK)", becomes the name the switcher shows (its label), so the switcher reads as
 *   it did. Since hooks 21.
 * - The address format: parameter or directory, with whether the original has a folder too. A
 *   domain or subdomain format keeps the domains given, but the site keeps its addresses: SwiftLing
 *   switches to hosts only once every one leads to the site, on the Addresses screen.
 *
 * WordPress's own translations of the new languages are installed, and what counts as translated
 * is worked out again. The change is logged, as any change of the languages.
 *
 * @param array $source  locale, slug (the original's folder), name: the language's name, or the switcher's.
 * @param array $targets List of locale, slug, visible, name (as for the original), rtl.
 * @param array $address format (parameter, directory, domain or subdomain), source_folder, domains (locale => host).
 * @return array{added: string[], kept: string[], slugs: array<string, string>, hosts: bool, packs: array}|WP_Error
 *         The locales added and those the site had, the slug of each, whether the address format
 *         waits for hosts, and WordPress's translations installed and missing.
 */
function swiftling_import_languages( $source, $targets, $address = array() ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'swiftling_import_rights', __( 'You may not change the languages of this site.', 'swiftling' ), array( 'status' => rest_authorization_required_code() ) );
	}
	$wordpress = swiftling_available_languages( false );
	$saved     = get_option( 'swiftling_languages', array() );
	$saved     = is_array( $saved ) ? $saved : array();
	$locale    = (string) ( $source['locale'] ?? '' );
	$entry     = static function ( $locale, $name, $rtl ) use ( $wordpress ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( isset( $wordpress[ $locale ] ) ) {
			// The name the other plugin showed, where WordPress names the language otherwise, is what
			// the switcher shows, as written on the Languages screen.
			return array(
				'locale' => $locale,
				'name'   => $wordpress[ $locale ]['name'],
				'label'  => '' !== $name && $name !== $wordpress[ $locale ]['name'] ? $name : '',
			);
		}
		return array(
			'locale' => $locale,
			'name'   => '' !== $name ? $name : $locale,
			'label'  => '',
			'custom' => array(
				'wp_locale' => 'en_US',
				'rtl'       => (bool) $rtl,
			),
		);
	};

	if ( swiftling_is_set_up() ) {
		if ( $saved['source']['locale'] !== $locale ) {
			/* translators: 1: SwiftLing's original language, e.g. English, 2: the other plugin's, e.g. Hrvatski. */
			return new WP_Error( 'swiftling_import_source', sprintf( __( 'SwiftLing is set up with %1$s as the original, and this site is written in %2$s. The original can be changed only by starting over.', 'swiftling' ), $saved['source']['name'], '' !== $locale ? $locale : '?' ), array( 'status' => 409 ) );
		}
	} else {
		if ( '' === swiftling_normalize_locale( $locale ) ) {
			return new WP_Error( 'swiftling_import_source', __( 'The original language is not known.', 'swiftling' ), array( 'status' => 400 ) );
		}
		$saved = array(
			'source'        => $entry( $locale, $source['name'] ?? '', $source['rtl'] ?? false ) + array(
				'slug' => sanitize_title( (string) ( $source['slug'] ?? strtok( $locale, '_' ) ) ),
			),
			'targets'       => array(),
			'order'         => array( $locale ),
			'names'         => 'native',
			'format'        => 'parameter',
			'source_folder' => false,
		);
	}
	$before = isset( $saved['targets'] ) && is_array( $saved['targets'] ) ? $saved['targets'] : array();

	$taken = array( (string) $saved['source']['slug'] => true );
	$have  = array();
	foreach ( $before as $target ) {
		$taken[ (string) $target['slug'] ]  = true;
		$have[ (string) $target['locale'] ] = (string) $target['slug'];
	}
	$added = array();
	$kept  = array();
	$slugs = $have;
	foreach ( $targets as $target ) {
		$target_locale = (string) ( $target['locale'] ?? '' );
		if ( '' === swiftling_normalize_locale( $target_locale ) || $target_locale === $locale ) {
			continue;
		}
		if ( isset( $have[ $target_locale ] ) ) {
			$kept[] = $target_locale;
			continue;
		}
		$slug = sanitize_title( (string) ( $target['slug'] ?? '' ) );
		if ( '' === $slug || isset( $taken[ $slug ] ) ) {
			$slug = sanitize_title( str_replace( '_', '-', strtolower( $target_locale ) ) );
		}
		$taken[ $slug ]          = true;
		$slugs[ $target_locale ] = $slug;
		$have[ $target_locale ]  = $slug;
		$saved['targets'][]      = $entry( $target_locale, $target['name'] ?? '', $target['rtl'] ?? false ) + array(
			'slug'    => $slug,
			'visible' => ! empty( $target['visible'] ),
		);
		$saved['order'][]        = $target_locale;
		$added[]                 = $target_locale;
	}

	$format = (string) ( $address['format'] ?? '' );
	$hosts  = in_array( $format, array( 'domain', 'subdomain' ), true );
	if ( in_array( $format, array( 'parameter', 'directory' ), true ) ) {
		$saved['format'] = $format;
	}
	if ( isset( $address['source_folder'] ) ) {
		$saved['source_folder'] = (bool) $address['source_folder'];
	}
	if ( isset( $address['domains'] ) && is_array( $address['domains'] ) ) {
		$domains = isset( $saved['domains'] ) && is_array( $saved['domains'] ) ? $saved['domains'] : array();
		foreach ( $address['domains'] as $domain_locale => $host ) {
			$host = strtolower( (string) wp_parse_url( 'http://' . preg_replace( '~^[a-z]+://~i', '', (string) $host ), PHP_URL_HOST ) );
			if ( '' !== $host ) {
				$domains[ (string) $domain_locale ] = $host;
			}
		}
		$saved['domains'] = $domains;
	}
	$saved['order'] = array_values( array_unique( $saved['order'] ) );

	update_option( 'swiftling_languages', $saved, true );
	swiftling_forget_memo();

	$packs = $added ? swiftling_install_wordpress_translations(
		array_unique(
			wp_list_pluck(
				array_filter(
					swiftling_languages()['targets'],
					static function ( $target ) use ( $added ) {
						return in_array( $target['locale'], $added, true );
					}
				),
				'wp_locale'
			)
		)
	) : array(
		'installed' => array(),
		'missing'   => array(),
	);
	if ( $added ) {
		swiftling_refresh_all_translated();
	}

	return array(
		'added' => $added,
		'kept'  => $kept,
		'slugs' => $slugs,
		'hosts' => $hosts,
		'packs' => $packs,
	);
}
