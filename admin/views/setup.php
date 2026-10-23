<?php
/**
 * First setup: the language the site's content is written in.
 *
 * The form posts to swiftling_save_setup() in includes/setup.php. A custom language is filled in
 * under "My language is not in the list"; when its fields are filled, they win over the list.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

$swiftling_wordpress = swiftling_available_languages( false );
$swiftling_site      = get_locale();
$swiftling_suggested = isset( $swiftling_wordpress[ $swiftling_site ] ) ? $swiftling_site : 'en_US';
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notices after a redirect.
$swiftling_notice = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
$swiftling_custom = isset( $_GET['swiftling_custom'] ) ? sanitize_text_field( wp_unslash( $_GET['swiftling_custom'] ) ) : '';
// phpcs:enable
$swiftling_messages = array(
	'started_over_everything' => array( 'is-success', __( 'SwiftLing started over: its translations, languages and settings are removed. Your pages are as they were.', 'swiftling' ) ),
	'started_over_keep'       => array( 'is-success', __( 'SwiftLing started over. The translations are kept: add a language again and its translations come back.', 'swiftling' ) ),
	'custom_invalid'          => array( 'is-info', __( 'A custom language needs a name and a language code such as cnr or es_MX, of at most 12 characters.', 'swiftling' ) ),
	'choose'                  => array( 'is-info', __( 'Choose the language of your content.', 'swiftling' ) ),
);

/**
 * Fires above the question, so another plugin can offer the way to set SwiftLing up that fits the
 * site better, as SwiftLing Pro's import from a multilingual plugin used before. What it prints
 * comes first; the question follows as the other way. See docs/hooks.md.
 */
ob_start();
do_action( 'swiftling_setup_offer' );
$swiftling_offer  = trim( (string) ob_get_clean() );
$swiftling_second = '' !== $swiftling_offer;
?>
<div class="swiftling-setup">
	<p class="swiftling-setup__brand">
		<img src="<?php echo esc_url( SWIFTLING_URL . 'assets/images/bird.svg' ); ?>" alt="" width="30" height="17">
		SwiftLing
	</p>

	<?php if ( isset( $swiftling_messages[ $swiftling_notice ] ) ) : ?>
		<?php Ui::notice( substr( $swiftling_messages[ $swiftling_notice ][0], 3 ), array( 'text' => $swiftling_messages[ $swiftling_notice ][1] ) ); ?>
	<?php endif; ?>

	<?php if ( $swiftling_second ) : ?>
		<?php echo $swiftling_offer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Printed by the callbacks of swiftling_setup_offer, which escape their own output; only held back to know whether there is any. ?>
		<p class="swiftling-setup__or"><span><?php esc_html_e( 'or set SwiftLing up by hand', 'swiftling' ); ?></span></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-card swiftling-setup__card<?php echo $swiftling_second ? ' is-second' : ''; ?>">
		<input type="hidden" name="action" value="swiftling_setup">
		<?php wp_nonce_field( 'swiftling_setup' ); ?>

		<div class="swiftling-setup__intro">
			<?php if ( $swiftling_second ) : ?>
				<h2 class="swiftling-title"><?php esc_html_e( 'What language is your site written in?', 'swiftling' ); ?></h2>
			<?php else : ?>
				<h1 class="swiftling-title"><?php esc_html_e( 'What language is your site written in?', 'swiftling' ); ?></h1>
			<?php endif; ?>
			<p class="swiftling-muted"><?php esc_html_e( 'SwiftLing translates from this language. Your pages stay exactly as they are; the languages to translate into come next.', 'swiftling' ); ?></p>
		</div>

		<div class="swiftling-setup__field">
			<label for="swiftling-setup-language"><?php esc_html_e( 'Language of your content', 'swiftling' ); ?></label>
			<select id="swiftling-setup-language" name="source">
				<?php foreach ( $swiftling_wordpress as $swiftling_locale => $swiftling_language ) : ?>
					<option value="<?php echo esc_attr( $swiftling_locale ); ?>" <?php selected( $swiftling_locale, $swiftling_suggested ); ?>><?php echo esc_html( swiftling_language_option_label( $swiftling_language ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="swiftling-muted">
				<?php
				/* translators: %s: language name, e.g. English (United States). */
				echo esc_html( sprintf( __( 'WordPress itself is set to %s. That is the language of the admin, which can differ from the language of your pages.', 'swiftling' ), isset( $swiftling_wordpress[ $swiftling_site ] ) ? $swiftling_wordpress[ $swiftling_site ]['name'] : $swiftling_site ) );
				?>
			</p>
		</div>

		<details class="swiftling-setup__custom" <?php echo 'custom_invalid' === $swiftling_notice ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'My language is not in the list', 'swiftling' ); ?></summary>
			<p class="swiftling-muted"><?php esc_html_e( 'Add a language or a regional variant of your own, such as Montenegrin or Spanish as spoken in Mexico. Filled in, it is used instead of the list above.', 'swiftling' ); ?></p>
			<div class="swiftling-custom-language__fields">
				<label>
					<span><?php esc_html_e( 'Name, as its speakers write it', 'swiftling' ); ?></span>
					<input type="text" name="custom[name]" value="<?php echo esc_attr( $swiftling_custom ); ?>" placeholder="Crnogorski">
				</label>
				<label>
					<span><?php esc_html_e( 'Language code', 'swiftling' ); ?></span>
					<input type="text" class="swiftling-mono" name="custom[locale]" placeholder="cnr" maxlength="12">
					<span class="swiftling-muted"><?php esc_html_e( 'The language, then a country or region if any: cnr, es_MX, pt_AO. Browsers and search engines read it.', 'swiftling' ); ?></span>
				</label>
				<label>
					<span><?php esc_html_e( 'WordPress texts and dates from', 'swiftling' ); ?></span>
					<select name="custom[wp_locale]">
						<option value=""><?php esc_html_e( 'The language itself, if WordPress has it', 'swiftling' ); ?></option>
						<?php foreach ( $swiftling_wordpress as $swiftling_locale => $swiftling_language ) : ?>
							<option value="<?php echo esc_attr( $swiftling_locale ); ?>"><?php echo esc_html( $swiftling_language['name'] . ' (' . $swiftling_locale . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="swiftling-muted"><?php esc_html_e( 'Used for the names of other languages in the switcher; the admin and pages in this language keep the language WordPress is set to.', 'swiftling' ); ?></span>
				</label>
				<label class="swiftling-custom-language__rtl">
					<input type="checkbox" name="custom[rtl]" value="1">
					<?php esc_html_e( 'Written right to left', 'swiftling' ); ?>
				</label>
			</div>
		</details>

		<div class="swiftling-setup__foot">
			<span class="swiftling-muted"><?php esc_html_e( 'To change it later, start over in SwiftLing › Settings › General.', 'swiftling' ); ?></span>
			<button type="submit" class="swiftling-button <?php echo $swiftling_second ? 'is-secondary' : 'is-primary'; ?> is-large"><?php esc_html_e( 'Continue', 'swiftling' ); ?></button>
		</div>
	</form>

	<p class="swiftling-muted swiftling-setup__next"><?php esc_html_e( 'Next: the languages to translate into, on the Languages screen.', 'swiftling' ); ?></p>

	<?php
	/**
	 * Fires under the first setup, so another plugin can offer to set the languages up another
	 * way, as SwiftLing Pro's import from a multilingual plugin used before. See docs/hooks.md.
	 */
	do_action( 'swiftling_setup_aside' );
	?>
</div>
