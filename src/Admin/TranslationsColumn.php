<?php
/**
 * Translations column in the admin lists of pages, posts and products.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin;

use SwiftLing\Builders\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The Translations column of the admin lists of the content visitors open (Pages, Posts, Products
 * and other such types): every language with the state of its translation, as the Translations
 * screen shows it. Each language is a link to where that translation is edited: the translation
 * editor, or for a separate copy the copy in its own editor.
 *
 * Because a separate copy is reached from its original, the lists show only originals: copies leave
 * every view and its count. The view "Separate copies" lists them, and the Trash still shows them,
 * so a copy that was stopped can be restored.
 *
 * Only for people who may translate, as the Translate link of the rows, and only once the site has
 * languages to translate into.
 */
final class TranslationsColumn {
	/**
	 * Key of the column.
	 */
	private const COLUMN = 'swiftling';

	/**
	 * Query argument of the view that lists separate copies.
	 */
	private const COPIES_VIEW = 'swiftling_copies';

	/**
	 * Posts whose states are read at once: a page of the list.
	 */
	private const BATCH = 20;

	/**
	 * Post type of the list.
	 *
	 * @var string
	 */
	private $type = '';

	/**
	 * States read so far: post ID => language slug => state.
	 *
	 * @var array<int, array<string, string>>
	 */
	private $states = array();

	/**
	 * Whether a row of the list showed a language as a separate copy, which the legend then explains.
	 *
	 * @var bool
	 */
	private $shown_copy = false;

	/**
	 * Separate copies of the list's type out of the trash, by status, once read.
	 *
	 * @var array<string, int>|null
	 */
	private $copy_counts = null;

	/**
	 * Hooks the column into the admin lists of posts.
	 */
	public function register(): void {
		add_action( 'load-edit.php', array( $this, 'load' ) );
	}

	/**
	 * Adds the column, and leaves copies out, on the list of a type visitors open.
	 */
	public function load(): void {
		global $typenow;
		$type = (string) $typenow;
		if ( ! isset( swiftling_content_types()[ $type ] ) || ! current_user_can( SWIFTLING_TRANSLATE ) || ! swiftling_languages()['targets'] ) {
			return;
		}
		$this->type = $type;

		// Late, so WooCommerce has set up the columns of its products first.
		add_filter( "manage_{$type}_posts_columns", array( $this, 'columns' ), 20 );
		add_action( "manage_{$type}_posts_custom_column", array( $this, 'renderCell' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'filterCopies' ), 10, 2 );
		add_filter( 'wp_count_posts', array( $this, 'countWithoutCopies' ), 10, 3 );
		add_filter( "views_edit-{$type}", array( $this, 'views' ) );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'renderLegend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the styles of the column: wide enough for every language on one line, but never more
	 * than a third of the list, so the title keeps its room. More languages wrap onto more lines.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'swiftling-list', SWIFTLING_URL . 'assets/css/list.css', array( Ui::registerStatesStyle() ), SWIFTLING_VERSION );
		// One language takes its icon, its code and a gap: about 66px.
		$width = 66 * count( swiftling_languages()['targets'] ) + 16;
		wp_add_inline_style( 'swiftling-list', '.fixed .column-swiftling { width: min(' . $width . 'px, 34%); }' );
	}

	/**
	 * Adds the column before the date, or last where the list has no date.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = (array) $columns;
		$label   = __( 'Translations', 'swiftling' );
		$at      = array_search( 'date', array_keys( $columns ), true );
		if ( false === $at ) {
			$columns[ self::COLUMN ] = $label;
			return $columns;
		}
		return array_slice( $columns, 0, $at, true ) + array( self::COLUMN => $label ) + array_slice( $columns, $at, null, true );
	}

	/**
	 * Prints the languages of a row: one link per language to where its translation is edited. A
	 * copy, listed in the Trash or under Separate copies, shows the language it is a copy for.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function renderCell( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( swiftling_copy_original_id( $post ) ) {
			$this->renderCopyCell( $post );
			return;
		}

		$labels = self::stateLabels();
		$states = $this->statesOf( $post_id );
		$copies = swiftling_copies_of( $post_id );
		$can    = current_user_can( 'edit_post', $post_id );

		echo '<div class="swiftling-list-langs">';
		foreach ( swiftling_languages()['targets'] as $slug => $language ) {
			$slug  = (string) $slug;
			$state = isset( $states[ $slug ], $labels[ $states[ $slug ] ] ) ? $states[ $slug ] : 'missing';
			$copy  = isset( $copies[ $language['locale'] ] ) ? $copies[ $language['locale'] ] : null;

			if ( $copy ) {
				$this->shown_copy = true;
				$about            = 'publish' === $copy->post_status
					/* translators: 1: language name, e.g. Deutsch, 2: state, e.g. Reviewed. */
					? sprintf( __( '%1$s: separate copy, %2$s', 'swiftling' ), $language['name'], $labels[ $state ] )
					/* translators: 1: language name, e.g. Deutsch, 2: state of the translation visitors see, e.g. Machine translated. */
					: sprintf( __( '%1$s: separate copy, not published yet. Visitors see the translation: %2$s', 'swiftling' ), $language['name'], $labels[ $state ] );
				$href = current_user_can( 'edit_post', $copy->ID ) ? Registry::editUrl( $copy->ID ) : '';
				$does = __( 'Click to edit the copy', 'swiftling' );
			} else {
				/* translators: 1: language name, e.g. Deutsch, 2: state, e.g. Reviewed. */
				$about = sprintf( __( '%1$s: %2$s', 'swiftling' ), $language['name'], $labels[ $state ] );
				$href  = $can ? swiftling_editor_url( $post_id, $slug ) : '';
				$does  = __( 'Click to edit the translation', 'swiftling' );
			}

			$inner = sprintf(
				'<span class="swiftling-status is-%1$s" aria-hidden="true"></span><span class="swiftling-lang%2$s" aria-hidden="true">%3$s</span>',
				esc_attr( $state ),
				$copy ? ' is-copy' : '',
				esc_html( $language['code'] )
			);
			if ( '' === $href ) {
				printf( '<span class="swiftling-list-lang" role="img" aria-label="%1$s" data-tip="%1$s">%2$s</span>', esc_attr( $about ), $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
				continue;
			}
			printf(
				'<a class="swiftling-list-lang" href="%1$s" aria-label="%2$s" data-tip="%3$s">%4$s</a>',
				esc_url( $href ),
				esc_attr( $about . '. ' . $does ),
				esc_attr( $about . "\n" . $does ),
				$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			);
		}
		echo '</div>';
	}

	/**
	 * Prints the language a separate copy is for, which its chip shows as on its original's row.
	 *
	 * @param \WP_Post $copy Copy.
	 */
	private function renderCopyCell( \WP_Post $copy ): void {
		$locale   = (string) get_post_meta( $copy->ID, SWIFTLING_COPY_LANG, true );
		$slug     = swiftling_locale_slug( $locale );
		$language = '' === $slug ? null : swiftling_target_language( $slug );
		if ( ! $language ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		/* translators: %s: language name, e.g. Deutsch. */
		$about            = sprintf( __( 'Separate copy for %s', 'swiftling' ), $language['name'] );
		$this->shown_copy = true;
		printf(
			'<div class="swiftling-list-langs"><span class="swiftling-list-lang" role="img" aria-label="%1$s" data-tip="%1$s"><span class="swiftling-lang is-copy" aria-hidden="true">%2$s</span></span></div>',
			esc_attr( $about ),
			esc_html( $language['code'] )
		);
	}

	/**
	 * Explains the icons under the list, once it has rows; the mark of a separate copy only when a
	 * row shows one.
	 *
	 * @param string $which top or bottom.
	 */
	public function renderLegend( $which ): void {
		global $wp_query;
		if ( 'bottom' !== $which || empty( $wp_query->posts ) ) {
			return;
		}
		$labels = self::stateLabels();
		echo '<ul class="swiftling-list-legend">';
		foreach ( array( 'reviewed', 'machine', 'partial', 'outdated', 'missing', 'duplicate' ) as $state ) {
			printf( '<li><span class="swiftling-status is-%1$s" aria-hidden="true"></span> %2$s</li>', esc_attr( $state ), esc_html( $labels[ $state ] ) );
		}
		if ( $this->shown_copy ) {
			$first = reset( swiftling_languages()['targets'] );
			printf( '<li><span class="swiftling-lang is-copy" aria-hidden="true">%1$s</span> %2$s</li>', esc_html( $first['code'] ), esc_html__( 'Separate copy', 'swiftling' ) );
		}
		echo '</ul>';
	}

	/**
	 * Leaves separate copies out of the list, except in the Trash; the view Separate copies lists
	 * only them.
	 *
	 * @param string    $where WHERE clause of the query.
	 * @param \WP_Query $query Query.
	 * @return string
	 */
	public function filterCopies( $where, $query ): string {
		global $wpdb;
		$where = (string) $where;
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return $where;
		}
		if ( $this->showsCopies() ) {
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s )", SWIFTLING_COPY_OF );
		}
		if ( 'trash' === $query->get( 'post_status' ) || ! swiftling_copy_map() ) {
			return $where;
		}
		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s )", SWIFTLING_COPY_OF );
	}

	/**
	 * Counts the list's views without separate copies, as the list shows them; the Trash keeps
	 * counting them.
	 *
	 * @param object $counts Number of posts by status.
	 * @param string $type   Post type.
	 * @param string $perm   readable, or empty.
	 * @return object
	 */
	public function countWithoutCopies( $counts, $type, $perm ) {
		if ( $type !== $this->type || ! is_object( $counts ) ) {
			return $counts;
		}
		$counts = clone $counts;
		foreach ( $this->copyCounts( 'readable' === $perm ) as $status => $number ) {
			if ( isset( $counts->$status ) ) {
				$counts->$status = max( 0, (int) $counts->$status - $number );
			}
		}
		return $counts;
	}

	/**
	 * Adds the view Separate copies, and counts Mine without copies: WordPress counts the current
	 * user's posts in a query of its own, which no filter reaches.
	 *
	 * @param array<string, string> $views Links of the views.
	 * @return array<string, string>
	 */
	public function views( $views ): array {
		$views = $this->viewMine( (array) $views );

		$copies = array_sum( $this->copyCounts( true ) );
		if ( $copies || $this->showsCopies() ) {
			$views[ self::COPIES_VIEW ] = self::viewLink(
				array(
					'post_type'       => $this->type,
					self::COPIES_VIEW => 1,
				),
				esc_html__( 'Separate copies', 'swiftling' ) . ' <span class="count">(' . esc_html( number_format_i18n( $copies ) ) . ')</span>',
				$this->showsCopies()
			);
		}
		return $views;
	}

	/**
	 * Shows Mine as WordPress would without copies: only when it lists fewer posts than All, with
	 * the number of the current user's originals.
	 *
	 * @param array<string, string> $views Links of the views.
	 * @return array<string, string>
	 */
	private function viewMine( array $views ): array {
		if ( ! swiftling_copy_map() ) {
			return $views;
		}
		$mine = $this->countMine();
		$all  = 0;
		$num  = wp_count_posts( $this->type, 'readable' );
		foreach ( get_post_stati( array( 'show_in_admin_all_list' => true ) ) as $status ) {
			$all += isset( $num->$status ) ? (int) $num->$status : 0;
		}

		if ( ! $mine || $mine === $all ) {
			unset( $views['mine'] );
			return $views;
		}
		$link = self::viewLink(
			array(
				'post_type' => $this->type,
				'author'    => get_current_user_id(),
			),
			sprintf(
				/* translators: %s: Number of posts. */
				_nx( // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own words for the view, in the language it shows them in.
					'Mine <span class="count">(%s)</span>',
					'Mine <span class="count">(%s)</span>',
					$mine,
					'posts'
				),
				esc_html( number_format_i18n( $mine ) )
			),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view of a list.
			isset( $_GET['author'] ) && get_current_user_id() === (int) $_GET['author']
		);
		if ( isset( $views['mine'] ) ) {
			$views['mine'] = $link;
			return $views;
		}
		// Right after All, where WordPress puts it.
		$at = array_search( 'all', array_keys( $views ), true );
		$at = false === $at ? 0 : (int) $at + 1;
		return array_slice( $views, 0, $at, true ) + array( 'mine' => $link ) + array_slice( $views, $at, null, true );
	}

	/**
	 * Returns the link of a view, as WordPress writes those of the admin lists.
	 *
	 * @param array<string, mixed> $args    Query arguments of the view.
	 * @param string               $inner   Name and count of the view, as HTML.
	 * @param bool                 $current Whether it is shown.
	 * @return string
	 */
	private static function viewLink( array $args, string $inner, bool $current ): string {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( add_query_arg( $args, admin_url( 'edit.php' ) ) ),
			$current ? ' class="current" aria-current="page"' : '',
			wp_kses( $inner, array( 'span' => array( 'class' => true ) ) )
		);
	}

	/**
	 * Returns the states of a post, read with the posts that follow it in the list, so a page of the
	 * list is read in one go. A list of pages in their hierarchy shows them in another order than it
	 * read them, which then takes a few reads more.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string> Language slug => state.
	 */
	private function statesOf( int $post_id ): array {
		global $wp_query;
		if ( ! isset( $this->states[ $post_id ] ) ) {
			$ids   = $wp_query instanceof \WP_Query ? array_map( 'intval', wp_list_pluck( (array) $wp_query->posts, 'ID' ) ) : array();
			$at    = array_search( $post_id, $ids, true );
			$batch = false === $at ? array() : array_slice( $ids, (int) $at, self::BATCH );
			$batch = array_diff( $batch, array_keys( $this->states ) );
			$batch = array_values( array_unique( array_merge( array( $post_id ), $batch ) ) );

			$this->states += swiftling_post_states( $batch );
		}
		return $this->states[ $post_id ] ?? array();
	}

	/**
	 * Whether the view Separate copies is shown.
	 */
	private function showsCopies(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view of a list.
		return ! empty( $_GET[ self::COPIES_VIEW ] );
	}

	/**
	 * Returns how many separate copies of the list's type there are out of the trash, by status.
	 *
	 * @param bool $readable Whether to count only the private copies the user may read, as the
	 *                       counts of the list do.
	 * @return array<string, int>
	 */
	private function copyCounts( bool $readable ): array {
		global $wpdb;
		if ( null === $this->copy_counts ) {
			$this->copy_counts = array(
				'all' => array(),
				'own' => array(),
			);
			if ( swiftling_copy_map() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Once per list; meta_key is indexed and few posts are copies.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_status, COUNT( 1 ) AS copies, SUM( p.post_author = %d ) AS own FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_status <> 'trash' GROUP BY p.post_status", get_current_user_id(), $wpdb->posts, $wpdb->postmeta, SWIFTLING_COPY_OF, $this->type ) );
				foreach ( $rows as $row ) {
					$this->copy_counts['all'][ $row->post_status ] = (int) $row->copies;
					$this->copy_counts['own'][ $row->post_status ] = (int) $row->own;
				}
			}
		}

		$counts = $this->copy_counts['all'];
		$type   = get_post_type_object( $this->type );
		// As wp_count_posts(): someone who may not read others' private posts counts only their own.
		if ( $readable && isset( $counts['private'] ) && $type && ! current_user_can( $type->cap->read_private_posts ) ) {
			$counts['private'] = $this->copy_counts['own']['private'] ?? 0;
		}
		return $counts;
	}

	/**
	 * Counts the current user's posts of the list's type as WordPress does for Mine, without copies.
	 */
	private function countMine(): int {
		global $wpdb;
		$hidden = array_values( get_post_stati( array( 'show_in_admin_all_list' => false ) ) );
		// Never empty: WordPress keeps the trash out of All.
		$hidden = $hidden ? $hidden : array( 'trash' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress counts Mine the same way, once per list.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT( 1 ) FROM %i p WHERE p.post_type = %s AND p.post_author = %d AND p.post_status NOT IN (' . implode( ',', array_fill( 0, count( $hidden ), '%s' ) ) . ') AND p.ID NOT IN ( SELECT post_id FROM %i WHERE meta_key = %s )', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One placeholder per hidden status.
				array_merge( array( $wpdb->posts, $this->type, get_current_user_id() ), $hidden, array( $wpdb->postmeta, SWIFTLING_COPY_OF ) )
			)
		);
	}

	/**
	 * Words for the states, as the Translations panel of the block editor says them.
	 *
	 * @return array<string, string>
	 */
	private static function stateLabels(): array {
		$labels             = Ui::statusLabels();
		$labels['outdated'] = __( 'Original changed', 'swiftling' );
		return $labels;
	}
}
