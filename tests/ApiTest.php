<?php
/**
 * Tests of translating without the editor's screen.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * What SwiftLing Pro's REST API, WP-CLI and abilities build on, see includes/api.php: the texts of a
 * subject listed after a check of its page on the server, translations saved by key, and the empty
 * texts filled with the translation service as the Translate everything run fills a page.
 */
class ApiTest extends TestCase {

	/**
	 * A post with a title and a heading kept in a meta field.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Subject key of the post.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Makes the post, answers the server's checks of its page, and signs in as an administrator.
	 */
	public function set_up() {
		parent::set_up();
		$this->post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_content' => '',
				'post_excerpt' => '',
			)
		);
		add_post_meta( $this->post->ID, 'subtitle', '<h2>Javite nam se &amp; pišite</h2>' );
		$this->key = 'post:' . $this->post->ID;
		$id        = $this->post->ID;
		$this->serve_page_check(
			$this->key,
			static function () use ( $id ) {
				get_the_title( $id );
				get_post_meta( $id, 'subtitle', true );
			}
		);
		$this->become( 'administrator' );
	}

	/**
	 * Returns the text listed at a path of the post.
	 *
	 * @param array  $texts What swiftling_subject_texts() returned.
	 * @param string $path  Path.
	 * @return array
	 */
	private function text( $texts, $path ) {
		$this->assertIsArray( $texts, is_wp_error( $texts ) ? $texts->get_error_message() : '' );
		foreach ( $texts['texts'] as $text ) {
			if ( $this->key === $text['object'] && $path === $text['path'] ) {
				return $text;
			}
		}
		$this->fail( 'No text at ' . $path );
	}

	/**
	 * Returns the place key of a text of the post.
	 *
	 * @param string $path     Path.
	 * @param string $original Original.
	 * @return string
	 */
	private function place( $path, $original ) {
		return swiftling_place_key( $this->key, swiftling_path_key( $path ), swiftling_hash( $original ) );
	}

	/**
	 * A subject's texts are listed after its page is checked on the server, each under the key of its
	 * place, with its translation and state; a saved translation shows, and once the original
	 * changes the text is outdated, with the translation of the original before.
	 */
	public function test_texts_of_a_subject() {
		$this->assertSame( array(), swiftling_get_maps( array( $this->key ) ), 'Never checked' );

		$texts = swiftling_subject_texts( $this->key, 'de' );
		$title = $this->text( $texts, 'field/post_title' );
		$this->assertSame(
			array(
				'key'         => $this->place( 'field/post_title', 'Kontakt' ),
				'object'      => $this->key,
				'path'        => 'field/post_title',
				'type'        => 'text',
				'original'    => 'Kontakt',
				'translation' => '',
				'state'       => 'missing',
				'previous'    => '',
				'follows'     => false,
			),
			array_diff_key( $title, array_flip( array( 'section', 'kind' ) ) )
		);
		$this->assertSame( 'html', $this->text( $texts, 'meta/subtitle' )['type'] );
		$this->assertSame( $this->key, $texts['subject']['key'] );

		$saved = swiftling_save_texts(
			$this->key,
			'de',
			array(
				array(
					'key'  => $title['key'],
					'text' => 'Kontakt DE',
				),
				array(
					'key'  => md5( 'elsewhere' ),
					'text' => 'Tajna',
				),
			),
			'reviewed'
		);
		$this->assertSame( array( $title['key'] => 'reviewed' ), $saved['states'] );
		$this->assertSame( array( md5( 'elsewhere' ) ), $saved['ignored'], 'Not a place of this subject' );
		$this->assertSame( 'partial', $saved['languages']['de'] );

		$title = $this->text( swiftling_subject_texts( $this->key, 'de' ), 'field/post_title' );
		$this->assertSame( array( 'Kontakt DE', 'reviewed' ), array( $title['translation'], $title['state'] ) );

		wp_update_post(
			array(
				'ID'         => $this->post->ID,
				'post_title' => 'Kontaktirajte nas',
			)
		);
		$title = $this->text( swiftling_subject_texts( $this->key, 'de' ), 'field/post_title' );
		$this->assertSame( array( 'Kontaktirajte nas', '', 'outdated', 'Kontakt DE' ), array( $title['original'], $title['translation'], $title['state'], $title['previous'] ) );
	}

	/**
	 * A translation made for an earlier version of a text, as another plugin kept it, is saved as
	 * outdated, as when the original changed after it was translated here: visitors read the
	 * original, the editor shows the earlier translation, Translate everything leaves it to the
	 * editor, and the post counts as translated. A page check keeps it so. A text translated now, an
	 * earlier version that is the text now, a place of no text of the subject, and an earlier version
	 * other than the one another language waits with are left alone.
	 */
	public function test_earlier_translations() {
		$texts    = swiftling_subject_texts( $this->key, 'de' );
		$title    = $this->text( $texts, 'field/post_title' );
		$subtitle = $this->text( $texts, 'meta/subtitle' );

		$saved = swiftling_save_earlier_texts(
			$this->key,
			'de',
			array(
				array(
					'key'     => $title['key'],
					'text'    => 'Kontakt DE',
					'earlier' => 'Kontakti',
				),
				array(
					'key'     => $subtitle['key'],
					'text'    => '<h2>Schreiben Sie uns</h2>',
					'earlier' => $subtitle['original'],
				),
				array(
					'key'     => md5( 'elsewhere' ),
					'text'    => 'Tajna',
					'earlier' => 'Tajno',
				),
			)
		);
		$this->assertSame( array( $title['key'] ), $saved['saved'] );
		$this->assertSame( array( $subtitle['key'], md5( 'elsewhere' ) ), $saved['ignored'], 'Not earlier; not a place of this subject' );

		$title = $this->text( swiftling_subject_texts( $this->key, 'de' ), 'field/post_title' );
		$this->assertSame( array( 'Kontakt', '', 'outdated', 'Kontakt DE' ), array( $title['original'], $title['translation'], $title['state'], $title['previous'] ), 'Kept by the page check' );
		$this->assertSame( array(), swiftling_get_translations( 'de_DE', array( $title['key'] ) ), 'Visitors read the original' );
		$this->assertSame( array(), swiftling_translated_locales( $this->post->ID ), 'The subtitle is not translated' );

		swiftling_save_texts(
			$this->key,
			'de',
			array(
				array(
					'key'  => $subtitle['key'],
					'text' => '<h2>Schreiben Sie uns</h2>',
				),
			),
			'reviewed'
		);
		$this->assertSame( array( 'de_DE' ), swiftling_translated_locales( $this->post->ID ) );
		$this->assertSame( 'outdated', swiftling_subject_states( $this->key )['de'] );
		require_once SWIFTLING_DIR . 'includes/translate-all.php';
		list( , $places, $translations ) = swiftling_object_places( array( $this->key ) );
		$this->assertSame( 0, swiftling_translate_all_empty_count( $this->key, $places[ $this->key ], $translations['de'] ), 'Left to the editor' );

		$again = swiftling_save_earlier_texts(
			$this->key,
			'de',
			array(
				array(
					'key'     => $subtitle['key'],
					'text'    => '<h2>Alt</h2>',
					'earlier' => '<h2>Staro</h2>',
				),
			)
		);
		$this->assertSame( array( $subtitle['key'] ), $again['ignored'], 'Translated now' );

		$french = swiftling_save_earlier_texts(
			$this->key,
			'fr',
			array(
				array(
					'key'     => $title['key'],
					'text'    => 'Contact',
					'earlier' => 'Kontakt nekad',
				),
			)
		);
		$this->assertSame( array( $title['key'] ), $french['ignored'], 'German waits with another earlier version' );
		$french = swiftling_save_earlier_texts(
			$this->key,
			'fr',
			array(
				array(
					'key'     => $title['key'],
					'text'    => 'Contact',
					'earlier' => 'Kontakti',
				),
			)
		);
		$this->assertSame( array( $title['key'] ), $french['saved'], 'The same earlier version' );
		$this->assertSame( 'outdated', $this->text( swiftling_subject_texts( $this->key, 'fr' ), 'field/post_title' )['state'] );

		$this->become( 'subscriber' );
		$this->assertSame( 'swiftling_subject_rights', swiftling_save_earlier_texts( $this->key, 'de', array() )->get_error_code() );
	}

	/**
	 * A list of comments gives one page of its comments, as the comments editor lists it, the
	 * newest first; their translations are saved by key then. Without a page it is refused, as it is
	 * translated on its own screen.
	 */
	public function test_a_page_of_comments() {
		$older = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post->ID,
				'comment_content'  => 'Prekrasna plaža!',
				'comment_approved' => 1,
				'comment_date_gmt' => '2026-10-01 10:00:00',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post->ID,
				'comment_content'  => 'Vraćamo se.',
				'comment_approved' => 1,
				'comment_date_gmt' => '2026-10-02 10:00:00',
			)
		);
		$subject = swiftling_subject( 'comments:post' );
		$this->assertSame( 'swiftling_subject_list', swiftling_subject_found( $subject )->get_error_code() );

		$found = swiftling_subject_found( $subject, array( 'page' => 1 ) );
		$this->assertIsArray( $found, is_wp_error( $found ) ? $found->get_error_message() : '' );
		$units = array_merge( ...array_values( $found['sections'] ) );
		$this->assertSame( array( 'Vraćamo se.', 'Prekrasna plaža!' ), array_column( $units, 'v' ) );
		$this->assertSame( array( 1, 1 ), array( $found['list']['page'], $found['list']['pages'] ) );

		$unit  = $units[1];
		$place = swiftling_live_places( $unit, $found['maps'][ $unit['o'] ]['units'] )[0];
		$saved = swiftling_save_texts(
			'comments:post',
			'de',
			array(
				array(
					'key'  => $place,
					'text' => 'Ein wunderschöner Strand!',
				),
			),
			'machine'
		);
		$this->assertSame( array( $place => 'machine' ), $saved['states'] );
		$this->assertSame( 'comment:' . $older, $unit['o'] );
		$this->assertSame( 'Ein wunderschöner Strand!', swiftling_get_translations( 'de_DE', array( $place ) )[ $place ]['text'] );
	}

	/**
	 * The address of a post and of a term is saved in a language as the editor saves a typed slug, an
	 * empty one gives back the original slug; a list without pages of its own has no address to save.
	 */
	public function test_addresses() {
		$this->use_pretty_permalinks();
		$address = swiftling_save_address( $this->key, 'de', 'Kontaktiere uns' );
		$this->assertSame( array( 'kontaktiere-uns', 'reviewed' ), array( $address['slug'], $address['state'] ) );
		$this->assertStringContainsString( '/kontaktiere-uns/', $address['url'] );
		$this->assertSame( '', swiftling_save_address( $this->key, 'de', '' )['slug'], 'The original again' );

		$term    = self::factory()->category->create_and_get( array( 'name' => 'Vijesti' ) );
		$address = swiftling_save_address( 'term:' . $term->term_id, 'de', 'nachrichten' );
		$this->assertSame( 'nachrichten', $address['slug'] );
		register_taxonomy( 'swiftling_hidden', 'post', array( 'public' => false ) );
		$hidden = self::factory()->term->create( array( 'taxonomy' => 'swiftling_hidden' ) );
		$this->assertWPError( swiftling_save_address( 'term:' . $hidden, 'de', 'versteckt' ) );

		$this->become( 'subscriber' );
		$this->assertSame( 'swiftling_subject_rights', swiftling_save_address( $this->key, 'de', 'x' )->get_error_code() );
	}

	/**
	 * Another plugin's languages become the site's: targets added with their address slugs, a
	 * language WordPress does not list as a custom one, the address format; targets the site has
	 * stay; the original must be the same, or SwiftLing must not be set up yet.
	 */
	public function test_import_languages() {
		$done = swiftling_import_languages(
			array(
				'locale' => 'hr',
				'slug'   => 'hr',
			),
			array(
				array(
					'locale'  => 'de_DE',
					'slug'    => 'deutsch',
					'visible' => false,
				),
				array(
					'locale'  => 'it_IT',
					'slug'    => 'it',
					'visible' => true,
				),
				array(
					'locale'  => 'tlh',
					'slug'    => 'tlh',
					'visible' => true,
					'name'    => 'Klingon',
				),
			),
			array(
				'format'        => 'directory',
				'source_folder' => false,
			)
		);
		$this->assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$this->assertSame( array( 'it_IT', 'tlh' ), $done['added'] );
		$this->assertSame( array( 'de_DE' ), $done['kept'], 'Already on the site' );
		$languages = swiftling_languages();
		$this->assertSame( 'de', $languages['targets']['de']['slug'], 'A target the site has keeps its slug' );
		$this->assertSame( 'Italiano', $languages['targets']['it']['name'] );
		$this->assertSame( 'Klingon', $languages['targets']['tlh']['name'] );
		$this->assertSame( 'directory', swiftling_saved_url_format() );
		$this->assertStringContainsString( 'Italiano was added.', wp_json_encode( swiftling_log_entries() ) );

		// A host format keeps its domains, but the site keeps its addresses until the hosts lead here.
		$hosted = swiftling_import_languages(
			array( 'locale' => 'hr' ),
			array(),
			array(
				'format'  => 'domain',
				'domains' => array( 'it_IT' => 'example.it' ),
			)
		);
		$this->assertSame( array( true, 'directory' ), array( $hosted['hosts'], swiftling_saved_url_format() ) );
		$this->assertSame( array( 'it_IT' => 'example.it' ), swiftling_saved_domains() );

		$this->assertSame( 'swiftling_import_source', swiftling_import_languages( array( 'locale' => 'de_DE' ), array() )->get_error_code(), 'Another original' );

		delete_option( 'swiftling_languages' );
		swiftling_forget_memo();
		$set = swiftling_import_languages(
			array(
				'locale' => 'fr_FR',
				'slug'   => 'fr',
			),
			array(
				array(
					'locale' => 'hr',
					'slug'   => 'hr',
				),
			)
		);
		$this->assertIsArray( $set );
		$this->assertSame( array( 'fr_FR', 'hr' ), array( swiftling_languages()['source']['locale'], swiftling_languages()['targets']['hr']['locale'] ), 'Set up from the import' );

		$this->become( 'editor' );
		$this->assertSame( 'swiftling_import_rights', swiftling_import_languages( array( 'locale' => 'fr_FR' ), array() )->get_error_code() );
	}

	/**
	 * The name another plugin gave a language WordPress lists under another name becomes the name
	 * SwiftLing's switcher shows for it (its label), so the switcher reads as before. WordPress's own
	 * name is no label, and a custom language has the name given as its name.
	 */
	public function test_import_keeps_the_names_of_languages() {
		delete_option( 'swiftling_languages' );
		swiftling_forget_memo();
		$done = swiftling_import_languages(
			array(
				'locale' => 'hr',
				'slug'   => 'hr',
				'name'   => 'Hrvatski jezik',
			),
			array(
				array(
					'locale'  => 'en_GB',
					'slug'    => 'en',
					'visible' => true,
					'name'    => 'English',
				),
				array(
					'locale'  => 'it_IT',
					'slug'    => 'it',
					'visible' => true,
					'name'    => 'Italiano',
				),
				array(
					'locale'  => 'tlh',
					'slug'    => 'tlh',
					'visible' => true,
					'name'    => 'Klingon',
				),
			)
		);
		$this->assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$languages = swiftling_languages();
		$this->assertSame( array( 'English (UK)', 'English' ), array( $languages['targets']['en']['name'], $languages['targets']['en']['label'] ) );
		$this->assertSame( '', $languages['targets']['it']['label'], 'WordPress\'s own name' );
		$this->assertSame( array( 'Klingon', '' ), array( $languages['targets']['tlh']['name'], $languages['targets']['tlh']['label'] ), 'A custom language' );
		$this->assertSame( array( 'Hrvatski', 'Hrvatski jezik' ), array( $languages['source']['name'], $languages['source']['label'] ), 'The original too' );
	}

	/**
	 * Nothing is listed or saved for what does not exist, for a language the site is not translated
	 * into, for a user who may not translate it, nor listed for a list that has a screen of its own.
	 */
	public function test_what_and_who() {
		$this->assertSame( 'swiftling_subject', swiftling_subject_texts( 'post:999999', 'de' )->get_error_code() );
		$this->assertSame( 'swiftling_language', swiftling_subject_texts( $this->key, 'xx' )->get_error_code() );
		$this->assertSame( 'swiftling_language', swiftling_save_texts( $this->key, 'xx', array(), 'reviewed' )->get_error_code() );
		$this->assertSame( 'swiftling_subject_list', swiftling_subject_texts( 'comments:post', 'de' )->get_error_code() );
		$this->assertSame( 'swiftling_subject_list', swiftling_machine_translate_subject( 'comments:post', 'de' )->get_error_code() );

		$this->become( 'subscriber' );
		foreach ( array( swiftling_subject_texts( $this->key, 'de' ), swiftling_save_texts( $this->key, 'de', array(), 'reviewed' ), swiftling_machine_translate_subject( $this->key, 'de' ) ) as $refused ) {
			$this->assertSame( 'swiftling_subject_rights', $refused->get_error_code() );
			$this->assertSame( 403, $refused->get_error_data()['status'] );
		}
	}

	/**
	 * The empty texts of a subject are translated with the service and saved as machine
	 * translations, a text inside tags with its tags put back and escaped, as the editor fills them;
	 * a second run finds nothing empty, and one asked to translate again translates them again. What
	 * the service sent is kept when it stops part of the way; a refused key saves nothing.
	 */
	public function test_machine_translate_subject() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );

		$done = swiftling_machine_translate_subject( $this->key, 'de' );
		$this->assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$this->assertSame( array( 2, 2, 7 + 22, '' ), array( $done['texts'], $done['translated'], $done['characters'], $done['error'] ) );
		$title    = $this->place( 'field/post_title', 'Kontakt' );
		$subtitle = $this->place( 'meta/subtitle', '<h2>Javite nam se &amp; pišite</h2>' );
		$saved    = swiftling_get_translations( 'de_DE', array( $title, $subtitle ) );
		$this->assertSame( array( 'KONTAKT', SWIFTLING_MACHINE ), array_values( $saved[ $title ] ) );
		$this->assertSame( '<h2>JAVITE NAM SE &amp; PIŠITE</h2>', $saved[ $subtitle ]['text'] );
		$this->assertSame( 'Javite nam se & pišite', FakeService::$sent[ $subtitle ]['text'], 'Sent without its tags' );

		FakeService::$sent = array();
		$this->assertSame( 0, swiftling_machine_translate_subject( $this->key, 'de' )['texts'], 'Nothing empty' );
		$this->assertSame( array(), FakeService::$sent );

		FakeService::$stopped = 'Quota used up.';
		$again                = swiftling_machine_translate_subject( $this->key, 'de', array( 'machine' ) );
		$this->assertSame( array( 2, 2, 'Quota used up.' ), array( $again['texts'], $again['translated'], $again['error'] ) );

		FakeService::$failure = new \WP_Error( 'fake_key', 'This key does not work.' );
		$this->assertSame( 'fake_key', swiftling_machine_translate_subject( $this->key, 'de', array( 'machine' ) )->get_error_code() );
		$this->assertSame( 'KONTAKT', swiftling_get_translations( 'de_DE', array( $title ) )[ $title ]['text'], 'What was saved stays' );
	}

	/**
	 * A language that shows a page of its own for the post, a duplicate here, has nothing to fill in,
	 * and a translator the manager keeps from machine translation is refused.
	 */
	public function test_machine_translate_subject_leaves_alone() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		add_post_meta( $this->post->ID, SWIFTLING_DUPLICATE, 'de_DE' );
		$done = swiftling_machine_translate_subject( $this->key, 'de' );
		$this->assertSame( array( true, 0 ), array( $done['own_page'], $done['texts'] ) );
		$this->assertSame( array(), FakeService::$sent );

		$this->become( 'editor' );
		add_filter( 'swiftling_can_translate', '__return_true' );
		add_filter( 'swiftling_translator_machine', '__return_false' );
		$this->assertSame( 'swiftling_machine_rights', swiftling_machine_translate_subject( $this->key, 'fr' )->get_error_code() );
		$this->assertSame( 'swiftling_machine_rights', swiftling_machine_translate( $this->key, 'fr', array() )->get_error_code() );
	}

	/**
	 * The server cuts a run into the batches the browser cuts, and puts the tags of a text back
	 * around its translation as the browser does.
	 */
	public function test_batches_and_tags() {
		$short = array_fill( 0, 120, array( 'text' => 'abc' ) );
		$this->assertSame( array( 50, 50, 20 ), array_map( 'count', swiftling_machine_batches( $short ) ) );
		$long = array( array( 'text' => str_repeat( 'x', 3000 ) ), array( 'text' => 'kraj' ), array( 'text' => str_repeat( 'y', 3000 ) ), array( 'text' => str_repeat( 'z', 5000 ) ), array( 'text' => 'kraj' ) );
		$this->assertSame( array( 2, 1, 1, 1 ), array_map( 'count', swiftling_machine_batches( $long ) ), 'Up to the characters of a batch; a longer text goes alone' );

		$js = (string) file_get_contents( SWIFTLING_DIR . 'assets/js/machine.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin.
		$this->assertMatchesRegularExpression( '/const MAX_TEXTS = ' . SWIFTLING_MACHINE_BATCH_TEXTS . ';/', $js );
		$this->assertMatchesRegularExpression( '/const MAX_CHARACTERS = ' . SWIFTLING_MACHINE_BATCH_CHARACTERS . ';/', $js );

		$heading = array(
			'prefix' => '<h2>',
			'suffix' => '</h2>',
			'escape' => true,
		);
		$this->assertSame( '<h2>A &amp; B &lt;C&gt;</h2>', swiftling_machine_wrapped( $heading, 'A & B <C>' ) );
		$this->assertSame( '<p><b>A</b> &amp; B</p>', swiftling_machine_wrapped( array( 'escape' => false ) + array_combine( array( 'prefix', 'suffix' ), array( '<p>', '</p>' ) ), '<b>A</b> &amp; B' ) );
		$this->assertSame( '', swiftling_machine_wrapped( $heading, '  ' ) );
	}
}
