<?php
/**
 * The language switcher as a classic widget.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * The language switcher as a classic widget, for sidebars of classic themes and the WordPress
 * widgets of page builders such as Elementor. Block themes and the block widget editor use the
 * block instead.
 */
final class Widget extends \WP_Widget {
	/**
	 * Sets up the widget.
	 */
	public function __construct() {
		parent::__construct(
			'swiftling_switcher',
			__( 'Language switcher', 'swiftling' ),
			array(
				'classname'                   => 'widget_swiftling_switcher',
				'description'                 => __( 'Lets visitors open the page in another language.', 'swiftling' ),
				'customize_selective_refresh' => true,
				'show_instance_in_rest'       => true,
			)
		);
	}

	/**
	 * Registers the widget.
	 */
	public static function registerWidget(): void {
		register_widget( self::class );
	}

	/**
	 * Prints the widget.
	 *
	 * @param array<string, mixed> $args     Sidebar markup.
	 * @param array<string, mixed> $instance Settings of this widget.
	 */
	public function widget( $args, $instance ): void {
		$instance = $this->complete( $instance );
		$switcher = Switcher::render(
			array(
				'style'     => $instance['style'],
				'flag'      => $instance['flag'],
				'name'      => $instance['name'],
				'code'      => $instance['code'],
				'flag_size' => $instance['flag_size'],
				'class'     => 'is-widget',
			)
		);
		if ( '' === $switcher ) {
			return;
		}

		/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
		$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup of the sidebar, from the theme.
		if ( '' !== (string) $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup of the sidebar, from the theme.
		}
		echo $switcher; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped piece by piece in Switcher::render().
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup of the sidebar, from the theme.
	}

	/**
	 * Prints the settings of the widget.
	 *
	 * @param array<string, mixed> $instance Settings of this widget.
	 * @return string
	 */
	public function form( $instance ) {
		$instance = $this->complete( $instance );
		$styles   = array(
			'dropdown'   => __( 'Dropdown', 'swiftling' ),
			'horizontal' => __( 'Horizontal list', 'swiftling' ),
			'vertical'   => __( 'Vertical list', 'swiftling' ),
		);
		$parts    = array(
			'flag' => __( 'Flag', 'swiftling' ),
			'name' => __( 'Language name', 'swiftling' ),
			'code' => __( 'Language code', 'swiftling' ),
		);
		$sizes    = array(
			'small'  => __( 'Small', 'swiftling' ),
			'medium' => __( 'Medium', 'swiftling' ),
			'large'  => __( 'Large', 'swiftling' ),
		);
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'swiftling' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"><?php esc_html_e( 'Style:', 'swiftling' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<?php foreach ( $styles as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $instance['style'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<?php foreach ( $parts as $part => $label ) : ?>
				<input type="checkbox" class="checkbox" id="<?php echo esc_attr( $this->get_field_id( $part ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( $part ) ); ?>" value="1" <?php checked( $instance[ $part ] ); ?>>
				<label for="<?php echo esc_attr( $this->get_field_id( $part ) ); ?>"><?php echo esc_html( $label ); ?></label><br>
			<?php endforeach; ?>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'flag_size' ) ); ?>"><?php esc_html_e( 'Flag size:', 'swiftling' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'flag_size' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'flag_size' ) ); ?>">
				<?php foreach ( $sizes as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $instance['flag_size'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
		return '';
	}

	/**
	 * Saves the settings of the widget.
	 *
	 * @param array<string, mixed> $new_instance Settings as submitted.
	 * @param array<string, mixed> $old_instance Settings before.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'     => sanitize_text_field( isset( $new_instance['title'] ) ? (string) $new_instance['title'] : '' ),
			'style'     => Settings::style( isset( $new_instance['style'] ) ? $new_instance['style'] : '', 'vertical' ),
			'flag'      => ! empty( $new_instance['flag'] ),
			'name'      => ! empty( $new_instance['name'] ),
			'code'      => ! empty( $new_instance['code'] ),
			'flag_size' => Settings::flagSize( isset( $new_instance['flag_size'] ) ? $new_instance['flag_size'] : '' ),
		);
	}

	/**
	 * Fills in what a new widget has not saved yet: a vertical list showing what the Switcher
	 * screen shows.
	 *
	 * @param array<string, mixed> $instance Settings of this widget.
	 * @return array{title: string, style: string, flag: bool, name: bool, code: bool, flag_size: string}
	 */
	private function complete( $instance ): array {
		$instance = is_array( $instance ) ? $instance : array();
		$show     = Settings::get()['show'];
		return array(
			'title'     => isset( $instance['title'] ) ? (string) $instance['title'] : '',
			'style'     => Settings::style( isset( $instance['style'] ) ? $instance['style'] : '', 'vertical' ),
			'flag'      => isset( $instance['flag'] ) ? (bool) $instance['flag'] : $show['flag'],
			'name'      => isset( $instance['name'] ) ? (bool) $instance['name'] : $show['name'],
			'code'      => isset( $instance['code'] ) ? (bool) $instance['code'] : $show['code'],
			'flag_size' => isset( $instance['flag_size'] ) ? Settings::flagSize( $instance['flag_size'] ) : $show['flag_size'],
		);
	}
}
