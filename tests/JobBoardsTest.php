<?php
/**
 * Tests of the classes of the plugins of job boards and directories.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/wp-job-manager-email.php';
require_once __DIR__ . '/stubs/rtcl-emails.php';
require_once __DIR__ . '/stubs/FakeWpJobManager.php';
require_once __DIR__ . '/stubs/FakeWpJobOpenings.php';
require_once __DIR__ . '/stubs/FakeClassifiedListing.php';
require_once __DIR__ . '/stubs/FakeGeoDirectory.php';
require_once __DIR__ . '/stubs/FakeBusinessDirectory.php';

/**
 * What SwiftLing translates of WP Job Manager, WP Job Openings, Classified Listing, GeoDirectory and
 * Business Directory Plugin: the texts the plugins read past the places SwiftLing finds texts at, each
 * email in the language of its reader, and the rules of addresses the same in every language.
 */
class JobBoardsTest extends TestCase {

	/**
	 * German, and the fakes in the registry.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		FakeGeoDirectory::install();
		FakeBusinessDirectory::install();
		$fakes    = array(
			'wp-job-manager'     => new FakeWpJobManager(),
			'wp-job-openings'    => new FakeWpJobOpenings(),
			'classified-listing' => new FakeClassifiedListing(),
			'geodirectory'       => new FakeGeoDirectory(),
			'business-directory' => new FakeBusinessDirectory(),
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
	 * A post type a plugin leaves out of menus is a page visitors open when the plugin's class names
	 * it, as WP Job Manager's listings: listed and translated as pages. Another such type is not.
	 */
	public function test_visitor_types() {
		register_post_type(
			'job_listing',
			array(
				'public'            => true,
				'show_in_nav_menus' => false,
			)
		);
		register_post_type(
			'hidden_thing',
			array(
				'public'            => true,
				'show_in_nav_menus' => false,
			)
		);
		$types = swiftling_content_types();
		$this->assertArrayHasKey( 'job_listing', $types );
		$this->assertArrayNotHasKey( 'hidden_thing', $types );
		$this->assertSame( array( 'job_listing' ), Registry::get( 'wp-job-manager' )->noCopyTypes() );
		unregister_post_type( 'job_listing' );
		unregister_post_type( 'hidden_thing' );
	}

	/**
	 * While the rules of addresses are built, a page's address keeps its original slug, so the rules
	 * are the same in every language; elsewhere a page in a language has its translated slug.
	 */
	public function test_rules_keep_original_slugs() {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'imenik',
				'post_title' => 'Imenik',
			)
		);
		swiftling_save_slug( 'post:' . $page, 'de_DE', 'verzeichnis' );
		swiftling_forget_slugs();
		$this->show_in( 'de' );
		$this->assertSame( 'verzeichnis', get_page_uri( $page ) );
		$seen = '';
		$rule = static function ( $rules ) use ( $page, &$seen ) {
			$seen = get_page_uri( $page );
			return $rules;
		};
		add_filter( 'rewrite_rules_array', $rule );
		apply_filters( 'rewrite_rules_array', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as it runs it.
		remove_filter( 'rewrite_rules_array', $rule );
		$this->assertSame( 'imenik', $seen, 'The rules are built from the original slug' );
		$this->assertSame( 'verzeichnis', get_page_uri( $page ) );
	}

	/**
	 * WP Job Manager: a job's description, which the plugin prints from the post itself, is translated
	 * where the plugin hands it out; a listing keeps the language it was posted in; the notice to its
	 * employer is written in that language, with the listing's title in it, and the notice to the site's
	 * owner in the original.
	 */
	public function test_wp_job_manager() {
		$wpjm = Registry::get( 'wp-job-manager' );
		register_post_type( 'job_listing', array( 'public' => true ) );
		$employer = self::factory()->user->create();
		$job      = self::factory()->post->create(
			array(
				'post_type'    => 'job_listing',
				'post_title'   => 'Vrtlar',
				'post_content' => 'Pomozite nam uzgajati bilje.',
				'post_author'  => $employer,
			)
		);
		$this->check(
			'post:' . $job,
			static function () use ( $job ) {
				swiftling_translate_post_content( 'post:' . $job, get_post( $job )->post_content );
				get_the_title( $job );
			}
		);
		$this->translate( 'post:' . $job, 'field/post_content', 'Pomozite nam uzgajati bilje.', 'de', 'Helfen Sie uns, Kräuter anzubauen.' );
		$this->translate( 'post:' . $job, 'field/post_title', 'Vrtlar', 'de', 'Gärtner' );

		$this->show_in( 'de' );
		$this->assertSame( 'Helfen Sie uns, Kräuter anzubauen.', $wpjm->translateDescription( 'Pomozite nam uzgajati bilje.', get_post( $job ) ) );
		$wpjm->keepLanguage( $job );
		$this->assertSame( 'de', get_post_meta( $job, 'swiftling_language', true ) );

		// The site's owner's notice, written from a page in German.
		$wpjm->startEmail( 'owner@example.org', new \WP_Job_Manager_Email_Admin_New_Job( array( 'job_id' => $job ), array() ) );
		$this->assertSame( '', swiftling_writing_language() );
		$wpjm->endEmail( true );
		$this->assertSame( 'de', swiftling_writing_language() );

		$this->reset_state();
		$this->assertSame( 'Pomozite nam uzgajati bilje.', $wpjm->translateDescription( 'Pomozite nam uzgajati bilje.', get_post( $job ) ) );
		// The employer's notice, written by a scheduled task.
		$email = new \WP_Job_Manager_Email_Employer_Expiring_Job( array( 'job_id' => $job ), array() );
		$wpjm->startEmail( 'employer@example.org', $email );
		$this->assertSame( 'de', swiftling_writing_language() );
		$property = new \ReflectionProperty( \WP_Job_Manager_Email::class, 'args' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$this->assertSame( 'Gärtner', $property->getValue( $email )['job']->post_title, 'The title the plugin prints from the post' );
		$wpjm->endEmail( true );
		$this->assertSame( '', swiftling_writing_language() );
		$this->assertSame( 'Vrtlar', get_post( $job )->post_title, 'Only the email\'s copy' );
		unregister_post_type( 'job_listing' );
	}

	/**
	 * WP Job Openings: the names of the specifications are text and their other values none; the email
	 * to an applicant is one text; the email to the site's owner about an application is written in the
	 * original, with the opening's original title, and the application keeps that title.
	 */
	public function test_wp_job_openings() {
		$wpjo = Registry::get( 'wp-job-openings' );
		$this->assertTrue( $wpjo->fieldHoldsText( 'options', 'option/awsm_jobs_filter/0/filter' ) );
		$this->assertFalse( $wpjo->fieldHoldsText( 'options', 'option/awsm_jobs_filter/0/taxonomy' ) );
		$this->assertTrue( $wpjo->fieldHoldsText( 'options', 'option/awsm_gdpr_cb_text' ) );
		$this->assertTrue( $wpjo->isWhole( 'option/awsm_jobs_notification_content' ) );
		$this->assertContains( 'awsm_applicant_form_submission', $wpjo->sendingActions() );

		register_post_type( 'awsm_job_openings', array( 'public' => true ) );
		$job = self::factory()->post->create(
			array(
				'post_type'  => 'awsm_job_openings',
				'post_title' => 'Glavni vrtlar',
			)
		);
		$this->show_in( 'de' );
		$wpjo->startEmails( array( 'applicant', 'admin' ) );
		$this->assertSame( 'de', swiftling_writing_language(), 'The applicant\'s email first, in the page\'s language' );
		$wpjo->startOwnerEmail( false );
		$this->assertSame( '', swiftling_writing_language() );
		$tags = $wpjo->ownerTags( array( '{job-title}' => 'Hauptgärtner' ), array( 'awsm_job_id' => $job ) );
		$this->assertSame( 'Glavni vrtlar', $tags['{job-title}'] );
		$wpjo->endEmails();
		$this->assertSame( 'de', swiftling_writing_language() );
		$this->assertSame( array( '{job-title}' => 'Hauptgärtner' ), $wpjo->ownerTags( array( '{job-title}' => 'Hauptgärtner' ), array( 'awsm_job_id' => $job ) ), 'Only in the owner\'s email' );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$_POST['awsm_job_id'] = (string) $job;
		$this->assertSame( 'Glavni vrtlar', $wpjo->originalTitle( 'Hauptgärtner', 'awsm_apply_for', 'post' ) );
		unset( $_POST['awsm_job_id'] );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unregister_post_type( 'awsm_job_openings' );
	}

	/**
	 * Classified Listing: the plugin does not put an email in the site's language itself; one to an ad's
	 * owner is written in the ad's language, one to the site's owner in the original, one to a visitor in
	 * the page's; while the rules are built, the address the plugin makes an endpoint of is the original.
	 */
	public function test_classified_listing() {
		$rtcl = Registry::get( 'classified-listing' );
		register_post_type( 'rtcl_listing', array( 'public' => true ) );
		$ad = self::factory()->post->create( array( 'post_type' => 'rtcl_listing' ) );
		update_post_meta( $ad, 'swiftling_language', 'de' );

		$owner         = new \Rtcl\Emails\ListingPublishedEmailToOwner();
		$owner->object = new \Rtcl\Emails\Listing( $ad );
		$this->assertFalse( $rtcl->switchLocale( true, $owner ) );
		$rtcl->startEmail( array( 'owner@example.org' ) );
		$this->assertSame( 'de', swiftling_writing_language(), 'From a request in the original' );
		$rtcl->startEmail( array( 'owner@example.org' ) );
		$this->assertFalse( $rtcl->endEmail( true, $owner ) );
		$this->assertSame( '', swiftling_writing_language() );

		$this->show_in( 'de' );
		$site         = new \Rtcl\Emails\ListingContactEmailToAdmin();
		$site->object = new \Rtcl\Emails\Listing( $ad );
		$rtcl->switchLocale( true, $site );
		$rtcl->startEmail( array( 'admin@example.org' ) );
		$this->assertSame( '', swiftling_writing_language() );
		$rtcl->endEmail( true, $site );
		$this->assertSame( 'de', swiftling_writing_language() );
		$visitor = new \Rtcl\Emails\UserNewRegistrationEmailToUser();
		$rtcl->switchLocale( true, $visitor );
		$rtcl->startEmail( array( 'erika@example.org' ) );
		$this->assertSame( 'de', swiftling_writing_language() );
		$rtcl->endEmail( true, $visitor );

		$form = untrailingslashit( (string) get_option( 'home' ) ) . '/de/obrazac/';
		$this->assertSame( array( 'add-listing' => $form ), $rtcl->ruleEndpoints( array( 'add-listing' => $form ) ) );
		$seen = null;
		$rule = static function ( $rules ) use ( $rtcl, $form, &$seen ) {
			$seen = $rtcl->ruleEndpoints( array( 'add-listing' => $form ) );
			return $rules;
		};
		add_filter( 'rewrite_rules_array', $rule );
		apply_filters( 'rewrite_rules_array', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as it runs it.
		remove_filter( 'rewrite_rules_array', $rule );
		$this->assertSame( array( 'add-listing' => untrailingslashit( (string) get_option( 'home' ) ) . '/obrazac/' ), $seen, 'Without the language\'s folder' );
		unregister_post_type( 'rtcl_listing' );
	}

	/**
	 * GeoDirectory: the values of a listing's text fields, from the plugin's own table, are texts of the
	 * listing, named after their fields; a number stays as it is; the email to a listing's owner is written in
	 * the listing's language and the one to the site's owner in the original.
	 */
	public function test_geodirectory() {
		global $wpdb;
		$gd = Registry::get( 'geodirectory' );
		register_post_type( 'gd_place', array( 'public' => true ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The tests' copy of the plugin's table.
		$wpdb->insert( $wpdb->prefix . 'geodir_custom_fields', array( 'post_type' => 'gd_place', 'htmlvar_name' => 'opening_hours', 'field_type' => 'text', 'data_type' => 'VARCHAR', 'frontend_title' => 'Radno vrijeme' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One row.
		$wpdb->insert( $wpdb->prefix . 'geodir_custom_fields', array( 'post_type' => 'gd_place', 'htmlvar_name' => 'price', 'field_type' => 'text', 'data_type' => 'INT', 'frontend_title' => 'Cijena' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One row.
		// phpcs:enable
		$place   = self::factory()->post->create( array( 'post_type' => 'gd_place' ) );
		$listing = static function () use ( $place ) {
			return (object) array(
				'ID'            => $place,
				'post_type'     => 'gd_place',
				'opening_hours' => 'Svaki dan od devet do pet',
				'price'         => '12',
			);
		};
		$this->check(
			'post:' . $place,
			static function () use ( $gd, $listing, $place ) {
				$gd->translateListing( $listing(), $place );
			}
		);
		$this->translate( 'post:' . $place, 'geodirectory/opening_hours', 'Svaki dan od devet do pet', 'de', 'Jeden Tag von neun bis fünf' );
		$this->assertTrue( $gd->fieldHoldsText( 'post:' . $place, 'geodirectory/opening_hours' ) );
		$this->assertSame( 'Radno vrijeme', $gd->fieldLabel( 'post:' . $place, 'geodirectory/opening_hours' ) );

		$tab   = static function () {
			return array(
				(object) array(
					'id'       => 5,
					'tab_name' => 'Radno vrijeme',
				),
			);
		};
		$names = array();
		foreach ( $this->probe(
			static function () use ( $gd, $tab ) {
				$gd->translateTabs( $tab() );
			}
		) as $leaf ) {
			$names[ $leaf['o'] . ' ' . $leaf['p'] ] = $leaf['v'];
		}
		$this->assertSame( array( 'geodirectory:tabs tabs/5/name' => 'Radno vrijeme' ), $names, 'A tab\'s name is a text of the tabs' );
		$this->assertTrue( $gd->fieldHoldsText( 'geodirectory:tabs', 'tabs/5/name' ) );

		$this->show_in( 'de' );
		$shown = $gd->translateListing( $listing(), $place );
		$this->assertSame( array( 'Jeden Tag von neun bis fünf', '12' ), array( $shown->opening_hours, $shown->price ) );
		$gd->startEmail( 'admin_pending_post', array( 'post' => get_post( $place ) ) );
		$this->assertSame( '', swiftling_writing_language() );
		$gd->endEmail();
		$this->assertSame( 'de', swiftling_writing_language() );

		$this->reset_state();
		$this->assertSame( 'Svaki dan od devet do pet', $gd->translateListing( $listing(), $place )->opening_hours );
		update_post_meta( $place, 'swiftling_language', 'de' );
		$gd->startEmail( 'user_publish_post', array( 'post' => get_post( $place ) ) );
		$this->assertSame( 'de', swiftling_writing_language() );
		$gd->endEmail();
		$this->assertSame( '', swiftling_writing_language() );
		unregister_post_type( 'gd_place' );
	}

	/**
	 * Business Directory Plugin: the names and choices of its fields, from its own table, are offered on
	 * the Templates screen and translated where the plugin makes a field; a listing's description, which
	 * the plugin reads from the post, is translated where the plugin hands it out; its rules are built
	 * from its main page's original address, its links keep the language's.
	 */
	public function test_business_directory() {
		global $wpdb;
		$bdp = Registry::get( 'business-directory' );
		$wpdb->insert( $wpdb->prefix . 'wpbdp_form_fields', array( 'label' => 'Telefon', 'description' => 'Broj za pozive', 'field_data' => serialize( array( 'options' => array( 'Da', 'Ne' ) ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- The tests' copy of the plugin's table, as it keeps it.
		$field = (int) $wpdb->insert_id;
		$this->assertSame( array( 'business-directory-fields' ), wp_list_pluck( Registry::subjects()['Business Directory Plugin'][0]['subjects'], 'key' ) );
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'business-directory-fields' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Telefon', 'Broj za pozive', 'Da', 'Ne' ), array_keys( $units ) );
		$this->assertSame( 'Telefon › Name', $units['Telefon']['place'] );
		$this->translate( $units['Telefon']['o'], $units['Telefon']['p'], 'Telefon', 'de', 'Telefonnummer' );
		$this->translate( $units['Da']['o'], $units['Da']['p'], 'Da', 'de', 'Ja' );

		register_post_type( 'wpbdp_listing', array( 'public' => true ) );
		$listing = self::factory()->post->create(
			array(
				'post_type'    => 'wpbdp_listing',
				'post_content' => 'Organska metvica iz doline.',
			)
		);
		$this->check(
			'post:' . $listing,
			static function () use ( $listing ) {
				swiftling_translate_post_content( 'post:' . $listing, get_post( $listing )->post_content );
			}
		);
		$this->translate( 'post:' . $listing, 'field/post_content', 'Organska metvica iz doline.', 'de', 'Bio-Minze aus dem Tal.' );
		$content = new class() {
			/**
			 * Where the field's value is kept.
			 */
			public function get_association() {
				return 'content';
			}
		};

		$this->show_in( 'de' );
		$made = $bdp->translateField(
			array(
				'id'          => $field,
				'label'       => 'Telefon',
				'description' => 'Broj za pozive',
				'field_data'  => array( 'options' => array( 'Da', 'Ne' ) ),
			)
		);
		$this->assertSame( array( 'Telefonnummer', 'Ja' ), array( $made['label'], $made['field_data']['options'][0] ) );
		$this->assertSame( 'Bio-Minze aus dem Tal.', $bdp->translateValue( 'Organska metvica iz doline.', $listing, $content ) );
		$page = untrailingslashit( (string) get_option( 'home' ) ) . '/de/verzeichnis/';
		$this->assertSame( $page, $bdp->ruleBase( $page, 0, 'view' ), 'A link keeps the language\'s address' );
		$main = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'imenik',
			)
		);
		swiftling_save_slug( 'post:' . $main, 'de_DE', 'verzeichnis' );
		swiftling_forget_slugs();
		$this->assertStringEndsWith( '/verzeichnis/', get_page_link( $main ) );
		$this->assertStringEndsWith( '/imenik/', $bdp->ruleBase( get_page_link( $main ), $main ), 'The rules are built from the original slug' );

		$this->reset_state();
		$this->assertSame( 'Telefon', $bdp->translateField( array( 'id' => $field, 'label' => 'Telefon' ) )['label'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One field.
		unregister_post_type( 'wpbdp_listing' );
	}
}
