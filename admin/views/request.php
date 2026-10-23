<?php
/**
 * Request support, a screen of Translations: asks swiftling.pro to test and add a plugin, theme or
 * form SwiftLing does not translate yet. The form posts to swiftling_handle_request() in
 * includes/requests.php, which says what is sent and when.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: the notice after the redirect, and what the screen was opened for.
$swiftling_notice = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
$swiftling_named  = isset( $_GET['swiftling_which'] ) ? sanitize_text_field( wp_unslash( $_GET['swiftling_which'] ) ) : '';
$swiftling_opened = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
// phpcs:enable
$swiftling_screen  = admin_url( 'admin.php?page=swiftling-request' );
$swiftling_kinds   = swiftling_request_kinds();
$swiftling_choices = swiftling_request_choices();
// What was written before a request that was incomplete or could not be sent; it waits until sent.
$swiftling_draft = get_transient( 'swiftling_request_draft_' . get_current_user_id() );
$swiftling_draft = is_array( $swiftling_draft ) ? $swiftling_draft : array();
$swiftling_field = static function ( $name, $fallback = '' ) use ( $swiftling_draft ) {
	return isset( $swiftling_draft[ $name ] ) ? (string) $swiftling_draft[ $name ] : $fallback;
};
$swiftling_kind  = $swiftling_field( 'kind', isset( $swiftling_kinds[ $swiftling_opened ] ) ? $swiftling_opened : 'plugin' );
$swiftling_which = $swiftling_field( 'which' );

Ui::screenHeader(
	array(
		'crumb'       => __( 'Translations', 'swiftling' ),
		'title'       => __( 'Request support', 'swiftling' ),
		'description' => __( 'For a plugin, theme or form SwiftLing does not translate yet. We test it and add it to SwiftLing.', 'swiftling' ),
	)
);

if ( 'request_sent' === $swiftling_notice ) :
	?>
	<section class="swiftling-card swiftling-request-sent" role="status">
		<span class="swiftling-request-sent__tick" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><path d="M3.5 8.4l2.9 2.9L12.5 5"/></svg></span>
		<h3><?php esc_html_e( 'Request sent', 'swiftling' ); ?></h3>
		<?php /* translators: %s: name of a plugin or theme, e.g. Rank Math SEO. */ ?>
		<p><?php echo esc_html( sprintf( __( 'We test %s and write to you at the email you gave. Each site that asks for the same one moves it up our list.', 'swiftling' ), '' !== $swiftling_named ? $swiftling_named : __( 'it', 'swiftling' ) ) ); ?></p>
		<p class="swiftling-request-sent__actions">
			<?php Ui::button( __( 'Request another', 'swiftling' ), 'secondary', $swiftling_screen ); ?>
			<?php Ui::button( __( 'Back to Translations', 'swiftling' ), 'primary', admin_url( 'admin.php?page=swiftling-content' ) ); ?>
		</p>
	</section>
	<?php
	return;
endif;

if ( 'request_incomplete' === $swiftling_notice ) {
	Ui::notice( 'warning', array( 'text' => __( 'Nothing was sent: name what it is about, what stays untranslated and an email to answer.', 'swiftling' ) ) );
} elseif ( 'request_limit' === $swiftling_notice ) {
	/* translators: %d: number of requests. */
	Ui::notice( 'warning', array( 'text' => sprintf( __( 'Nothing was sent: you sent %d requests in the last hour. What you wrote waits here; send it a little later.', 'swiftling' ), SWIFTLING_REQUEST_LIMIT ) ) );
} elseif ( 'request_failed' === $swiftling_notice && '' !== $swiftling_field( 'text' ) ) {
	$swiftling_mail = 'mailto:' . SWIFTLING_REQUEST_EMAIL . '?subject=' . rawurlencode( __( 'Request for support', 'swiftling' ) ) . '&body=' . rawurlencode( $swiftling_field( 'text' ) );
	Ui::notice(
		'error',
		array(
			'title'   => __( 'The request could not be sent', 'swiftling' ),
			/* translators: %s: email address. */
			'text'    => sprintf( __( 'swiftling.pro could not be reached. Email it to %s instead: it is written out below.', 'swiftling' ), SWIFTLING_REQUEST_EMAIL ),
			'actions' => array( array( __( 'Email the request', 'swiftling' ), $swiftling_mail ) ),
		)
	);
	?>
	<label class="screen-reader-text" for="swiftling-request-text"><?php esc_html_e( 'The request, to email', 'swiftling' ); ?></label>
	<textarea id="swiftling-request-text" class="swiftling-request-unsent swiftling-mono" rows="8" readonly><?php echo esc_textarea( $swiftling_field( 'text' ) ); ?></textarea>
	<?php
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-card swiftling-request">
	<input type="hidden" name="action" value="swiftling_send_request">
	<?php wp_nonce_field( 'swiftling_send_request' ); ?>

	<fieldset class="swiftling-request__kinds">
		<legend><?php esc_html_e( 'What is it?', 'swiftling' ); ?></legend>
		<?php foreach ( $swiftling_kinds as $swiftling_key => $swiftling_name ) : ?>
			<label>
				<input type="radio" name="kind" value="<?php echo esc_attr( $swiftling_key ); ?>" <?php checked( $swiftling_kind, $swiftling_key ); ?>>
				<span><?php echo esc_html( $swiftling_name ); ?></span>
			</label>
		<?php endforeach; ?>
	</fieldset>

	<div class="swiftling-request__row">
		<label class="swiftling-request__field">
			<span><?php esc_html_e( 'Which one', 'swiftling' ); ?></span>
			<select name="which">
				<option value=""><?php esc_html_e( 'Choose…', 'swiftling' ); ?></option>
				<?php foreach ( $swiftling_choices as $swiftling_key => $swiftling_choice ) : ?>
					<option value="<?php echo esc_attr( $swiftling_key ); ?>" <?php selected( $swiftling_which, $swiftling_key ); ?>><?php echo esc_html( $swiftling_choice['label'] ); ?></option>
				<?php endforeach; ?>
				<option value="other" <?php selected( $swiftling_which, 'other' ); ?>><?php esc_html_e( 'Another plugin or theme…', 'swiftling' ); ?></option>
			</select>
		</label>
		<label class="swiftling-request__field">
			<span><?php esc_html_e( 'Or its name, when it is not in the list', 'swiftling' ); ?></span>
			<input type="text" name="name" value="<?php echo esc_attr( $swiftling_field( 'name' ) ); ?>" placeholder="<?php esc_attr_e( 'For example: Bricks, or its address on wordpress.org', 'swiftling' ); ?>">
		</label>
	</div>

	<label class="swiftling-request__field">
		<span><?php esc_html_e( 'What stays untranslated', 'swiftling' ); ?></span>
		<textarea name="what" rows="4" required placeholder="<?php esc_attr_e( 'For example: the questions of its FAQ block stay in English on /de/ pages.', 'swiftling' ); ?>"><?php echo esc_textarea( $swiftling_field( 'what' ) ); ?></textarea>
	</label>

	<div class="swiftling-request__row">
		<label class="swiftling-request__field">
			<span><?php esc_html_e( 'A page where it shows', 'swiftling' ); ?> <span class="swiftling-muted"><?php esc_html_e( '(optional)', 'swiftling' ); ?></span></span>
			<input type="url" name="page" value="<?php echo esc_attr( $swiftling_field( 'page' ) ); ?>" placeholder="https://…">
		</label>
		<label class="swiftling-request__field">
			<span><?php esc_html_e( 'Your email', 'swiftling' ); ?></span>
			<input type="email" name="email" required value="<?php echo esc_attr( $swiftling_field( 'email', wp_get_current_user()->user_email ) ); ?>">
		</label>
	</div>

	<div class="swiftling-request__report">
		<label>
			<input type="checkbox" name="report" value="1" <?php checked( array() === $swiftling_draft || '' !== $swiftling_field( 'report' ) ); ?>>
			<span>
				<strong><?php esc_html_e( 'Add the support report', 'swiftling' ); ?></strong>
				<span class="swiftling-muted"><?php esc_html_e( 'Versions and setup of the site, the same as Logs › Copy for support. No content, keys or passwords.', 'swiftling' ); ?></span>
			</span>
		</label>
		<details>
			<summary><?php esc_html_e( 'Show the report', 'swiftling' ); ?></summary>
			<pre class="swiftling-mono"><?php echo esc_html( swiftling_log_report() ); ?></pre>
		</details>
	</div>

	<p class="swiftling-muted"><?php esc_html_e( 'Sent to swiftling.pro only when you press Send: what you wrote here, your email and, if ticked, the report. Nothing else, and nothing before.', 'swiftling' ); ?></p>

	<div class="swiftling-card__foot">
		<span></span>
		<button type="submit" class="swiftling-button is-accent"><?php esc_html_e( 'Send request', 'swiftling' ); ?></button>
	</div>
</form>
