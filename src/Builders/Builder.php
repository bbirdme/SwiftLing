<?php
/**
 * What every builder class tells SwiftLing.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * A page builder, a plugin that adds widgets to one, a plugin that adds fields to content, such as
 * Advanced Custom Fields, or a plugin that keeps texts of its own in posts of its own, such as a
 * form plugin, see SwiftLing\Forms\Provider.
 *
 * The probe finds the text of a page in any builder without knowing it, because every builder
 * reads its data where WordPress hands it over (see includes/sources.php). A builder's class holds
 * only what the probe cannot find out by itself:
 *
 * - whether the builder is active, and whether a page is built with it, which the editor shows;
 * - copies of rendered output that the builder prints instead of rendering from its data, which
 *   would show the original language on a translated page;
 * - files the builder writes while a page renders, which a page check must not write, because
 *   the data it renders from carries markers;
 * - what its fields hold, where it declares that, and texts it keeps that no page shows, which
 *   the editor can still list.
 *
 * Each class says what was checked, and in which version of the builder.
 */
abstract class Builder {
	/**
	 * Returns the builder's ID.
	 *
	 * @return string Lowercase, e.g. "elementor".
	 */
	abstract public function id(): string;

	/**
	 * Returns the builder's name, as the editor shows it.
	 */
	abstract public function name(): string;

	/**
	 * Whether the builder is active on the site.
	 */
	abstract public function isActive(): bool;

	/**
	 * Whether a post is built with the builder.
	 *
	 * @param int $post_id Post ID.
	 */
	abstract public function isUsed( int $post_id ): bool;

	/**
	 * Hooks what the builder needs on every request, once plugins are loaded and only when the
	 * builder is active, such as a rule of its own in the builder's settings.
	 */
	public function register(): void {
	}

	/**
	 * Prepares the builder for a request SwiftLing changes, before the page renders: a page check,
	 * in any of its variants, or a translated view.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check, which must leave no trace.
	 */
	public function start( string $mode, bool $probe ): void {
	}

	/**
	 * Whether a value in the builder's data never holds text, although it may look like it, such as
	 * a font name in a style. The probe leaves such a value unmarked. Only for data the builder
	 * writes into files while a page renders: a marker in it would end up in the file.
	 *
	 * @param string $path Path of the value inside its object, e.g. "meta/_elementor_data/@json/…".
	 */
	public function holdsNoText( string $path ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A builder with such data overrides it.
		return false;
	}

	/**
	 * Whether the current user may translate an object the builder keeps, which takes the right to
	 * edit it: the owner key of such an object is the builder's, such as "gravityforms:7". Objects
	 * of WordPress itself, posts, terms and options, are decided in swiftling_can_translate_object().
	 *
	 * @param string $owner Object key.
	 * @return bool|null Null when the object is not the builder's.
	 */
	public function canTranslate( string $owner ): ?bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A builder with objects of its own overrides it.
		return null;
	}

	/**
	 * Returns the actions of admin-ajax.php with which the builder's plugin receives a form a visitor
	 * sends from a page. Such a request runs in the language of the page it was sent from, so its
	 * answer is in that language, see swiftling_sent_form_language(); other Ajax requests do not.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array();
	}

	/**
	 * Returns the routes of the REST API at which the builder's plugin receives a form a visitor
	 * sends from a page, as regular expressions on the route, such as
	 * "#^/contact-form-7/v1/contact-forms/\d+/feedback/?$#". Such a request runs in the language of
	 * the page it was sent from, see swiftling_sent_form_language(); other REST requests do not.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array();
	}

	/**
	 * Whether the builder itself declares a value as text, such as a text field of a widget. Such a
	 * value is checked even when it does not look like text, as "/month" or "m" beside a price, and
	 * it counts as text also when its marker shows only in a data attribute that a script turns into
	 * text, as the rotating texts of an animated headline.
	 *
	 * @param string $rule Rule key of the value: where it comes from, the element type and the field.
	 */
	public function declaresText( string $rule ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A builder that declares its fields overrides it.
		return false;
	}

	/**
	 * Whether the plugin that stored a value declares it as text, from the type of the field it
	 * belongs to: a text field (true), a choice, a number or another setting (false), or nothing it
	 * knows of (null), which leaves the value to the probe as any other.
	 *
	 * A value declared as text is a candidate also when it does not look like text, as one word in
	 * lowercase, and is read also where its key looks like a setting's, as "block_title". A value
	 * declared as no text is never marked or translated, even when it looks like text: a theme
	 * compares the value of a choice such as "Open now" with a fixed string.
	 *
	 * @param string $owner Object key of the value, e.g. "post:12" or "options".
	 * @param string $path  Path of the value inside its object, e.g. "meta/hero_title".
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- A builder with typed fields overrides it.
		return null;
	}

	/**
	 * Returns what the editor calls the field a value belongs to, such as "Team members 2 › Name",
	 * to show next to the value.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the builder does not know the value.
	 */
	public function fieldLabel( string $owner, string $path ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- A builder with named fields overrides it.
		return '';
	}

	/**
	 * Returns a subject of the translation editor the builder adds: texts it keeps outside any
	 * post, such as settings pages of its own, translated without a page check. See
	 * swiftling_subject() for the shape; "kind" is "fields", and the subject may add "cap", the
	 * capability needed to translate it, and "note", a sentence about it for the editor.
	 *
	 * @param string $key Subject key.
	 * @return array|null Null when the key is not one of the builder's.
	 */
	public function subject( string $key ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A builder with subjects of its own overrides it.
		return null;
	}

	/**
	 * Returns which part of every page an object of the builder is: its header or its footer, kept
	 * in a post of its own, such as a header template of a theme builder. Its texts are listed with
	 * the header or footer on the Templates screen, wherever the builder prints them and whatever
	 * tag it prints them in, see swiftling_unit_site_part().
	 *
	 * @param string $owner Object key, e.g. "post:12".
	 * @return string header, footer, or empty when the object is no such part of the builder.
	 */
	public function sitePart( string $owner ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A builder with headers and footers of its own overrides it.
		return '';
	}

	/**
	 * Returns the subjects the builder adds, for the Templates screen, in groups.
	 *
	 * @return array<int, array{title: string, description: string, subjects: array<int, array{key: string, title: string, description: string}>}>
	 */
	public function subjects(): array {
		return array();
	}

	/**
	 * Returns the texts the builder keeps for a subject, read from where they are stored rather
	 * than from a page: for a subject of its own, all of its texts; for a post, the texts of the
	 * post's fields, also those its page does not show.
	 *
	 * Each text names its object (o), its path (p), its value (v), its rule key (r) and its kind,
	 * as the probe would find it, and may name the field it belongs to (place). An address is of
	 * the kind "url", "image" or "video".
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 * @return array<int, array{o: string, p: string, v: string, r: string, kind: string, place?: string}>
	 */
	public function listTexts( array $subject ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A builder that keeps texts outside pages overrides it.
		return array();
	}

	/**
	 * Whether the builder declares a value as code, such as the content of an HTML or code snippet
	 * field. The editor shows code only when asked to.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @param string $rule  Rule key of the value.
	 */
	public function isCode( string $owner, string $path, string $rule ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- A builder with code fields overrides it.
		return false;
	}

	/**
	 * Returns the post types of the plugin that get no separate copy, see includes/copies.php: posts
	 * that work only together with data a copy would not have, such as a product's stock or a
	 * course's lessons and students. They are translated in place.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array();
	}

	/**
	 * Returns the meta keys the builder writes again by itself, such as CSS made from a page's
	 * data, which a separate copy of the page leaves out.
	 *
	 * @return string[]
	 */
	public function regeneratedMeta(): array {
		return array();
	}

	/**
	 * Returns the address of the builder's own editor for a post the builder is used for.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Null when the post is edited in WordPress's own editor.
	 */
	public function editUrl( int $post_id ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A builder with its own editor overrides it.
		return null;
	}

	/**
	 * Returns the address of the page being shown in a language, for the language switcher, where the
	 * builder writes words of its own into the addresses of its pages, in the language of the page,
	 * such as "monat" in the address of a calendar's month: the words of that language.
	 *
	 * @param string $url  The address in that language, as SwiftLing built it from the request.
	 * @param string $slug Address slug of the language, or an empty string for the original.
	 */
	public function currentAddress( string $url, string $slug ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- A builder with words of its own in addresses overrides it.
		return $url;
	}

	/**
	 * Whether the request is the builder's own editor on the front end, or the preview it shows
	 * while a post is edited. Such a request reads stored values untranslated, also at a language's
	 * address, see swiftling_start(): the editor edits the original.
	 */
	public function isEditorPreview(): bool {
		return false;
	}
}
