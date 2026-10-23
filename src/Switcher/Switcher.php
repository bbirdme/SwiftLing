<?php
/**
 * The language switcher: the languages of the page being shown and their HTML.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * The language switcher: the languages of the page being shown and their HTML.
 *
 * Every place a switcher can sit uses this class: the block, the shortcode, the widget, the PHP
 * function for themes, the footer and the menu. Each link opens the page being shown in another
 * language, at the address urls.php gives it.
 *
 * The switcher carries translate="no": language names stay in their own language, so neither the
 * translation editor nor a browser's translation offers them for translation.
 */
final class Switcher {
	/**
	 * Registers the stylesheet and script of the switcher, for the site and the editors.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'registerAssets' ), 5 );
	}

	/**
	 * Registers the stylesheet and script. They load only where a switcher is printed.
	 */
	public static function registerAssets(): void {
		wp_register_style( 'swiftling-switcher', SWIFTLING_URL . 'assets/css/switcher.css', array(), SWIFTLING_VERSION );
		wp_register_script(
			'swiftling-switcher',
			SWIFTLING_URL . 'assets/js/switcher.js',
			array(),
			SWIFTLING_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Returns the options of a switcher, completed with the saved settings.
	 *
	 * @param array<string, mixed> $args Keys: style (dropdown, horizontal, vertical), flag, name, code,
	 *                                   current (whether a list shows the language being shown), class
	 *                                   and flag_size (small, medium, large). Also place: menu or footer
	 *                                   for those switchers, whose parts the Switcher screen sets
	 *                                   apart from every other one's.
	 * @return array{style: string, flag: bool, name: bool, code: bool, current: bool, class: string, flag_size: string}
	 */
	public static function args( array $args ): array {
		$show = Settings::show( isset( $args['place'] ) ? (string) $args['place'] : '' );
		$args = array(
			'style'     => Settings::style( isset( $args['style'] ) ? $args['style'] : '' ),
			'flag'      => isset( $args['flag'] ) ? (bool) $args['flag'] : $show['flag'],
			'name'      => isset( $args['name'] ) ? (bool) $args['name'] : $show['name'],
			'code'      => isset( $args['code'] ) ? (bool) $args['code'] : $show['code'],
			'current'   => isset( $args['current'] ) ? (bool) $args['current'] : $show['current'],
			'class'     => isset( $args['class'] ) ? (string) $args['class'] : '',
			'flag_size' => isset( $args['flag_size'] ) ? Settings::flagSize( $args['flag_size'] ) : $show['flag_size'],
		);
		// A switcher showing nothing would be a row of empty links.
		if ( ! $args['flag'] && ! $args['name'] && ! $args['code'] ) {
			$args['name'] = true;
		}
		return $args;
	}

	/**
	 * Returns the languages a visitor can switch to on the page being shown, in the order the
	 * Languages screen sets.
	 *
	 * A hidden language is in the list only for people who can open it, those who can edit
	 * content, and is marked as hidden. On a post that is hidden in a language because it is not
	 * translated, that language is left out: the post has no page there.
	 *
	 * @return array<int, array{slug: string, locale: string, tag: string, name: string, translated: string, code: string, url: string, flag: string, current: bool, hidden: bool}>
	 */
	public static function languages(): array {
		$state   = &swiftling_state();
		$shown   = 'translate' === swiftling_mode() ? $state['language'] : '';
		$editor  = current_user_can( 'edit_posts' );
		$post    = is_singular() ? get_queried_object() : null;
		$ordered = swiftling_ordered_languages();
		$page    = swiftling_page_language();

		$list = array();
		foreach ( $ordered as $language ) {
			if ( $language['source'] ) {
				$list[] = self::item( '', $language, '' === $shown, false, $page, $ordered );
				continue;
			}
			$slug = (string) $language['slug'];
			// A language whose host does not lead here yet is not on the site, for editors either.
			if ( ( ! $language['visible'] && ! $editor ) || ! $language['reachable'] ) {
				continue;
			}
			if ( $post instanceof \WP_Post && $slug !== $shown && swiftling_is_hidden_post( $post, $language['locale'] ) ) {
				continue;
			}
			$list[] = self::item( $slug, $language, $slug === $shown, ! $language['visible'], $page, $ordered );
		}
		return $list;
	}

	/**
	 * Returns one language of the list.
	 *
	 * @param string               $slug     Address slug, empty for the original.
	 * @param array<string, mixed> $language Language from swiftling_ordered_languages().
	 * @param bool                 $current  Whether the page is shown in it.
	 * @param bool                 $hidden   Whether visitors cannot see it.
	 * @param array<string, mixed> $page     Language the page is shown in.
	 * @param array<int, array>    $all      Every language, to tell regional variants apart.
	 * @return array<string, mixed>
	 */
	private static function item( string $slug, array $language, bool $current, bool $hidden, array $page, array $all ): array {
		return array(
			'slug'       => $slug,
			'locale'     => (string) $language['locale'],
			'tag'        => str_replace( '_', '-', (string) $language['locale'] ),
			'name'       => '' !== $language['label'] ? (string) $language['label'] : (string) $language['name'],
			'translated' => self::translatedName( $language, $page, $all ),
			'code'       => (string) $language['code'],
			'url'        => swiftling_current_url( $slug ),
			'flag'       => Flags::url( (string) $language['locale'] ),
			'current'    => $current,
			'hidden'     => $hidden,
		);
	}

	/**
	 * Returns the name of a language in the language of the page, when the Languages screen asks
	 * for it: "Njemački" for Deutsch on a Croatian page. A name the user wrote for the language in
	 * the language of the page comes first, also for a language intl does not know. Otherwise intl
	 * names it, unless the user wrote the language a name of its own for the switcher. The language
	 * of the page needs no second name.
	 *
	 * @param array<string, mixed> $language Language.
	 * @param array<string, mixed> $page     Language the page is shown in.
	 * @param array<int, array>    $all      Every language: two of the same language are named with their country.
	 * @return string Empty when the switcher shows only the name in the language itself.
	 */
	private static function translatedName( array $language, array $page, array $all ): string {
		if ( 'native' === swiftling_languages()['names'] || $language['locale'] === $page['locale'] ) {
			return '';
		}
		$written = swiftling_language_name_in( (string) $language['locale'], (string) $page['locale'] );
		if ( '' !== $written ) {
			return $written;
		}
		if ( '' !== $language['label'] ) {
			return '';
		}
		$base    = strtolower( (string) strtok( (string) $language['locale'], '_' ) );
		$sharing = 0;
		foreach ( $all as $other ) {
			if ( strtolower( (string) strtok( (string) $other['locale'], '_' ) ) === $base ) {
				++$sharing;
			}
		}
		$name = swiftling_translated_language_name( (string) $language['locale'], (string) $page['wp_locale'], $sharing > 1 );
		return $name !== $language['name'] ? $name : '';
	}

	/**
	 * Enqueues what a switcher of a style needs: the stylesheet, and the script of a dropdown.
	 *
	 * A switcher printed in the content enqueues them while it renders, in time for the footer.
	 * One printed at the very end of the page comes too late for that and enqueues them before,
	 * see Footer::enqueue().
	 *
	 * @param string $style dropdown, horizontal or vertical.
	 */
	public static function enqueue( string $style ): void {
		wp_enqueue_style( 'swiftling-switcher' );
		if ( 'dropdown' === $style ) {
			wp_enqueue_script( 'swiftling-switcher' );
		}
	}

	/**
	 * Returns the HTML of a switcher, or an empty string when there is nothing to switch to.
	 *
	 * @param array<string, mixed> $args       See args().
	 * @param string               $attributes Attributes of the outer element, as a block builds them; replaces the class.
	 */
	public static function render( array $args = array(), string $attributes = '' ): string {
		$args      = self::args( $args );
		$languages = self::languages();
		if ( count( $languages ) < 2 ) {
			return '';
		}

		self::enqueue( $args['style'] );
		$class = trim( 'swiftling-switcher is-' . $args['style'] . ' ' . $args['class'] ) . ( self::flagOnly( $args ) ? ' is-flag-only' : '' );
		if ( '' === $attributes ) {
			$attributes = 'class="' . esc_attr( $class ) . '"';
		} else {
			// A block's own class list comes first; the switcher's classes join it.
			$attributes = (string) preg_replace( '/\bclass="/', 'class="' . esc_attr( $class ) . ' ', $attributes, 1, $count );
			if ( ! $count ) {
				$attributes .= ' class="' . esc_attr( $class ) . '"';
			}
		}

		if ( 'dropdown' === $args['style'] ) {
			$current = wp_list_filter( $languages, array( 'current' => true ) );
			$current = $current ? reset( $current ) : $languages[0];
			$others  = wp_list_filter( $languages, array( 'current' => false ) );

			$html  = '<details class="swiftling-switcher__dropdown">';
			$html .= '<summary class="swiftling-switcher__toggle"' . self::languageAttributes( $current, false ) . '>' . self::label( $current, $args ) . '</summary>';
			$html .= '<ul class="swiftling-switcher__menu">';
			foreach ( $others as $language ) {
				$html .= '<li class="' . esc_attr( self::itemClass( $language ) ) . '">' . self::link( $language, $args ) . '</li>';
			}
			$html .= '</ul></details>';
		} else {
			$html = '<ul class="swiftling-switcher__list">';
			foreach ( $languages as $language ) {
				if ( $language['current'] && ! $args['current'] ) {
					continue;
				}
				$html .= '<li class="' . esc_attr( self::itemClass( $language ) ) . '">' . self::link( $language, $args ) . '</li>';
			}
			$html .= '</ul>';
		}

		/**
		 * Filters the HTML of a language switcher, so another plugin can add to it, as SwiftLing Pro
		 * adds its currency switcher beside it. See docs/hooks.md.
		 *
		 * @param string $html The switcher.
		 * @param array  $args Its options, see args().
		 */
		return (string) apply_filters( 'swiftling_switcher_html', '<div ' . $attributes . ' translate="no">' . $html . '</div>', $args );
	}

	/**
	 * Returns the globe of a switcher: lines in the colour of the text around it, for eyes only.
	 *
	 * @param int    $size       Width and height in pixels.
	 * @param string $class_name Class of the icon.
	 */
	public static function globe( int $size = 18, string $class_name = 'swiftling-switcher__globe' ): string {
		return sprintf(
			'<svg class="%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.8 3.8 5.8 3.8 9s-1.3 6.2-3.8 9c-2.5-2.8-3.8-5.8-3.8-9S9.5 5.8 12 3z"/></svg>',
			esc_attr( $class_name ),
			$size
		);
	}

	/**
	 * Whether a switcher shows only flags. Its dropdown is then as narrow as a flag; a language
	 * without a flag shows its code, which is short too.
	 *
	 * @param array<string, mixed> $args Options from args().
	 */
	public static function flagOnly( array $args ): bool {
		return $args['flag'] && ! $args['name'] && ! $args['code'];
	}

	/**
	 * Returns what a language shows: its flag, name and code, as the switcher asks.
	 *
	 * @param array<string, mixed> $language Language from languages().
	 * @param array<string, mixed> $args     Options from args().
	 */
	public static function label( array $language, array $args ): string {
		$flag = $args['flag'] && '' !== $language['flag'];
		$name = $args['name'];
		// Without its flag, a language that shows only a flag shows its code instead.
		$code = $args['code'] || ( ! $flag && ! $name );

		$html       = '';
		$mode       = swiftling_languages()['names'];
		$translated = isset( $language['translated'] ) ? (string) $language['translated'] : '';
		$native     = '<span lang="' . esc_attr( $language['tag'] ) . '">' . esc_html( $language['name'] ) . '</span>';

		if ( $flag ) {
			// The size is on the flag itself, so it holds also in a menu, where the theme draws the item.
			// The stylesheet sizes it with the text beside it; the width and height in pixels keep its
			// place while the stylesheet loads.
			$size   = isset( $args['flag_size'] ) ? Settings::flagSize( $args['flag_size'] ) : 'medium';
			$pixels = array(
				'small'  => array( 15, 10 ),
				'medium' => array( 18, 12 ),
				'large'  => array( 27, 18 ),
			);
			$html  .= sprintf(
				'<img class="swiftling-switcher__flag%s" src="%s" alt="%s" width="%d" height="%d" decoding="async">',
				'medium' === $size ? '' : ' is-' . $size,
				esc_url( $language['flag'] ),
				// The image is the only label of the link when no name or code is shown.
				esc_attr( $name || $code ? '' : ( 'page' === $mode && '' !== $translated ? $translated : $language['name'] ) ),
				$pixels[ $size ][0],
				$pixels[ $size ][1]
			);
		}
		if ( $name ) {
			// A name in the language itself carries its lang; one in the page's language needs none.
			if ( '' === $translated ) {
				$inner = $native;
			} elseif ( 'page' === $mode ) {
				$inner = esc_html( $translated );
			} else {
				$inner = $native . ' <span class="swiftling-switcher__translated">(' . esc_html( $translated ) . ')</span>';
			}
			$html .= '<span class="swiftling-switcher__name">' . $inner . '</span>';
		}
		if ( $code ) {
			$html .= '<span class="swiftling-switcher__code"' . ( $name ? ' aria-hidden="true"' : '' ) . '>' . esc_html( $language['code'] ) . '</span>';
		}
		return $html;
	}

	/**
	 * Returns the link to a language.
	 *
	 * @param array<string, mixed> $language Language from languages().
	 * @param array<string, mixed> $args     Options from args().
	 */
	private static function link( array $language, array $args ): string {
		return sprintf(
			'<a class="swiftling-switcher__link" href="%s"%s%s>%s</a>',
			esc_url( $language['url'] ),
			self::languageAttributes( $language ),
			$language['current'] ? ' aria-current="true"' : '',
			self::label( $language, $args )
		);
	}

	/**
	 * Returns the attributes that tell which language a link or button is for, and the note on a
	 * hidden language. The lang of the name is on the name itself, see label().
	 *
	 * @param array<string, mixed> $language Language from languages().
	 * @param bool                 $note     Whether to add the note on a hidden language.
	 */
	public static function languageAttributes( array $language, bool $note = true ): string {
		$attributes = ' data-locale="' . esc_attr( $language['tag'] ) . '"';
		if ( $note && $language['hidden'] ) {
			$attributes .= ' title="' . esc_attr__( 'Hidden from visitors. You see it because you can edit content.', 'swiftling' ) . '"';
		}
		return $attributes;
	}

	/**
	 * Returns the classes of a language in a list.
	 *
	 * @param array<string, mixed> $language Language from languages().
	 */
	private static function itemClass( array $language ): string {
		return 'swiftling-switcher__item' . ( $language['current'] ? ' is-current' : '' ) . ( $language['hidden'] ? ' is-hidden-language' : '' );
	}
}
