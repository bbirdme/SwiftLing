<?php
/**
 * Advanced Custom Fields.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced Custom Fields (ACF), free and PRO: the fields of posts, options pages and the texts of
 * field groups.
 *
 * Checked in ACF PRO 6.8.9. ACF reads the value of a field of a post with get_metadata()
 * (MetaLocation::get_value() in src/Meta/MetaLocation.php) and of an options page with get_option()
 * (Option::get_value() in src/Meta/Option.php), one stored value per field: a field of a repeater,
 * group or flexible content row has a value of its own, named after its parents ("team_0_name").
 * Next to every value ACF stores the key of its field, under the same name with an underscore in
 * front ("_team_0_name"), so the field, and its type, can be found for any stored value. The probe
 * finds the values without this class; this class adds what ACF declares:
 *
 * - a text, textarea or WYSIWYG field and the title of a link are text, also one word in lowercase,
 *   and also where the field's name looks like a setting's, as "block_title";
 * - the value of a choice, a number, a date, a colour, a password and the IDs that relational and
 *   media fields keep are never text, however they look: a theme compares a choice with a fixed
 *   string, so a translated value would break the page;
 * - options pages and the texts of field groups (labels, choices, default values) are subjects of
 *   the editor, translated without a page check, and the editor of a post also lists the texts of
 *   fields its page does not show; an options page has a Translations box that leads there;
 * - labels and choices of fields show translated where a theme or a front-end form shows them. A
 *   choice field that returns its value returns it as stored, also where the theme prints it: ACF
 *   formats a value the same way whether the theme prints it or compares it (the_field() asks for
 *   escaping, but a choice field does not support it, see the_field() in
 *   includes/api/api-template.php), so a translated value would break the comparison. A theme
 *   shows a choice translated when the field returns its label;
 * - a separate copy of a post shows the field groups of its original, and the location rule
 *   "SwiftLing language" shows a field group only in some languages.
 *
 * ACF blocks keep their values in the block's attributes ("data"), which the probe reads as it
 * reads any block. Fields of terms are typed as fields of posts are, and translated in the editor
 * of their list, see includes/terms.php. Fields of users and comments are not read yet.
 */
final class Acf extends Builder {
	/**
	 * Field types whose value is text as a whole.
	 */
	private const TEXT_TYPES = array( 'text', 'textarea', 'wysiwyg' );

	/**
	 * Field types whose value never is text: choices, numbers, dates, colours, settings, the IDs of
	 * posts, terms, users and files, and fields that only hold other fields (their own value is a
	 * count or a list of layouts).
	 */
	private const NO_TEXT_TYPES = array(
		'select',
		'checkbox',
		'radio',
		'button_group',
		'true_false',
		'number',
		'range',
		'password',
		'color_picker',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'icon_picker',
		'image',
		'file',
		'gallery',
		'post_object',
		'page_link',
		'relationship',
		'taxonomy',
		'user',
		'repeater',
		'flexible_content',
		'group',
		'clone',
		'message',
		'tab',
		'accordion',
	);

	/**
	 * Field types whose value is an address: offered as a link, an image or a video.
	 */
	private const ADDRESS_TYPES = array( 'url', 'oembed' );

	/**
	 * Field types whose value is the ID of a file of the media library, or a list of them.
	 */
	private const MEDIA_TYPES = array( 'image', 'file', 'gallery' );

	/**
	 * Field types a person chooses a value of from a list of choices.
	 */
	private const CHOICE_TYPES = array( 'select', 'checkbox', 'radio', 'button_group' );

	/**
	 * Settings of a field that a visitor can read: in a front-end form (acf_form()), or where a
	 * theme prints them, as a label next to a value. Setting => kind of text in the editor.
	 */
	private const FIELD_TEXTS = array(
		'label'        => 'label',
		'instructions' => 'text',
		'placeholder'  => 'text',
		'prepend'      => 'text',
		'append'       => 'text',
		'message'      => 'paragraph',
		'ui_on_text'   => 'label',
		'ui_off_text'  => 'label',
		'button_label' => 'button',
	);

	/**
	 * The field each stored value belongs to, by object and name, as found so far.
	 *
	 * @var array<string, array|null>
	 */
	private $fields = array();

	/**
	 * The field group each field belongs to, by field key, as found so far.
	 *
	 * @var array<string, string>
	 */
	private $groups = array();

	/**
	 * Prefixes of the option names under which options pages store their values, e.g. "options_".
	 *
	 * @var string[]|null
	 */
	private $prefixes = null;

	/**
	 * Returns the builder's ID.
	 */
	public function id(): string {
		return 'acf';
	}

	/**
	 * Returns the builder's name.
	 */
	public function name(): string {
		return 'Advanced Custom Fields';
	}

	/**
	 * Whether ACF is active.
	 */
	public function isActive(): bool {
		return class_exists( '\ACF' ) && function_exists( 'acf_get_field' );
	}

	/**
	 * Fields do not build a page: the editor names only the builders that lay a page out.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same answer for every post.
		return false;
	}

	/**
	 * Adds the location rule "SwiftLing language", and shows on a separate copy of a post the field
	 * groups that name its original.
	 */
	public function register(): void {
		if ( did_action( 'acf/init' ) ) {
			$this->registerLocation();
		} else {
			add_action( 'acf/init', array( $this, 'registerLocation' ) );
		}
		// See acf_match_location_rule() in includes/locations.php.
		foreach ( array( 'post', 'page' ) as $param ) {
			add_filter( 'acf/location/match_rule/type=' . $param, array( $this, 'matchOriginal' ), 20, 4 );
		}
		// The Translations box on an options page, under ACF's Publish box. ACF fires these on the
		// screens where it shows fields, and adds its own boxes on acf/input/admin_head
		// (ACF_Admin_Options_Page::admin_head() in pro/admin/admin-options-page.php).
		add_action( 'acf/input/admin_enqueue_scripts', array( $this, 'enqueueOptionsBox' ) );
		add_action( 'acf/input/admin_head', array( $this, 'addOptionsBox' ) );
	}

	/**
	 * Loads the styles of the Translations box on an options page.
	 */
	public function enqueueOptionsBox(): void {
		if ( $this->currentOptionsSubject() ) {
			\SwiftLing\Admin\SubjectBox::enqueue();
		}
	}

	/**
	 * Adds the Translations box to the options page being shown, for someone who may translate it,
	 * so the way to its translation starts where its fields are filled in.
	 */
	public function addOptionsBox(): void {
		$subject = $this->currentOptionsSubject();
		if ( ! $subject ) {
			return;
		}
		add_meta_box(
			'swiftling-translations',
			__( 'Translations', 'swiftling' ),
			static function () use ( $subject ) {
				\SwiftLing\Admin\SubjectBox::render( $subject );
			},
			'acf_options_page',
			'side'
		);
	}

	/**
	 * Returns the subject of the options page being shown in the admin, when the user may
	 * translate it.
	 */
	private function currentOptionsSubject(): ?array {
		global $plugin_page;
		if ( ! is_admin() || ! is_string( $plugin_page ) || ! $this->optionsPage( $plugin_page ) ) {
			return null;
		}
		$subject = swiftling_subject( 'acf-options:' . $plugin_page );
		return $subject && swiftling_can_translate_subject( $subject ) ? $subject : null;
	}

	/**
	 * Registers the location rule "SwiftLing language", see AcfLanguageLocation.
	 */
	public function registerLocation(): void {
		if ( function_exists( 'acf_register_location_type' ) && class_exists( '\ACF_Location' ) ) {
			acf_register_location_type( AcfLanguageLocation::class );
		}
	}

	/**
	 * Matches the rules "Post is" and "Page is" on a separate copy as on its original, so the copy
	 * shows the field groups of its original and its fields can be changed in it. Other rules, such
	 * as the post type or the page template, already match: the copy has the same.
	 *
	 * @param bool  $result      Whether the rule matches, as ACF found.
	 * @param array $rule        Rule: param, operator, value.
	 * @param array $screen      What ACF shows the field groups for.
	 * @param array $field_group Field group.
	 * @return bool
	 */
	public function matchOriginal( $result, $rule, $screen, $field_group ) {
		$post     = empty( $screen['post_id'] ) || ! is_numeric( $screen['post_id'] ) ? null : get_post( (int) $screen['post_id'] );
		$original = $post ? (int) swiftling_copy_original_id( $post ) : 0;
		$location = $original && function_exists( 'acf_get_location_type' ) ? acf_get_location_type( (string) $rule['param'] ) : null;
		if ( ! $location ) {
			return $result;
		}
		$screen['post_id'] = $original;
		return (bool) $location->match( $rule, $screen, $field_group );
	}

	/**
	 * Shows the texts of fields translated on a translated page: labels, choices, default values.
	 *
	 * ACF keeps a field once it is loaded (acf_get_field() in includes/acf-field-functions.php keeps
	 * it in the store "fields"), so fields loaded before the translated view started are loaded again.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		if ( 'translate' !== $mode ) {
			return;
		}
		if ( function_exists( 'acf_get_store' ) ) {
			acf_get_store( 'fields' )->reset();
		}
		add_filter( 'acf/load_field', array( $this, 'translateField' ), PHP_INT_MAX );
	}

	/**
	 * Puts the translations of a field's texts in place, see self::FIELD_TEXTS: its label, the labels
	 * of its choices and of its layouts, and the default value of a text field. The value a choice
	 * stores stays as it is.
	 *
	 * @param array|mixed $field Field.
	 * @return array|mixed
	 */
	public function translateField( $field ) {
		if ( ! is_array( $field ) || empty( $field['key'] ) ) {
			return $field;
		}
		$key   = $this->originalKey( $field );
		$owner = $this->groupOwner( $key );
		if ( '' === $owner ) {
			return $field;
		}

		foreach ( $this->settingTexts( $field ) as $path => list( $setting, $value ) ) {
			$field[ $setting ] = swiftling_transform_text( $owner, $path, 'acf-field:' . $setting, $value );
		}
		if ( in_array( $field['type'], self::CHOICE_TYPES, true ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
			foreach ( $field['choices'] as $value => $label ) {
				if ( is_string( $label ) ) {
					$field['choices'][ $value ] = swiftling_transform_text( $owner, $this->choicePath( $key, (string) $value ), 'acf-field:choice', $label );
				}
			}
		}
		if ( 'flexible_content' === $field['type'] && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			foreach ( $field['layouts'] as $index => $layout ) {
				if ( isset( $layout['key'], $layout['label'] ) && is_string( $layout['label'] ) ) {
					$field['layouts'][ $index ]['label'] = swiftling_transform_text( $owner, 'field/' . swiftling_escape_segment( $key ) . '/layouts/' . swiftling_escape_segment( (string) $layout['key'] ), 'acf-field:layout', $layout['label'] );
				}
			}
		}
		return $field;
	}

	/**
	 * Whether ACF declares a stored value as text, from the type of its field. See
	 * Builder::fieldHoldsText().
	 *
	 * Only the value as a whole counts: a link field keeps an address, a title and a target in one
	 * value, and only its title is declared text; its address is left to the probe, as any address.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		$found = $this->storedField( $owner, $path );
		if ( null === $found ) {
			return null;
		}
		list( $field, $rest ) = $found;
		$type                 = (string) $field['type'];

		if ( in_array( $type, self::TEXT_TYPES, true ) ) {
			return '' === $rest ? true : null;
		}
		if ( 'link' === $type ) {
			if ( 'title' === $rest ) {
				return true;
			}
			return 'url' === $rest ? null : false;
		}
		if ( in_array( $type, self::NO_TEXT_TYPES, true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Returns the label of the field a stored value belongs to, with the rows it sits in, such as
	 * "Team members 2 › Name".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		$found = $this->storedField( $owner, $path );
		if ( null === $found ) {
			return '';
		}
		list( $field, , $name ) = $found;

		// The fields above this one, outermost first, each with the row the value sits in.
		$chain = array();
		$above = $field;
		for ( $depth = 0; $depth < 10; $depth++ ) {
			$above = $this->parentField( $above );
			if ( ! $above ) {
				break;
			}
			array_unshift( $chain, $above );
		}

		$parts  = array();
		$offset = 0;
		foreach ( $chain as $ancestor ) {
			$label = '' !== (string) $ancestor['label'] ? (string) $ancestor['label'] : (string) $ancestor['name'];
			$start = (string) $ancestor['name'] . '_';
			if ( '' !== (string) $ancestor['name'] && substr( $name, $offset, strlen( $start ) ) === $start ) {
				$offset += strlen( $start );
				if ( in_array( $ancestor['type'], array( 'repeater', 'flexible_content' ), true ) && preg_match( '/^(\d+)_/', substr( $name, $offset ), $row ) ) {
					$offset += strlen( $row[0] );
					/* translators: 1: name of a repeating field, e.g. Team members, 2: number of the row, e.g. 2. */
					$label = sprintf( __( '%1$s %2$s', 'swiftling' ), $label, number_format_i18n( (int) $row[1] + 1 ) );
				}
			}
			$parts[] = $label;
		}
		$parts[] = '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['name'];
		return implode( ' › ', $parts );
	}

	/**
	 * Returns a subject of the editor: an options page ("acf-options:<menu slug>") or the texts of a
	 * field group ("acf-fields:<group key>").
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		if ( 0 === strpos( $key, 'acf-options:' ) ) {
			$page = $this->optionsPage( substr( $key, 12 ) );
			if ( ! $page ) {
				return null;
			}
			return array(
				'key'   => $key,
				'kind'  => 'fields',
				'title' => (string) $page['page_title'],
				'type'  => __( 'Options page', 'swiftling' ),
				'url'   => '',
				'probe' => false,
				'post'  => null,
				'cap'   => (string) $page['capability'],
				'note'  => __( 'Every field of this options page that holds text. What the site shows from it, such as the footer, shows translated on every page.', 'swiftling' ),
			);
		}

		if ( 0 === strpos( $key, 'acf-fields:' ) ) {
			$group = acf_get_field_group( substr( $key, 11 ) );
			if ( ! $group ) {
				return null;
			}
			return array(
				'key'   => 'acf-fields:' . $group['key'],
				'kind'  => 'fields',
				'title' => (string) $group['title'],
				'type'  => __( 'Labels and choices of a field group', 'swiftling' ),
				'url'   => '',
				'probe' => false,
				'post'  => null,
				'cap'   => (string) acf_get_setting( 'capability' ),
				'note'  => __( 'The labels, choices and default values of these fields, as visitors see them where the theme or a form shows them. What a page stores in the fields is translated with the page.', 'swiftling' ),
			);
		}

		return null;
	}

	/**
	 * Returns the options pages and field groups, for the Templates screen.
	 */
	public function subjects(): array {
		$pages = array();
		foreach ( $this->optionsPages() as $slug => $page ) {
			// The field groups it shows, where they are named other than the page.
			$names   = array_diff( wp_list_pluck( acf_get_field_groups( array( 'options_page' => $slug ) ), 'title' ), array( (string) $page['page_title'] ) );
			$pages[] = array(
				'key'         => 'acf-options:' . $slug,
				'title'       => (string) $page['page_title'],
				/* translators: %l: names of field groups, e.g. Header and Footer. */
				'description' => $names ? wp_sprintf( __( 'Fields: %l', 'swiftling' ), array_values( $names ) ) : '',
			);
		}

		$groups = array();
		foreach ( acf_get_field_groups() as $group ) {
			$groups[] = array(
				'key'         => 'acf-fields:' . $group['key'],
				'title'       => (string) $group['title'],
				'description' => empty( $group['active'] ) ? __( 'Inactive: shows only where another group clones its fields.', 'swiftling' ) : '',
			);
		}

		$subjects = array();
		if ( $pages ) {
			$subjects[] = array(
				'title'       => __( 'Options pages', 'swiftling' ),
				'description' => __( 'Settings pages made with ACF, such as the texts of the header and the footer.', 'swiftling' ),
				'subjects'    => $pages,
			);
		}
		if ( $groups ) {
			$subjects[] = array(
				'title'       => __( 'Labels and choices of field groups', 'swiftling' ),
				'description' => __( 'Where the theme or a form shows the label of a field or the name of a choice.', 'swiftling' ),
				'subjects'    => $groups,
			);
		}
		return $subjects;
	}

	/**
	 * Returns the texts ACF keeps for a subject: the fields of a post or of an options page, read as
	 * ACF reads them, or the texts of a field group.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( 'post' === $subject['kind'] && $subject['post'] instanceof \WP_Post ) {
			$post_id = $subject['post']->ID;
			return $this->valueTexts( $post_id, acf_get_field_groups( array( 'post_id' => $post_id ) ) );
		}
		if ( 0 === strpos( $subject['key'], 'acf-options:' ) ) {
			$slug = substr( $subject['key'], 12 );
			$page = $this->optionsPage( $slug );
			return $page ? $this->valueTexts( (string) $page['post_id'], acf_get_field_groups( array( 'options_page' => $slug ) ) ) : array();
		}
		if ( 0 === strpos( $subject['key'], 'acf-fields:' ) ) {
			return $this->groupTexts( substr( $subject['key'], 11 ) );
		}
		return array();
	}

	/**
	 * Returns the texts stored in the fields of some field groups, for a post or an options page.
	 *
	 * Every field is loaded as ACF loads it, with acf_get_value(), which loads the rows of
	 * repeaters and flexible content and the fields of groups and clones by itself, while
	 * swiftling_collect() notes each value read. So every text gets the object and path under which
	 * the translated view finds it, without this class knowing how ACF names the values of rows.
	 * Files of the media library a field shows are listed too, as the probe lists them where a page
	 * shows them: their address and, for an image, its alt text.
	 *
	 * @param int|string $post_id ACF's post ID: a post's ID, or the post ID of an options page.
	 * @param array      $groups  Field groups.
	 * @return array Texts, see Builder::listTexts().
	 */
	private function valueTexts( $post_id, array $groups ): array {
		$fields = array();
		foreach ( $groups as $group ) {
			array_push( $fields, ...acf_get_fields( $group ) );
		}
		if ( ! $fields ) {
			return array();
		}

		$media    = array();
		$recorder = static function ( $value, $value_post_id, $field ) use ( &$media ) {
			if ( isset( $field['type'] ) && in_array( $field['type'], self::MEDIA_TYPES, true ) ) {
				foreach ( (array) $value as $id ) {
					if ( is_numeric( $id ) && (int) $id > 0 ) {
						$media[ (int) $id ] = true;
					}
				}
			}
			return $value;
		};

		// Values ACF already holds would not be read again, and the values read here carry markers.
		acf_get_store( 'values' )->reset();
		add_filter( 'acf/load_value', $recorder, PHP_INT_MAX, 3 );
		try {
			$leaves = swiftling_collect(
				static function () use ( $fields, $post_id ) {
					foreach ( $fields as $field ) {
						acf_get_value( $post_id, $field );
					}
				}
			);
		} finally {
			remove_filter( 'acf/load_value', $recorder, PHP_INT_MAX );
			acf_get_store( 'values' )->reset();
		}

		$texts = array();
		foreach ( $leaves as $leaf ) {
			$found = $this->storedField( $leaf['o'], $leaf['p'] );
			$kind  = null === $found ? '' : $this->textKind( $found[0], $found[1], $leaf['v'] );
			if ( '' !== $kind ) {
				$texts[] = array(
					'o'     => $leaf['o'],
					'p'     => $leaf['p'],
					'v'     => $leaf['v'],
					'r'     => $leaf['r'],
					'kind'  => $kind,
					'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
				);
			}
		}

		foreach ( array_keys( $media ) as $id ) {
			array_push( $texts, ...swiftling_media_texts( $id ) );
		}
		return $texts;
	}

	/**
	 * Returns what kind of text a stored value is, from its field, or nothing when it is no text:
	 * a text, a paragraph, the text of a link, or an address.
	 *
	 * @param array  $field Field.
	 * @param string $rest  Path of the value inside the field's value, e.g. "title" in a link.
	 * @param string $value Value.
	 */
	private function textKind( array $field, string $rest, string $value ): string {
		$type    = (string) $field['type'];
		$address = swiftling_is_url_like( $value ) ? swiftling_media_kind( $value ) : '';
		$address = swiftling_is_url_like( $value ) && '' === $address ? 'url' : $address;

		if ( in_array( $type, self::TEXT_TYPES, true ) && '' === $rest ) {
			// A full address typed into a text field is an address all the same.
			if ( '' !== $address && 1 === preg_match( '~^(?:https?:)?//~i', $value ) ) {
				return $address;
			}
			if ( 1 !== preg_match( '/\p{L}/u', $value ) ) {
				return '';
			}
			return 'text' === $type ? 'text' : 'paragraph';
		}
		if ( 'link' === $type && 'title' === $rest && 1 === preg_match( '/\p{L}/u', $value ) ) {
			return 'link';
		}
		if ( ( 'link' === $type && 'url' === $rest ) || ( in_array( $type, self::ADDRESS_TYPES, true ) && '' === $rest ) ) {
			return $address;
		}
		return '';
	}

	/**
	 * Returns the texts of a field group's fields, see self::FIELD_TEXTS, and of their choices and
	 * layouts, also of the fields inside repeaters, groups and flexible content. A clone shows the
	 * fields of another group, whose texts are that group's.
	 *
	 * @param string $group_key Field group key.
	 * @return array Texts, see Builder::listTexts().
	 */
	private function groupTexts( string $group_key ): array {
		$texts = array();
		$owner = 'acf:' . $group_key;
		$walk  = function ( array $fields, string $above ) use ( &$walk, &$texts, $owner ) {
			foreach ( $fields as $field ) {
				if ( empty( $field['key'] ) ) {
					continue;
				}
				$key   = $this->originalKey( $field );
				$label = '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['name'];
				$place = ltrim( $above . ' › ' . $label, ' ›' );

				foreach ( $this->settingTexts( $field ) as $path => list( $setting, $value ) ) {
					$texts[] = array(
						'o'     => $owner,
						'p'     => $path,
						'v'     => $value,
						'r'     => swiftling_rule_key( 'acf-field:' . $setting, '', '' ),
						'kind'  => 'default_value' === $setting ? $this->defaultKind( $field ) : self::FIELD_TEXTS[ $setting ],
						'place' => 'label' === $setting ? $above : $place,
					);
				}
				if ( in_array( $field['type'], self::CHOICE_TYPES, true ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					foreach ( $field['choices'] as $value => $choice ) {
						if ( is_string( $choice ) && 1 === preg_match( '/\p{L}/u', $choice ) ) {
							$texts[] = array(
								'o'     => $owner,
								'p'     => $this->choicePath( $key, (string) $value ),
								'v'     => $choice,
								'r'     => swiftling_rule_key( 'acf-field:choice', '', '' ),
								'kind'  => 'label',
								'place' => $place,
							);
						}
					}
				}
				if ( 'clone' === $field['type'] ) {
					continue;
				}
				if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
					$walk( $field['sub_fields'], $place );
				}
				if ( 'flexible_content' === $field['type'] && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
					foreach ( $field['layouts'] as $layout ) {
						if ( empty( $layout['key'] ) ) {
							continue;
						}
						if ( is_string( $layout['label'] ) && 1 === preg_match( '/\p{L}/u', $layout['label'] ) ) {
							$texts[] = array(
								'o'     => $owner,
								'p'     => 'field/' . swiftling_escape_segment( $key ) . '/layouts/' . swiftling_escape_segment( (string) $layout['key'] ),
								'v'     => $layout['label'],
								'r'     => swiftling_rule_key( 'acf-field:layout', '', '' ),
								'kind'  => 'label',
								'place' => $place,
							);
						}
						if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
							$walk( $layout['sub_fields'], $place . ' › ' . $layout['label'] );
						}
					}
				}
			}
		};
		$walk( acf_get_fields( $group_key ), '' );
		return $texts;
	}

	/**
	 * Returns the texts among a field's settings that a visitor can read, see self::FIELD_TEXTS,
	 * and the default value of a text field, keyed by their path in the field group's object.
	 *
	 * @param array $field Field.
	 * @return array<string, array{0: string, 1: string}> Path => setting, text.
	 */
	private function settingTexts( array $field ): array {
		$key      = $this->originalKey( $field );
		$settings = array_keys( self::FIELD_TEXTS );
		if ( in_array( $field['type'], self::TEXT_TYPES, true ) ) {
			$settings[] = 'default_value';
		}
		$texts = array();
		foreach ( $settings as $setting ) {
			$value = isset( $field[ $setting ] ) ? $field[ $setting ] : '';
			if ( is_string( $value ) && 1 === preg_match( '/\p{L}/u', $value ) ) {
				$texts[ 'field/' . swiftling_escape_segment( $key ) . '/' . $setting ] = array( $setting, $value );
			}
		}
		return $texts;
	}

	/**
	 * Returns the kind of text a field's default value is: as its value would be.
	 *
	 * @param array $field Field.
	 */
	private function defaultKind( array $field ): string {
		return 'text' === $field['type'] ? 'text' : 'paragraph';
	}

	/**
	 * Returns the path of a choice's label in the field group's object.
	 *
	 * @param string $key   Field key.
	 * @param string $value Value of the choice.
	 */
	private function choicePath( string $key, string $value ): string {
		return 'field/' . swiftling_escape_segment( $key ) . '/choices/' . swiftling_escape_segment( $value );
	}

	/**
	 * Returns the key of the field as its field group defines it. A field shown through a clone has
	 * a key made of the clone's and its own; ACF keeps its own as "__key".
	 *
	 * @param array $field Field.
	 */
	private function originalKey( array $field ): string {
		return isset( $field['__key'] ) && is_string( $field['__key'] ) ? $field['__key'] : (string) $field['key'];
	}

	/**
	 * Returns the object under which the texts of a field's settings are kept: its field group,
	 * as "acf:<group key>". Empty when the field belongs to no group, as a field made in code
	 * without one.
	 *
	 * @param string $key Field key.
	 */
	private function groupOwner( string $key ): string {
		if ( ! isset( $this->groups[ $key ] ) ) {
			$owner = '';
			$field = $this->rawField( $key );
			for ( $depth = 0; $field && $depth < 10; $depth++ ) {
				$above = $field['parent'] ?? '';
				$group = $this->groupKey( $above );
				if ( '' !== $group ) {
					$owner = 'acf:' . $group;
					break;
				}
				$field = $this->rawField( $above );
			}
			if ( ! did_action( 'acf/init' ) ) {
				return $owner;
			}
			$this->groups[ $key ] = $owner;
		}
		return $this->groups[ $key ];
	}

	/**
	 * Returns a field as stored, without running ACF's filters for loaded fields, which include
	 * SwiftLing's own.
	 *
	 * @param int|string $id Field key, or the ID of its post.
	 * @return array|null
	 */
	private function rawField( $id ): ?array {
		if ( is_string( $id ) && acf_is_local_field( $id ) ) {
			$field = acf_get_local_field( $id );
		} elseif ( ( is_string( $id ) && acf_is_field_key( $id ) ) || ( is_numeric( $id ) && 'acf-field' === get_post_type( (int) $id ) ) ) {
			$field = acf_get_raw_field( $id );
		} else {
			$field = null;
		}
		return is_array( $field ) ? $field : null;
	}

	/**
	 * Returns the key of the field group a field's parent names, when the parent is a field group.
	 *
	 * @param int|string $above Parent of a field: the key or post ID of a field or a field group.
	 */
	private function groupKey( $above ): string {
		if ( is_string( $above ) && 0 === strpos( $above, 'group_' ) ) {
			return $above;
		}
		if ( is_numeric( $above ) && 'acf-field-group' === get_post_type( (int) $above ) ) {
			return (string) get_post_field( 'post_name', (int) $above );
		}
		return '';
	}

	/**
	 * Returns the field a field sits in: a repeater, group, flexible content or clone; null for a
	 * field at the top of its group.
	 *
	 * @param array $field Field.
	 */
	private function parentField( array $field ): ?array {
		$above = $field['parent'] ?? '';
		return '' === $this->groupKey( $above ) ? $this->rawField( $above ) : null;
	}

	/**
	 * Returns the field a stored value belongs to, from the key ACF stores next to the value.
	 *
	 * @param string $owner Object key: "post:<ID>", "term:<ID>" for a field of a term, or "options" for options pages.
	 * @param string $path  Path of the value: "meta/<name>…" or "option/<option name>…".
	 * @return array{0: array, 1: string, 2: string}|null The field, the path of the value inside the
	 *         field's value (empty for the whole value), and the value's name without the prefix of
	 *         an options page; null for a value of no field.
	 */
	private function storedField( string $owner, string $path ): ?array {
		$parts = explode( '/', $path, 3 );
		if ( ! isset( $parts[1] ) || ! in_array( $parts[0], array( 'meta', 'option' ), true ) ) {
			return null;
		}
		$name = rawurldecode( $parts[1] );
		$rest = isset( $parts[2] ) ? $parts[2] : '';

		if ( 'meta' === $parts[0] && ( 0 === strpos( $owner, 'post:' ) || 0 === strpos( $owner, 'term:' ) ) ) {
			$short = $name;
		} elseif ( 'option' === $parts[0] && 'options' === $owner ) {
			$short = $this->withoutPrefix( $name );
			if ( null === $short ) {
				return null;
			}
		} else {
			return null;
		}

		$cache = $owner . '|' . $name;
		if ( array_key_exists( $cache, $this->fields ) ) {
			$field = $this->fields[ $cache ];
		} else {
			$field = null;
			// ACF's own reference keys, as "_hero_title", hold no text and have no reference.
			if ( '_' !== substr( $name, 0, 1 ) ) {
				$reference = $this->raw( $owner, '_' . $name );
				$found     = '' === $reference ? null : $this->field( $reference );
				$field     = $found && isset( $found['type'] ) ? $found : null;
			}
			// Fields a theme registers in code exist only from acf/init on: what is not found before
			// then is looked up again.
			if ( did_action( 'acf/init' ) ) {
				$this->fields[ $cache ] = $field;
			}
		}
		return null === $field ? null : array( $field, $rest, $short );
	}

	/**
	 * Returns the field ACF stored a value for, by the key it stored next to the value. A field shown
	 * through a clone may be stored under a key made of the clone's and its own; its own is the last.
	 *
	 * @param string $reference Stored key, e.g. "field_abc123".
	 */
	private function field( string $reference ): ?array {
		if ( ! acf_is_field_key( $reference ) ) {
			return null;
		}
		$field = $this->rawField( $reference );
		if ( ! $field ) {
			$own   = strrpos( $reference, '_field_' );
			$field = false === $own ? null : $this->rawField( substr( $reference, $own + 1 ) );
		}
		return $field;
	}

	/**
	 * Returns a stored value of a post, a term or an option as it is stored, without SwiftLing.
	 *
	 * @param string $owner Object key: "post:<ID>", "term:<ID>" or "options".
	 * @param string $name  Meta key or option name.
	 */
	private function raw( string $owner, string $name ): string {
		$state          = &swiftling_state();
		$guard          = $state['guard'];
		$state['guard'] = true;
		try {
			if ( 'options' === $owner ) {
				$value = get_option( $name, '' );
			} elseif ( 0 === strpos( $owner, 'term:' ) ) {
				$value = get_term_meta( (int) substr( $owner, 5 ), $name, true );
			} else {
				$value = get_post_meta( (int) substr( $owner, 5 ), $name, true );
			}
		} finally {
			$state['guard'] = $guard;
		}
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Returns the name of an option an options page stores a value under, without the page's
	 * prefix: "footer_text" for "options_footer_text".
	 *
	 * @param string $option Option name.
	 * @return string|null Null for an option no options page stores.
	 */
	private function withoutPrefix( string $option ): ?string {
		$prefixes = $this->prefixes;
		if ( null === $prefixes ) {
			$prefixes = array( 'options_' );
			foreach ( $this->optionsPages() as $page ) {
				$prefixes[] = $page['post_id'] . '_';
			}
			$prefixes = array_values( array_unique( $prefixes ) );
			// The longest first, so "options_shop_" wins over "options_".
			usort(
				$prefixes,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			// Options pages are added on acf/init, after a translated view starts: kept only then.
			if ( did_action( 'acf/init' ) ) {
				$this->prefixes = $prefixes;
			}
		}
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) && strlen( $option ) > strlen( $prefix ) ) {
				return substr( $option, strlen( $prefix ) );
			}
		}
		return null;
	}

	/**
	 * Returns the options pages that store their values as options, keyed by menu slug.
	 *
	 * @return array<string, array>
	 */
	private function optionsPages(): array {
		if ( ! function_exists( 'acf_get_options_pages' ) || ! did_action( 'acf/init' ) ) {
			return array();
		}
		$pages = array();
		foreach ( (array) acf_get_options_pages() as $slug => $page ) {
			if ( ! is_array( $page ) || empty( $page['post_id'] ) || ! is_string( $page['post_id'] ) ) {
				continue;
			}
			$decoded = acf_decode_post_id( $page['post_id'] );
			if ( 'option' === $decoded['type'] ) {
				$pages[ (string) $slug ] = $page;
			}
		}
		return $pages;
	}

	/**
	 * Returns an options page that stores its values as options.
	 *
	 * @param string $slug Menu slug.
	 */
	private function optionsPage( string $slug ): ?array {
		$pages = $this->optionsPages();
		return isset( $pages[ $slug ] ) ? $pages[ $slug ] : null;
	}
}
