<?php
/**
 * Tests of untranslated content: shown in the original or hidden.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WPDieException;

/**
 * Which posts count as translated, and how hidden ones leave lists, menus and their own address.
 */
class VisibilityTest extends TestCase {

	/**
	 * Makes a post whose page check found its title, translated into some languages.
	 *
	 * @param string   $title Title.
	 * @param string[] $slugs Languages it is translated into.
	 * @param string   $type  Post type.
	 * @return \WP_Post
	 */
	private function post( $title, $slugs = array(), $type = 'post' ) {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => $title,
				'post_excerpt' => '',
				'post_type'    => $type,
			)
		);
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		foreach ( $slugs as $slug ) {
			$this->translate( 'post:' . $post->ID, 'field/post_title', $title, $slug, $title . ' (' . $slug . ')' );
		}
		swiftling_refresh_translated( array( $post->ID ) );
		return $post;
	}

	/**
	 * Hides untranslated posts of some types.
	 *
	 * @param string[] $types  Post types.
	 * @param string   $address What a hidden post's own address does: original or 404.
	 */
	private function hide( $types, $address = 'original' ) {
		update_option(
			'swiftling_settings',
			array(
				'untranslated'   => array_fill_keys( $types, 'hide' ),
				'hidden_address' => $address,
			)
		);
	}

	/**
	 * Without settings, untranslated content shows in the original; unknown choices fall back.
	 */
	public function test_settings() {
		$this->assertSame(
			array(
				'untranslated'   => array(),
				'never'          => array(),
				'hidden_address' => 'original',
				'search'         => true,
			),
			swiftling_settings()
		);
		update_option( 'swiftling_settings', array( 'hidden_address' => 'redirect-somewhere' ) );
		$this->assertSame( 'original', swiftling_settings()['hidden_address'] );
		update_option( 'swiftling_settings', 'broken' );
		$this->assertSame( array(), swiftling_settings()['untranslated'] );
	}

	/**
	 * The listed types are those visitors open on their own and find in menus.
	 */
	public function test_content_types() {
		register_post_type(
			'swl_event',
			array(
				'public'            => true,
				'show_in_nav_menus' => true,
			)
		);
		register_post_type(
			'swl_template',
			array(
				'public'            => true,
				'show_in_nav_menus' => false,
			)
		);
		$types = array_keys( swiftling_content_types() );
		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertContains( 'swl_event', $types );
		$this->assertNotContains( 'swl_template', $types );
		$this->assertNotContains( 'attachment', $types );

		$this->hide( array( 'post', 'swl_gone' ) );
		$this->assertSame( array( 'post' ), swiftling_hidden_types(), 'A type that no longer exists is left out' );
	}

	/**
	 * A post counts as translated once every text of its own is translated, or it is a duplicate.
	 */
	public function test_which_posts_count_as_translated() {
		$this->become( 'administrator' );
		$full    = $this->post( 'Kontakt', array( 'de' ) );
		$none    = $this->post( 'O nama' );
		$partial = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Usluge',
				'post_excerpt' => 'Sve što radimo',
			)
		);
		$this->check(
			'post:' . $partial->ID,
			static function () use ( $partial ) {
				get_the_title( $partial );
			}
		);
		$this->translate( 'post:' . $partial->ID, 'field/post_title', 'Usluge', 'de', 'Leistungen' );
		swiftling_refresh_translated( array( $partial->ID ) );

		$this->assertSame( array( 'de_DE' ), swiftling_translated_locales( $full->ID ) );
		$this->assertSame( array(), swiftling_translated_locales( $none->ID ) );
		$this->assertSame( array(), swiftling_translated_locales( $partial->ID ), 'Its excerpt is not translated' );

		swiftling_set_language_mode( $none, 'fr', 'duplicate' );
		$this->assertSame( array( 'fr_FR' ), swiftling_translated_locales( $none->ID ) );

		swiftling_refresh_translated( array( $full->ID ) );
		$this->assertSame( array( 'de_DE' ), swiftling_translated_locales( $full->ID ), 'Running it again writes the same rows' );
		$this->assertSame( array( $full->ID ), swiftling_translated_ids( array( $full->ID, $none->ID, 0 ), 'de_DE' ) );
	}

	/**
	 * A post whose original changed after it was translated still counts as translated while the
	 * earlier translation waits for an update, but not when another of its texts was never
	 * translated.
	 */
	public function test_outdated_counts_only_with_every_other_text() {
		$this->become( 'administrator' );
		$changed = $this->post( 'Kontakt', array( 'de' ) );
		$partial = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Usluge',
				'post_excerpt' => 'Sve što radimo',
			)
		);
		$this->check(
			'post:' . $partial->ID,
			static function () use ( $partial ) {
				get_the_title( $partial->ID );
			}
		);
		$this->translate( 'post:' . $partial->ID, 'field/post_title', 'Usluge', 'de', 'Leistungen' );

		foreach ( array( $changed, $partial ) as $post ) {
			wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => $post->post_title . ' i više',
				)
			);
			$id = $post->ID;
			$this->check(
				'post:' . $id,
				static function () use ( $id ) {
					get_the_title( $id );
				}
			);
		}
		swiftling_refresh_translated( array( $changed->ID, $partial->ID ) );

		$this->assertSame( 'outdated', swiftling_subject_states( 'post:' . $partial->ID )['de'], 'The editor names the changed text first' );
		$this->assertSame( array( 'de_DE' ), swiftling_translated_locales( $changed->ID ), 'Its earlier translation waits for an update' );
		$this->assertSame( array(), swiftling_translated_locales( $partial->ID ), 'Its excerpt was never translated' );
		$this->hide( array( 'post' ) );
		$this->assertTrue( swiftling_is_hidden_post( $partial, 'de_DE' ) );
		$this->assertFalse( swiftling_is_hidden_post( $changed, 'de_DE' ) );
	}

	/**
	 * When the languages change, the rows of a language the site no longer has go.
	 */
	public function test_refresh_all() {
		$post = $this->post( 'Kontakt', array( 'de', 'fr' ) );
		$this->assertCount( 2, swiftling_translated_locales( $post->ID ) );
		$this->set_languages( array( 'de_DE' => 'de' ) );
		swiftling_refresh_all_translated();
		$this->assertSame( array( 'de_DE' ), swiftling_translated_locales( $post->ID ) );
		$this->set_languages( array() );
		swiftling_refresh_all_translated();
		$this->assertSame( array(), swiftling_translated_locales( $post->ID ) );
	}

	/**
	 * A deleted post's rows go with it.
	 */
	public function test_deleted_post_is_forgotten() {
		$post = $this->post( 'Kontakt', array( 'de' ) );
		wp_delete_post( $post->ID, true );
		$this->assertSame( array(), swiftling_translated_locales( $post->ID ) );
	}

	/**
	 * Only untranslated posts of a hidden type are hidden, only in a translated view, and never a copy.
	 */
	public function test_is_hidden_post() {
		$translated = $this->post( 'Kontakt', array( 'de' ) );
		$other      = $this->post( 'O nama' );
		$page       = $this->post( 'Stranica', array(), 'page' );
		$this->hide( array( 'post' ) );

		$this->assertFalse( swiftling_is_hidden_post( $other ), 'The original shows everything' );
		$this->show_in( 'de' );
		$this->assertTrue( swiftling_is_hidden_post( $other ) );
		$this->assertFalse( swiftling_is_hidden_post( $translated ) );
		$this->assertFalse( swiftling_is_hidden_post( $page ), 'Pages are not hidden' );
		$this->assertTrue( swiftling_is_hidden_post( $translated, 'fr_FR' ), 'Another language asked for by name' );
	}

	/**
	 * Lists of posts leave hidden posts out; the original lists all of them.
	 */
	public function test_lists_leave_hidden_posts_out() {
		$translated = $this->post( 'Kontakt', array( 'de' ) );
		$other      = $this->post( 'O nama' );
		$this->hide( array( 'post' ) );

		$ids = static function () {
			$query = new \WP_Query(
				array(
					'post_type' => 'post',
					'fields'    => 'ids',
				)
			);
			return $query->posts;
		};
		$this->assertEqualsCanonicalizing( array( $translated->ID, $other->ID ), $ids() );
		$this->show_in( 'de' );
		$this->assertSame( array( $translated->ID ), array_map( 'intval', $ids() ) );
	}

	/**
	 * Queries that skip the filters of their SQL, as get_posts() does, leave hidden posts out too.
	 */
	public function test_get_posts_leaves_hidden_posts_out() {
		// A language of its own: the hidden posts of a language are read once per request.
		$this->set_languages( array( 'it_IT' => 'it' ) );
		$translated = $this->post( 'Kontakt', array( 'it' ) );
		$other      = $this->post( 'O nama' );
		$this->hide( array( 'post' ) );

		$this->show_in( 'it' );
		$found = get_posts( array( 'fields' => 'ids' ) );
		$this->assertContains( $translated->ID, $found );
		$this->assertNotContains( $other->ID, $found );
	}

	/**
	 * Page lists leave hidden pages out.
	 */
	public function test_page_lists() {
		$translated = $this->post( 'Kontakt', array( 'de' ), 'page' );
		$other      = $this->post( 'O nama', array(), 'page' );
		$this->hide( array( 'page' ) );
		$this->show_in( 'de' );
		$this->assertSame( array( $translated->ID ), wp_list_pluck( swiftling_filter_hidden_pages( array( $translated, $other ) ), 'ID' ) );
	}

	/**
	 * A menu loses links to hidden posts; their submenu moves up a level.
	 */
	public function test_menu_items() {
		$translated = $this->post( 'Kontakt', array( 'de' ) );
		$other      = $this->post( 'O nama' );
		$this->hide( array( 'post' ) );
		$item  = static function ( $id, $parent_id, $post = null ) {
			return (object) array(
				'db_id'            => $id,
				'menu_item_parent' => $parent_id,
				'type'             => $post ? 'post_type' : 'custom',
				'object'           => $post ? 'post' : 'custom',
				'object_id'        => $post ? $post->ID : 0,
			);
		};
		$items = array( $item( 10, 0, $translated ), $item( 11, 0, $other ), $item( 12, 11 ), $item( 13, 12 ) );

		$this->assertCount( 4, swiftling_filter_hidden_menu_items( $items ), 'The original keeps every link' );
		$this->show_in( 'de' );
		$kept = swiftling_filter_hidden_menu_items( $items );
		$this->assertSame( array( 10, 12, 13 ), wp_list_pluck( $kept, 'db_id' ) );
		$this->assertSame( array( '0', '0', '12' ), array_map( 'strval', wp_list_pluck( $kept, 'menu_item_parent' ) ) );
	}

	/**
	 * The Navigation block loses links to hidden posts, but not custom links.
	 */
	public function test_navigation_links() {
		$other = $this->post( 'O nama' );
		$this->hide( array( 'post' ) );
		$this->show_in( 'de' );
		$block = static function ( $attrs ) {
			return array( 'attrs' => $attrs );
		};
		$this->assertSame(
			'',
			swiftling_filter_hidden_navigation_link(
				'<li>O nama</li>',
				$block(
					array(
						'id'   => $other->ID,
						'kind' => 'post-type',
					)
				)
			)
		);
		$this->assertSame( '<li>x</li>', swiftling_filter_hidden_navigation_link( '<li>x</li>', $block( array( 'kind' => 'custom' ) ) ) );
		$this->assertSame(
			'<li>x</li>',
			swiftling_filter_hidden_navigation_link(
				'<li>x</li>',
				$block(
					array(
						'id'   => $other->ID,
						'kind' => 'taxonomy',
					)
				)
			)
		);
	}

	/**
	 * Previous and next links skip hidden posts.
	 */
	public function test_adjacent_posts() {
		$this->hide( array( 'post' ) );
		$this->assertSame( 'WHERE 1', swiftling_filter_hidden_adjacent( 'WHERE 1' ) );
		$this->show_in( 'de' );
		$this->assertStringContainsString( 'p.ID IN ( SELECT post_id FROM', swiftling_filter_hidden_adjacent( 'WHERE 1' ) );
	}

	/**
	 * A hidden post's own address opens the original, or is not found, as the settings say.
	 */
	public function test_hidden_post_address() {
		$other = $this->post( 'O nama' );
		$this->hide( array( 'post' ), '404' );
		$this->show_in( 'de' );
		$this->go_to( add_query_arg( 'lang', 'de', get_permalink( $other ) ) );
		swiftling_open_hidden_post();
		$this->assertTrue( is_404() );

		$this->hide( array( 'post' ) );
		$this->go_to( add_query_arg( 'lang', 'de', get_permalink( $other ) ) );
		$this->assertSame( get_permalink( $other ), $this->redirect_of( 'swiftling_open_hidden_post' ) );
		$this->assertSame( 302, $this->redirect_status );
	}

	/**
	 * The Settings screen counts the untranslated published posts per type and language.
	 */
	public function test_untranslated_counts() {
		$this->post( 'Kontakt', array( 'de' ) );
		$this->post( 'O nama' );
		$this->assertSame(
			array(
				'post' => array(
					'de' => 1,
					'fr' => 2,
				),
			),
			swiftling_untranslated_counts( array( 'post' ) )
		);
		$this->assertSame( array(), swiftling_untranslated_counts( array() ) );
	}

	/**
	 * The Settings screen saves only types it lists and only the choices it offers.
	 */
	public function test_saving_settings() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_settings',
			array(
				'untranslated'   => array(
					'post'       => 'hide',
					'page'       => 'show',
					'attachment' => 'hide',
				),
				'hidden_address' => '404',
				'search'         => '1',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=saved', $this->redirect_of( 'swiftling_save_settings' ) );
		$this->assertSame(
			array(
				'untranslated'   => array( 'post' => 'hide' ),
				'never'          => array(),
				'hidden_address' => '404',
				'search'         => true,
			),
			get_option( 'swiftling_settings' )
		);

		// The box is not ticked: search reads the original language only.
		$this->post_form( 'swiftling_save_settings', array( 'hidden_address' => 'original' ) );
		$this->redirect_of( 'swiftling_save_settings' );
		$this->assertFalse( get_option( 'swiftling_settings' )['search'] );

		$this->become( 'editor' );
		$this->expectException( WPDieException::class );
		swiftling_save_settings();
	}
}
