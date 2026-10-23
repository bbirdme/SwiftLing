<?php
/**
 * Translation service screen: machine translation with the site owner's own key, which texts go
 * from the site straight to the service.
 *
 * A strip on top says which service translates and its characters of the month, or tokens for a
 * language model. The services are
 * listed in a card per kind (Service::kind()): machine translation, and AI translation when a
 * service of that kind is there, as SwiftLing Pro's. Each service is a row, see service-row.php:
 * its state, Translates for the one in use or a button to use another connected one, and a part
 * that opens with its key, this month's characters and its guide to a key. Under them: languages
 * the service in use does not translate, what leaves the site and when, how translating works, and
 * the words that are never translated.
 *
 * Its forms post to the actions in includes/service.php, which return to the row of the service
 * the action was on, with its notice in the row.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\ProOffer;
use SwiftLing\Admin\Ui;
use SwiftLing\Translation\Services;

defined( 'ABSPATH' ) || exit;

Ui::screenHeader(
	array(
		'crumb'       => __( 'Settings', 'swiftling' ),
		'title'       => __( 'Translation service', 'swiftling' ),
		'description' => __( 'Machine translation with your own key. Texts go straight from your site to the service, which bills you directly.', 'swiftling' ),
	)
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the redirect.
$swiftling_notice  = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
$swiftling_notices = array(
	'working'       => array( 'success', __( 'The key works. The translation editor now offers machine translation.', 'swiftling' ) ),
	'unpaid'        => array( 'warning', __( 'The key works, but the account cannot pay yet. See what the service said below.', 'swiftling' ) ),
	'removed'       => array( 'info', __( 'The key is removed.', 'swiftling' ) ),
	'empty'         => array( 'info', __( 'Paste a key first.', 'swiftling' ) ),
	'constant'      => array( 'info', __( 'The key is set in wp-config.php, so it cannot be changed here.', 'swiftling' ) ),
	'chosen'        => array( 'success', __( 'The translation editor now translates with this service.', 'swiftling' ) ),
	'not_connected' => array( 'info', __( 'Connect that service with its key first.', 'swiftling' ) ),
	'model'         => array( 'success', __( 'The service now translates with this model.', 'swiftling' ) ),
	'model_unknown' => array( 'info', __( 'This key cannot use that model. Check the key again to see the models it can use.', 'swiftling' ) ),
);
// After an action on a service the screen opens at its row, which shows the notice instead.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which row the notice belongs to.
$swiftling_focus = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
$swiftling_focus = Services::get( $swiftling_focus ) ? $swiftling_focus : '';
if ( '' === $swiftling_focus && isset( $swiftling_notices[ $swiftling_notice ] ) ) {
	Ui::notice( $swiftling_notices[ $swiftling_notice ][0], array( 'text' => $swiftling_notices[ $swiftling_notice ][1] ) );
}

$swiftling_current = Services::current();
$swiftling_kinds   = array(
	'machine' => array( __( 'Machine translation', 'swiftling' ), __( 'Fast and even, billed per character.', 'swiftling' ) ),
	'ai'      => array( __( 'AI translation', 'swiftling' ), __( 'Follows tone and context, billed per token.', 'swiftling' ) ),
);
$swiftling_groups  = array();
foreach ( Services::all() as $swiftling_service ) {
	$swiftling_kind                        = isset( $swiftling_kinds[ $swiftling_service->kind() ] ) ? $swiftling_service->kind() : 'machine';
	$swiftling_groups[ $swiftling_kind ][] = $swiftling_service;
}
?>
<div class="swiftling-stack swiftling-services">
	<?php if ( $swiftling_current ) : ?>
		<div class="swiftling-service-strip">
			<?php Ui::serviceIcon( $swiftling_current->kind(), $swiftling_current->id() ); ?>
			<p class="swiftling-service-strip__text">
				<?php /* translators: %s: translation service name, e.g. DeepL. */ ?>
				<strong><?php echo esc_html( sprintf( __( '%s translates', 'swiftling' ), $swiftling_current->name() ) ); ?></strong>
				<?php /* translators: %s: the label of the button in the translation editor, e.g. Translate empty fields with DeepL. */ ?>
				<span><?php echo esc_html( sprintf( __( '· the editor’s button is “%s”', 'swiftling' ), sprintf( /* translators: %s: translation service name, e.g. DeepL. */ __( 'Translate empty fields with %s', 'swiftling' ), $swiftling_current->name() ) ) ); ?></span>
			</p>
			<?php if ( 'ai' === $swiftling_current->kind() ) : ?>
				<?php $swiftling_used = Services::tokens( $swiftling_current->id() ); ?>
				<?php /* translators: %s: number of tokens. */ ?>
				<span class="swiftling-service-strip__count"><?php echo esc_html( sprintf( _n( '%s token this month', '%s tokens this month', $swiftling_used, 'swiftling' ), number_format_i18n( $swiftling_used ) ) ); ?></span>
			<?php else : ?>
				<?php $swiftling_used = Services::usage( $swiftling_current->id() ); ?>
				<?php /* translators: %s: number of characters. */ ?>
				<span class="swiftling-service-strip__count"><?php echo esc_html( sprintf( _n( '%s character this month', '%s characters this month', $swiftling_used, 'swiftling' ), number_format_i18n( $swiftling_used ) ) ); ?></span>
			<?php endif; ?>
		</div>
		<?php
		// The site's languages the service does not translate; the editor offers no machine translation for them.
		$swiftling_languages   = swiftling_languages();
		$swiftling_unsupported = array();
		foreach ( array_merge( array( $swiftling_languages['source'] ), array_values( $swiftling_languages['targets'] ) ) as $swiftling_language ) {
			if ( null === $swiftling_current->languageCode( $swiftling_language ) ) {
				$swiftling_unsupported[] = $swiftling_language['name'];
			}
		}
		if ( $swiftling_unsupported ) {
			Ui::notice(
				'warning',
				array(
					'text' => null === $swiftling_current->languageCode( $swiftling_languages['source'] )
						/* translators: 1: translation service name, e.g. Google, 2: language name, e.g. Montenegrin. */
						? sprintf( __( '%1$s does not translate %2$s, the original language of your site, so the translation editor offers no machine translation.', 'swiftling' ), $swiftling_current->name(), $swiftling_languages['source']['name'] )
						/* translators: 1: translation service name, e.g. Google, 2: list of language names, e.g. Montenegrin and Esperanto. */
						: sprintf( _n( '%1$s does not translate %2$s, so the translation editor offers no machine translation into it.', '%1$s does not translate %2$s, so the translation editor offers no machine translation into them.', count( $swiftling_unsupported ), 'swiftling' ), $swiftling_current->name(), wp_sprintf( '%l', $swiftling_unsupported ) ),
				)
			);
		}
		?>
	<?php else : ?>
		<div class="swiftling-service-strip is-empty">
			<p class="swiftling-service-strip__text"><strong><?php esc_html_e( 'No service translates yet.', 'swiftling' ); ?></strong> <span><?php esc_html_e( 'Connect one below with its key, and the translation editor offers machine translation.', 'swiftling' ); ?></span></p>
		</div>
	<?php endif; ?>

	<?php foreach ( $swiftling_kinds as $swiftling_kind => $swiftling_about ) : ?>
		<?php if ( empty( $swiftling_groups[ $swiftling_kind ] ) ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<section class="swiftling-card swiftling-service-group" aria-labelledby="swiftling-services-<?php echo esc_attr( $swiftling_kind ); ?>">
			<div class="swiftling-service-group__head">
				<h2 id="swiftling-services-<?php echo esc_attr( $swiftling_kind ); ?>"><?php echo esc_html( $swiftling_about[0] ); ?></h2>
				<span class="swiftling-muted"><?php echo esc_html( $swiftling_about[1] ); ?></span>
			</div>
			<?php foreach ( $swiftling_groups[ $swiftling_kind ] as $swiftling_row_service ) : ?>
				<?php require __DIR__ . '/service-row.php'; ?>
			<?php endforeach; ?>
			<?php if ( 'machine' === $swiftling_kind ) : ?>
				<?php ProOffer::serviceLine(); ?>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<div class="swiftling-grid is-halves">
		<section class="swiftling-card is-dark swiftling-service-privacy" aria-labelledby="swiftling-privacy-title">
			<h2 id="swiftling-privacy-title"><?php esc_html_e( 'Texts leave your site only when you start a translation', 'swiftling' ); ?></h2>
			<p><?php esc_html_e( 'Page visits never send anything. Only the texts being translated are sent; link addresses and the other attributes of their tags stay on your site.', 'swiftling' ); ?></p>
		</section>
		<section class="swiftling-card swiftling-service-how" aria-labelledby="swiftling-how-title">
			<h2 id="swiftling-how-title"><?php esc_html_e( 'How translating works', 'swiftling' ); ?></h2>
			<ol>
				<li>
					<?php
					$swiftling_button = $swiftling_current
						/* translators: %s: translation service name, e.g. Google. */
						? sprintf( __( 'Translate empty fields with %s', 'swiftling' ), $swiftling_current->name() )
						: __( 'Translate empty fields', 'swiftling' );
					/* translators: %s: the label of the button in the translation editor, e.g. Translate empty fields with Google, in bold. */
					echo wp_kses( sprintf( __( 'In the translation editor, click %s.', 'swiftling' ), '<strong>' . esc_html( $swiftling_button ) . '</strong>' ), array( 'strong' => array() ) );
					?>
				</li>
				<li><?php esc_html_e( 'The empty fields of the language shown are filled and saved as machine translations.', 'swiftling' ); ?></li>
				<li><?php esc_html_e( 'Read them, then save to mark them reviewed.', 'swiftling' ); ?></li>
			</ol>
		</section>
	</div>

	<?php if ( $swiftling_current ) : ?>
		<?php $swiftling_words = Services::neverWords(); ?>
		<section class="swiftling-card swiftling-never-words" id="swiftling-never-words" aria-labelledby="swiftling-never-words-title">
			<h2 id="swiftling-never-words-title"><?php esc_html_e( 'Words that are never translated', 'swiftling' ); ?></h2>
			<p class="swiftling-muted">
				<?php
				/* translators: %s: translation service name, e.g. Google. */
				echo esc_html( sprintf( __( 'Names of your business, places and products. When %s translates, it keeps them as you wrote them, in every language. You can still change them by hand in the editor.', 'swiftling' ), $swiftling_current->name() ) );
				?>
			</p>
			<?php if ( $swiftling_words ) : ?>
				<ul class="swiftling-never-words__list">
					<?php foreach ( $swiftling_words as $swiftling_word ) : ?>
						<li>
							<?php echo esc_html( $swiftling_word ); ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="swiftling_save_never_words">
								<input type="hidden" name="remove" value="<?php echo esc_attr( $swiftling_word ); ?>">
								<?php wp_nonce_field( 'swiftling_never_words' ); ?>
								<?php /* translators: %s: a word of the list, e.g. Lavanda. */ ?>
								<button type="submit" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'swiftling' ), $swiftling_word ) ); ?>">×</button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-never-words__add">
				<input type="hidden" name="action" value="swiftling_save_never_words">
				<?php wp_nonce_field( 'swiftling_never_words' ); ?>
				<label class="screen-reader-text" for="swiftling-never-word"><?php esc_html_e( 'A word or a name', 'swiftling' ); ?></label>
				<input type="text" id="swiftling-never-word" name="word" maxlength="100" placeholder="<?php esc_attr_e( 'A word or a name, such as Villa Marija', 'swiftling' ); ?>">
				<button type="submit" class="swiftling-button is-primary"><?php esc_html_e( 'Add', 'swiftling' ); ?></button>
			</form>
			<p class="swiftling-muted swiftling-never-words__rule"><?php esc_html_e( 'Whole words, with their letters as written: Lavanda is kept, lavanda in a sentence is translated.', 'swiftling' ); ?></p>
		</section>
	<?php endif; ?>
</div>
