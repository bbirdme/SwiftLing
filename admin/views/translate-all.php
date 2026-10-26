<?php
/**
 * Translate everything screen: every empty text of the site filled in with the machine translation
 * service, for one language in one go.
 *
 * The texts of a page are known only after a page check in the browser, so the run is a loop in
 * this tab: for each page it checks the page, saves what the check found, translates the empty
 * texts in batches and saves them as machine translations, see assets/js/translate-all.js. The
 * screen has three states, which that script switches between: before the run (the steps below),
 * while it runs (the pages and the progress) and once a language is done.
 *
 * What a run did is kept per language, see includes/translate-all.php: a finished language cannot
 * be done again, and a run that stopped goes on with the pages it has not done.
 *
 * Another plugin can add a way of running, see swiftling_translate_all_modes(): the screen then
 * shows the ways as tabs, and prints an added one with that plugin's file.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;
use SwiftLing\Translation\Services;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();
$swiftling_service   = Services::current();
$swiftling_name      = $swiftling_service ? $swiftling_service->name() : __( 'the translation service', 'swiftling' );

// The ways the screen runs: filling the empty texts, and those another plugin adds, as cards.
$swiftling_modes = swiftling_translate_all_modes();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which tab the screen shows.
$swiftling_mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'fill';
$swiftling_mode = isset( $swiftling_modes[ $swiftling_mode ] ) ? $swiftling_mode : 'fill';

Ui::screenHeader(
	array(
		'crumb'       => __( 'Translate everything', 'swiftling' ),
		'title'       => __( 'Translate everything', 'swiftling' ),
		'description' => 'fill' === $swiftling_mode
			/* translators: %s: translation service name, e.g. Google. */
			? sprintf( __( 'Fill every empty text of the site with %s, for one language, in one go.', 'swiftling' ), $swiftling_name )
			: $swiftling_modes[ $swiftling_mode ]['description'],
	)
);

if ( ! $swiftling_languages['targets'] ) {
	Ui::emptyState(
		__( 'No language to translate into yet', 'swiftling' ),
		__( 'Add a language the site is translated into, and you can fill all of its texts in one go.', 'swiftling' ),
		array( __( 'Manage languages', 'swiftling' ), admin_url( 'admin.php?page=swiftling-languages' ) )
	);
	return;
}

// Nothing runs without a working service: the key is added on the Translation service screen.
if ( ! $swiftling_service ) {
	Ui::notice(
		'warning',
		array(
			'title'   => __( 'No translation service is connected.', 'swiftling' ),
			'text'    => __( 'Add your own Google Cloud Translation key, and SwiftLing can fill every empty text of the site with it.', 'swiftling' ),
			'actions' => array( array( __( 'Translation service', 'swiftling' ), admin_url( 'admin.php?page=swiftling-service' ) ) ),
		)
	);
	return;
}

if ( count( $swiftling_modes ) > 1 ) :
	?>
	<nav class="swiftling-all-modes" aria-label="<?php esc_attr_e( 'Translate everything', 'swiftling' ); ?>">
		<?php foreach ( $swiftling_modes as $swiftling_key => $swiftling_about ) : ?>
			<a class="swiftling-all-mode<?php echo $swiftling_key === $swiftling_mode ? ' is-current" aria-current="page' : ''; ?>" href="<?php echo esc_url( 'fill' === $swiftling_key ? admin_url( 'admin.php?page=swiftling-translate-all' ) : add_query_arg( 'mode', $swiftling_key, admin_url( 'admin.php?page=swiftling-translate-all' ) ) ); ?>">
				<?php if ( '' !== $swiftling_about['icon'] ) : ?>
					<span class="swiftling-rail__tile is-<?php echo esc_attr( $swiftling_about['tint'] ); ?>" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><path d="<?php echo esc_attr( $swiftling_about['icon'] ); ?>"/></svg></span>
				<?php endif; ?>
				<span class="swiftling-all-mode__text">
					<strong><?php echo esc_html( $swiftling_about['label'] ); ?></strong>
					<?php if ( '' !== $swiftling_about['hint'] ) : ?>
						<span class="swiftling-muted"><?php echo esc_html( $swiftling_about['hint'] ); ?></span>
					<?php endif; ?>
				</span>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php
endif;

// Another plugin's way prints its own screen, with the service and the languages checked above.
if ( 'fill' !== $swiftling_mode ) {
	require $swiftling_modes[ $swiftling_mode ]['file'];
	return;
}

$swiftling_summary = swiftling_translate_all_summary();
$swiftling_date    = get_option( 'date_format' );

// Which languages the service translates: both the language of the site and the target.
$swiftling_source_code = $swiftling_service->languageCode( $swiftling_languages['source'] );

$swiftling_tiles = array();
foreach ( $swiftling_summary as $swiftling_slug => $swiftling_language ) {
	$swiftling_unsupported = '';
	if ( null === $swiftling_source_code ) {
		/* translators: 1: service name, e.g. Google, 2: language name, e.g. Montenegrin. */
		$swiftling_unsupported = sprintf( __( '%1$s does not translate %2$s.', 'swiftling' ), $swiftling_name, $swiftling_languages['source']['name'] );
	} elseif ( null === $swiftling_service->languageCode( $swiftling_language['language'] ) ) {
		/* translators: 1: service name, e.g. Google, 2: language name, e.g. Montenegrin. */
		$swiftling_unsupported = sprintf( __( '%1$s does not translate %2$s.', 'swiftling' ), $swiftling_name, $swiftling_language['language']['name'] );
	}

	$swiftling_note   = '';
	$swiftling_server = 'running' === $swiftling_language['status'] && 'server' === $swiftling_language['run']['driver'];
	if ( 'done' === $swiftling_language['status'] ) {
		/* translators: %s: date, e.g. 3 April 2025. */
		$swiftling_note = sprintf( __( '✓ Done on %s', 'swiftling' ), wp_date( $swiftling_date, $swiftling_language['date'] ) );
	} elseif ( $swiftling_server ) {
		// Another plugin translates it on the server now, see swiftling_translate_all_record().
		$swiftling_total = max( $swiftling_language['run']['total'], count( $swiftling_language['run']['pages'] ) + $swiftling_language['pages'] );
		$swiftling_note  = sprintf(
			/* translators: 1: pages done, 2: pages in all. */
			_n( 'On the server: %1$s of %2$s page', 'On the server: %1$s of %2$s pages', $swiftling_total, 'swiftling' ),
			number_format_i18n( count( $swiftling_language['run']['pages'] ) ),
			number_format_i18n( $swiftling_total )
		);
	} elseif ( 'running' === $swiftling_language['status'] ) {
		$swiftling_stopped = count( $swiftling_language['run']['pages'] );
		$swiftling_total   = max( $swiftling_language['run']['total'], $swiftling_stopped + $swiftling_language['pages'] );
		$swiftling_note    = sprintf(
			/* translators: 1: pages done, 2: pages in all. */
			_n( 'Stopped: %1$s of %2$s page', 'Stopped: %1$s of %2$s pages', $swiftling_total, 'swiftling' ),
			number_format_i18n( $swiftling_stopped ),
			number_format_i18n( $swiftling_total )
		);
	} else {
		$swiftling_note = sprintf(
			/* translators: %s: number of texts. */
			_n( '%s empty text', '%s empty texts', $swiftling_language['texts'], 'swiftling' ),
			number_format_i18n( $swiftling_language['texts'] )
		);
	}

	$swiftling_tiles[ $swiftling_slug ] = array(
		'name'        => $swiftling_language['language']['name'],
		'note'        => $swiftling_note,
		'unsupported' => $swiftling_unsupported,
		'done'        => 'done' === $swiftling_language['status'],
		'go_on'       => 'running' === $swiftling_language['status'],
		'server'      => $swiftling_server,
	);
}

// The language the steps describe when the screen opens: the first one that can be done.
$swiftling_first = '';
foreach ( $swiftling_tiles as $swiftling_slug => $swiftling_tile ) {
	if ( '' === $swiftling_first && ! $swiftling_tile['done'] && ! $swiftling_tile['server'] && '' === $swiftling_tile['unsupported'] ) {
		$swiftling_first = $swiftling_slug;
	}
}
$swiftling_shown = '' !== $swiftling_first ? $swiftling_summary[ $swiftling_first ] : reset( $swiftling_summary );

$swiftling_config = array(
	'rest'         => esc_url_raw( rest_url( 'swiftling/v1/' ) ),
	'nonce'        => wp_create_nonce( 'wp_rest' ),
	'service'      => $swiftling_name,
	'translations' => admin_url( 'admin.php?page=swiftling-content' ),
	'languages'    => array(),
);
foreach ( $swiftling_summary as $swiftling_slug => $swiftling_language ) {
	$swiftling_config['languages'][ $swiftling_slug ] = array(
		'name'       => $swiftling_language['language']['name'],
		'pages'      => $swiftling_language['pages'],
		'texts'      => $swiftling_language['texts'],
		'characters' => $swiftling_language['characters'],
		'estimated'  => $swiftling_language['estimated'],
		'status'     => $swiftling_language['status'],
		// The date the tile shows once the language is done: the date of a finished run, or today
		// for a language the run finishes in this tab.
		'done'       => wp_date( $swiftling_date, 0 < $swiftling_language['date'] ? $swiftling_language['date'] : time() ),
	);
}
?>
<div class="swiftling-translate-all">
	<div class="swiftling-translate-all__layout" id="swiftling-all-before">
		<div class="swiftling-stack">
			<section class="swiftling-card swiftling-step" aria-labelledby="swiftling-all-step-language">
				<header class="swiftling-step__head">
					<span class="swiftling-step__number" aria-hidden="true">1</span>
					<div>
						<h2 id="swiftling-all-step-language"><?php esc_html_e( 'Which language', 'swiftling' ); ?></h2>
						<p class="swiftling-muted">
							<?php esc_html_e( 'One language at a time. Each one can be done this way once; later texts are translated in the translation editor.', 'swiftling' ); ?>
							<span class="swiftling-tip swiftling-all-why">
								<button type="button" class="swiftling-tip__button" aria-describedby="swiftling-all-once">
									<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Why once', 'swiftling' ); ?></span>
								</button>
								<span class="swiftling-tip__text" role="tooltip" id="swiftling-all-once"><?php esc_html_e( 'Doing a language that is already filled in all over again, such as rewriting every text, is part of SwiftLing Pro.', 'swiftling' ); ?></span>
							</span>
						</p>
					</div>
				</header>
				<fieldset class="swiftling-tiles">
					<legend class="screen-reader-text"><?php esc_html_e( 'Language', 'swiftling' ); ?></legend>
					<?php foreach ( $swiftling_tiles as $swiftling_slug => $swiftling_tile ) : ?>
						<label class="swiftling-tile swiftling-all-tile<?php echo $swiftling_tile['done'] ? ' is-done' : ''; ?>" data-lang="<?php echo esc_attr( $swiftling_slug ); ?>">
							<input type="radio" name="swiftling-all-language" value="<?php echo esc_attr( $swiftling_slug ); ?>" <?php checked( $swiftling_slug, $swiftling_first ); ?> <?php disabled( $swiftling_tile['done'] || $swiftling_tile['server'] || '' !== $swiftling_tile['unsupported'] ); ?>>
							<strong><?php echo esc_html( $swiftling_tile['name'] ); ?></strong>
							<span class="swiftling-muted swiftling-all-tile__note"><?php echo esc_html( $swiftling_tile['note'] ); ?></span>
							<?php if ( '' !== $swiftling_tile['unsupported'] ) : ?>
								<span class="swiftling-muted"><?php echo esc_html( $swiftling_tile['unsupported'] ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</section>

			<section class="swiftling-card swiftling-step" aria-labelledby="swiftling-all-step-what">
				<header class="swiftling-step__head">
					<span class="swiftling-step__number" aria-hidden="true">2</span>
					<div>
						<h2 id="swiftling-all-step-what">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: language name, e.g. Deutsch. */
									__( 'What it will do in %s', 'swiftling' ),
									$swiftling_shown['language']['name']
								)
							);
							?>
						</h2>
						<p class="swiftling-muted" id="swiftling-all-about"><?php esc_html_e( 'Counted from what SwiftLing knows. Pages it has never checked are guessed, so these numbers are about right.', 'swiftling' ); ?></p>
					</div>
				</header>
				<div class="swiftling-all-numbers">
					<div class="swiftling-all-number">
						<strong id="swiftling-all-count-pages"><?php echo esc_html( number_format_i18n( $swiftling_shown['pages'] ) ); ?></strong>
						<span class="swiftling-muted"><?php esc_html_e( 'pages and posts to check', 'swiftling' ); ?></span>
					</div>
					<div class="swiftling-all-number">
						<strong id="swiftling-all-count-texts"><?php echo esc_html( number_format_i18n( $swiftling_shown['texts'] ) ); ?></strong>
						<span class="swiftling-muted"><?php esc_html_e( 'empty texts to translate', 'swiftling' ); ?></span>
					</div>
					<div class="swiftling-all-number">
						<strong id="swiftling-all-count-characters"><?php echo esc_html( number_format_i18n( $swiftling_shown['characters'] ) ); ?></strong>
						<span class="swiftling-muted">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: translation service name, e.g. Google. */
									__( 'characters billed by %s to your key', 'swiftling' ),
									$swiftling_name
								)
							);
							?>
						</span>
					</div>
				</div>
				<ul class="swiftling-all-keeps">
					<li><?php esc_html_e( 'Texts already translated or reviewed stay as they are.', 'swiftling' ); ?></li>
					<li><?php esc_html_e( 'Words on the never-translate list are kept as written.', 'swiftling' ); ?></li>
					<li><?php esc_html_e( 'Content types set to Never, and code, are left out.', 'swiftling' ); ?></li>
				</ul>
			</section>

			<section class="swiftling-card swiftling-step" aria-labelledby="swiftling-all-step-start">
				<header class="swiftling-step__head">
					<span class="swiftling-step__number" aria-hidden="true">3</span>
					<div>
						<h2 id="swiftling-all-step-start"><?php esc_html_e( 'Start', 'swiftling' ); ?></h2>
						<p class="swiftling-muted"><?php esc_html_e( 'Keep this tab open: each page is checked in the background, then translated.', 'swiftling' ); ?></p>
					</div>
				</header>
				<label class="swiftling-pick swiftling-all-agree">
					<input type="checkbox" id="swiftling-all-agree" required>
					<span id="swiftling-all-agree-text">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of characters, 2: translation service name, e.g. Google, 3: language name, e.g. Deutsch. */
								__( 'I know it sends about %1$s characters to %2$s, and that %3$s can be done this way only once.', 'swiftling' ),
								number_format_i18n( $swiftling_shown['characters'] ),
								$swiftling_name,
								$swiftling_shown['language']['name']
							)
						);
						?>
					</span>
				</label>
				<div class="swiftling-all-actions">
					<button type="button" class="swiftling-button is-primary" id="swiftling-all-start" disabled>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: language name, e.g. Deutsch. */
								__( 'Translate everything into %s', 'swiftling' ),
								$swiftling_shown['language']['name']
							)
						);
						?>
					</button>
					<?php
					/**
					 * Fires beside the button that starts the run in this tab: where another plugin adds
					 * a way to start it, as SwiftLing Pro on the server. See docs/hooks.md.
					 *
					 * @param string $lang The language the steps show first.
					 */
					do_action( 'swiftling_translate_all_start_actions', (string) $swiftling_first );
					?>
					<p class="swiftling-muted" id="swiftling-all-start-note" role="status"></p>
				</div>
			</section>
		</div>

		<aside class="swiftling-translate-all__aside swiftling-stack">
			<?php
			/**
			 * Fires at the top of the side column, above How it works: where another plugin prints a
			 * card of its own about translating the whole site, as SwiftLing Pro that pages can be
			 * translated as soon as they are published. See docs/hooks.md.
			 */
			do_action( 'swiftling_translate_all_aside' );
			?>
			<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-all-how-title">
				<h2 id="swiftling-all-how-title"><?php esc_html_e( 'How it works', 'swiftling' ); ?></h2>
				<ol class="swiftling-all-how">
					<li><?php esc_html_e( 'Each page opens in a hidden frame, so SwiftLing sees every text visitors see, also the ones your theme and page builder write.', 'swiftling' ); ?></li>
					<li>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: translation service name, e.g. Google. */
								__( 'Its empty texts go to %s in small batches and come back as machine translations.', 'swiftling' ),
								$swiftling_name
							)
						);
						?>
					</li>
					<li><?php esc_html_e( 'The next page follows, until every page of the language is done.', 'swiftling' ); ?></li>
				</ol>
			</section>
			<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-all-know-title">
				<h2 id="swiftling-all-know-title"><?php esc_html_e( 'Good to know', 'swiftling' ); ?></h2>
				<ul class="swiftling-all-know">
					<li><?php esc_html_e( 'Machine translations show as such, so you can read them and mark them reviewed.', 'swiftling' ); ?></li>
					<li><?php esc_html_e( 'Texts you add or change later are translated in the translation editor, page by page.', 'swiftling' ); ?></li>
					<li>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: translation service name, e.g. Google. */
								__( 'The %s key must work the whole way through: the run stops when it is refused.', 'swiftling' ),
								$swiftling_name
							)
						);
						?>
					</li>
				</ul>
			</section>
		</aside>
	</div>

	<section class="swiftling-card swiftling-all-running" id="swiftling-all-running" aria-labelledby="swiftling-all-running-title" hidden>
		<h2 id="swiftling-all-running-title"><?php esc_html_e( 'Translating the site', 'swiftling' ); ?></h2>
		<p class="swiftling-all-running__count" id="swiftling-all-pages" role="status"></p>
		<p class="swiftling-muted" id="swiftling-all-current"></p>
		<div class="swiftling-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Progress', 'swiftling' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="swiftling-all-bar"><span class="is-progress"></span></div>
		<ul class="swiftling-all-counters">
			<li><strong id="swiftling-all-done-texts">0</strong> <span class="swiftling-muted"><?php esc_html_e( 'texts translated', 'swiftling' ); ?></span></li>
			<li><strong id="swiftling-all-sent">0</strong> <span class="swiftling-muted"><?php esc_html_e( 'characters sent', 'swiftling' ); ?></span></li>
			<li><strong id="swiftling-all-skipped-count">0</strong> <span class="swiftling-muted"><?php esc_html_e( 'pages skipped', 'swiftling' ); ?></span></li>
		</ul>
		<?php
		Ui::notice(
			'warning',
			array(
				'text'    => __( 'Keep this tab open. The run stops when it closes; open this screen again and press Continue to go on.', 'swiftling' ),
				'compact' => true,
			)
		);
		?>
		<div class="swiftling-table-wrap">
			<table class="swiftling-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Page', 'swiftling' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'swiftling' ); ?></th>
					</tr>
				</thead>
				<tbody id="swiftling-all-rows"></tbody>
			</table>
		</div>
		<div class="swiftling-all-actions">
			<button type="button" class="swiftling-button is-secondary" id="swiftling-all-stop"><?php esc_html_e( 'Stop', 'swiftling' ); ?></button>
		</div>
	</section>

	<div class="swiftling-translate-all__layout" id="swiftling-all-finished" hidden>
		<div class="swiftling-stack">
			<div id="swiftling-all-status">
				<?php
				Ui::notice(
					'success',
					array(
						'title' => __( 'The language is translated.', 'swiftling' ),
						'text'  => __( 'Read the machine translations and mark them reviewed.', 'swiftling' ),
					)
				);
				?>
			</div>
			<section class="swiftling-card" id="swiftling-all-skipped" aria-labelledby="swiftling-all-skipped-title" hidden>
				<h2 id="swiftling-all-skipped-title"><?php esc_html_e( 'Pages that were skipped', 'swiftling' ); ?></h2>
				<p class="swiftling-muted"><?php esc_html_e( 'Open each one in the translation editor: it says what its texts are and translates them.', 'swiftling' ); ?></p>
				<div class="swiftling-table-wrap">
					<table class="swiftling-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Page', 'swiftling' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Why', 'swiftling' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Open', 'swiftling' ); ?></span></th>
							</tr>
						</thead>
						<tbody id="swiftling-all-skipped-rows"></tbody>
					</table>
				</div>
			</section>
		</div>
		<aside class="swiftling-translate-all__aside swiftling-stack">
			<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-all-next-title">
				<h2 id="swiftling-all-next-title"><?php esc_html_e( 'What now', 'swiftling' ); ?></h2>
				<p class="swiftling-muted"><?php esc_html_e( 'Machine translations wait to be read. The Translations screen shows every page and how far it has come.', 'swiftling' ); ?></p>
				<?php Ui::button( __( 'Go to Translations', 'swiftling' ), 'secondary', admin_url( 'admin.php?page=swiftling-content' ) ); ?>
			</section>
		</aside>
	</div>
</div>
<script type="application/json" id="swiftling-all-config"><?php echo wp_json_encode( $swiftling_config, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & escaped, inside a JSON script element. ?></script>
