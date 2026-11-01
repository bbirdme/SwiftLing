<?php
/**
 * Popup Maker: its popups in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Popup Maker. Its popups are posts of the type "popup", which visitors never open by their address,
 * so the editor offers no page of them: each popup is a subject of its own on the Templates screen,
 * see subjects(). What SwiftLing does not find by itself:
 *
 * - The plugin prints a popup's content on every page the popup shows on, after it hands it to the
 *   filter pum_popup_content: translated there as the content of a post, under the popup's own object,
 *   see translateContent(), so a popup is translated once for every page.
 * - The title above the content and the text of the close button are in the meta of the popup,
 *   "popup_title" and the setting "close_text" of "popup_settings", read with get_post_meta() as any
 *   meta; every other setting of a popup, such as its triggers, is no text, see fieldHoldsText().
 * - A popup without a close text of its own shows that of its theme, which the plugin keeps for every
 *   theme in the option "pum_all_theme_close_text_cache": translated there, so the plugin writing the
 *   option again from its themes never takes a translation into it.
 *
 * Checked against the code of Popup Maker 1.25 (the free plugin), in tests/PopupMakerTest.php, and by
 * hand on a site with the plugin.
 */
class PopupMaker extends Builder {
	/**
	 * The plugin's type of post of a popup.
	 */
	const POST_TYPE = 'popup';

	/**
	 * The plugin's type of post of a theme of popups.
	 */
	const THEME_TYPE = 'popup_theme';

	/**
	 * The option in which the plugin keeps the close text of every theme, by the theme's ID.
	 */
	const THEME_TEXTS = 'pum_all_theme_close_text_cache';

	/**
	 * The keys of the subject of a popup, "popup-maker:<ID>", and of the close texts of the themes.
	 */
	const SUBJECT        = 'popup-maker:';
	const THEMES_SUBJECT = 'popup-maker-themes';

	/**
	 * How many popups the Templates screen lists.
	 */
	const LIMIT = 200;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'popup-maker';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Popup Maker';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return class_exists( 'Popup_Maker', false ) || defined( 'POPMAKE' ) || ( post_type_exists( self::POST_TYPE ) && post_type_exists( self::THEME_TYPE ) );
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
	 * Hooks where the plugin prints the content of a popup.
	 */
	public function register(): void {
		// Before the plugin runs its blocks, shortcodes and paragraphs over the content (8 and later).
		add_filter( 'pum_popup_content', array( $this, 'translateContent' ), 1, 2 );
	}

	/**
	 * Returns the content of a popup in the mode of the moment.
	 *
	 * @param mixed $content The content, as the popup's post keeps it.
	 * @param mixed $popup   The popup's ID.
	 * @return mixed
	 */
	public function translateContent( $content, $popup = 0 ) {
		if ( ! is_string( $content ) || '' === trim( $content ) || ! $popup ) {
			return $content;
		}
		return swiftling_translate_post_content( swiftling_post_owner( (int) $popup ), $content );
	}

	/**
	 * Returns the subjects of the plugin, for the Templates screen: every popup, and the close texts of
	 * the themes where one is a word.
	 *
	 * @return array
	 */
	public function subjects(): array {
		$subjects = array();
		foreach ( $this->popups() as $popup ) {
			$subjects[] = array(
				'key'         => self::SUBJECT . $popup->ID,
				'title'       => self::title( $popup ),
				'description' => __( 'Popup', 'swiftling' ),
			);
		}
		if ( $this->themeTexts() ) {
			$subjects[] = array(
				'key'         => self::THEMES_SUBJECT,
				'title'       => __( 'Popup themes', 'swiftling' ),
				'description' => __( 'Close buttons', 'swiftling' ),
			);
		}
		if ( ! $subjects ) {
			return array();
		}
		return array(
			array(
				'title'       => $this->name(),
				'description' => __( 'Popups, shown on the pages their conditions choose.', 'swiftling' ),
				'subjects'    => $subjects,
			),
		);
	}

	/**
	 * Returns a subject of the editor: a popup, or the close texts of the themes.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		if ( self::THEMES_SUBJECT === $key ) {
			return array(
				'key'   => $key,
				'kind'  => 'fields',
				'title' => __( 'Popup themes', 'swiftling' ),
				'type'  => $this->name(),
				'url'   => '',
				'probe' => false,
				'post'  => null,
				'cap'   => 'manage_options',
				'note'  => __( 'The text of the close button of each theme, shown by every popup of the theme without a close text of its own.', 'swiftling' ),
			);
		}
		$popup = $this->popup( $key );
		if ( null === $popup ) {
			return null;
		}
		$type = get_post_type_object( self::POST_TYPE );
		return array(
			'key'   => self::SUBJECT . $popup->ID,
			'kind'  => 'fields',
			'title' => self::title( $popup ),
			'type'  => __( 'Popup', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => $type && isset( $type->cap->edit_posts ) ? (string) $type->cap->edit_posts : 'manage_options',
			'note'  => __( 'The title, content and close button of this popup. It is translated once and shows translated on every page in the language.', 'swiftling' ),
		);
	}

	/**
	 * Returns the texts of a popup, or of the themes, read as a page in a language reads them.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( self::THEMES_SUBJECT === $subject['key'] ) {
			return $this->collect(
				static function (): void {
					get_option( self::THEME_TEXTS );
				}
			);
		}
		$popup = $this->popup( (string) $subject['key'] );
		if ( null === $popup ) {
			return array();
		}
		return $this->collect(
			function () use ( $popup ): void {
				get_post_meta( $popup->ID, 'popup_title', true );
				$this->translateContent( $popup->post_content, $popup->ID );
				get_post_meta( $popup->ID, 'popup_settings', true );
			}
		);
	}

	/**
	 * Declares the title and the close text of a popup and the close texts of the themes as text, and
	 * every other setting of the plugin's posts as none.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 'options' === $owner ) {
			// The option holds texts, although its name looks like a cache's; its values are the texts.
			$prefix = 'option/' . self::THEME_TEXTS;
			return $prefix === $path || 0 === strpos( $path, $prefix . '/' ) ? $prefix === $path || 1 === preg_match( '#^' . preg_quote( $prefix, '#' ) . '/\d+$#', $path ) : null;
		}
		if ( 0 !== strpos( $owner, 'post:' ) || 0 !== strpos( $path, 'meta/' ) || ! in_array( get_post_type( (int) substr( $owner, 5 ) ), array( self::POST_TYPE, self::THEME_TYPE ), true ) ) {
			return null;
		}
		if ( 'meta/popup_title' === $path || 'meta/popup_settings/close_text' === $path ) {
			return true;
		}
		// The plugin's own meta, such as the settings, the theme's settings and the counts of openings.
		return 0 === strpos( $path, 'meta/popup_' ) || 0 === strpos( $path, 'meta/enabled' ) || 0 === strpos( $path, 'meta/data_version' ) ? false : null;
	}

	/**
	 * Returns what the editor calls a text of a popup or of a theme.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 'options' === $owner && preg_match( '#^option/' . preg_quote( self::THEME_TEXTS, '#' ) . '/(\d+)$#', $path, $found ) ) {
			$theme = get_post( (int) $found[1] );
			/* translators: %s: name of a theme of popups. */
			return sprintf( __( 'Theme: %s', 'swiftling' ), $theme instanceof \WP_Post ? $theme->post_title : $found[1] ) . ' › ' . __( 'Close button', 'swiftling' );
		}
		if ( 0 !== strpos( $owner, 'post:' ) || self::POST_TYPE !== get_post_type( (int) substr( $owner, 5 ) ) ) {
			return '';
		}
		$labels = array(
			'meta/popup_title'               => __( 'Title', 'swiftling' ),
			'meta/popup_settings/close_text' => __( 'Close button', 'swiftling' ),
		);
		if ( isset( $labels[ $path ] ) ) {
			return $labels[ $path ];
		}
		return self::isContent( $path ) ? __( 'Content', 'swiftling' ) : '';
	}

	/**
	 * Whether a path is of the content of a popup: the content whole, or a block of it.
	 *
	 * @param string $path Path of the value inside its object.
	 */
	private static function isContent( string $path ): bool {
		return 0 === strpos( $path, 'field/post_content' ) || 0 === strpos( $path, 'b/' );
	}

	/**
	 * Reads texts as a page check would, see swiftling_collect(), and returns them for the editor.
	 *
	 * @param callable $read Reads the texts.
	 * @return array<int, array{o: string, p: string, v: string, r: string, kind: string, place: string}>
	 */
	private function collect( callable $read ): array {
		$texts = array();
		foreach ( swiftling_collect( $read ) as $leaf ) {
			if ( true !== $this->fieldHoldsText( $leaf['o'], $leaf['p'] ) && ! self::isContent( $leaf['p'] ) ) {
				continue;
			}
			// A sign written as an entity, as the close text "&times;", has letters but no words.
			if ( ! preg_match( '/\p{L}/u', html_entity_decode( $leaf['v'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
				continue;
			}
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => self::isContent( $leaf['p'] ) ? 'paragraph' : 'text',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns the close texts of the themes that are words, by the theme's ID.
	 *
	 * @return array<int, string>
	 */
	private function themeTexts(): array {
		$texts = swiftling_raw_option( self::THEME_TEXTS, array() );
		return is_array( $texts ) ? array_filter(
			$texts,
			static function ( $text ): bool {
				return is_string( $text ) && 1 === preg_match( '/\p{L}/u', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		) : array();
	}

	/**
	 * Returns the popups, by title. A popup a person is still writing counts: it can be translated
	 * before it shows.
	 *
	 * @return \WP_Post[]
	 */
	private function popups(): array {
		return get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => self::LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Returns the popup a subject key names.
	 *
	 * @param string $key Subject key.
	 */
	private function popup( string $key ): ?\WP_Post {
		$rest = 0 === strpos( $key, self::SUBJECT ) ? substr( $key, strlen( self::SUBJECT ) ) : '';
		$post = ctype_digit( $rest ) ? get_post( (int) $rest ) : null;
		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ? $post : null;
	}

	/**
	 * Returns the name of a popup as its list of popups shows it.
	 *
	 * @param \WP_Post $popup The popup.
	 */
	private static function title( \WP_Post $popup ): string {
		return '' !== trim( $popup->post_title ) ? $popup->post_title : __( 'No title', 'swiftling' );
	}
}
