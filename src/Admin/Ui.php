<?php
/**
 * Small building blocks shared by the SwiftLing admin screens.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin;

use SwiftLing\Switcher\Flags;

defined( 'ABSPATH' ) || exit;

/**
 * Small building blocks shared by the SwiftLing admin screens.
 */
final class Ui {
	/**
	 * The SwiftLing swift, as the path of an SVG drawing in a box of 64.86 by 36.57: the menu's icon,
	 * the sticker of a tip and the flock above a group of screens draw it.
	 */
	public const BIRD = 'M32.43 0C33.81 0 35.19 2.07 35.88 4.83C43.47 2.76 56.58 5.52 64.86 19.32C56.58 15.18 44.16 13.11 36.91 17.25C36.91 20.7 36.57 22.77 35.88 24.84L40.02 36.57L32.43 28.29L24.84 36.57L28.98 24.84C28.29 22.77 27.95 20.7 27.95 17.25C20.7 13.11 8.28 15.18 0 19.32C8.28 5.52 21.39 2.76 28.98 4.83C29.67 2.07 31.05 0 32.43 0Z';

	/**
	 * The inline SVG icons of the screens, for wp_kses(): notices, the switcher's globe and the
	 * offers other plugins print on them, as SwiftLing Pro's import.
	 */
	public const ICON_HTML = array(
		'svg'    => array(
			'class'        => true,
			'width'        => true,
			'height'       => true,
			'viewbox'      => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
			'aria-hidden'  => true,
			'focusable'    => true,
		),
		'path'   => array(
			'd'               => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
		'circle' => array(
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'fill'   => true,
			'stroke' => true,
		),
	);

	/**
	 * Prints the screen header: breadcrumb, title, optional description and action buttons.
	 *
	 * On a screen of a group, the group's header above is the page's title and says where it is, see
	 * Menu::renderScreen(). This header is then the header of the screen's part of the page: its
	 * title one level down, and its path only on a screen deeper than the group's list, such as one
	 * menu of Menus, where the crumb names the screen of the list it belongs to.
	 *
	 * @param array<string, mixed> $args Keys: crumb (label after "SwiftLing"), title, description (optional),
	 *                    actions (optional list of array( label, variant, url ) buttons; url is optional).
	 */
	public static function screenHeader( array $args ): void {
		$args    = wp_parse_args(
			$args,
			array(
				'crumb'       => '',
				'title'       => '',
				'description' => '',
				'actions'     => array(),
			)
		);
		$group   = Menu::renderingGroup();
		$section = '' !== $group;
		?>
		<header class="swiftling-header<?php echo $section ? ' is-section' : ''; ?>">
			<div>
				<?php if ( ! $section ) : ?>
					<p class="swiftling-breadcrumb">
						<img src="<?php echo esc_url( SWIFTLING_URL . 'assets/images/bird.svg' ); ?>" alt="" width="20" height="11">
						<span class="swiftling-breadcrumb__brand">SwiftLing</span>
						<span aria-hidden="true">/</span>
						<?php echo esc_html( $args['crumb'] ); ?>
					</p>
					<h1 class="swiftling-title"><?php echo esc_html( $args['title'] ); ?></h1>
				<?php else : ?>
					<?php if ( Menu::groups()[ $group ]['name'] !== $args['crumb'] ) : ?>
						<p class="swiftling-section-crumb"><?php echo esc_html( $args['crumb'] ); ?></p>
					<?php endif; ?>
					<h2 class="swiftling-title"><?php echo esc_html( $args['title'] ); ?></h2>
				<?php endif; ?>
				<?php if ( $args['description'] ) : ?>
					<p class="swiftling-description"><?php echo esc_html( $args['description'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $args['actions'] ) : ?>
				<div class="swiftling-header__actions">
					<?php
					foreach ( $args['actions'] as $action ) {
						self::button( $action[0], $action[1], $action[2] ?? '' );
					}
					?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}

	/**
	 * Prints the icon of a kind of translation service, drawn by SwiftLing: a translation for
	 * machine translation, a spark for AI. Never a logo of a service: Google's attribution rules
	 * forbid its logo next to competitors', and wordpress.org asks every image to be GPL.
	 *
	 * Each service gets a tint of SwiftLing's own, the same every time for its ID, so services of a
	 * kind tell apart without taking a service's colours.
	 *
	 * @param string $kind machine or ai, see Service::kind().
	 * @param string $id   ID of the service, e.g. "deepl".
	 */
	public static function serviceIcon( string $kind, string $id = '' ): void {
		$paths = array(
			'machine' => 'M4 5h8M8 5V3.5M5.5 11c2.6-1.3 4.6-3.6 5.3-6M6.8 7.8c.9 1.7 2.3 3 4 3.7M13 20l3.6-8.5L20.2 20M14.3 17.2h4.6',
			'ai'      => 'M11 3.5l1.9 5.1 5.1 1.9-5.1 1.9L11 17.5l-1.9-5.1L4 10.5l5.1-1.9zM18 14.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z',
		);
		$kind  = isset( $paths[ $kind ] ) ? $kind : 'machine';
		$tints = array( 'blue', 'teal', 'violet', 'amber', 'green' );
		$tint  = '' !== $id ? $tints[ abs( crc32( $id ) ) % count( $tints ) ] : ( 'ai' === $kind ? 'violet' : 'blue' );
		printf(
			'<span class="swiftling-service__icon is-%1$s is-%2$s" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="%3$s"/></svg></span>',
			esc_attr( $kind ),
			esc_attr( $tint ),
			esc_attr( $paths[ $kind ] )
		);
	}

	/**
	 * Prints a notice: a tinted card with a round sticker, a bold first line, its text and the
	 * buttons of where it leads. Every notice of the SwiftLing screens comes from here, so they all
	 * look the same; a test fails when one is written by hand.
	 *
	 * Kinds: success (a tick), info (a tip, with the SwiftLing bird), warning (!) and error (×). An
	 * error is announced at once, the others when the reader gets to them.
	 *
	 * @param string               $kind success, info, warning or error; anything else is info.
	 * @param array<string, mixed> $args Keys: title (the bold first line), text (plain text), html
	 *                    (text with links or emphasis, instead of text), link (array( label, url ),
	 *                    a link after the text), actions (list of array( label, url ) buttons),
	 *                    compact (true for a notice inside a card) and class (more classes).
	 */
	public static function notice( string $kind, array $args ): void {
		$args = wp_parse_args(
			$args,
			array(
				'title'   => '',
				'text'    => '',
				'html'    => '',
				'link'    => array(),
				'actions' => array(),
				'compact' => false,
				'class'   => '',
			)
		);
		// Links to screens the user may not open are left out.
		if ( $args['link'] && ! self::opens( (string) $args['link'][1] ) ) {
			$args['link'] = array();
		}
		$args['actions'] = array_values(
			array_filter(
				(array) $args['actions'],
				static function ( $action ) {
					return self::opens( (string) $action[1] );
				}
			)
		);
		$kind            = in_array( $kind, array( 'success', 'info', 'warning', 'error' ), true ) ? $kind : 'info';
		$classes         = trim( 'swiftling-notice is-' . $kind . ( $args['compact'] ? ' is-compact' : '' ) . ' ' . $args['class'] );
		$icons           = array(
			'success' => '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3.5 8.4l2.9 2.9L12.5 5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'info'    => '<svg viewBox="0 0 64.86 36.57" aria-hidden="true" focusable="false"><path fill="currentColor" d="' . self::BIRD . '"/></svg>',
			'warning' => '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 3.5v5.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><circle cx="8" cy="12.3" r="1.3" fill="currentColor"/></svg>',
			'error'   => '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4.5 4.5l7 7M11.5 4.5l-7 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>',
		);
		$allowed         = array(
			'a'      => array( 'href' => true ),
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
		);
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" role="<?php echo 'error' === $kind ? 'alert' : 'status'; ?>">
			<span class="swiftling-notice__icon"><?php echo wp_kses( $icons[ $kind ], self::ICON_HTML ); ?></span>
			<div class="swiftling-notice__body">
				<?php if ( '' !== $args['title'] ) : ?>
					<p class="swiftling-notice__title"><?php echo esc_html( $args['title'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $args['text'] || '' !== $args['html'] || $args['link'] ) : ?>
					<p class="swiftling-notice__text">
						<?php echo '' !== $args['html'] ? wp_kses( $args['html'], $allowed ) : esc_html( $args['text'] ); ?>
						<?php if ( $args['link'] ) : ?>
							<a href="<?php echo esc_url( $args['link'][1] ); ?>"><?php echo esc_html( $args['link'][0] ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<?php if ( $args['actions'] ) : ?>
					<p class="swiftling-notice__actions">
						<?php foreach ( $args['actions'] as $action ) : ?>
							<a class="swiftling-notice__action" href="<?php echo esc_url( $action[1] ); ?>"><?php echo esc_html( $action[0] ); ?></a>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Whether the current user may open an address: false only for a SwiftLing screen whose right
	 * they do not have, as the settings for a translator. Any other address may open.
	 *
	 * @param string $url Address.
	 */
	public static function opens( string $url ): bool {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		wp_parse_str( $query, $args );
		$page = isset( $args['page'] ) && is_string( $args['page'] ) ? $args['page'] : '';
		if ( 0 !== strpos( $page, 'swiftling' ) || false === strpos( $url, 'admin.php' ) ) {
			return true;
		}
		$screens = Menu::screens();
		return isset( $screens[ $page ] ) ? current_user_can( Menu::capability( $screens[ $page ] ) ) : current_user_can( 'manage_options' );
	}

	/**
	 * Prints a button, or a link styled as a button when a URL is given.
	 *
	 * @param string $label   Button text.
	 * @param string $variant One of primary, accent, secondary, link, danger, danger-solid.
	 * @param string $url     Optional link target.
	 * @param string $icon    Optional Dashicon name without the "dashicons-" prefix.
	 */
	public static function button( string $label, string $variant = 'secondary', string $url = '', string $icon = '' ): void {
		// A link to a screen the user may not open is left out, as one to the settings for a translator.
		if ( '' !== $url && ! self::opens( $url ) ) {
			return;
		}
		$class = 'swiftling-button is-' . $variant;
		if ( $url ) {
			printf( '<a class="%s" href="%s">', esc_attr( $class ), esc_url( $url ) );
		} else {
			printf( '<button type="button" class="%s">', esc_attr( $class ) );
		}
		if ( $icon ) {
			printf( '<span class="dashicons dashicons-%s" aria-hidden="true"></span>', esc_attr( $icon ) );
		}
		echo esc_html( $label );
		echo $url ? '</a>' : '</button>';
	}

	/**
	 * Prints the tools of a long list in the translation editor: which of its entries show, a search,
	 * and the pages of the list. Each is a link or a form that reloads the editor.
	 *
	 * @param array<string, mixed> $args Keys: subject (key of the editor's subject), lang (language
	 *                    slug), shows (optional, show => array( label, count )), show (the one shown),
	 *                    search (words searched for), placeholder (of the search field), page, pages,
	 *                    per_page, shown (entries found) and keep (optional, other values of the
	 *                    editor's address every link and the search keep, such as the page whose
	 *                    comments show).
	 */
	public static function listFilters( array $args ): void {
		$args = wp_parse_args(
			$args,
			array(
				'shows'       => array(),
				'show'        => '',
				'keep'        => array(),
				'search'      => '',
				'placeholder' => __( 'Search texts', 'swiftling' ),
				'page'        => 1,
				'pages'       => 1,
				'per_page'    => 1,
				'shown'       => 0,
			)
		);
		$base = swiftling_editor_url( $args['subject'], $args['lang'] );
		$link = static function ( array $more ) use ( $base, $args ) {
			$query = array_merge(
				$args['keep'],
				array(
					'show' => $args['show'],
					'find' => $args['search'],
				),
				$more
			);
			return add_query_arg( array_map( 'rawurlencode', array_filter( array_map( 'strval', $query ) ) ), $base );
		};
		?>
		<div class="swiftling-list-filters">
			<?php if ( $args['shows'] ) : ?>
				<div class="swiftling-list-filters__shows" role="group" aria-label="<?php esc_attr_e( 'Show', 'swiftling' ); ?>">
					<?php foreach ( $args['shows'] as $show => $entry ) : ?>
						<a class="swiftling-filter-pill<?php echo $show === $args['show'] ? ' is-current' : ''; ?>" href="<?php echo esc_url( $link( array( 'show' => $show ) ) ); ?>"<?php echo $show === $args['show'] ? ' aria-current="true"' : ''; ?>>
							<?php echo esc_html( $entry[0] ); ?>
							<span class="swiftling-filter-pill__count"><?php echo esc_html( number_format_i18n( $entry[1] ) ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="swiftling-list-filters__search" role="search">
				<input type="hidden" name="page" value="swiftling-translation">
				<input type="hidden" name="subject" value="<?php echo esc_attr( $args['subject'] ); ?>">
				<input type="hidden" name="lang" value="<?php echo esc_attr( $args['lang'] ); ?>">
				<?php if ( '' !== $args['show'] ) : ?>
					<input type="hidden" name="show" value="<?php echo esc_attr( $args['show'] ); ?>">
				<?php endif; ?>
				<?php foreach ( $args['keep'] as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
				<?php endforeach; ?>
				<label class="screen-reader-text" for="swiftling-list-find"><?php echo esc_html( $args['placeholder'] ); ?></label>
				<input type="search" id="swiftling-list-find" name="find" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>">
				<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Search', 'swiftling' ); ?></button>
				<?php if ( '' !== $args['search'] ) : ?>
					<a class="swiftling-button is-link" href="<?php echo esc_url( $link( array( 'find' => '' ) ) ); ?>"><?php esc_html_e( 'Show all', 'swiftling' ); ?></a>
				<?php endif; ?>
			</form>
		</div>
		<?php if ( $args['pages'] > 1 ) : ?>
			<nav class="swiftling-list-pages" aria-label="<?php esc_attr_e( 'Pages of the list', 'swiftling' ); ?>">
				<span class="swiftling-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: first entry shown, 2: last entry shown, 3: all entries in the list. */
							__( '%1$s–%2$s of %3$s', 'swiftling' ),
							number_format_i18n( ( $args['page'] - 1 ) * $args['per_page'] + 1 ),
							number_format_i18n( min( $args['shown'], $args['page'] * $args['per_page'] ) ),
							number_format_i18n( $args['shown'] )
						)
					);
					?>
				</span>
				<?php if ( $args['page'] > 1 ) : ?>
					<a class="swiftling-button is-secondary" href="<?php echo esc_url( $link( array( 'paged' => $args['page'] - 1 ) ) ); ?>"><?php esc_html_e( 'Previous', 'swiftling' ); ?></a>
				<?php endif; ?>
				<?php if ( $args['page'] < $args['pages'] ) : ?>
					<a class="swiftling-button is-secondary" href="<?php echo esc_url( $link( array( 'paged' => $args['page'] + 1 ) ) ); ?>"><?php esc_html_e( 'Next', 'swiftling' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
		<?php
	}

	/**
	 * Labels for the kinds of text in the translation editor.
	 *
	 * @return array<string, string>
	 */
	public static function partLabels(): array {
		return array(
			'heading'            => __( 'Heading', 'swiftling' ),
			'paragraph'          => __( 'Paragraph', 'swiftling' ),
			'list'               => __( 'List', 'swiftling' ),
			'button'             => __( 'Button', 'swiftling' ),
			'link'               => __( 'Link', 'swiftling' ),
			'alt'                => __( 'Alt text', 'swiftling' ),
			'caption'            => __( 'Caption', 'swiftling' ),
			'label'              => __( 'Label', 'swiftling' ),
			'quote'              => __( 'Quote', 'swiftling' ),
			'seo_title'          => __( 'SEO title', 'swiftling' ),
			'meta_description'   => __( 'Meta description', 'swiftling' ),
			'social_title'       => __( 'Title when shared', 'swiftling' ),
			'social_description' => __( 'Description when shared', 'swiftling' ),
			'structured'         => __( 'Structured data', 'swiftling' ),
			'text'               => __( 'Text', 'swiftling' ),
			'url'                => __( 'Link address', 'swiftling' ),
			'image'              => __( 'Image', 'swiftling' ),
			'video'              => __( 'Video or audio', 'swiftling' ),
			'code'               => __( 'Code', 'swiftling' ),
			'name'               => __( 'Name', 'swiftling' ),
			'description'        => __( 'Description', 'swiftling' ),
		);
	}

	/**
	 * Labels for the parts of a page a text can sit in.
	 *
	 * @return array<string, string>
	 */
	public static function regionLabels(): array {
		return array(
			'header'     => __( 'Header', 'swiftling' ),
			'navigation' => __( 'Navigation', 'swiftling' ),
			'footer'     => __( 'Footer', 'swiftling' ),
			'sidebar'    => __( 'Sidebar', 'swiftling' ),
			'head'       => __( 'Search results', 'swiftling' ),
		);
	}

	/**
	 * Registers the stylesheet of the state icons and language chips, which the SwiftLing screens
	 * and the Translations column of the admin lists share.
	 *
	 * @return string Its handle.
	 */
	public static function registerStatesStyle(): string {
		wp_register_style( 'swiftling-states', SWIFTLING_URL . 'assets/css/states.css', array(), SWIFTLING_VERSION );
		return 'swiftling-states';
	}

	/**
	 * Labels for translation and connection states.
	 *
	 * @return array<string, string>
	 */
	public static function statusLabels(): array {
		return array(
			'reviewed'      => __( 'Reviewed', 'swiftling' ),
			'machine'       => __( 'Machine translated', 'swiftling' ),
			'official'      => __( 'Translated by WordPress, the theme or the plugin', 'swiftling' ),
			'outdated'      => __( 'Outdated', 'swiftling' ),
			'partial'       => __( 'Partly translated', 'swiftling' ),
			'missing'       => __( 'Not translated', 'swiftling' ),
			'duplicate'     => __( 'Keeps the original', 'swiftling' ),
			'published'     => __( 'Published', 'swiftling' ),
			'hidden'        => __( 'Hidden', 'swiftling' ),
			'working'       => __( 'Working', 'swiftling' ),
			'not-connected' => __( 'Not connected', 'swiftling' ),
			'failed'        => __( 'Key does not work', 'swiftling' ),
			'unpaid'        => __( 'Key works · needs credit', 'swiftling' ),
			'untested'      => __( 'Not checked yet', 'swiftling' ),
		);
	}

	/**
	 * Prints a coloured pill for a state, e.g. "Reviewed".
	 *
	 * @param string $status Key from self::statusLabels().
	 * @param string $label  Optional text instead of the default label.
	 */
	public static function badge( string $status, string $label = '' ): void {
		$labels = self::statusLabels();
		printf(
			'<span class="swiftling-badge is-%s">%s</span>',
			esc_attr( $status ),
			esc_html( '' !== $label ? $label : $labels[ $status ] )
		);
	}

	/**
	 * Prints a round status icon with a text alternative for screen readers.
	 *
	 * @param string $status One of reviewed, machine, official, outdated, partial, missing.
	 */
	public static function statusIcon( string $status ): void {
		$labels = self::statusLabels();
		printf(
			'<span class="swiftling-status is-%1$s" title="%2$s"><span class="screen-reader-text">%2$s</span></span>',
			esc_attr( $status ),
			esc_attr( $labels[ $status ] )
		);
	}

	/**
	 * Prints the choice of a language's flag: the flag now shown, a list of countries with automatic
	 * and no flag, and an image of the site's own. The Languages and the Switcher screen print the
	 * same fields, flags[locale][country] and flags[locale][image], which Flags::sanitize() saves,
	 * and assets/js/flag-choice.js keeps the picture right.
	 *
	 * @param string $locale Locale of the language, e.g. "de_DE".
	 * @param string $name   Name of the language, for screen readers.
	 */
	public static function flagChoice( string $locale, string $name ): void {
		$countries = Flags::countries();
		$choice    = Flags::choice( $locale );
		$automatic = Flags::automatic( $locale );
		$field     = 'flags[' . $locale . ']';
		$id        = 'swiftling-flag-' . sanitize_html_class( $locale );
		$current   = Flags::url( $locale );
		?>
		<span class="swiftling-flag-choice" data-swiftling-flag="<?php echo esc_attr( str_replace( '_', '-', $locale ) ); ?>" data-automatic="<?php echo esc_attr( $automatic ); ?>">
			<img class="swiftling-flag-choice__image" src="<?php echo esc_url( '' !== $current ? $current : Flags::countryUrl( 'eu' ) ); ?>" alt="" width="30" height="20" <?php echo '' === $current ? 'hidden' : ''; ?>>
			<span class="swiftling-flag-choice__fields">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
					<?php
					/* translators: %s: language name, e.g. Deutsch. */
					echo esc_html( sprintf( __( 'Flag of %s', 'swiftling' ), $name ) );
					?>
				</label>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field . '[country]' ); ?>">
					<option value="" <?php selected( '', $choice['country'] ); ?>>
						<?php
						echo esc_html(
							'' !== $automatic
								/* translators: %s: country name, e.g. Croatia. */
								? sprintf( __( 'Automatic: %s', 'swiftling' ), $countries[ $automatic ] ?? strtoupper( $automatic ) )
								: __( 'Automatic: no flag', 'swiftling' )
						);
						?>
					</option>
					<option value="none" <?php selected( 'none', $choice['country'] ); ?>><?php esc_html_e( 'No flag', 'swiftling' ); ?></option>
					<?php foreach ( $countries as $code => $country ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $choice['country'] ); ?>><?php echo esc_html( $country ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="swiftling-flag-choice__own">
					<input type="hidden" name="<?php echo esc_attr( $field . '[image]' ); ?>" value="<?php echo esc_attr( $choice['image'] ); ?>">
					<button type="button" class="swiftling-button is-link" data-swiftling-flag-choose><?php esc_html_e( 'Use your own image', 'swiftling' ); ?></button>
					<button type="button" class="swiftling-button is-link" data-swiftling-flag-remove <?php echo '' === $choice['image'] ? 'hidden' : ''; ?>><?php esc_html_e( 'Remove your image', 'swiftling' ); ?></button>
				</span>
			</span>
		</span>
		<?php
	}

	/**
	 * Prints a language code chip. The site's own language is shown dark.
	 *
	 * @param string $code      Short language code, e.g. DE.
	 * @param bool   $is_source Whether this is the language the site is written in.
	 */
	public static function lang( string $code, bool $is_source = false ): void {
		printf( '<span class="swiftling-lang%s">%s</span>', $is_source ? ' is-source' : '', esc_html( $code ) );
	}

	/**
	 * Prints the notice that language folders are the addresses of pages of the site, which then
	 * show the language instead: after the folders or a language's address change.
	 *
	 * @param string[] $taken Language folders, e.g. array( 'de' ).
	 */
	public static function takenFolders( array $taken ): void {
		if ( ! $taken ) {
			return;
		}
		$list = implode(
			', ',
			array_map(
				static function ( string $slug ): string {
					return '/' . $slug . '/';
				},
				$taken
			)
		);
		self::notice(
			'warning',
			array(
				/* translators: %s: language folders, e.g. /de/. */
				'text' => sprintf( _n( 'A page of this site already uses the address %s. The language now shows there instead, so change the slug of that page or the address of the language to open the page again.', 'Pages of this site already use the addresses %s. The languages now show there instead, so change the slugs of those pages or the addresses of the languages to open the pages again.', count( $taken ), 'swiftling' ), $list ),
			)
		);
	}

	/**
	 * Prints a card that says a screen has nothing to show yet, and where to go instead.
	 *
	 * @param string             $title  What is missing.
	 * @param string             $text   Why, and what to do.
	 * @param array<int, string> $action Optional array( label, url ) for a button.
	 */
	public static function emptyState( string $title, string $text, array $action = array() ): void {
		?>
		<section class="swiftling-card swiftling-empty">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="swiftling-muted"><?php echo esc_html( $text ); ?></p>
			<?php
			if ( $action ) {
				self::button( $action[0], 'primary', $action[1] );
			}
			?>
		</section>
		<?php
	}
}
