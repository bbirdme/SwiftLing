<?php
/**
 * Forms screen, "My form is not listed": the way to the texts of a form of a plugin SwiftLing has
 * no provider for. Such a form is found where it shows, so the page it is on is checked in the
 * translation editor, which is where its texts are then translated. Included by
 * admin/views/forms.php.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;

defined( 'ABSPATH' ) || exit;

$swiftling_types = array_keys( swiftling_content_types() );
$swiftling_pages = $swiftling_types ? get_posts(
	array(
		'post_type'      => $swiftling_types,
		'post_status'    => 'publish',
		// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- The pages to choose from, read once per screen; the cap keeps a very large site from building a list without end.
		'posts_per_page' => 200,
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
) : array();

Ui::screenHeader(
	array(
		/* translators: 1: name of the Forms screen, 2: title of this screen. */
		'crumb'       => sprintf( __( '%1$s › %2$s', 'swiftling' ), __( 'Forms', 'swiftling' ), __( 'My form is not listed', 'swiftling' ) ),
		'title'       => __( 'My form is not listed', 'swiftling' ),
		'description' => __( 'SwiftLing has a class for each form plugin it works with. For another plugin it can find a form’s texts on a page only where the plugin reads them the way WordPress does, as it does for a post.', 'swiftling' ),
		'actions'     => array(
			array( __( 'All forms', 'swiftling' ), 'secondary', admin_url( 'admin.php?page=swiftling-forms' ) ),
		),
	)
);
?>
<div class="swiftling-stack">
	<section class="swiftling-card">
		<div class="swiftling-card__head">
			<h2><?php esc_html_e( 'Check the page the form is on', 'swiftling' ); ?></h2>
		</div>
		<?php if ( ! $swiftling_pages ) : ?>
			<p class="swiftling-muted"><?php esc_html_e( 'This site has no published page yet. Once the page with the form is published, it can be checked here.', 'swiftling' ); ?></p>
		<?php else : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="swiftling-forms-check">
				<input type="hidden" name="page" value="swiftling-translation">
				<label for="swiftling-form-page"><?php esc_html_e( 'Page', 'swiftling' ); ?></label>
				<select id="swiftling-form-page" name="post">
					<?php foreach ( $swiftling_pages as $swiftling_page ) : ?>
						<option value="<?php echo esc_attr( (string) $swiftling_page->ID ); ?>"><?php echo esc_html( '' !== trim( $swiftling_page->post_title ) ? $swiftling_page->post_title : __( 'No title', 'swiftling' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="swiftling-button is-primary"><?php esc_html_e( 'Check page', 'swiftling' ); ?></button>
			</form>
		<?php endif; ?>
		<p class="swiftling-muted"><?php esc_html_e( 'What SwiftLing finds on the page is translated in that page’s editor. A plugin that keeps its forms in tables of its own often shows nothing here, and then it needs a class of its own. Messages that no page shows, such as an email or a ‘thank you’ after sending, stay out of reach for a plugin SwiftLing does not know.', 'swiftling' ); ?></p>
	</section>
	<section class="swiftling-card swiftling-request-offer">
		<div>
			<h2><?php esc_html_e( 'Your form plugin is not on the list?', 'swiftling' ); ?></h2>
			<p class="swiftling-muted"><?php esc_html_e( 'Ask us to add it: we test its fields, messages and emails in every language.', 'swiftling' ); ?></p>
		</div>
		<?php Ui::button( __( 'Request support for it', 'swiftling' ), 'primary', add_query_arg( 'kind', 'form', admin_url( 'admin.php?page=swiftling-request' ) ) ); ?>
	</section>
</div>
