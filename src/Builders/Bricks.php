<?php
/**
 * Bricks: the theme and its builder.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks, a theme with a builder of its own. It keeps the elements of a page as a list in the post
 * meta "_bricks_page_content_2", and of a header or footer in "_bricks_page_header_2" and
 * "_bricks_page_footer_2". It reads them with get_post_meta() (Database::get_data() in
 * includes/database.php), so a page check finds their texts like any others, see sources.php.
 * Its templates are posts of the type "bricks_template", and its components are kept in the option
 * "bricks_components", which it reads with get_option().
 *
 * This class adds what the page check cannot find out by itself:
 *
 * - The builder runs on the front end, at the address of the page it edits. It is the editor of
 *   the original, see isEditorPreview(): opened at a language's address, it showed the translated
 *   texts and saved them over the original.
 * - Which fields hold text, from the type Bricks declares for each control, see fieldHoldsText(): a
 *   short text in lowercase is found, as " for you" after an animated headline, and a style, a CSS
 *   class or executable code is never marked.
 * - Texts that show only after a visitor sends a form, which the editor lists, see listTexts().
 * - Which templates are the header and the footer, see sitePart(), and where a page's content is
 *   while a page check runs: Bricks prints it without the_content.
 * - Requests a page sends later, which answer in the page's language: sending a form, a popup and
 *   the next page of a query loop.
 * - Breadcrumbs, which print stored titles, and dynamic data tags, which machine translation keeps.
 *
 * Checked on Bricks 2.4.4 with a Starter licence: every element of the theme, templates of every
 * kind, a component, a form, templates of Bricks' library. Its WooCommerce templates were not
 * checked.
 */
class Bricks extends Builder {
	/**
	 * Meta keys of the elements of a post: its content, or a header's or footer's.
	 */
	private const DATA_KEYS = array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' );

	/**
	 * Option that holds every component.
	 */
	private const COMPONENTS = 'bricks_components';

	/**
	 * Types of controls that hold settings, never text: choices, numbers, colours, icons and styles.
	 */
	private const SETTING_TYPES = array( 'select', 'number', 'checkbox', 'color', 'icon', 'typography', 'border', 'box-shadow', 'spacing', 'dimensions', 'background', 'gradient', 'filters', 'transform', 'align-items', 'justify-content', 'direction', 'text-align', 'text-shadow', 'separator', 'info', 'apply', 'datepicker' );

	/**
	 * Types of controls Bricks declares as text, the same it hands to WPML for translation
	 * (Wpml::get_translatable_control_types() in includes/integrations/wpml/wpml.php).
	 */
	private const TEXT_TYPES = array( 'text', 'textarea', 'editor' );

	/**
	 * Controls of the text types that hold a setting all the same: an address, an email address, an
	 * ID, a selector, coordinates, a key. Every such control of the elements of Bricks 2.4.4 that is
	 * not on the style tab, which holds no text anyway. They are left to the page check, as values
	 * no builder declares.
	 */
	private const SETTING_KEYS = array( 'actionURL', 'additionalParams', 'anchorId', 'center', 'confirmationEmailTo', 'confirmationFromEmail', 'confirmationReplyToEmail', 'contentSelector', 'coordinates', 'countFrom', 'countTo', 'createPostMeta', 'cursorChar', 'customFileName', 'customTag', 'dynamicData', 'emailBcc', 'emailToCustom', 'errorTileUrl', 'external', 'fileUrl', 'fromEmail', 'googleMapId', 'headingSelectors', 'homeURL', 'href', 'ignoreSelector', 'latitude', 'lightboxId', 'linkRel', 'longitude', 'mapCenterLat', 'mapCenterLng', 'megaMenuSelector', 'megaMenuSelectorVertical', 'metaKey', 'nameTag', 'redirect', 'replyToEmail', 'schemaProperties', 'schemaReviewProperties', 'schemaType', 'searchOverlayTitleTag', 'shortcode', 'singularStructure', 'sources', 'targetSelector', 'titleTag', 'toggleAttribute', 'toggleSelector', 'toggleValue', 'updatePostMeta', 'url', 'useDynamicData', 'vimeoHash', 'vimeoId', 'webhooks', 'youTubeId', 'youtubeCcLang', 'youtubeLanguage', '_animationDelay', '_animationDurationCustom' );

	/**
	 * Fields of a form's fields that hold a setting: the attributes of the input, a format, a time,
	 * file types, sizes. Others of the same name in other elements hold text, as the format of a
	 * countdown, "%D days".
	 */
	private const FORM_FIELD_SETTINGS = array( 'name', 'autocomplete', 'spellcheck', 'pattern', 'l10n', 'format', 'minTime', 'maxTime', 'fileUploadAllowedTypes', 'fileUploadStorageDirectory', 'tinyMceImagesFileTypes', 'tinyMceHeight', 'tinyMceWidth', 'passwordMainFieldId' );

	/**
	 * Types of properties of a component that hold text.
	 */
	private const PROPERTY_TEXT_TYPES = array( 'text', 'textarea', 'editor', 'rich-text' );

	/**
	 * A dynamic data tag, such as {post_title}, {post_date:F j} or {echo:greet('{post_title}')}, and a
	 * field of a form in an email, such as {{all_fields}}. Bricks replaces them on the page.
	 */
	private const DYNAMIC_DATA = '~\{\{[^{}]+\}\}|\{[a-z_][a-z0-9_\-]*(?::(?:[^{}]|\{[^{}]*\})*)?\}~i';

	/**
	 * A unit of a countdown, such as %D in "%D days", which its script replaces.
	 */
	private const COUNTDOWN_UNITS = '~(?<![%\w])%[DHMSdhms](?!\w)~';

	/**
	 * Elements of posts by post, meta key and element ID, as stored.
	 *
	 * @var array<string, array<string, array>>
	 */
	private $data = array();

	/**
	 * Whether printPopupsInPage() reads a value itself.
	 *
	 * @var bool
	 */
	private $reading = false;

	/**
	 * Whether each content area Bricks opened while a page check runs is the checked post's own.
	 *
	 * @var bool[]
	 */
	private $areas = array();

	/**
	 * Returns the builder's ID.
	 */
	public function id(): string {
		return 'bricks';
	}

	/**
	 * Returns the builder's name.
	 */
	public function name(): string {
		return 'Bricks';
	}

	/**
	 * Whether Bricks is the theme, or the parent of a child theme. Known before the theme loads:
	 * translation starts on setup_theme, before the theme's own code runs. Read as get_template()
	 * reads it, but past SwiftLing's filter of options, which asks the active builders itself.
	 */
	public function isActive(): bool {
		return 'bricks' === apply_filters( 'template', swiftling_raw_option( 'template', '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as get_template() applies it.
	}

	/**
	 * Whether a post is built with Bricks, as Bricks itself tells: its editor mode.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool {
		return 'bricks' === get_post_meta( $post_id, '_bricks_editor_mode', true );
	}

	/**
	 * Returns the address of Bricks' builder for a post: its page with ?bricks=run.
	 *
	 * @param int $post_id Post ID.
	 */
	public function editUrl( int $post_id ): ?string {
		return class_exists( '\Bricks\Helpers' ) ? (string) \Bricks\Helpers::get_builder_edit_link( $post_id ) : add_query_arg( 'bricks', 'run', (string) get_permalink( $post_id ) );
	}

	/**
	 * Whether the request is Bricks' builder: its window or the preview inside it, both on the front
	 * end, with ?bricks=run (bricks_is_builder() in functions.php). The constant that names the
	 * parameter is not there yet when translation starts.
	 */
	public function isEditorPreview(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether the parameter is there; Bricks checks the rights.
		return ! is_admin() && isset( $_GET['bricks'] );
	}

	/**
	 * Hooks what a site with Bricks needs on every request.
	 */
	public function register(): void {
		add_filter( 'bricks/breadcrumbs/items', array( $this, 'translateBreadcrumbs' ) );
		add_filter( 'bricks/get_builder_edit_link', array( $this, 'originalEditLink' ), 10, 2 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepDynamicData' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'forgetData' ), 10, 3 );
		}
	}

	/**
	 * During a page check, marks where the checked page's content is, as swiftling_mark_content_area()
	 * does for the_content: Bricks prints a page's elements itself (Frontend::render_content()).
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		if ( 'control' !== $mode ) {
			add_action( 'template_redirect', array( $this, 'readComponents' ) );
		}
		if ( $probe ) {
			add_filter( 'bricks/content/html_after_begin', array( $this, 'openContent' ), 10, 2 );
			add_filter( 'bricks/content/html_before_end', array( $this, 'closeContent' ) );
			// After SwiftLing's own filter of post meta, which hands over a marked value.
			add_filter( 'get_post_metadata', array( $this, 'printPopupsInPage' ), 101, 4 );
		}
	}

	/**
	 * During a page check, lets Bricks print a popup it would load only when it opens, as any other
	 * popup: its texts are on the checked page then. Bricks renders such a popup with the page all
	 * the same, but keeps it back and loads it later from its REST API (Popups::generate_popup_html()
	 * and Api::render_popup_content()), so the check never saw its texts.
	 *
	 * @param mixed $check     Value a filter before this one returned, or null.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $single    Whether one value was asked for.
	 * @return mixed
	 */
	public function printPopupsInPage( $check, $object_id, $meta_key, $single ) {
		if ( $this->reading || '_bricks_template_settings' !== $meta_key || ! $single || 'bricks_template' !== get_post_type( (int) $object_id ) ) {
			return $check;
		}
		if ( null === $check ) {
			// get_metadata_raw() runs this filter again, and SwiftLing's, which the guard keeps out.
			$state          = &swiftling_state();
			$guard          = $state['guard'];
			$state['guard'] = true;
			$this->reading  = true;
			try {
				$settings = get_metadata_raw( 'post', (int) $object_id, $meta_key, true );
			} finally {
				$state['guard'] = $guard;
				$this->reading  = false;
			}
		} else {
			$settings = is_array( $check ) ? reset( $check ) : $check;
		}
		if ( ! is_array( $settings ) || ! isset( $settings['popupAjax'] ) ) {
			return $check;
		}
		unset( $settings['popupAjax'] );
		return array( $settings );
	}

	/**
	 * Reads the components again once the page renders. Bricks reads them when the theme loads, before
	 * a page check counts the texts of options (Database::get_global_data() in includes/database.php),
	 * and keeps them, so the texts of a component were never found. Bricks sets them the same way when
	 * it saves them (Component_Repository::update_all()). Components a network shares from its main
	 * site stay as Bricks read them.
	 */
	public function readComponents(): void {
		if ( ! class_exists( '\Bricks\Database' ) || ! isset( \Bricks\Database::$global_data['components'] ) || ( is_multisite() && defined( 'BRICKS_MULTISITE_USE_MAIN_SITE_COMPONENTS' ) && BRICKS_MULTISITE_USE_MAIN_SITE_COMPONENTS ) ) {
			return;
		}
		\Bricks\Database::$global_data['components'] = get_option( self::COMPONENTS, array() );
	}

	/**
	 * Opens the content area of the checked post. The template of a single post is printed the same
	 * way, but its texts are the template's, not the post's content.
	 *
	 * @param mixed $html     What Bricks prints at the start of the area.
	 * @param mixed $elements Elements Bricks prints in it.
	 * @return mixed
	 */
	public function openContent( $html, $elements ) {
		$own           = $this->isOwnContent( $elements );
		$this->areas[] = $own;
		return $own ? $html . '<!--swiftling:content-->' : $html;
	}

	/**
	 * Closes what openContent() opened.
	 *
	 * @param mixed $html What Bricks prints at the end of the area.
	 * @return mixed
	 */
	public function closeContent( $html ) {
		return array_pop( $this->areas ) ? '<!--/swiftling:content-->' . $html : $html;
	}

	/**
	 * Whether elements Bricks prints are those of the checked post, or, when a template is checked, of
	 * the post the page shows: one of them is.
	 *
	 * @param mixed $elements Elements.
	 */
	private function isOwnContent( $elements ): bool {
		$post = (int) swiftling_state()['probe'];
		if ( -1 === $post ) {
			$post = (int) get_queried_object_id();
		}
		if ( $post <= 0 || ! is_array( $elements ) ) {
			return false;
		}
		$own = $this->postData( $post, '_bricks_page_content_2' );
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && isset( $own[ (string) $element['id'] ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns which part of every page a template is: a template of the type header or footer.
	 * Which of several headers a page shows is decided by their conditions; each is listed.
	 *
	 * @param string $owner Object key.
	 * @return string header, footer, or empty.
	 */
	public function sitePart( string $owner ): string {
		if ( 0 !== strpos( $owner, 'post:' ) ) {
			return '';
		}
		$post_id = (int) substr( $owner, 5 );
		if ( 'bricks_template' !== get_post_type( $post_id ) ) {
			return '';
		}
		$type = (string) get_post_meta( $post_id, '_bricks_template_type', true );
		return in_array( $type, array( 'header', 'footer' ), true ) ? $type : '';
	}

	/**
	 * Whether Bricks declares a value as text, from the control it belongs to: a text, textarea or
	 * rich text control (true), a style, a choice, a number or code that runs (false). A text control
	 * that holds an address, an ID or another setting, see SETTING_KEYS, and anything else are left
	 * to the page check (null).
	 *
	 * Covers the elements of posts, the elements and property defaults of components, and what a
	 * page sets for a component it shows.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		$field = $this->field( $owner, $path );
		return null === $field ? null : $field['text'];
	}

	/**
	 * Whether a value comes from a code control that holds HTML, as the HTML element: the editor shows
	 * it as code. Code that runs, PHP, CSS or JavaScript, is never marked, see fieldHoldsText().
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @param string $rule  Rule key of the value.
	 */
	public function isCode( string $owner, string $path, string $rule ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The path says it.
		$field = $this->field( $owner, $path );
		return null !== $field && 'code' === ( $field['control']['type'] ?? '' );
	}

	/**
	 * Returns what Bricks calls the field of a value, as "Form › Success message" or
	 * "Form › Fields 1 › Label".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		$field = $this->field( $owner, $path );
		if ( null === $field || ! isset( $field['labels'] ) ) {
			return '';
		}
		return implode( ' › ', $field['labels'] );
	}

	/**
	 * Returns the texts of a post's elements that Bricks declares as text and shows in its builder for
	 * the element's settings: also those no page shows until a visitor does something, as the message
	 * after a form is sent and the email it sends. The editor lists those the page check did not find,
	 * see swiftling_unshown_field_texts().
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 * @return array<int, array{o: string, p: string, v: string, r: string, kind: string, place: string}>
	 */
	public function listTexts( array $subject ): array {
		if ( 'post' !== ( $subject['kind'] ?? '' ) || ! ( $subject['post'] ?? null ) instanceof \WP_Post ) {
			return array();
		}
		$post_id = (int) $subject['post']->ID;
		$leaves  = swiftling_collect(
			static function () use ( $post_id ): void {
				foreach ( self::DATA_KEYS as $key ) {
					get_post_meta( $post_id, $key, true );
				}
			}
		);

		$texts = array();
		foreach ( $leaves as $leaf ) {
			$field = $this->field( $leaf['o'], $leaf['p'] );
			if ( null === $field || true !== $field['text'] || ! $field['shown'] ) {
				continue;
			}
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'text',
				'place' => implode( ' › ', $field['labels'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns the action of admin-ajax.php that receives a form of Bricks.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'bricks_form_submit' );
	}

	/**
	 * Returns the routes of Bricks' REST API a page asks later: the content of a popup loaded when it
	 * opens, and the results of a query loop on its next page, after a filter or with infinite scroll.
	 * They render elements of the page, so they answer in its language. The builder's route that
	 * renders an element stays in the original.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/bricks/v1/(?:load_popup_content|load_query_page|query_result)/?$#' );
	}

	/**
	 * Puts the translated titles in Bricks' breadcrumbs: they print the stored title of the page and
	 * its parents, esc_html( $post->post_title ), past the_title (includes/elements/breadcrumbs.php,
	 * Bricks 2.4.4). The names of terms come through get_term(), which SwiftLing translates.
	 *
	 * @param mixed $items Items, each the HTML of a link or of the current page.
	 * @return mixed
	 */
	public function translateBreadcrumbs( $items ) {
		$post = get_queried_object();
		if ( ! is_array( $items ) || 'translate' !== swiftling_mode() || ! $post instanceof \WP_Post ) {
			return $items;
		}
		$pairs = array();
		foreach ( array_merge( array( $post->ID ), get_post_ancestors( $post ) ) as $id ) {
			$stored = (string) get_post_field( 'post_title', $id, 'raw' );
			$shown  = (string) swiftling_filter_title( $stored, (int) $id );
			if ( '' !== $stored && $shown !== $stored ) {
				$pairs[ '>' . esc_html( $stored ) . '<' ] = '>' . esc_html( $shown ) . '<';
			}
		}
		if ( ! $pairs ) {
			return $items;
		}
		foreach ( $items as $index => $item ) {
			if ( is_string( $item ) ) {
				$items[ $index ] = strtr( $item, $pairs );
			}
		}
		return $items;
	}

	/**
	 * Points "Edit with Bricks" on a translated page to the original's address. The builder edits the
	 * original wherever it opens, see isEditorPreview(); at the original's address the page it shows
	 * is that original too. A separate copy is a post of its own and keeps its address.
	 *
	 * @param mixed $link    Address of the builder.
	 * @param mixed $post_id Post the builder edits.
	 * @return mixed
	 */
	public function originalEditLink( $link, $post_id ) {
		$post_id = (int) $post_id;
		if ( 'translate' !== swiftling_mode() || ! $post_id || '' !== (string) get_post_meta( $post_id, SWIFTLING_COPY_LANG, true ) ) {
			return $link;
		}
		$original = (string) swiftling_in_link_language(
			'',
			static function () use ( $post_id ) {
				return swiftling_unlocalized_url(
					static function () use ( $post_id ) {
						return get_permalink( $post_id );
					}
				);
			}
		);
		return '' === $original ? $link : add_query_arg( 'bricks', 'run', swiftling_language_url( $original, '' ) );
	}

	/**
	 * Keeps Bricks' dynamic data tags and the units of a countdown as they are when texts go to
	 * machine translation: Bricks replaces them on the page, so a translated tag would show nothing.
	 * Other texts keep such parts too, which a service would only change.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepDynamicData( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['text'] ) || ! is_string( $item['text'] ) ) {
				continue;
			}
			preg_match_all( self::DYNAMIC_DATA, $item['text'], $tags );
			preg_match_all( self::COUNTDOWN_UNITS, $item['text'], $units );
			$found = array_merge( $tags[0], $units[0] );
			if ( $found ) {
				$keep                  = isset( $item['keep'] ) && is_array( $item['keep'] ) ? $item['keep'] : array();
				$items[ $key ]['keep'] = array_values( array_unique( array_merge( $keep, $found ) ) );
			}
		}
		return $items;
	}

	/**
	 * Forgets the elements of a post read in this request, once its meta changed.
	 *
	 * @param mixed $meta_ids  Meta IDs.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 */
	public function forgetData( $meta_ids, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, self::DATA_KEYS, true ) ) {
			unset( $this->data[ (int) $object_id . '|' . $meta_key ] );
		}
	}

	/**
	 * Returns an element as Bricks declares it: its label, its controls and the groups of controls.
	 *
	 * @param string $name Element name, e.g. "heading".
	 * @return array|null Null when Bricks does not know it, or has not loaded its elements yet.
	 */
	protected function element( string $name ): ?array {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return null;
		}
		$element = \Bricks\Elements::get_element( array( 'name' => $name ) );
		return is_array( $element ) && isset( $element['controls'] ) && is_array( $element['controls'] ) ? $element : null;
	}

	/**
	 * Returns what Bricks declares about the field of a value: whether it holds text, its control,
	 * the labels Bricks shows for it, and whether the builder shows it with the element's settings.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return array{text: bool|null, control: array, labels: string[], shown: bool}|null Null for a
	 *         value that is not in a field of an element or a component.
	 */
	private function field( string $owner, string $path ): ?array {
		if ( 'options' === $owner && 1 === preg_match( '~^option/' . self::COMPONENTS . '/#([^/]+)/(?:elements/#([^/]+)/settings/(.+)|properties/#([^/]+)/default)$~', $path, $found ) ) {
			$component = $this->component( $found[1] );
			if ( null === $component ) {
				return null;
			}
			if ( isset( $found[4] ) && '' !== $found[4] ) {
				return $this->property( $component, $found[4] );
			}
			foreach ( isset( $component['elements'] ) && is_array( $component['elements'] ) ? $component['elements'] : array() as $element ) {
				if ( is_array( $element ) && isset( $element['id'] ) && (string) $element['id'] === $found[2] ) {
					return $this->setting( $element, $found[3] );
				}
			}
			return null;
		}

		if ( 0 !== strpos( $owner, 'post:' ) || 1 !== preg_match( '~^meta/(_bricks_page_(?:content|header|footer)_2)/#([^/]+)/(settings|properties)/(.+)$~', $path, $found ) ) {
			return null;
		}
		$data    = $this->postData( (int) substr( $owner, 5 ), $found[1] );
		$element = isset( $data[ $found[2] ] ) ? $data[ $found[2] ] : null;
		if ( null === $element ) {
			return null;
		}
		if ( 'settings' === $found[3] ) {
			return $this->setting( $element, $found[4] );
		}
		// What a page sets for a property of a component it shows.
		$component = isset( $element['cid'] ) ? $this->component( (string) $element['cid'] ) : null;
		return null !== $component && false === strpos( $found[4], '/' ) ? $this->property( $component, $found[4] ) : null;
	}

	/**
	 * Returns what Bricks declares about a setting of an element, see field().
	 *
	 * @param array  $element Element as stored: name and settings.
	 * @param string $rel     Path of the value inside the settings, e.g. "text",
	 *                        "fields/#abc123/label" or "_typography/font-family".
	 * @return array|null
	 */
	private function setting( array $element, string $rel ): ?array {
		$name   = isset( $element['name'] ) ? (string) $element['name'] : '';
		$config = '' === $name ? null : $this->element( $name );
		$parts  = explode( '/', $rel );
		// A setting for a breakpoint or a state, such as "_padding:tablet_portrait", is the control's.
		$key     = (string) strtok( $parts[0], ':' );
		$control = null !== $config && isset( $config['controls'][ $key ] ) && is_array( $config['controls'][ $key ] ) ? $config['controls'][ $key ] : null;
		if ( null === $control ) {
			return null;
		}
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$labels   = array( isset( $config['label'] ) ? (string) $config['label'] : $name );
		$shown    = $this->meets( $control['required'] ?? null, $settings ) && $this->meets( $config['controlGroups'][ $control['group'] ?? '' ]['required'] ?? null, $settings );
		$style    = isset( $control['css'] ) || 'style' === ( $control['tab'] ?? '' );

		if ( 'repeater' === ( $control['type'] ?? '' ) && ! $style ) {
			$field = 3 === count( $parts ) && isset( $control['fields'][ $parts[2] ] ) && is_array( $control['fields'][ $parts[2] ] ) ? $control['fields'][ $parts[2] ] : null;
			if ( null === $field ) {
				return null;
			}
			$group    = $this->controlLabel( $config, $control );
			$labels[] = trim( ( '' === $group ? $key : $group ) . ' ' . $this->itemNumber( $settings[ $key ] ?? null, $parts[1] ) );
			$labels[] = isset( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : $parts[2];
			$text     = $this->holdsText( $field, $parts[2], $this->valueAt( $settings, $parts ) );
			if ( true === $text && 'form' === $name && in_array( $parts[2], self::FORM_FIELD_SETTINGS, true ) ) {
				$text = null;
			}
			return array(
				'text'    => $text,
				'control' => $field,
				'labels'  => $labels,
				'shown'   => $shown,
			);
		}

		if ( '' !== $this->controlLabel( $config, $control ) ) {
			$labels[] = $this->controlLabel( $config, $control );
		}
		if ( $style ) {
			$text = false;
		} elseif ( 1 === count( $parts ) ) {
			$text = $this->holdsText( $control, $key, $settings[ $parts[0] ] ?? null );
		} else {
			// The text shown when a query loop finds nothing, inside the query's settings, as Bricks
			// hands it to WPML (Wpml::process_query_no_results_text()).
			$text = 'query' === ( $control['type'] ?? '' ) && array( 'no_results_text' ) === array_slice( $parts, 1 ) ? true : null;
		}
		return array(
			'text'    => $text,
			'control' => $control,
			'labels'  => $labels,
			'shown'   => $shown,
		);
	}

	/**
	 * Whether a control holds text, see fieldHoldsText(). A value that is only dynamic data, such as
	 * "{post_title}" in the heading of a query loop, holds nothing to translate: Bricks replaces it
	 * with a text kept elsewhere, translated where it is kept. It is left to the page check, which
	 * leaves it alone, as it did before this class declared the control.
	 *
	 * @param array  $control Control or field of a repeater.
	 * @param string $key     Its key.
	 * @param mixed  $value   The stored value.
	 */
	private function holdsText( array $control, string $key, $value ): ?bool {
		if ( is_string( $value ) && 1 !== preg_match( '/\p{L}/u', (string) preg_replace( self::DYNAMIC_DATA, '', $value ) ) ) {
			return null;
		}
		$type = isset( $control['type'] ) ? (string) $control['type'] : '';
		if ( isset( $control['css'] ) || in_array( $type, self::SETTING_TYPES, true ) ) {
			return false;
		}
		if ( 'code' === $type ) {
			// HTML is what visitors read, as the HTML element; PHP, CSS and JavaScript run, and Bricks
			// runs signed code only, which a marker would break.
			return false !== strpos( (string) ( $control['mode'] ?? '' ), 'html' ) ? null : false;
		}
		if ( ! in_array( $type, self::TEXT_TYPES, true ) || in_array( $key, self::SETTING_KEYS, true ) ) {
			return null;
		}
		return true;
	}

	/**
	 * Returns the value at a path inside an element's settings, with an item of a list named by its
	 * ID, as "#abc123", or by its position.
	 *
	 * @param array    $settings Settings.
	 * @param string[] $parts    Path segments.
	 * @return mixed Null when nothing is there.
	 */
	private function valueAt( array $settings, array $parts ) {
		$value = $settings;
		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			if ( '#' === substr( $part, 0, 1 ) ) {
				$found = null;
				foreach ( $value as $item ) {
					if ( is_array( $item ) && isset( $item['id'] ) && '#' . $item['id'] === $part ) {
						$found = $item;
						break;
					}
				}
				$value = $found;
				continue;
			}
			$value = $value[ $part ] ?? null;
		}
		return $value;
	}

	/**
	 * Returns what Bricks declares about a property of a component, see field().
	 *
	 * @param array  $component Component as stored.
	 * @param string $id        Property ID.
	 * @return array|null
	 */
	private function property( array $component, string $id ): ?array {
		foreach ( isset( $component['properties'] ) && is_array( $component['properties'] ) ? $component['properties'] : array() as $property ) {
			if ( is_array( $property ) && isset( $property['id'] ) && (string) $property['id'] === $id ) {
				return array(
					'text'    => in_array( $property['type'] ?? '', self::PROPERTY_TEXT_TYPES, true ) ? true : null,
					'control' => $property,
					'labels'  => array( isset( $property['label'] ) ? (string) $property['label'] : $id ),
					'shown'   => true,
				);
			}
		}
		return null;
	}

	/**
	 * Returns the label Bricks shows for a control: its own, or its group's title. Many controls of
	 * an element's main text have neither, as the text of a heading: the element names it.
	 *
	 * @param array|null $config  Element.
	 * @param array      $control Control.
	 * @return string Empty when Bricks shows none.
	 */
	private function controlLabel( ?array $config, array $control ): string {
		if ( isset( $control['label'] ) && '' !== (string) $control['label'] ) {
			return wp_strip_all_tags( (string) $control['label'] );
		}
		$group = (string) ( $control['group'] ?? '' );
		return isset( $config['controlGroups'][ $group ]['title'] ) ? wp_strip_all_tags( (string) $config['controlGroups'][ $group ]['title'] ) : '';
	}

	/**
	 * Returns the number of an item of a repeater, counted from 1, as the builder lists it.
	 *
	 * @param mixed  $items   Items of the repeater.
	 * @param string $segment Path segment of the item: its ID as "#abc123", or its position.
	 */
	private function itemNumber( $items, string $segment ): string {
		if ( '#' !== substr( $segment, 0, 1 ) ) {
			return (string) ( (int) $segment + 1 );
		}
		$number = 0;
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			++$number;
			if ( is_array( $item ) && isset( $item['id'] ) && '#' . $item['id'] === $segment ) {
				return (string) $number;
			}
		}
		return '';
	}

	/**
	 * Whether an element's settings meet a condition Bricks sets for showing a control in its builder,
	 * as array( 'actions', '=', 'email' ), or several. A condition on something other than the
	 * element, such as the theme's settings, counts as met.
	 *
	 * @param mixed $rule     Condition.
	 * @param array $settings Settings of the element.
	 */
	private function meets( $rule, array $settings ): bool {
		if ( ! is_array( $rule ) || ! $rule ) {
			return true;
		}
		if ( is_array( reset( $rule ) ) ) {
			foreach ( $rule as $one ) {
				if ( ! $this->meets( $one, $settings ) ) {
					return false;
				}
			}
			return true;
		}
		$rule = array_values( $rule );
		if ( isset( $rule[3] ) || ! isset( $rule[0], $rule[1] ) || ! is_string( $rule[0] ) ) {
			return true;
		}
		$set    = $settings[ $rule[0] ] ?? '';
		$has    = array_map( 'strval', is_array( $set ) ? $set : array( $set ) );
		$wanted = array_map( 'strval', is_array( $rule[2] ?? '' ) ? $rule[2] : array( $rule[2] ?? '' ) );
		$match  = (bool) array_intersect( $has, $wanted );
		if ( '=' === $rule[1] ) {
			return $match;
		}
		return '!=' === $rule[1] ? ! $match : true;
	}

	/**
	 * Returns the elements of a post kept under a meta key, by ID, as stored: SwiftLing's own filters
	 * stay out, as this runs while they judge a value of the same meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return array<string, array>
	 */
	private function postData( int $post_id, string $key ): array {
		$cache = $post_id . '|' . $key;
		if ( ! isset( $this->data[ $cache ] ) ) {
			$state          = &swiftling_state();
			$guard          = $state['guard'];
			$state['guard'] = true;
			try {
				$stored = get_metadata_raw( 'post', $post_id, $key, true );
			} finally {
				$state['guard'] = $guard;
			}
			$this->data[ $cache ] = array();
			foreach ( is_array( $stored ) ? $stored : array() as $element ) {
				if ( is_array( $element ) && isset( $element['id'] ) ) {
					$this->data[ $cache ][ (string) $element['id'] ] = $element;
				}
			}
		}
		return $this->data[ $cache ];
	}

	/**
	 * Returns a component as stored.
	 *
	 * @param string $id Component ID.
	 * @return array|null
	 */
	private function component( string $id ): ?array {
		$components = swiftling_raw_option( self::COMPONENTS, array() );
		foreach ( is_array( $components ) ? $components : array() as $component ) {
			if ( is_array( $component ) && isset( $component['id'] ) && (string) $component['id'] === $id ) {
				return $component;
			}
		}
		return null;
	}
}
