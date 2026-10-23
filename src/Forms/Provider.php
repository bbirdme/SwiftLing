<?php
/**
 * What every form plugin tells SwiftLing.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

use SwiftLing\Builders\Builder;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin whose forms are posts of its own, with their texts in the meta of those posts: the
 * labels of the fields, the names of the choices, the text of the button and the messages a
 * visitor reads after sending.
 *
 * Such a plugin keeps texts no page of its own shows, so each of its forms is a subject of the
 * translation editor, as an options page of Advanced Custom Fields is, see SwiftLing\Builders\Acf.
 * The provider only says where the forms are. Their texts are read where the plugin itself reads
 * them, through get_post_meta(), so the meta filter in includes/sources.php notes every value with
 * the object and the path a translated page finds it under, and there is no read path of
 * SwiftLing's own.
 *
 * One class per plugin: a new form plugin gets a class in this folder and a line in
 * SwiftLing\Builders\Registry::all(). The Forms screen lists what the classes say.
 *
 * Each class says what was checked, and in which version of the plugin.
 */
abstract class Provider extends Builder implements FormPlugin {
	use FormSubjects;

	/**
	 * How many forms of one plugin a screen lists.
	 */
	const LIMIT = 200;

	/**
	 * Returns the post type the plugin keeps its forms in, e.g. "wpcf7_contact_form".
	 */
	abstract public function postType(): string;

	/**
	 * Returns what says the plugin is on the site before it registers that post type: the constants
	 * and classes its files declare while they load.
	 *
	 * @return string[]
	 */
	abstract protected function markers(): array;

	/**
	 * Returns the meta keys of a form that hold its texts, in the order the editor lists them.
	 *
	 * @return string[]
	 */
	abstract protected function metaKeys(): array;

	/**
	 * Returns what the editor calls each of those keys, e.g. "_messages" => "Messages".
	 *
	 * @return array<string, string>
	 */
	abstract protected function metaLabels(): array;

	/**
	 * Returns the shortcode a page holds where it shows one of the forms, e.g. "contact-form-7".
	 */
	abstract protected function shortcodeTag(): string;

	/**
	 * Whether the plugin is on the site.
	 *
	 * Builders are asked once, at init priority 0 (see SwiftLing\Plugin), before plugins register
	 * their post types, so the markers answer there; everything that runs later, such as a screen,
	 * sees the post type as well, whatever the plugin calls its own classes.
	 */
	public function isActive(): bool {
		foreach ( $this->markers() as $marker ) {
			if ( defined( $marker ) || class_exists( $marker ) ) {
				return true;
			}
		}
		return post_type_exists( $this->postType() );
	}

	/**
	 * A form plugin does not lay a page out: the editor names only the builders that build pages.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same answer for every post.
		return false;
	}

	/**
	 * Returns the forms of the plugin, each with the subject key its texts are translated under and
	 * how many pages show it.
	 *
	 * @return array<int, array{key: string, id: int, title: string, pages: int}>
	 */
	public function forms(): array {
		if ( ! $this->isActive() ) {
			return array();
		}

		$forms = array();
		foreach ( $this->formPosts() as $post ) {
			$forms[] = array(
				'key'   => $this->subjectKey( $post->ID ),
				'id'    => $post->ID,
				'title' => '' !== trim( $post->post_title ) ? $post->post_title : __( 'No title', 'swiftling' ),
				'pages' => $this->pages( $post ),
			);
		}
		return $forms;
	}

	/**
	 * Returns the posts of the forms, by name. A form a person is still writing counts: its texts
	 * can be translated before it goes on a page.
	 *
	 * @return WP_Post[]
	 */
	protected function formPosts(): array {
		return get_posts(
			array(
				'post_type'        => $this->postType(),
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => self::LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Returns how many pages visitors can open show a form: published pages whose content holds the
	 * plugin's shortcode with the id of the form, or with its slug, which some plugins put there
	 * instead. A page that shows the form in another way, such as a block of a page builder, is not
	 * counted; the screen says as much.
	 *
	 * @param WP_Post $form Form.
	 */
	protected function pages( WP_Post $form ): int {
		global $wpdb;

		$needles = array();
		foreach ( array_unique( array( (string) $form->ID, (string) $form->post_name ) ) as $value ) {
			if ( '' !== $value ) {
				$needles[] = '%' . $wpdb->esc_like( '[' . $this->shortcodeTag() . ' id="' . $value . '"' ) . '%';
			}
		}
		$where = implode( ' OR ', array_fill( 0, count( $needles ), 'post_content LIKE %s' ) );
		$sql   = "SELECT COUNT(ID) FROM %i WHERE post_status = 'publish' AND ( {$where} )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One count per form on the Forms screen; $where has one placeholder per needle.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $wpdb->posts, ...$needles ) );
	}

	/**
	 * Returns the subject of the translation editor for one form.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		$form = $this->form( $this->formId( $key ) );
		if ( null === $form ) {
			return null;
		}
		$type = get_post_type_object( $this->postType() );

		return array(
			'key'   => $this->subjectKey( $form->ID ),
			'kind'  => 'fields',
			'title' => '' !== trim( $form->post_title ) ? $form->post_title : __( 'No title', 'swiftling' ),
			/* translators: %s: name of a form plugin, e.g. Contact Form 7. */
			'type'  => sprintf( __( 'Form of %s', 'swiftling' ), $this->name() ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => $type && isset( $type->cap->edit_posts ) ? (string) $type->cap->edit_posts : 'manage_options',
			'note'  => __( 'Every text of this form: the labels of its fields, its choices, its button and the messages a visitor reads after sending. The form is translated once and shows translated in every page it is on.', 'swiftling' ),
		);
	}

	/**
	 * Returns the texts of one form, read as the plugin reads them: through get_post_meta(), which
	 * the meta filter of includes/sources.php notes value by value, so each text comes with the
	 * object and the path a translated page finds it under. A value a provider says holds no text,
	 * such as the name of a font, is left out there, see Builder::holdsNoText().
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$form = $this->form( $this->formId( (string) $subject['key'] ) );
		if ( null === $form ) {
			return array();
		}

		$keys   = $this->metaKeys();
		$leaves = swiftling_collect(
			static function () use ( $form, $keys ): void {
				foreach ( $keys as $key ) {
					get_post_meta( $form->ID, $key, true );
				}
			}
		);

		$texts = array();
		foreach ( $leaves as $leaf ) {
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => $this->textKind( $leaf['v'] ),
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns what the editor calls the field a text of a form belongs to, such as
	 * "Notifications 1 › Email subject".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is not a text of one of this plugin's forms.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, 'post:' ) || null === $this->form( (int) substr( $owner, 5 ) ) ) {
			return '';
		}
		$parts  = explode( '/', $path );
		$labels = $this->metaLabels();
		if ( count( $parts ) < 2 || 'meta' !== $parts[0] || ! isset( $labels[ $parts[1] ] ) ) {
			return '';
		}

		$names = array( $labels[ $parts[1] ] );
		foreach ( array_slice( $parts, 2 ) as $part ) {
			$names[] = $this->segmentLabel( $part );
		}
		return implode( ' › ', array_filter( $names ) );
	}

	/**
	 * Returns a part of a path as the editor shows it: a number as the row it names, counted from
	 * one as a person counts; the name the plugin gave a field, such as "name-1", as it is; and
	 * anything else with its words apart and its first letter capital.
	 *
	 * @param string $segment Part of a path, e.g. "field_label", "#name-1" or "2".
	 */
	protected function segmentLabel( string $segment ): string {
		if ( ctype_digit( $segment ) ) {
			return number_format_i18n( (int) $segment + 1 );
		}
		if ( '' === $segment || '@' === $segment[0] ) {
			return '';
		}
		if ( '#' === $segment[0] ) {
			return substr( $segment, 1 );
		}
		return ucfirst( str_replace( array( '_', '-' ), ' ', $segment ) );
	}

	/**
	 * Returns the post of one of this plugin's forms.
	 *
	 * @param int $form_id Post ID.
	 */
	protected function form( int $form_id ): ?WP_Post {
		$post = $form_id ? get_post( $form_id ) : null;
		return $post instanceof WP_Post && $post->post_type === $this->postType() ? $post : null;
	}
}
