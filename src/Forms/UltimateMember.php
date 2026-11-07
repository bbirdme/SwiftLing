<?php
/**
 * Ultimate Member: where its forms are and what of them is text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Ultimate Member's forms: registration, login and profile. Each is a post of the type "um_form", which
 * visitors never open:
 *
 * - The fields of a form are the meta "_um_custom_fields", read once per form and handed to the filter
 *   um_get_form_fields before the plugin prints them: their labels, placeholders and help texts are
 *   translated there, see translateFields(), under the post's own object at paths such as
 *   "um/fields/first_name/label". The choices of a field are not: the plugin keeps a choice as its own
 *   value, so a translated one would be saved with the member.
 * - The words of a form's buttons are meta, read with get_post_meta() as any meta.
 *
 * A form is sent to the page that shows it, so the answer is in the language of that page. The texts
 * of the plugin's settings and emails are in SwiftLing\Builders\UltimateMember.
 *
 * Checked against the code of Ultimate Member 2.14, in tests/UltimateMemberTest.php, and by hand on a
 * site with the plugin.
 */
final class UltimateMember extends Provider {
	/**
	 * Where the texts of the fields are in the object of the form's post, and their source for rule keys.
	 */
	const BASE   = 'um/fields';
	const SOURCE = 'um:fields';

	/**
	 * The texts of a field a visitor reads, as the plugin names them.
	 */
	const FIELD_TEXTS = array( 'label', 'placeholder', 'help' );

	/**
	 * Returns the provider's ID.
	 */
	public function id(): string {
		return 'um';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Ultimate Member';
	}

	/**
	 * Returns the post type the forms are.
	 */
	public function postType(): string {
		return 'um_form';
	}

	/**
	 * Returns what the plugin's files declare while they load.
	 */
	protected function markers(): array {
		return array( 'UM_VERSION' );
	}

	/**
	 * Returns the meta keys that hold the words of a form's buttons.
	 */
	protected function metaKeys(): array {
		return array_keys( $this->metaLabels() );
	}

	/**
	 * Returns what the editor calls those keys.
	 */
	protected function metaLabels(): array {
		return array(
			'_um_register_primary_btn_word'   => __( 'Main button', 'swiftling' ),
			'_um_register_secondary_btn_word' => __( 'Second button', 'swiftling' ),
			'_um_login_primary_btn_word'      => __( 'Main button', 'swiftling' ),
			'_um_login_secondary_btn_word'    => __( 'Second button', 'swiftling' ),
			'_um_profile_primary_btn_word'    => __( 'Main button', 'swiftling' ),
			'_um_profile_secondary_btn_word'  => __( 'Second button', 'swiftling' ),
		);
	}

	/**
	 * Returns the shortcode a page holds where it shows a form.
	 */
	protected function shortcodeTag(): string {
		return 'ultimatemember';
	}

	/**
	 * Returns how many published posts show a form: the plugin's shortcode names it by form_id.
	 *
	 * @param WP_Post $form Form.
	 */
	protected function pages( WP_Post $form ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One count per form on the Forms screen.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM %i WHERE post_status = 'publish' AND post_content LIKE %s", $wpdb->posts, '%' . $wpdb->esc_like( '[' . $this->shortcodeTag() . ' form_id="' . $form->ID . '"' ) . '%' ) );
	}

	/**
	 * Hooks where the plugin hands out the fields of a form.
	 */
	public function register(): void {
		add_filter( 'um_get_form_fields', array( $this, 'translateFields' ), 100, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
	}

	/**
	 * Returns the fields of a form with their texts in the mode of the moment.
	 *
	 * @param mixed $fields  The fields, by their key.
	 * @param mixed $form_id The form's post ID.
	 * @return mixed
	 */
	public function translateFields( $fields, $form_id = 0 ) {
		if ( ! is_array( $fields ) || ! is_numeric( $form_id ) || ! $form_id || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $fields;
		}
		$owner = swiftling_post_owner( (int) $form_id );
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			foreach ( self::FIELD_TEXTS as $name ) {
				if ( isset( $field[ $name ] ) && is_string( $field[ $name ] ) && '' !== trim( $field[ $name ] ) ) {
					$translated              = swiftling_transform_text( $owner, self::BASE . '/' . swiftling_escape_segment( (string) $key ) . '/' . $name, self::SOURCE, $field[ $name ] );
					$fields[ $key ][ $name ] = is_string( $translated ) ? $translated : $field[ $name ];
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
		// Declared as no text, see fieldHoldsText(): read as stored, also while the editor notes texts.
		$fields = get_post_meta( $form->ID, '_um_custom_fields', true );
		if ( is_array( $fields ) ) {
			$this->translateFields( $fields, $form->ID );
		}
	}

	/**
	 * Returns what the editor calls a text of a form: the label of its field and which text of it.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $path, self::BASE . '/' ) || 0 !== strpos( $owner, 'post:' ) ) {
			return parent::fieldLabel( $owner, $path );
		}
		$form = $this->form( (int) substr( $owner, 5 ) );
		if ( null === $form ) {
			return '';
		}
		$parts  = explode( '/', substr( $path, strlen( self::BASE ) + 1 ) );
		$key    = rawurldecode( $parts[0] );
		$fields = get_post_meta( $form->ID, '_um_custom_fields', true );
		$name   = is_array( $fields ) && isset( $fields[ $key ]['title'] ) && is_string( $fields[ $key ]['title'] ) && '' !== $fields[ $key ]['title'] ? $fields[ $key ]['title'] : $key;
		$texts  = array(
			'label'       => __( 'Label', 'swiftling' ),
			'placeholder' => __( 'Placeholder', 'swiftling' ),
			'help'        => __( 'Help text', 'swiftling' ),
		);
		return $name . ' › ' . ( $texts[ $parts[1] ?? '' ] ?? ( $parts[1] ?? '' ) );
	}

	/**
	 * Declares the texts of the fields as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for what is not a text of a field.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, 'post:' ) || null === $this->form( (int) substr( $owner, 5 ) ) ) {
			return null;
		}
		if ( 0 === strpos( $path, self::BASE . '/' ) || in_array( substr( $path, 5 ), $this->metaKeys(), true ) ) {
			return true;
		}
		// The fields as the plugin stores them: their texts are offered by field, see above.
		return 0 === strpos( $path, 'meta/_um_custom_fields' ) ? false : null;
	}
}
