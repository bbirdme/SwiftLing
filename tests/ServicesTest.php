<?php
/**
 * Tests of the translation services and the Translation service screen.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Translation\Google;
use SwiftLing\Translation\Services;
use WPDieException;

/**
 * Services, their keys and states, this month's characters, and the screen's actions.
 */
class ServicesTest extends TestCase {

	/**
	 * Google is offered, and another plugin can add a service; anything else it adds is ignored.
	 */
	public function test_services() {
		$this->assertInstanceOf( Google::class, Services::get( 'google' ) );
		$this->assertNull( Services::get( 'deepl' ) );

		self::reset_static( Services::class, 'services' );
		add_filter(
			'swiftling_translation_services',
			static function ( $services ) {
				$services['fake']  = new FakeService();
				$services['wrong'] = 'not a service';
				return $services;
			}
		);
		$this->assertSame( array( 'google', 'fake' ), array_keys( Services::all() ) );
	}

	/**
	 * Settings are saved per service, and a null value removes one.
	 */
	public function test_settings() {
		Services::update(
			'google',
			array(
				'key'   => 'k',
				'state' => 'working',
			)
		);
		Services::update( 'fake', array( 'key' => 'f' ) );
		Services::update( 'google', array( 'state' => null ) );
		$this->assertSame( 'k', Services::setting( 'google', 'key' ) );
		$this->assertSame( 'fallback', Services::setting( 'google', 'state', 'fallback' ) );
		$this->assertSame( 'f', Services::setting( 'fake', 'key' ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( 'swiftling_service', wp_load_alloptions(), 'The key is not loaded on every request' );
	}

	/**
	 * A key is working only when the key that was checked is the one in use.
	 */
	public function test_state_follows_the_checked_key() {
		$google = new Google();
		$this->assertSame( 'unchecked', $google->state() );

		Services::update(
			'google',
			array(
				'key'         => 'one',
				'state'       => 'working',
				'fingerprint' => $google->fingerprint( 'one' ),
			)
		);
		$this->assertSame( 'working', $google->state() );
		$this->assertTrue( $google->isConnected() );
		$this->assertSame( 'google', Services::current()->id() );

		Services::update( 'google', array( 'key' => 'two' ) );
		$this->assertSame( 'unchecked', $google->state() );
		$this->assertFalse( $google->isConnected() );
		$this->assertNull( Services::current() );

		Services::update(
			'google',
			array(
				'state'       => 'failed',
				'fingerprint' => $google->fingerprint( 'two' ),
			)
		);
		$this->assertSame( 'failed', $google->state() );
		$this->assertSame( 16, strlen( $google->fingerprint( 'two' ) ) );
		$this->assertStringNotContainsString( 'two', (string) wp_json_encode( get_option( 'swiftling_service' )['google']['fingerprint'] ) );
	}

	/**
	 * Another plugin can choose the service per page and language, but only a connected one.
	 */
	public function test_service_for_a_target() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$german = swiftling_target_language( 'de' );
		$this->assertSame( 'fake', Services::forTarget( 'post:1', $german )->id() );

		add_filter(
			'swiftling_translation_service',
			static function ( $service, $subject, $language ) {
				return 'de_DE' === $language['locale'] ? new Google() : $service;
			},
			10,
			3
		);
		$this->assertNull( Services::forTarget( 'post:1', $german ), 'Google is not connected' );
		$this->assertSame( 'fake', Services::forTarget( 'post:1', swiftling_target_language( 'fr' ) )->id() );
	}

	/**
	 * Characters are counted per calendar month in UTC; a new month starts from zero.
	 */
	public function test_usage() {
		Services::addUsage( 'google', 100 );
		Services::addUsage( 'google', 23 );
		Services::addUsage( 'google', 0 );
		Services::addUsage( 'google', -5 );
		$this->assertSame( 123, Services::usage( 'google' ) );
		$this->assertSame( 0, Services::usage( 'fake' ) );

		update_option(
			Services::USAGE_OPTION,
			array(
				'month'  => '2000-01',
				'counts' => array( 'google' => 999 ),
			)
		);
		$this->assertSame( 0, Services::usage( 'google' ) );
		Services::addUsage( 'google', 5 );
		$this->assertSame( 5, Services::usage( 'google' ) );
	}

	/**
	 * Languages translated at once from the command line each count in their own process. A process
	 * that read the counts before another wrote must not write over the other's: here the row
	 * changes behind the cached copy, as a second process would change it.
	 */
	public function test_usage_counted_by_another_process_is_not_lost() {
		global $wpdb;
		Services::addUsage( 'openai', 100, 40 );
		// This process has the counts in its cache, as it would after reading them.
		$this->assertSame( 100, Services::usage( 'openai' ) );

		$row                        = get_option( Services::USAGE_OPTION );
		$other                      = $row;
		$other['counts']['openai'] += 50;
		$other['tokens']['openai'] += 20;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The test writes behind the cache on purpose.
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $other ) ), array( 'option_name' => Services::USAGE_OPTION ) );

		Services::addUsage( 'openai', 10, 5 );

		wp_cache_delete( Services::USAGE_OPTION, 'options' );
		$this->assertSame( 160, Services::usage( 'openai' ), 'The 50 counted by the other process stay' );
		$this->assertSame( 65, Services::tokens( 'openai' ) );
	}

	/**
	 * Tokens of language models are counted beside the characters, in the same month; tokens alone
	 * count too.
	 */
	public function test_tokens() {
		Services::addUsage( 'claude', 100, 250 );
		Services::addUsage( 'claude', 0, 50 );
		Services::addUsage( 'google', 10 );
		$this->assertSame( 100, Services::usage( 'claude' ) );
		$this->assertSame( 300, Services::tokens( 'claude' ) );
		$this->assertSame( 0, Services::tokens( 'google' ) );

		update_option(
			Services::USAGE_OPTION,
			array(
				'month'  => '2000-01',
				'counts' => array( 'claude' => 9 ),
				'tokens' => array( 'claude' => 999 ),
			)
		);
		$this->assertSame( 0, Services::tokens( 'claude' ) );
		Services::addUsage( 'claude', 0, 7 );
		$this->assertSame( 7, Services::tokens( 'claude' ) );
		$this->assertSame( 0, Services::usage( 'claude' ) );
	}

	/**
	 * A key from wp-config.php has its own constant name.
	 */
	public function test_constant_name() {
		$this->assertSame( 'SWIFTLING_GOOGLE_API_KEY', ( new Google() )->constant() );
		$this->assertFalse( ( new Google() )->keyFromConstant() );
	}

	/**
	 * Saving a key keeps it only in letters, digits, dashes, underscores, colons and dots, as a
	 * DeepL API Free key ends in :fx; checks it and says how it went.
	 */
	public function test_saving_a_key() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		Services::update( 'fake', array( 'key' => null ) );
		$this->become( 'administrator' );

		$this->post_form(
			'swiftling_save_service_key',
			array(
				'service' => 'fake',
				'key'     => ' "abc-123_XYZ.9:fx" ',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=working', $this->redirect_of( 'swiftling_save_service_key' ) );
		$this->assertSame( 'abc-123_XYZ.9:fx', Services::setting( 'fake', 'key' ) );
		$this->assertSame( 'working', Services::get( 'fake' )->state() );
		$this->assertSame( array( 'hr', 'de', 'fr' ), Services::get( 'fake' )->languages() );

		$this->post_form(
			'swiftling_save_service_key',
			array(
				'service' => 'fake',
				'key'     => 'bad',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=failed', $this->redirect_of( 'swiftling_save_service_key' ) );
		$this->assertSame( 'bad', Services::setting( 'fake', 'key' ), 'A key that fails is kept, to check again later' );
		$this->assertSame( 'This key does not work.', Services::setting( 'fake', 'error' ) );

		$this->post_form(
			'swiftling_save_service_key',
			array(
				'service' => 'fake',
				'key'     => '"" ',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=empty', $this->redirect_of( 'swiftling_save_service_key' ) );
	}

	/**
	 * Every check of a key is in the Logs: what the service said when it failed, that it works
	 * otherwise; never the key.
	 */
	public function test_key_checks_are_logged() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->become( 'administrator' );

		$this->post_form(
			'swiftling_save_service_key',
			array(
				'service' => 'fake',
				'key'     => 'bad',
			)
		);
		$this->redirect_of( 'swiftling_save_service_key' );
		$failed = swiftling_log_entries( array( 'area' => 'service' ) )['entries'];
		$this->assertCount( 1, $failed );
		$this->assertSame( 'error', $failed[0]->level );
		$this->assertSame( 'The check of the Fake key failed: This key does not work.', $failed[0]->message );
		$this->assertSame( 'fake · fake_key', $failed[0]->detail );

		$this->post_form(
			'swiftling_save_service_key',
			array(
				'service' => 'fake',
				'key'     => 'secret-key-123',
			)
		);
		$this->redirect_of( 'swiftling_save_service_key' );
		$entries = swiftling_log_entries( array( 'area' => 'service' ) )['entries'];
		$this->assertSame( 'event', $entries[0]->level );
		$this->assertSame( 'The key of Fake works.', $entries[0]->message );
		$this->assertStringNotContainsString( 'secret-key-123', wp_json_encode( $entries ) );
	}

	/**
	 * A key that works on an account that cannot pay is not a key that does not work: the row says
	 * the key works and what the service said, as a warning with its address as a link. It does not
	 * translate until the account can pay.
	 */
	public function test_key_on_an_account_that_cannot_pay() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->connect_google();
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_service_key',
			array(
				'service' => 'fake',
				'key'     => 'unpaid',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=unpaid', $this->redirect_of( 'swiftling_save_service_key' ) );
		$fake = Services::get( 'fake' );
		$this->assertSame( 'unpaid', $fake->state() );
		$this->assertFalse( $fake->isConnected() );
		$this->assertSame( 'google', Services::current()->id(), 'It does not translate' );
		$this->assertSame( 'warning', swiftling_log_entries( array( 'area' => 'service' ) )['entries'][0]->level );

		$_GET = array( 'swiftling_notice' => 'unpaid' );
		$html = $this->render_screen( 'service' );
		$row  = substr( $html, (int) strpos( $html, 'id="swiftling-service-fake"' ) );
		$row  = substr( $row, 0, (int) strpos( $row, 'id="swiftling-service-google"' ) );
		$this->assertStringContainsString( '<span class="swiftling-badge is-unpaid">Key works · needs credit</span>', $row );
		$this->assertStringNotContainsString( 'Key does not work', $row );
		$this->assertMatchesRegularExpression( '~class="swiftling-notice is-warning is-compact swiftling-service-row__said"[^>]*>.*?The key works, but Fake cannot bill your account yet\..*?You have no credits remaining\..*?<a href="https://fake\.example/settings/organization/billing/overview"[^>]*>~s', $row );
		$this->assertMatchesRegularExpression( '~<summary>Key and usage</summary>~', $row );
		$this->assertStringContainsString( 'The key works, but the account cannot pay yet', $html, 'The notice after saving' );
	}

	/**
	 * After an action on a service, the screen opens at that service's row, open, with the
	 * action's notice in the row rather than at the top of the screen.
	 */
	public function test_actions_return_to_the_row() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->connect_google();
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_test_service_key',
			array( 'service' => 'google' )
		);
		$location = $this->redirect_of( 'swiftling_test_service_key' );
		$this->assertStringContainsString( 'service=google', $location );
		$this->assertStringEndsWith( '#swiftling-service-google', $location );

		$this->post_form( 'swiftling_save_service_key', array( 'service' => 'google' ) );
		$this->assertStringEndsWith( 'swiftling_notice=empty&service=google#swiftling-service-google', $this->redirect_of( 'swiftling_save_service_key' ) );

		$_GET = array(
			'swiftling_notice' => 'empty',
			'service'          => 'google',
		);
		$html = $this->render_screen( 'service' );
		$top  = substr( $html, 0, (int) strpos( $html, 'class="swiftling-service-strip' ) );
		$row  = substr( $html, (int) strpos( $html, 'id="swiftling-service-google"' ) );
		$this->assertStringNotContainsString( 'Paste a key first.', $top, 'Not at the top' );
		$this->assertMatchesRegularExpression( '~^id="swiftling-service-google">.*?<details class="swiftling-service-row__more" open>.*?Paste a key first\.~s', $row, 'In the row, open, although Fake is the service in use' );
	}

	/**
	 * Removing a key removes what its check found too.
	 */
	public function test_removing_a_key() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->become( 'administrator' );
		$this->post_form( 'swiftling_remove_service_key', array( 'service' => 'fake' ) );
		$this->assertStringContainsString( 'swiftling_notice=removed', $this->redirect_of( 'swiftling_remove_service_key' ) );
		$this->assertSame( array(), get_option( 'swiftling_service' )['fake'] );
		$this->assertSame( 'unchecked', Services::get( 'fake' )->state() );
	}

	/**
	 * Only people who manage the site change the service, and only a known one.
	 */
	public function test_screen_needs_rights() {
		$this->become( 'editor' );
		$this->post_form( 'swiftling_save_service_key', array( 'service' => 'google' ) );
		try {
			swiftling_save_service_key();
			$this->fail( 'An editor changed the key.' );
		} catch ( WPDieException $refused ) {
			$this->assertNull( Services::setting( 'google', 'key' ) );
		}

		$this->become( 'administrator' );
		$this->post_form( 'swiftling_save_service_key', array( 'service' => 'deepl' ) );
		$this->expectException( WPDieException::class );
		swiftling_save_service_key();
	}

	/**
	 * Connects Google too, as if its key had been checked.
	 */
	private function connect_google() {
		$google = new Google();
		Services::update(
			'google',
			array(
				'key'         => 'google-key',
				'state'       => 'working',
				'fingerprint' => $google->fingerprint( 'google-key' ),
				'languages'   => array( 'hr', 'de', 'fr' ),
			)
		);
	}

	/**
	 * With several services connected, the one chosen translates; until one is chosen, or when the
	 * chosen one is no longer connected, the first connected one does.
	 */
	public function test_choosing_the_service() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->connect_google();
		$this->assertSame( 'fake', Services::current()->id(), 'The first connected one' );

		$this->become( 'administrator' );
		$this->post_form( 'swiftling_use_service', array( 'service' => 'google' ) );
		$this->assertStringContainsString( 'swiftling_notice=chosen', $this->redirect_of( 'swiftling_use_service' ) );
		$this->assertSame( 'google', Services::current()->id() );
		$this->assertSame( 'google', Services::forTarget( 'post:1', array( 'locale' => 'de_DE' ) )->id() );

		Services::update( 'google', array( 'state' => 'failed' ) );
		$this->assertSame( 'fake', Services::current()->id(), 'The chosen one stopped working' );

		Services::update( 'google', array( 'key' => null ) );
		$this->post_form( 'swiftling_use_service', array( 'service' => 'google' ) );
		$this->assertStringContainsString( 'swiftling_notice=not_connected', $this->redirect_of( 'swiftling_use_service' ) );
	}

	/**
	 * The screen says on top which service translates, with its characters of the month, and lists
	 * every service in a card of its kind: the one in use marked Translates and open, another
	 * connected one with a button to use it, one not connected with its key field.
	 */
	public function test_screen_groups_the_services() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		Services::addUsage( 'fake', 1234 );
		$this->become( 'administrator' );
		$_GET = array();

		$html = $this->render_screen( 'service' );
		$this->assertMatchesRegularExpression( '~class="swiftling-service-strip".*?Fake translates.*?1,234 characters this month~s', $html );
		$this->assertMatchesRegularExpression( '~<h2[^>]*>Machine translation</h2>.*?Fake Translate.*?Google Cloud Translation~s', $html, 'Both in one card' );
		$this->assertStringNotContainsString( 'AI translation', $html, 'No card for a kind without services' );
		$this->assertMatchesRegularExpression( '~id="swiftling-service-fake".*?swiftling-service-row__use is-current.*?Translates~s', $html );
		$this->assertMatchesRegularExpression( '~<details class="swiftling-service-row__more" open>\s*<summary>Key and usage</summary>~', $html, 'The one in use is open' );
		$this->assertMatchesRegularExpression( '~id="swiftling-service-google".*?<details class="swiftling-service-row__more"\s*>.*?name="service" value="google">.*?name="key"~s', $html, 'Google closed, with its key field' );

		$this->connect_google();
		$html = $this->render_screen( 'service' );
		$this->assertMatchesRegularExpression( '~name="action" value="swiftling_use_service">\s*<input type="hidden" name="service" value="google">.*?Use Google~s', $html );
	}

	/**
	 * A service of another kind, as an AI service of SwiftLing Pro, gets a card of its own.
	 */
	public function test_ai_services_have_their_card() {
		add_filter(
			'swiftling_translation_services',
			static function ( $services ) {
				$services['ai'] = new class() extends FakeService {
					/**
					 * ID.
					 */
					public function id(): string {
						return 'ai';
					}

					/**
					 * Full name.
					 */
					public function fullName(): string {
						return 'Thinking Translate';
					}

					/**
					 * An AI service.
					 */
					public function kind(): string {
						return 'ai';
					}
				};
				return $services;
			}
		);
		self::reset_static( Services::class, 'services' );
		$this->become( 'administrator' );
		$_GET = array();
		$this->assertMatchesRegularExpression( '~<h2[^>]*>AI translation</h2>.*?Thinking Translate~s', $this->render_screen( 'service' ) );
		$this->assertSame( 'machine', ( new Google() )->kind() );
	}

	/**
	 * Without a service connected, the strip says so and every service is open with its key field.
	 */
	public function test_screen_without_a_service() {
		$this->become( 'administrator' );
		$_GET = array();
		$html = $this->render_screen( 'service' );
		$this->assertStringContainsString( 'No service translates yet', $html );
		$this->assertMatchesRegularExpression( '~id="swiftling-service-google".*?<details class="swiftling-service-row__more" open>\s*<summary>Connect with a key</summary>~s', $html );
	}

	/**
	 * A service gives its own guide to getting a key, which the screen shows while it is not
	 * connected.
	 */
	public function test_guide_comes_from_the_service() {
		$guide = ( new Google() )->guide();
		$this->assertSame( 'How to get a Google key', $guide['title'] );
		$this->assertNotEmpty( $guide['steps'] );

		$this->become( 'administrator' );
		$_GET = array();
		$this->assertStringContainsString( 'How to get a Google key', $this->render_screen( 'service' ) );
	}

	/**
	 * Connects the fake service as one that reports its own usage of the billing period.
	 *
	 * @param array|null $quota What the service reports: used and limit.
	 */
	private function connect_counting( $quota ) {
		FakeService::connect();
		add_filter(
			'swiftling_translation_services',
			static function ( $services ) use ( $quota ) {
				$services['fake'] = new class( $quota ) extends FakeService {
					/**
					 * What the service reports.
					 *
					 * @var array|null
					 */
					private $quota;

					/**
					 * The service, with what it reports.
					 *
					 * @param array|null $quota Used and limit.
					 */
					public function __construct( $quota ) {
						$this->quota = $quota;
					}

					/**
					 * Usage of the billing period, as the service counts it.
					 */
					public function quota(): ?array {
						return $this->quota;
					}
				};
				return $services;
			},
			20
		);
		self::reset_static( Services::class, 'services' );
	}

	/**
	 * A service that reports its own usage shows it as it counts it, for its billing period, with
	 * the bar on those numbers; SwiftLing's count of the month stays apart, and nothing is called
	 * free of charge.
	 */
	public function test_usage_as_the_service_counts_it() {
		$this->connect_counting(
			array(
				'used'  => 12000,
				'limit' => 500000,
			)
		);
		Services::addUsage( 'fake', 343 );
		$this->become( 'administrator' );
		$_GET = array();
		$html = $this->render_screen( 'service' );
		$this->assertStringContainsString( 'Fake counts 12,000 of the 500,000 characters of your plan in this billing period.', $html );
		$this->assertStringContainsString( 'aria-valuenow="12000"', $html );
		$this->assertMatchesRegularExpression( '~<strong>343</strong>\s*characters sent to Fake~', $html );
		$row = substr( $html, (int) strpos( $html, 'id="swiftling-service-fake"' ), (int) strpos( $html, 'id="swiftling-service-google"' ) - (int) strpos( $html, 'id="swiftling-service-fake"' ) );
		$this->assertStringNotContainsString( 'free of charge', $row, 'Google, not connected, may still say what it gives free' );

		$this->connect_counting(
			array(
				'used'  => 12000,
				'limit' => 0,
			)
		);
		$html = $this->render_screen( 'service' );
		$this->assertStringContainsString( 'Fake counts 12,000 characters in this billing period.', $html, 'A plan without a limit' );
		$this->assertStringNotContainsString( 'role="progressbar"', $html );
	}

	/**
	 * Services show an icon of their kind, drawn by SwiftLing, never a logo of the service.
	 */
	public function test_icons_of_the_kinds() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->become( 'administrator' );
		$_GET = array();
		$html = $this->render_screen( 'service' );
		$this->assertMatchesRegularExpression( '~id="swiftling-service-google">.*?<span class="swiftling-service__icon is-machine is-[a-z]+" aria-hidden="true"><svg~s', $html );
		$this->assertMatchesRegularExpression( '~class="swiftling-service-strip">\s*<span class="swiftling-service__icon is-machine is-[a-z]+"~', $html );
		$this->assertStringNotContainsString( 'swiftling-service__logo', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	/**
	 * Connects the fake service as a language model with models to choose from.
	 */
	private function connect_model_service() {
		FakeService::connect();
		FakeService::$models = array(
			'small'  => 'Fake Small',
			'medium' => 'Fake Medium',
		);
		add_filter(
			'swiftling_translation_services',
			static function ( $services ) {
				$services['fake'] = new class() extends FakeService {
					/**
					 * A language model.
					 */
					public function kind(): string {
						return 'ai';
					}

					/**
					 * The model until another is chosen.
					 */
					public function defaultModel(): string {
						return 'medium';
					}
				};
				return $services;
			},
			20
		);
		self::reset_static( Services::class, 'services' );
	}

	/**
	 * A key's check keeps the models it may use; the service translates with the one chosen, the
	 * default until then, and only a model the key may use can be chosen. Removing the key forgets
	 * its models.
	 */
	public function test_models() {
		$this->connect_model_service();
		$this->become( 'administrator' );
		$fake = Services::get( 'fake' );
		$this->assertSame( array(), $fake->models(), 'Not checked yet' );
		$this->assertSame( '', $fake->model() );

		$this->post_form(
			'swiftling_save_service_key',
			array(
				'service' => 'fake',
				'key'     => 'fake-key',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=working', $this->redirect_of( 'swiftling_save_service_key' ) );
		$this->assertSame( FakeService::$models, $fake->models() );
		$this->assertSame( 'medium', $fake->model() );

		$this->post_form(
			'swiftling_save_service_model',
			array(
				'service' => 'fake',
				'model'   => 'small',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=model', $this->redirect_of( 'swiftling_save_service_model' ) );
		$this->assertSame( 'small', $fake->model() );

		$this->post_form(
			'swiftling_save_service_model',
			array(
				'service' => 'fake',
				'model'   => 'huge',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=model_unknown', $this->redirect_of( 'swiftling_save_service_model' ) );
		$this->assertSame( 'small', $fake->model() );

		// A later check that no longer lists the chosen model falls back to the default.
		Services::update( 'fake', array( 'models' => array( 'medium' => 'Fake Medium' ) ) );
		$this->assertSame( 'medium', $fake->model() );
		Services::update( 'fake', array( 'models' => array( 'other' => 'Other' ) ) );
		$this->assertSame( 'other', $fake->model(), 'Without the default, the first one' );

		$this->post_form( 'swiftling_remove_service_key', array( 'service' => 'fake' ) );
		$this->redirect_of( 'swiftling_remove_service_key' );
		$this->assertSame( array(), $fake->models() );
	}

	/**
	 * A language model's row offers its models, with the one in use chosen and the default named,
	 * and counts its tokens; the strip on top shows its tokens of the month.
	 */
	public function test_screen_shows_models_and_tokens() {
		$this->connect_model_service();
		Services::update(
			'fake',
			array(
				'models' => FakeService::$models,
				'model'  => 'small',
			)
		);
		Services::addUsage( 'fake', 500, 1234 );
		$this->become( 'administrator' );
		$_GET = array();
		$html = $this->render_screen( 'service' );

		$this->assertMatchesRegularExpression( '~class="swiftling-service-strip".*?Fake translates.*?1,234 tokens this month~s', $html );
		$this->assertMatchesRegularExpression( '~name="action" value="swiftling_save_service_model">\s*<input type="hidden" name="service" value="fake">.*?<select[^>]*name="model">\s*<option value="small"\s+selected=\'selected\'>Fake Small</option>\s*<option value="medium"\s*>Fake Medium \(default\)</option>~s', $html );
		$this->assertMatchesRegularExpression( '~<strong>1,234</strong>\s*tokens read and written by Fake~', $html );
		$this->assertMatchesRegularExpression( '~<strong>500</strong>\s*characters sent to Fake~', $html );

		$this->post_form(
			'swiftling_save_service_model',
			array(
				'service' => 'google',
				'model'   => 'small',
			)
		);
		$this->assertStringContainsString( 'swiftling_notice=model_unknown', $this->redirect_of( 'swiftling_save_service_model' ), 'A service without models has none to choose' );
	}

	/**
	 * Google has no models to choose, so its row has no model field and counts no tokens.
	 */
	public function test_machine_service_has_no_model_field() {
		$this->connect_google();
		Services::addUsage( 'google', 50 );
		$this->become( 'administrator' );
		$_GET = array();
		$html = $this->render_screen( 'service' );
		$this->assertStringNotContainsString( 'swiftling_save_service_model', $html );
		$this->assertStringNotContainsString( 'tokens', $html );
		$this->assertMatchesRegularExpression( '~50 characters this month~', $html );
	}
}
