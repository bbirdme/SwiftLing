<?php
/**
 * Newsletter: its form, messages and emails in the language of the visitor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter (by Stefano Lissa). The plugin keeps the texts a subscriber reads in its settings, WordPress
 * options named "newsletter_<part>", and reads them with get_option() while it answers, so SwiftLing
 * translates them as any option: declared as text by their keys, see textOptions(), and offered on the
 * subject of the plugin's settings. A text the owner left empty is the plugin's own default, translated
 * by its language pack.
 *
 * The plugin asks a plugin of languages, through its filters, which languages the site has and which
 * one a request is in, see register(), outside its own screens, see isMultilanguage(). Told, it keeps the language a visitor subscribed in with the
 * subscriber, and before it writes to a subscriber or answers a link from an email, which arrives with
 * no page to name the language, it switches to that language with the action wpml_switch_language,
 * see switchLanguage(): the emails and the page after the link are in the subscriber's language, at
 * that language's address, see messageUrl().
 *
 * The form is sent to admin-ajax.php with the action "tnp", answered in the language of the page it
 * was sent from, see sendingActions().
 *
 * Checked against the code of Newsletter 9.5 (the free plugin), in tests/NewsletterTest.php, and by hand
 * on a site with the plugin.
 */
class Newsletter extends SettingsTexts {
	/**
	 * The functions of the plugin that switch its language and put it back.
	 */
	const SWITCHERS = array( 'switch_language', 'restore_language' );

	/**
	 * A placeholder of the plugin's texts, as {name} or {subscription_confirm_url}.
	 */
	const PLACEHOLDER = '/\{[a-z_0-9]+\}/';

	/**
	 * How many lists the plugin has, unless the site sets more.
	 */
	const LISTS = 40;

	/**
	 * How many times the plugin switched the language and has not put it back yet, in this request.
	 *
	 * @var int
	 */
	private $switched = 0;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'newsletter';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Newsletter';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'NEWSLETTER_VERSION' ) && class_exists( 'Newsletter', false );
	}

	/**
	 * Whether a post is built with the plugin: none is.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The plugin builds no posts.
		return false;
	}

	/**
	 * Tells the plugin the site's languages and the language of the request, and hooks where it
	 * switches the language for a subscriber.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters, as it asks them.
		add_filter( 'newsletter_is_multilanguage', array( $this, 'isMultilanguage' ) );
		add_filter( 'newsletter_languages', array( $this, 'languages' ) );
		add_filter( 'newsletter_current_language', array( $this, 'currentLanguage' ) );
		add_filter( 'newsletter_message_url', array( $this, 'messageUrl' ) );
		// phpcs:enable
		add_action( 'wpml_switch_language', array( $this, 'switchLanguage' ), 10, 1 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns the action with which the plugin receives its form, and every other request of a
	 * visitor, at admin-ajax.php.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'tnp' );
	}

	/**
	 * Returns the page the plugin's form was sent from: the form names it in its field "nhr". The plugin
	 * first answers with a page of its own against bots, which sends the form again from admin-ajax.php,
	 * so the browser then names that address instead.
	 *
	 * @param string $referer The address the request names as its Referer.
	 */
	public function sentFrom( string $referer ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only which page the form names; the plugin verifies the form.
		if ( ! isset( $_REQUEST['action'], $_REQUEST['nhr'] ) || 'tnp' !== $_REQUEST['action'] || ! is_string( $_REQUEST['nhr'] ) ) {
			return $referer;
		}
		$page = esc_url_raw( wp_unslash( $_REQUEST['nhr'] ) );
		// phpcs:enable
		return '' !== $page ? $page : $referer;
	}

	/**
	 * Whether the site is in more than one language, for the plugin: when it is translated into one, where
	 * visitors subscribe and the plugin writes to them, but not on the plugin's own screens. There the
	 * plugin would edit a set of its settings for each language, with a public page of its own, and warn
	 * while one is missing: SwiftLing translates the one set instead.
	 *
	 * @param mixed $multilanguage What the plugin found itself.
	 * @return mixed
	 */
	public function isMultilanguage( $multilanguage ) {
		return $multilanguage || ( array() !== swiftling_languages()['targets'] && ( ! is_admin() || wp_doing_ajax() ) );
	}

	/**
	 * Returns the site's languages, for the plugin: the address slug of each language it is translated
	 * into, with the language's name. The original is the plugin's "all languages".
	 *
	 * @param mixed $languages Languages another plugin named.
	 * @return mixed
	 */
	public function languages( $languages ) {
		$languages = is_array( $languages ) ? $languages : array();
		foreach ( swiftling_languages()['targets'] as $language ) {
			$languages[ $language['slug'] ] = $language['name'];
		}
		return $languages;
	}

	/**
	 * Returns the language of the request, for the plugin: the address slug of the language a page is
	 * shown in, or empty in the original.
	 *
	 * @param mixed $language What another plugin said.
	 * @return mixed
	 */
	public function currentLanguage( $language ) {
		if ( 'translate' !== swiftling_mode() ) {
			return $language;
		}
		return (string) swiftling_state()['language'];
	}

	/**
	 * Switches the request into a language when the plugin does, before it writes to a subscriber or
	 * answers a link of an email, and back when the plugin puts its language back. Only the plugin's own
	 * calls count: another plugin that runs the action means a language of a plugin SwiftLing is not.
	 *
	 * @param mixed $language The language's address slug, as the plugin keeps it with the subscriber.
	 */
	public function switchLanguage( $language = null ): void {
		$caller = self::caller();
		if ( 'switch_language' === $caller ) {
			swiftling_switch_translation( is_string( $language ) ? $language : '' );
			++$this->switched;
		} elseif ( 'restore_language' === $caller && $this->switched > 0 ) {
			swiftling_resume_translation();
			--$this->switched;
		}
	}

	/**
	 * Returns the page the plugin sends a visitor to after a link of an email, with its message, in the
	 * language the plugin switched to for the subscriber: the address of a page in the original
	 * otherwise, as the link arrives with no page in a language to keep.
	 *
	 * @param mixed $url The page's address, as the plugin built it.
	 * @return mixed
	 */
	public function messageUrl( $url ) {
		return is_string( $url ) ? swiftling_localize_url( $url ) : $url;
	}

	/**
	 * Returns which function of the plugin runs the action wpml_switch_language, or empty for any other
	 * code.
	 */
	private static function caller(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Which function runs the action: the plugin's own switch, or another plugin's.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 ) as $frame ) {
			if ( isset( $frame['function'], $frame['class'] ) && in_array( $frame['function'], self::SWITCHERS, true ) && 0 === strpos( (string) $frame['class'], 'Newsletter' ) ) {
				return $frame['function'];
			}
		}
		return '';
	}

	/**
	 * Returns the plugin's options that hold text, by the keys of their texts.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$lists = array();
		$max   = defined( 'NEWSLETTER_LIST_MAX' ) ? (int) NEWSLETTER_LIST_MAX : self::LISTS;
		for ( $list = 1; $list <= $max; $list++ ) {
			$lists[] = 'list_' . $list;
		}
		return array(
			'newsletter_form'           => array( 'email', 'name', 'surname', 'sex', 'track', 'privacy', 'subscribe', 'title_female', 'title_male', 'title_none', 'sex_male', 'sex_female', 'sex_none' ),
			'newsletter_subscription'   => array( 'error_text', 'subscription_text', 'confirmation_text', 'confirmation_subject', 'confirmation_message', 'confirmation_message_plain_text', 'confirmed_text', 'confirmed_subject', 'confirmed_message', 'confirmed_message_plain_text' ),
			'newsletter_profile'        => array( 'text', 'email_changed', 'error', 'save_label', 'privacy_label', 'saved' ),
			'newsletter_unsubscription' => array( 'unsubscribe_text', 'reactivate_text', 'error_text', 'unsubscribed_text', 'unsubscribed_subject', 'unsubscribed_message', 'reactivated_text' ),
			'newsletter_lists'          => $lists,
			'newsletter_info'           => array( 'header_title', 'header_sub', 'footer_title', 'footer_contact', 'footer_legal' ),
		);
	}

	/**
	 * Returns the values of the plugin's settings that hold an address of a page, left to SwiftLing as
	 * any address.
	 *
	 * @return array<string, string[]>
	 */
	protected function addressOptions(): array {
		return array(
			'newsletter_form'         => array( 'privacy_url' ),
			'newsletter_subscription' => array( 'confirmed_url', 'confirmation_url' ),
		);
	}

	/**
	 * Returns what the editor calls each text: its part of the plugin, and the name of the text.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		$parts  = array(
			'newsletter_form'           => __( 'Form', 'swiftling' ),
			'newsletter_subscription'   => __( 'Subscription', 'swiftling' ),
			'newsletter_profile'        => __( 'Profile', 'swiftling' ),
			'newsletter_unsubscription' => __( 'Unsubscription', 'swiftling' ),
			'newsletter_lists'          => __( 'Lists', 'swiftling' ),
			'newsletter_info'           => __( 'Email header and footer', 'swiftling' ),
		);
		$labels = array();
		foreach ( $this->textOptions() as $option => $keys ) {
			foreach ( $keys as $key ) {
				$labels[ $option ][ $key ] = $parts[ $option ] . ' › ' . ucfirst( str_replace( '_', ' ', $key ) );
			}
		}
		return $labels;
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The texts of the subscription form, the pages after subscribing or unsubscribing, the lists and the emails to subscribers, which go out in the language the visitor subscribed in. A text left empty in Newsletter is its own, translated by its language pack. Keep every placeholder, such as {name}.', 'swiftling' );
	}

	/**
	 * Keeps the placeholders of the plugin's texts as they are when a text goes to machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, self::PLACEHOLDER );
	}
}
