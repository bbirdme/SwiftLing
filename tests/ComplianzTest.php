<?php
/**
 * Tests of the class of Complianz.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Complianz;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeComplianz.php';
require_once __DIR__ . '/stubs/complianz-functions.php';

/**
 * What SwiftLing translates of Complianz: the texts of a cookie banner, rows of the plugin's own
 * table, and what its documents name of the organisation, values of its settings.
 */
class ComplianzTest extends TestCase {

	/**
	 * The class, with the plugin's banners.
	 *
	 * @var FakeComplianz
	 */
	private $cmplz;

	/**
	 * The plugin's banner.
	 *
	 * @var FakeComplianzBanner
	 */
	private $banner;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin, with a banner and the
	 * plugin's settings.
	 */
	public function set_up() {
		parent::set_up();
		FakeComplianz::install();
		$this->cmplz          = new FakeComplianz();
		$this->banner         = new FakeComplianzBanner(
			3,
			array(
				'id'                  => 3,
				'title_class'         => 'banner-a',
				// A text the banner can hide is an array with "text", as the plugin keeps it.
				'header'              => array(
					'text' => 'Upravljanje pristankom',
					'show' => 1,
				),
				'message_optin'       => 'Koristimo tehnologije poput kolačića.',
				'accept_optin'        => 'Prihvati',
				'category_functional' => 'Funkcionalni',
				'position'            => 'bottom',
				'use_categories'      => 'view-preferences',
			)
		);
		$this->cmplz->banners = array( $this->banner );
		$builders             = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Complianz ? $this->cmplz : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->cmplz->register();
		update_option(
			Complianz::OPTION,
			array(
				'organisation_name'               => 'Tihi putevi d.o.o.',
				'address_company'                 => 'Mlinska 12, Varaždin',
				'regions'                         => 'eu',
				'contact_retain_wmy'              => 'dvije godine',
				'contact_processing_data_lawfull' => '1',
			)
		);
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * The subject of the plugin's settings offers the texts of each banner, named after the banner,
	 * and what the documents name of the organisation, and no region, position or choice. Translated,
	 * the banner the plugin hands its template and its settings are in the language; the original
	 * page keeps them as stored.
	 */
	public function test_banner_and_organisation_translated() {
		$subject = swiftling_subject( 'complianz-settings' );
		$this->assertSame( 'Complianz settings', $subject['title'] );
		$units = array();
		foreach ( swiftling_save_field_list( $subject )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Tihi putevi d.o.o.', 'Mlinska 12, Varaždin', 'dvije godine', 'Upravljanje pristankom', 'Koristimo tehnologije poput kolačića.', 'Prihvati', 'Funkcionalni' ), array_keys( $units ) );
		$this->assertSame( 'complianz:3', $units['Prihvati']['o'] );
		$this->assertSame( 'Banner 3 › Title', Registry::fieldLabel( 'complianz:3', $units['Upravljanje pristankom']['p'] ) );
		$this->assertSame( 'Banner 3 › Accept button', Registry::fieldLabel( 'complianz:3', $units['Prihvati']['p'] ) );
		$this->assertSame( 'Name of the organisation', Registry::fieldLabel( 'options', $units['Tihi putevi d.o.o.']['p'] ) );
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/' . Complianz::OPTION . '/regions' ) );

		$german = array(
			'Upravljanje pristankom' => 'Zustimmung verwalten',
			'Prihvati'               => 'Akzeptieren',
			'Tihi putevi d.o.o.'     => 'Stille Wege GmbH',
		);
		foreach ( $german as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}

		$this->show_in( 'de' );
		$shown = $this->banner->get_html_settings();
		$this->assertSame(
			array(
				'text' => 'Zustimmung verwalten',
				'show' => 1,
			),
			$shown['header']
		);
		$this->assertSame( 'Akzeptieren', $shown['accept_optin'] );
		$this->assertSame( 'Koristimo tehnologije poput kolačića.', $shown['message_optin'], 'Not translated yet' );
		$this->assertSame( 'bottom', $shown['position'] );
		$this->assertSame( 'Stille Wege GmbH', get_option( Complianz::OPTION )['organisation_name'] );
		$this->assertSame( 'eu', get_option( Complianz::OPTION )['regions'] );

		$this->reset_state();
		$this->assertSame( $this->banner->row, $this->banner->get_html_settings(), 'The original page' );
	}

	/**
	 * The page loads the banner, the page to manage consent and the documents from the plugin's REST
	 * API: answered in its language. Other routes of the plugin are not.
	 */
	public function test_rest_routes_of_the_page() {
		foreach ( array( '/complianz/v1/banner', '/complianz/v1/manage_consent_html', '/complianz/v1/documents' ) as $route ) {
			$this->assertTrue( Registry::sendsFormTo( $route ), $route );
		}
		$this->assertFalse( Registry::sendsFormTo( '/complianz/v1/track' ) );
	}

	/**
	 * Asks the plugin's REST API for a consent area of a post from a page in a language, which the
	 * plugin answers with what the area holds, as stored.
	 *
	 * @param int    $post_id The post.
	 * @param string $area    The area's ID.
	 * @param mixed  $stored  What the plugin answered.
	 * @return mixed
	 */
	private static function consent_area( $post_id, $area, $stored ) {
		$request = new \WP_REST_Request( 'GET', '/complianz/v1/consent-area/' . $post_id . '/' . $area );
		return apply_filters( 'rest_request_after_callbacks', $stored, array(), $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as its REST API runs it.
	}

	/**
	 * What a consent area holds shows only once a visitor consents, so a page check never sees it: the
	 * editor offers it with the post's fields, a block's and a shortcode's, under the paths of the
	 * post's content. Translated, the plugin's REST API answers with it in the language of the page,
	 * the original page as stored; a refusal of the plugin stays.
	 */
	public function test_consent_areas_offered_and_loaded_translated() {
		// Who may write a block's markup.
		$this->become( 'administrator' );
		$blocks    = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Uvod.</p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:complianz/consent-area {"blockId":"video","postId":1,"placeholderContent":"<p>Prihvatite kolačiće.</p>","consentedContent":"<p>Video obilazak vrta.</p>"} /-->',
			)
		);
		$shortcode = self::factory()->post->create(
			array(
				'post_content' => '<p>Uvod.</p>[cmplz-consent-area clientside="1" id="karta"]<p>Karta do vrta.</p>[/cmplz-consent-area]',
			)
		);
		$hidden    = array(
			$blocks    => array( 'video', '<p>Video obilazak vrta.</p>', '<p>Videoführung durch den Garten.</p>' ),
			$shortcode => array( 'karta', '<p>Karta do vrta.</p>', '<p>Karte zum Garten.</p>' ),
		);
		foreach ( $hidden as $post_id => $area ) {
			$listed = Registry::listTexts( swiftling_subject( 'post:' . $post_id ) );
			$this->assertSame( array( $area[1] ), wp_list_pluck( $listed, 'v' ), 'Only what the area holds' );
			$this->assertSame( 'Shown once a visitor consents', $listed[0]['place'] );

			// The editor's page check, where the area's marker lands nowhere: it lists it with the
			// post's fields.
			$result = $this->check(
				'post:' . $post_id,
				static function () use ( $post_id ) {
					$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The post of the page, as WordPress sets it.
					apply_filters( 'the_content', $GLOBALS['post']->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a page runs it.
				},
				array( $listed[0]['p'] => null )
			);
			$fields = $result['sections']['fields'];
			$this->assertSame( array( $area[1] ), wp_list_pluck( $fields, 'v' ) );
			$this->assertNotContains( $area[1], wp_list_pluck( $result['sections']['page'], 'v' ) );
			$this->translate( $fields[0]['o'], $fields[0]['p'], $area[1], 'de', $area[2] );
		}
		$this->assertSame( array(), Registry::listTexts( swiftling_subject( 'post:' . self::factory()->post->create() ) ), 'A post without one' );

		$this->assertSame( '<p>Video obilazak vrta.</p>', self::consent_area( $blocks, 'video', '<p>Video obilazak vrta.</p>' ), 'The original page' );
		$this->show_in( 'de' );
		foreach ( $hidden as $post_id => $area ) {
			$this->assertSame( $area[2], self::consent_area( $post_id, $area[0], $area[1] ) );
		}
		$this->assertSame( '<p>Karte zum Garten.</p>', self::consent_area( $shortcode, 'default', '<p>Karta do vrta.</p>' ), 'The first area, as the plugin hands it out' );
		$this->assertSame( '', self::consent_area( $blocks, 'drugo', '' ), 'No such area' );
		$refused = new \WP_Error( 'rest_forbidden', '', array( 'status' => 403 ) );
		$this->assertSame( $refused, self::consent_area( $blocks, 'video', $refused ) );
		$this->assertTrue( Registry::sendsFormTo( '/complianz/v1/consent-area/' . $blocks . '/video' ) );
	}

	/**
	 * The plugin keeps the titles of the legal documents its banner links to for ten minutes, for each
	 * language. Once a translation is saved and its pages are emptied, those copies are gone too, so a
	 * translated title shows at once; the plugin's other copies stay.
	 */
	public function test_document_links_forgotten_after_a_save() {
		$post = get_post( self::factory()->post->create() );
		// What publishing the post queued.
		swiftling_run_purge();
		$keep = array(
			'value'   => 'x',
			'expires' => time() + 600,
		);
		update_option(
			'cmplz_transients',
			array(
				'page_links_3_de_DE'                  => $keep,
				'page_links_3_hr'                     => $keep,
				'cmplz_shortcode_cookie-statement-eu' => $keep,
			),
			false
		);
		swiftling_purge_post( $post, array( 'de' ) );
		swiftling_run_purge();
		$this->assertSame( array( 'cmplz_shortcode_cookie-statement-eu' ), array_keys( get_option( 'cmplz_transients' ) ) );

		update_option( 'cmplz_transients', array( 'page_links_3_de_DE' => $keep ), false );
		swiftling_purge_all_later();
		swiftling_run_purge();
		$this->assertSame( array(), get_option( 'cmplz_transients' ), 'After a change shown on every page' );
	}
}
