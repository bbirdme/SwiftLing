<?php
/**
 * LatePoint: its booking form and emails in the language of the visitor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * LatePoint. Its booking form is loaded step by step through admin-ajax.php, from models of its own
 * tables: services, categories, agents, locations and bundles, and the texts of its steps, kept in its
 * own table of settings. Its emails are templates of its processes, written while a booking is made and
 * sent later. What SwiftLing does not find by itself:
 *
 * - The texts of those models are "latepoint:<type>:<ID>" with paths such as "latepoint/name", and the
 *   texts of the steps "latepoint:steps" with paths such as "latepoint/booking__services/main_panel_heading".
 *   They are translated where the plugin loads a model, see translateModel(), in a request in a
 *   language: the form's requests are answered in the language of the page, see sendingActions(). The
 *   plugin saves none of these models from its form.
 * - The language of a booking is kept with it, see keepLanguage(). An email to the customer is written
 *   from the translation of its template in that language, also when the plugin writes it later, as
 *   for a reminder; an email to an agent or the site's staff from the original template, in the
 *   original, see writeEmail(). Only the templates of emails to the customer are offered.
 *
 * Checked against the code of LatePoint 5.7 (the free plugin), in tests/LatePointTest.php, and by hand on
 * a site with the plugin.
 */
class LatePoint extends SettingsTexts {
	/**
	 * Where the texts of a model are in its map, and the prefix of its object key.
	 */
	const BASE = 'latepoint';

	/**
	 * The plugin's models with texts, by class: their type and the fields a visitor reads.
	 */
	const MODELS = array(
		'OsServiceModel'          => array( 'service', array( 'name', 'short_description', 'duration_name' ) ),
		'OsServiceCategoryModel'  => array( 'category', array( 'name', 'short_description' ) ),
		'OsAgentModel'            => array( 'agent', array( 'title', 'bio' ) ),
		'OsLocationModel'         => array( 'location', array( 'name', 'full_address' ) ),
		'OsLocationCategoryModel' => array( 'location-category', array( 'name', 'short_description' ) ),
		'OsBundleModel'           => array( 'bundle', array( 'name', 'short_description' ) ),
	);

	/**
	 * The plugin's tables of those models, by type, without prefix.
	 */
	const TABLES = array(
		'service'           => 'latepoint_services',
		'category'          => 'latepoint_service_categories',
		'agent'             => 'latepoint_agents',
		'location'          => 'latepoint_locations',
		'location-category' => 'latepoint_location_categories',
		'bundle'            => 'latepoint_bundles',
	);

	/**
	 * The meta of a booking SwiftLing keeps its language in.
	 */
	const LANGUAGE_META = 'swiftling_language';

	/**
	 * The originals of the texts translated in the request, by translation, to put an email to the staff
	 * back in the original.
	 *
	 * @var array<string, string>
	 */
	private $originals = array();

	/**
	 * The templates of the plugin's emails, by template: process, action, field and whether it goes to
	 * the customer. Read once a request.
	 *
	 * @var array<string, array{process: int, action: string, field: string, customer: bool}>|null
	 */
	private $templates = null;

	/**
	 * Whether SwiftLing writes an email itself now, so the plugin's filter it runs is left alone.
	 *
	 * @var bool
	 */
	private $writing = false;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'latepoint';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'LatePoint';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'LATEPOINT_VERSION' ) || class_exists( 'LatePoint', false );
	}

	/**
	 * Whether a post is built with the plugin: none is.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The plugin builds no posts.
		return false;
	}

	/**
	 * Hooks where the plugin loads a model, makes a booking and writes an email.
	 */
	public function register(): void {
		add_filter( 'latepoint_get_results_as_models', array( $this, 'translateModel' ), 100 );
		add_filter( 'latepoint_model_loaded_by_id', array( $this, 'translateModel' ), 100 );
		add_action( 'latepoint_booking_created', array( $this, 'keepLanguage' ), 10, 1 );
		add_filter( 'latepoint_replace_all_vars_in_template', array( $this, 'writeEmail' ), 100, 3 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns the action of admin-ajax.php the plugin's booking form loads its steps and sends a booking
	 * through: answered in the language of the page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'latepoint_route_call' );
	}

	/**
	 * Returns a model the plugin loaded with its texts in the mode of the moment: a model of the form,
	 * or the plugin's settings of its steps.
	 *
	 * @param mixed $model The model.
	 * @return mixed
	 */
	public function translateModel( $model ) {
		if ( ! is_object( $model ) || ! self::inMode() ) {
			return $model;
		}
		$class = get_class( $model );
		if ( 'OsSettingsModel' === $class ) {
			return $this->translateSteps( $model );
		}
		if ( ! isset( self::MODELS[ $class ] ) || empty( $model->id ) ) {
			return $model;
		}
		list( $type, $fields ) = self::MODELS[ $class ];
		foreach ( $fields as $field ) {
			if ( isset( $model->$field ) && is_string( $model->$field ) && '' !== trim( $model->$field ) ) {
				$original      = $model->$field;
				$model->$field = swiftling_transform_text( self::owner( $type, (int) $model->id ), self::BASE . '/' . $field, self::BASE . ':' . $type, $original );
				if ( is_string( $model->$field ) && $model->$field !== $original ) {
					$this->originals[ $model->$field ] = $original;
				}
			}
		}
		return $model;
	}

	/**
	 * Returns the plugin's settings of its steps with their texts in the mode of the moment.
	 *
	 * @param object $model The plugin's model of one setting.
	 * @return object
	 */
	private function translateSteps( $model ) {
		if ( ! isset( $model->name, $model->value ) || ! is_string( $model->value ) ) {
			return $model;
		}
		// The text beside every step, kept also on its own: the form shows this one.
		if ( 'steps_support_text' === $model->name ) {
			$model->value = $this->stepText( 'shared', 'steps_support_text', $model->value );
			return $model;
		}
		if ( 'steps_settings' !== $model->name ) {
			return $model;
		}
		$steps = maybe_unserialize( $model->value );
		if ( ! is_array( $steps ) ) {
			return $model;
		}
		foreach ( $steps as $step => $settings ) {
			if ( ! is_array( $settings ) ) {
				continue;
			}
			foreach ( $settings as $key => $text ) {
				if ( is_string( $text ) && '' !== trim( $text ) && ! is_numeric( $text ) && ! in_array( $text, array( 'on', 'off' ), true ) ) {
					$steps[ $step ][ $key ] = $this->stepText( (string) $step, (string) $key, $text );
				}
			}
		}
		$model->value = maybe_serialize( $steps );
		return $model;
	}

	/**
	 * Returns a text of the plugin's steps in the mode of the moment.
	 *
	 * @param string $step The step's code, or "shared".
	 * @param string $key  The setting.
	 * @param string $text The text, as stored.
	 */
	private function stepText( string $step, string $key, string $text ): string {
		$translated = swiftling_transform_text( self::BASE . ':steps', self::BASE . '/' . swiftling_escape_segment( $step ) . '/' . swiftling_escape_segment( $key ), self::BASE . ':steps', $text );
		return is_string( $translated ) ? $translated : $text;
	}

	/**
	 * Returns a template of an email to the customer in the mode of the moment: the subject whole, the
	 * message text by text, its HTML as it is.
	 *
	 * @param array  $email    The template: process, action and field.
	 * @param string $template The template, as stored.
	 */
	private function template( array $email, string $template ): string {
		$owner = self::BASE . ':process:' . $email['process'];
		$path  = self::BASE . '/' . swiftling_escape_segment( $email['action'] ) . '/' . $email['field'];
		if ( 'content' !== $email['field'] ) {
			$translated = swiftling_transform_text( $owner, $path, self::BASE . ':process', $template );
			return is_string( $translated ) ? $translated : $template;
		}
		return HtmlTexts::translate(
			$template,
			static function ( $text, $key ) use ( $owner, $path ) {
				return swiftling_transform_text( $owner, $path . '/' . $key, self::BASE . ':process', $text );
			}
		);
	}

	/**
	 * Returns the object key of a model of the plugin.
	 *
	 * @param string $type The model's type.
	 * @param int    $id   The model's ID.
	 */
	private static function owner( string $type, int $id ): string {
		return self::BASE . ':' . $type . ':' . $id;
	}

	/**
	 * Keeps the language of the page a booking was made from with the booking.
	 *
	 * @param mixed $booking The plugin's booking.
	 */
	public function keepLanguage( $booking ): void {
		$slug = self::pageSlug();
		if ( '' !== $slug && is_object( $booking ) && method_exists( $booking, 'save_meta_by_key' ) ) {
			$booking->save_meta_by_key( self::LANGUAGE_META, $slug );
		}
	}

	/**
	 * Returns the address slug of the language the request runs in, or an empty string for the original.
	 */
	private static function pageSlug(): string {
		$state = &swiftling_state();
		return 'translate' === $state['mode'] ? (string) $state['language'] : '';
	}

	/**
	 * Returns a part of an email the plugin wrote from a template of its processes: for the customer, in
	 * the language of the booking, from the template's translation; for an agent or the staff, in the
	 * original. Any other text the plugin writes stays as it is.
	 *
	 * @param mixed $text     The text the plugin wrote.
	 * @param mixed $vars     What the template's placeholders name: the booking, the customer and others.
	 * @param mixed $original The template.
	 * @return mixed
	 */
	public function writeEmail( $text, $vars = array(), $original = '' ) {
		if ( $this->writing || ! is_string( $text ) || ! is_string( $original ) || ! is_array( $vars ) || ! class_exists( 'OsReplacerHelper' ) ) {
			return $text;
		}
		$template = $this->templates()[ $original ] ?? null;
		if ( null === $template ) {
			return $text;
		}
		$slug = $template['customer'] ? $this->bookingLanguage( $vars ) : '';
		if ( ! $template['customer'] && '' === self::pageSlug() ) {
			return $text;
		}
		if ( '' === $slug ) {
			swiftling_pause_translation();
		} else {
			swiftling_switch_translation( $slug );
		}
		$this->writing = true;
		try {
			$written = '' === $slug ? \OsReplacerHelper::replace_all_vars( $original, $vars ) : \OsReplacerHelper::replace_all_vars( $this->template( $template, $original ), $this->translateVars( $vars ) );
		} finally {
			$this->writing = false;
			swiftling_resume_translation();
		}
		// What the request translated before, as the booking's service, back in the original for the staff.
		return '' === $slug ? strtr( $written, $this->originals ) : $written;
	}

	/**
	 * Returns what the placeholders of an email name with the plugin's models in the language of the
	 * moment: copies, translated, also those a booking holds; the plugin may have loaded them before,
	 * in the original.
	 *
	 * @param array $vars What the email's placeholders name.
	 * @return array
	 */
	private function translateVars( array $vars ): array {
		foreach ( $vars as $key => $value ) {
			if ( ! is_object( $value ) ) {
				continue;
			}
			$copy = clone $value;
			if ( isset( self::MODELS[ get_class( $copy ) ] ) ) {
				$copy = $this->translateModel( $copy );
			}
			foreach ( array( 'service', 'agent', 'location', 'bundle' ) as $relation ) {
				if ( isset( $copy->$relation ) && is_object( $copy->$relation ) && isset( self::MODELS[ get_class( $copy->$relation ) ] ) ) {
					$copy->$relation = $this->translateModel( clone $copy->$relation );
				}
			}
			$vars[ $key ] = $copy;
		}
		return $vars;
	}

	/**
	 * Returns the language a booking was made in, for an email about it: the language kept with it, or
	 * that of the request.
	 *
	 * @param array $vars What the email's placeholders name.
	 * @return string Address slug, or an empty string for the original.
	 */
	private function bookingLanguage( array $vars ): string {
		$booking = $vars['booking'] ?? null;
		if ( is_object( $booking ) && method_exists( $booking, 'get_meta_by_key' ) && ! empty( $booking->id ) ) {
			$slug = $booking->get_meta_by_key( self::LANGUAGE_META, '' );
			if ( is_string( $slug ) && '' !== $slug ) {
				return null !== swiftling_target_language( $slug ) ? $slug : '';
			}
		}
		return self::pageSlug();
	}

	/**
	 * Returns the templates of the emails of the plugin's processes, by template.
	 *
	 * @return array<string, array{process: int, action: string, field: string, customer: bool}>
	 */
	protected function templates(): array {
		if ( null !== $this->templates ) {
			return $this->templates;
		}
		global $wpdb;
		$this->templates = array();
		$table           = $wpdb->prefix . 'latepoint_processes';
		if ( ! $this->hasTable( $table ) ) {
			return $this->templates;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once a request.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, actions_json FROM %i', $table ), ARRAY_A ) as $row ) {
			$this->collectTemplates( (int) $row['id'], json_decode( (string) $row['actions_json'], true ) );
		}
		return $this->templates;
	}

	/**
	 * Notes the templates of the emails in a process's actions, which the plugin keeps in groups.
	 *
	 * @param int   $process The process's ID.
	 * @param mixed $node    A part of the process's actions.
	 */
	private function collectTemplates( int $process, $node ): void {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['id'], $node['settings']['type'], $node['settings']['settings'] ) && 'send_email' === $node['settings']['type'] && is_array( $node['settings']['settings'] ) ) {
			$email    = $node['settings']['settings'];
			$customer = isset( $email['to_email'] ) && is_string( $email['to_email'] ) && false !== strpos( $email['to_email'], '{{customer_' );
			foreach ( array( 'subject', 'content' ) as $field ) {
				if ( isset( $email[ $field ] ) && is_string( $email[ $field ] ) && '' !== trim( $email[ $field ] ) ) {
					$this->templates[ $email[ $field ] ] = array(
						'process'  => $process,
						'action'   => (string) $node['id'],
						'field'    => $field,
						'customer' => $customer,
					);
				}
			}
			return;
		}
		foreach ( $node as $child ) {
			$this->collectTemplates( $process, $child );
		}
	}

	/**
	 * Returns no option: the plugin's texts are rows of its tables.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array();
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The services, categories, agents, locations and bundles of LatePoint, the texts of its booking steps, and the emails to customers, which go out in the language the customer booked in. Keep every placeholder, such as {{customer_full_name}}.', 'swiftling' );
	}

	/**
	 * Reads the texts of the plugin's models, steps and emails to customers for the subject of the
	 * settings, as the plugin loads them.
	 */
	protected function readMoreTexts(): void {
		global $wpdb;
		foreach ( self::MODELS as $class => $model ) {
			$table = $wpdb->prefix . self::TABLES[ $model[0] ];
			if ( ! $this->hasTable( $table ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own table; the fields are constants of this class.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, ' . implode( ', ', $model[1] ) . ' FROM %i ORDER BY id', $table ) ) as $row ) {
				$row = (object) $row;
				// As the plugin hands the model to its filter.
				$this->translateModel( self::stand( $class, $row ) );
			}
		}
		$settings = $wpdb->prefix . 'latepoint_settings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
		$steps = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE name = %s', $settings, 'steps_settings' ) );
		if ( is_string( $steps ) ) {
			$this->translateModel(
				self::stand(
					'OsSettingsModel',
					(object) array(
						'name'  => 'steps_settings',
						'value' => $steps,
					)
				)
			);
		}
		$support = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE name = %s', $settings, 'steps_support_text' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
		if ( is_string( $support ) && '' !== trim( $support ) ) {
			$this->stepText( 'shared', 'steps_support_text', $support );
		}
		foreach ( $this->templates() as $template => $email ) {
			if ( $email['customer'] ) {
				$this->template( $email, $template );
			}
		}
	}

	/**
	 * Returns a model of the plugin's class holding a row, or a plain object where the class is not
	 * loaded: the model's class decides how it is translated.
	 *
	 * @param string $class_name The model's class.
	 * @param object $row        The row.
	 * @return object
	 */
	private static function stand( string $class_name, $row ) {
		$model = class_exists( $class_name ) ? new $class_name() : null;
		if ( null === $model ) {
			return $row;
		}
		foreach ( get_object_vars( $row ) as $key => $value ) {
			$model->$key = $value;
		}
		return $model;
	}

	/**
	 * Declares the texts of the plugin's models, steps and emails as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, self::BASE . ':' ) ) {
			return null;
		}
		return 0 === strpos( $path, self::BASE . '/' ) ? true : null;
	}

	/**
	 * Returns what the editor calls a text of the plugin.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, self::BASE . ':' ) ) {
			return '';
		}
		$parts = explode( ':', $owner );
		$field = str_replace( array( '%2F', '%25', '_' ), array( '/', '%', ' ' ), (string) substr( $path, strlen( self::BASE ) + 1 ) );
		$types = array(
			'service'           => __( 'Service', 'swiftling' ),
			'category'          => __( 'Category', 'swiftling' ),
			'agent'             => __( 'Agent', 'swiftling' ),
			'location'          => __( 'Location', 'swiftling' ),
			'location-category' => __( 'Location category', 'swiftling' ),
			'bundle'            => __( 'Bundle', 'swiftling' ),
			'steps'             => __( 'Booking steps', 'swiftling' ),
			'process'           => __( 'Email to the customer', 'swiftling' ),
		);
		$name  = $types[ $parts[1] ] ?? $parts[1];
		return ( isset( $parts[2] ) && 'process' !== $parts[1] ? $name . ' ' . $parts[2] : $name ) . ' › ' . ucfirst( $field );
	}

	/**
	 * Keeps the placeholders of the plugin's templates, as {{customer_full_name}}, as they are when a
	 * text goes to machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) && preg_match_all( '/\{\{[a-z0-9_]+\}\}/i', $item['text'], $found ) ) {
				$keep                  = isset( $item['keep'] ) && is_array( $item['keep'] ) ? $item['keep'] : array();
				$items[ $key ]['keep'] = array_values( array_unique( array_merge( $keep, $found[0] ) ) );
			}
		}
		return $items;
	}
}
