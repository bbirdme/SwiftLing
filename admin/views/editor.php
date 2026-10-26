<?php
/**
 * Translation editor: every text of a page, from any builder, with its translation; or of a
 * template, the names and descriptions of the terms of a taxonomy, the items of a menu, texts a
 * builder keeps outside posts, such as an options page of Advanced Custom Fields, or the texts of a
 * theme or plugin, see includes/subjects.php.
 *
 * A page or template is checked in the browser: assets/js/editor.js loads it in hidden frames,
 * once with markers and once as visitors see it, reports where every marker ended up, and replaces
 * the placeholder rows with the texts the server returns. The rows of terms and of a builder's texts
 * are ready at once; a list of terms checks the page of a term first when a field of its terms may
 * show there, see includes/terms.php.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\PackageTabs;
use SwiftLing\Admin\Ui;
use SwiftLing\Builders\Builder;
use SwiftLing\Builders\Registry;
use SwiftLing\Translation\Services;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
if ( isset( $_GET['post'] ) ) {
	$swiftling_subject = swiftling_subject( 'post:' . absint( $_GET['post'] ) );
} else {
	$swiftling_subject = isset( $_GET['subject'] ) ? swiftling_subject( sanitize_text_field( wp_unslash( $_GET['subject'] ) ) ) : null;
}
// phpcs:enable

if ( ! $swiftling_subject ) {
	Ui::screenHeader(
		array(
			'crumb' => __( 'Translation editor', 'swiftling' ),
			'title' => __( 'Translation editor', 'swiftling' ),
		)
	);
	Ui::emptyState(
		__( 'Choose what to translate', 'swiftling' ),
		__( 'Open a page or post from Translations, or with Translate in the list of pages. Templates, categories and tags have their own tabs in Translations.', 'swiftling' ),
		array( __( 'Open translations', 'swiftling' ), admin_url( 'admin.php?page=swiftling-content' ) )
	);
	return;
}

if ( ! swiftling_can_translate_subject( $swiftling_subject ) ) {
	wp_die( esc_html__( 'You may not edit this content, so you cannot translate it either.', 'swiftling' ), '', array( 'response' => 403 ) );
}

if ( ! swiftling_languages()['targets'] ) {
	Ui::emptyState(
		__( 'Choose the languages first', 'swiftling' ),
		__( 'Tell SwiftLing which languages this content should be translated into.', 'swiftling' ),
		array( __( 'Add languages', 'swiftling' ), admin_url( 'admin.php?page=swiftling-languages' ) )
	);
	return;
}

$swiftling_is_post    = 'post' === $swiftling_subject['kind'];
$swiftling_is_package = 'package' === $swiftling_subject['kind'];
$swiftling_back       = $swiftling_is_post ? array( __( 'Translations', 'swiftling' ), admin_url( 'admin.php?page=swiftling-content' ) ) : array( __( 'Templates', 'swiftling' ), admin_url( 'admin.php?page=swiftling-templates' ) );
if ( $swiftling_is_package ) {
	$swiftling_back = array( __( 'Themes & plugins', 'swiftling' ), swiftling_package_url() );
} elseif ( 'terms' === $swiftling_subject['kind'] ) {
	$swiftling_back = array( __( 'Categories & tags', 'swiftling' ), admin_url( 'admin.php?page=swiftling-terms' ) );
} elseif ( 'menu' === $swiftling_subject['kind'] ) {
	$swiftling_back = array( __( 'Menus', 'swiftling' ), admin_url( 'admin.php?page=swiftling-menus' ) );
} elseif ( 'comments' === $swiftling_subject['kind'] ) {
	$swiftling_back = array( __( 'Comments', 'swiftling' ), admin_url( 'admin.php?page=swiftling-comments' ) );
}
$swiftling_languages = swiftling_languages();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen parameter.
$swiftling_lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
if ( ! swiftling_target_language( $swiftling_lang ) ) {
	$swiftling_lang = (string) key( $swiftling_languages['targets'] );
}
$swiftling_target = swiftling_target_language( $swiftling_lang );

// A post can have a separate copy for the language, see includes/copies.php.
$swiftling_copy     = $swiftling_is_post ? swiftling_copy_for( $swiftling_subject['post']->ID, $swiftling_target['locale'] ) : null;
$swiftling_can_copy = $swiftling_is_post && ! $swiftling_copy && swiftling_can_make_copy( $swiftling_subject['post'] );
// How the language shows the post: translated here, as the copy, or the original kept as it is.
$swiftling_mode       = $swiftling_is_post ? swiftling_language_mode( $swiftling_subject['post']->ID, $swiftling_target['locale'] ) : 'translation';
$swiftling_can_choose = $swiftling_is_post && swiftling_can_choose_mode( $swiftling_subject['post'] );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which message to show after a redirect.
$swiftling_notice = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';

// A list of terms, of a menu, of a builder's texts or of a theme's or plugin's texts needs no page
// check: its rows are ready now. Saving them first lets the state of the languages below count them.
$swiftling_rows   = null;
$swiftling_list   = array();
$swiftling_search = '';
$swiftling_check  = false;
if ( $swiftling_is_package ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Which texts of the list to show.
	$swiftling_search = isset( $_GET['find'] ) ? sanitize_text_field( wp_unslash( $_GET['find'] ) ) : '';
	$swiftling_list   = swiftling_save_package_list(
		$swiftling_subject,
		$swiftling_lang,
		array(
			'show'   => isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : '',
			'search' => $swiftling_search,
			'page'   => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
		)
	);
	// phpcs:enable
} elseif ( 'terms' === $swiftling_subject['kind'] ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Which terms of the list to show.
	$swiftling_search = isset( $_GET['find'] ) ? sanitize_text_field( wp_unslash( $_GET['find'] ) ) : '';
	$swiftling_list   = swiftling_save_term_list( $swiftling_subject );
	// A field of the terms that may show on the page of a term: the page is checked first, and the
	// rows come with what it found.
	$swiftling_check = swiftling_term_list_needs_check( $swiftling_subject, $swiftling_list['fields'] );
	$swiftling_list  = swiftling_term_list_page(
		$swiftling_list,
		array(
			'page'   => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
			'search' => $swiftling_search,
		)
	);
	// phpcs:enable
} elseif ( 'comments' === $swiftling_subject['kind'] ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Which comments of the list to show.
	$swiftling_search = isset( $_GET['find'] ) ? sanitize_text_field( wp_unslash( $_GET['find'] ) ) : '';
	$swiftling_list   = swiftling_save_comment_list(
		$swiftling_subject,
		$swiftling_lang,
		array(
			'show'   => isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : '',
			'search' => $swiftling_search,
			'for'    => isset( $_GET['for'] ) ? absint( $_GET['for'] ) : 0,
			'page'   => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
		)
	);
	// phpcs:enable
} elseif ( 'fields' === $swiftling_subject['kind'] ) {
	$swiftling_list = swiftling_save_field_list( $swiftling_subject );
} elseif ( 'menu' === $swiftling_subject['kind'] ) {
	$swiftling_list = swiftling_save_menu_list( $swiftling_subject );
}
if ( $swiftling_list && ! $swiftling_check ) {
	$swiftling_rows = array(
		'rows'   => swiftling_live_rows_html( $swiftling_subject, $swiftling_lang, $swiftling_list ),
		'extras' => swiftling_live_extras_count( $swiftling_list ),
	);
}

if ( ! $swiftling_subject['probe'] && ! $swiftling_rows ) {
	Ui::screenHeader(
		array(
			'crumb' => __( 'Templates', 'swiftling' ),
			'title' => $swiftling_subject['title'],
		)
	);
	Ui::emptyState(
		__( 'No page shows this template', 'swiftling' ),
		__( 'Visitors never see this template on this site right now, so there is nothing to translate. Once a page uses it, it can be translated here.', 'swiftling' ),
		array( __( 'Back to templates', 'swiftling' ), admin_url( 'admin.php?page=swiftling-templates' ) )
	);
	return;
}

$swiftling_states = swiftling_subject_states( $swiftling_subject['key'] );
$swiftling_counts = swiftling_subject_counts( $swiftling_subject['key'] );
// A list of texts from code shows one page at a time; its languages count the whole list, and a
// text its theme's or plugin's translators translated counts as translated.
// So does a list of comments, see includes/comments.php.
if ( $swiftling_is_package && $swiftling_list['list']['progress'] ) {
	$swiftling_counts = $swiftling_list['list']['progress'];
} elseif ( 'comments' === $swiftling_subject['kind'] ) {
	$swiftling_counts = $swiftling_list['progress'];
}
if ( ( $swiftling_is_package && $swiftling_list['list']['progress'] ) || 'comments' === $swiftling_subject['kind'] ) {
	foreach ( $swiftling_counts as $swiftling_slug => $swiftling_count ) {
		// Machine translations not reviewed yet show as such, where the list counts them.
		$swiftling_done                      = empty( $swiftling_count['machine'] ) ? 'reviewed' : 'machine';
		$swiftling_states[ $swiftling_slug ] = $swiftling_count['translated'] >= $swiftling_count['total'] ? $swiftling_done : ( $swiftling_count['translated'] ? 'partial' : 'missing' );
	}
}
$swiftling_subtitle = $swiftling_subject['type'];
if ( $swiftling_is_post ) {
	$swiftling_built = array_map(
		static function ( Builder $builder ): string {
			return $builder->name();
		},
		array_values( Registry::forPost( $swiftling_subject['post']->ID ) )
	);
	if ( $swiftling_built ) {
		/* translators: 1: kind of content, e.g. Page, 2: builders, e.g. Elementor. */
		$swiftling_subtitle = sprintf( __( '%1$s, built with %2$s', 'swiftling' ), $swiftling_subject['type'], wp_sprintf( '%l', $swiftling_built ) );
	}
}
$swiftling_view_url = $swiftling_subject['url'];
// Where the page opens in the language: a published post at its translated address.
if ( '' === $swiftling_view_url ) {
	$swiftling_view_in = '';
} elseif ( $swiftling_is_post && 'publish' === $swiftling_subject['post']->post_status ) {
	$swiftling_view_in = swiftling_post_language_url( $swiftling_subject['post'], $swiftling_lang );
} else {
	$swiftling_view_in = swiftling_language_url( $swiftling_view_url, $swiftling_lang );
}
// Where the header, footer and menu around a post are translated.
$swiftling_frame = $swiftling_is_post ? swiftling_post_view_subject( $swiftling_subject['post'] ) : null;

// A list of terms checks its page only when it needs to, see swiftling_term_list_needs_check().
$swiftling_probes = $swiftling_subject['probe'] && ( 'terms' !== $swiftling_subject['kind'] || $swiftling_check ) ? array(
	'marked'  => swiftling_probe_url( $swiftling_subject, 'marked' ),
	'control' => swiftling_probe_url( $swiftling_subject, 'control' ),
) : null;

// Machine translation with the service that translates this subject into the language, when one is
// connected and translates both languages.
$swiftling_service = Services::forTarget( $swiftling_subject['key'], $swiftling_target );
// A translator the site's manager keeps from machine translation works as without a service.
if ( ! swiftling_can_machine_translate() ) {
	$swiftling_service = null;
}
$swiftling_unsupported      = '';
$swiftling_unsupported_note = '';
if ( $swiftling_service ) {
	foreach ( array( $swiftling_languages['source'], $swiftling_target ) as $swiftling_needed ) {
		if ( '' === $swiftling_unsupported && null === $swiftling_service->languageCode( $swiftling_needed ) ) {
			/* translators: 1: service name, e.g. Google, 2: language name, e.g. Montenegrin. */
			$swiftling_unsupported = sprintf( __( '%1$s does not translate %2$s.', 'swiftling' ), $swiftling_service->name(), $swiftling_needed['name'] );
			/* translators: 1: service name, e.g. Google, 2: language name, e.g. Montenegrin. */
			$swiftling_unsupported_note = sprintf( __( '%1$s does not translate %2$s, so machine translation is off for this language. Write the translations here, or connect another service.', 'swiftling' ), $swiftling_service->name(), $swiftling_needed['name'] );
		}
	}
}

$swiftling_config = array(
	'subject'  => $swiftling_subject['key'],
	'lang'     => $swiftling_lang,
	'rest'     => esc_url_raw( rest_url( 'swiftling/v1/' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'probes'   => $swiftling_probes,
	'rows'     => $swiftling_rows,
	'labels'   => Ui::statusLabels(),
	'machine'  => $swiftling_service && '' === $swiftling_unsupported ? array( 'service' => $swiftling_service->name() ) : null,
	// A list of texts from code, or of terms, shows one page at a time: its languages count the
	// whole list.
	'paged'    => ( $swiftling_is_package && 'settings' !== $swiftling_subject['view'] ) || 'comments' === $swiftling_subject['kind'] || ( isset( $swiftling_list['list'] ) && 'terms' === $swiftling_subject['kind'] && ( $swiftling_list['list']['pages'] > 1 || '' !== $swiftling_search ) ),
	// Which terms of a list the rows show, for the rows that come after its page check.
	'list'     => 'terms' === $swiftling_subject['kind'] ? array(
		'paged' => $swiftling_list['list']['page'],
		'find'  => $swiftling_search,
	) : null,
	// Which comments translating every one without a translation goes through, see includes/comments.php.
	'comments' => 'comments' === $swiftling_subject['kind'] ? array(
		'missing' => $swiftling_list['list']['counts']['missing'],
		'for'     => $swiftling_list['list']['for'],
		'find'    => $swiftling_search,
	) : null,
	'strings'  => array(
		/* translators: %s: seconds since the check started. */
		'stepOpen'   => __( 'Preparing the translation fields: loading the page · %s s', 'swiftling' ),
		/* translators: %s: seconds since the check started. */
		'stepRead'   => __( 'Preparing the translation fields: finding the texts · %s s', 'swiftling' ),
		/* translators: %s: seconds since the check started. */
		'stepSave'   => __( 'Preparing the translation fields: saving · %s s', 'swiftling' ),
		'ready'      => __( 'The translation fields are ready.', 'swiftling' ),
		'failed'     => __( 'The page could not be checked. Reload the editor to try again.', 'swiftling' ),
		'pickImage'  => __( 'Choose the image for this language', 'swiftling' ),
		'useImage'   => __( 'Use this image', 'swiftling' ),
		'saving'     => __( 'Saving…', 'swiftling' ),
		'saved'      => __( 'Saved.', 'swiftling' ),
		'nothing'    => __( 'No changes to save.', 'swiftling' ),
		'saveFailed' => __( 'The translations could not be saved.', 'swiftling' ),
		'noEmpty'    => __( 'Every field is filled in.', 'swiftling' ),
		'leave'      => __( 'Some translations are not saved yet.', 'swiftling' ),
		'noMachine'  => __( 'Every text field is filled in. Empty a field to translate it again.', 'swiftling' ),
		'noAgain'    => __( 'No translation of this page is in that state.', 'swiftling' ),
		'machineOff' => __( 'The translation stopped.', 'swiftling' ),
		'slugHint'   => __( 'Empty: the original slug. A changed address keeps leading here from the old one.', 'swiftling' ),
		'slugMade'   => __( 'Made from the translated title. Change it if you like.', 'swiftling' ),
		'slugSaved'  => __( 'The address is saved.', 'swiftling' ),
		'slugFailed' => __( 'The address could not be saved.', 'swiftling' ),
	),
);
?>
<div class="swiftling-editor-bar">
	<?php Ui::button( $swiftling_back[0], 'link is-back', $swiftling_back[1], 'arrow-left-alt2' ); ?>
	<div class="swiftling-editor-bar__title">
		<h1><?php echo esc_html( $swiftling_subject['title'] ); ?></h1>
		<span class="swiftling-muted"><?php echo esc_html( $swiftling_subtitle ); ?></span>
	</div>
	<div class="swiftling-editor-bar__actions">
	<?php if ( '' !== $swiftling_view_url ) : ?>
		<a class="swiftling-button is-ghost" href="<?php echo esc_url( swiftling_language_url( $swiftling_view_url, '' ) ); ?>" target="_blank" rel="noopener">
			<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
			<?php esc_html_e( 'View original', 'swiftling' ); ?>
		</a>
		<a class="swiftling-button is-ghost" id="swiftling-view-in" href="<?php echo esc_url( $swiftling_view_in ); ?>" target="_blank" rel="noopener">
			<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
			<?php
			/* translators: %s: language name, e.g. Deutsch. */
			echo esc_html( sprintf( __( 'View in %s', 'swiftling' ), $swiftling_target['name'] ) );
			?>
		</a>
	<?php endif; ?>
	<?php if ( 'translation' === $swiftling_mode ) : ?>
		<?php // How many fields changed since the last save, next to the button that saves them. ?>
		<span class="swiftling-live-unsaved" id="swiftling-live-unsaved" aria-live="polite"></span>
	<button type="button" class="swiftling-button is-accent swiftling-live-save" id="swiftling-live-save" disabled>
		<span class="swiftling-spinner" aria-hidden="true"></span>
		<?php esc_html_e( 'Save and mark reviewed', 'swiftling' ); ?>
	</button>
	<?php endif; ?>
	</div>
	<?php if ( 'translation' === $swiftling_mode ) : ?>
		<?php // The bar stays at the top of the window, so the state of a save shows wherever the user is on the page. ?>
	<div class="swiftling-editor-bar__status">
		<div class="swiftling-editor-bar__messages">
			<p class="swiftling-live-status" id="swiftling-live-status" role="status"><?php esc_html_e( 'Checking the page…', 'swiftling' ); ?></p>
			<?php // What the last save or tool did, under the Save button. ?>
			<p class="swiftling-live-note" id="swiftling-live-note" role="status"></p>
		</div>
		<div class="swiftling-live-progress" id="swiftling-live-progress">
			<div class="swiftling-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Progress', 'swiftling' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="is-progress"></span></div>
		</div>
	</div>
	<?php endif; ?>
</div>
<?php // The languages under the bar: there can be many, and the bar that stays on screen stays one line. ?>
<nav class="swiftling-segmented swiftling-editor-languages" aria-label="<?php esc_attr_e( 'Language', 'swiftling' ); ?>">
	<?php foreach ( $swiftling_languages['targets'] as $swiftling_slug => $swiftling_language ) : ?>
		<?php
		$swiftling_current = $swiftling_lang === $swiftling_slug;
		// A language shown as a separate copy or keeping the original is not translated here: no count.
		$swiftling_count = isset( $swiftling_counts[ $swiftling_slug ] ) && ( ! $swiftling_is_post || 'translation' === swiftling_language_mode( $swiftling_subject['post']->ID, $swiftling_language['locale'] ) ) ? $swiftling_counts[ $swiftling_slug ] : null;
		?>
		<a class="<?php echo $swiftling_current ? 'is-current' : ''; ?>" href="<?php echo esc_url( swiftling_editor_url( $swiftling_subject['key'], $swiftling_slug ) ); ?>"<?php echo $swiftling_current ? ' aria-current="page"' : ''; ?> data-lang="<?php echo esc_attr( $swiftling_slug ); ?>">
			<?php Ui::statusIcon( $swiftling_states[ $swiftling_slug ] ); ?>
			<span class="swiftling-segmented__code"><?php echo esc_html( $swiftling_language['code'] ); ?></span>
			<?php if ( $swiftling_count && $swiftling_count['total'] ) : ?>
				<span class="swiftling-segmented__progress">
					<span class="swiftling-segmented__count">
						<?php
						/* translators: 1: texts translated, 2: all texts, as in 24/28. */
						echo esc_html( sprintf( __( '%1$s/%2$s', 'swiftling' ), number_format_i18n( $swiftling_count['translated'] ), number_format_i18n( $swiftling_count['total'] ) ) );
						?>
					</span>
					<span class="swiftling-bar swiftling-segmented__meter" aria-hidden="true"><span class="is-reviewed" style="width: <?php echo esc_attr( (string) round( 100 * $swiftling_count['translated'] / $swiftling_count['total'], 1 ) ); ?>%"></span></span>
				</span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>

<?php
if ( $swiftling_is_package ) {
	PackageTabs::render( \SwiftLing\Packages\Packages::get( $swiftling_subject['package'] ), $swiftling_subject['view'], $swiftling_lang );
	if ( 'settings' !== $swiftling_subject['view'] ) {
		PackageTabs::filters( $swiftling_subject, $swiftling_lang, $swiftling_list['list'], $swiftling_search );
	}
} elseif ( 'comments' === $swiftling_subject['kind'] ) {
	$swiftling_for = $swiftling_list['list']['for'] ? get_post( $swiftling_list['list']['for'] ) : null;
	if ( $swiftling_for ) {
		?>
		<p class="swiftling-list-scope">
			<?php
			/* translators: %s: title of a page. */
			echo esc_html( sprintf( __( 'Only the comments on “%s”.', 'swiftling' ), get_the_title( $swiftling_for ) ) );
			?>
			<a href="<?php echo esc_url( swiftling_editor_url( $swiftling_subject['key'], $swiftling_lang ) ); ?>"><?php esc_html_e( 'Show the comments on every page', 'swiftling' ); ?></a>
		</p>
		<?php
	}
	Ui::listFilters(
		array(
			'subject'     => $swiftling_subject['key'],
			'lang'        => $swiftling_lang,
			'keep'        => $swiftling_for ? array( 'for' => (string) $swiftling_for->ID ) : array(),
			'shows'       => array(
				'missing' => array( __( 'Without a translation', 'swiftling' ), $swiftling_list['list']['counts']['missing'] ),
				'all'     => array( __( 'All', 'swiftling' ), $swiftling_list['list']['counts']['all'] ),
			),
			'show'        => $swiftling_list['list']['show'],
			'search'      => $swiftling_search,
			'placeholder' => __( 'Search comments', 'swiftling' ),
			'page'        => $swiftling_list['list']['page'],
			'pages'       => $swiftling_list['list']['pages'],
			'per_page'    => SWIFTLING_COMMENTS_PER_PAGE,
			'shown'       => $swiftling_list['list']['shown'],
		)
	);
} elseif ( 'terms' === $swiftling_subject['kind'] && ( $swiftling_list['list']['pages'] > 1 || '' !== $swiftling_search ) ) {
	Ui::listFilters(
		array(
			'subject'     => $swiftling_subject['key'],
			'lang'        => $swiftling_lang,
			'search'      => $swiftling_search,
			'placeholder' => __( 'Search terms', 'swiftling' ),
			'page'        => $swiftling_list['list']['page'],
			'pages'       => $swiftling_list['list']['pages'],
			'per_page'    => SWIFTLING_TERMS_PER_PAGE,
			'shown'       => $swiftling_list['list']['shown'],
		)
	);
}

$swiftling_messages = array(
	'mode_copy_made'         => array( 'is-success', __( 'The copy is made. Edit it, and publish it when it is ready.', 'swiftling' ) ),
	/* translators: %s: language name, e.g. Deutsch. */
	'mode_translation'       => array( 'is-success', sprintf( __( '%s shows this page translated in this editor.', 'swiftling' ), $swiftling_target['name'] ) ),
	/* translators: 1: language name, e.g. Deutsch, 2: the original's language, e.g. English. */
	'mode_duplicate'         => array( 'is-success', sprintf( __( '%1$s keeps the original: visitors there see this page in %2$s, as it is.', 'swiftling' ), $swiftling_target['name'], swiftling_languages()['source']['name'] ) ),
	'mode_copy_kept'         => array( 'is-info', __( 'This language already has a separate copy.', 'swiftling' ) ),
	'swiftling_copy_refused' => array( 'is-info', __( 'This content cannot have a separate copy.', 'swiftling' ) ),
	'swiftling_copy_kept'    => array( 'is-info', __( 'You may not remove the separate copy.', 'swiftling' ) ),
	'swiftling_mode_refused' => array( 'is-info', __( 'This cannot be changed here.', 'swiftling' ) ),
);

/**
 * Prints a button that chooses how the language shows the post, see swiftling_set_language_mode().
 *
 * @param string $mode    translation, manual or duplicate.
 * @param string $label   Text of the button.
 * @param string $variant Button variant.
 * @param string $note    Optional text next to the button.
 * @param string $classes Optional classes of the form.
 */
$swiftling_mode_fields = static function ( $mode ) use ( $swiftling_subject, $swiftling_lang ) {
	?>
	<input type="hidden" name="action" value="swiftling_language_mode">
	<input type="hidden" name="post" value="<?php echo esc_attr( (string) $swiftling_subject['post']->ID ); ?>">
	<input type="hidden" name="lang" value="<?php echo esc_attr( $swiftling_lang ); ?>">
	<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
	<?php
	wp_nonce_field( 'swiftling_mode_' . $swiftling_subject['post']->ID );
};

$swiftling_mode_form = static function ( $mode, $label, $variant, $note = '', $classes = '' ) use ( $swiftling_mode_fields ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-copy__mode <?php echo esc_attr( $classes ); ?>">
		<?php $swiftling_mode_fields( $mode ); ?>
		<button type="submit" class="swiftling-button is-<?php echo esc_attr( $variant ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php if ( '' !== $note ) : ?>
			<span class="swiftling-muted"><?php echo esc_html( $note ); ?></span>
		<?php endif; ?>
	</form>
	<?php
};

/**
 * Prints another way the language can show the post, as an option of the side column: its name
 * and what it means, all of it the button that chooses it.
 *
 * @param string $mode  manual or duplicate.
 * @param string $title Name of the way.
 * @param string $text  What it means.
 */
$swiftling_way = static function ( $mode, $title, $text ) use ( $swiftling_mode_fields ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php $swiftling_mode_fields( $mode ); ?>
		<button type="submit" class="swiftling-way">
			<strong><?php echo esc_html( $title ); ?></strong>
			<span><?php echo esc_html( $text ); ?></span>
		</button>
	</form>
	<?php
};
?>
<?php if ( isset( $swiftling_messages[ $swiftling_notice ] ) ) : ?>
	<?php Ui::notice( substr( $swiftling_messages[ $swiftling_notice ][0], 3 ), array( 'text' => $swiftling_messages[ $swiftling_notice ][1] ) ); ?>
<?php endif; ?>
<?php
// After translating every comment without a translation, the editor opens again on what is left.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which message to show after the run.
$swiftling_run = 'comments' === $swiftling_subject['kind'] && $swiftling_service && isset( $_GET['translated'] ) ? absint( $_GET['translated'] ) : null;
if ( null !== $swiftling_run ) {
	Ui::notice(
		'success',
		array(
			'text' => sprintf(
				/* translators: 1: number of comments, 2: translation service name, e.g. Google. */
				_n( '%1$s comment translated with %2$s and saved as a machine translation. Read it in the list of all comments, then save to mark it reviewed.', '%1$s comments translated with %2$s and saved as machine translations. Read them in the list of all comments, then save to mark them reviewed.', $swiftling_run, 'swiftling' ),
				number_format_i18n( $swiftling_run ),
				$swiftling_service->name()
			),
		)
	);
}
?>

<?php
// What else to know about the subject: where the header and footer around a post are translated,
// which page a template is checked on, that a list of terms is not complete, or what the texts a
// builder lists are.
$swiftling_about = '';
if ( $swiftling_frame ) {
	$swiftling_about = __( 'Around this page', 'swiftling' );
} elseif ( in_array( $swiftling_subject['kind'], array( 'template', 'view' ), true ) && '' !== $swiftling_view_url ) {
	$swiftling_about = __( 'Example page', 'swiftling' );
} elseif ( 'terms' === $swiftling_subject['kind'] && ! empty( $swiftling_list['more'] ) ) {
	$swiftling_about = __( 'More terms', 'swiftling' );
} elseif ( ! empty( $swiftling_subject['note'] ) ) {
	$swiftling_about = __( 'About these texts', 'swiftling' );
}
$swiftling_about_note = static function () use ( $swiftling_frame, $swiftling_subject, $swiftling_lang, $swiftling_view_url, $swiftling_list ) {
	?>
	<?php if ( $swiftling_frame ) : ?>
		<p class="swiftling-live-frame">
			<?php
			/* translators: %s: name of a template, e.g. Pages. */
			echo esc_html( sprintf( __( 'The header, footer, menu and site title around this page are translated once for the whole site, in the template “%s”.', 'swiftling' ), $swiftling_frame['title'] ) );
			?>
			<a href="<?php echo esc_url( swiftling_editor_url( $swiftling_frame['key'], $swiftling_lang ) ); ?>"><?php esc_html_e( 'Translate the template', 'swiftling' ); ?></a>
		</p>
	<?php elseif ( in_array( $swiftling_subject['kind'], array( 'template', 'view' ), true ) && '' !== $swiftling_view_url ) : ?>
		<p class="swiftling-live-frame">
			<?php
			/* translators: %s: address of an example page, e.g. /category/news/. */
			echo esc_html( sprintf( __( 'Checked on an example page made from this template: %s. The content of posts on it is translated in the editor of each post.', 'swiftling' ), wp_make_link_relative( $swiftling_view_url ) ) );
			?>
		</p>
	<?php elseif ( 'terms' === $swiftling_subject['kind'] && ! empty( $swiftling_list['more'] ) ) : ?>
		<p class="swiftling-live-frame">
			<?php
			/* translators: 1: number of terms shown, 2: number of terms left out. */
			echo esc_html( sprintf( __( 'The %1$s most used are listed. %2$s more are translated where they show, in the editor of the template that shows them.', 'swiftling' ), number_format_i18n( SWIFTLING_TERM_LIST_LIMIT ), number_format_i18n( $swiftling_list['more'] ) ) );
			?>
		</p>
	<?php elseif ( ! empty( $swiftling_subject['note'] ) ) : ?>
		<p class="swiftling-live-frame"><?php echo esc_html( $swiftling_subject['note'] ); ?></p>
	<?php endif; ?>
	<?php
};

if ( 'translation' !== $swiftling_mode ) {
	$swiftling_about_note();
}
?>

<?php
if ( $swiftling_copy ) :
	$swiftling_published = 'publish' === $swiftling_copy->post_status;
	$swiftling_original  = $swiftling_subject['post'];
	?>
	<section class="swiftling-card swiftling-copy" aria-labelledby="swiftling-copy-title">
		<h2 id="swiftling-copy-title">
			<?php
			echo esc_html(
				$swiftling_published
					/* translators: %s: language name, e.g. Deutsch. */
					? sprintf( __( '%s shows a separate copy', 'swiftling' ), $swiftling_target['name'] )
					/* translators: %s: language name, e.g. Deutsch. */
					: sprintf( __( '%s gets a separate copy', 'swiftling' ), $swiftling_target['name'] )
			);
			?>
			<?php Ui::badge( $swiftling_published ? 'published' : 'hidden', $swiftling_published ? '' : __( 'Draft', 'swiftling' ) ); ?>
		</h2>
		<p>
			<?php
			echo esc_html(
				$swiftling_published
					/* translators: %s: language name, e.g. Deutsch. */
					? sprintf( __( 'Visitors who open this page in %s see the copy, at the same address. Change it in its own editor: translations made here no longer show in this language.', 'swiftling' ), $swiftling_target['name'] )
					: __( 'The copy is a draft. Until it is published, visitors see this page translated as before. It started with the translations made here; change anything in it, as in any page.', 'swiftling' )
			);
			?>
		</p>
		<?php if ( swiftling_copy_is_outdated( $swiftling_copy ) ) : ?>
			<?php
			Ui::notice(
				'warning',
				array(
					/* translators: %s: date and time, e.g. 4 October 2026 at 18:00. */
					'text'    => sprintf( __( 'The original changed on %s, after the copy was last edited. Check whether the copy needs the same change.', 'swiftling' ), get_post_modified_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), false, $swiftling_original, true ) ),
					'compact' => true,
				)
			);
			?>
		<?php endif; ?>
		<div class="swiftling-copy__actions">
			<?php Ui::button( __( 'Edit the copy', 'swiftling' ), 'primary', Registry::editUrl( $swiftling_copy->ID ), 'edit' ); ?>
			<?php
			if ( $swiftling_published ) {
				/* translators: %s: language name, e.g. Deutsch. */
				Ui::button( sprintf( __( 'View in %s', 'swiftling' ), $swiftling_target['name'] ), 'secondary', (string) get_permalink( $swiftling_copy ), 'visibility' );
			} else {
				Ui::button( __( 'Preview the copy', 'swiftling' ), 'secondary', (string) get_preview_post_link( $swiftling_copy ), 'visibility' );
			}
			?>
			<?php
			if ( $swiftling_can_choose && current_user_can( 'delete_post', $swiftling_copy->ID ) ) {
				$swiftling_mode_form( 'translation', __( 'Stop using the copy', 'swiftling' ), 'danger', __( 'The copy goes to the trash, where it can be restored. The page is translated here again.', 'swiftling' ), 'swiftling-copy__stop' );
			}
			?>
		</div>
		<p class="swiftling-muted"><?php esc_html_e( 'Categories and tags come from the original and change with it. Their names are translated as everywhere.', 'swiftling' ); ?></p>
	</section>
	<?php
	return;
endif;

if ( 'duplicate' === $swiftling_mode ) :
	?>
	<section class="swiftling-card swiftling-copy" aria-labelledby="swiftling-copy-title">
		<h2 id="swiftling-copy-title">
			<?php
			/* translators: %s: language name, e.g. Deutsch. */
			echo esc_html( sprintf( __( '%s keeps the original', 'swiftling' ), $swiftling_target['name'] ) );
			?>
			<?php Ui::badge( 'duplicate' ); ?>
		</h2>
		<p>
			<?php
			/* translators: 1: language name, e.g. Deutsch, 2: the original's language, e.g. English. */
			echo esc_html( sprintf( __( 'Visitors in %1$s see this page in %2$s, as it is, also after every change of it. The header, footer and menus around it are translated. It counts as done, so Translate everything skips it. The translations made here are kept, for when you translate the page again.', 'swiftling' ), $swiftling_target['name'], swiftling_languages()['source']['name'] ) );
			?>
		</p>
		<div class="swiftling-copy__actions">
			<?php
			if ( $swiftling_can_choose ) {
				$swiftling_mode_form( 'translation', __( 'Translate it in this editor', 'swiftling' ), 'primary' );
			}
			if ( $swiftling_can_choose && swiftling_can_make_copy( $swiftling_subject['post'] ) ) {
				$swiftling_mode_form( 'manual', __( 'Make a separate copy', 'swiftling' ), 'secondary' );
			}
			if ( '' !== $swiftling_view_url ) {
				/* translators: %s: language name, e.g. Deutsch. */
				Ui::button( sprintf( __( 'View in %s', 'swiftling' ), $swiftling_target['name'] ), 'secondary', $swiftling_view_in, 'visibility' );
			}
			?>
		</div>
	</section>
	<?php
	return;
endif;
?>

<div class="swiftling-editor-layout">
	<div class="swiftling-card is-flush swiftling-editor swiftling-live-editor">
		<div class="swiftling-live-head">
			<div class="swiftling-live-head__title">
				<h2><?php echo esc_html( $swiftling_target['name'] ); ?></h2>
				<?php // How many texts are translated: counted in the browser from the fields, as they change. ?>
				<p class="swiftling-live-summary" id="swiftling-live-summary" aria-live="polite"></p>
			</div>
			<div class="swiftling-bar swiftling-live-meter" id="swiftling-live-meter" aria-hidden="true"><span class="is-reviewed"></span><span class="is-machine"></span></div>
			<?php // What fills the empty fields, in a box of its own; which rows show is in the tabs on the table. ?>
			<div class="swiftling-live-fill" role="group" aria-labelledby="swiftling-live-fill-label">
				<span class="swiftling-live-toolbar__label" id="swiftling-live-fill-label"><?php esc_html_e( 'Fill empty fields', 'swiftling' ); ?></span>
				<?php if ( $swiftling_service ) : ?>
					<?php // Named after the service in use, which can differ per language. Off for a language it does not translate, with a tooltip over it that says why. ?>
					<?php if ( '' !== $swiftling_unsupported ) : ?>
						<span class="swiftling-tip is-above swiftling-live-machine-off">
					<?php endif; ?>
					<button type="button" class="swiftling-button is-secondary is-strong swiftling-live-machine" id="swiftling-live-machine" disabled<?php echo '' !== $swiftling_unsupported ? ' aria-describedby="swiftling-live-machine-note"' : ''; ?>>
						<span class="swiftling-spinner" aria-hidden="true"></span>
						<?php
						/* translators: %s: translation service name, e.g. Google. */
						echo esc_html( sprintf( __( 'Translate empty fields with %s', 'swiftling' ), $swiftling_service->name() ) );
						?>
					</button>
					<?php if ( '' !== $swiftling_unsupported ) : ?>
							<span class="swiftling-tip__text" role="tooltip"><?php echo esc_html( $swiftling_unsupported ); ?></span>
						</span>
					<?php endif; ?>
					<?php if ( 'comments' === $swiftling_subject['kind'] && $swiftling_list['list']['counts']['missing'] > count( $swiftling_list['units'] ) ) : ?>
						<?php // Every comment of the list without a translation, on every page of it, in batches. ?>
						<button type="button" class="swiftling-button is-secondary swiftling-live-machine" id="swiftling-live-machine-all" disabled>
							<span class="swiftling-spinner" aria-hidden="true"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of comments, 2: translation service name, e.g. Google. */
									_n( 'Translate %1$s comment without a translation with %2$s', 'Translate all %1$s comments without a translation with %2$s', $swiftling_list['list']['counts']['missing'], 'swiftling' ),
									number_format_i18n( $swiftling_list['list']['counts']['missing'] ),
									$swiftling_service->name()
								)
							);
							?>
						</button>
					<?php endif; ?>
				<?php else : ?>
					<?php Ui::button( __( 'Set up machine translation', 'swiftling' ), 'secondary', admin_url( 'admin.php?page=swiftling-service' ), 'translation' ); ?>
				<?php endif; ?>
				<button type="button" class="swiftling-button is-secondary" id="swiftling-live-copy" disabled><?php esc_html_e( 'Copy the original into empty fields', 'swiftling' ); ?></button>
			</div>
			<?php
			/**
			 * Fires in the head of the translation editor, after the tools that fill the empty
			 * fields, so another plugin, as SwiftLing Pro, can print its own: it works with the
			 * fields through window.swiftlingEditor, see assets/js/editor.js. See docs/hooks.md.
			 *
			 * @param array        $subject  What is translated, see swiftling_subject().
			 * @param array        $language The language shown, as swiftling_languages() gives it.
			 * @param Service|null $service  The service that translates it, or null.
			 */
			do_action( 'swiftling_editor_actions', $swiftling_subject, $swiftling_target, $swiftling_service );
			?>
			<?php if ( $swiftling_service && '' !== $swiftling_unsupported ) : ?>
				<div id="swiftling-live-machine-note">
					<?php
					Ui::notice(
						'warning',
						array(
							'text'    => $swiftling_unsupported_note,
							'link'    => array( __( 'Translation service', 'swiftling' ), admin_url( 'admin.php?page=swiftling-service' ) ),
							'compact' => true,
						)
					);
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php // Which rows the table shows: one view at a time, as tabs on its top edge. ?>
		<div class="swiftling-live-views">
			<div class="swiftling-live-tabs" role="radiogroup" aria-label="<?php esc_attr_e( 'Show', 'swiftling' ); ?>">
				<label class="swiftling-live-tab">
					<input type="radio" name="swiftling-live-view" value="texts" id="swiftling-live-view-texts" checked>
					<span><?php esc_html_e( 'Texts', 'swiftling' ); ?> <span class="swiftling-live-tab__count" id="swiftling-live-texts-count"></span></span>
				</label>
				<label class="swiftling-live-tab">
					<input type="radio" name="swiftling-live-view" value="empty" id="swiftling-live-only-empty">
					<span><?php esc_html_e( 'Empty', 'swiftling' ); ?> <span class="swiftling-live-tab__count" id="swiftling-live-empty-count"></span></span>
				</label>
				<label class="swiftling-live-tab">
					<input type="radio" name="swiftling-live-view" value="extras" id="swiftling-live-extras">
					<span><?php esc_html_e( 'Images, links and code', 'swiftling' ); ?> <span class="swiftling-live-tab__count" id="swiftling-live-extras-count"></span></span>
				</label>
			</div>
			<button type="button" class="swiftling-live-switch" id="swiftling-live-mark-empty" aria-pressed="false" disabled><?php esc_html_e( 'Highlight empty fields', 'swiftling' ); ?></button>
		</div>
		<table class="swiftling-table" id="swiftling-live-table">
			<thead>
				<tr>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Part', 'swiftling' ); ?></span></th>
					<th><?php Ui::lang( $swiftling_languages['source']['code'], true ); ?> <?php esc_html_e( 'Original', 'swiftling' ); ?></th>
					<th><?php Ui::lang( $swiftling_target['code'] ); ?> <?php echo esc_html( $swiftling_target['name'] ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Status', 'swiftling' ); ?></span></th>
				</tr>
			</thead>
			<tbody class="swiftling-live-placeholder">
				<tr>
					<td colspan="4" class="swiftling-muted"><?php esc_html_e( 'The page opens in the background, in your browser. Every text found on it is listed here.', 'swiftling' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="swiftling-live-all-done" id="swiftling-live-all-done" hidden><?php esc_html_e( 'Everything is translated.', 'swiftling' ); ?></p>
	</div>

	<?php // What is good to know next to the editor, and other ways for the language. ?>
	<aside class="swiftling-editor-layout__aside">
		<?php if ( $swiftling_can_choose ) : ?>
			<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-ways-title">
				<h2 id="swiftling-ways-title">
					<?php
					/* translators: %s: language name, e.g. Deutsch. */
					echo esc_html( sprintf( __( 'How %s shows this page', 'swiftling' ), $swiftling_target['name'] ) );
					?>
				</h2>
				<div class="swiftling-ways">
					<p class="swiftling-way is-current" aria-current="true">
						<strong><?php esc_html_e( 'Translated here', 'swiftling' ); ?></strong>
						<span><?php esc_html_e( 'The texts of this page, in this editor.', 'swiftling' ); ?></span>
					</p>
					<?php
					if ( $swiftling_can_copy ) {
						$swiftling_way(
							'manual',
							__( 'Separate copy', 'swiftling' ),
							$swiftling_built
								/* translators: %s: builder, e.g. Elementor. */
								? sprintf( __( 'A different layout, changed by hand in %s. It starts with the translations made here.', 'swiftling' ), wp_sprintf( '%l', $swiftling_built ) )
								: __( 'A different layout, changed by hand in its own editor. It starts with the translations made here.', 'swiftling' )
						);
					}
					$swiftling_way(
						'duplicate',
						__( 'Keep the original', 'swiftling' ),
						/* translators: 1: language name, e.g. Deutsch, 2: the original's language, e.g. English. */
						sprintf( __( 'Visitors in %1$s see this page in %2$s, as it is. It counts as done, so Translate everything skips it. The translations made here are kept.', 'swiftling' ), $swiftling_target['name'], swiftling_languages()['source']['name'] )
					);
					?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $swiftling_is_post ) : ?>
			<?php $swiftling_address = swiftling_post_address( $swiftling_subject['post'], $swiftling_lang ); ?>
			<section class="swiftling-card swiftling-side swiftling-address" aria-labelledby="swiftling-address-title">
				<h2 id="swiftling-address-title">
					<?php
					/* translators: %s: language name, e.g. Deutsch. */
					echo esc_html( sprintf( __( 'Address in %s', 'swiftling' ), $swiftling_target['name'] ) );
					?>
				</h2>
				<?php if ( ! swiftling_directories_supported() ) : ?>
					<p class="swiftling-live-frame">
						<?php esc_html_e( 'Translated addresses need pretty permalinks: with plain ones, addresses name pages by their number.', 'swiftling' ); ?>
						<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'Permalink settings', 'swiftling' ); ?></a>
					</p>
				<?php elseif ( '' === $swiftling_address['original'] ) : ?>
					<p class="swiftling-live-frame"><?php esc_html_e( 'This page gets its address when it is published. Then it can have one in every language.', 'swiftling' ); ?></p>
				<?php else : ?>
					<form class="swiftling-address__form" id="swiftling-address-form">
						<label for="swiftling-address-slug"><?php esc_html_e( 'Slug', 'swiftling' ); ?></label>
						<code class="swiftling-address__prefix swiftling-mono" id="swiftling-address-prefix"><?php echo esc_html( $swiftling_address['prefix'] ); ?></code>
						<span class="swiftling-address__field">
							<input type="text" id="swiftling-address-slug" class="swiftling-mono" value="<?php echo esc_attr( rawurldecode( $swiftling_address['slug'] ) ); ?>" placeholder="<?php echo esc_attr( rawurldecode( $swiftling_address['original'] ) ); ?>" maxlength="<?php echo esc_attr( (string) SWIFTLING_SLUG_LENGTH ); ?>" autocomplete="off" spellcheck="false">
							<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Save', 'swiftling' ); ?></button>
						</span>
						<p class="swiftling-muted" id="swiftling-address-note"><?php echo esc_html( 'machine' === $swiftling_address['state'] ? $swiftling_config['strings']['slugMade'] : $swiftling_config['strings']['slugHint'] ); ?></p>
						<?php swiftling_print_base_note( 'type', $swiftling_subject['post']->post_type, $swiftling_lang ); ?>
						<a class="swiftling-address__url swiftling-mono" id="swiftling-address-url" href="<?php echo esc_url( $swiftling_address['url'] ); ?>" target="_blank" rel="noopener"<?php echo '' === $swiftling_address['url'] ? ' hidden' : ''; ?>><?php echo esc_html( rawurldecode( $swiftling_address['url'] ) ); ?></a>
					</form>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php
		// The comments under the page are translated in a list of their own, see includes/comments.php.
		$swiftling_comments = $swiftling_is_post && swiftling_comments_subject( $swiftling_subject['post']->post_type ) ? swiftling_comment_progress( $swiftling_subject['post']->post_type, $swiftling_subject['post']->ID )[ $swiftling_lang ] : null;
		?>
		<?php if ( $swiftling_comments && $swiftling_comments['total'] ) : ?>
			<?php $swiftling_untranslated = $swiftling_comments['total'] - $swiftling_comments['translated']; ?>
			<section class="swiftling-card swiftling-side swiftling-comments-card" aria-labelledby="swiftling-comments-title">
				<h2 id="swiftling-comments-title"><?php esc_html_e( 'Comments', 'swiftling' ); ?></h2>
				<p class="swiftling-live-frame">
					<?php
					/* translators: %s: number of comments. */
					echo esc_html( sprintf( _n( '%s comment', '%s comments', $swiftling_comments['total'], 'swiftling' ), number_format_i18n( $swiftling_comments['total'] ) ) . ' · ' );
					echo esc_html(
						$swiftling_untranslated
							/* translators: 1: number of comments, 2: language name, e.g. Deutsch. */
							? sprintf( _n( '%1$s not translated in %2$s', '%1$s not translated in %2$s', $swiftling_untranslated, 'swiftling' ), number_format_i18n( $swiftling_untranslated ), $swiftling_target['name'] )
							/* translators: %s: language name, e.g. Deutsch. */
							: sprintf( __( 'all translated in %s', 'swiftling' ), $swiftling_target['name'] )
					);
					?>
				</p>
				<?php if ( swiftling_can_list( 'moderate_comments' ) ) : ?>
					<?php Ui::button( __( 'Translate the comments', 'swiftling' ), 'secondary', add_query_arg( 'for', $swiftling_subject['post']->ID, swiftling_editor_url( 'comments:' . $swiftling_subject['post']->post_type, $swiftling_lang ) ) ); ?>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( '' !== $swiftling_about ) : ?>
			<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-about-title">
				<h2 id="swiftling-about-title"><?php echo esc_html( $swiftling_about ); ?></h2>
				<?php $swiftling_about_note(); ?>
			</section>
		<?php endif; ?>

		<?php if ( $swiftling_is_package && 'settings' === $swiftling_subject['view'] ) : ?>
			<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-choose-title">
				<h2 id="swiftling-choose-title"><?php esc_html_e( 'Missing a text?', 'swiftling' ); ?></h2>
				<p class="swiftling-muted"><?php esc_html_e( 'Choose it from what the scan of the settings found, or scan again after changing the settings.', 'swiftling' ); ?></p>
				<?php Ui::button( __( 'Choose settings', 'swiftling' ), 'secondary', swiftling_package_url( $swiftling_subject['package'], 'choose' ) ); ?>
			</section>
		<?php endif; ?>

		<?php if ( $swiftling_is_post ) : ?>
			<?php // A text a plugin shows that the page check cannot find: ask for the plugin to be added. ?>
			<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-request-title">
				<h2 id="swiftling-request-title"><?php esc_html_e( 'A text is missing from the list?', 'swiftling' ); ?></h2>
				<p class="swiftling-muted"><?php esc_html_e( 'When a plugin or the theme shows it, tell us which one: we test it and add it to SwiftLing.', 'swiftling' ); ?></p>
				<?php Ui::button( __( 'Request support', 'swiftling' ), 'secondary', admin_url( 'admin.php?page=swiftling-request' ) ); ?>
			</section>
		<?php endif; ?>

		<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-states-title">
			<h2 id="swiftling-states-title"><?php esc_html_e( 'States', 'swiftling' ); ?></h2>
			<ul class="swiftling-side__states">
				<?php
				$swiftling_row_states = array(
					'reviewed' => __( 'Reviewed', 'swiftling' ),
					'machine'  => __( 'Machine translated, not reviewed yet', 'swiftling' ),
					'official' => __( 'Translated by WordPress, the theme or the plugin', 'swiftling' ),
					'outdated' => __( 'The original changed since it was translated', 'swiftling' ),
					'missing'  => __( 'Not translated yet: visitors read the original', 'swiftling' ),
				);
				foreach ( $swiftling_row_states as $swiftling_state => $swiftling_label ) :
					?>
					<li><?php Ui::statusIcon( $swiftling_state ); ?> <?php echo esc_html( $swiftling_label ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	</aside>
</div>

<script type="application/json" id="swiftling-editor-config"><?php echo wp_json_encode( $swiftling_config, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & escaped, inside a JSON script element. ?></script>
