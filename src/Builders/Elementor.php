<?php
/**
 * Elementor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor and its widgets, classic and atomic.
 *
 * Checked in Elementor 4.3.3: Document::get_elements_data() reads the widgets of a page from the
 * _elementor_data meta with get_post_meta() (get_json_meta() in core/base/document.php), so the
 * probe finds their text without this class. Widget default texts come through gettext, or
 * through readDefaults() when the widget's code does not wrap them in it.
 */
final class Elementor extends Builder {
	/**
	 * Widgets that show some of their texts in the language a setting chooses, by widget name:
	 * the setting, whose options are English names of languages in lower case.
	 *
	 * Premium Addons' countdown takes the names of its units ("Days", "Hours") from a table in its
	 * script (LanguageObject in assets/frontend/min-js/countdown-translator.min.js), chosen with
	 * its Language setting. The fields of its Strings Translation section never reach the page:
	 * render() in widgets/premium-countdown.php reads them and leaves them unused. Checked in
	 * Premium Addons 4.11.110.
	 */
	private const LANGUAGE_SETTINGS = array(
		'premium-countdown-timer' => 'flip_language',
	);

	/**
	 * The languages those settings offer, by language code: every language of the countdown's
	 * table. A language without its own entry there shows in English, as the script falls back to
	 * it. On the front end Elementor leaves the options out of a control, so they cannot be read
	 * from the widget.
	 */
	private const LANGUAGE_NAMES = array(
		'ar' => 'arabic',
		'cs' => 'czech',
		'da' => 'danish',
		'de' => 'german',
		'en' => 'english',
		'es' => 'spanish',
		'fa' => 'persian',
		'fi' => 'finnish',
		'fr' => 'french',
		'he' => 'hebrew',
		'it' => 'italian',
		'ja' => 'japanese',
		'ko' => 'korean',
		'lv' => 'latvian',
		'nb' => 'norwegian',
		'nl' => 'dutch',
		'nn' => 'norwegian',
		'pl' => 'polish',
		'pt' => 'portuguese',
		'ro' => 'romanian',
		'ru' => 'russian',
		'sk' => 'slovak',
		'sv' => 'swedish',
		'th' => 'thai',
		'tr' => 'turkish',
		'zh' => 'chinese',
	);

	/**
	 * Whether Elementor declares a field as text, by rule key, as worked out so far.
	 *
	 * @var array<string, bool>
	 */
	private $declared = array();

	/**
	 * Returns the builder's ID.
	 */
	public function id(): string {
		return 'elementor';
	}

	/**
	 * Returns the builder's name.
	 */
	public function name(): string {
		return 'Elementor';
	}

	/**
	 * Whether Elementor is active.
	 */
	public function isActive(): bool {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Whether a post is built with Elementor, as Elementor itself tells.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		return $document && $document->is_built_with_elementor();
	}

	/**
	 * Returns the meta Elementor writes again from a page's data: its CSS, the rendered copy of its
	 * elements and the list of its assets. Checked in Elementor 4.3.3.
	 *
	 * @return string[]
	 */
	public function regeneratedMeta(): array {
		return array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets' );
	}

	/**
	 * Returns the address of Elementor's editor for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function editUrl( int $post_id ): ?string {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		return $document ? (string) $document->get_edit_url() : null;
	}

	/**
	 * Whether the request is the preview in Elementor's editor: the post's address with
	 * ?elementor-preview=ID, see Document::get_preview_url().
	 */
	public function isEditorPreview(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether the parameter is there; Elementor checks the rights.
		return isset( $_GET['elementor-preview'] );
	}

	/**
	 * Switches off Elementor's copy of rendered elements, and during a page check its CSS files.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		// Elementor 4 saves the rendered elements of each page in the _elementor_element_cache meta
		// (for a day by default) and prints that copy instead of rendering from _elementor_data.
		// See Document::print_elements() in core/base/document.php, checked in Elementor 4.3.1.
		add_filter( 'pre_option_elementor_element_cache_ttl', array( $this, 'disableElementCache' ) );

		if ( 'control' !== $mode ) {
			// First of all, before another plugin reads the settings: Elementor keeps the settings it
			// prepared for display, so a change after that would not show.
			add_action( 'elementor/frontend/before_render', array( $this, 'readDefaults' ), PHP_INT_MIN );
		}

		if ( 'translate' === $mode ) {
			add_action( 'elementor/frontend/before_render', array( $this, 'chooseLanguage' ), PHP_INT_MIN );
		}

		if ( $probe ) {
			// When the CSS file of a page is missing, Elementor writes it while the page renders, from
			// the data the probe marks: a font name stored with a widget ended up in the public file
			// with its marker. With the CSS printed in the page, no file is written. See
			// Base::use_external_file() in core/files/css/base.php, checked in Elementor 4.3.3.
			add_filter( 'pre_option_elementor_css_print_method', array( $this, 'printCssInPage' ) );
		}
	}

	/**
	 * Whether a value holds styles, which Elementor writes into CSS files.
	 *
	 * Atomic elements (Elementor 4) keep their styles next to their settings, and global classes
	 * keep theirs in posts of their own. When their CSS file is missing, Elementor writes it while
	 * the page renders (CSS_Files_Manager::get() in modules/atomic-widgets/styles), from the same
	 * data the probe reads: a font name in a local style and one in a global class both ended up
	 * in the public file with their markers. Checked in Elementor 4.3.3.
	 *
	 * @param string $path Path of the value inside its object.
	 */
	public function holdsNoText( string $path ): bool {
		return 0 === strpos( $path, 'meta/_elementor_global_class_data' )
			|| ( 0 === strpos( $path, 'meta/_elementor_data/' ) && 1 === preg_match( '~/#[^/]+/styles/~', $path ) );
	}

	/**
	 * Returns Elementor's setting value that switches its element cache off.
	 */
	public function disableElementCache(): string {
		return 'disable';
	}

	/**
	 * Returns Elementor's setting value that prints CSS in the page instead of a file.
	 */
	public function printCssInPage(): string {
		return 'internal';
	}

	/**
	 * Runs the default texts of a widget through SwiftLing, just before the widget renders.
	 *
	 * Elementor fills every field a page did not store with the default the widget's code declares
	 * (Controls_Stack::get_init_settings() in includes/base/controls-stack.php, and for the items
	 * of a repeater Control_Repeater::get_value()). Such a text reaches no place SwiftLing reads,
	 * unless the code wraps it in gettext: the content of a Royal Addons tab and the period beside
	 * an Essential Addons price showed in the original language. They belong to the widget, so they
	 * are shared by every page that shows them, as texts from code are.
	 *
	 * @param \Elementor\Element_Base $widget Element about to render; only widgets have default texts.
	 */
	public function readDefaults( $widget ): void {
		if ( ! $widget instanceof \Elementor\Widget_Base ) {
			return;
		}
		$stored   = (array) $widget->get_data( 'settings' );
		$settings = $widget->get_settings();
		$owner    = 'elementor:' . $widget->get_name();
		$source   = 'elementor-default:' . $widget->get_name();

		if ( method_exists( $widget, 'get_props_schema' ) ) {
			$this->readAtomicDefaults( $widget, $stored, $owner, $source );
		}

		foreach ( $widget->get_controls() as $name => $control ) {
			$type = $this->controlType( $control );

			if ( in_array( $type, $this->textTypes(), true ) ) {
				if ( ! array_key_exists( $name, $stored ) && isset( $settings[ $name ] ) && is_string( $settings[ $name ] ) && '' !== $settings[ $name ] ) {
					$value = swiftling_transform( $owner, 'default/' . $name, $source, $settings[ $name ] );
					if ( $value !== $settings[ $name ] ) {
						$widget->set_settings( $name, $value );
					}
				}
				continue;
			}

			if ( 'repeater' !== $type || empty( $settings[ $name ] ) || ! is_array( $settings[ $name ] ) ) {
				continue;
			}
			$items   = $settings[ $name ];
			$changed = false;
			foreach ( $items as $index => $item ) {
				$stored_item = isset( $stored[ $name ][ $index ] ) ? (array) $stored[ $name ][ $index ] : array();
				foreach ( isset( $control['fields'] ) ? (array) $control['fields'] : array() as $field ) {
					$field_name = isset( $field['name'] ) ? $field['name'] : '';
					if ( ! in_array( $this->controlType( $field ), $this->textTypes(), true ) || array_key_exists( $field_name, $stored_item ) || ! isset( $item[ $field_name ] ) || ! is_string( $item[ $field_name ] ) || '' === $item[ $field_name ] ) {
						continue;
					}
					$value = swiftling_transform( $owner, 'default/' . $name . '/' . $index . '/' . $field_name, $source, $item[ $field_name ] );
					if ( $value !== $item[ $field_name ] ) {
						$items[ $index ][ $field_name ] = $value;
						$changed                        = true;
					}
				}
			}
			if ( $changed ) {
				$widget->set_settings( $name, $items );
			}
		}
	}

	/**
	 * Runs the default addresses of an atomic widget (Elementor 4) through SwiftLing, such as the
	 * video a YouTube widget shows until another is chosen.
	 *
	 * Elementor fills a property a page did not store with the default of the widget's properties
	 * (Has_Atomic_Base::get_atomic_settings() resolves the stored settings against
	 * get_props_schema()). Their default texts come through gettext; an address does not. Checked
	 * in Elementor 4.3.3: the YouTube widget showed its default video in every language.
	 *
	 * @param \Elementor\Widget_Base $widget Atomic widget about to render.
	 * @param array                  $stored Settings the page stored for it.
	 * @param string                 $owner  Object key of the widget's defaults.
	 * @param string                 $source Source of its defaults, for rule keys.
	 */
	private function readAtomicDefaults( \Elementor\Widget_Base $widget, array $stored, string $owner, string $source ): void {
		foreach ( $widget::get_props_schema() as $name => $prop ) {
			if ( array_key_exists( $name, $stored ) || ! is_object( $prop ) || ! method_exists( $prop, 'get_default' ) ) {
				continue;
			}
			$default = $prop->get_default();
			if ( ! is_array( $default ) || ! isset( $default['value'] ) || ! is_string( $default['value'] ) || 1 !== preg_match( '~^https?://~i', $default['value'] ) ) {
				continue;
			}
			$value = swiftling_transform( $owner, 'default/' . $name, $source, $default['value'] );
			if ( $value !== $default['value'] ) {
				$default['value'] = $value;
				$widget->set_settings( $name, $default );
			}
		}
	}

	/**
	 * Sets the language setting of a widget to the language the page is shown in, when the widget
	 * offers that language. See self::LANGUAGE_SETTINGS and self::LANGUAGE_NAMES.
	 *
	 * @param \Elementor\Element_Base $widget Element about to render.
	 */
	public function chooseLanguage( $widget ): void {
		if ( ! $widget instanceof \Elementor\Widget_Base || ! isset( self::LANGUAGE_SETTINGS[ $widget->get_name() ] ) ) {
			return;
		}
		$code = strtolower( (string) strtok( swiftling_state()['locale'], '_' ) );
		if ( isset( self::LANGUAGE_NAMES[ $code ] ) ) {
			$widget->set_settings( self::LANGUAGE_SETTINGS[ $widget->get_name() ], self::LANGUAGE_NAMES[ $code ] );
		}
	}

	/**
	 * Whether Elementor declares a value as text: a text, textarea or WYSIWYG field of a widget,
	 * also inside a repeater, an atomic property that holds HTML, or a default text of a widget.
	 *
	 * @param string $rule Rule key: "meta:_elementor_data|heading|settings/title" or "elementor-default:heading||".
	 */
	public function declaresText( string $rule ): bool {
		if ( 0 === strpos( $rule, 'elementor-default:' ) ) {
			return true;
		}
		if ( 0 !== strpos( $rule, 'meta:_elementor_data|' ) || ! $this->isActive() ) {
			return false;
		}
		if ( ! isset( $this->declared[ $rule ] ) ) {
			list( , $type, $rel )    = array_pad( explode( '|', $rule, 3 ), 3, '' );
			$this->declared[ $rule ] = in_array( $this->fieldType( $type, $rel ), $this->textTypes(), true );
		}
		return $this->declared[ $rule ];
	}

	/**
	 * Whether a value comes from a code field of a widget, such as the HTML widget or a code
	 * snippet: stored with the page, or the field's default.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @param string $rule  Rule key of the value.
	 */
	public function isCode( string $owner, string $path, string $rule ): bool {
		if ( ! $this->isActive() ) {
			return false;
		}
		if ( 0 === strpos( $rule, 'meta:_elementor_data|' ) ) {
			list( , $type, $rel ) = array_pad( explode( '|', $rule, 3 ), 3, '' );
			return 0 === strpos( (string) $this->fieldType( $type, $rel ), 'code:' );
		}
		// A default of a widget, see readDefaults(): "default/<field>", or "default/<field>/<item>/<field>" in a repeater.
		if ( 0 === strpos( $owner, 'elementor:' ) && 1 === preg_match( '~^default/([^/]+)(?:/\d+/([^/]+))?$~', $path, $match ) ) {
			$rel = 'settings/' . $match[1] . ( isset( $match[2] ) ? '/*/' . $match[2] : '' );
			return 0 === strpos( (string) $this->fieldType( substr( $owner, 10 ), $rel ), 'code:' );
		}
		return false;
	}

	/**
	 * Field and property types that hold text in Elementor: text fields, and code fields that hold
	 * HTML, as a code snippet shows it. Code in CSS or JavaScript is no text.
	 *
	 * @return string[]
	 */
	public function textTypes(): array {
		return array( 'text', 'textarea', 'wysiwyg', 'code:html', 'html', 'html-v2', 'html-v3', 'escaped-html' );
	}

	/**
	 * Returns the type of a control, with the language of a code field: "text" or "code:html".
	 *
	 * @param array<string, mixed> $control Control or repeater field.
	 */
	private function controlType( array $control ): string {
		$type = isset( $control['type'] ) ? (string) $control['type'] : '';
		if ( 'code' === $type ) {
			return 'code:' . ( isset( $control['language'] ) ? (string) $control['language'] : 'html' );
		}
		return $type;
	}

	/**
	 * Returns the type Elementor declares for a field of a widget or element.
	 *
	 * @param string $type Widget or element name, e.g. "heading" or "e-heading".
	 * @param string $rel  Path of the field inside the element, e.g. "settings/title" or "settings/icon_list/STAR/text" with STAR as "*".
	 * @return string|null Field type, or null for anything that is not a declared field.
	 */
	public function fieldType( string $type, string $rel ): ?string {
		$parts = explode( '/', $rel );
		if ( '' === $type || 'settings' !== $parts[0] || ! isset( $parts[1] ) ) {
			return null;
		}

		$plugin  = \Elementor\Plugin::$instance;
		$element = $plugin->widgets_manager->get_widget_types( $type );
		if ( ! $element ) {
			$element = $plugin->elements_manager->get_element_types( $type );
		}
		if ( ! $element ) {
			return null;
		}

		// Atomic elements (Elementor 4) store each property as array( '$$type' => ..., 'value' => ... ).
		if ( method_exists( $element, 'get_props_schema' ) ) {
			$schema = $element::get_props_schema();
			if ( ! isset( $schema[ $parts[1] ] ) || ! isset( $parts[2] ) || 'value' !== $parts[2] || isset( $parts[3] ) ) {
				return null;
			}
			$prop = $schema[ $parts[1] ];
			// A union accepts several types, e.g. HTML or a dynamic value; the text type among them counts.
			if ( method_exists( $prop, 'get_prop_types' ) ) {
				foreach ( array_keys( $prop->get_prop_types() ) as $key ) {
					if ( in_array( $key, $this->textTypes(), true ) ) {
						return (string) $key;
					}
				}
			}
			return (string) $prop::get_key();
		}

		$controls = $element->get_controls();
		if ( ! isset( $controls[ $parts[1] ] ) ) {
			return null;
		}
		$control = $controls[ $parts[1] ];

		if ( 'repeater' === $control['type'] ) {
			if ( ! isset( $parts[3] ) || isset( $parts[4] ) || empty( $control['fields'] ) ) {
				return null;
			}
			foreach ( $control['fields'] as $field ) {
				if ( isset( $field['name'] ) && $field['name'] === $parts[3] ) {
					return $this->controlType( $field );
				}
			}
			return null;
		}

		return isset( $parts[2] ) ? null : $this->controlType( $control );
	}
}
