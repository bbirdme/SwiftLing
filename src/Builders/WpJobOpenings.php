<?php
/**
 * WP Job Openings: what of a job opening SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * WP Job Openings. A job opening is a post of the type "awsm_job_openings", its specifications terms:
 * SwiftLing finds them as on any post. What it does not find by itself:
 *
 * - The names of the specifications, as "Job Category", the message when there are no openings, the
 *   consent text of the application form and the email to an applicant are values of the plugin's
 *   settings: declared as text and offered on the Templates screen, see textOptions(). An email's
 *   text is one text with the plugin's placeholders, as {applicant}, which machine translation keeps,
 *   see keepPlaceholders().
 * - The application form, the filters and "Load more" send to admin-ajax.php from the page: answered
 *   in the language of that page, see sendingActions(), so the applicant reads the answer and the email
 *   in it. The email to the site's owner about the application is written in the original, see
 *   startOwnerEmail().
 * - An opening gets no separate copy: its applications are kept by its ID. It is translated in place,
 *   see noCopyTypes().
 *
 * Checked against the code of WP Job Openings 4.2 (the free plugin), in tests/JobBoardsTest.php, and by
 * hand on a site with the plugin. Its Pro add-on, with forms of one's own, was not checked.
 */
class WpJobOpenings extends SettingsTexts {
	/**
	 * The plugin's type of post.
	 */
	const POST_TYPE = 'awsm_job_openings';

	/**
	 * The option with the plugin's specifications, each with a name.
	 */
	const SPECS = 'awsm_jobs_filter';

	/**
	 * A placeholder of the plugin's emails.
	 */
	const PLACEHOLDER = '/\{[a-z][a-z0-9-]*\}/';

	/**
	 * The meta of an application with the title of the opening it is for.
	 */
	const APPLIED_FOR = 'awsm_apply_for';

	/**
	 * The plugin's type of post of an application.
	 */
	const APPLICATION = 'awsm_job_application';

	/**
	 * Where an application keeps the language it was sent in.
	 */
	const LANGUAGE_META = 'swiftling_language';

	/**
	 * The actions of admin-ajax.php the plugin sends to from a page.
	 */
	const ACTIONS = array( 'awsm_applicant_form_submission', 'jobfilter', 'loadmore', 'block_jobfilter', 'block_loadmore' );

	/**
	 * Whether the plugin is writing the emails about an application, and whether this class put the
	 * request in the original for the one to the site's owner.
	 *
	 * @var array{notifying: bool, paused: bool}
	 */
	private $emails = array(
		'notifying' => false,
		'paused'    => false,
	);

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'wp-job-openings';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'WP Job Openings';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'AWSM_JOBS_PLUGIN_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin: an opening needs nothing of a builder.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- An opening needs nothing of a builder.
		return false;
	}

	/**
	 * Returns the plugin's openings: translated in place.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( self::POST_TYPE );
	}

	/**
	 * Returns the actions of admin-ajax.php the plugin sends to from a page: the application form, its
	 * filters and its "Load more".
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return self::ACTIONS;
	}

	/**
	 * Returns the values of the plugin's settings that hold text, by option name. The names of the
	 * specifications are declared by fieldHoldsText().
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array(
			self::SPECS                         => array(),
			'awsm_default_msg'                  => array( '' ),
			'awsm_gdpr_cb_text'                 => array( '' ),
			'awsm_jobs_notification_subject'    => array( '' ),
			'awsm_jobs_notification_content'    => array( '' ),
			'awsm_jobs_notification_customizer' => array( 'footer_text' ),
		);
	}

	/**
	 * Returns what the editor calls each text of the plugin's settings.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array(
			'awsm_default_msg'                  => array( '' => __( 'Message when there are no job openings', 'swiftling' ) ),
			'awsm_gdpr_cb_text'                 => array( '' => __( 'Consent text of the application form', 'swiftling' ) ),
			'awsm_jobs_notification_subject'    => array( '' => __( 'Email to the applicant › Subject', 'swiftling' ) ),
			'awsm_jobs_notification_content'    => array( '' => __( 'Email to the applicant › Text', 'swiftling' ) ),
			'awsm_jobs_notification_customizer' => array( 'footer_text' => __( 'Email to the applicant › Footer', 'swiftling' ) ),
		);
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		return __( 'The names of the job specifications, the message when there are no openings, the consent text of the application form and the email to an applicant, which goes out in the language of the page the applicant applied on. Keep every placeholder, such as {applicant}: the plugin fills it in.', 'swiftling' );
	}

	/**
	 * Declares the name of each specification as text, and its other values as none, beside the texts of
	 * the plugin's other settings.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of no option of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		$specs = 'option/' . swiftling_escape_segment( self::SPECS );
		if ( 'options' === $owner && ( $specs === $path || 0 === strpos( $path, $specs . '/' ) ) ) {
			return 1 === preg_match( '#^' . preg_quote( $specs, '#' ) . '/\d+/filter$#', $path );
		}
		return parent::fieldHoldsText( $owner, $path );
	}

	/**
	 * Returns what the editor calls a text of the plugin's settings: a specification's name by itself.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 'options' === $owner && 1 === preg_match( '#^option/' . preg_quote( swiftling_escape_segment( self::SPECS ), '#' ) . '/\d+/filter$#', $path ) ) {
			return __( 'Name of a job specification', 'swiftling' );
		}
		return parent::fieldLabel( $owner, $path );
	}

	/**
	 * Whether a value is one text as a whole: the text of the email to an applicant, with the plugin's
	 * placeholders in it.
	 *
	 * @param string $path Path of the value.
	 */
	public function isWhole( string $path ): bool {
		return 'option/awsm_jobs_notification_content' === $path;
	}

	/**
	 * Hooks where the plugin writes the emails about an application.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		add_filter( 'awsm_jobs_default_notifications_types', array( $this, 'startEmails' ), PHP_INT_MAX, 1 );
		// The first setting the plugin reads for the email to the site's owner.
		add_filter( 'pre_option_awsm_jobs_enable_admin_notification', array( $this, 'startOwnerEmail' ), 1, 1 );
		add_filter( 'awsm_jobs_mail_template_tags', array( $this, 'ownerTags' ), PHP_INT_MAX, 2 );
		add_action( 'awsm_job_application_submitted', array( $this, 'endEmails' ), 0, 0 );
		add_action( 'awsm_job_application_submitted', array( $this, 'keepLanguage' ), 10, 1 );
		// phpcs:enable
		add_filter( 'sanitize_post_meta_' . self::APPLIED_FOR, array( $this, 'originalTitle' ), 10, 3 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Notes that the plugin starts writing the emails about an application: the applicant's first, in
	 * the language of the request, then the site's owner's.
	 *
	 * @param mixed $types The emails.
	 * @return mixed
	 */
	public function startEmails( $types ) {
		$this->emails['notifying'] = true;
		return $types;
	}

	/**
	 * Before the plugin writes the email to the site's owner about an application: the request is put in
	 * the original until endEmails().
	 *
	 * @param mixed $value The setting, before WordPress reads it.
	 * @return mixed
	 */
	public function startOwnerEmail( $value ) {
		if ( $this->emails['notifying'] && ! $this->emails['paused'] && '' !== swiftling_writing_language() ) {
			swiftling_pause_translation();
			$this->emails['paused'] = true;
		}
		return $value;
	}

	/**
	 * Returns the placeholders of the email to the site's owner with the opening's title in the
	 * original: the plugin worked it out once, in the language of the page, before its emails.
	 *
	 * @param mixed $tags              The placeholders and their values.
	 * @param mixed $applicant_details The application, with the opening as "awsm_job_id".
	 * @return mixed
	 */
	public function ownerTags( $tags, $applicant_details = array() ) {
		if ( ! $this->emails['paused'] || ! is_array( $tags ) || ! isset( $tags['{job-title}'] ) || empty( $applicant_details['awsm_job_id'] ) ) {
			return $tags;
		}
		$tags['{job-title}'] = esc_html( (string) get_post_field( 'post_title', (int) $applicant_details['awsm_job_id'], 'raw' ) );
		return $tags;
	}

	/**
	 * Returns the title an application keeps of its opening in the original: the plugin works it out in
	 * the language of the page, and the site's owner reads the application in the admin.
	 *
	 * @param mixed  $value    The title, as the plugin worked it out.
	 * @param string $key      Meta key.
	 * @param string $type     Object type.
	 * @return mixed
	 */
	public function originalTitle( $value, $key = '', $type = '' ) {
		unset( $key );
		if ( 'post' !== $type || '' === swiftling_writing_language() || ! wp_doing_ajax() || empty( $_POST['awsm_job_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The plugin checked its nonce; only the opening's ID is read.
			return $value;
		}
		$job = (int) $_POST['awsm_job_id']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		return self::POST_TYPE === get_post_type( $job ) ? esc_html( (string) get_post_field( 'post_title', $job, 'raw' ) ) : $value;
	}

	/**
	 * Keeps the language of the page an application was sent from.
	 *
	 * @param mixed $application_id The application.
	 */
	public function keepLanguage( $application_id ): void {
		$slug = swiftling_writing_language();
		if ( '' !== $slug && is_numeric( $application_id ) && self::APPLICATION === get_post_type( (int) $application_id ) ) {
			update_post_meta( (int) $application_id, self::LANGUAGE_META, $slug );
		}
	}

	/**
	 * After the plugin wrote the emails about an application: the request goes on in its own language.
	 */
	public function endEmails(): void {
		if ( $this->emails['paused'] ) {
			swiftling_resume_translation();
		}
		$this->emails = array(
			'notifying' => false,
			'paused'    => false,
		);
	}

	/**
	 * Keeps the placeholders of the plugin's emails as they are when a text goes to machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, self::PLACEHOLDER );
	}
}
