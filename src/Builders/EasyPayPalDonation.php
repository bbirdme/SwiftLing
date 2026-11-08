<?php
/**
 * Accept Donations with PayPal & Stripe: its donation buttons in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Accept Donations with PayPal & Stripe (the plugin "easy-paypal-donation"). A button is a post of the
 * type "wpplugin_don_button", which visitors never open, printed by the shortcode [wpedon id="..."]:
 *
 * - its name and the names of its amounts and the label of its amount: a subject of each button on the
 *   Templates screen, "wpedon-button:<ID>". The names of the amounts and the label are its meta,
 *   translated where the plugin reads them; the name is read from the post itself, so it is translated
 *   where the shortcode prints it, see translateButton();
 * - the language of PayPal's pages and of the picture of the button, a choice of the button or of the
 *   plugin's settings: in a language, the language's, where the plugin offers it, see paypalLanguage();
 * - the pages PayPal sends the donor back to, in the settings, the option "wpedon_settings": in a
 *   language, those pages in the language, see translateSettings().
 *
 * Checked against the code of Accept Donations with PayPal & Stripe 1.5 in tests/EasyPayPalDonationTest.php,
 * and by hand on a site with the plugin.
 */
class EasyPayPalDonation extends Builder {
	/**
	 * The post type of the buttons.
	 */
	const POST_TYPE = 'wpplugin_don_button';

	/**
	 * How many buttons the Templates screen lists.
	 */
	const LIMIT = 200;

	/**
	 * The meta of a button that hold text a donor reads: the label of its list of amounts, the names of
	 * its ten amounts and the label of an amount the donor types.
	 */
	const TEXT_META = array( 'wpedon_button_scpriceprice', 'wpedon_button_scpriceaname', 'wpedon_button_scpricebname', 'wpedon_button_scpricecname', 'wpedon_button_scpricedname', 'wpedon_button_scpriceename', 'wpedon_button_scpricefname', 'wpedon_button_scpricegname', 'wpedon_button_scpricehname', 'wpedon_button_scpriceiname', 'wpedon_button_scpricejname', 'wpedon_button_donation_text' );

	/**
	 * The plugin's languages of PayPal, by its number of each, as WordPress locales.
	 */
	const LANGUAGES = array(
		'1'  => 'da_DK',
		'2'  => 'nl_BE',
		'3'  => 'en_US',
		'20' => 'en_GB',
		'4'  => 'fr_CA',
		'5'  => 'de_DE',
		'6'  => 'he_IL',
		'7'  => 'it_IT',
		'8'  => 'ja_JP',
		'9'  => 'nb_NO',
		'10' => 'pl_PL',
		'11' => 'pt_BR',
		'12' => 'ru_RU',
		'13' => 'es_ES',
		'14' => 'sv_SE',
		'15' => 'zh_CN',
		'16' => 'zh_HK',
		'17' => 'zh_TW',
		'18' => 'tr_TR',
		'19' => 'th_TH',
	);

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wpedon';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Accept Donations with PayPal & Stripe';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return function_exists( 'wpedon_shortcode' );
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
	 * Hooks where the plugin reads its settings and a button's choice of language, and where its shortcode
	 * is printed.
	 */
	public function register(): void {
		add_filter( 'option_wpedon_settings', array( $this, 'translateSettings' ), PHP_INT_MAX );
		add_filter( 'get_post_metadata', array( $this, 'paypalLanguage' ), 101, 3 );
		add_filter( 'do_shortcode_tag', array( $this, 'translateButton' ), 10, 3 );
	}

	/**
	 * Returns the plugin's settings as a page in a language reads them: the pages PayPal sends the donor
	 * back to in the language, and PayPal's language the language's, where the plugin offers it.
	 *
	 * @param mixed $settings The settings.
	 * @return mixed
	 */
	public function translateSettings( $settings ) {
		$slug = self::pageSlug();
		if ( '' === $slug || ! is_array( $settings ) ) {
			return $settings;
		}
		foreach ( array( 'return', 'cancel' ) as $page ) {
			if ( isset( $settings[ $page ] ) && is_string( $settings[ $page ] ) && '' !== $settings[ $page ] ) {
				$settings[ $page ] = swiftling_addresses_in( $settings[ $page ], $slug );
			}
		}
		$number = self::languageNumber();
		if ( '' !== $number ) {
			$settings['language'] = $number;
		}
		return $settings;
	}

	/**
	 * Returns a button's choice of PayPal's language as a page in a language reads it: the language's,
	 * where the plugin offers it.
	 *
	 * @param mixed  $value     Value from an earlier filter, or null.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return mixed
	 */
	public function paypalLanguage( $value, $object_id, $meta_key = '' ) {
		if ( 'wpedon_button_language' !== $meta_key || '' === self::pageSlug() ) {
			return $value;
		}
		$number = self::languageNumber();
		return '' !== $number && self::POST_TYPE === get_post_type( (int) $object_id ) ? array( $number ) : $value;
	}

	/**
	 * Returns the plugin's number of the language the request runs in: of its locale, or else of its
	 * language in another country.
	 *
	 * @return string Empty when the plugin offers no such language.
	 */
	private static function languageNumber(): string {
		$language = swiftling_target_language( self::pageSlug() );
		$locale   = is_array( $language ) ? (string) ( $language['wp_locale'] ?? $language['locale'] ) : '';
		if ( '' === $locale ) {
			return '';
		}
		$found = array_search( $locale, self::LANGUAGES, true );
		if ( false !== $found ) {
			return (string) $found;
		}
		foreach ( self::LANGUAGES as $number => $known ) {
			if ( substr( $known, 0, 2 ) === substr( $locale, 0, 2 ) ) {
				return (string) $number;
			}
		}
		return '';
	}

	/**
	 * Returns a button as the shortcode printed it, with its name in the mode of the moment: the plugin
	 * reads the name from the post itself.
	 *
	 * @param mixed  $output The shortcode's output.
	 * @param string $tag    The shortcode.
	 * @param mixed  $attrs  Its attributes, with the button's ID.
	 * @return mixed
	 */
	public function translateButton( $output, $tag = '', $attrs = array() ) {
		$id = is_array( $attrs ) && isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;
		if ( 'wpedon' !== $tag || ! is_string( $output ) || ! $id || ! self::inMode() || self::POST_TYPE !== get_post_type( $id ) ) {
			return $output;
		}
		$name = (string) get_post_field( 'post_title', $id, 'raw' );
		if ( '' === trim( $name ) || ( isset( $attrs['name'] ) && '' !== $attrs['name'] ) ) {
			return $output;
		}
		$translated = swiftling_filter_title( $name, $id );
		if ( ! is_string( $translated ) || $translated === $name ) {
			return $output;
		}
		return str_replace( array( esc_html( $name ), esc_attr( $name ) ), array( esc_html( $translated ), esc_attr( $translated ) ), $output );
	}

	/**
	 * Returns the plugin's buttons, drafts too: their names, by ID.
	 *
	 * @return array<int, string>
	 */
	protected function buttons(): array {
		$buttons = array();
		foreach ( get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => self::LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		) as $post ) {
			$buttons[ $post->ID ] = $post->post_title;
		}
		return $buttons;
	}

	/**
	 * Returns the buttons as subjects of the Templates screen.
	 *
	 * @return array
	 */
	public function subjects(): array {
		$subjects = array();
		foreach ( $this->buttons() as $id => $name ) {
			$subjects[] = array(
				'key'         => 'wpedon-button:' . $id,
				'title'       => '' !== trim( $name ) ? $name : __( 'No title', 'swiftling' ),
				'description' => __( 'Donation button', 'swiftling' ),
			);
		}
		if ( ! $subjects ) {
			return array();
		}
		return array(
			array(
				'title'       => $this->name(),
				'description' => __( 'Donation buttons, shown on the pages that hold them.', 'swiftling' ),
				'subjects'    => $subjects,
			),
		);
	}

	/**
	 * Returns a subject of the editor: one button.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		$id = self::buttonOf( $key );
		if ( ! $id || ! isset( $this->buttons()[ $id ] ) ) {
			return null;
		}
		$name = $this->buttons()[ $id ];
		return array(
			'key'   => $key,
			'kind'  => 'fields',
			'title' => '' !== trim( $name ) ? $name : __( 'No title', 'swiftling' ),
			'type'  => __( 'Donation button', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => 'manage_options',
			'note'  => __( 'The name of this donation button, the names of its amounts and the label of its amount. The button is translated once and shows translated on every page that holds it.', 'swiftling' ),
		);
	}

	/**
	 * Returns the texts of a button, read as the shortcode reads them.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$id = self::buttonOf( (string) $subject['key'] );
		if ( ! $id || ! isset( $this->buttons()[ $id ] ) ) {
			return array();
		}
		$name  = $this->buttons()[ $id ];
		$texts = array();
		foreach ( swiftling_collect(
			static function () use ( $id, $name ): void {
				swiftling_filter_title( $name, $id );
				foreach ( self::TEXT_META as $key ) {
					get_post_meta( $id, $key, true );
				}
			}
		) as $leaf ) {
			if ( swiftling_post_owner( $id ) !== $leaf['o'] ) {
				continue;
			}
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'text',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns the button a subject names.
	 *
	 * @param string $key Subject key.
	 * @return int Zero when the key names none.
	 */
	private static function buttonOf( string $key ): int {
		return 0 === strpos( $key, 'wpedon-button:' ) && ctype_digit( substr( $key, 14 ) ) ? (int) substr( $key, 14 ) : 0;
	}

	/**
	 * Declares the meta of a button that holds text a donor reads as text, and its other meta as none.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, 'post:' ) || 0 !== strpos( $path, 'meta/wpedon_' ) || self::POST_TYPE !== get_post_type( (int) substr( $owner, 5 ) ) ) {
			return null;
		}
		foreach ( self::TEXT_META as $key ) {
			if ( swiftling_meta_path( $key, 0 ) === $path ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns what the editor calls a text of a button.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, 'post:' ) || self::POST_TYPE !== get_post_type( (int) substr( $owner, 5 ) ) ) {
			return '';
		}
		if ( 'field/post_title' === $path ) {
			return __( 'Name', 'swiftling' );
		}
		if ( swiftling_meta_path( 'wpedon_button_donation_text', 0 ) === $path ) {
			return __( 'Label of the amount', 'swiftling' );
		}
		if ( swiftling_meta_path( 'wpedon_button_scpriceprice', 0 ) === $path ) {
			return __( 'Label of the amounts', 'swiftling' );
		}
		if ( preg_match( '#^meta/wpedon_button_scprice([a-j])name$#', $path, $found ) ) {
			/* translators: %d: number of an amount of a donation button, counted from one. */
			return sprintf( __( 'Amount %d', 'swiftling' ), ord( $found[1] ) - ord( 'a' ) + 1 );
		}
		return '';
	}

	/**
	 * Whether SwiftLing changes the request: a page check or a page in a language.
	 */
	private static function inMode(): bool {
		return in_array( swiftling_mode(), array( 'probe', 'translate' ), true );
	}

	/**
	 * Returns the address slug of the language the request runs in, or an empty string for the original.
	 */
	private static function pageSlug(): string {
		$state = &swiftling_state();
		return 'translate' === $state['mode'] ? (string) $state['language'] : '';
	}
}
