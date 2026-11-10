<?php
/**
 * Business Directory Plugin: what of a listing SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Business Directory Plugin. A listing is a post of the type "wpbdp_listing", its categories and tags
 * terms, and the values of its fields its meta, read through get_post_meta(): SwiftLing finds them
 * by itself. What it does not:
 *
 * - The fields themselves, their names, descriptions and choices, are rows of the plugin's own table,
 *   "wpbdp_form_fields": texts of their own, "business-directory:fields", translated where the plugin
 *   makes a field, see translateField(), and offered on the Templates screen, see listTexts().
 * - The fields that show a listing's title, short description and description read them from the
 *   post itself, past get_the_title() and the_content: put in the mode of the moment where the plugin
 *   hands a value out, see translateValue().
 * - The plugin builds its addresses' rules from the address of its main page on every request and
 *   writes them again when they differ. In another language that page has its translated slug, so the
 *   rules changed on every request and a listing there was not found. The rules are built from the
 *   page's original slug, see ruleBase(); SwiftLing puts a translated slug back before WordPress reads
 *   an address.
 * - A listing gets no separate copy: its plan and payments are kept by its ID. It is translated in
 *   place, see noCopyTypes().
 *
 * Not reached: the plugin writes the emails to a listing's owner and to the site's owner together, in
 * the language of the request.
 *
 * Checked against the code of Business Directory Plugin 6.4 (the free plugin), in tests/JobBoardsTest.php,
 * and by hand on a site with the plugin. Its premium modules were not checked.
 */
class BusinessDirectory extends Builder {
	/**
	 * The plugin's type of post.
	 */
	const POST_TYPE = 'wpbdp_listing';

	/**
	 * The object key of the texts of the plugin's fields.
	 */
	const FIELDS = 'business-directory:fields';

	/**
	 * Source of a text of a field, for its rule key.
	 */
	const SOURCE = 'business-directory:field';

	/**
	 * The subject of the plugin's fields on the Templates screen.
	 */
	const SUBJECT = 'business-directory-fields';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'business-directory';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Business Directory Plugin';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'WPBDP_VERSION' );
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
	 * Returns the plugin's listings: translated in place.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( self::POST_TYPE );
	}

	/**
	 * Hooks where the plugin builds its rules, makes a field and hands out a value.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters.
		add_filter( 'wpbdp_url_base_url', array( $this, 'ruleBase' ), 10, 3 );
		add_filter( 'wpbdp_form_field_args', array( $this, 'translateField' ), 10, 1 );
		add_filter( 'wpbdp_form_field_value', array( $this, 'translateValue' ), 10, 3 );
		// phpcs:enable
	}

	/**
	 * Returns the address of the plugin's main page the plugin builds its rules from: with the page's
	 * original slug in every language. For a link, which the plugin builds with more arguments, the
	 * address stays as it is, with the slug of the language.
	 *
	 * @param mixed $url     The page's address, as WordPress built it.
	 * @param mixed $page_id The page.
	 * @param mixed $context What the plugin builds a link to; none for its rules.
	 * @return mixed
	 */
	public function ruleBase( $url, $page_id = 0, $context = null ) {
		if ( null !== $context || ! is_numeric( $page_id ) || ! (int) $page_id || '' === swiftling_writing_language() ) {
			return $url;
		}
		$original = swiftling_in_link_language(
			'',
			static function () use ( $page_id ) {
				return _get_page_link( (int) $page_id );
			}
		);
		return is_string( $original ) ? (string) preg_replace( '/\?.*/', '', $original ) : $url;
	}

	/**
	 * Returns a field the plugin makes with its name, description and choices in the mode of the moment.
	 *
	 * @param mixed $attrs The field's settings, as the plugin read them from its table.
	 * @return mixed
	 */
	public function translateField( $attrs ) {
		if ( ! is_array( $attrs ) || empty( $attrs['id'] ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $attrs;
		}
		$base = 'fields/' . (int) $attrs['id'] . '/';
		foreach ( array( 'label', 'description' ) as $part ) {
			if ( isset( $attrs[ $part ] ) && is_string( $attrs[ $part ] ) && '' !== trim( $attrs[ $part ] ) ) {
				$attrs[ $part ] = (string) swiftling_transform_text( self::FIELDS, $base . $part, self::SOURCE, $attrs[ $part ] );
			}
		}
		if ( isset( $attrs['field_data']['options'] ) && is_array( $attrs['field_data']['options'] ) ) {
			foreach ( $attrs['field_data']['options'] as $key => $choice ) {
				if ( is_string( $choice ) && '' !== trim( $choice ) ) {
					$attrs['field_data']['options'][ $key ] = (string) swiftling_transform_text( self::FIELDS, $base . 'options/' . swiftling_escape_segment( (string) $key ), self::SOURCE, $choice );
				}
			}
		}
		return $attrs;
	}

	/**
	 * Returns a value of a listing the plugin hands out in the mode of the moment: its title, short
	 * description or description, which the plugin reads from the post itself.
	 *
	 * @param mixed $value   The value.
	 * @param mixed $post_id The listing.
	 * @param mixed $field   The plugin's field.
	 * @return mixed
	 */
	public function translateValue( $value, $post_id = 0, $field = null ) {
		if ( ! is_string( $value ) || '' === trim( $value ) || ! is_object( $field ) || ! method_exists( $field, 'get_association' ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $value;
		}
		$post = get_post( is_object( $post_id ) ? (int) $post_id->ID : (int) $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $value;
		}
		$owner = swiftling_post_owner( $post->ID );
		switch ( $field->get_association() ) {
			case 'title':
				return $value === $post->post_title ? (string) swiftling_filter_title( $value, $post->ID ) : $value;
			case 'excerpt':
				return $value === $post->post_excerpt ? (string) swiftling_transform( $owner, 'field/post_excerpt', 'field:post_excerpt', $value ) : $value;
			case 'content':
				return $value === $post->post_content ? (string) swiftling_translate_post_content( $owner, $value ) : $value;
		}
		return $value;
	}

	/**
	 * Declares the names, descriptions and choices of the plugin's fields as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		return self::FIELDS === $owner && 0 === strpos( $path, 'fields/' ) ? true : null;
	}

	/**
	 * Returns what the editor calls a text of a field: the field's name, and which text of it.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( self::FIELDS !== $owner || ! preg_match( '#^fields/(\d+)/(label|description|options)#', $path, $found ) ) {
			return '';
		}
		$names = array(
			'label'       => __( 'Name', 'swiftling' ),
			'description' => __( 'Description', 'swiftling' ),
			'options'     => __( 'Choice', 'swiftling' ),
		);
		$label = $this->rows()[ (int) $found[1] ]['label'] ?? '';
		return ( '' !== $label ? $label : '#' . $found[1] ) . ' › ' . $names[ $found[2] ];
	}

	/**
	 * Returns a subject of the editor: the plugin's fields.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		if ( self::SUBJECT !== $key ) {
			return null;
		}
		return array(
			'key'   => $key,
			'kind'  => 'fields',
			'title' => __( 'Fields of Business Directory', 'swiftling' ),
			'type'  => __( 'Settings', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => 'manage_options',
			'note'  => __( 'The names, descriptions and choices of the fields of every listing, as its forms and its pages show them. Each is translated once for every listing.', 'swiftling' ),
		);
	}

	/**
	 * Returns the subject of the plugin's fields, for the Templates screen.
	 *
	 * @return array
	 */
	public function subjects(): array {
		if ( ! $this->rows() ) {
			return array();
		}
		return array(
			array(
				'title'       => $this->name(),
				'description' => __( 'The fields of its listings.', 'swiftling' ),
				'subjects'    => array(
					array(
						'key'         => self::SUBJECT,
						'title'       => __( 'Fields', 'swiftling' ),
						'description' => __( 'Names, descriptions and choices', 'swiftling' ),
					),
				),
			),
		);
	}

	/**
	 * Returns the texts of the plugin's fields for their subject, read as a page in a language reads
	 * them, so their object and path are the ones translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( self::SUBJECT !== $subject['key'] ) {
			return array();
		}
		$rows  = $this->rows();
		$texts = array();
		foreach ( swiftling_collect(
			function () use ( $rows ): void {
				foreach ( $rows as $row ) {
					$this->translateField( $row );
				}
			}
		) as $leaf ) {
			if ( self::FIELDS !== $leaf['o'] ) {
				continue;
			}
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'text',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns the plugin's fields as its table keeps them, by ID.
	 *
	 * @return array<int, array{id: int, label: string, description: string, field_data: array}>
	 */
	private function rows(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpbdp_form_fields';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		$rows = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, label, description, field_data FROM %i ORDER BY weight DESC, id LIMIT %d', $table, 500 ) ) as $row ) {
			$data                   = maybe_unserialize( (string) $row->field_data );
			$rows[ (int) $row->id ] = array(
				'id'          => (int) $row->id,
				'label'       => (string) $row->label,
				'description' => (string) $row->description,
				'field_data'  => is_array( $data ) ? $data : array(),
			);
		}
		return $rows;
	}
}
