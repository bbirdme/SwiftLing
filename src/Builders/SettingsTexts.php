<?php
/**
 * A plugin that keeps the texts visitors read in settings of its own.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin whose texts visitors read are values in its settings (WordPress options), such as the
 * texts of a cookie banner. The class names which values of which options hold text, see
 * textOptions(): those are declared as text, see fieldHoldsText(), and every other value of the same
 * options as none, so a colour or a choice is never marked. They are a subject of their own on the
 * Templates screen, see subject(), because the page that shows them often hides them from a page
 * check, as a banner a plugin hides from bots, or shows them only after a visitor acts.
 *
 * A value read with get_option() while a page renders is translated there as any option SwiftLing
 * knows. A plugin that reads its settings before SwiftLing starts puts them in place itself, see
 * optionText().
 */
abstract class SettingsTexts extends Builder {
	/**
	 * Returns the values of the plugin's settings that hold text, by option name: the paths of the
	 * values inside each option, as "message_text" or "see_more_opt/text", or array( '' ) for an
	 * option that is one text.
	 *
	 * @return array<string, string[]>
	 */
	abstract protected function textOptions(): array;

	/**
	 * Returns the values of the plugin's settings that hold an address, such as a link to a page of
	 * its own: left to SwiftLing as any address, by option name and path.
	 *
	 * @return array<string, string[]>
	 */
	protected function addressOptions(): array {
		return array();
	}

	/**
	 * Returns what the editor calls each text, by option name and path, as textOptions() names them.
	 * A text without one is named after its path.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function textLabels(): array {
		return array();
	}

	/**
	 * Returns the key of the subject of the plugin's settings.
	 */
	protected function settingsSubject(): string {
		return $this->id() . '-settings';
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		/* translators: %s: name of a plugin, e.g. Complianz. */
		return sprintf( __( 'The texts %s keeps in its settings, such as those of its cookie banner. They show translated on every page in the language.', 'swiftling' ), $this->name() );
	}

	/**
	 * Returns a subject of the editor: the plugin's settings that hold text.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		if ( $this->settingsSubject() !== $key ) {
			return null;
		}
		return array(
			'key'   => $key,
			'kind'  => 'fields',
			/* translators: %s: name of a plugin, e.g. Complianz. */
			'title' => sprintf( __( '%s settings', 'swiftling' ), $this->name() ),
			'type'  => __( 'Settings', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => 'manage_options',
			'note'  => $this->settingsNote(),
		);
	}

	/**
	 * Returns the subject of the plugin's settings, for the Templates screen.
	 *
	 * @return array
	 */
	public function subjects(): array {
		return array(
			array(
				'title'       => $this->name(),
				/* translators: %s: name of a plugin, e.g. Complianz. */
				'description' => sprintf( __( 'Texts %s keeps in its settings.', 'swiftling' ), $this->name() ),
				'subjects'    => array(
					array(
						'key'         => $this->settingsSubject(),
						'title'       => __( 'Settings', 'swiftling' ),
						'description' => __( 'Texts visitors read', 'swiftling' ),
					),
				),
			),
		);
	}

	/**
	 * Returns the texts of the plugin's settings for their subject, read as a page in a language reads
	 * them, so their object and path are the ones translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( $this->settingsSubject() !== $subject['key'] ) {
			return array();
		}
		return $this->collect(
			function (): void {
				foreach ( array_keys( $this->textOptions() ) as $option ) {
					get_option( $option );
				}
				$this->readMoreTexts();
			}
		);
	}

	/**
	 * Reads texts of the plugin kept outside its settings that the subject of the settings offers too,
	 * such as rows of its own table, while swiftling_collect() notes them.
	 */
	protected function readMoreTexts(): void {
	}

	/**
	 * Reads texts as a page check would, see swiftling_collect(), and returns them for the editor, the
	 * values of options only where they hold text.
	 *
	 * @param callable $read Reads the texts.
	 * @return array<int, array{o: string, p: string, v: string, r: string, kind: string, place: string}>
	 */
	protected function collect( callable $read ): array {
		$texts = array();
		foreach ( swiftling_collect( $read ) as $leaf ) {
			if ( 'options' === $leaf['o'] && true !== $this->fieldHoldsText( 'options', $leaf['p'] ) ) {
				continue;
			}
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'paragraph',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns the option and the path inside it of a value of the plugin's settings.
	 *
	 * @param string $path Path of the value in the object of options, e.g. "option/cookie_notice_options/accept_text".
	 * @return array{0: string, 1: string}|null Null for a value of no option of the plugin.
	 */
	private function optionPath( string $path ): ?array {
		foreach ( array_keys( $this->textOptions() ) as $option ) {
			$prefix = 'option/' . swiftling_escape_segment( $option );
			if ( $path === $prefix ) {
				return array( $option, '' );
			}
			if ( 0 === strpos( $path, $prefix . '/' ) ) {
				return array( $option, substr( $path, strlen( $prefix ) + 1 ) );
			}
		}
		return null;
	}

	/**
	 * Declares the values of the plugin's settings that hold text, and every other value of the same
	 * options as none.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of no option of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 'options' !== $owner ) {
			return null;
		}
		$found = $this->optionPath( $path );
		if ( null === $found ) {
			return null;
		}
		foreach ( $this->textOptions()[ $found[0] ] as $text ) {
			if ( '' === $text || $found[1] === $text || 0 === strpos( $found[1], $text . '/' ) ) {
				return true;
			}
		}
		$addresses = $this->addressOptions();
		return isset( $addresses[ $found[0] ] ) && in_array( $found[1], $addresses[ $found[0] ], true ) ? null : false;
	}

	/**
	 * Returns what the editor calls a text of the plugin's settings.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is none of the plugin's settings.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		$found = 'options' === $owner ? $this->optionPath( $path ) : null;
		if ( null === $found ) {
			return '';
		}
		$labels = $this->textLabels();
		foreach ( $this->textOptions()[ $found[0] ] as $text ) {
			if ( '' === $text || $found[1] === $text || 0 === strpos( $found[1], $text . '/' ) ) {
				if ( isset( $labels[ $found[0] ][ $text ] ) ) {
					return $labels[ $found[0] ][ $text ];
				}
				return ucfirst( trim( str_replace( array( '_', '-', '/' ), ' ', '' !== $text ? $text : $found[0] ) ) );
			}
		}
		return '';
	}

	/**
	 * Returns a text of the plugin's settings in the mode of the moment, for a plugin that read its
	 * settings before SwiftLing started and keeps them: in a language its translation, in a page check
	 * with a marker, as SwiftLing reads any option.
	 *
	 * @param string $option Option name.
	 * @param string $path   Path of the value inside the option, as textOptions() names it.
	 * @param mixed  $value  The value, as stored.
	 * @return mixed
	 */
	protected function optionText( string $option, string $path, $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $value;
		}
		$mode = swiftling_mode();
		if ( 'translate' !== $mode && ( 'probe' !== $mode || ! did_action( 'template_redirect' ) ) ) {
			return $value;
		}
		// The value is walked in the shape it has in the option, so it has the path and the rule key
		// the subject of the settings lists it with.
		$shaped = $value;
		$parts  = '' === $path ? array() : explode( '/', $path );
		foreach ( array_reverse( $parts ) as $part ) {
			$shaped = array( $part => $shaped );
		}
		$walked = swiftling_transform( 'options', 'option/' . swiftling_escape_segment( $option ), 'option:' . $option, $shaped );
		foreach ( $parts as $part ) {
			if ( ! is_array( $walked ) || ! isset( $walked[ $part ] ) ) {
				return $value;
			}
			$walked = $walked[ $part ];
		}
		return is_string( $walked ) ? $walked : $value;
	}

	/**
	 * Returns a whole option of the plugin in the mode of the moment, for a plugin that hands the option
	 * out itself from a filter that runs before SwiftLing's own, as from a table of its own: as
	 * SwiftLing reads any option, see swiftling_filter_option().
	 *
	 * @param mixed  $value  The option, as the plugin handed it out, or false.
	 * @param string $option Option name.
	 * @return mixed
	 */
	protected function optionValue( $value, string $option ) {
		$state = &swiftling_state();
		if ( false === $value || $state['guard'] || ! self::inMode() ) {
			return $value;
		}
		if ( 'probe' === $state['mode'] && ! $state['collect'] && ! did_action( 'template_redirect' ) ) {
			return $value;
		}
		return swiftling_transform( 'options', 'option/' . swiftling_escape_segment( $option ), 'option:' . $option, $value );
	}

	/**
	 * Whether SwiftLing changes the request: a page check or a page in a language.
	 */
	protected static function inMode(): bool {
		return in_array( swiftling_mode(), array( 'probe', 'translate' ), true );
	}
}
