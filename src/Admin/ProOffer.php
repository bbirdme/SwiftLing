<?php
/**
 * Where the screens offer SwiftLing Pro.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Offers SwiftLing Pro quietly, in three places, to someone who may buy it, an administrator, while
 * Pro is not active:
 *
 * - a button in the header of a group of screens, see Menu::groupHeader();
 * - a card on the Overview, under what the screen shows, which the person can hide for themselves;
 * - a line under machine translation on Translation service, where someone looks for better
 *   translation.
 *
 * No feature of SwiftLing is locked or shown as Pro's, and nothing nags: wordpress.org allows an
 * offer of a paid version only so. Each offer leads to Pro's prices on swiftling.pro, in a tab of
 * its own, with where it was in the address. The address is written only here; a test fails when
 * it shows up anywhere else.
 */
final class ProOffer {
	/**
	 * Address of SwiftLing Pro's prices: the pricing section of the home page.
	 */
	public const URL = 'https://swiftling.pro/#pricing';

	/**
	 * User meta: the person hid the card on the Overview. Start over removes it with SwiftLing's
	 * other preferences, see swiftling_remove_everything().
	 */
	public const HIDDEN = 'swiftling_pro_card_hidden';

	/**
	 * Hooks the form that hides the card.
	 */
	public function register(): void {
		add_action( 'admin_post_swiftling_hide_pro_card', array( self::class, 'hideCard' ) );
	}

	/**
	 * Whether the screens offer Pro: to someone who may buy it, while Pro is not active, and not
	 * installed either. SwiftLing Pro says it is active with swiftling_pro_loaded, once it runs.
	 */
	public static function offered(): bool {
		return ! did_action( 'swiftling_pro_loaded' ) && ! self::installed() && current_user_can( 'manage_options' );
	}

	/**
	 * Whether SwiftLing Pro's plugin is active, with a licence or without: without one it has not
	 * said it runs, but the person has it. Found by its file, as its folder may be named otherwise.
	 */
	private static function installed(): bool {
		$active = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		foreach ( $active as $file ) {
			if ( 'swiftling-pro.php' === basename( (string) $file ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the address of Pro's prices, with the place of the offer.
	 *
	 * @param string $place header, overview or service.
	 */
	public static function url( string $place ): string {
		return add_query_arg(
			array(
				'utm_source'   => 'swiftling',
				'utm_medium'   => 'plugin',
				'utm_campaign' => $place,
			),
			self::URL
		);
	}

	/**
	 * Prints the button of the header of a group of screens.
	 */
	public static function headerButton(): void {
		if ( ! self::offered() ) {
			return;
		}
		?>
		<a class="swiftling-button is-accent swiftling-pro-button" href="<?php echo esc_url( self::url( 'header' ) ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Upgrade to Pro', 'swiftling' ); ?>
			<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'swiftling' ); ?></span>
		</a>
		<?php
	}

	/**
	 * Prints the card of the Overview: what Pro adds, unless the person hid it.
	 */
	public static function overviewCard(): void {
		if ( ! self::offered() || get_user_meta( get_current_user_id(), self::HIDDEN, true ) ) {
			return;
		}
		$adds = array(
			__( 'DeepL, Claude, OpenAI and Gemini, with your own key', 'swiftling' ),
			__( 'A glossary of your names and terms', 'swiftling' ),
			__( 'The whole site translated in the background, and new pages as you publish them', 'swiftling' ),
			__( 'Prices in a currency for each language', 'swiftling' ),
			__( 'Import from Polylang, WPML and TranslatePress', 'swiftling' ),
		);
		?>
		<section class="swiftling-card swiftling-pro-card" aria-labelledby="swiftling-pro-card-title">
			<svg class="swiftling-pro-card__bird" viewBox="0 0 64.86 36.57" aria-hidden="true" focusable="false"><path d="<?php echo esc_attr( Ui::BIRD ); ?>"/></svg>
			<div class="swiftling-pro-card__main">
				<h2 id="swiftling-pro-card-title"><?php esc_html_e( 'SwiftLing Pro', 'swiftling' ); ?></h2>
				<ul class="swiftling-pro-card__list">
					<?php foreach ( $adds as $add ) : ?>
						<li><?php echo esc_html( $add ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="swiftling-pro-card__actions">
				<a class="swiftling-button is-secondary" href="<?php echo esc_url( self::url( 'overview' ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'See SwiftLing Pro', 'swiftling' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'swiftling' ); ?></span>
				</a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="swiftling_hide_pro_card">
					<?php wp_nonce_field( 'swiftling_hide_pro_card' ); ?>
					<button type="submit" class="swiftling-pro-card__hide"><?php esc_html_e( 'Hide this', 'swiftling' ); ?></button>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Prints the line under machine translation on Translation service.
	 */
	public static function serviceLine(): void {
		if ( ! self::offered() ) {
			return;
		}
		?>
		<p class="swiftling-pro-line">
			<?php
			printf(
				/* translators: %s: link to SwiftLing Pro. */
				esc_html__( 'DeepL, Claude, OpenAI and Gemini come with %s.', 'swiftling' ),
				'<a href="' . esc_url( self::url( 'service' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'SwiftLing Pro', 'swiftling' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'swiftling' ) . '</span></a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Hides the card of the Overview for the person, from its form, and goes back to the Overview.
	 */
	public static function hideCard(): void {
		check_admin_referer( 'swiftling_hide_pro_card' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not change this.', 'swiftling' ), '', array( 'response' => 403 ) );
		}
		update_user_meta( get_current_user_id(), self::HIDDEN, 1 );
		wp_safe_redirect( admin_url( 'admin.php?page=swiftling' ) );
		exit;
	}
}
