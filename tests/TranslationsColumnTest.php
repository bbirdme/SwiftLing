<?php
/**
 * Tests of the Translations column of the admin lists.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hooks of the admin lists, run as it runs them.

use SwiftLing\Admin\TranslationsColumn;

/**
 * The admin lists of pages and posts show each language of a row as a link to its translation,
 * and list only originals: separate copies are reached from them.
 */
class TranslationsColumnTest extends TestCase {

	/**
	 * Opens the admin list of a post type as WordPress does, up to the point where the column
	 * hooks in.
	 *
	 * @param string $type Post type.
	 * @return TranslationsColumn
	 */
	private function open_list( $type ) {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$GLOBALS['typenow'] = $type; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As edit.php sets it.
		set_current_screen( 'edit-' . $type );
		$column = new TranslationsColumn();
		$column->load();
		return $column;
	}

	/**
	 * Runs the query of the list, as edit.php runs it, and returns the IDs it lists.
	 *
	 * @param string $type Post type.
	 * @param array  $args More query arguments of the request, such as post_status.
	 * @return int[]
	 */
	private function listed( $type, $args = array() ) {
		$_GET = array_merge( array( 'post_type' => $type ), $args );
		wp_edit_posts_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The request of the list, as edit.php passes it.
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	/**
	 * Makes a separate copy of a post for a language, as swiftling_make_copy() links it.
	 *
	 * @param int    $original_id Original.
	 * @param string $locale      Locale.
	 * @param string $status      Status of the copy.
	 * @param int    $author      Author of the copy, or 0 for the current user.
	 * @return int The copy's ID.
	 */
	private function make_copy( $original_id, $locale, $status = 'publish', $author = 0 ) {
		$original = get_post( $original_id );
		$copy_id  = self::factory()->post->create(
			array(
				'post_type'   => $original->post_type,
				'post_title'  => $original->post_title,
				'post_status' => $status,
				'post_author' => $author ? $author : get_current_user_id(),
			)
		);
		add_post_meta( $copy_id, SWIFTLING_COPY_OF, $original_id );
		add_post_meta( $copy_id, SWIFTLING_COPY_LANG, $locale );
		add_post_meta( $copy_id, SWIFTLING_COPY_FROM, $original->post_modified_gmt );
		swiftling_copy_map( true );
		return $copy_id;
	}

	/**
	 * Prints the column of a row.
	 *
	 * @param TranslationsColumn $column  Column.
	 * @param int                $post_id Row.
	 * @return string
	 */
	private function cell( $column, $post_id ) {
		ob_start();
		$column->renderCell( 'swiftling', $post_id );
		return (string) ob_get_clean();
	}

	/**
	 * Prints the views of the list above it, once the list read its posts as edit.php does.
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	private function views( $type ) {
		$_GET  = array( 'post_type' => $type );
		$table = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => 'edit-' . $type ) );
		$table->prepare_items();
		ob_start();
		$table->views();
		return (string) ob_get_clean();
	}

	/**
	 * The column stands before the date on the lists of content visitors open, for people who may
	 * translate, once the site has languages to translate into.
	 */
	public function test_column_on_lists_of_content_for_translators() {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
		);

		$this->become( 'administrator' );
		$this->open_list( 'page' );
		$this->assertSame( array( 'cb', 'title', 'swiftling', 'date' ), array_keys( apply_filters( 'manage_page_posts_columns', $columns ) ) );
		remove_all_filters( 'manage_page_posts_columns' );

		$this->open_list( 'post' );
		$this->assertArrayHasKey( 'swiftling', apply_filters( 'manage_post_posts_columns', $columns ) );
		remove_all_filters( 'manage_post_posts_columns' );

		// Patterns of the block editor are no content visitors open on their own.
		$this->open_list( 'wp_block' );
		$this->assertArrayNotHasKey( 'swiftling', apply_filters( 'manage_wp_block_posts_columns', $columns ) );

		$this->become( 'editor' );
		$this->open_list( 'page' );
		$this->assertArrayNotHasKey( 'swiftling', apply_filters( 'manage_page_posts_columns', $columns ) );

		$this->become( 'administrator' );
		$this->set_languages( array() );
		$this->open_list( 'page' );
		$this->assertArrayNotHasKey( 'swiftling', apply_filters( 'manage_page_posts_columns', $columns ) );
	}

	/**
	 * Each language links to where its translation is edited: the translation editor, or the
	 * separate copy in its own editor, whose chip is marked as a copy.
	 */
	public function test_each_language_links_to_where_it_is_edited() {
		$this->become( 'administrator' );
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Kontakt',
			)
		);
		add_post_meta( $page, SWIFTLING_DUPLICATE, 'fr_FR' );
		$copy   = $this->make_copy( $page, 'de_DE', 'draft' );
		$column = $this->open_list( 'page' );

		$html = $this->cell( $column, $page );
		$this->assertStringContainsString( 'href="' . esc_url( get_edit_post_link( $copy, 'raw' ) ) . '"', $html );
		$this->assertStringContainsString( 'swiftling-lang is-copy', $html );
		$this->assertStringContainsString( 'not published yet', $html );
		// The draft copy is not shown yet: the state is the translation's, which visitors see.
		$this->assertStringContainsString( 'swiftling-status is-missing', $html );

		$this->assertStringContainsString( 'href="' . esc_url( swiftling_editor_url( $page, 'fr' ) ) . '"', $html );
		$this->assertStringContainsString( 'swiftling-status is-duplicate', $html );
		$this->assertStringNotContainsString( esc_url( swiftling_editor_url( $page, 'de' ) ), $html );

		// Published, the copy is what visitors see in German.
		wp_publish_post( $copy );
		$html = $this->cell( new TranslationsColumn(), $page );
		$this->assertStringContainsString( 'swiftling-status is-reviewed', $html );
		$this->assertStringContainsString( 'separate copy, Reviewed', $html );

		// A page translated in the editor leads to the translation editor.
		$other = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$html  = $this->cell( $column, $other );
		$this->assertStringContainsString( 'href="' . esc_url( swiftling_editor_url( $other, 'de' ) ) . '"', $html );
		$this->assertStringNotContainsString( 'is-copy', $html );

		// A copy's own row, under Separate copies or in the Trash, names its language.
		$html = $this->cell( $column, $copy );
		$this->assertStringContainsString( 'Separate copy for Deutsch', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * Someone who may not edit a post sees the states of its languages, without links.
	 */
	public function test_no_links_without_the_right_to_edit() {
		$owner  = $this->become( 'administrator' );
		$page   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $owner,
			)
		);
		$column = $this->open_list( 'page' );

		$deny = static function ( $caps, $cap, $user_id, $args ) use ( $page ) {
			return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $page ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		$html = $this->cell( $column, $page );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( 'swiftling-status is-missing', $html );
	}

	/**
	 * The list shows only originals. Separate copies list on their own view, and in the Trash.
	 */
	public function test_copies_leave_the_list() {
		$this->become( 'administrator' );
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$copy    = $this->make_copy( $page, 'de_DE' );
		$trashed = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$old     = $this->make_copy( $trashed, 'fr_FR' );
		wp_trash_post( $trashed );
		$this->open_list( 'page' );

		$listed = $this->listed( 'page' );
		$this->assertContains( $page, $listed );
		$this->assertNotContains( $copy, $listed );

		$this->assertSame( array( $copy ), $this->listed( 'page', array( 'swiftling_copies' => '1' ) ) );

		$listed = $this->listed( 'page', array( 'post_status' => 'trash' ) );
		$this->assertContains( $trashed, $listed );
		$this->assertContains( $old, $listed );
	}

	/**
	 * The counts of the views leave copies out, except the Trash's; the view Separate copies counts
	 * them.
	 */
	public function test_views_count_without_copies() {
		$this->become( 'administrator' );
		$pages = self::factory()->post->create_many( 2, array( 'post_type' => 'page' ) );
		$this->make_copy( $pages[0], 'de_DE' );
		$this->make_copy( $pages[1], 'de_DE', 'draft' );
		$trashed = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->make_copy( $trashed, 'fr_FR' );
		wp_trash_post( $trashed );
		$this->open_list( 'page' );

		$counts = wp_count_posts( 'page', 'readable' );
		$this->assertSame( 2, (int) $counts->publish );
		$this->assertSame( 0, (int) $counts->draft );
		$this->assertSame( 2, (int) $counts->trash );

		$views = $this->views( 'page' );
		$this->assertMatchesRegularExpression( '/All <span class="count">\(2\)<\/span>/', $views );
		$this->assertMatchesRegularExpression( '/Separate copies <span class="count">\(2\)<\/span>/', $views );
		$this->assertStringContainsString( 'swiftling_copies=1', $views );
		// Every original is the administrator's: WordPress shows no Mine then.
		$this->assertStringNotContainsString( 'Mine', $views );
		$this->assertStringNotContainsString( '>Drafts', $views );
	}

	/**
	 * Mine counts the user's originals, and shows only when it lists fewer posts than All, as
	 * WordPress shows it: also when only the copies made WordPress's own count equal All.
	 */
	public function test_mine_counts_without_copies() {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $other,
			)
		);
		$this->become( 'administrator' );
		$mine = self::factory()->post->create( array( 'post_type' => 'page' ) );
		// WordPress counts 2 posts of the user, the copy too, and 2 under All without copies.
		$this->make_copy( $mine, 'de_DE' );
		$this->open_list( 'page' );

		$this->assertMatchesRegularExpression( '/Mine <span class="count">\(1\)<\/span>/', $this->views( 'page' ) );
	}

	/**
	 * A list without copies keeps WordPress's own views.
	 */
	public function test_views_without_copies_are_wordpress_own() {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $other,
			)
		);
		$this->become( 'administrator' );
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->open_list( 'page' );

		$views = $this->views( 'page' );
		$this->assertMatchesRegularExpression( '/Mine <span class="count">\(1\)<\/span>/', $views );
		$this->assertStringNotContainsString( 'Separate copies', $views );
	}

	/**
	 * The legend under the list explains the icons, and the mark of a separate copy only once a row
	 * shows one.
	 */
	public function test_legend_names_copies_only_when_shown() {
		$this->become( 'administrator' );
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$column = $this->open_list( 'page' );
		$this->listed( 'page' );

		$legend = function () use ( $column ) {
			ob_start();
			$column->renderLegend( 'bottom' );
			return (string) ob_get_clean();
		};

		$this->cell( $column, $page );
		$this->assertStringContainsString( 'Machine translated', $legend() );
		$this->assertStringNotContainsString( 'Separate copy', $legend() );

		$this->make_copy( $page, 'de_DE' );
		$this->cell( $column, $page );
		$this->assertStringContainsString( 'Separate copy', $legend() );
	}

	/**
	 * With many languages the column never takes the room of the title: it grows with the languages
	 * up to a part of the list, and its languages wrap onto more lines after that.
	 */
	public function test_width_with_many_languages() {
		$targets = array();
		foreach ( array( 'de_DE', 'fr_FR', 'it_IT', 'es_ES', 'es_MX', 'en_GB', 'pt_PT', 'sq', 'am', 'af', 'ar', 'hy', 'eu' ) as $locale ) {
			$targets[ $locale ] = strtolower( str_replace( '_', '-', $locale ) );
		}
		$this->set_languages( $targets );
		( new TranslationsColumn() )->enqueue();
		$css = implode( '', (array) wp_styles()->get_data( 'swiftling-list', 'after' ) );
		$this->assertMatchesRegularExpression( '~\.column-swiftling \{ width: min\((\d+)px, (\d+)%\); \}~', $css );
		preg_match( '~min\((\d+)px, (\d+)%\)~', $css, $found );
		$this->assertSame( 66 * 13 + 16, (int) $found[1] );
		$this->assertLessThanOrEqual( 40, (int) $found[2] );
	}
}
