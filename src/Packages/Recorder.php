<?php
/**
 * Recording the texts of themes and plugins while the user browses.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * A recording: for up to 30 minutes, SwiftLing notes the texts of themes and plugins on the pages
 * the user opens, and which settings the settings screens of the admin hold. It only ever starts
 * when the user starts it, and it is the user's own: other people browsing the site meanwhile are
 * not recorded.
 *
 * - Pages of the site: each page the user opens is checked once, separately, as the translation
 *   editor checks a page (see includes/probe.php): in hidden frames of the user's browser, a probe
 *   that leaves no trace. The page the user sees is not changed, so a page cache never keeps a
 *   marked page and no form can save a marker. Only texts from the code of themes and plugins and
 *   their settings are kept, never the content of posts, the fields of a builder such as ACF or
 *   WordPress's own texts, see swiftling_rest_record().
 * - Settings screens: which options the code of each theme and plugin reads there, so a scan of
 *   its settings lists them, see Settings. Nothing on the screen changes and saving works as usual.
 *
 * Which theme or plugin reads an option or writes a text is told from the call stack: the file that
 * calls get_option() itself, see Packages::callerOf(), so a setting WordPress reads for a theme, such
 * as the site title, stays WordPress's; and for a text, the first file that is not WordPress's or
 * SwiftLing's, see Packages::caller(). That is noted for each option and text domain the first time
 * it is used in a request, and only during a recording.
 */
final class Recorder {
	/**
	 * User meta that holds the user's recording.
	 */
	public const META = 'swiftling_recording';

	/**
	 * Option with the end of the latest recording of any user, so requests can tell cheaply whether
	 * anyone might be recording.
	 */
	public const UNTIL_OPTION = 'swiftling_recording_until';

	/**
	 * How long a recording runs, in seconds.
	 */
	public const DURATION = 1800;

	/**
	 * Most pages a recording remembers as checked.
	 */
	private const PAGE_LIMIT = 300;

	/**
	 * Packages whose code read each option in this request, by option name.
	 *
	 * @var array<string, string>
	 */
	private static $options = array();

	/**
	 * Packages whose code used each text domain in this request.
	 *
	 * @var array<string, string>
	 */
	private static $domains = array();

	/**
	 * Whether this request notes which code reads options and uses text domains.
	 *
	 * @var bool
	 */
	private static $watching = false;

	/**
	 * Whether a note is being taken, so the lookups it makes are not noted themselves.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hooks the recording into WordPress. Called when SwiftLing loads, before themes and most plugins
	 * read their settings, so their first reads are noted.
	 */
	public static function register(): void {
		if ( self::isRecordingProbe() || ( is_admin() && ! wp_doing_ajax() && (int) swiftling_raw_option( self::UNTIL_OPTION, 0 ) > time() ) ) {
			self::watch();
		}

		add_action( 'admin_post_swiftling_recording', array( self::class, 'handle' ) );
		add_action( 'admin_bar_menu', array( self::class, 'adminBar' ), 82 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueueSite' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueAdmin' ) );
		// Before WordPress prints the scripts of the footer (priority 20), so the panel is there when they run.
		add_action( 'wp_footer', array( self::class, 'printSitePanel' ), 5 );
		add_action( 'admin_footer', array( self::class, 'printAdminPanel' ), 1 );
	}

	/**
	 * Whether the request is the marked probe of a page being recorded. Told from the address alone,
	 * as the probe itself is verified only later, on init; until then only notes are taken, and they
	 * are printed only into a verified probe.
	 */
	private static function isRecordingProbe(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only whether to take notes; probe.php verifies the probe before anything is printed.
		return isset( $_GET['swiftling_probe'], $_GET['swiftling_subject'] )
			&& 'marked' === sanitize_key( wp_unslash( $_GET['swiftling_probe'] ) )
			&& 0 === strpos( sanitize_text_field( wp_unslash( $_GET['swiftling_subject'] ) ), 'record:' );
		// phpcs:enable
	}

	/**
	 * Starts noting which code reads options and uses text domains.
	 */
	public static function watch(): void {
		if ( self::$watching ) {
			return;
		}
		self::$watching = true;
		add_filter( 'pre_option', array( self::class, 'noteOption' ), PHP_INT_MIN, 2 );
		add_filter( 'gettext', array( self::class, 'noteText' ), PHP_INT_MIN, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'noteTextWithContext' ), PHP_INT_MIN, 4 );
		add_filter( 'ngettext', array( self::class, 'notePlural' ), PHP_INT_MIN, 5 );
		add_filter( 'ngettext_with_context', array( self::class, 'notePluralWithContext' ), PHP_INT_MIN, 6 );
	}

	/**
	 * Stops noting, and forgets what was noted. For the tests and once notes are saved.
	 */
	public static function unwatch(): void {
		remove_filter( 'pre_option', array( self::class, 'noteOption' ), PHP_INT_MIN );
		remove_filter( 'gettext', array( self::class, 'noteText' ), PHP_INT_MIN );
		remove_filter( 'gettext_with_context', array( self::class, 'noteTextWithContext' ), PHP_INT_MIN );
		remove_filter( 'ngettext', array( self::class, 'notePlural' ), PHP_INT_MIN );
		remove_filter( 'ngettext_with_context', array( self::class, 'notePluralWithContext' ), PHP_INT_MIN );
		self::$watching = false;
	}

	/**
	 * Notes which package's code reads an option, the first time it is read.
	 *
	 * @param mixed  $pre    Value from an earlier filter.
	 * @param string $option Option name.
	 * @return mixed The value, unchanged.
	 */
	public static function noteOption( $pre, $option ) {
		if ( ! self::$busy && is_string( $option ) && ! isset( self::$options[ $option ] ) ) {
			self::$busy               = true;
			self::$options[ $option ] = Packages::callerOf( 'get_option' );
			self::$busy               = false;
		}
		return $pre;
	}

	/**
	 * Notes which package's code uses a text domain.
	 *
	 * @param string $translation Text.
	 * @param string $text        Text in the code.
	 * @param string $domain      Text domain.
	 * @return string The text, unchanged.
	 */
	public static function noteText( $translation, $text, $domain ) {
		self::noteDomain( $domain );
		return $translation;
	}

	/**
	 * Notes which package's code uses a text domain, for a text with a context.
	 *
	 * @param string $translation Text.
	 * @param string $text        Text in the code.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string The text, unchanged.
	 */
	public static function noteTextWithContext( $translation, $text, $context, $domain ) {
		self::noteDomain( $domain );
		return $translation;
	}

	/**
	 * Notes which package's code uses a text domain, for a text with plural forms.
	 *
	 * @param string $translation Text.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @param string $domain      Text domain.
	 * @return string The text, unchanged.
	 */
	public static function notePlural( $translation, $single, $plural, $number, $domain ) {
		self::noteDomain( $domain );
		return $translation;
	}

	/**
	 * Notes which package's code uses a text domain, for a text with plural forms and a context.
	 *
	 * @param string $translation Text.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string The text, unchanged.
	 */
	public static function notePluralWithContext( $translation, $single, $plural, $number, $context, $domain ) {
		self::noteDomain( $domain );
		return $translation;
	}

	/**
	 * Notes the package using a text domain, the first time it is used.
	 *
	 * @param mixed $domain Text domain.
	 */
	private static function noteDomain( $domain ): void {
		if ( ! self::$busy && is_string( $domain ) && ! isset( self::$domains[ $domain ] ) ) {
			self::$busy               = true;
			self::$domains[ $domain ] = Packages::caller();
			self::$busy               = false;
		}
	}

	/**
	 * Returns what was noted in this request: the options each package's code read and the text
	 * domains it used. Code of WordPress and SwiftLing is left out.
	 *
	 * @return array{options: array<string, string>, domains: array<string, string>}
	 */
	public static function findings(): array {
		return array(
			'options' => array_filter( self::$options ),
			'domains' => array_filter( self::$domains ),
		);
	}

	/**
	 * Returns the current user's recording, while it runs.
	 *
	 * @return array{until: int, pages: bool, admin: bool, paused: bool, started: int, checked: array, found: array}|null
	 */
	public static function current(): ?array {
		$user = get_current_user_id();
		if ( ! $user ) {
			return null;
		}
		$recording = get_user_meta( $user, self::META, true );
		if ( ! is_array( $recording ) || empty( $recording['until'] ) || (int) $recording['until'] <= time() ) {
			return null;
		}
		return array_merge(
			array(
				'pages'   => false,
				'admin'   => false,
				'paused'  => false,
				'started' => time(),
				'checked' => array(),
				'found'   => array(),
			),
			$recording
		);
	}

	/**
	 * Returns the current user's last recording, also once it ended, for what it found.
	 *
	 * @return array|null
	 */
	public static function last(): ?array {
		$user      = get_current_user_id();
		$recording = $user ? get_user_meta( $user, self::META, true ) : null;
		return is_array( $recording ) ? $recording : null;
	}

	/**
	 * Whether the current user records pages of the site right now.
	 */
	public static function recordsPages(): bool {
		$recording = self::current();
		return $recording && $recording['pages'] && ! $recording['paused'] && current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user records settings screens right now.
	 */
	public static function recordsAdmin(): bool {
		$recording = self::current();
		return $recording && $recording['admin'] && ! $recording['paused'] && current_user_can( 'manage_options' );
	}

	/**
	 * Starts a recording for the current user.
	 *
	 * @param bool $pages Whether pages of the site are recorded.
	 * @param bool $admin Whether settings screens are recorded.
	 */
	public static function start( bool $pages, bool $admin ): void {
		$until = time() + self::DURATION;
		update_user_meta(
			get_current_user_id(),
			self::META,
			array(
				'until'   => $until,
				'pages'   => $pages,
				'admin'   => $admin,
				'paused'  => false,
				'started' => time(),
				'checked' => array(),
				'found'   => array(),
			)
		);
		if ( (int) swiftling_raw_option( self::UNTIL_OPTION, 0 ) < $until ) {
			update_option( self::UNTIL_OPTION, $until, true );
		}
	}

	/**
	 * Ends the current user's recording; what it found stays for the screen to show.
	 */
	public static function stop(): void {
		$recording = self::last();
		if ( $recording ) {
			$recording['until']  = 0;
			$recording['paused'] = false;
			update_user_meta( get_current_user_id(), self::META, $recording );
		}
	}

	/**
	 * Pauses or resumes the current user's recording. The time it may still run keeps running.
	 *
	 * @param bool $paused Whether it pauses.
	 */
	public static function pause( bool $paused ): void {
		$recording = self::current();
		if ( $recording ) {
			$recording['paused'] = $paused;
			update_user_meta( get_current_user_id(), self::META, $recording );
		}
	}

	/**
	 * Notes a page as recorded, with what was found on it, in the current user's recording.
	 *
	 * @param string             $url   Address of the page, without a language.
	 * @param array<string, int> $found Package ID => texts first found on this page.
	 */
	public static function notePage( string $url, array $found ): void {
		$recording = self::current();
		if ( ! $recording ) {
			return;
		}
		$recording['checked'][ $url ] = time();
		if ( count( $recording['checked'] ) > self::PAGE_LIMIT ) {
			$recording['checked'] = array_slice( $recording['checked'], -self::PAGE_LIMIT, null, true );
		}
		foreach ( $found as $id => $count ) {
			$recording['found'][ $id ] = ( isset( $recording['found'][ $id ] ) ? (int) $recording['found'][ $id ] : 0 ) + (int) $count;
		}
		update_user_meta( get_current_user_id(), self::META, $recording );
	}

	/**
	 * Starts, stops, pauses or resumes the current user's recording, from a form or a link.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not record the texts of this site.', 'swiftling' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'swiftling_recording' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Verified right above.
		$operation = isset( $_REQUEST['operation'] ) ? sanitize_key( wp_unslash( $_REQUEST['operation'] ) ) : '';
		$pages     = ! empty( $_POST['pages'] );
		$admin     = ! empty( $_POST['admin'] );
		// phpcs:enable
		$screen = admin_url( 'admin.php?page=swiftling-themes-plugins' );

		switch ( $operation ) {
			case 'start':
				if ( ! $pages && ! $admin ) {
					wp_safe_redirect( add_query_arg( 'swiftling_notice', 'recording_nothing', $screen ) );
					exit;
				}
				self::start( $pages, $admin );
				// Pages are recorded on the site; settings screens in the admin, from where the user is.
				wp_safe_redirect( $pages ? home_url( '/' ) : add_query_arg( 'swiftling_notice', 'recording_admin', $screen ) );
				exit;
			case 'pause':
			case 'resume':
				self::pause( 'pause' === $operation );
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $screen );
				exit;
			default:
				self::stop();
				wp_safe_redirect( add_query_arg( 'swiftling_notice', 'recording_stopped', $screen ) );
				exit;
		}
	}

	/**
	 * Returns the address of a link that changes the recording, with its nonce.
	 *
	 * @param string $operation stop, pause or resume.
	 */
	public static function actionUrl( string $operation ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=swiftling_recording&operation=' . $operation ), 'swiftling_recording' );
	}

	/**
	 * Returns how many minutes the current user's recording still runs.
	 */
	public static function minutesLeft(): int {
		$recording = self::current();
		return $recording ? (int) max( 1, ceil( ( (int) $recording['until'] - time() ) / 60 ) ) : 0;
	}

	/**
	 * Shows in the toolbar that the user is recording, with a way to stop, on every screen and page.
	 *
	 * @param \WP_Admin_Bar $bar Toolbar.
	 */
	public static function adminBar( $bar ): void {
		$recording = self::current();
		if ( ! $recording || ! current_user_can( 'manage_options' ) || swiftling_is_probe() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'swiftling-recording',
				'title' => $recording['paused']
					? esc_html__( 'SwiftLing recording paused', 'swiftling' )
					: esc_html(
						sprintf(
							/* translators: %s: minutes, e.g. 27. */
							_n( 'SwiftLing is recording texts · %s min left', 'SwiftLing is recording texts · %s min left', self::minutesLeft(), 'swiftling' ),
							number_format_i18n( self::minutesLeft() )
						)
					),
				'href'  => admin_url( 'admin.php?page=swiftling-themes-plugins' ),
				'meta'  => array( 'class' => $recording['paused'] ? 'swiftling-recording is-paused' : 'swiftling-recording' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'swiftling-recording',
				'id'     => 'swiftling-recording-pause',
				'title'  => $recording['paused'] ? esc_html__( 'Resume recording', 'swiftling' ) : esc_html__( 'Pause recording', 'swiftling' ),
				'href'   => self::actionUrl( $recording['paused'] ? 'resume' : 'pause' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'swiftling-recording',
				'id'     => 'swiftling-recording-stop',
				'title'  => esc_html__( 'Stop and review', 'swiftling' ),
				'href'   => self::actionUrl( 'stop' ),
			)
		);
	}

	/**
	 * Whether the request shows a page of the site the recording checks: a page a person reads, not
	 * a feed, a preview of the Customizer or a page check itself.
	 */
	private static function isRecordablePage(): bool {
		return ! is_admin() && ! swiftling_is_probe() && ! is_feed() && ! is_customize_preview() && ! is_robots()
			&& isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'];
	}

	/**
	 * Loads the recording on a page of the site: it checks the page in the background and shows what
	 * it found in a panel.
	 */
	public static function enqueueSite(): void {
		if ( ! self::current() || ! current_user_can( 'manage_options' ) || swiftling_is_probe() ) {
			return;
		}
		// The look of the recording in the toolbar, on every page while it runs.
		wp_enqueue_style( 'swiftling-recorder', SWIFTLING_URL . 'assets/css/recorder.css', array(), SWIFTLING_VERSION );
		if ( ! self::recordsPages() || ! self::isRecordablePage() ) {
			return;
		}
		$url     = swiftling_current_original_url();
		$subject = swiftling_subject( 'record:' . self::pathOf( $url ) );
		if ( ! $subject ) {
			return;
		}
		$recording = self::current();
		wp_enqueue_script( 'swiftling-page-check', SWIFTLING_URL . 'assets/js/page-check.js', array(), SWIFTLING_VERSION, true );
		wp_enqueue_script( 'swiftling-recorder', SWIFTLING_URL . 'assets/js/recorder.js', array( 'swiftling-page-check', 'wp-i18n' ), SWIFTLING_VERSION, true );
		wp_set_script_translations( 'swiftling-recorder', 'swiftling' );
		wp_add_inline_script(
			'swiftling-recorder',
			'window.swiftlingRecorder = ' . wp_json_encode(
				array(
					'subject' => $subject['key'],
					'checked' => isset( $recording['checked'][ $subject['url'] ] ),
					'probes'  => array(
						'marked'  => swiftling_probe_url( $subject, 'marked' ),
						'control' => swiftling_probe_url( $subject, 'control' ),
					),
					'rest'    => esc_url_raw( rest_url( 'swiftling/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);
	}

	/**
	 * Returns the path and query of an address of this site, as a recorded page's subject names it.
	 *
	 * @param string $url Address.
	 */
	public static function pathOf( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		return ( '' === $path ? '/' : $path ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Prints the panel that shows what the recording found on a page of the site.
	 */
	public static function printSitePanel(): void {
		if ( ! wp_script_is( 'swiftling-recorder' ) ) {
			return;
		}
		$recording = self::current();
		$found     = 0;
		foreach ( (array) $recording['found'] as $count ) {
			$found += (int) $count;
		}
		?>
		<section class="swiftling-recorder" id="swiftling-recorder" aria-labelledby="swiftling-recorder-title" translate="no">
			<div class="swiftling-recorder__head">
				<h2 id="swiftling-recorder-title"><?php esc_html_e( 'Recording texts', 'swiftling' ); ?></h2>
				<button type="button" class="swiftling-recorder__hide" aria-expanded="true" aria-controls="swiftling-recorder-body"><?php esc_html_e( 'Hide', 'swiftling' ); ?></button>
			</div>
			<div class="swiftling-recorder__body" id="swiftling-recorder-body">
				<p class="swiftling-recorder__status" role="status"><?php esc_html_e( 'Checking this page in the background…', 'swiftling' ); ?></p>
				<ul class="swiftling-recorder__found" hidden></ul>
				<p class="swiftling-recorder__total">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of texts, 2: number of pages. */
							_n( 'Recorded so far: %1$s new text on %2$s pages', 'Recorded so far: %1$s new texts on %2$s pages', $found, 'swiftling' ),
							number_format_i18n( $found ),
							number_format_i18n( count( (array) $recording['checked'] ) )
						)
					);
					?>
				</p>
				<p class="swiftling-recorder__note"><?php esc_html_e( 'Visitors see nothing of this. Each page is checked separately, as the translation editor does, so this page stays as it is.', 'swiftling' ); ?></p>
				<div class="swiftling-recorder__actions">
					<a class="swiftling-recorder__button is-primary" href="<?php echo esc_url( self::actionUrl( 'stop' ) ); ?>"><?php esc_html_e( 'Stop and review', 'swiftling' ); ?></a>
					<a class="swiftling-recorder__button" href="<?php echo esc_url( self::actionUrl( 'pause' ) ); ?>"><?php esc_html_e( 'Pause', 'swiftling' ); ?></a>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Loads the look of the recording in the toolbar, and of the panel on a settings screen being
	 * recorded, on every admin screen while it runs.
	 */
	public static function enqueueAdmin(): void {
		if ( self::current() && current_user_can( 'manage_options' ) ) {
			wp_enqueue_style( 'swiftling-recorder', SWIFTLING_URL . 'assets/css/recorder.css', array(), SWIFTLING_VERSION );
		}
	}

	/**
	 * Whether the screen is one of SwiftLing's own, which is never recorded.
	 */
	private static function isOwnScreen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which screen this is.
		return isset( $_GET['page'] ) && 0 === strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'swiftling' );
	}

	/**
	 * Saves which package's code read which options on a settings screen being recorded, and shows
	 * in a panel the texts found among them.
	 */
	public static function printAdminPanel(): void {
		if ( ! self::$watching || ! self::recordsAdmin() || self::isOwnScreen() ) {
			return;
		}
		$findings = self::findings();
		self::unwatch();
		Settings::learnOwners( $findings['options'] );
		Packages::learnDomains( $findings['domains'] );

		$by_package = array();
		foreach ( $findings['options'] as $option => $id ) {
			if ( Packages::get( $id ) && Settings::isCandidateName( (string) $option ) ) {
				$by_package[ $id ][] = $option;
			}
		}
		$rows = array();
		foreach ( $by_package as $id => $options ) {
			$texts   = 0;
			$holding = array();
			foreach ( Settings::classify( $options ) as $value ) {
				if ( 'settings' !== $value['group'] ) {
					++$texts;
					$holding[ $value['option'] ] = true;
				}
			}
			if ( $texts ) {
				$rows[] = array(
					'package' => Packages::get( $id ),
					'texts'   => $texts,
					'other'   => count( $options ) - count( $holding ),
				);
			}
		}
		if ( ! $rows ) {
			return;
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['texts'] - $a['texts'];
			}
		);
		?>
		<section class="swiftling-recorder is-admin" aria-labelledby="swiftling-recorder-title" translate="no">
			<div class="swiftling-recorder__head">
				<h2 id="swiftling-recorder-title"><?php esc_html_e( 'SwiftLing found texts on this screen', 'swiftling' ); ?></h2>
			</div>
			<div class="swiftling-recorder__body">
				<ul class="swiftling-recorder__found">
					<?php foreach ( array_slice( $rows, 0, 5 ) as $row ) : ?>
						<li>
							<span>
								<strong><?php echo esc_html( $row['package']['name'] ); ?></strong>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: number of texts, 2: number of other settings. */
										_n( '%1$s text visitors may read; %2$s other settings stay as they are.', '%1$s texts visitors may read; %2$s other settings stay as they are.', $row['texts'], 'swiftling' ),
										number_format_i18n( $row['texts'] ),
										number_format_i18n( $row['other'] )
									)
								);
								?>
							</span>
							<a class="swiftling-recorder__button is-primary" href="<?php echo esc_url( swiftling_package_url( $row['package']['id'], 'choose' ) ); ?>">
								<?php esc_html_e( 'Choose what to translate', 'swiftling' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="swiftling-recorder__note"><?php esc_html_e( 'Nothing on this screen changes, and saving works as usual.', 'swiftling' ); ?></p>
				<div class="swiftling-recorder__actions">
					<a class="swiftling-recorder__button" href="<?php echo esc_url( self::actionUrl( 'stop' ) ); ?>"><?php esc_html_e( 'Stop and review', 'swiftling' ); ?></a>
				</div>
			</div>
		</section>
		<?php
	}
}
