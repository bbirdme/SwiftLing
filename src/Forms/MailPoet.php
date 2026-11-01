<?php
/**
 * MailPoet: its subscription forms in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * MailPoet. Its forms are rows of its own table, "mailpoet_forms": the blocks of a form, with the labels,
 * descriptions, texts and the names of the lists a visitor reads, in its body, and the message after
 * subscribing in its settings. The plugin reads a form with its own database layer and renders it without
 * a filter on its texts, so SwiftLing translates the HTML it rendered: for each of its forms on a page, the
 * blocks are rendered again from their texts in the mode of the moment and put in place of those the
 * plugin rendered, see translateMarkup(). The plugin renders the same blocks the same way every time, so
 * the HTML it printed is found whole; where it is not, as when another plugin changed it, the form stays as
 * it is. Its forms are printed in three places: a shortcode, a block or a widget, which hand their HTML to
 * the filter mailpoet_form_widget_post_process; below a post's content, at the_content; and as a popup, a
 * bar or a slide-in at the foot of the page, at wp_footer.
 *
 * The value of a choice of a custom field is what the plugin keeps for a subscriber, so the choices stay
 * as they are. The names of the lists a visitor chooses from are the plugin's lists, not texts of the
 * form, and stay as they are too.
 *
 * A form is sent to admin-ajax.php with the action "mailpoet", or without JavaScript to admin-post.php
 * with the action "mailpoet_subscription_form": both answered in the language of the page, see
 * sendingActions(), so the errors and the email asking to confirm the subscription, the plugin's own
 * texts, are in the language of the page. An email the site's owner rewrote in the plugin stays as written.
 *
 * Checked against the code of MailPoet 5.44 (the free plugin), in tests/MailPoetTest.php, and by hand on a
 * site with the plugin.
 */
class MailPoet extends TableProvider {
	/**
	 * The parameters of a block that hold text a visitor reads. The others are settings, and "value" is
	 * the value a field is filled with, which the plugin keeps.
	 */
	const BLOCK_TEXTS = array( 'label', 'description', 'text', 'content' );

	/**
	 * The names the plugin makes up anew each time it renders a block: the place of its errors, with
	 * random letters, and the IDs of the choices of lists, radio buttons and checkboxes, counted through
	 * the request.
	 */
	const MADE_UP = 'mailpoet_error_[A-Za-z0-9]+|mailpoet_(?:segment_choice|segment|radio|checkbox)_\d+';

	/**
	 * Whether what is printed at the foot of the page is being kept, to translate the plugin's forms in it.
	 *
	 * @var bool
	 */
	private $footer = false;

	/**
	 * The forms of the site, by ID, read once a request.
	 *
	 * @var array<int, array{id: int, title: string, body: array, settings: array}>|null
	 */
	private $rows = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'mailpoet';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'MailPoet';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'MAILPOET_VERSION' ) && class_exists( '\\MailPoet\\DI\\ContainerWrapper' );
	}

	/**
	 * Returns the actions with which the plugin receives a form: at admin-ajax.php, and at admin-post.php
	 * from a form sent without JavaScript.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'mailpoet', 'mailpoet_subscription_form' );
	}

	/**
	 * Hooks where the plugin prints its forms.
	 */
	public function register(): void {
		add_filter( 'mailpoet_form_widget_post_process', array( $this, 'translateMarkup' ), 10, 1 );
		// After the plugin adds its forms below the content (10).
		add_filter( 'the_content', array( $this, 'translateMarkup' ), 11 );
		add_filter( 'woocommerce_product_loop_end', array( $this, 'translateMarkup' ), 11 );
		// Around the plugin printing its popups, bars and slide-ins at the foot of the page (10).
		add_action( 'wp_footer', array( $this, 'startFooter' ), 9 );
		add_action( 'wp_footer', array( $this, 'endFooter' ), 11 );
	}

	/**
	 * Returns HTML with the plugin's forms in it in the mode of the moment: the blocks and the message
	 * after subscribing of each form found in it.
	 *
	 * @param mixed $html HTML that may hold forms of the plugin.
	 * @return mixed
	 */
	public function translateMarkup( $html ) {
		if ( ! is_string( $html ) || '' === swiftling_mode() || false === strpos( $html, 'name="data[form_id]"' ) || ! preg_match_all( '/name="data\[form_id\]" value="(\d+)"/', $html, $found ) ) {
			return $html;
		}
		foreach ( array_unique( array_map( 'intval', $found[1] ) ) as $form_id ) {
			$form = $this->formRow( $form_id );
			if ( null === $form ) {
				continue;
			}
			$texts = $this->formTexts( $form_id, $form['body'], $form['settings'] );
			if ( $texts['body'] !== $form['body'] ) {
				$html = $this->replaceBlocks( $html, $form, $texts );
			}
			$html = self::replaceMessage( $html, (string) ( $form['settings']['success_message'] ?? '' ), (string) ( $texts['settings']['success_message'] ?? '' ) );
		}
		return $html;
	}

	/**
	 * Starts keeping what is printed at the foot of the page, where the plugin prints its popups.
	 */
	public function startFooter(): void {
		if ( '' !== swiftling_mode() && ! $this->footer ) {
			$this->footer = ob_start( array( $this, 'translateMarkup' ) );
		}
	}

	/**
	 * Lets what was kept at the foot of the page through, with the plugin's forms in it translated.
	 */
	public function endFooter(): void {
		if ( $this->footer ) {
			$this->footer = false;
			ob_end_flush();
		}
	}

	/**
	 * Returns the blocks and settings of a form with their texts in the mode of the moment.
	 *
	 * @param int   $form_id  Form ID.
	 * @param array $body     The blocks of the form.
	 * @param array $settings The settings of the form.
	 * @return array{body: array, settings: array}
	 */
	private function formTexts( int $form_id, array $body, array $settings ): array {
		$places = array();
		$seen   = array();
		self::blockPlaces( $body, $body, array(), $places, $seen );
		$body = $this->translate( $form_id, $body, $places );

		$message = array();
		self::addPlace( $message, $settings, array( 'success_message' ), 'message' );
		$settings = $this->translate( $form_id, $settings, $message );
		return array(
			'body'     => is_array( $body ) ? $body : array(),
			'settings' => is_array( $settings ) ? $settings : array(),
		);
	}

	/**
	 * Adds the places of the texts of blocks, also of blocks in columns, each named after the block's ID,
	 * with a count where the ID repeats, as the plugin's paragraphs share one.
	 *
	 * @param array                                 $body   The blocks of the form, which the places lead into.
	 * @param array                                 $blocks Blocks.
	 * @param array<int, int|string>                $path   Keys that lead to the blocks in the form.
	 * @param array<string, array<int, int|string>> $places Places so far.
	 * @param array<string, int>                    $seen   How often each ID was seen so far.
	 */
	private static function blockPlaces( array $body, array $blocks, array $path, array &$places, array &$seen ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$at = array_merge( $path, array( $index ) );
			if ( isset( $block['body'] ) && is_array( $block['body'] ) ) {
				self::blockPlaces( $body, $block['body'], array_merge( $at, array( 'body' ) ), $places, $seen );
			}
			if ( ! isset( $block['params'] ) || ! is_array( $block['params'] ) ) {
				continue;
			}
			$id          = (string) preg_replace( '/[^a-z0-9_-]+/i', '_', (string) ( $block['id'] ?? ( $block['type'] ?? 'block' ) ) );
			$seen[ $id ] = ( $seen[ $id ] ?? 0 ) + 1;
			$name        = 'fields/' . $id . ( $seen[ $id ] > 1 ? '-' . $seen[ $id ] : '' );
			foreach ( self::BLOCK_TEXTS as $param ) {
				self::addPlace( $places, $body, array_merge( $at, array( 'params', $param ) ), $name . '/' . $param );
			}
		}
	}

	/**
	 * Returns the blocks of a form as the plugin renders them, or an empty string where it cannot.
	 *
	 * @param array $body     The blocks.
	 * @param array $settings The settings of the form.
	 * @param int   $form_id  Form ID.
	 */
	private function render( array $body, array $settings, int $form_id ): string {
		try {
			$renderer = \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoet\Form\Renderer::class );
			return is_object( $renderer ) && method_exists( $renderer, 'renderBlocks' ) ? (string) $renderer->renderBlocks( $body, $settings, $form_id ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Returns HTML with the blocks of a form the plugin rendered rendered again from their texts in the
	 * mode of the moment. The plugin makes up some names anew each time it renders a block, see
	 * MADE_UP, so the rendering is found with any such names, and each place the form is printed gets a
	 * rendering of its own, with names of its own.
	 *
	 * @param string $html  HTML with the form.
	 * @param array  $form  The form, as stored.
	 * @param array  $texts The blocks and settings in the mode of the moment.
	 */
	private function replaceBlocks( string $html, array $form, array $texts ): string {
		$rendered = $this->render( $form['body'], $form['settings'], $form['id'] );
		if ( '' === $rendered ) {
			return $html;
		}
		$quoted   = array_map(
			static function ( $part ): string {
				return preg_quote( (string) $part, '#' );
			},
			(array) preg_split( '#' . self::MADE_UP . '#', $rendered )
		);
		$pattern  = '#' . implode( '(?:' . self::MADE_UP . ')', $quoted ) . '#';
		$replaced = preg_replace_callback(
			$pattern,
			function ( $found ) use ( $texts, $form ) {
				$changed = $this->render( $texts['body'], $texts['settings'], $form['id'] );
				return '' !== $changed ? $changed : $found[0];
			},
			$html
		);
		return is_string( $replaced ) ? $replaced : $html;
	}

	/**
	 * Returns HTML with the message after subscribing of a form in its translation, where the plugin
	 * printed it: in the paragraph it shows once a visitor subscribed.
	 *
	 * @param string $html       HTML with the form.
	 * @param string $original   The message, as the form keeps it.
	 * @param string $translated The message in the mode of the moment.
	 */
	private static function replaceMessage( string $html, string $original, string $translated ): string {
		if ( '' === trim( $original ) || $original === $translated ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#(<p class="mailpoet_validate_success"[^>]*>)(.*?)(</p>)#s',
			static function ( $found ) use ( $original, $translated ) {
				if ( trim( html_entity_decode( $found[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) !== trim( $original ) ) {
					return $found[0];
				}
				return $found[1] . esc_html( $translated ) . $found[3];
			},
			$html
		);
	}

	/**
	 * Returns the forms, by ID: those in the plugin's bin are left out.
	 *
	 * @return array<int, array{id: int, title: string, body: array, settings: array}>
	 */
	private function rows(): array {
		if ( null === $this->rows ) {
			global $wpdb;
			$this->rows = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table of forms, once a request.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, body, settings FROM %i WHERE deleted_at IS NULL ORDER BY name', $wpdb->prefix . 'mailpoet_forms' ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$body                           = maybe_unserialize( (string) $row['body'] );
				$settings                       = maybe_unserialize( (string) $row['settings'] );
				$this->rows[ (int) $row['id'] ] = array(
					'id'       => (int) $row['id'],
					'title'    => trim( (string) $row['name'] ),
					'body'     => is_array( $body ) ? $body : array(),
					'settings' => is_array( $settings ) ? $settings : array(),
				);
			}
		}
		return $this->rows;
	}

	/**
	 * Returns one form.
	 *
	 * @param int $form_id Form ID.
	 * @return array{id: int, title: string, body: array, settings: array}|null
	 */
	private function formRow( int $form_id ): ?array {
		return $this->rows()[ $form_id ] ?? null;
	}

	/**
	 * Returns the forms, but those in the plugin's bin.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	protected function allForms(): array {
		$forms = array();
		foreach ( $this->rows() as $form ) {
			$forms[] = array(
				'id'    => $form['id'],
				'title' => $form['title'],
			);
		}
		return $forms;
	}

	/**
	 * Reads the texts of a form, as a page that shows it does.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function readTexts( int $form_id ): void {
		$form = $this->formRow( $form_id );
		if ( null !== $form ) {
			$this->formTexts( $form_id, $form['body'], $form['settings'] );
		}
	}

	/**
	 * Returns the ways a post shows a form: the shortcode, or the plugin's block with the form's ID.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, string[]>
	 */
	protected function pageMarks( int $form_id ): array {
		$marks = array();
		foreach ( array( '"' . $form_id . '"', "'" . $form_id . "'", $form_id . ']', $form_id . ' ' ) as $id ) {
			$marks[] = array( '[mailpoet_form id=' . $id );
		}
		foreach ( array( $form_id . ',', $form_id . '}' ) as $id ) {
			$marks[] = array( '<!-- wp:mailpoet/subscription-form-block ', '"formId":' . $id );
		}
		return $marks;
	}

	/**
	 * Whether the current user may edit forms, as the plugin asks.
	 */
	protected function mayEdit(): bool {
		return current_user_can( 'mailpoet_manage_forms' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- The plugin's own capability, which it grants to administrators.
	}

	/**
	 * Returns what the editor calls a text of a form, such as "First name › Label".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is not a text of one of the plugin's forms.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( ! $this->formOf( $owner ) || 0 !== strpos( $path, self::BASE . '/' ) ) {
			return '';
		}
		$parts = explode( '/', substr( $path, strlen( self::BASE ) + 1 ) );
		if ( 'message' === $parts[0] ) {
			return __( 'Message after subscribing', 'swiftling' );
		}
		if ( 'fields' !== $parts[0] || count( $parts ) < 3 ) {
			return '';
		}
		$names = array(
			'label'       => __( 'Label', 'swiftling' ),
			'description' => __( 'Description', 'swiftling' ),
			'text'        => __( 'Text', 'swiftling' ),
			'content'     => __( 'Text', 'swiftling' ),
		);
		$block = ucfirst( str_replace( array( '_', '-' ), ' ', $parts[1] ) );
		return $block . ' › ' . ( $names[ $parts[2] ] ?? $parts[2] );
	}
}
