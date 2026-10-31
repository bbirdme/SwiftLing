<?php
/**
 * Real Cookie Banner: its banner, services and cookie policy in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Real Cookie Banner. The plugin hands its banner to the page as data, which its script shows. What
 * SwiftLing does not find by itself:
 *
 * - The texts of the banner, of a content blocker, of the individual settings, of the button that
 *   reopens them and of the cookie policy are values of the plugin's settings, each an option of its
 *   own, read while the page renders: declared as text by their names, see textOptions(), and a
 *   subject of their own on the Templates screen. A page check does not see them, as the banner is
 *   data for a script.
 * - The services, the content blockers and the links of the banner are posts of types visitors never
 *   open, and the service groups terms of a taxonomy without a list on the Terms screen: the subject of
 *   the settings offers their names and descriptions, see readMoreTexts(). Where a query hands the
 *   plugin such a post, it gets a copy with its texts in the mode of the moment, see displayCopies().
 *   What a service sets, such as the names of its cookies, and the code it runs are no text, see
 *   fieldHoldsText().
 * - A text with a placeholder, as {{services}}, keeps it in machine translation, see keepPlaceholders(),
 *   and a translation that leaves one out shows the original, see keepPlaceholderTexts().
 *
 * The plugin keeps a revision of its settings with a hash, which a visitor's consent names; it makes
 * it when the settings are saved, not from a page, so a page in a language keeps the hash of the
 * original and a consent counts in every language. Checked against the code of Real Cookie Banner 5.4
 * (the free plugin), in tests/RealCookieBannerTest.php, and by hand on a site with the plugin.
 */
class RealCookieBanner extends SettingsTexts {
	/**
	 * The prefixes of the names of the plugin's options that hold text.
	 */
	const TEXT_PREFIXES = array( 'rcb-banner-texts-', 'rcb-blocker-texts-', 'rcb-individual-texts-', 'rcb-sticky-texts-', 'rcb-cookie-policy-h-', 'rcb-cookie-policy-' );

	/**
	 * Options of the plugin with the prefix of texts that hold no text, such as the page of the cookie policy.
	 */
	const NO_TEXT_OPTIONS = array( 'rcb-cookie-policy-id', 'rcb-cookie-policy-show-custom-groups', 'rcb-cookie-policy-table-dark-mode' );

	/**
	 * The attributes of the plugin's shortcodes in its texts that a visitor reads, as the label of the
	 * link that changes the consent. The others, such as its type or tag, are settings.
	 */
	const SHORTCODE_TEXTS = array( 'text', 'successmessage' );

	/**
	 * The plugin's options of the website's operator that hold text.
	 */
	const OPERATOR_TEXTS = array( 'rcb-operator-contact-address' );

	/**
	 * The plugin's types of post of a service, a content blocker and a link of the banner.
	 */
	const POST_TYPES = array( 'rcb-cookie', 'rcb-blocker', 'rcb-banner-link' );

	/**
	 * The plugin's taxonomy of service groups.
	 */
	const GROUPS = 'rcb-cookie-group';

	/**
	 * The fields of a service that hold text a visitor reads. Every other field of the plugin's posts is
	 * a setting, a cookie's name or code.
	 */
	const SERVICE_TEXT_META = array( 'provider', 'providerNotice', 'groupNotice', 'legalBasisNotice', 'technicalHandlingNotice', 'createContentBlockerNotice' );

	/**
	 * A placeholder of the plugin's texts, as {{services}}.
	 */
	const PLACEHOLDER = '/\{\{[^{}]+\}\}/';

	/**
	 * Names of the options of texts, found once a request.
	 *
	 * @var string[]|null
	 */
	private $options = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'real-cookie-banner';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Real Cookie Banner';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'RCB_FILE' ) || class_exists( 'DevOwl\\RealCookieBanner\\Core', false );
	}

	/**
	 * Whether a post is built with the plugin: none visitors open.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The plugin builds no posts visitors open.
		return false;
	}

	/**
	 * Hooks where queries hand out the plugin's posts and where its options are read.
	 */
	public function register(): void {
		add_filter( 'the_posts', array( $this, 'displayCopies' ), 100, 1 );
		// After SwiftLing's own filter of options (100), which put the translation in.
		add_filter( 'pre_option', array( $this, 'keepPlaceholderTexts' ), 101, 2 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns the names of the plugin's options that hold text: those with a prefix of texts, as the
	 * site has them, and the operator's address.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		if ( null === $this->options ) {
			global $wpdb;
			$names = array();
			foreach ( self::TEXT_PREFIXES as $prefix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The names of the plugin's options, once a request.
				$names = array_merge( $names, (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ) );
			}
			$this->options = array_values( array_diff( array_unique( array_merge( $names, self::OPERATOR_TEXTS ) ), self::NO_TEXT_OPTIONS ) );
			sort( $this->options );
		}
		return array_fill_keys( $this->options, array( '' ) );
	}

	/**
	 * Returns what the editor calls a text of the settings: its name without the plugin's prefix.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		$labels = array();
		foreach ( array_keys( $this->textOptions() ) as $option ) {
			$labels[ $option ] = array( '' => ucfirst( str_replace( '-', ' ', substr( $option, 4 ) ) ) );
		}
		return $labels;
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The texts of the cookie banner, the content blockers and the cookie policy, and the names and descriptions of the services and their groups. Keep every placeholder, such as {{services}}: a translation without one shows the original.', 'swiftling' );
	}

	/**
	 * Gives the plugin's posts a query hands out their texts in the mode of the moment, as copies: the
	 * plugin prints a service's name and description straight from the post.
	 *
	 * @param mixed $posts Posts of the query.
	 * @return mixed
	 */
	public function displayCopies( $posts ) {
		if ( ! is_array( $posts ) || ! self::inMode() ) {
			return $posts;
		}
		foreach ( $posts as $index => $post ) {
			if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
				continue;
			}
			$title   = swiftling_filter_title( $post->post_title, $post->ID );
			$content = '' !== trim( $post->post_content ) ? swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $post->post_content ) : $post->post_content;
			if ( $title === $post->post_title && $content === $post->post_content ) {
				continue;
			}
			$copy               = clone $post;
			$copy->post_title   = is_string( $title ) ? $title : $post->post_title;
			$copy->post_content = is_string( $content ) ? $content : $post->post_content;
			$posts[ $index ]    = $copy;
		}
		return $posts;
	}

	/**
	 * Reads the names and descriptions of the service groups, and the names, descriptions and notices of
	 * the services, content blockers and links of the banner, for the subject of the settings.
	 */
	protected function readMoreTexts(): void {
		$groups = taxonomy_exists( self::GROUPS ) ? get_terms(
			array(
				'taxonomy'   => self::GROUPS,
				'hide_empty' => false,
			)
		) : array();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			swiftling_transform_text( 'term:' . $group->term_id, 'field/name', 'term:name', $group->name );
			swiftling_transform_text( 'term:' . $group->term_id, 'field/description', 'term:description', $group->description );
		}
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPES,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => array(
					'post_type'  => 'ASC',
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			swiftling_filter_title( $post->post_title, $post->ID );
			if ( '' !== trim( $post->post_content ) ) {
				swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $post->post_content );
			}
			if ( 'rcb-cookie' === $post->post_type ) {
				foreach ( self::SERVICE_TEXT_META as $key ) {
					get_post_meta( $post->ID, $key, true );
				}
			}
		}
	}

	/**
	 * Declares which fields of the plugin's posts hold text, and the options as SettingsTexts does.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, 'post:' ) && 0 === strpos( $path, 'meta/' ) && in_array( get_post_type( (int) substr( $owner, 5 ) ), self::POST_TYPES, true ) ) {
			$key = explode( '/', substr( $path, 5 ) )[0];
			return in_array( $key, self::SERVICE_TEXT_META, true );
		}
		if ( 'options' === $owner && 0 === strpos( $path, 'option/rcb-' ) && in_array( substr( $path, 7 ), self::NO_TEXT_OPTIONS, true ) ) {
			return false;
		}
		$declared = parent::fieldHoldsText( $owner, $path );
		if ( true === $declared && preg_match( '#/@sc\d+/([a-z_]+)$#', $path, $attribute ) && 'content' !== $attribute[1] ) {
			return in_array( $attribute[1], self::SHORTCODE_TEXTS, true );
		}
		return $declared;
	}

	/**
	 * Returns what the editor calls a text of a service, a content blocker, a link or a group.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $owner, 'post:' ) ) {
			$post = get_post( (int) substr( $owner, 5 ) );
			if ( $post instanceof \WP_Post && in_array( $post->post_type, self::POST_TYPES, true ) ) {
				$kinds = array(
					'rcb-cookie'      => __( 'Service', 'swiftling' ),
					'rcb-blocker'     => __( 'Content blocker', 'swiftling' ),
					'rcb-banner-link' => __( 'Link', 'swiftling' ),
				);
				$part  = 'field/post_title' === $path ? __( 'Name', 'swiftling' ) : ( 0 === strpos( $path, 'field/post_content' ) ? __( 'Description', 'swiftling' ) : substr( $path, 5 ) );
				return $kinds[ $post->post_type ] . ': ' . $post->post_title . ' › ' . $part;
			}
			return '';
		}
		if ( 0 === strpos( $owner, 'term:' ) ) {
			$state          = &swiftling_state();
			$guard          = $state['guard'];
			$state['guard'] = true;
			try {
				$term = get_term( (int) substr( $owner, 5 ), self::GROUPS );
			} finally {
				$state['guard'] = $guard;
			}
			if ( ! $term instanceof \WP_Term ) {
				return '';
			}
			/* translators: %s: name of a group of services of a cookie banner. */
			return sprintf( __( 'Service group: %s', 'swiftling' ), $term->name ) . ' › ' . ( 'field/name' === $path ? __( 'Name', 'swiftling' ) : __( 'Description', 'swiftling' ) );
		}
		return parent::fieldLabel( $owner, $path );
	}

	/**
	 * Shows the original of a text of the plugin's settings when its translation leaves out a
	 * placeholder of the original, as {{services}}: the plugin puts the list of services there.
	 *
	 * @param mixed  $value  The option, as SwiftLing's filter gave it: its translation, or false.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function keepPlaceholderTexts( $value, $option = '' ) {
		$state = &swiftling_state();
		if ( $state['guard'] || ! is_string( $value ) || 'translate' !== $state['mode'] || 0 !== strpos( (string) $option, 'rcb-' ) || ! isset( $this->textOptions()[ $option ] ) ) {
			return $value;
		}
		$stored = swiftling_raw_option( $option );
		if ( ! is_string( $stored ) || ! preg_match_all( self::PLACEHOLDER, $stored, $needed ) ) {
			return $value;
		}
		preg_match_all( self::PLACEHOLDER, $value, $kept );
		return array_diff( array_unique( $needed[0] ), $kept[0] ) ? false : $value;
	}

	/**
	 * Keeps the placeholders of the plugin's texts as they are when a text goes to machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) && preg_match_all( self::PLACEHOLDER, $item['text'], $found ) ) {
				$keep                  = isset( $item['keep'] ) && is_array( $item['keep'] ) ? $item['keep'] : array();
				$items[ $key ]['keep'] = array_values( array_unique( array_merge( $keep, $found[0] ) ) );
			}
		}
		return $items;
	}
}
