<?php
/**
 * What a form plugin that keeps its forms in tables of its own needs from SwiftLing.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

use ArrayAccess;
use SwiftLing\Builders\Builder;

defined( 'ABSPATH' ) || exit;

/**
 * A form plugin whose forms are rows of tables of its own, such as Gravity Forms and Ninja Forms.
 * The plugin reads a form straight from those tables, so none of its texts passes through
 * get_post_meta() or any other place SwiftLing watches: a page check finds none of them, and a
 * translated page shows none of them translated, unless the class of the plugin takes them there.
 *
 * It does at the filters where the plugin hands a form over before it shows it. There the class
 * lists where the texts are, see translate(), and they go through swiftling_transform() under an
 * owner of the form, "<class ID>:<form ID>", as the meta of a post does, and come back into the
 * form. Only what a visitor reads is listed, never what a program reads: the value of a choice, a
 * CSS class, an address mail goes to.
 *
 * The Forms screen lists the forms, the translation editor opens each under the subject key
 * "<class ID>-form:<form ID>", and lists its texts by reading the form in a page check, see
 * listTexts().
 */
abstract class TableProvider extends Builder implements FormPlugin {
	use FormSubjects;

	/**
	 * Where the texts of a form are in their owner. It names two parts because the place of a text
	 * in the owner's map starts with two, see swiftling_path_source().
	 */
	const BASE = 'form/texts';

	/**
	 * Returns the forms, inactive ones too: a form a person is still writing can be translated
	 * before it goes on a page.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	abstract protected function allForms(): array;

	/**
	 * Reads the texts of a form as a page that shows it does, through the filters of the class, in
	 * the mode of the moment.
	 *
	 * @param int $form_id Form ID.
	 */
	abstract protected function readTexts( int $form_id ): void;

	/**
	 * Returns the ways a published post shows a form: each a list of pieces its content holds all
	 * of, such as a block and the ID in its attributes.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, string[]>
	 */
	abstract protected function pageMarks( int $form_id ): array;

	/**
	 * Whether the current user may edit the forms of the plugin, as the plugin asks.
	 */
	abstract protected function mayEdit(): bool;

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
		foreach ( $this->allForms() as $form ) {
			$forms[] = array(
				'key'   => $this->subjectKey( $form['id'] ),
				'id'    => $form['id'],
				'title' => '' !== $form['title'] ? $form['title'] : __( 'No title', 'swiftling' ),
				'pages' => $this->pages( $form['id'] ),
			);
		}
		return $forms;
	}

	/**
	 * Returns the title of a form.
	 *
	 * @param int $form_id Form ID.
	 * @return string|null Null when there is no such form.
	 */
	protected function formTitle( int $form_id ): ?string {
		foreach ( $this->allForms() as $form ) {
			if ( $form['id'] === $form_id ) {
				return $form['title'];
			}
		}
		return null;
	}

	/**
	 * Returns how many published posts show a form, see pageMarks(). A page that shows the form in
	 * another way, such as a widget of a page builder, is not counted.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function pages( int $form_id ): int {
		global $wpdb;

		$any  = array();
		$args = array( $wpdb->posts );
		foreach ( $this->pageMarks( $form_id ) as $pieces ) {
			$all = array();
			foreach ( $pieces as $piece ) {
				$all[]  = 'post_content LIKE %s';
				$args[] = '%' . $wpdb->esc_like( $piece ) . '%';
			}
			$any[] = '( ' . implode( ' AND ', $all ) . ' )';
		}
		if ( ! $any ) {
			return 0;
		}
		$sql = "SELECT COUNT(ID) FROM %i WHERE post_status = 'publish' AND ( " . implode( ' OR ', $any ) . ' )';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One count per form on the Forms screen; one placeholder per piece.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * Returns the subject of the translation editor for one form.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		$id    = $this->formId( $key );
		$title = $id && $this->isActive() ? $this->formTitle( $id ) : null;
		if ( null === $title ) {
			return null;
		}
		return array(
			'key'   => $this->subjectKey( $id ),
			'kind'  => 'fields',
			'title' => '' !== $title ? $title : __( 'No title', 'swiftling' ),
			/* translators: %s: name of a form plugin, e.g. Contact Form 7. */
			'type'  => sprintf( __( 'Form of %s', 'swiftling' ), $this->name() ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			// Whoever manages the site; what the plugin asks for its forms is checked per text, see canTranslate().
			'cap'   => 'manage_options',
			'note'  => __( 'Every text of this form: the labels of its fields, its choices, its button and the messages a visitor reads after sending. The form is translated once and shows translated in every page it is on.', 'swiftling' ),
		);
	}

	/**
	 * Returns the texts of one form, read in a page check, which notes every text with the owner and
	 * the path a translated page finds it under.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$id = $this->formId( (string) $subject['key'] );
		if ( ! $id ) {
			return array();
		}
		$leaves = swiftling_collect(
			function () use ( $id ): void {
				$this->readTexts( $id );
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
	 * Whether a text of a form is a text: every value the class takes from a form is one.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for what is not a form of this plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Every path of a form is a text.
		return $this->formOf( $owner ) ? true : null;
	}

	/**
	 * Whether the current user may translate a form: the plugin's own right to edit forms.
	 *
	 * @param string $owner Object key.
	 * @return bool|null Null when the object is not a form of this plugin.
	 */
	public function canTranslate( string $owner ): ?bool {
		return $this->formOf( $owner ) ? $this->mayEdit() : null;
	}

	/**
	 * Returns the owner key of a form, e.g. "gravityforms:7".
	 *
	 * @param int $form_id Form ID.
	 */
	protected function owner( int $form_id ): string {
		return $this->id() . ':' . $form_id;
	}

	/**
	 * Returns the ID of the form an owner key names.
	 *
	 * @param string $owner Object key, e.g. "gravityforms:7".
	 * @return int Zero when the key is not a form of this plugin.
	 */
	protected function formOf( string $owner ): int {
		$prefix = $this->id() . ':';
		$rest   = 0 === strpos( $owner, $prefix ) ? substr( $owner, strlen( $prefix ) ) : '';
		return ctype_digit( $rest ) ? (int) $rest : 0;
	}

	/**
	 * Runs texts of a form through the current mode: a page check marks them and notes where they
	 * are, a page in another language gets the translations. Nothing changes outside those.
	 *
	 * @param int                                   $form_id   Form ID.
	 * @param mixed                                 $container What the plugin hands over: the form, its
	 *                                                         settings or its fields.
	 * @param array<string, array<int, int|string>> $places    Name of each text, "fields/field_3/label",
	 *                                                         and the keys that lead to it in the container.
	 * @return mixed The container, with its texts changed, or as it was.
	 */
	protected function translate( int $form_id, $container, array $places ) {
		if ( '' === swiftling_mode() || ! $places ) {
			return $container;
		}

		$texts = array();
		foreach ( $places as $key => $path ) {
			self::setAt( $texts, explode( '/', $key ), (string) self::at( $container, $path ) );
		}
		$new = swiftling_transform( $this->owner( $form_id ), self::BASE, $this->id(), $texts );

		foreach ( $places as $key => $path ) {
			$text = self::at( $new, explode( '/', $key ) );
			if ( is_string( $text ) && self::at( $container, $path ) !== $text ) {
				self::setAt( $container, $path, $text );
			}
		}
		return $container;
	}

	/**
	 * Adds the place of a text when there is one there.
	 *
	 * @param array<string, array<int, int|string>> $places    Places so far.
	 * @param mixed                                 $container Form, settings or fields.
	 * @param array<int, int|string>                $path      Keys that lead to the text.
	 * @param string                                $key       Name of the text.
	 */
	protected static function addPlace( array &$places, $container, array $path, string $key ): void {
		$value = self::at( $container, $path );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$places[ $key ] = $path;
		}
	}

	/**
	 * Reads a value the way the plugin does: arrays and objects that act as arrays alike.
	 *
	 * @param mixed                  $container Array or object that acts as one.
	 * @param array<int, int|string> $path      Keys.
	 * @return mixed Null where there is none.
	 */
	protected static function at( $container, array $path ) {
		foreach ( $path as $key ) {
			if ( ( ! is_array( $container ) && ! $container instanceof ArrayAccess ) || ! isset( $container[ $key ] ) ) {
				return null;
			}
			$container = $container[ $key ];
		}
		return $container;
	}

	/**
	 * Writes a value. A list inside an object that acts as an array comes back by value, so each
	 * level is read, changed and written back, and the object stays the one the plugin made.
	 *
	 * @param mixed                  $container Array or object that acts as one.
	 * @param array<int, int|string> $path      Keys.
	 * @param string                 $value     Value.
	 */
	protected static function setAt( &$container, array $path, string $value ): void {
		$key = array_shift( $path );
		if ( ! $path ) {
			$container[ $key ] = $value;
			return;
		}
		$child = ( is_array( $container ) || $container instanceof ArrayAccess ) && isset( $container[ $key ] ) ? $container[ $key ] : array();
		self::setAt( $child, $path, $value );
		$container[ $key ] = $child;
	}
}
