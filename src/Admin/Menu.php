<?php
/**
 * Admin menu, screen rendering and admin assets.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, screen rendering and admin assets.
 */
final class Menu {
	/**
	 * Group of the screen being printed, see renderingGroup().
	 *
	 * @var string
	 */
	private static $rendering = '';

	/**
	 * Hooks the menu into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
		add_action( 'admin_head', array( $this, 'hideScreens' ) );
		add_filter( 'submenu_file', array( $this, 'markGroup' ) );
	}

	/**
	 * Groups of screens. A group is one item of the menu, under the group's name, which opens its
	 * first screen. Each screen of a group shows under the group's title and description, with the
	 * group's screens listed beside it in parts (parts, names keyed by the part of screens()). See
	 * renderScreen().
	 *
	 * @return array<string, array{name: string, description: string, parts: array<string, string>}>
	 */
	public static function groups(): array {
		$groups = array(
			'translations' => array(
				'name'        => __( 'Translations', 'swiftling' ),
				'description' => __( 'Everything visitors read on the site, kind by kind.', 'swiftling' ),
				'parts'       => array(
					'content' => __( 'Content', 'swiftling' ),
					'site'    => __( 'Site parts', 'swiftling' ),
				),
			),
			'settings'     => array(
				'name'        => __( 'Settings', 'swiftling' ),
				'description' => __( 'Which languages the site speaks, at which addresses, and how SwiftLing translates.', 'swiftling' ),
				'parts'       => array(
					'site'      => __( 'Site', 'swiftling' ),
					'swiftling' => 'SwiftLing',
				),
			),
		);

		/**
		 * Filters the groups of screens, so another plugin, as SwiftLing Pro, can add a group of its
		 * own or parts to a group. See docs/hooks.md.
		 *
		 * @param array<string, array{name: string, description?: string, parts?: array<string, string>}> $groups
		 *        Groups keyed by ID: the name of the group's menu item and title, a description under
		 *        it, and the parts of the list of its screens, names keyed by part.
		 */
		$filtered = apply_filters( 'swiftling_admin_groups', $groups );
		$clean    = array();
		foreach ( is_array( $filtered ) ? $filtered : $groups as $id => $group ) {
			if ( is_string( $id ) && is_array( $group ) && ! empty( $group['name'] ) ) {
				$clean[ $id ] = array(
					'name'        => (string) $group['name'],
					'description' => isset( $group['description'] ) ? (string) $group['description'] : '',
					'parts'       => isset( $group['parts'] ) && is_array( $group['parts'] ) ? $group['parts'] : array(),
				);
			}
		}
		return $clean;
	}

	/**
	 * SwiftLing admin screens, in menu order, the screens of a group in the order of the list beside
	 * them. Hidden screens are reachable only by link. A screen of a group (group) is out of the menu
	 * unless it is the group's first; every screen keeps its own address, so links to it work. The
	 * name of a screen (menu) is its title, and its name in the list, in its part (part) of the
	 * group, after a small picture: the path of a 16 by 16 line drawing (icon) on a tile of a colour
	 * (tint), see .swiftling-rail__tile in assets/css/admin.css.
	 *
	 * A screen can name a script in assets/js that loads only on that screen, and ask for the
	 * WordPress visual editor, which that script can then start on its fields, for the media
	 * library, from which it can let the user choose an image, for registered stylesheets, such as
	 * the one of the language switcher it previews, for the script that checks a page of the site in
	 * hidden frames (checks), see assets/js/page-check.js, and for what the screens that machine
	 * translate share (machine), see assets/js/machine.js.
	 *
	 * Another plugin adds screens through the filter swiftling_admin_screens: its own view file
	 * (file), a path to a PHP file that prints the screen, and its scripts and styles through the
	 * action swiftling_admin_screen_assets; script names a script of SwiftLing only. See
	 * docs/hooks.md. A screen without a name or a view, or whose file cannot be read, is left out; a
	 * screen of a group SwiftLing does not have is a screen of its own.
	 *
	 * Until the first setup is answered, every screen leads to it, but one that opens before it
	 * (before_setup), as an import that sets the languages up from another plugin's.
	 *
	 * @return array<string, array{menu: string, view: string, file?: string, group?: string, part?: string, icon?: string, tint?: string, hidden?: bool, count?: int, script?: string, checks?: bool, machine?: bool, editor?: bool, media?: bool, styles?: string[], before_setup?: bool}>
	 */
	public static function screens(): array {
		$screens = array(
			'swiftling'                => array(
				'menu' => __( 'Overview', 'swiftling' ),
				'view' => 'overview',
			),
			'swiftling-content'        => array(
				'cap'   => SWIFTLING_TRANSLATE,
				'menu'  => __( 'Pages & posts', 'swiftling' ),
				'view'  => 'content',
				'group' => 'translations',
				'part'  => 'content',
				'icon'  => 'M4 1.5h5.5l3 3v10H4zM9.5 1.5v3h3M6.5 8h4M6.5 11h4',
				'tint'  => 'orange',
			),
			'swiftling-terms'          => array(
				'cap'   => SWIFTLING_TRANSLATE,
				'menu'  => __( 'Categories & tags', 'swiftling' ),
				'view'  => 'terms',
				'group' => 'translations',
				'part'  => 'content',
				'icon'  => 'M1.5 8.2V2h6.2l6.8 6.8-6.2 6.2zM4.8 4.8h.01',
				'tint'  => 'blue',
			),
			'swiftling-comments'       => array(
				'cap'   => SWIFTLING_TRANSLATE,
				'menu'  => __( 'Comments', 'swiftling' ),
				'view'  => 'comments',
				'group' => 'translations',
				'part'  => 'content',
				'icon'  => 'M2 3h12v8H7l-3 2.5V11H2z',
				'tint'  => 'green',
			),
			'swiftling-forms'          => array(
				'cap'   => SWIFTLING_TRANSLATE,
				'menu'  => __( 'Forms', 'swiftling' ),
				'view'  => 'forms',
				'group' => 'translations',
				'part'  => 'content',
				'icon'  => 'M2.5 2.5h11v4h-11zM2.5 9.5h11v4h-11zM5 4.5h.01M5 11.5h.01',
				'tint'  => 'teal',
			),
			'swiftling-menus'          => array(
				'cap'    => SWIFTLING_TRANSLATE,
				'menu'   => __( 'Menus', 'swiftling' ),
				'view'   => 'menus',
				'script' => 'menus',
				'group'  => 'translations',
				'part'   => 'site',
				'icon'   => 'M2 4h12M2 8h12M2 12h8',
				'tint'   => 'amber',
			),
			'swiftling-templates'      => array(
				'cap'   => SWIFTLING_TRANSLATE,
				'menu'  => __( 'Templates', 'swiftling' ),
				'view'  => 'templates',
				'group' => 'translations',
				'part'  => 'site',
				'icon'  => 'M2 2h12v12H2zM2 6h12M6 6v8',
				'tint'  => 'violet',
			),
			'swiftling-themes-plugins' => array(
				'cap'    => SWIFTLING_TRANSLATE,
				'menu'   => __( 'Themes & plugins', 'swiftling' ),
				'view'   => 'themes-plugins',
				'script' => 'themes-plugins',
				'checks' => true,
				'group'  => 'translations',
				'part'   => 'site',
				'icon'   => 'M6 2h4v2.5a1.5 1.5 0 0 0 3 0H14v4h-1.5a1.5 1.5 0 0 0 0 3H14V14H2v-3h1.5a1.5 1.5 0 0 0 0-3H2V4h4z',
				'tint'   => 'ink',
			),
			'swiftling-request'        => array(
				'menu'  => __( 'Request support', 'swiftling' ),
				'view'  => 'request',
				'group' => 'translations',
			),
			'swiftling-translate-all'  => array(
				'menu'    => __( 'Translate everything', 'swiftling' ),
				'view'    => 'translate-all',
				'script'  => 'translate-all',
				'checks'  => true,
				'machine' => true,
			),
			'swiftling-languages'      => array(
				'menu'   => __( 'Languages', 'swiftling' ),
				'view'   => 'languages',
				'script' => 'languages',
				'media'  => true,
				'group'  => 'settings',
				'part'   => 'site',
				'icon'   => 'M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM1.5 8h13M8 1.5c1.8 1.9 2.7 4 2.7 6.5S9.8 12.6 8 14.5C6.2 12.6 5.3 10.5 5.3 8S6.2 3.4 8 1.5z',
				'tint'   => 'blue',
			),
			'swiftling-addresses'      => array(
				'menu'   => __( 'URLs', 'swiftling' ),
				'view'   => 'addresses',
				'script' => 'addresses',
				'group'  => 'settings',
				'part'   => 'site',
				'icon'   => 'M6.5 9.5l3-3M7 4.5l1.2-1.2a2.6 2.6 0 0 1 3.7 3.7l-1.2 1.2M9 11.5l-1.2 1.2a2.6 2.6 0 0 1-3.7-3.7l1.2-1.2',
				'tint'   => 'teal',
			),
			'swiftling-switcher'       => array(
				'menu'   => __( 'Switcher', 'swiftling' ),
				'view'   => 'switcher',
				'script' => 'switcher-settings',
				'media'  => true,
				'styles' => array( 'swiftling-switcher' ),
				'group'  => 'settings',
				'part'   => 'site',
				'icon'   => 'M2.5 5.5h10l-2.5-2.5M13.5 10.5h-10l2.5 2.5',
				'tint'   => 'violet',
			),
			'swiftling-service'        => array(
				'menu'  => __( 'Translation service', 'swiftling' ),
				'view'  => 'service',
				'group' => 'settings',
				'part'  => 'swiftling',
				'icon'  => 'M9 1.5L3.5 9h4L7 14.5 12.5 7h-4z',
				'tint'  => 'amber',
			),
			'swiftling-settings'       => array(
				'menu'   => __( 'General', 'swiftling' ),
				'view'   => 'settings',
				'script' => 'settings',
				'group'  => 'settings',
				'part'   => 'swiftling',
				'icon'   => 'M2 4h7M12 4h2M2 12h2M7 12h7M10.5 2.5v3M5.5 10.5v3',
				'tint'   => 'ink',
			),
			'swiftling-log'            => array(
				'menu'   => __( 'Logs', 'swiftling' ),
				'view'   => 'log',
				'script' => 'log',
			),
			'swiftling-setup'          => array(
				'menu'   => __( 'First setup', 'swiftling' ),
				'view'   => 'setup',
				'hidden' => true,
			),
			'swiftling-translation'    => array(
				'cap'     => SWIFTLING_TRANSLATE,
				'menu'    => __( 'Translation editor', 'swiftling' ),
				'view'    => 'editor',
				'hidden'  => true,
				'script'  => 'editor',
				'checks'  => true,
				'machine' => true,
				'editor'  => true,
				'media'   => true,
			),
		);

		/**
		 * Filters SwiftLing's admin screens, so another plugin, as SwiftLing Pro, can add screens to
		 * the menu or to a group. See self::screens() and docs/hooks.md.
		 *
		 * @param array<string, array> $screens Screens keyed by the slug of their address, in menu order.
		 */
		$filtered = apply_filters( 'swiftling_admin_screens', $screens );
		$groups   = self::groups();
		$clean    = array();
		foreach ( is_array( $filtered ) ? $filtered : $screens as $slug => $screen ) {
			if ( ! is_string( $slug ) || ! is_array( $screen ) || empty( $screen['menu'] ) || empty( $screen['view'] ) ) {
				continue;
			}
			if ( isset( $screen['file'] ) && ! is_readable( (string) $screen['file'] ) ) {
				continue;
			}
			if ( isset( $screen['group'] ) && ! isset( $groups[ $screen['group'] ] ) ) {
				unset( $screen['group'], $screen['part'] );
			}
			$clean[ $slug ] = $screen;
		}
		return $clean;
	}

	/**
	 * Registers the SwiftLing menu and its screens.
	 */
	public function registerMenu(): void {
		$icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64.86 36.57"><path fill="black" d="' . Ui::BIRD . '"/></svg>';

		$screens = self::screens();
		$groups  = self::groups();
		// The counts of the screens (count): each on the item that opens its screen, the screens of a
		// group on the group's item, and all of them on SwiftLing's own item, seen with the menu closed.
		$counts = array();
		foreach ( $screens as $slug => $screen ) {
			$item            = empty( $screen['group'] ) ? $slug : self::groupFirst( $screen['group'] );
			$counts[ $item ] = ( $counts[ $item ] ?? 0 ) + ( isset( $screen['count'] ) ? absint( $screen['count'] ) : 0 );
		}

		// Whoever may translate sees the menu, with the screens their right opens.
		add_menu_page(
			'SwiftLing',
			'SwiftLing' . self::countBadge( (int) array_sum( $counts ) ),
			SWIFTLING_TRANSLATE,
			'swiftling',
			'__return_null',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress recolours menu icons only when they are base64 SVG data URIs.
			'data:image/svg+xml;base64,' . base64_encode( $icon ),
			81
		);

		foreach ( $screens as $slug => $screen ) {
			$hook = add_submenu_page(
				'swiftling',
				$screen['menu'],
				( self::firstOfGroup( $slug ) ? $groups[ $screen['group'] ]['name'] : $screen['menu'] ) . self::countBadge( $counts[ $slug ] ?? 0 ),
				self::capability( $screen ),
				$slug,
				function () use ( $screen ) {
					$this->renderScreen( $screen['view'] );
				}
			);
			add_action(
				'load-' . $hook,
				function () use ( $screen, $slug ): void {
					$this->loadScreen( $screen + array( 'slug' => $slug ) );
				}
			);
		}
	}

	/**
	 * Returns the badge of a count beside a menu item, as WordPress's own for pending comments; none
	 * for 0.
	 *
	 * @param int $count Count.
	 */
	private static function countBadge( int $count ): string {
		if ( $count <= 0 ) {
			return '';
		}
		return sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $count, esc_html( number_format_i18n( $count ) ) );
	}

	/**
	 * Returns the slug of the first screen of a group, whose menu item stands for the group.
	 *
	 * @param string $group ID of the group.
	 */
	private static function groupFirst( string $group ): string {
		foreach ( self::screens() as $slug => $screen ) {
			if ( ( $screen['group'] ?? '' ) === $group ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Removes from the menu the hidden screens and the screens of a group but its first. This runs
	 * after WordPress has read their titles and before it prints the menu, so the screens keep a
	 * proper page title.
	 */
	public function hideScreens(): void {
		// WordPress lists the menu's own page first, the Overview, which only whoever manages the
		// site may open: without it the menu opens the first screen the user may open.
		if ( ! current_user_can( 'manage_options' ) ) {
			while ( remove_submenu_page( 'swiftling', 'swiftling' ) ) {
				continue;
			}
		}
		foreach ( self::screens() as $slug => $screen ) {
			if ( ! empty( $screen['hidden'] ) || ( ! empty( $screen['group'] ) && ! self::firstOfGroup( $slug ) ) ) {
				remove_submenu_page( 'swiftling', $slug );
			}
		}
	}

	/**
	 * Marks the item of the group as the current one of the menu on every screen of the group,
	 * which WordPress cannot do itself, as only the group's first screen is in the menu.
	 *
	 * @param string|null $item Slug of the current item of the menu, as WordPress chose it.
	 * @return string|null
	 */
	public function markGroup( $item ) {
		global $plugin_page;
		$screens = self::screens();
		if ( ! is_string( $plugin_page ) || empty( $screens[ $plugin_page ]['group'] ) ) {
			return $item;
		}
		$first = self::groupFirst( $screens[ $plugin_page ]['group'] );
		return '' !== $first ? $first : $item;
	}

	/**
	 * Returns the capability a screen takes: the one it names, or managing the site.
	 *
	 * @param array<string, mixed> $screen Screen of screens().
	 */
	public static function capability( array $screen ): string {
		return isset( $screen['cap'] ) && is_string( $screen['cap'] ) && '' !== $screen['cap'] ? $screen['cap'] : 'manage_options';
	}

	/**
	 * Whether a screen is the first of its group, the one the group's item of the menu opens.
	 *
	 * @param string $slug Slug of a screen of screens().
	 */
	private static function firstOfGroup( string $slug ): bool {
		$group = self::screens()[ $slug ]['group'] ?? '';
		return '' !== $group && self::groupFirst( $group ) === $slug;
	}

	/**
	 * Prints one SwiftLing screen. A screen of a group shows under the group's header, with the
	 * group's screens listed beside it; its own header is then the header of its part of the page,
	 * see Ui::screenHeader(). The view is a file in admin/views, or the file another plugin gave
	 * its screen.
	 *
	 * @param string $view View of a screen of screens().
	 */
	public function renderScreen( string $view ): void {
		$screen = self::screenOfView( $view );
		$group  = $screen['group'] ?? '';
		$file   = $screen['file'] ?? SWIFTLING_DIR . 'admin/views/' . $view . '.php';
		// WordPress moves the notices of other plugins after the page's .wp-header-end
		// (wp-admin/js/common.js): first, so they stand above the screen, never between its header and
		// the rest. SwiftLing's own notices are no WordPress notices and stay where a screen prints them.
		echo '<div class="wrap swiftling"><hr class="wp-header-end">';
		if ( '' === $group ) {
			include $file;
			echo '</div>';
			return;
		}

		self::groupHeader( $group );
		echo '<div class="swiftling-group"><div class="swiftling-group__side">';
		self::rail( $group, $view );
		if ( 'translations' === $group && 'request' !== $view && current_user_can( 'manage_options' ) ) {
			self::requestCard();
		}
		echo '</div><div class="swiftling-group__main">';
		self::$rendering = $group;
		try {
			include $file;
		} finally {
			self::$rendering = '';
		}
		echo '</div></div></div>';
	}

	/**
	 * The group of the screen being printed, '' when it is in none or no screen is being printed. Its
	 * header then leaves the page's title and path to the group's header.
	 */
	public static function renderingGroup(): string {
		return self::$rendering;
	}

	/**
	 * Returns the screen of a view.
	 *
	 * @param string $view View of a screen of screens().
	 * @return array<string, mixed> The screen; empty when no screen has the view.
	 */
	private static function screenOfView( string $view ): array {
		foreach ( self::screens() as $screen ) {
			if ( $screen['view'] === $view ) {
				return $screen;
			}
		}
		return array();
	}

	/**
	 * Prints the header of a group: SwiftLing, the group's name as the page's title, what it holds,
	 * and a small flock of swifts.
	 *
	 * @param string $group Group of groups().
	 */
	private static function groupHeader( string $group ): void {
		$about = self::groups()[ $group ];
		?>
		<header class="swiftling-group-header">
			<div>
				<p class="swiftling-group-header__brand">SwiftLing</p>
				<h1 class="swiftling-title"><?php echo esc_html( $about['name'] ); ?></h1>
				<p class="swiftling-description"><?php echo esc_html( $about['description'] ); ?></p>
			</div>
			<div class="swiftling-group-header__end">
				<?php ProOffer::headerButton(); ?>
				<svg class="swiftling-group-header__flock" viewBox="0 0 150 64" aria-hidden="true" focusable="false">
					<path class="is-swift" transform="translate(0 24) rotate(-10 32 18)" d="<?php echo esc_attr( Ui::BIRD ); ?>"/>
					<path class="is-ink" transform="translate(74 6) rotate(-6 20 11) scale(.62)" d="<?php echo esc_attr( Ui::BIRD ); ?>"/>
					<path class="is-swift is-far" transform="translate(124 0) rotate(-4 12 7) scale(.37)" d="<?php echo esc_attr( Ui::BIRD ); ?>"/>
				</svg>
			</div>
		</header>
		<?php
	}

	/**
	 * Prints the offer under the list of Translations to ask for a plugin, theme or form to be tested
	 * and added, where people find that something is not translated. See includes/requests.php.
	 */
	private static function requestCard(): void {
		?>
		<aside class="swiftling-request-card" aria-labelledby="swiftling-request-card-title">
			<svg class="swiftling-request-card__bird" viewBox="0 0 64.86 36.57" aria-hidden="true" focusable="false"><path d="<?php echo esc_attr( Ui::BIRD ); ?>"/></svg>
			<p class="swiftling-request-card__title" id="swiftling-request-card-title"><?php esc_html_e( 'A plugin, theme or form not translated?', 'swiftling' ); ?></p>
			<p class="swiftling-request-card__text"><?php esc_html_e( 'We test it and add it to SwiftLing.', 'swiftling' ); ?></p>
			<a class="swiftling-request-card__button" href="<?php echo esc_url( admin_url( 'admin.php?page=swiftling-request' ) ); ?>">
				<?php esc_html_e( 'Request support', 'swiftling' ); ?>
				<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
			</a>
		</aside>
		<?php
	}

	/**
	 * Prints the list of a group's screens beside the screen of a view, in the group's parts, each
	 * screen with its picture and the screen of the view marked. On a narrow window the list is a
	 * row that scrolls sideways, see assets/css/admin.css.
	 *
	 * @param string $group Group of groups().
	 * @param string $view  View file name in admin/views, without extension.
	 */
	private static function rail( string $group, string $view ): void {
		$about   = self::groups()[ $group ];
		$screens = self::screens();
		?>
		<nav class="swiftling-rail" aria-label="<?php echo esc_attr( $about['name'] ); ?>">
			<?php foreach ( $about['parts'] as $part => $part_name ) : ?>
				<div class="swiftling-rail__part">
					<p class="swiftling-rail__label"><?php echo esc_html( $part_name ); ?></p>
					<?php foreach ( $screens as $slug => $screen ) : ?>
						<?php
						if ( ( $screen['group'] ?? '' ) !== $group || ( $screen['part'] ?? '' ) !== $part || ! current_user_can( self::capability( $screen ) ) ) {
							continue;
						}
						$current = $screen['view'] === $view;
						?>
						<a class="swiftling-rail__item<?php echo $current ? ' is-current' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?>>
							<span class="swiftling-rail__tile is-<?php echo esc_attr( $screen['tint'] ?? 'ink' ); ?>"><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="<?php echo esc_attr( $screen['icon'] ?? '' ); ?>"/></svg></span>
							<?php echo esc_html( $screen['menu'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Runs before a SwiftLing screen loads, so assets and styling apply only to these screens.
	 *
	 * @param array<string, mixed> $screen Screen from screens(), with its slug.
	 */
	public function loadScreen( array $screen ): void {
		// Until the first setup is answered, it is the only screen, but one that sets the languages up
		// another way, as an import from another plugin; after that it is done.
		$set_up = swiftling_is_set_up();
		if ( ! $set_up && 'setup' !== $screen['view'] && empty( $screen['before_setup'] ) ) {
			// A translator waits for whoever manages the site to set SwiftLing up.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'SwiftLing is not set up yet. Whoever manages this site sets it up first; then there is something to translate.', 'swiftling' ), '', array( 'response' => 403 ) );
			}
			wp_safe_redirect( swiftling_setup_url() );
			exit;
		}
		if ( $set_up && 'setup' === $screen['view'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=swiftling-languages' ) );
			exit;
		}

		add_action(
			'admin_enqueue_scripts',
			function () use ( $screen ): void {
				$this->enqueueAssets( $screen );
			}
		);
		add_filter( 'admin_body_class', array( $this, 'bodyClass' ) );
	}

	/**
	 * Enqueues the admin stylesheet, the screen's own script when it has one, and the visual editor,
	 * the media library and other stylesheets when the screen asks for them.
	 *
	 * @param array<string, mixed> $screen Screen from screens(), with its slug.
	 */
	public function enqueueAssets( array $screen ): void {
		wp_enqueue_style( 'swiftling-admin', SWIFTLING_URL . 'assets/css/admin.css', array( Ui::registerStatesStyle() ), SWIFTLING_VERSION );
		foreach ( $screen['styles'] ?? array() as $style ) {
			wp_enqueue_style( $style );
		}

		$dependencies = array();
		if ( ! empty( $screen['editor'] ) ) {
			wp_enqueue_editor();
			$dependencies = array( 'editor', 'jquery' );
		}
		if ( ! empty( $screen['media'] ) ) {
			wp_enqueue_media();
		}
		if ( ! empty( $screen['checks'] ) ) {
			wp_enqueue_script( 'swiftling-page-check', SWIFTLING_URL . 'assets/js/page-check.js', array(), SWIFTLING_VERSION, true );
			$dependencies[] = 'swiftling-page-check';
		}
		if ( ! empty( $screen['machine'] ) ) {
			wp_enqueue_script( 'swiftling-machine', SWIFTLING_URL . 'assets/js/machine.js', array(), SWIFTLING_VERSION, true );
			$dependencies[] = 'swiftling-machine';
		}

		// The two screens that choose a flag share one script, which the screen's own script follows.
		if ( in_array( $screen['slug'] ?? '', array( 'swiftling-languages', 'swiftling-switcher' ), true ) ) {
			wp_enqueue_script( 'swiftling-flag-choice', SWIFTLING_URL . 'assets/js/flag-choice.js', array( 'wp-i18n' ), SWIFTLING_VERSION, true );
			wp_set_script_translations( 'swiftling-flag-choice', 'swiftling' );
			wp_add_inline_script( 'swiftling-flag-choice', 'window.swiftlingFlags = ' . wp_json_encode( array( 'url' => SWIFTLING_URL . 'assets/flags/' ) ) . ';', 'before' );
			$dependencies[] = 'swiftling-flag-choice';
		}
		if ( ! empty( $screen['script'] ) ) {
			// wp-i18n for texts whose form depends on a number, such as "1 field" and "2 fields".
			$dependencies[] = 'wp-i18n';
			wp_enqueue_script( 'swiftling-' . $screen['script'], SWIFTLING_URL . 'assets/js/' . $screen['script'] . '.js', $dependencies, SWIFTLING_VERSION, true );
			wp_set_script_translations( 'swiftling-' . $screen['script'], 'swiftling' );
		}

		/**
		 * Fires where a SwiftLing screen loads its scripts and styles, so another plugin, as SwiftLing
		 * Pro, can load its own on its screens or on SwiftLing's. See docs/hooks.md.
		 *
		 * @param string $slug   Slug of the screen's address, e.g. "swiftling-languages".
		 * @param array  $screen The screen, as Menu::screens() gives it.
		 */
		do_action( 'swiftling_admin_screen_assets', (string) ( $screen['slug'] ?? '' ), $screen );
	}

	/**
	 * Adds a body class to SwiftLing screens.
	 *
	 * @param string $classes Space-separated admin body classes.
	 * @return string
	 */
	public function bodyClass( string $classes ): string {
		return $classes . ' swiftling-screen';
	}
}
