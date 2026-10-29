<?php
/**
 * The builders SwiftLing knows.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

use SwiftLing\Forms\ContactForm7;
use SwiftLing\Forms\Forminator;
use SwiftLing\Forms\FluentForms;
use SwiftLing\Forms\Formidable;
use SwiftLing\Forms\GravityForms;
use SwiftLing\Forms\NinjaForms;
use SwiftLing\Forms\WPForms;
use SwiftLing\Forms\FormPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * The builders SwiftLing knows, one class each. A new builder gets its class in this folder and
 * a line in all(). The form plugins are builders too: their forms keep texts outside the pages
 * visitors open, see SwiftLing\Forms\FormPlugin, so they are in the same list and the editor opens
 * their subjects through subject() and listTexts() as it does an options page of ACF.
 */
final class Registry {
	/**
	 * Every builder, keyed by ID.
	 *
	 * @var array<string, Builder>|null
	 */
	private static $builders = null;

	/**
	 * The builders active on the site.
	 *
	 * @var array<string, Builder>|null
	 */
	private static $active = null;

	/**
	 * Returns every builder SwiftLing knows, keyed by ID.
	 *
	 * @return array<string, Builder>
	 */
	public static function all(): array {
		if ( null === self::$builders ) {
			self::$builders = array();
			foreach ( array( new Elementor(), new Breakdance(), new Brizy(), new Acf(), new ContactForm7(), new Forminator(), new GravityForms(), new NinjaForms(), new FluentForms(), new WPForms(), new Formidable(), new WooCommerce(), new SeoPress(), new Aioseo() ) as $builder ) {
				self::$builders[ $builder->id() ] = $builder;
			}
		}
		return self::$builders;
	}

	/**
	 * Returns the form plugins SwiftLing knows, active or not, for the Forms screen: it names them
	 * all and lists the forms of those that are on the site.
	 *
	 * @return array<string, Builder&FormPlugin>
	 */
	public static function forms(): array {
		return array_filter(
			self::all(),
			static function ( Builder $builder ): bool {
				return $builder instanceof FormPlugin;
			}
		);
	}

	/**
	 * Whether the current user may translate an object some builder keeps, see Builder::canTranslate().
	 *
	 * @param string $owner Object key.
	 * @return bool|null Null when no active builder keeps the object.
	 */
	public static function canTranslate( string $owner ): ?bool {
		foreach ( self::active() as $builder ) {
			$can = $builder->canTranslate( $owner );
			if ( null !== $can ) {
				return $can;
			}
		}
		return null;
	}

	/**
	 * Whether an action of admin-ajax.php is the sending of a form of an active builder, see
	 * Builder::sendingActions().
	 *
	 * @param string $action Action of the request.
	 */
	public static function sendsForm( string $action ): bool {
		foreach ( self::active() as $builder ) {
			if ( in_array( $action, $builder->sendingActions(), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a route of the REST API is where an active builder receives a form, see
	 * Builder::sendingRoutes().
	 *
	 * @param string $route Route of the request, as "/contact-form-7/v1/contact-forms/7/feedback".
	 */
	public static function sendsFormTo( string $route ): bool {
		foreach ( self::active() as $builder ) {
			foreach ( $builder->sendingRoutes() as $pattern ) {
				if ( preg_match( $pattern, $route ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Returns one builder.
	 *
	 * @param string $id Builder ID, e.g. "elementor".
	 */
	public static function get( string $id ): ?Builder {
		$builders = self::all();
		return isset( $builders[ $id ] ) ? $builders[ $id ] : null;
	}

	/**
	 * Returns the builders that are active on the site. Plugins load before anything asks.
	 *
	 * @return array<string, Builder>
	 */
	public static function active(): array {
		if ( null === self::$active ) {
			self::$active = array_filter(
				self::all(),
				static function ( Builder $builder ): bool {
					return $builder->isActive();
				}
			);
		}
		return self::$active;
	}

	/**
	 * Returns the active builders a post is built with. A page can use more than one.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, Builder>
	 */
	public static function forPost( int $post_id ): array {
		return array_filter(
			self::active(),
			static function ( Builder $builder ) use ( $post_id ): bool {
				return $builder->isUsed( $post_id );
			}
		);
	}

	/**
	 * Whether an active builder says a value never holds text, so the probe leaves it unmarked.
	 *
	 * @param string $path Path of the value inside its object.
	 */
	public static function holdsNoText( string $path ): bool {
		foreach ( self::active() as $builder ) {
			if ( $builder->holdsNoText( $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an active builder declares a value as text.
	 *
	 * @param string $rule Rule key of the value.
	 */
	public static function declaresText( string $rule ): bool {
		foreach ( self::active() as $builder ) {
			if ( $builder->declaresText( $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the plugin that stored a value declares it as text, see Builder::fieldHoldsText().
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null when no active builder says.
	 */
	public static function fieldHoldsText( string $owner, string $path ): ?bool {
		foreach ( self::active() as $builder ) {
			$declared = $builder->fieldHoldsText( $owner, $path );
			if ( null !== $declared ) {
				return $declared;
			}
		}
		return null;
	}

	/**
	 * Returns the name of the active builder that knows the field a value belongs to, such as
	 * Advanced Custom Fields for one of its fields.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when no active builder knows the value.
	 */
	public static function fieldSource( string $owner, string $path ): string {
		foreach ( self::active() as $builder ) {
			if ( null !== $builder->fieldHoldsText( $owner, $path ) ) {
				return $builder->name();
			}
		}
		return '';
	}

	/**
	 * Returns what an active builder calls the field a value belongs to.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when no active builder knows the value.
	 */
	public static function fieldLabel( string $owner, string $path ): string {
		foreach ( self::active() as $builder ) {
			$label = $builder->fieldLabel( $owner, $path );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Returns a subject an active builder adds to the editor.
	 *
	 * @param string $key Subject key.
	 * @return array|null
	 */
	public static function subject( string $key ): ?array {
		foreach ( self::active() as $builder ) {
			$subject = $builder->subject( $key );
			if ( null !== $subject ) {
				return $subject;
			}
		}
		return null;
	}

	/**
	 * Returns which part of every page an object of an active builder is, see Builder::sitePart().
	 *
	 * @param string $owner Object key.
	 * @return string header, footer, or empty.
	 */
	public static function sitePart( string $owner ): string {
		foreach ( self::active() as $builder ) {
			$part = $builder->sitePart( $owner );
			if ( '' !== $part ) {
				return $part;
			}
		}
		return '';
	}

	/**
	 * Returns the subjects active builders add, by builder.
	 *
	 * @return array<string, array> Builder name => groups, see Builder::subjects().
	 */
	public static function subjects(): array {
		$subjects = array();
		foreach ( self::active() as $builder ) {
			$groups = $builder->subjects();
			if ( $groups ) {
				$subjects[ $builder->name() ] = $groups;
			}
		}
		return $subjects;
	}

	/**
	 * Returns the texts active builders keep for a subject, see Builder::listTexts().
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 * @return array
	 */
	public static function listTexts( array $subject ): array {
		$texts = array();
		foreach ( self::active() as $builder ) {
			array_push( $texts, ...$builder->listTexts( $subject ) );
		}
		return $texts;
	}

	/**
	 * Hooks what active builders need on every request, see Builder::register().
	 */
	public static function register(): void {
		foreach ( self::active() as $builder ) {
			$builder->register();
		}
	}

	/**
	 * Whether an active builder declares a value as code.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @param string $rule  Rule key of the value.
	 */
	public static function isCode( string $owner, string $path, string $rule ): bool {
		foreach ( self::active() as $builder ) {
			if ( $builder->isCode( $owner, $path, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the meta keys active builders write again by themselves.
	 *
	 * @return string[]
	 */
	public static function regeneratedMeta(): array {
		$keys = array();
		foreach ( self::active() as $builder ) {
			$keys = array_merge( $keys, $builder->regeneratedMeta() );
		}
		return $keys;
	}

	/**
	 * Returns where a post is edited: in the editor of a builder it is built with, or in WordPress's.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function editUrl( int $post_id ): string {
		foreach ( self::forPost( $post_id ) as $builder ) {
			$url = $builder->editUrl( $post_id );
			if ( null !== $url ) {
				return $url;
			}
		}
		return (string) get_edit_post_link( $post_id, 'raw' );
	}

	/**
	 * Whether the request is the preview in the editor of an active builder.
	 */
	public static function isEditorPreview(): bool {
		foreach ( self::active() as $builder ) {
			if ( $builder->isEditorPreview() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prepares every active builder for the rest of a request SwiftLing changes.
	 *
	 * @param string $mode probe, control or translate.
	 */
	public static function start( string $mode ): void {
		$probe = swiftling_is_probe();
		foreach ( self::active() as $builder ) {
			$builder->start( $mode, $probe );
		}
	}
}
