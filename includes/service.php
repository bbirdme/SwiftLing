<?php
/**
 * The Translation service screen's actions: save a key, check it again, remove it, choose which
 * connected service translates, and the model of a service that has models.
 *
 * Each action checks the key with the service and keeps the result, so the editor offers machine
 * translation only with a key that worked, and knows which languages the service translates.
 *
 * @package SwiftLing
 */

use SwiftLing\Translation\Service;
use SwiftLing\Translation\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the service a form of the Translation service screen names, after checking the user's
 * right and the form's nonce.
 *
 * @param string $action Nonce action.
 * @return Service
 */
function swiftling_service_from_form( $action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the translation service of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $action );

	$service = Services::get( isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '' );
	if ( ! $service ) {
		wp_die( esc_html__( 'This translation service is not known.', 'swiftling' ), '', array( 'response' => 400 ) );
	}
	return $service;
}

/**
 * Checks a key with its service and keeps the result: the state, the error, the time, the
 * languages the service translates and the models the key may use. The check is written to the Logs.
 *
 * @param Service $service Service.
 * @param string  $key     API key.
 * @return bool Whether the key works and can translate; a key on an account that cannot pay does not
 *              yet, see Service::state().
 */
function swiftling_check_service_key( $service, $key ) {
	$result = $service->check( $key );
	// Each check goes to the Logs, so what the service said stays after the screen's notice: its
	// message and the error's code, never the key.
	$unpaid = is_wp_error( $result ) && swiftling_service_error_is_billing( $result );
	if ( $unpaid ) {
		swiftling_log(
			'warning',
			'service',
			/* translators: 1: translation service name, e.g. OpenAI, 2: what it said. */
			sprintf( __( 'The %1$s key works, but the account cannot pay yet: %2$s', 'swiftling' ), $service->name(), $result->get_error_message() ),
			array( 'detail' => $service->id() . ' · ' . $result->get_error_code() )
		);
	} elseif ( is_wp_error( $result ) ) {
		swiftling_log(
			'error',
			'service',
			/* translators: 1: translation service name, e.g. DeepL, 2: what it said. */
			sprintf( __( 'The check of the %1$s key failed: %2$s', 'swiftling' ), $service->name(), $result->get_error_message() ),
			array( 'detail' => $service->id() . ' · ' . $result->get_error_code() )
		);
	} else {
		/* translators: %s: translation service name, e.g. DeepL. */
		swiftling_log( 'event', 'service', sprintf( __( 'The key of %s works.', 'swiftling' ), $service->name() ), array( 'detail' => $service->id() ) );
	}
	if ( is_wp_error( $result ) ) {
		Services::update(
			$service->id(),
			array(
				'state'       => $unpaid ? 'unpaid' : 'failed',
				'error'       => $result->get_error_message(),
				'checked'     => time(),
				'fingerprint' => $service->fingerprint( $key ),
			)
		);
		return false;
	}
	Services::update(
		$service->id(),
		array(
			'state'       => 'working',
			'error'       => null,
			'checked'     => time(),
			'fingerprint' => $service->fingerprint( $key ),
			'languages'   => $result['languages'],
			'models'      => isset( $result['models'] ) && is_array( $result['models'] ) ? swiftling_clean_service_models( $result['models'] ) : null,
		)
	);
	return true;
}

/**
 * Returns the models a service listed, as kept: IDs and names as plain text, at most 200 of them.
 *
 * @param array $models ID => name.
 * @return array<string, string>
 */
function swiftling_clean_service_models( $models ) {
	$clean = array();
	foreach ( $models as $id => $name ) {
		$id = sanitize_text_field( (string) $id );
		if ( '' !== $id && count( $clean ) < 200 ) {
			$clean[ $id ] = '' !== trim( (string) $name ) ? sanitize_text_field( (string) $name ) : $id;
		}
	}
	return $clean;
}

/**
 * Whether a service's error is about paying, not about the key: no credits, billing off, a spend
 * limit reached. The service says so with the reason "billing" in the error's data.
 *
 * @param WP_Error $error Error of a check or a translation.
 * @return bool
 */
function swiftling_service_error_is_billing( $error ) {
	$data = $error->get_error_data();
	return is_array( $data ) && isset( $data['reason'] ) && 'billing' === $data['reason'];
}

/**
 * Returns the notice after a check of a key: working, unpaid for a key that works on an account
 * that cannot pay, or failed.
 *
 * @param Service $service Service.
 * @param bool    $works   What the check returned.
 * @return string
 */
function swiftling_service_check_notice( $service, $works ) {
	if ( $works ) {
		return 'working';
	}
	return 'unpaid' === $service->state() ? 'unpaid' : 'failed';
}

/**
 * Returns to the Translation service screen with a notice: at the row of the service the action
 * was on, which shows the notice, so the user is where they were.
 *
 * @param string       $notice  Notice key, see admin/views/service.php.
 * @param Service|null $service The service the action was on.
 */
function swiftling_service_redirect( $notice, $service = null ) {
	$args = array( 'swiftling_notice' => $notice );
	if ( $service ) {
		$args['service'] = $service->id();
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=swiftling-service' ) ) . ( $service ? '#swiftling-service-' . $service->id() : '' ) );
	exit;
}

/**
 * Adds a word to the words that are never translated, or removes one, from the Translation service
 * screen. A word is kept once, trimmed and without tags; the list holds up to 200 words.
 */
function swiftling_save_never_words() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may not change the translation service of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'swiftling_never_words' );

	$words  = Services::neverWords();
	$add    = isset( $_POST['word'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['word'] ) ) ) : '';
	$remove = isset( $_POST['remove'] ) ? sanitize_text_field( wp_unslash( $_POST['remove'] ) ) : '';
	if ( '' !== $remove ) {
		$words = array_values( array_diff( $words, array( $remove ) ) );
	} elseif ( '' !== $add && mb_strlen( $add ) <= 100 && ! in_array( $add, $words, true ) && count( $words ) < 200 ) {
		$words[] = $add;
	}
	update_option( Services::NEVER_WORDS_OPTION, $words, false );
	wp_safe_redirect( admin_url( 'admin.php?page=swiftling-service#swiftling-never-words' ) );
	exit;
}
add_action( 'admin_post_swiftling_save_never_words', 'swiftling_save_never_words' );

/**
 * Saves the key typed on the Translation service screen and checks it. A key that does not work is
 * kept too, so it can be checked again once the cause is fixed, for example billing turned on.
 */
function swiftling_save_service_key() {
	$service = swiftling_service_from_form( 'swiftling_save_service_key' );
	if ( $service->keyFromConstant() ) {
		swiftling_service_redirect( 'constant', $service );
	}

	// API keys hold letters, digits, dashes, underscores, and colons and dots, as a DeepL API Free key
	// ending in :fx; spaces and quotes come from copying.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in swiftling_service_from_form() above.
	$key = isset( $_POST['key'] ) ? preg_replace( '/[^A-Za-z0-9_\-:.]/', '', sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
	$key = substr( (string) $key, 0, 200 );
	if ( '' === $key ) {
		swiftling_service_redirect( 'empty', $service );
	}

	Services::update( $service->id(), array( 'key' => $key ) );
	swiftling_service_redirect( swiftling_service_check_notice( $service, swiftling_check_service_key( $service, $key ) ), $service );
}
add_action( 'admin_post_swiftling_save_service_key', 'swiftling_save_service_key' );

/**
 * Checks the key in use again: the saved one or the one in wp-config.php.
 */
function swiftling_test_service_key() {
	$service = swiftling_service_from_form( 'swiftling_test_service_key' );
	if ( '' === $service->key() ) {
		swiftling_service_redirect( 'empty', $service );
	}
	swiftling_service_redirect( swiftling_service_check_notice( $service, swiftling_check_service_key( $service, $service->key() ) ), $service );
}
add_action( 'admin_post_swiftling_test_service_key', 'swiftling_test_service_key' );

/**
 * Removes the key saved on the Translation service screen. The count of characters sent stays.
 */
function swiftling_remove_service_key() {
	$service = swiftling_service_from_form( 'swiftling_remove_service_key' );
	swiftling_forget_saved_key( $service );
	swiftling_service_redirect( 'removed', $service );
}

/**
 * Removes the key saved in the database. Without a key in wp-config.php, what its check found
 * goes with it. With one, that check is about the key in wp-config.php and stays, so the service
 * stays connected.
 *
 * @param Service $service Service.
 */
function swiftling_forget_saved_key( $service ) {
	$forget = array( 'key' => null );
	if ( ! $service->keyFromConstant() ) {
		$forget += array(
			'state'       => null,
			'error'       => null,
			'checked'     => null,
			'fingerprint' => null,
			'languages'   => null,
			'models'      => null,
		);
	}
	Services::update( $service->id(), $forget );
}
add_action( 'admin_post_swiftling_remove_service_key', 'swiftling_remove_service_key' );

/**
 * Makes a connected service the one that translates, when several are connected.
 */
function swiftling_use_service() {
	$service = swiftling_service_from_form( 'swiftling_use_service' );
	if ( ! $service->isConnected() ) {
		swiftling_service_redirect( 'not_connected', $service );
	}
	update_option( Services::CHOICE_OPTION, $service->id(), false );
	swiftling_service_redirect( 'chosen', $service );
}
add_action( 'admin_post_swiftling_use_service', 'swiftling_use_service' );

/**
 * Saves the model a service translates with, one of the models its key may use.
 */
function swiftling_save_service_model() {
	$service = swiftling_service_from_form( 'swiftling_save_service_model' );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in swiftling_service_from_form() above.
	$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
	if ( ! isset( $service->models()[ $model ] ) ) {
		swiftling_service_redirect( 'model_unknown', $service );
	}
	Services::update( $service->id(), array( 'model' => $model ) );
	swiftling_service_redirect( 'model', $service );
}
add_action( 'admin_post_swiftling_save_service_model', 'swiftling_save_service_model' );
