<?php
/**
 * Tests of where the screens offer SwiftLing Pro.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\ProOffer;

/**
 * The screens offer SwiftLing Pro quietly, in three places, to someone who may buy it, while Pro is
 * not active: a button in the header of a group of screens, a card on the Overview the person can
 * hide, and a line under machine translation on Translation service.
 */
class ProOfferTest extends TestCase {

	/**
	 * Renders a screen as an administrator, without a notice or a warning.
	 *
	 * @param string $view View.
	 * @return string
	 */
	private function screen( $view ) {
		return $this->render_screen( $view );
	}

	/**
	 * An administrator sees the three offers, each a link to Pro's prices with where it was.
	 */
	public function test_administrator_sees_the_offers() {
		$this->become( 'administrator' );

		$languages = $this->screen( 'languages' );
		$this->assertStringContainsString( 'Upgrade to Pro', $languages );
		$this->assertStringContainsString( esc_url( ProOffer::url( 'header' ) ), $languages );
		$this->assertStringNotContainsString( 'swiftling-pro-card', $languages, 'The card is the Overview’s' );

		$overview = $this->screen( 'overview' );
		$this->assertStringContainsString( 'swiftling-pro-card', $overview );
		$this->assertStringContainsString( 'DeepL', $overview );
		$this->assertStringContainsString( esc_url( ProOffer::url( 'overview' ) ), $overview );
		$this->assertStringNotContainsString( 'Upgrade to Pro', $overview, 'The Overview has no group header' );

		$service = $this->screen( 'service' );
		$this->assertStringContainsString( 'swiftling-pro-line', $service );
		$this->assertStringContainsString( 'DeepL, Claude, OpenAI and Gemini', $service );
		$this->assertStringContainsString( esc_url( ProOffer::url( 'service' ) ), $service );
		$this->assertStringContainsString( 'Upgrade to Pro', $service, 'Translation service is a screen of Settings' );
	}

	/**
	 * Every offer leads to swiftling.pro in a tab of its own, with where it was in the address.
	 */
	public function test_offers_lead_to_the_prices() {
		$this->assertSame( 'https://swiftling.pro/?utm_source=swiftling&utm_medium=plugin&utm_campaign=header#pricing', ProOffer::url( 'header' ) );
		$this->become( 'administrator' );
		$this->assertMatchesRegularExpression( '~<a [^>]*href="' . preg_quote( esc_url( ProOffer::url( 'header' ) ), '~' ) . '"[^>]*target="_blank"[^>]*rel="noopener[^"]*"~', $this->screen( 'languages' ) );
	}

	/**
	 * With SwiftLing Pro active, no screen offers it.
	 */
	public function test_nothing_with_pro_active() {
		$this->become( 'administrator' );
		do_action( 'swiftling_pro_loaded' );
		foreach ( array( 'languages', 'overview', 'service', 'content' ) as $view ) {
			$html = $this->screen( $view );
			$this->assertStringNotContainsString( 'Upgrade to Pro', $html, $view );
			$this->assertStringNotContainsString( 'swiftling-pro-card', $html, $view );
			$this->assertStringNotContainsString( 'swiftling-pro-line', $html, $view );
		}
	}

	/**
	 * Where SwiftLing Pro's plugin is active, with a licence or without one, no screen offers it: it
	 * has not announced itself without a licence, but the person has it already. A site of a network
	 * counts it when it is active for the network.
	 */
	public function test_nothing_where_pro_is_installed() {
		$this->become( 'administrator' );
		$this->assertTrue( ProOffer::offered() );
		update_option( 'active_plugins', array( 'swiftling/swiftling.php', 'swiftling-pro/swiftling-pro.php' ) );
		$this->assertFalse( ProOffer::offered() );
		$this->assertStringNotContainsString( 'Upgrade to Pro', $this->screen( 'languages' ) );
		$this->assertStringNotContainsString( 'swiftling-pro-card', $this->screen( 'overview' ) );

		update_option( 'active_plugins', array( 'swiftling/swiftling.php', 'swiftling-pro-old/other.php' ) );
		$this->assertTrue( ProOffer::offered(), 'A plugin of another name' );
		update_option( 'active_plugins', array( 'swiftling/swiftling.php' ) );
		update_site_option( 'active_sitewide_plugins', array( 'pro-copy/swiftling-pro.php' => time() ) );
		$this->assertFalse( ProOffer::offered(), 'Active for the network' );
		delete_site_option( 'active_sitewide_plugins' );
	}

	/**
	 * Someone who may not buy it, as an editor who translates, is offered nothing.
	 */
	public function test_nothing_for_who_may_not_buy() {
		$this->become( 'editor' );
		$this->assertStringNotContainsString( 'Upgrade to Pro', $this->screen( 'content' ) );
		$this->assertFalse( ProOffer::offered() );
	}

	/**
	 * The person can hide the card on the Overview for themselves; the header's button stays. Only
	 * someone the card is offered to can hide it, with the form's nonce.
	 */
	public function test_card_can_be_hidden() {
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_hide_pro_card', array() );
		$this->assertStringContainsString( 'page=swiftling', $this->redirect_of( array( ProOffer::class, 'hideCard' ) ) );
		$this->assertStringNotContainsString( 'swiftling-pro-card', $this->screen( 'overview' ) );
		$this->assertStringContainsString( 'Upgrade to Pro', $this->screen( 'languages' ) );

		$me = get_current_user_id();
		$this->become( 'administrator' );
		$this->assertStringContainsString( 'swiftling-pro-card', $this->screen( 'overview' ), 'Hidden only for the person who hid it' );
		$this->assertSame( '1', get_user_meta( $me, ProOffer::HIDDEN, true ) );
	}

	/**
	 * Hiding the card checks the nonce.
	 */
	public function test_hiding_needs_the_nonce() {
		$this->become( 'administrator' );
		$_POST                     = array( '_wpnonce' => 'wrong' );
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- A form with a wrong nonce, which the code must refuse.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->expectException( \WPDieException::class );
		ProOffer::hideCard();
	}

	/**
	 * Start over takes the hidden card with the other preferences of SwiftLing.
	 */
	public function test_start_over_forgets_the_hidden_card() {
		$this->become( 'administrator' );
		update_user_meta( get_current_user_id(), ProOffer::HIDDEN, 1 );
		swiftling_remove_everything();
		$this->assertSame( '', get_user_meta( get_current_user_id(), ProOffer::HIDDEN, true ) );
	}

	/**
	 * The address of Pro's prices is written only where the offers are, so every offer stays in one
	 * place and none slips in elsewhere.
	 */
	public function test_offers_live_in_one_class() {
		$found = array();
		foreach ( array( 'admin', 'includes', 'src', 'assets/js', 'blocks' ) as $dir ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( SWIFTLING_DIR . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( in_array( $file->getExtension(), array( 'php', 'js' ), true ) && false !== strpos( (string) file_get_contents( $file->getPathname() ), 'swiftling.pro/#pricing' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A source file of the plugin.
					$found[] = substr( $file->getPathname(), strlen( SWIFTLING_DIR ) );
				}
			}
		}
		$this->assertSame( array( 'src/Admin/ProOffer.php' ), $found );
	}
}
