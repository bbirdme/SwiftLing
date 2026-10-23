<?php
/**
 * Part of the Translation service screen, see service.php and service-connected.php: where the key
 * is kept, and how to keep it in wp-config.php instead of the database.
 *
 * Expects $swiftling_key_service, the service, from the file that includes it.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Translation\Services;

defined( 'ABSPATH' ) || exit;

$swiftling_constant = '<code>' . esc_html( $swiftling_key_service->constant() ) . '</code>';
// Whether a key is saved on this screen. While wp-config.php holds one, the saved key is not used,
// but it is still in the database.
$swiftling_saved_too = '' !== (string) Services::setting( $swiftling_key_service->id(), 'key', '' );
?>
<?php if ( $swiftling_key_service->keyFromConstant() ) : ?>
	<div class="swiftling-service-key-place">
		<p>
			<?php
			/* translators: %s: name of a PHP constant, e.g. SWIFTLING_GOOGLE_API_KEY. */
			echo wp_kses( sprintf( __( 'The key is set in wp-config.php, as %s, so it stays out of the database.', 'swiftling' ), $swiftling_constant ), array( 'code' => array() ) );
			?>
		</p>
		<p class="swiftling-muted"><?php esc_html_e( 'To change it, edit that line in wp-config.php, then check the key again. To save a key on this screen instead, remove the line.', 'swiftling' ); ?></p>
		<?php if ( $swiftling_saved_too ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="swiftling_remove_service_key">
				<input type="hidden" name="service" value="<?php echo esc_attr( $swiftling_key_service->id() ); ?>">
				<?php wp_nonce_field( 'swiftling_remove_service_key' ); ?>
				<p class="swiftling-muted">
					<?php esc_html_e( 'A key saved earlier on this screen is still in the database, unused.', 'swiftling' ); ?>
					<button type="submit" class="swiftling-button is-link is-danger-text"><?php esc_html_e( 'Remove it from the database', 'swiftling' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
	</div>
<?php else : ?>
	<div class="swiftling-service-key-place">
		<p class="swiftling-muted">
			<?php
			echo esc_html(
				$swiftling_saved_too
					? __( 'The key is saved in the database of your site.', 'swiftling' )
					: __( 'A key saved here is kept in the database of your site.', 'swiftling' )
			);
			?>
		</p>
		<details class="swiftling-service-key-place__more">
			<summary><?php esc_html_e( 'Keep the key in wp-config.php instead', 'swiftling' ); ?></summary>
			<p><?php esc_html_e( 'The key then stays out of the database: out of its backups and exports, and out of copies of the site such as a staging site. Only someone who can edit the files of the site can change it.', 'swiftling' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Open wp-config.php in the main folder of your site.', 'swiftling' ); ?></li>
				<li>
					<?php
					/* translators: %s: the line in wp-config.php after which nothing is added, as code. */
					echo wp_kses( sprintf( __( 'Add this line above %s, with your key between the quotes:', 'swiftling' ), '<code>' . esc_html( "/* That's all, stop editing! */" ) . '</code>' ), array( 'code' => array() ) );
					?>
					<code class="swiftling-service-key-place__line"><?php echo esc_html( "define( '" . $swiftling_key_service->constant() . "', 'your-key' );" ); ?></code>
				</li>
				<li>
					<?php
					echo esc_html(
						$swiftling_saved_too
							? __( 'Come back to this screen and check the key again. The key saved here is then not used; remove it so it leaves the database too.', 'swiftling' )
							: __( 'Come back to this screen and check the key.', 'swiftling' )
					);
					?>
				</li>
			</ol>
		</details>
	</div>
<?php endif; ?>
