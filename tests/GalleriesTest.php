<?php
/**
 * Tests of the classes of the plugins of galleries and sliders.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Modula;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeFooGallery.php';
require_once __DIR__ . '/stubs/FakeMetaSlider.php';
require_once __DIR__ . '/stubs/FakeModula.php';
require_once __DIR__ . '/stubs/FakeEnvira.php';
require_once __DIR__ . '/stubs/FakeNextGen.php';
require_once __DIR__ . '/stubs/FakePhotoGallery.php';
require_once __DIR__ . '/stubs/FakeMasterSlider.php';
require_once __DIR__ . '/stubs/FakeSmartSlider.php';
require_once __DIR__ . '/stubs/ngg-transient.php';

/**
 * What SwiftLing translates of FooGallery, MetaSlider, Modula, Envira Gallery, NextGEN Gallery, Photo
 * Gallery, Master Slider and Smart Slider 3: each gallery or slider a subject of its own, the texts of an
 * image of the media library translated under the image, and the plugin's own texts where it hands them
 * out or prints them, with no translation left in a copy the plugin keeps for every language.
 */
class GalleriesTest extends TestCase {

	/**
	 * An image of the media library.
	 *
	 * @var int
	 */
	private $image;

	/**
	 * German, an image of the media library, and the fakes in the registry.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->image = $this->image( 'bosiljak.jpg', 'Bosiljak na suncu', 'Bosiljak treba toplinu.', 'Slatki bosiljak, posijan u svibnju.', 'Lonci bosiljka' );
		FakeNextGen::install();
		FakePhotoGallery::install();
		FakeMasterSlider::install();
		FakeSmartSlider::install();
		$fakes    = array(
			'foogallery'    => new FakeFooGallery(),
			'metaslider'    => new FakeMetaSlider(),
			'modula'        => new FakeModula(),
			'envira'        => new FakeEnvira(),
			'nextgen'       => new FakeNextGen(),
			'photo-gallery' => new FakePhotoGallery(),
			'master-slider' => new FakeMasterSlider(),
			'smart-slider'  => new FakeSmartSlider(),
		);
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $fakes[ $id ] ?? $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
	}

	/**
	 * Gives the registry back its own builders.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Adds an image to the media library.
	 *
	 * @param string $file        File name.
	 * @param string $title       Title.
	 * @param string $caption     Caption.
	 * @param string $description Description.
	 * @param string $alt         Alt text.
	 * @return int The image's ID.
	 */
	private function image( $file, $title, $caption, $description, $alt ) {
		$id = self::factory()->attachment->create(
			array(
				'post_title'     => $title,
				'post_excerpt'   => $caption,
				'post_content'   => $description,
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $id, '_wp_attached_file', $file );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return $id;
	}

	/**
	 * Notes the texts of a subject as the editor does, and translates some into German.
	 *
	 * @param string $key          Subject key.
	 * @param array  $translations Original => German.
	 * @return array<string, array> The texts offered, by original.
	 */
	private function units( $key, $translations = array() ) {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( $key ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		foreach ( $translations as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
		return $units;
	}

	/**
	 * Returns the subjects the Templates screen lists for a plugin.
	 *
	 * @param string $name The plugin's name.
	 * @return string[] Subject keys.
	 */
	private function listed( $name ) {
		return wp_list_pluck( Registry::subjects()[ $name ][0]['subjects'] ?? array(), 'key' );
	}

	/**
	 * Translations of the image of the media library: its title, caption and description.
	 */
	private function translate_image() {
		$owner = 'post:' . $this->image;
		$this->translate( $owner, 'field/post_title', 'Bosiljak na suncu', 'de', 'Basilikum in der Sonne' );
		$this->translate( $owner, 'field/post_excerpt', 'Bosiljak treba toplinu.', 'de', 'Basilikum braucht Wärme.' );
		$this->translate( $owner, 'field/post_content', 'Slatki bosiljak, posijan u svibnju.', 'de', 'Süßes Basilikum, im Mai gesät.' );
	}

	/**
	 * FooGallery: a gallery offers the texts of its images under each image, with their file names;
	 * translated, the plugin's object of an image and the captions it reads once more from the image's
	 * post are in the language, a value the image does not hold, as its file name, stays; in a language
	 * the plugin's copy of a gallery's HTML is not used.
	 */
	public function test_foogallery() {
		$foogallery = Registry::get( 'foogallery' );
		register_post_type( 'foogallery' );
		$gallery = self::factory()->post->create(
			array(
				'post_type'  => 'foogallery',
				'post_title' => 'Bilje',
			)
		);
		update_post_meta( $gallery, 'foogallery_attachments', array( (string) $this->image ) );
		$this->assertSame( array( 'foogallery:' . $gallery ), $this->listed( 'FooGallery' ) );
		$units = $this->units( 'foogallery:' . $gallery );
		$this->assertSame( array( 'Bosiljak na suncu', 'Bosiljak treba toplinu.', 'Slatki bosiljak, posijan u svibnju.', 'Lonci bosiljka' ), array_keys( $units ) );
		$this->assertSame( 'post:' . $this->image, $units['Bosiljak treba toplinu.']['o'], 'Under the image, once for every gallery' );
		$this->assertSame( 'bosiljak.jpg › Caption', $units['Bosiljak treba toplinu.']['place'] );
		$this->translate_image();
		unregister_post_type( 'foogallery' );

		$this->show_in( 'de' );
		$image = (object) array(
			'ID'          => $this->image,
			'title'       => 'Bosiljak na suncu',
			'caption'     => 'Bosiljak treba toplinu.',
			'description' => 'Moj vlastiti opis',
		);
		$foogallery->translateImage( $image );
		$this->assertSame( array( 'Basilikum in der Sonne', 'Basilikum braucht Wärme.', 'Moj vlastiti opis' ), array( $image->title, $image->caption, $image->description ), 'A value the image does not hold stays' );
		$this->assertSame( 'Basilikum braucht Wärme.', $foogallery->translateCaption( 'Bosiljak treba toplinu.', get_post( $this->image ) ) );
		$this->assertSame( 'Basilikum in der Sonne', $foogallery->translateCaption( 'Bosiljak na suncu', get_post( $this->image ) ) );
		$this->assertSame( 'bosiljak.jpg', $foogallery->translateCaption( 'bosiljak.jpg', get_post( $this->image ) ) );

		$foogallery->start( 'translate', false );
		$this->assertTrue( apply_filters( 'foogallery_html_cache_disabled', false, null ), 'No copy of the HTML in a language' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		remove_all_filters( 'foogallery_html_cache_disabled' );
		$foogallery->start( 'control', true );
		$this->assertFalse( apply_filters( 'foogallery_html_cache_disabled', false, null ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.

		$this->reset_state();
		$this->assertSame( 'Bosiljak treba toplinu.', $foogallery->translateCaption( 'Bosiljak treba toplinu.', get_post( $this->image ) ) );
	}

	/**
	 * MetaSlider: a slider offers a slide's caption of its own and the texts of the images its slides
	 * take theirs from; translated, the plugin prints the slide's caption and the image's in the language.
	 */
	public function test_metaslider() {
		$metaslider = Registry::get( 'metaslider' );
		register_post_type( 'ml-slider' );
		register_post_type( 'ml-slide' );
		register_taxonomy( 'ml-slider', 'ml-slide' );
		$slider = self::factory()->post->create(
			array(
				'post_type'  => 'ml-slider',
				'post_title' => 'Vrt',
			)
		);
		$own    = self::factory()->post->create(
			array(
				'post_type'    => 'ml-slide',
				'post_excerpt' => 'Naše riječi: staza lavande otvara se u srpnju.',
			)
		);
		$taken  = self::factory()->post->create( array( 'post_type' => 'ml-slide' ) );
		$told   = self::factory()->post->create( array( 'post_type' => 'ml-slide' ) );
		update_post_meta( $told, '_thumbnail_id', $this->image );
		update_post_meta( $told, 'ml-slider_caption_source', 'image-description' );
		update_post_meta( $own, '_thumbnail_id', $this->image );
		update_post_meta( $taken, '_thumbnail_id', $this->image );
		update_post_meta( $taken, 'ml-slider_inherit_image_caption', 1 );
		update_post_meta( $taken, 'ml-slider_inherit_image_title', 1 );
		foreach ( array( $own, $taken, $told ) as $slide ) {
			wp_set_object_terms( $slide, (string) $slider, 'ml-slider' );
		}
		$this->assertSame( array( 'metaslider:' . $slider ), $this->listed( 'MetaSlider' ) );
		$units = $this->units( 'metaslider:' . $slider, array( 'Naše riječi: staza lavande otvara se u srpnju.' => 'Unsere Worte: Der Lavendelweg öffnet im Juli.' ) );
		$this->assertEqualsCanonicalizing( array( 'Naše riječi: staza lavande otvara se u srpnju.', 'Lonci bosiljka', 'Bosiljak treba toplinu.', 'Bosiljak na suncu', 'Slatki bosiljak, posijan u svibnju.' ), array_keys( $units ), 'The caption each slide shows: its own, or its image\'s caption or description' );
		$this->assertSame( 'Caption of the slide', $units['Naše riječi: staza lavande otvara se u srpnju.']['place'] );
		$this->translate_image();
		unregister_post_type( 'ml-slider' );
		unregister_post_type( 'ml-slide' );
		unregister_taxonomy( 'ml-slider' );

		$this->show_in( 'de' );
		$slide = $metaslider->translateSlide(
			array(
				'id'          => $own,
				'caption'     => 'Naše riječi: staza lavande otvara se u srpnju.',
				'caption_raw' => 'Naše riječi: staza lavande otvara se u srpnju.',
				'title'       => '',
			),
			$slider
		);
		$this->assertSame( array( 'Unsere Worte: Der Lavendelweg öffnet im Juli.', 'Unsere Worte: Der Lavendelweg öffnet im Juli.' ), array( $slide['caption'], $slide['caption_raw'] ) );
		$slide = $metaslider->translateSlide(
			array(
				'id'          => $taken,
				'caption'     => 'Bosiljak treba toplinu.',
				'caption_raw' => 'Bosiljak treba toplinu.',
				'title'       => 'Bosiljak na suncu',
			),
			$slider
		);
		$this->assertSame( array( 'Basilikum braucht Wärme.', 'Basilikum in der Sonne' ), array( $slide['caption'], $slide['title'] ) );

		$slide = $metaslider->translateSlide(
			array(
				'id'      => $told,
				'caption' => 'Slatki bosiljak, posijan u svibnju.',
			),
			$slider
		);
		$this->assertSame( 'Süßes Basilikum, im Mai gesät.', $slide['caption'], 'The image\'s description, as the slide chose' );

		$this->reset_state();
		$this->assertSame( 'Bosiljak treba toplinu.', $metaslider->translateSlide( array( 'id' => $taken, 'caption' => 'Bosiljak treba toplinu.' ), $slider )['caption'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One short slide.
	}

	/**
	 * Modula: the texts of the images, which the plugin reads from the media library over the copies in
	 * its meta, are translated under each image; a caption of the gallery's own, shown where the image has
	 * none, under the gallery; the copies in the meta are no text.
	 */
	public function test_modula() {
		$modula = Registry::get( 'modula' );
		register_post_type( Modula::POST_TYPE );
		$bare    = $this->image( 'metvica.jpg', 'Metvica', '', '', '' );
		$gallery = self::factory()->post->create(
			array(
				'post_type'  => Modula::POST_TYPE,
				'post_title' => 'Bilje',
			)
		);
		update_post_meta(
			$gallery,
			Modula::IMAGES,
			array(
				array(
					'id'          => $this->image,
					'title'       => 'Bosiljak na suncu',
					'description' => 'Bosiljak treba toplinu.',
				),
				array(
					'id'          => $bare,
					'title'       => 'Metvica',
					'description' => 'Metvica raste uz vodu.',
				),
			)
		);
		$this->assertSame( array( 'modula:' . $gallery ), $this->listed( 'Modula' ) );
		$units = $this->units(
			'modula:' . $gallery,
			array(
				'Metvica raste uz vodu.' => 'Minze wächst am Wasser.',
				'Metvica'                => 'Minze',
			)
		);
		$this->assertSame( array( 'Bosiljak na suncu', 'Bosiljak treba toplinu.', 'Lonci bosiljka', 'Metvica', 'Metvica raste uz vodu.' ), array_keys( $units ) );
		$this->assertSame( 'modula:gallery:' . $gallery, $units['Metvica raste uz vodu.']['o'] );
		$this->assertFalse( $modula->fieldHoldsText( 'post:' . $gallery, 'meta/' . Modula::IMAGES . '/0/title' ), 'The plugin shows the image\'s own' );
		$this->translate_image();
		unregister_post_type( Modula::POST_TYPE );

		$this->show_in( 'de' );
		$images = $modula->translateImages(
			array(
				array(
					'id'          => $this->image,
					'title'       => 'Bosiljak na suncu',
					'description' => 'Bosiljak treba toplinu.',
					'alt'         => 'Moj alt',
				),
				array(
					'id'          => $bare,
					'title'       => 'Metvica',
					'description' => 'Metvica raste uz vodu.',
				),
			),
			array( 'gallery_id' => $gallery )
		);
		$this->assertSame( array( 'Basilikum in der Sonne', 'Basilikum braucht Wärme.', 'Moj alt' ), array( $images[0]['title'], $images[0]['description'], $images[0]['alt'] ) );
		$this->assertSame( array( 'Minze', 'Minze wächst am Wasser.' ), array( $images[1]['title'], $images[1]['description'] ) );
	}

	/**
	 * Envira Gallery: a gallery offers the title, alt text and caption of each image and its description
	 * from its meta, no address or setting; translated, the gallery the plugin took from its cache, made
	 * in the original, prints in the language, and the plugin's cache never keeps what a page in a
	 * language read.
	 */
	public function test_envira() {
		$envira = Registry::get( 'envira' );
		register_post_type( 'envira' );
		$gallery = self::factory()->post->create(
			array(
				'post_type'  => 'envira',
				'post_title' => 'Bilje',
			)
		);
		$data    = array(
			'id'      => $gallery,
			'gallery' => array(
				$this->image => array(
					'status'  => 'active',
					'src'     => 'http://example.org/bosiljak.jpg',
					'title'   => 'Bosiljak na suncu',
					'alt'     => 'Lonci bosiljka',
					'caption' => 'Bosiljak u loncu',
					'link'    => 'http://example.org/bosiljak.jpg',
				),
			),
			'config'  => array(
				'columns'     => '3',
				'description' => 'Bilje iz našeg vrta.',
			),
		);
		update_post_meta( $gallery, '_eg_gallery_data', $data );
		$this->assertSame( array( 'envira:' . $gallery ), $this->listed( 'Envira Gallery' ) );
		$units = $this->units(
			'envira:' . $gallery,
			array(
				'Bosiljak na suncu'    => 'Basilikum in der Sonne',
				'Bilje iz našeg vrta.' => 'Kräuter aus unserem Garten.',
			)
		);
		$this->assertSame( array( 'Bosiljak na suncu', 'Lonci bosiljka', 'Bosiljak u loncu', 'Bilje iz našeg vrta.' ), array_keys( $units ) );
		$this->assertSame( 'bosiljak.jpg › Title', $units['Bosiljak na suncu']['place'] );

		$this->show_in( 'de' );
		$envira->start( 'translate', false );
		$cached                                       = $data;
		$cached['gallery'][ $this->image ]['caption'] = 'Moj opis';
		$printed                                      = apply_filters( 'envira_gallery_pre_data', $cached, $gallery ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( array( 'Basilikum in der Sonne', 'Lonci bosiljka', 'Moj opis' ), array( $printed['gallery'][ $this->image ]['title'], $printed['gallery'][ $this->image ]['alt'], $printed['gallery'][ $this->image ]['caption'] ), 'Only what the meta holds' );
		$this->assertSame( 'Kräuter aus unserem Garten.', $printed['config']['description'] );
		set_transient( '_eg_cache_id_' . $gallery, get_post_meta( $gallery, '_eg_gallery_data', true ) );
		$this->assertFalse( get_transient( '_eg_cache_id_' . $gallery ), 'What a language read is not kept' );
		set_transient( 'swiftling_test', 'kept' );
		$this->assertSame( 'kept', get_transient( 'swiftling_test' ) );
		remove_all_filters( 'envira_gallery_pre_data' );
		remove_action( 'set_transient', array( $envira, 'forgetCache' ) );

		$this->reset_state();
		$envira->start( 'control', true );
		set_transient( '_eg_cache_id_' . $gallery, $data );
		$this->assertSame( $data, get_transient( '_eg_cache_id_' . $gallery ), 'The original is kept' );
		$this->assertSame( $data, apply_filters( 'envira_gallery_pre_data', $data, $gallery ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		unregister_post_type( 'envira' );
	}

	/**
	 * NextGEN Gallery: a gallery offers the alt texts and descriptions of its images, one text of all its
	 * galleries; translated, the filter the plugin hands them to answers in the language, the plugin keeps
	 * what it printed by language, and a translation saved of one of its texts empties what it keeps.
	 */
	public function test_nextgen() {
		global $wpdb;
		$nextgen = Registry::get( 'nextgen' );
		$wpdb->insert( $wpdb->prefix . 'ngg_gallery', array( 'title' => 'Bilje', 'galdesc' => 'Nije prikazano.' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- The tests' copy of the plugin's table.
		$gallery = (int) $wpdb->insert_id;
		foreach ( array(
			'Lonci bosiljka' => 'Bosiljak treba toplinu.',
			'Metvica'        => '',
		) as $alt => $description ) {
			$wpdb->insert( $wpdb->prefix . 'ngg_pictures', array( 'galleryid' => $gallery, 'alttext' => $alt, 'description' => $description ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- As above.
		}
		$this->assertSame( array( 'nextgen:' . $gallery ), $this->listed( 'NextGEN Gallery' ) );
		$nextgen->register();
		\Imagely\NGG\Util\Transient::$flushed = array();
		$units                                = $this->units( 'nextgen:' . $gallery, array( 'Lonci bosiljka' => 'Töpfe mit Basilikum' ) );
		$this->assertSame( array( 'Lonci bosiljka', 'Bosiljak treba toplinu.', 'Metvica' ), array_keys( $units ), 'Not the gallery\'s title or description, shown nowhere' );
		$this->assertSame( 'nextgen:gallery:all', $units['Metvica']['o'] );
		$this->assertSame( array( 'displayed_gallery_rendering' ), \Imagely\NGG\Util\Transient::$flushed, 'A translation of its text empties what the plugin keeps' );
		$this->translate( 'post:' . $this->image, 'field/post_title', 'Bosiljak na suncu', 'de', 'Basilikum in der Sonne' );
		$this->assertCount( 1, \Imagely\NGG\Util\Transient::$flushed, 'Another translation does not' );
		remove_filter( 'localization', array( $nextgen, 'translateText' ) );
		remove_filter( 'ngg_displayed_gallery_cache_params', array( $nextgen, 'cacheLanguage' ) );
		remove_action( 'swiftling_translations_saved', array( $nextgen, 'translationsSaved' ) );

		$this->show_in( 'de' );
		$this->assertSame( 'Töpfe mit Basilikum', $nextgen->translateText( 'Lonci bosiljka' ) );
		$this->assertSame( 'Lonci', $nextgen->translateText( 'Lonci' ) );
		$this->assertSame(
			array(
				'x',
				'swiftling_language' => 'de',
			),
			$nextgen->cacheLanguage( array( 'x' ) )
		);
		$this->reset_state();
		$this->assertSame( 'Lonci bosiljka', $nextgen->translateText( 'Lonci bosiljka' ) );
		$this->assertSame( array( 'x' ), $nextgen->cacheLanguage( array( 'x' ) ) );
	}

	/**
	 * Photo Gallery: a gallery offers its name and description and the alt texts and descriptions of its
	 * images; translated, they print in the language wherever the plugin prints one whole, in its HTML and
	 * its JSON, also in the lightbox it loads from admin-ajax.php in the language of the page, and a word
	 * of an address stays.
	 */
	public function test_photo_gallery() {
		global $wpdb;
		$photo = Registry::get( 'photo-gallery' );
		$wpdb->insert( $wpdb->prefix . 'bwg_gallery', array( 'name' => 'Bilje', 'description' => 'Četiri biljke iz vrta.' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- The tests' copy of the plugin's table.
		$gallery = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'bwg_image', array( 'gallery_id' => $gallery, 'alt' => 'Bosiljak', 'description' => 'Bosiljak treba toplinu.' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- As above.
		$this->assertSame( array( 'photo-gallery:' . $gallery ), $this->listed( 'Photo Gallery by 10Web' ) );
		$units = $this->units(
			'photo-gallery:' . $gallery,
			array(
				'Bosiljak'                => 'Basilikum',
				'Bosiljak treba toplinu.' => 'Basilikum braucht Wärme.',
			)
		);
		$this->assertSame( array( 'Bilje', 'Četiri biljke iz vrta.', 'Bosiljak', 'Bosiljak treba toplinu.' ), array_keys( $units ) );
		$this->assertSame( array( 'GalleryBox', 'bwg_frontend_data' ), $photo->sendingActions() );

		$this->show_in( 'de' );
		$html    = '<a href="http://example.org/Bosiljak.jpg" title="Bosiljak"><img src="http://example.org/Bosiljak.jpg" alt="Bosiljak"></a><div class="bwg-desc"> Bosiljak treba toplinu. </div><p>Bosiljak je biljka.</p>';
		$printed = $photo->translateShortcode( $html, 'Best_Wordpress_Gallery' );
		$this->assertSame( '<a href="http://example.org/Bosiljak.jpg" title="Basilikum"><img src="http://example.org/Bosiljak.jpg" alt="Basilikum"></a><div class="bwg-desc"> Basilikum braucht Wärme. </div><p>Bosiljak je biljka.</p>', $printed, 'Whole values only: an address and a longer text keep the word' );
		$this->assertSame( $html, $photo->translateShortcode( $html, 'gallery' ) );
		$json = 'var data = ' . wp_json_encode(
			array(
				array(
					'alt'         => 'Bosiljak',
					'description' => 'Bosiljak treba toplinu.',
				),
			)
		) . ';';
		$this->assertSame(
			'var data = ' . wp_json_encode(
				array(
					array(
						'alt'         => 'Basilikum',
						'description' => 'Basilikum braucht Wärme.',
					),
				)
			) . ';',
			$photo->translateOutput( $json ),
			'The lightbox\'s JSON'
		);

		$this->reset_state();
		$this->assertSame( $html, $photo->translateShortcode( $html, 'Best_Wordpress_Gallery' ) );
	}

	/**
	 * Master Slider: a slider offers the texts of its slides from the plugin's own table; translated,
	 * they print in the language where the plugin hands out the slider, after its cache; a deleted
	 * slider is not listed.
	 */
	public function test_master_slider() {
		global $wpdb;
		$master = Registry::get( 'master-slider' );
		$slides = array(
			'MSPanel.Slide' => array(
				'1' => wp_json_encode(
					array(
						'info'  => '<h3>Vrt</h3><p>Naše bilje.</p>',
						'bgAlt' => 'Lonci bosiljka',
					)
				),
			),
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The tests' copy of the plugin's table, as it keeps its slides.
		$wpdb->insert(
			$wpdb->prefix . 'masterslider_sliders',
			array(
				'title'  => 'Vrt',
				'params' => base64_encode( (string) wp_json_encode( $slides ) ),
				'status' => 'draft',
			)
		);
		$slider = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'masterslider_sliders',
			array(
				'title'  => 'Obrisan',
				'params' => '',
				'status' => 'deleted',
			)
		);
		// phpcs:enable
		$this->assertSame( array( 'master-slider:' . $slider ), $this->listed( 'Master Slider' ) );
		$units = $this->units(
			'master-slider:' . $slider,
			array(
				'<h3>Vrt</h3><p>Naše bilje.</p>' => '<h3>Garten</h3><p>Unsere Kräuter.</p>',
				'Lonci bosiljka'                 => 'Töpfe mit Basilikum',
			)
		);
		$this->assertSame( array( '<h3>Vrt</h3><p>Naše bilje.</p>', 'Lonci bosiljka' ), array_keys( $units ) );
		$this->assertSame( 'master-slider:slider:' . $slider, $units['Lonci bosiljka']['o'] );

		$this->show_in( 'de' );
		$html = '<div class="ms-info"><h3>Vrt</h3><p>Naše bilje.</p></div><img src="x.jpg" alt="Lonci bosiljka">';
		$this->assertSame( '<div class="ms-info"><h3>Garten</h3><p>Unsere Kräuter.</p></div><img src="x.jpg" alt="Töpfe mit Basilikum">', $master->translateSlider( $html, $slider ) );
		$this->reset_state();
		$this->assertSame( $html, $master->translateSlider( $html, $slider ) );
	}

	/**
	 * Smart Slider 3: a slider offers the title of each slide and the texts of its layers, not an
	 * address; translated, the slider the plugin hands out whole, from the copy it keeps in the
	 * original, prints in the language; a group or a slider in the bin is not listed.
	 */
	public function test_smart_slider() {
		global $wpdb;
		$smart = Registry::get( 'smart-slider' );
		$ids   = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The tests' copy of the plugin's tables.
		foreach ( array(
			array( 'Vrt', 'simple', 'published' ),
			array( 'Grupa', 'group', 'published' ),
			array( 'U košu', 'simple', 'trash' ),
		) as $row ) {
			$wpdb->insert(
				$wpdb->prefix . 'nextend2_smartslider3_sliders',
				array(
					'title'         => $row[0],
					'type'          => $row[1],
					'slider_status' => $row[2],
				)
			);
			$ids[] = (int) $wpdb->insert_id;
		}
		$layers = array(
			array(
				'type'   => 'content',
				'layers' => array(
					array(
						'type' => 'layer',
						'item' => array(
							'type'   => 'heading',
							'values' => array(
								'heading' => 'Dobro došli u vrt',
								'href'    => 'https://example.org/vrt/',
							),
						),
					),
					array(
						'type' => 'layer',
						'item' => array(
							'type'   => 'text',
							'values' => array( 'content' => '<p>Uzgajamo bilje.</p>' ),
						),
					),
				),
			),
		);
		$wpdb->insert(
			$wpdb->prefix . 'nextend2_smartslider3_slides',
			array(
				'title'       => 'Pozadina',
				'slider'      => $ids[0],
				'description' => '',
				'slide'       => wp_json_encode( $layers ),
			)
		);
		// phpcs:enable
		$this->assertSame( array( 'smart-slider:' . $ids[0] ), $this->listed( 'Smart Slider 3' ) );
		$units = $this->units(
			'smart-slider:' . $ids[0],
			array(
				'Pozadina'               => 'Hintergrund',
				'Dobro došli u vrt'      => 'Willkommen im Garten',
				'<p>Uzgajamo bilje.</p>' => '<p>Wir ziehen Kräuter.</p>',
			)
		);
		$this->assertSame( array( 'Pozadina', 'Dobro došli u vrt', '<p>Uzgajamo bilje.</p>' ), array_keys( $units ) );
		$this->assertSame( 'smart-slider:slider:all', $units['Pozadina']['o'] );
		$this->assertSame( 'Slider', $units['Pozadina']['place'], 'A text of the slider, not of an image' );

		$this->show_in( 'de' );
		$html = '<div class="n2-ss-slide" data-title="Pozadina"><h2 class="n2-ss-item-content"><a href="https://example.org/vrt/">Dobro došli u vrt</a></h2><div class="n2-ss-item-content"><p>Uzgajamo bilje.</p></div></div>';
		$this->assertSame( '<div class="n2-ss-slide" data-title="Hintergrund"><h2 class="n2-ss-item-content"><a href="https://example.org/vrt/">Willkommen im Garten</a></h2><div class="n2-ss-item-content"><p>Wir ziehen Kräuter.</p></div></div>', $smart->translateText( $html ) );
		$this->assertSame( 'Willkommen im Garten', $smart->translateText( 'Dobro došli u vrt' ) );
		$this->reset_state();
		$this->assertSame( $html, $smart->translateText( $html ) );
	}
}
