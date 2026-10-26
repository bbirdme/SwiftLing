<?php
/**
 * Settings of the language switcher, saved on the Switcher screen.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * Settings of the language switcher.
 *
 * Saved in the swiftling_switcher option:
 *
 *     array(
 *         'show'   => array( 'flag' => false, 'name' => true, 'code' => false, 'current' => true, 'flag_size' => 'medium' ),
 *         'menu'   => array( 'enabled' => true, 'menus' => array( 3 ), 'position' => 'last', 'style' => 'dropdown', 'show' => array( … ) ),
 *         'footer' => array( 'enabled' => false, 'position' => 'end', 'style' => 'horizontal', 'show' => array( … ) ),
 *         'flags'  => array( 'en_US' => array( 'country' => 'gb', 'image' => '' ) ),
 *         'suggest' => array(
 *             'enabled'  => false,
 *             'position' => 'bar',
 *             'texts'    => array( 'de_DE' => array( 'text' => 'Auch auf Deutsch.', 'show' => '' ) ),
 *         ),
 *     )
 *
 * "show" is what a switcher shows: the menu and the end of the page each have their own, and the
 * one at the top is for everything else, a block, widget, shortcode or theme that does not ask
 * otherwise. A site saved before the menu and the end of the page had their own shows there what
 * it saved at the top, see show().
 * Flags and the texts of the suggestion are keyed by locale, like translations, so changing the
 * address of a language keeps them. "suggest" is the suggestion of the browser's language, see
 * Suggestion; an empty text is its default.
 */
final class Settings {
	/**
	 * Option name.
	 */
	public const OPTION = 'swiftling_switcher';

	/**
	 * Styles a switcher can have.
	 */
	public const STYLES = array( 'dropdown', 'horizontal', 'vertical' );

	/**
	 * Sizes of flags, relative to the text beside them. Medium is the size flags always had.
	 */
	public const FLAG_SIZES = array( 'small', 'medium', 'large' );

	/**
	 * Places with a "show" of their own, as the tabs of the Switcher screen name them: the menu, the
	 * end of the page, and everything else.
	 */
	public const PLACES = array( 'menu', 'footer', 'elsewhere' );

	/**
	 * Where the footer switcher sits: after the footer of the theme, or floating in a corner.
	 */
	public const FOOTER_POSITIONS = array( 'end', 'floating-right', 'floating-left' );

	/**
	 * Where the suggestion of the browser's language sits: a bar along the bottom of the window,
	 * or a card in a bottom corner.
	 */
	public const SUGGEST_POSITIONS = array( 'bar', 'right', 'left' );

	/**
	 * What happens for a visitor whose browser prefers another language the page is in: nothing,
	 * a note that suggests it, or the home page opened in it the first time, with the note on the
	 * other pages. See Suggestion.
	 */
	public const SUGGEST_MODES = array( 'off', 'suggest', 'open' );

	/**
	 * Settings of this request.
	 *
	 * @var array<string, array>|null
	 */
	private static $settings = null;

	/**
	 * Hooks the Switcher screen's form.
	 */
	public function register(): void {
		add_action( 'admin_post_swiftling_save_switcher', array( $this, 'save' ) );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'forget' ) );
	}

	/**
	 * Returns the settings a site has before anything is saved.
	 *
	 * @return array<string, array>
	 */
	public static function defaults(): array {
		return array(
			'show'    => array(
				'flag'      => false,
				'name'      => true,
				'code'      => false,
				'current'   => true,
				'flag_size' => 'medium',
			),
			'menu'    => array(
				// Off, no menu shows the languages, and the menus ticked are kept. A site saved before
				// the switch has it on.
				'enabled'  => true,
				'menus'    => array(),
				'position' => 'last',
				'style'    => 'dropdown',
			),
			'footer'  => array(
				'enabled'  => false,
				'position' => 'end',
				'style'    => 'horizontal',
			),
			'flags'   => array(),
			'suggest' => array(
				'mode'     => 'off',
				'enabled'  => false,
				'position' => 'bar',
				'texts'    => array(),
			),
		);
	}

	/**
	 * Returns the saved settings, with defaults for anything not saved.
	 *
	 * @return array<string, array>
	 */
	public static function get(): array {
		if ( null === self::$settings ) {
			$saved    = get_option( self::OPTION, array() );
			$saved    = is_array( $saved ) ? $saved : array();
			$settings = self::defaults();
			foreach ( array( 'show', 'menu', 'footer' ) as $group ) {
				if ( isset( $saved[ $group ] ) && is_array( $saved[ $group ] ) ) {
					$settings[ $group ] = array_merge( $settings[ $group ], array_intersect_key( $saved[ $group ], $settings[ $group ] ) );
				}
			}
			$settings['show'] = self::completeShow( isset( $saved['show'] ) ? $saved['show'] : null, $settings['show'] );
			foreach ( array( 'menu', 'footer' ) as $place ) {
				// Saved before the place had its own: what was saved for every switcher.
				$settings[ $place ]['show'] = isset( $saved[ $place ]['show'] ) && is_array( $saved[ $place ]['show'] )
					? self::completeShow( $saved[ $place ]['show'], array_fill_keys( array_keys( $settings['show'] ), false ) )
					: $settings['show'];
			}
			$settings['flags'] = isset( $saved['flags'] ) && is_array( $saved['flags'] ) ? $saved['flags'] : array();
			$suggest           = isset( $saved['suggest'] ) && is_array( $saved['suggest'] ) ? $saved['suggest'] : array();
			// A note saved before there was a choice of modes, as enabled, is the note.
			$mode                = isset( $suggest['mode'] ) ? self::suggestMode( $suggest['mode'] ) : ( empty( $suggest['enabled'] ) ? 'off' : 'suggest' );
			$settings['suggest'] = array(
				'mode'     => $mode,
				'enabled'  => 'off' !== $mode,
				'position' => isset( $suggest['position'] ) && in_array( $suggest['position'], self::SUGGEST_POSITIONS, true ) ? $suggest['position'] : 'bar',
				'texts'    => isset( $suggest['texts'] ) && is_array( $suggest['texts'] ) ? $suggest['texts'] : array(),
			);
			self::$settings      = $settings;
		}
		return self::$settings;
	}

	/**
	 * Returns what a switcher in a place shows, unless it asks otherwise.
	 *
	 * @param string $place menu, footer, or anything else for a block, widget, shortcode or theme.
	 * @return array{flag: bool, name: bool, code: bool, current: bool, flag_size: string}
	 */
	public static function show( string $place ): array {
		$settings = self::get();
		return in_array( $place, array( 'menu', 'footer' ), true ) ? $settings[ $place ]['show'] : $settings['show'];
	}

	/**
	 * Returns a saved "show" with every part: the switches as true or false, and a size of flags
	 * it knows. A part not saved is the fallback's.
	 *
	 * @param mixed                $saved    Saved value.
	 * @param array<string, mixed> $fallback Value of each part not saved; its keys are the parts.
	 * @return array{flag: bool, name: bool, code: bool, current: bool, flag_size: string}
	 */
	private static function completeShow( $saved, array $fallback ): array {
		$saved = is_array( $saved ) ? $saved : array();
		$show  = array();
		foreach ( $fallback as $part => $value ) {
			$show[ $part ] = 'flag_size' === $part
				? self::flagSize( isset( $saved[ $part ] ) ? $saved[ $part ] : '' )
				: (bool) ( isset( $saved[ $part ] ) ? $saved[ $part ] : $value );
		}
		if ( ! isset( $saved['flag_size'] ) && isset( $fallback['flag_size'] ) ) {
			$show['flag_size'] = self::flagSize( $fallback['flag_size'] );
		}
		return $show;
	}

	/**
	 * Forgets the settings read in this request, after they were saved.
	 */
	public static function forget(): void {
		self::$settings = null;
	}

	/**
	 * Returns a mode of the visitor's language if it is one, or off.
	 *
	 * @param mixed $mode Mode asked for.
	 * @return string off, suggest or open.
	 */
	public static function suggestMode( $mode ): string {
		return is_string( $mode ) && in_array( $mode, self::SUGGEST_MODES, true ) ? $mode : 'off';
	}

	/**
	 * Returns a style if it is one a switcher can have, or the fallback.
	 *
	 * @param mixed  $style    Style asked for.
	 * @param string $fallback Style to use otherwise.
	 */
	public static function style( $style, string $fallback = 'dropdown' ): string {
		return is_string( $style ) && in_array( $style, self::STYLES, true ) ? $style : $fallback;
	}

	/**
	 * Returns a size of flags if it is one, or the fallback.
	 *
	 * @param mixed  $size     Size asked for.
	 * @param string $fallback Size to use otherwise.
	 */
	public static function flagSize( $size, string $fallback = 'medium' ): string {
		return is_string( $size ) && in_array( $size, self::FLAG_SIZES, true ) ? $size : $fallback;
	}

	/**
	 * Saves the Switcher screen.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not change the language switcher of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'swiftling_save_switcher' );

		$defaults = self::defaults();
		// Every field as text, then checked against its list below.
		$show    = isset( $_POST['show'] ) && is_array( $_POST['show'] ) ? map_deep( wp_unslash( $_POST['show'] ), 'sanitize_text_field' ) : array();
		$menu    = isset( $_POST['menu'] ) && is_array( $_POST['menu'] ) ? map_deep( wp_unslash( $_POST['menu'] ), 'sanitize_text_field' ) : array();
		$footer  = isset( $_POST['footer'] ) && is_array( $_POST['footer'] ) ? map_deep( wp_unslash( $_POST['footer'] ), 'sanitize_text_field' ) : array();
		$suggest = isset( $_POST['suggest'] ) && is_array( $_POST['suggest'] ) ? map_deep( wp_unslash( $_POST['suggest'] ), 'sanitize_text_field' ) : array();

		$settings = array(
			'show'    => array(),
			'menu'    => array(
				'enabled'  => ! empty( $menu['enabled'] ),
				'menus'    => array(),
				'position' => isset( $menu['position'] ) && 'first' === $menu['position'] ? 'first' : 'last',
				'style'    => isset( $menu['style'] ) && in_array( $menu['style'], array( 'list', 'globe' ), true ) ? $menu['style'] : 'dropdown',
			),
			'footer'  => array(
				'enabled'  => ! empty( $footer['enabled'] ),
				'position' => isset( $footer['position'] ) && in_array( $footer['position'], self::FOOTER_POSITIONS, true ) ? $footer['position'] : 'end',
				'style'    => self::style( isset( $footer['style'] ) ? $footer['style'] : '', 'horizontal' ),
			),
			'flags'   => array(),
			'suggest' => array(
				'mode'     => self::suggestMode( isset( $suggest['mode'] ) ? $suggest['mode'] : '' ),
				'enabled'  => 'off' !== self::suggestMode( isset( $suggest['mode'] ) ? $suggest['mode'] : '' ),
				'position' => isset( $suggest['position'] ) && in_array( $suggest['position'], self::SUGGEST_POSITIONS, true ) ? $suggest['position'] : 'bar',
				'texts'    => array(),
			),
		);

		// A switch left off is not in the form at all.
		$off                        = array_fill_keys( array_keys( $defaults['show'] ), false );
		$settings['show']           = self::completeShow( $show, $off );
		$settings['menu']['show']   = self::completeShow( isset( $menu['show'] ) ? $menu['show'] : null, $off );
		$settings['footer']['show'] = self::completeShow( isset( $footer['show'] ) ? $footer['show'] : null, $off );

		$menus = wp_list_pluck( wp_get_nav_menus(), 'term_id' );
		foreach ( isset( $menu['menus'] ) && is_array( $menu['menus'] ) ? $menu['menus'] : array() as $id ) {
			if ( in_array( (int) $id, array_map( 'intval', $menus ), true ) ) {
				$settings['menu']['menus'][] = (int) $id;
			}
		}

		$languages         = swiftling_languages();
		$locales           = array_merge( array( $languages['source']['locale'] ), wp_list_pluck( $languages['targets'], 'locale' ) );
		$settings['flags'] = Flags::sanitize( Flags::posted( $locales ), $locales );

		foreach ( isset( $suggest['texts'] ) && is_array( $suggest['texts'] ) ? $suggest['texts'] : array() as $locale => $texts ) {
			$locale = sanitize_text_field( (string) $locale );
			if ( ! in_array( $locale, $locales, true ) || ! is_array( $texts ) ) {
				continue;
			}
			$clean = array();
			foreach ( array( 'text', 'show' ) as $field ) {
				$clean[ $field ] = isset( $texts[ $field ] ) ? sanitize_text_field( (string) $texts[ $field ] ) : '';
			}
			if ( array_filter( $clean ) ) {
				$settings['suggest']['texts'][ $locale ] = $clean;
			}
		}

		update_option( self::OPTION, $settings, true );

		// Back on the tab of step 2 the user was on.
		$args = array( 'swiftling_notice' => 'saved' );
		$tab  = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( in_array( $tab, self::PLACES, true ) ) {
			$args['swiftling_tab'] = $tab;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=swiftling-switcher' ) ) );
		exit;
	}
}
