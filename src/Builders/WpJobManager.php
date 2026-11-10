<?php
/**
 * WP Job Manager: what of a job listing SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * WP Job Manager. A job listing is a post of the type "job_listing", its company, location and other
 * fields its meta, read through get_post_meta(), and its types and categories terms: SwiftLing finds
 * them as on any post. What it does not find by itself:
 *
 * - The plugin prints a job's description from the post's content itself, past the_content, and
 *   hands it to its own filter, the_job_description: translated there, see translateDescription().
 *   The same description goes into the job's structured data for search engines.
 * - The plugin leaves its listings out of menus, so they would not count as pages visitors open:
 *   this class names them, see visitorTypes(), and they are listed, counted and translated as pages.
 *   A listing gets no separate copy: a copy would expire and send its own emails. It is translated in
 *   place, see noCopyTypes().
 * - A listing keeps the language of the page it was posted or edited on, see keepLanguage(). The
 *   notice to its employer that it expires is written in that language, those to the site's owner in
 *   the original, see startEmail().
 *
 * Checked against the code of WP Job Manager 2.4 (the free plugin), in tests/JobBoardsTest.php, and by
 * hand on a site with the plugin. Its paid add-ons, as applications and job alerts, were not checked.
 */
class WpJobManager extends Builder {
	/**
	 * The plugin's type of post.
	 */
	const POST_TYPE = 'job_listing';

	/**
	 * Where a listing keeps the language it was posted in.
	 */
	const LANGUAGE_META = 'swiftling_language';

	/**
	 * The plugin's emails, by their key: true for those to an employer, false for those to the site's
	 * owner.
	 */
	const EMAILS = array(
		'admin_new_job'         => false,
		'admin_updated_job'     => false,
		'admin_expiring_job'    => false,
		'employer_expiring_job' => true,
	);

	/**
	 * The emails being written: how many recipients each still has, and whether it changed the language.
	 *
	 * @var array<int, array{left: int, changed: bool}>
	 */
	private $writing = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wp-job-manager';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'WP Job Manager';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'JOB_MANAGER_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin: a listing needs nothing of a builder.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A listing needs nothing of a builder.
		return false;
	}

	/**
	 * Returns the plugin's listings: pages visitors open, which the plugin leaves out of menus.
	 *
	 * @return string[]
	 */
	public function visitorTypes(): array {
		return array( self::POST_TYPE );
	}

	/**
	 * Returns the plugin's listings: translated in place.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( self::POST_TYPE );
	}

	/**
	 * Hooks where the plugin prints a description, saves a listing and writes an email.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		// Before the plugin's own filters, which add its markup.
		add_filter( 'the_job_description', array( $this, 'translateDescription' ), 0, 2 );
		add_action( 'job_manager_update_job_data', array( $this, 'keepLanguage' ), 10, 1 );
		add_action( 'job_manager_user_edit_job_listing', array( $this, 'keepLanguage' ), 10, 1 );
		foreach ( array_keys( self::EMAILS ) as $key ) {
			// The first part of an email the plugin works out; the others follow in its language.
			add_filter( 'job_manager_email_' . $key . '_to', array( $this, 'startEmail' ), PHP_INT_MAX, 2 );
		}
		add_filter( 'job_manager_email_do_send_notification', array( $this, 'endEmail' ), PHP_INT_MAX, 1 );
		// phpcs:enable
	}

	/**
	 * Returns a job's description in the mode of the moment.
	 *
	 * @param mixed $description The description, as the plugin read it from the post.
	 * @param mixed $post        The listing.
	 * @return mixed
	 */
	public function translateDescription( $description, $post = null ) {
		if ( ! is_string( $description ) || '' === trim( $description ) || ! $post instanceof \WP_Post || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $description;
		}
		return swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $description );
	}

	/**
	 * Keeps the language of the page a listing was posted or edited on; one in the original keeps none.
	 *
	 * @param mixed $job_id The listing.
	 */
	public function keepLanguage( $job_id ): void {
		if ( ! is_numeric( $job_id ) || self::POST_TYPE !== get_post_type( (int) $job_id ) ) {
			return;
		}
		$slug = swiftling_writing_language();
		if ( '' === $slug ) {
			delete_post_meta( (int) $job_id, self::LANGUAGE_META );
		} else {
			update_post_meta( (int) $job_id, self::LANGUAGE_META, $slug );
		}
	}

	/**
	 * Before the plugin writes an email: one to the site's owner in the original, one to an employer in
	 * the language of the listing. Until endEmail(), once for every recipient.
	 *
	 * @param mixed $to    The recipients.
	 * @param mixed $email The plugin's email.
	 * @return mixed
	 */
	public function startEmail( $to, $email = null ) {
		$key   = is_object( $email ) && method_exists( $email, 'get_key' ) ? (string) $email::get_key() : '';
		$slug  = ! empty( self::EMAILS[ $key ] ) ? self::jobLanguage( $email ) : '';
		$left  = is_array( $to ) ? count( $to ) : count( array_filter( preg_split( '/[,|;]\s?/', (string) $to ) ) );
		$entry = array(
			'left'    => max( 1, $left ),
			'changed' => false,
		);
		if ( swiftling_writing_language() !== $slug && ( '' === $slug || null !== swiftling_target_language( $slug ) ) ) {
			swiftling_write_in( $slug );
			$entry['changed'] = true;
		}
		if ( '' !== $slug ) {
			self::translateJob( $email );
		}
		$this->writing[] = $entry;
		return $to;
	}

	/**
	 * After the plugin wrote an email for a recipient: once it wrote it for every one, the request goes
	 * on in its own language.
	 *
	 * @param mixed $send Whether the plugin sends it.
	 * @return mixed
	 */
	public function endEmail( $send ) {
		$last = count( $this->writing ) - 1;
		if ( $last < 0 ) {
			return $send;
		}
		--$this->writing[ $last ]['left'];
		if ( $this->writing[ $last ]['left'] <= 0 ) {
			$entry = array_pop( $this->writing );
			if ( $entry['changed'] ) {
				swiftling_resume_translation();
			}
		}
		return $send;
	}

	/**
	 * Returns the language of the listing an email is about, or an empty string for the original.
	 *
	 * @param mixed $email The plugin's email.
	 */
	private static function jobLanguage( $email ): string {
		$job  = self::job( $email );
		$slug = $job ? (string) get_post_meta( $job->ID, self::LANGUAGE_META, true ) : '';
		return null !== swiftling_target_language( $slug ) ? $slug : '';
	}

	/**
	 * Returns the listing an email is about.
	 *
	 * @param mixed $email The plugin's email.
	 * @return \WP_Post|null
	 */
	private static function job( $email ): ?\WP_Post {
		$args = self::emailArgs( $email );
		return isset( $args['job'] ) && $args['job'] instanceof \WP_Post ? $args['job'] : null;
	}

	/**
	 * Returns what an email is about, as the plugin keeps it: private to its base class, which hands it
	 * to its own emails only.
	 *
	 * @param mixed $email The plugin's email.
	 */
	private static function emailArgs( $email ): array {
		if ( ! $email instanceof \WP_Job_Manager_Email ) {
			return array();
		}
		$property = new \ReflectionProperty( \WP_Job_Manager_Email::class, 'args' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		return (array) $property->getValue( $email );
	}

	/**
	 * Puts the title of the listing an email is about in the language it is written in: the plugin
	 * prints it from the listing's post, past get_the_title(). Only the email's own copy of the post
	 * changes.
	 *
	 * @param mixed $email The plugin's email.
	 */
	private static function translateJob( $email ): void {
		$job = self::job( $email );
		if ( $job ) {
			$job->post_title = (string) swiftling_filter_title( $job->post_title, $job->ID );
		}
	}
}
