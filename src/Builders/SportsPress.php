<?php
/**
 * SportsPress: the labels of its results and tables in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * SportsPress. Events, teams, players, tables and lists are posts, their names and texts found as on
 * any post. The labels the plugin's sport brings, as the outcomes "Win" and "Loss", the results "1st
 * Half" and "Goals" and the columns of a table, "P", "W" and "Pts", are posts of types of their own,
 * whose titles the plugin prints straight from the post, past get_the_title(). They are texts of their
 * own, "sportspress:labels", each by its words, see HtmlTexts::key(), so the same label is translated
 * once: offered on the Templates screen, see listTexts(), and put in the mode of the moment wherever a
 * template of the plugin prints one whole, see translateOutput().
 *
 * Checked against the code of SportsPress 2.7 (the free plugin), in tests/ReviewsRecipesSportsTest.php,
 * and by hand on a site with the plugin. SportsPress Pro was not checked.
 */
class SportsPress extends Builder {
	/**
	 * The plugin's types of post that are labels.
	 */
	const LABELS = array( 'sp_outcome', 'sp_result', 'sp_performance', 'sp_column', 'sp_metric', 'sp_statistic' );

	/**
	 * The object key of the labels.
	 */
	const OWNER = 'sportspress:labels';

	/**
	 * Source of a label, for its rule key.
	 */
	const SOURCE = 'sportspress:label';

	/**
	 * The subject of the labels on the Templates screen.
	 */
	const SUBJECT = 'sportspress-labels';

	/**
	 * How many templates of the plugin are printing now, one inside another.
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * The labels' titles, read once a request.
	 *
	 * @var string[]|null
	 */
	private $labels = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'sportspress';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'SportsPress';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'SP_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin: an event or a team needs nothing of a builder.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Its posts need nothing of a builder.
		return false;
	}

	/**
	 * Hooks around each template the plugin prints.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own actions.
		add_action( 'sportspress_before_template', array( $this, 'startTemplate' ), 10, 0 );
		add_action( 'sportspress_after_template', array( $this, 'endTemplate' ), 10, 0 );
		// phpcs:enable
	}

	/**
	 * Before the plugin prints a template, in a page check or a page in a language: what it prints goes
	 * through translateOutput(), until endTemplate(). A template inside another goes with it.
	 */
	public function startTemplate(): void {
		if ( 0 === $this->depth && in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			ob_start( array( $this, 'translateOutput' ) );
			$this->depth = 1;
			return;
		}
		if ( $this->depth > 0 ) {
			++$this->depth;
		}
	}

	/**
	 * After the plugin printed a template: what it printed goes out, through translateOutput().
	 */
	public function endTemplate(): void {
		if ( $this->depth <= 0 ) {
			return;
		}
		--$this->depth;
		if ( 0 === $this->depth ) {
			ob_end_flush();
		}
	}

	/**
	 * Returns what the plugin printed with its labels in the mode of the moment, where each is printed
	 * whole.
	 *
	 * @param string $output What it printed.
	 */
	public function translateOutput( $output ) {
		return is_string( $output ) && '' !== $output ? WholeTexts::replace( $output, $this->labels(), array( $this, 'labelText' ) ) : $output;
	}

	/**
	 * Returns a label in the mode of the moment.
	 *
	 * @param string $label The label.
	 */
	public function labelText( string $label ): string {
		$text = swiftling_transform_text( self::OWNER, 'texts/' . HtmlTexts::key( $label ), self::SOURCE, $label );
		return is_string( $text ) ? $text : $label;
	}

	/**
	 * Returns the titles of the plugin's labels.
	 *
	 * @return string[]
	 */
	private function labels(): array {
		if ( null === $this->labels ) {
			$this->labels = array();
			foreach ( get_posts(
				array(
					'post_type'      => self::LABELS,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => array(
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
				)
			) as $post ) {
				if ( '' !== trim( $post->post_title ) && preg_match( '/\p{L}/u', $post->post_title ) ) {
					$this->labels[] = $post->post_title;
				}
			}
			$this->labels = array_values( array_unique( $this->labels ) );
		}
		return $this->labels;
	}

	/**
	 * Declares the labels as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		return self::OWNER === $owner ? true : null;
	}

	/**
	 * Returns what the editor calls a label.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Every label is one.
		return self::OWNER === $owner ? __( 'Label', 'swiftling' ) : '';
	}

	/**
	 * Returns a subject of the editor: the plugin's labels.
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
			'title' => __( 'Labels of SportsPress', 'swiftling' ),
			'type'  => __( 'Settings', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => 'manage_options',
			'note'  => __( 'The outcomes, results, performance and columns of tables your sport uses, as "Win", "Goals" and "Pts". Each is translated once and shows translated in every event, table and list.', 'swiftling' ),
		);
	}

	/**
	 * Returns the subject of the labels, for the Templates screen.
	 *
	 * @return array
	 */
	public function subjects(): array {
		if ( ! $this->labels() ) {
			return array();
		}
		return array(
			array(
				'title'       => $this->name(),
				'description' => __( 'The labels of its results and tables.', 'swiftling' ),
				'subjects'    => array(
					array(
						'key'         => self::SUBJECT,
						'title'       => __( 'Labels', 'swiftling' ),
						'description' => __( 'Outcomes, results, performance and columns', 'swiftling' ),
					),
				),
			),
		);
	}

	/**
	 * Returns the labels for their subject, read as a page in a language reads them.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( self::SUBJECT !== $subject['key'] ) {
			return array();
		}
		$labels = $this->labels();
		$texts  = array();
		foreach ( swiftling_collect(
			function () use ( $labels ): void {
				foreach ( $labels as $label ) {
					$this->labelText( $label );
				}
			}
		) as $leaf ) {
			if ( self::OWNER !== $leaf['o'] ) {
				continue;
			}
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'text',
				'place' => __( 'Label', 'swiftling' ),
			);
		}
		return $texts;
	}
}
