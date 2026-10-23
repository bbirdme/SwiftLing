<?php
/**
 * Part of the Translation service screen, see service.php: one service as a row of its card.
 *
 * The head: the service, its state, Translates for the one in use or a button to use another
 * connected one. A part that opens: why its key does not work, its key and where it is kept,
 * checking or removing it, this month's characters, and its guide to a key while it is not
 * connected. Open for the one in use, and for every service while none is in use.
 *
 * Expects $swiftling_row_service, the service, and $swiftling_current, the service in use or null,
 * and the notice of an action on a service: $swiftling_focus, the service, $swiftling_notice and
 * $swiftling_notices, from service.php. The row of that service is open and shows the notice.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;
use SwiftLing\Translation\Services;

defined( 'ABSPATH' ) || exit;

$swiftling_id        = $swiftling_row_service->id();
$swiftling_name      = $swiftling_row_service->name();
$swiftling_state     = $swiftling_row_service->state();
$swiftling_key       = $swiftling_row_service->key();
$swiftling_connected = $swiftling_row_service->isConnected();
$swiftling_in_use    = $swiftling_current && $swiftling_current->id() === $swiftling_id;
$swiftling_used      = Services::usage( $swiftling_id );
$swiftling_tokens    = Services::tokens( $swiftling_id );
$swiftling_models    = $swiftling_row_service->models();
$swiftling_free      = $swiftling_row_service->freeCharacters();
$swiftling_quota     = $swiftling_row_service->quota();
$swiftling_checked   = (int) Services::setting( $swiftling_id, 'checked', 0 );
$swiftling_focused   = $swiftling_focus === $swiftling_id;

if ( $swiftling_in_use ) {
	$swiftling_line = __( 'Connected · fills the empty fields in the editor', 'swiftling' );
} elseif ( $swiftling_connected ) {
	$swiftling_line = __( 'Connected · ready to use', 'swiftling' );
} elseif ( 'unpaid' === $swiftling_state ) {
	/* translators: %s: translation service name, e.g. OpenAI. */
	$swiftling_line = sprintf( __( 'Key works · add credit in your %s account to translate', 'swiftling' ), $swiftling_name );
} else {
	/* translators: %s: translation service name, e.g. DeepL. */
	$swiftling_line = sprintf( __( 'With your own key: %s bills your account directly.', 'swiftling' ), $swiftling_name );
}
$swiftling_badge = array(
	'working'   => 'working',
	'unpaid'    => 'unpaid',
	'failed'    => 'failed',
	'unchecked' => '' === $swiftling_key ? 'not-connected' : 'untested',
);
?>
<div class="swiftling-service-row<?php echo $swiftling_in_use ? ' is-current' : ''; ?>" id="swiftling-service-<?php echo esc_attr( $swiftling_id ); ?>">
	<div class="swiftling-service-row__head">
		<?php Ui::serviceIcon( $swiftling_row_service->kind(), $swiftling_id ); ?>
		<span class="swiftling-service-row__text">
			<strong class="swiftling-service__name"><?php echo esc_html( $swiftling_row_service->fullName() ); ?></strong>
			<span class="swiftling-muted"><?php echo esc_html( $swiftling_line ); ?></span>
		</span>
		<?php Ui::badge( $swiftling_badge[ $swiftling_state ] ); ?>
		<?php if ( $swiftling_in_use ) : ?>
			<span class="swiftling-service-row__use is-current"><span class="swiftling-service-row__dot" aria-hidden="true"></span><?php esc_html_e( 'Translates', 'swiftling' ); ?></span>
		<?php elseif ( $swiftling_connected ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="swiftling_use_service">
				<input type="hidden" name="service" value="<?php echo esc_attr( $swiftling_id ); ?>">
				<?php wp_nonce_field( 'swiftling_use_service' ); ?>
				<button type="submit" class="swiftling-service-row__use"><span class="swiftling-service-row__dot" aria-hidden="true"></span>
					<?php
					/* translators: %s: translation service name, e.g. DeepL. */
					echo esc_html( sprintf( __( 'Use %s', 'swiftling' ), $swiftling_name ) );
					?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<details class="swiftling-service-row__more" <?php echo $swiftling_in_use || ! $swiftling_current || $swiftling_focused ? 'open' : ''; ?>>
		<summary><?php echo esc_html( $swiftling_connected || 'unpaid' === $swiftling_state ? __( 'Key and usage', 'swiftling' ) : __( 'Connect with a key', 'swiftling' ) ); ?></summary>
		<div class="swiftling-service-row__body">
			<div class="swiftling-service-row__box">
				<?php // The notice of an action on this service; a key that works on an account that cannot pay says so below. ?>
				<?php if ( $swiftling_focused && isset( $swiftling_notices[ $swiftling_notice ] ) && 'unpaid' !== $swiftling_notice ) : ?>
					<?php
					Ui::notice(
						$swiftling_notices[ $swiftling_notice ][0],
						array(
							'text'    => $swiftling_notices[ $swiftling_notice ][1],
							'compact' => true,
						)
					);
					?>
				<?php endif; ?>
				<?php if ( 'failed' === $swiftling_state || 'unpaid' === $swiftling_state ) : ?>
					<?php
					// The service's words, with the addresses in them as links: a billing page, say.
					$swiftling_said = make_clickable( esc_html( (string) Services::setting( $swiftling_id, 'error', '' ) ) );
					Ui::notice(
						'unpaid' === $swiftling_state ? 'warning' : 'error',
						array(
							/* translators: %s: translation service name, e.g. OpenAI. */
							'title'   => 'unpaid' === $swiftling_state ? sprintf( __( 'The key works, but %s cannot bill your account yet.', 'swiftling' ), $swiftling_name ) : '',
							'html'    => $swiftling_said,
							'compact' => true,
							'class'   => 'swiftling-service-row__said',
						)
					);
					?>
				<?php elseif ( 'untested' === $swiftling_badge[ $swiftling_state ] ) : ?>
					<?php
					Ui::notice(
						'warning',
						array(
							'text'    => __( 'This key is not checked yet. Check it to start translating.', 'swiftling' ),
							'compact' => true,
						)
					);
					?>
				<?php endif; ?>
				<?php if ( ! $swiftling_row_service->keyFromConstant() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-service__key">
						<input type="hidden" name="action" value="swiftling_save_service_key">
						<input type="hidden" name="service" value="<?php echo esc_attr( $swiftling_id ); ?>">
						<?php wp_nonce_field( 'swiftling_save_service_key' ); ?>
						<label for="swiftling-key-<?php echo esc_attr( $swiftling_id ); ?>"><?php esc_html_e( 'API key', 'swiftling' ); ?></label>
						<span class="swiftling-service__field">
							<?php // The saved key never goes back to the browser, only its last characters. ?>
							<input type="password" id="swiftling-key-<?php echo esc_attr( $swiftling_id ); ?>" name="key" class="swiftling-mono" autocomplete="off" spellcheck="false"
								placeholder="<?php echo esc_attr( '' === $swiftling_key ? __( 'Paste your API key', 'swiftling' ) : str_repeat( '•', 12 ) . substr( $swiftling_key, -4 ) ); ?>">
							<button type="submit" class="swiftling-button is-primary"><?php echo esc_html( '' === $swiftling_key ? __( 'Save and check', 'swiftling' ) : __( 'Replace and check', 'swiftling' ) ); ?></button>
						</span>
					</form>
				<?php endif; ?>
				<?php if ( '' !== $swiftling_key ) : ?>
					<div class="swiftling-service-row__actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="swiftling_test_service_key">
							<input type="hidden" name="service" value="<?php echo esc_attr( $swiftling_id ); ?>">
							<?php wp_nonce_field( 'swiftling_test_service_key' ); ?>
							<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Check the key again', 'swiftling' ); ?></button>
						</form>
						<?php if ( ! $swiftling_row_service->keyFromConstant() ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="swiftling_remove_service_key">
								<input type="hidden" name="service" value="<?php echo esc_attr( $swiftling_id ); ?>">
								<?php wp_nonce_field( 'swiftling_remove_service_key' ); ?>
								<button type="submit" class="swiftling-button is-link is-danger-text"><?php esc_html_e( 'Remove key', 'swiftling' ); ?></button>
							</form>
						<?php endif; ?>
						<?php if ( $swiftling_checked && 'unchecked' !== $swiftling_state ) : ?>
							<?php /* translators: %s: time since the check, e.g. 5 minutes. */ ?>
							<span class="swiftling-muted"><?php echo esc_html( sprintf( __( 'Checked %s ago', 'swiftling' ), human_time_diff( $swiftling_checked ) ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( $swiftling_connected && $swiftling_models ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-service__key swiftling-service__model">
						<input type="hidden" name="action" value="swiftling_save_service_model">
						<input type="hidden" name="service" value="<?php echo esc_attr( $swiftling_id ); ?>">
						<?php wp_nonce_field( 'swiftling_save_service_model' ); ?>
						<label for="swiftling-model-<?php echo esc_attr( $swiftling_id ); ?>"><?php esc_html_e( 'Model', 'swiftling' ); ?></label>
						<span class="swiftling-service__field">
							<select id="swiftling-model-<?php echo esc_attr( $swiftling_id ); ?>" name="model">
								<?php foreach ( $swiftling_models as $swiftling_model => $swiftling_model_name ) : ?>
									<?php
									$swiftling_model_label = $swiftling_model === $swiftling_row_service->defaultModel()
										/* translators: %s: name of a language model, e.g. Claude Opus 5.5. */
										? sprintf( __( '%s (default)', 'swiftling' ), $swiftling_model_name )
										: $swiftling_model_name;
									?>
									<option value="<?php echo esc_attr( $swiftling_model ); ?>" <?php selected( $swiftling_model, $swiftling_row_service->model() ); ?>><?php echo esc_html( $swiftling_model_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Save model', 'swiftling' ); ?></button>
						</span>
						<?php /* translators: %s: translation service name, e.g. Claude. */ ?>
						<span class="swiftling-muted"><?php echo esc_html( sprintf( __( 'The models this key may use, as %s listed them when the key was checked. Prices differ per model.', 'swiftling' ), $swiftling_name ) ); ?></span>
					</form>
				<?php endif; ?>
				<?php
				$swiftling_key_service = $swiftling_row_service;
				require __DIR__ . '/service-key-place.php';
				?>
			</div>

			<?php if ( '' !== $swiftling_key ) : ?>
				<div class="swiftling-service-row__box swiftling-service-row__usage">
					<strong><?php esc_html_e( 'This month', 'swiftling' ); ?></strong>
					<p class="swiftling-service-row__count">
						<strong><?php echo esc_html( number_format_i18n( $swiftling_used ) ); ?></strong>
						<?php
						/* translators: %s: translation service name, e.g. Google. */
						echo esc_html( sprintf( _n( 'character sent to %s', 'characters sent to %s', $swiftling_used, 'swiftling' ), $swiftling_name ) );
						?>
					</p>
					<?php if ( 'ai' === $swiftling_row_service->kind() || $swiftling_tokens ) : ?>
						<p class="swiftling-service-row__count">
							<strong><?php echo esc_html( number_format_i18n( $swiftling_tokens ) ); ?></strong>
							<?php
							/* translators: %s: translation service name, e.g. Claude. */
							echo esc_html( sprintf( _n( 'token read and written by %s', 'tokens read and written by %s', $swiftling_tokens, 'swiftling' ), $swiftling_name ) );
							?>
						</p>
					<?php endif; ?>
					<?php if ( $swiftling_quota ) : ?>
						<?php
						// What the service counts for its billing period, which depends on its plan.
						$swiftling_counted = (int) $swiftling_quota['used'];
						$swiftling_limit   = (int) $swiftling_quota['limit'];
						?>
						<?php if ( $swiftling_limit > 0 ) : ?>
							<?php $swiftling_percent = $swiftling_counted ? max( 1, min( 100, (int) round( 100 * $swiftling_counted / $swiftling_limit ) ) ) : 0; ?>
							<div class="swiftling-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Characters of the billing period', 'swiftling' ); ?>" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $swiftling_limit ); ?>" aria-valuenow="<?php echo esc_attr( (string) min( $swiftling_counted, $swiftling_limit ) ); ?>">
								<span class="is-progress" style="width: <?php echo esc_attr( (string) $swiftling_percent ); ?>%"></span>
							</div>
							<?php /* translators: 1: translation service name, e.g. DeepL, 2: characters used, 3: the plan's limit. */ ?>
							<p><?php echo esc_html( sprintf( __( '%1$s counts %2$s of the %3$s characters of your plan in this billing period.', 'swiftling' ), $swiftling_name, number_format_i18n( $swiftling_counted ), number_format_i18n( $swiftling_limit ) ) ); ?></p>
						<?php else : ?>
							<?php /* translators: 1: translation service name, e.g. DeepL, 2: characters used. */ ?>
							<p><?php echo esc_html( sprintf( __( '%1$s counts %2$s characters in this billing period.', 'swiftling' ), $swiftling_name, number_format_i18n( $swiftling_counted ) ) ); ?></p>
						<?php endif; ?>
					<?php elseif ( $swiftling_free ) : ?>
						<?php
						// Anything sent shows as at least a sliver of the bar.
						$swiftling_percent = $swiftling_used ? max( 1, min( 100, (int) round( 100 * $swiftling_used / $swiftling_free ) ) ) : 0;
						?>
						<div class="swiftling-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Characters this month', 'swiftling' ); ?>" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $swiftling_free ); ?>" aria-valuenow="<?php echo esc_attr( (string) min( $swiftling_used, $swiftling_free ) ); ?>">
							<span class="is-progress" style="width: <?php echo esc_attr( (string) $swiftling_percent ); ?>%"></span>
						</div>
						<p>
							<?php
							echo esc_html(
								$swiftling_used > $swiftling_free
									/* translators: 1: number of characters, 2: translation service name, e.g. Google. */
									? sprintf( __( '%1$s more than the characters %2$s translates free of charge each month; it bills those.', 'swiftling' ), number_format_i18n( $swiftling_used - $swiftling_free ), $swiftling_name )
									/* translators: 1: number of characters, 2: translation service name, e.g. Google. */
									: sprintf( __( 'of the %1$s characters %2$s translates free of charge each month', 'swiftling' ), number_format_i18n( $swiftling_free ), $swiftling_name )
							);
							?>
						</p>
					<?php endif; ?>
					<p class="swiftling-muted">
						<?php
						/* translators: %s: translation service name, e.g. Google. */
						echo esc_html( sprintf( __( 'Counted by SwiftLing, in UTC. Your %s account shows what it bills, including what other programs send with the same key.', 'swiftling' ), $swiftling_name ) );
						?>
						<?php if ( '' !== $swiftling_row_service->pricingUrl() ) : ?>
							<a href="<?php echo esc_url( $swiftling_row_service->pricingUrl() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Prices', 'swiftling' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'swiftling' ); ?></span></a>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php // A key that works on an account that cannot pay needs no guide to a key: the notice says where to pay. ?>
			<?php if ( ! $swiftling_connected && 'unpaid' !== $swiftling_state ) : ?>
				<?php
				$swiftling_guide_service = $swiftling_row_service;
				require __DIR__ . '/service-guide.php';
				?>
			<?php endif; ?>
		</div>
	</details>
</div>
