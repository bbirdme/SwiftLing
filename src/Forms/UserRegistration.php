<?php
/**
 * User Registration & Membership: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * User Registration & Membership's forms. Each is a post of the type "user_registration", which visitors
 * never open:
 *
 * - The fields of a form are JSON in the content of the post, with the label, placeholder and
 *   description of each in its "general_setting", also those the site's owner never changed: the plugin
 *   writes its own words into a form when it creates it. It hands the fields of each part of a form to
 *   the filter user_registration_handle_form_fields before it prints them, where they are translated,
 *   see translateFields(), under the post's own object at paths such as "ur/fields/user_login/label".
 * - The word of the button is the meta "user_registration_form_setting_form_submit_label". A form whose
 *   settings were never saved has none, and the plugin prints a "Submit" of its own: that word is
 *   offered and translated at the place of the meta, see defaultButton().
 *
 * The form is sent through admin-ajax.php, answered in the language of the page it was sent from, see
 * SwiftLing\Builders\UserRegistration, with the messages and emails of the plugin's settings.
 *
 * Checked against the code of User Registration & Membership 5.3 (the free plugin), in
 * tests/UserRegistrationTest.php, and by hand on a site with the plugin.
 */
final class UserRegistration extends Provider {
	/**
	 * Where the texts of the fields are in the object of the form's post, and their source for rule keys.
	 */
	const BASE   = 'ur/fields';
	const SOURCE = 'ur:fields';

	/**
	 * The texts of a field a visitor reads, as the plugin names them.
	 */
	const FIELD_TEXTS = array( 'label', 'placeholder', 'description' );

	/**
	 * The meta that holds the word of a form's button, and the word the plugin prints when there is none,
	 * see ur_admin_form_settings_fields().
	 */
	const BUTTON         = 'user_registration_form_setting_form_submit_label';
	const DEFAULT_BUTTON = 'Submit';

	/**
	 * Returns the provider's ID.
	 */
	public function id(): string {
		return 'ur';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'User Registration & Membership';
	}

	/**
	 * Returns the post type the forms are.
	 */
	public function postType(): string {
		return 'user_registration';
	}

	/**
	 * Returns what the plugin's files declare while they load.
	 */
	protected function markers(): array {
		return array( 'UR_VERSION' );
	}

	/**
	 * Returns the meta key that holds the word of a form's button.
	 */
	protected function metaKeys(): array {
		return array( self::BUTTON );
	}

	/**
	 * Returns what the editor calls that key.
	 */
	protected function metaLabels(): array {
		return array( self::BUTTON => __( 'Button', 'swiftling' ) );
	}

	/**
	 * Returns the shortcode a page holds where it shows a form.
	 */
	protected function shortcodeTag(): string {
		return 'user_registration_form';
	}

	/**
	 * Hooks where the plugin hands out the fields of a form.
	 */
	public function register(): void {
		add_filter( 'user_registration_handle_form_fields', array( $this, 'translateFields' ), 100, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		// After swiftling_filter_post_meta(), which translates a word that is stored.
		add_filter( 'get_post_metadata', array( $this, 'defaultButton' ), 101, 4 );
	}

	/**
	 * Returns the word of the button of a form that has none stored, in a language or a page check: the
	 * plugin's own "Submit", translated at the place of a stored one, so it is offered, and its
	 * translation stays once the settings are saved with it. In the original nothing is returned, and the
	 * plugin prints its own word.
	 *
	 * @param mixed  $check     Value from an earlier filter, or null.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether one value was asked for.
	 * @return mixed
	 */
	public function defaultButton( $check, $object_id, $meta_key = '', $single = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress takes the first value for one.
		if ( null !== $check || self::BUTTON !== $meta_key || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $check;
		}
		$state = &swiftling_state();
		if ( $state['guard'] || null === $this->form( (int) $object_id ) ) {
			return $check;
		}
		$state['guard'] = true;
		$stored         = metadata_exists( 'post', (int) $object_id, self::BUTTON );
		$state['guard'] = false;
		if ( $stored ) {
			return $check;
		}
		$word = swiftling_transform_text( swiftling_post_owner( (int) $object_id ), swiftling_meta_path( self::BUTTON, 0 ), 'meta:' . self::BUTTON, self::DEFAULT_BUTTON );
		return array( is_string( $word ) ? $word : self::DEFAULT_BUTTON );
	}

	/**
	 * Returns fields of a form with their texts in the mode of the moment.
	 *
	 * @param mixed $fields  The fields of a part of the form, as the plugin's objects.
	 * @param mixed $form_id The form's post ID.
	 * @return mixed
	 */
	public function translateFields( $fields, $form_id = 0 ) {
		if ( ! is_array( $fields ) || ! is_numeric( $form_id ) || ! $form_id || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $fields;
		}
		$owner = swiftling_post_owner( (int) $form_id );
		foreach ( $fields as $field ) {
			if ( ! is_object( $field ) || ! isset( $field->general_setting ) || ! is_object( $field->general_setting ) ) {
				continue;
			}
			$settings = $field->general_setting;
			$name     = isset( $settings->field_name ) && is_string( $settings->field_name ) ? $settings->field_name : (string) ( $field->field_key ?? '' );
			foreach ( self::FIELD_TEXTS as $text ) {
				if ( isset( $settings->$text ) && is_string( $settings->$text ) && '' !== trim( $settings->$text ) ) {
					$translated      = swiftling_transform_text( $owner, self::BASE . '/' . swiftling_escape_segment( $name ) . '/' . $text, self::SOURCE, $settings->$text );
					$settings->$text = is_string( $translated ) ? $translated : $settings->$text;
				}
			}
		}
		return $fields;
	}

	/**
	 * Reads the fields of a form, as the plugin hands them to its filter.
	 *
	 * @param WP_Post $form Form.
	 */
	protected function readMoreTexts( WP_Post $form ): void {
		$rows = json_decode( $form->post_content );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			foreach ( is_array( $row ) ? $row : array() as $grid ) {
				if ( is_array( $grid ) ) {
					$this->translateFields( $grid, $form->ID );
				}
			}
		}
	}

	/**
	 * Returns what the editor calls a text of a form: the field's name and which text of it.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $path, self::BASE . '/' ) || 0 !== strpos( $owner, 'post:' ) || null === $this->form( (int) substr( $owner, 5 ) ) ) {
			return parent::fieldLabel( $owner, $path );
		}
		$parts = explode( '/', substr( $path, strlen( self::BASE ) + 1 ) );
		$texts = array(
			'label'       => __( 'Label', 'swiftling' ),
			'placeholder' => __( 'Placeholder', 'swiftling' ),
			'description' => __( 'Description', 'swiftling' ),
		);
		return ucfirst( str_replace( '_', ' ', rawurldecode( $parts[0] ) ) ) . ' › ' . ( $texts[ $parts[1] ?? '' ] ?? ( $parts[1] ?? '' ) );
	}

	/**
	 * Declares the texts of the fields and the word of the button as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for what is not a text of a form.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, 'post:' ) || null === $this->form( (int) substr( $owner, 5 ) ) ) {
			return null;
		}
		return 0 === strpos( $path, self::BASE . '/' ) || swiftling_meta_path( self::BUTTON, 0 ) === $path ? true : null;
	}
}
