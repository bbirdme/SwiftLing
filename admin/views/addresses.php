<?php
/**
 * Addresses screen: the word in front of every address of a list in each language, one language at
 * a time (see includes/bases.php), and how the address of a page tells its language. The words post
 * to swiftling_save_bases(), the format to swiftling_save_url_format() in includes/urls.php; the
 * original's folder shows as an option of language folders with CSS alone, and so do the hosts of
 * the subdomain and domain formats with how to set them up (includes/hosts.php).
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Admin\Views;

use SwiftLing\Admin\Ui;
use SwiftLing\Switcher\Flags;

defined( 'ABSPATH' ) || exit;

$swiftling_languages = swiftling_languages();
$swiftling_format    = swiftling_saved_url_format();
$swiftling_site      = untrailingslashit( (string) preg_replace( '#^https?://#i', '', home_url() ) );
$swiftling_example   = $swiftling_languages['targets'] ? (string) key( $swiftling_languages['targets'] ) : 'de';
$swiftling_source    = $swiftling_languages['source'];
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notices after the redirect.
$swiftling_notice  = isset( $_GET['swiftling_notice'] ) ? sanitize_key( wp_unslash( $_GET['swiftling_notice'] ) ) : '';
$swiftling_taken   = isset( $_GET['swiftling_taken'] ) ? array_filter( array_map( 'sanitize_title', explode( ',', sanitize_text_field( wp_unslash( $_GET['swiftling_taken'] ) ) ) ) ) : array();
$swiftling_refused = isset( $_GET['swiftling_refused'] ) && is_array( $_GET['swiftling_refused'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET['swiftling_refused'] ) ) : array();
$swiftling_lang    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
// After a check, the format that was checked stays chosen.
$swiftling_checked       = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
$swiftling_errors        = isset( $_GET['swiftling_domain_error'] ) && is_array( $_GET['swiftling_domain_error'] ) ? array_map( 'sanitize_key', wp_unslash( $_GET['swiftling_domain_error'] ) ) : array();
$swiftling_typed_domains = isset( $_GET['swiftling_typed'] ) && is_array( $_GET['swiftling_typed'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET['swiftling_typed'] ) ) : array();
// phpcs:enable
$swiftling_hosted   = ! is_multisite();
$swiftling_selected = $swiftling_hosted && in_array( $swiftling_checked, array( 'subdomain', 'domain' ), true ) ? $swiftling_checked : $swiftling_format;

// The host of every language in each host format, with what its last check found; a format is
// ready once every host leads here.
$swiftling_checks  = swiftling_address_checks();
$swiftling_domains = swiftling_saved_domains();
$swiftling_hosts   = array();
$swiftling_ready   = array();
foreach ( array( 'subdomain', 'domain' ) as $swiftling_host_format ) {
	$swiftling_hosts[ $swiftling_host_format ] = array();
	$swiftling_ready[ $swiftling_host_format ] = (bool) $swiftling_languages['targets'];
	foreach ( $swiftling_languages['targets'] as $swiftling_slug => $swiftling_language ) {
		$swiftling_host  = swiftling_target_host( $swiftling_host_format, (string) $swiftling_slug, $swiftling_language['locale'], $swiftling_domains );
		$swiftling_state = '' !== $swiftling_host && isset( $swiftling_checks[ $swiftling_host ]['state'] ) ? (string) $swiftling_checks[ $swiftling_host ]['state'] : '';

		$swiftling_hosts[ $swiftling_host_format ][ $swiftling_slug ] = array(
			'language' => $swiftling_language,
			'host'     => $swiftling_host,
			'state'    => $swiftling_state,
		);
		$swiftling_ready[ $swiftling_host_format ]                    = $swiftling_ready[ $swiftling_host_format ] && 'ok' === $swiftling_state;
	}
}
$swiftling_states = array(
	'ok'         => array( 'published', __( 'Leads here', 'swiftling' ), '' ),
	'no_answer'  => array( 'failed', __( 'Does not answer', 'swiftling' ), __( 'Add it at your host, or where you bought the domain. A new one can take up to a day.', 'swiftling' ) ),
	'no_https'   => array( 'failed', __( 'No secure certificate', 'swiftling' ), __( 'Turn on a certificate for it at your host. Most hosts do it for free.', 'swiftling' ) ),
	'other_site' => array( 'failed', __( 'Another site answers', 'swiftling' ), __( 'It shows another site, or sends visitors on to another address. Add it to this site at your host as an alias, not as a redirect.', 'swiftling' ) ),
	''           => array( 'untested', __( 'Not checked', 'swiftling' ), '' ),
);
if ( ! isset( $swiftling_languages['targets'][ $swiftling_lang ] ) ) {
	$swiftling_lang = (string) key( $swiftling_languages['targets'] );
}
$swiftling_target = '' !== $swiftling_lang ? $swiftling_languages['targets'][ $swiftling_lang ] : null;
$swiftling_types  = swiftling_content_types();

// The lists by the content they belong to, in the order of the content types; each with the object
// keys of the parts of its word.
$swiftling_groups = array();
$swiftling_owners = array();
foreach ( swiftling_base_lists() as $swiftling_key => $swiftling_list ) {
	$swiftling_list['owners'] = array();
	foreach ( array_keys( $swiftling_list['parts'] ) as $swiftling_index ) {
		$swiftling_list['owners'][ $swiftling_index ] = swiftling_base_owner( $swiftling_key, $swiftling_index );
		$swiftling_owners[]                           = $swiftling_list['owners'][ $swiftling_index ];
	}
	$swiftling_groups[ $swiftling_list['group'] ][ $swiftling_key ] = $swiftling_list;
}
$swiftling_groups = array_replace( array_intersect_key( array_fill_keys( array_keys( $swiftling_types ), array() ), $swiftling_groups ), $swiftling_groups );

// How many words each language has translated, for its tab.
$swiftling_done = array();
foreach ( $swiftling_languages['targets'] as $swiftling_slug => $swiftling_language ) {
	swiftling_prime_slugs( $swiftling_owners, $swiftling_language['locale'] );
	$swiftling_done[ $swiftling_slug ] = count( array_filter( array_map( 'swiftling_get_slug', $swiftling_owners, array_fill( 0, count( $swiftling_owners ), $swiftling_language['locale'] ) ) ) );
}
$swiftling_screen = admin_url( 'admin.php?page=swiftling-addresses' );

Ui::screenHeader(
	array(
		'crumb'       => __( 'Settings', 'swiftling' ),
		'title'       => __( 'URLs', 'swiftling' ),
		'description' => __( 'How the addresses of your pages read in each language.', 'swiftling' ),
	)
);
?>
<?php if ( 'saved' === $swiftling_notice ) : ?>
	<?php Ui::notice( 'success', array( 'title' => __( 'URLs saved.', 'swiftling' ) ) ); ?>
<?php elseif ( 'not_ready' === $swiftling_notice ) : ?>
	<?php Ui::notice( 'error', array( 'text' => __( 'Not saved yet: not every address leads to this site. The site keeps the addresses it has; below is what to do.', 'swiftling' ) ) ); ?>
<?php elseif ( 'checked' === $swiftling_notice && isset( $swiftling_ready[ $swiftling_selected ] ) && $swiftling_ready[ $swiftling_selected ] ) : ?>
	<?php Ui::notice( 'success', array( 'text' => __( 'Every address leads to this site. Save changes to switch to them.', 'swiftling' ) ) ); ?>
<?php elseif ( 'checked' === $swiftling_notice ) : ?>
	<?php Ui::notice( 'info', array( 'text' => __( 'Checked. Not every address leads to this site yet; below is what to do.', 'swiftling' ) ) ); ?>
<?php elseif ( 'bases_saved' === $swiftling_notice && ! $swiftling_refused ) : ?>
	<?php Ui::notice( 'success', array( 'text' => __( 'Words saved. Addresses with the earlier words lead to the new ones.', 'swiftling' ) ) ); ?>
<?php elseif ( 'bases_saved' === $swiftling_notice ) : ?>
	<?php Ui::notice( 'error', array( 'text' => __( 'Some words were not saved, because they are taken. They show below as you typed them.', 'swiftling' ) ) ); ?>
<?php endif; ?>
<?php Ui::takenFolders( $swiftling_taken ); ?>

<div class="swiftling-stack">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-card swiftling-bases">
		<input type="hidden" name="action" value="swiftling_save_bases">
		<input type="hidden" name="lang" value="<?php echo esc_attr( $swiftling_lang ); ?>">
		<?php wp_nonce_field( 'swiftling_save_bases' ); ?>
		<div class="swiftling-card__head">
			<h2><?php esc_html_e( 'Fixed words in addresses', 'swiftling' ); ?></h2>
		</div>
		<p class="swiftling-muted">
			<?php esc_html_e( 'Some addresses start with a word that says what they are, such as category in /category/news/ or product in /product/blue-shirt/. Translate the word once and every address that starts with it follows: links, the switcher, hreflang and the sitemap.', 'swiftling' ); ?>
		</p>
		<?php if ( ! $swiftling_target ) : ?>
			<?php
			Ui::notice(
				'info',
				array(
					'text'    => __( 'Choose the languages first.', 'swiftling' ),
					'link'    => array( __( 'Add languages', 'swiftling' ), admin_url( 'admin.php?page=swiftling-languages' ) ),
					'compact' => true,
				)
			);
			?>
		<?php elseif ( ! swiftling_directories_supported() ) : ?>
			<?php
			Ui::notice(
				'info',
				array(
					'text'    => __( 'Translated addresses need pretty permalinks: with plain ones, addresses name pages by their number.', 'swiftling' ),
					'link'    => array( __( 'Permalink settings', 'swiftling' ), admin_url( 'options-permalink.php' ) ),
					'compact' => true,
				)
			);
			?>
		<?php elseif ( ! $swiftling_groups ) : ?>
			<?php
			Ui::notice(
				'info',
				array(
					'text'    => __( 'No list of this site starts its addresses with a word of its own.', 'swiftling' ),
					'compact' => true,
				)
			);
			?>
		<?php else : ?>
			<nav class="swiftling-segmented swiftling-bases__languages" aria-label="<?php esc_attr_e( 'Language', 'swiftling' ); ?>">
				<?php foreach ( $swiftling_languages['targets'] as $swiftling_slug => $swiftling_language ) : ?>
					<?php $swiftling_current = $swiftling_lang === $swiftling_slug; ?>
					<a class="<?php echo $swiftling_current ? 'is-current' : ''; ?>"<?php echo $swiftling_current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( 'lang', $swiftling_slug, $swiftling_screen ) ); ?>">
						<?php $swiftling_flag = Flags::url( $swiftling_language['locale'] ); ?>
						<?php if ( '' !== $swiftling_flag ) : ?>
							<img class="swiftling-segmented__flag" src="<?php echo esc_url( $swiftling_flag ); ?>" alt="" width="20" height="14">
						<?php else : ?>
							<span class="swiftling-segmented__code"><?php echo esc_html( $swiftling_language['code'] ); ?></span>
						<?php endif; ?>
						<?php echo esc_html( $swiftling_language['name'] ); ?>
						<span class="swiftling-segmented__count">
							<?php
							/* translators: 1: words translated, 2: all words, as in 3/10. */
							echo esc_html( sprintf( __( '%1$s/%2$s', 'swiftling' ), number_format_i18n( $swiftling_done[ $swiftling_slug ] ), number_format_i18n( count( $swiftling_owners ) ) ) );
							?>
						</span>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="swiftling-table-wrap">
				<table class="swiftling-table swiftling-bases__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'List', 'swiftling' ); ?></th>
							<th><?php esc_html_e( 'Original', 'swiftling' ); ?></th>
							<?php /* translators: %s: language name, e.g. Deutsch. */ ?>
							<th><?php echo esc_html( sprintf( __( 'In %s', 'swiftling' ), $swiftling_target['name'] ) ); ?></th>
							<th><?php esc_html_e( 'Addresses then read', 'swiftling' ); ?></th>
						</tr>
					</thead>
					<?php foreach ( $swiftling_groups as $swiftling_group => $swiftling_lists ) : ?>
						<tbody>
							<tr class="swiftling-bases__group">
								<th colspan="4" scope="rowgroup"><?php echo esc_html( isset( $swiftling_types[ $swiftling_group ] ) ? $swiftling_types[ $swiftling_group ]->labels->name : $swiftling_group ); ?></th>
							</tr>
							<?php foreach ( $swiftling_lists as $swiftling_key => $swiftling_list ) : ?>
								<?php
								$swiftling_object = swiftling_base_object( $swiftling_list['kind'], $swiftling_list['name'] );
								if ( 'tax' === $swiftling_list['kind'] ) {
									$swiftling_count = (int) wp_count_terms(
										array(
											'taxonomy'   => $swiftling_list['name'],
											'hide_empty' => false,
										)
									);
									/* translators: %s: number of terms. */
									$swiftling_size   = sprintf( _n( '%s term', '%s terms', $swiftling_count, 'swiftling' ), number_format_i18n( $swiftling_count ) );
									$swiftling_sample = get_terms(
										array(
											'taxonomy'   => $swiftling_list['name'],
											'hide_empty' => false,
											'orderby'    => 'count',
											'order'      => 'DESC',
											'number'     => 1,
										)
									);
									$swiftling_sample = is_array( $swiftling_sample ) && $swiftling_sample ? swiftling_term_language_url( $swiftling_sample[0], $swiftling_lang ) : '';
								} else {
									$swiftling_count = (int) wp_count_posts( $swiftling_list['name'] )->publish;
									/* translators: %s: number of published posts of a content type. */
									$swiftling_size   = sprintf( _n( '%s published', '%s published', $swiftling_count, 'swiftling' ), number_format_i18n( $swiftling_count ) );
									$swiftling_sample = get_posts(
										array(
											'post_type' => $swiftling_list['name'],
											'posts_per_page' => 1,
											'no_found_rows' => true,
										)
									);
									$swiftling_sample = $swiftling_sample ? swiftling_post_language_url( $swiftling_sample[0], $swiftling_lang ) : '';
								}
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $swiftling_list['label'] ); ?></strong>
										<span class="swiftling-muted swiftling-bases__size"><?php echo esc_html( $swiftling_size ); ?></span>
									</td>
									<td>
										<code><?php echo esc_html( '/' . trim( (string) $swiftling_object->rewrite['slug'], '/' ) . '/' ); ?></code>
										<?php if ( in_array( $swiftling_list['name'], array( 'category', 'post_tag' ), true ) && 'tax' === $swiftling_list['kind'] ) : ?>
											<a class="swiftling-bases__set" href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'Set in Permalinks', 'swiftling' ); ?></a>
										<?php endif; ?>
									</td>
									<td>
										<span class="swiftling-bases__fields">
											<?php foreach ( $swiftling_list['parts'] as $swiftling_index => $swiftling_part ) : ?>
												<?php
												$swiftling_owner = $swiftling_list['owners'][ $swiftling_index ];
												$swiftling_typed = isset( $swiftling_refused[ $swiftling_owner ] ) ? swiftling_sanitize_slug( $swiftling_refused[ $swiftling_owner ], $swiftling_target['locale'] ) : null;
												$swiftling_value = null !== $swiftling_typed ? $swiftling_typed : swiftling_get_slug( $swiftling_owner, $swiftling_target['locale'] );
												$swiftling_error = null !== $swiftling_typed ? swiftling_base_taken( $swiftling_owner, $swiftling_target['locale'], $swiftling_typed ) : '';
												$swiftling_label = 1 < count( $swiftling_list['parts'] )
													/* translators: 1: name of a list, e.g. Products, 2: which part of its word, 3: language name. */
													? sprintf( __( '%1$s, part %2$s, in %3$s', 'swiftling' ), $swiftling_list['label'], number_format_i18n( $swiftling_index + 1 ), $swiftling_target['name'] )
													/* translators: 1: name of a list, e.g. Categories, 2: language name, e.g. Deutsch. */
													: sprintf( __( '%1$s in %2$s', 'swiftling' ), $swiftling_list['label'], $swiftling_target['name'] );
												$swiftling_id = 'swiftling-base-' . sanitize_html_class( str_replace( ':', '-', $swiftling_owner ) );
												?>
												<span class="swiftling-prefixed<?php echo '' !== $swiftling_error ? ' is-invalid' : ''; ?>">
													<?php if ( array_key_first( $swiftling_list['parts'] ) === $swiftling_index ) : ?>
														<code><?php echo esc_html( '/' . $swiftling_lang . '/' ); ?></code>
													<?php endif; ?>
													<input type="text" id="<?php echo esc_attr( $swiftling_id ); ?>" class="swiftling-mono" name="<?php echo esc_attr( 'bases[' . $swiftling_owner . ']' ); ?>" value="<?php echo esc_attr( rawurldecode( $swiftling_value ) ); ?>" placeholder="<?php echo esc_attr( $swiftling_part ); ?>" aria-label="<?php echo esc_attr( $swiftling_label ); ?>" maxlength="<?php echo esc_attr( (string) SWIFTLING_SLUG_LENGTH ); ?>" size="14" autocomplete="off" spellcheck="false"<?php echo '' !== $swiftling_error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $swiftling_id . '-error' ) . '"' : ''; ?>>
													<code>/</code>
												</span>
												<?php if ( '' !== $swiftling_error ) : ?>
													<span class="swiftling-bases__error" id="<?php echo esc_attr( $swiftling_id . '-error' ); ?>"><?php echo esc_html( $swiftling_error ); ?></span>
												<?php endif; ?>
											<?php endforeach; ?>
										</span>
									</td>
									<td>
										<?php if ( '' !== $swiftling_sample ) : ?>
											<code class="swiftling-bases__example"><?php echo esc_html( rawurldecode( (string) wp_parse_url( $swiftling_sample, PHP_URL_PATH ) ) ); ?></code>
										<?php else : ?>
											<span class="swiftling-muted"><?php esc_html_e( 'Nothing published yet', 'swiftling' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					<?php endforeach; ?>
				</table>
			</div>
			<div class="swiftling-card__foot">
				<span class="swiftling-muted"><?php esc_html_e( 'Empty: the original word. Addresses with the earlier word keep leading to the new one.', 'swiftling' ); ?></span>
				<?php /* translators: %s: language name, e.g. Deutsch. */ ?>
				<button type="submit" class="swiftling-button is-primary"><?php echo esc_html( sprintf( __( 'Save %s', 'swiftling' ), $swiftling_target['name'] ) ); ?></button>
			</div>
		<?php endif; ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swiftling-card swiftling-format-form" id="swiftling-format">
		<input type="hidden" name="action" value="swiftling_save_url_format">
		<?php wp_nonce_field( 'swiftling_save_url_format' ); ?>
		<div class="swiftling-format-layout">
			<div class="swiftling-format-main">
				<fieldset class="swiftling-format">
					<legend><h2><?php esc_html_e( 'Language in the URL', 'swiftling' ); ?></h2></legend>
					<p class="swiftling-muted"><?php esc_html_e( 'How the address of a page tells its language.', 'swiftling' ); ?></p>
					<label>
						<input type="radio" name="format" value="parameter" <?php checked( 'parameter', $swiftling_selected ); ?>>
						<span>
							<strong><?php esc_html_e( 'Parameter', 'swiftling' ); ?></strong>
							<code><?php echo esc_html( $swiftling_site . '/page/?lang=' . $swiftling_example ); ?></code>
						</span>
					</label>
					<label>
						<input type="radio" name="format" value="directory" <?php checked( 'directory', $swiftling_selected ); ?>>
						<span>
							<strong><?php esc_html_e( 'Language folder', 'swiftling' ); ?></strong>
							<code><?php echo esc_html( $swiftling_site . '/' . $swiftling_example . '/page/' ); ?></code>
						</span>
					</label>
					<label class="swiftling-format__source">
						<input type="checkbox" class="swiftling-toggle" name="source_folder" value="1" <?php checked( swiftling_saved_source_folder() ); ?>>
						<span>
							<?php /* translators: %s: language name, e.g. Hrvatski. */ ?>
							<strong><?php echo esc_html( sprintf( __( '%s in a folder too', 'swiftling' ), $swiftling_source['name'] ) ); ?></strong>
							<code><?php echo esc_html( $swiftling_site . '/' . $swiftling_source['slug'] . '/page/' ); ?></code>
							<span class="swiftling-muted"><?php esc_html_e( 'An address without a folder, the address of the site too, then leads to the same page in this folder.', 'swiftling' ); ?></span>
						</span>
					</label>
					<?php if ( $swiftling_hosted ) : ?>
						<label>
							<input type="radio" name="format" value="subdomain" <?php checked( 'subdomain', $swiftling_selected ); ?>>
							<span>
								<strong><?php esc_html_e( 'Subdomain', 'swiftling' ); ?> <span class="swiftling-format__step"><?php esc_html_e( '· needs a step at your host', 'swiftling' ); ?></span></strong>
								<code><?php echo esc_html( $swiftling_example . '.' . swiftling_subdomain_base() . '/page/' ); ?></code>
							</span>
						</label>
						<label>
							<input type="radio" name="format" value="domain" <?php checked( 'domain', $swiftling_selected ); ?>>
							<span>
								<strong><?php esc_html_e( 'Own domain', 'swiftling' ); ?> <span class="swiftling-format__step"><?php esc_html_e( '· needs a step at your host', 'swiftling' ); ?></span></strong>
								<code><?php echo esc_html( 'example.' . $swiftling_example . '/page/' ); ?></code>
							</span>
						</label>
					<?php endif; ?>
				</fieldset>

				<?php if ( $swiftling_hosted && $swiftling_languages['targets'] ) : ?>
					<?php foreach ( $swiftling_hosts as $swiftling_host_format => $swiftling_rows ) : ?>
						<div class="swiftling-hosts is-<?php echo esc_attr( $swiftling_host_format ); ?>">
							<div class="swiftling-hosts__head">
								<strong><?php echo esc_html( 'domain' === $swiftling_host_format ? __( 'The domain of each language', 'swiftling' ) : __( 'The address of each language', 'swiftling' ) ); ?></strong>
								<?php /* translators: 1: language name, e.g. Hrvatski, 2: the host of the site, e.g. example.com. */ ?>
								<span class="swiftling-muted"><?php echo esc_html( sprintf( __( '%1$s stays at %2$s', 'swiftling' ), $swiftling_source['name'], swiftling_home_host() ) ); ?></span>
							</div>
							<div class="swiftling-table-wrap">
								<table class="swiftling-table swiftling-hosts__table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Language', 'swiftling' ); ?></th>
											<th><?php echo esc_html( 'domain' === $swiftling_host_format ? __( 'Domain', 'swiftling' ) : __( 'Address', 'swiftling' ) ); ?></th>
											<th><?php esc_html_e( 'Leads to this site?', 'swiftling' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $swiftling_rows as $swiftling_row ) : ?>
											<?php
											$swiftling_language = $swiftling_row['language'];
											$swiftling_id       = 'swiftling-domain-' . $swiftling_language['locale'];
											$swiftling_error    = isset( $swiftling_errors[ $swiftling_language['locale'] ] ) ? $swiftling_errors[ $swiftling_language['locale'] ] : '';
											$swiftling_state    = isset( $swiftling_states[ $swiftling_row['state'] ] ) ? $swiftling_states[ $swiftling_row['state'] ] : $swiftling_states[''];
											?>
											<tr>
												<td>
													<?php if ( 'domain' === $swiftling_host_format ) : ?>
														<label for="<?php echo esc_attr( $swiftling_id ); ?>"><?php Ui::lang( $swiftling_language['code'] ); ?> <?php echo esc_html( $swiftling_language['name'] ); ?></label>
													<?php else : ?>
														<?php Ui::lang( $swiftling_language['code'] ); ?> <?php echo esc_html( $swiftling_language['name'] ); ?>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( 'domain' === $swiftling_host_format ) : ?>
														<input type="text" id="<?php echo esc_attr( $swiftling_id ); ?>" class="swiftling-mono<?php echo '' !== $swiftling_error ? ' is-invalid' : ''; ?>" name="<?php echo esc_attr( 'domains[' . $swiftling_language['locale'] . ']' ); ?>" value="<?php echo esc_attr( isset( $swiftling_typed_domains[ $swiftling_language['locale'] ] ) ? $swiftling_typed_domains[ $swiftling_language['locale'] ] : $swiftling_row['host'] ); ?>" placeholder="<?php echo esc_attr( 'example.' . $swiftling_language['slug'] ); ?>" data-swiftling-domain<?php echo '' !== $swiftling_error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $swiftling_id . '-error' ) . '"' : ''; ?>>
														<?php if ( '' !== $swiftling_error ) : ?>
															<span class="swiftling-hosts__error" id="<?php echo esc_attr( $swiftling_id . '-error' ); ?>">
																<?php
																if ( 'taken' === $swiftling_error ) {
																	esc_html_e( 'The site or another language has this domain.', 'swiftling' );
																} elseif ( 'invalid' === $swiftling_error ) {
																	esc_html_e( 'This is not a domain. Type it as example.de, without a path.', 'swiftling' );
																} else {
																	/* translators: %s: language name, e.g. Deutsch. */
																	echo esc_html( sprintf( __( 'Enter a domain for %s.', 'swiftling' ), $swiftling_language['name'] ) );
																}
																?>
															</span>
														<?php endif; ?>
													<?php else : ?>
														<code><?php echo esc_html( $swiftling_row['host'] ); ?></code>
													<?php endif; ?>
												</td>
												<td>
													<?php Ui::badge( $swiftling_state[0], $swiftling_state[1] ); ?>
													<?php if ( '' !== $swiftling_state[2] ) : ?>
														<span class="swiftling-hosts__todo swiftling-muted"><?php echo esc_html( $swiftling_state[2] ); ?></span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<p class="swiftling-muted swiftling-hosts__note">
								<?php
								echo esc_html(
									'domain' === $swiftling_host_format
										? __( 'Only the domain, without https:// and without a path. Each language needs one of its own.', 'swiftling' )
										: __( 'The part in front, such as de, is the address of the language, set on Languages.', 'swiftling' )
								);
								?>
							</p>
							<?php
							$swiftling_waiting = array();
							foreach ( $swiftling_rows as $swiftling_row ) {
								if ( 'ok' !== $swiftling_row['state'] ) {
									$swiftling_waiting[] = $swiftling_row['language']['name'];
								}
							}
							?>
							<?php
							// A few languages by name; more as their number, so the line stays short.
							$swiftling_who = count( $swiftling_waiting ) > 3
								/* translators: %d: number of languages. */
								? sprintf( _n( '%d language', '%d languages', count( $swiftling_waiting ), 'swiftling' ), count( $swiftling_waiting ) )
								: wp_sprintf( '%l', $swiftling_waiting );
							?>
							<?php if ( $swiftling_waiting && $swiftling_host_format !== $swiftling_format ) : ?>
								<p class="swiftling-hosts__status">
									<?php
									/* translators: %s: language names, e.g. Deutsch and Français, or a number of languages, e.g. 5 languages. */
									echo esc_html( sprintf( _n( '%s does not lead here yet, so the site keeps its addresses for now.', '%s do not lead here yet, so the site keeps its addresses for now.', count( $swiftling_waiting ), 'swiftling' ), $swiftling_who ) );
									?>
								</p>
							<?php elseif ( $swiftling_waiting ) : ?>
								<p class="swiftling-hosts__status">
									<?php
									/* translators: %s: language names, e.g. Deutsch and Français, or a number of languages, e.g. 5 languages. */
									echo esc_html( sprintf( _n( '%s does not lead here, so it is not on the site until it does.', '%s do not lead here, so they are not on the site until they do.', count( $swiftling_waiting ), 'swiftling' ), $swiftling_who ) );
									?>
								</p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<?php if ( isset( $swiftling_checks['#fonts']['state'] ) && 'blocked' === $swiftling_checks['#fonts']['state'] ) : ?>
						<div class="swiftling-hosts swiftling-hosts__fonts is-subdomain is-domain">
							<?php /* translators: %s: the host of the site, e.g. example.com. */ ?>
							<strong><?php echo esc_html( sprintf( __( 'Fonts at %s do not load on the other addresses yet', 'swiftling' ), swiftling_home_host() ) ); ?></strong>
							<p class="swiftling-muted"><?php esc_html_e( 'Pages work, but some themes, such as Divi, miss their icons there: they keep the address of their fonts in a file every address shares. Your host, or whoever runs your server, can allow the fonts with these lines, then press Check again.', 'swiftling' ); ?></p>
							<span><?php esc_html_e( 'Apache, in .htaccess:', 'swiftling' ); ?></span>
							<pre><code>&lt;IfModule mod_headers.c&gt;
&lt;FilesMatch "\.(woff2?|ttf|otf|eot)$"&gt;
Header set Access-Control-Allow-Origin "*"
&lt;/FilesMatch&gt;
&lt;/IfModule&gt;</code></pre>
							<span><?php esc_html_e( 'nginx, in the server block:', 'swiftling' ); ?></span>
							<pre><code>location ~* \.(woff2?|ttf|otf|eot)$ {
	add_header Access-Control-Allow-Origin "*";
}</code></pre>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<?php if ( $swiftling_hosted && $swiftling_languages['targets'] ) : ?>
				<?php
				$swiftling_first = (string) key( $swiftling_languages['targets'] );
				$swiftling_home  = swiftling_home_host();
				?>
				<?php foreach ( array( 'subdomain', 'domain' ) as $swiftling_host_format ) : ?>
					<aside class="swiftling-format-help is-<?php echo esc_attr( $swiftling_host_format ); ?>">
						<section class="swiftling-format-help__steps">
							<h3><?php esc_html_e( 'How to set it up', 'swiftling' ); ?></h3>
							<ol>
								<?php if ( 'subdomain' === $swiftling_host_format ) : ?>
									<li>
										<span>
											<?php /* translators: 1: a language's subdomain, e.g. de.example.com, 2: the host of the site, e.g. example.com. */ ?>
											<?php echo esc_html( sprintf( __( 'At your host, add %1$s and the others to this site, with the same folder as %2$s. Hosts call it a subdomain or an alias.', 'swiftling' ), $swiftling_hosts['subdomain'][ $swiftling_first ]['host'], $swiftling_home ) ); ?>
											<strong><?php esc_html_e( 'Not a redirect: each address has to show this site itself.', 'swiftling' ); ?></strong>
										</span>
									</li>
									<li><?php esc_html_e( 'Turn on a secure certificate (https) for each. Most hosts do it for free.', 'swiftling' ); ?></li>
								<?php else : ?>
									<?php /* translators: %s: the host of the site, e.g. example.com. */ ?>
									<li><?php echo esc_html( sprintf( __( 'Where you bought each domain, point it to the same server as %s.', 'swiftling' ), $swiftling_home ) ); ?></li>
									<li>
										<span>
											<?php /* translators: %s: the host of the site, e.g. example.com. */ ?>
											<?php echo esc_html( sprintf( __( 'At your host, add the domains to this site as an alias or a parked domain, with the same folder as %s, and turn on a secure certificate (https) for each.', 'swiftling' ), $swiftling_home ) ); ?>
											<strong><?php esc_html_e( 'Not a redirect or forwarding: each domain has to show this site itself.', 'swiftling' ); ?></strong>
										</span>
									</li>
								<?php endif; ?>
								<li><?php esc_html_e( 'Press Check again to see each address. Save changes checks them too, and switches once every address leads here.', 'swiftling' ); ?></li>
							</ol>
							<div class="swiftling-format-help__guides">
								<h4><?php esc_html_e( 'How hosts do it', 'swiftling' ); ?></h4>
								<ul>
									<?php
									// Each host's own guide, checked to describe a second address that shows the same site.
									$swiftling_guides = array(
										array( 'cPanel', 'https://docs.cpanel.net/cpanel/domains/domains/create-a-new-domain/', __( 'Create a New Domain, with Share document root on', 'swiftling' ) ),
										array( 'Plesk', 'https://docs.plesk.com/en-US/obsidian/customer-guide/websites-and-domains/domains-and-dns/adding-domain-aliases.65286/', __( 'Add Domain Alias, with Redirect with the HTTP 301 code off', 'swiftling' ) ),
										array( 'SiteGround', 'https://www.siteground.com/kb/parked-domains/', __( 'Domain, then Parked Domains', 'swiftling' ) ),
										array( 'Hostinger', 'https://www.hostinger.com/support/1583404-how-to-park-a-domain-at-hostinger/', __( 'Parked Domains', 'swiftling' ) ),
									);
									?>
									<?php foreach ( $swiftling_guides as $swiftling_guide ) : ?>
										<li>
											<a href="<?php echo esc_url( $swiftling_guide[1] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $swiftling_guide[0] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'swiftling' ); ?></span></a>
											<span class="swiftling-muted"><?php echo esc_html( $swiftling_guide[2] ); ?></span>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						</section>
						<section class="swiftling-format-help__know">
							<h3><?php esc_html_e( 'Good to know', 'swiftling' ); ?></h3>
							<ul>
								<?php /* translators: %s: the host of the site, e.g. example.com. */ ?>
								<li><?php echo esc_html( sprintf( __( 'Your dashboard stays at %s.', 'swiftling' ), $swiftling_home ) ); ?></li>
								<?php /* translators: %s: an address in a language folder, e.g. example.com/de/page/. */ ?>
								<li><?php echo esc_html( sprintf( __( 'Old addresses such as %s lead to the new ones.', 'swiftling' ), $swiftling_site . '/' . $swiftling_first . '/page/' ) ); ?></li>
								<?php if ( 'subdomain' === $swiftling_host_format ) : ?>
									<li>
										<?php /* translators: 1: the host of the site, e.g. example.com, 2: a language's subdomain, e.g. de.example.com. */ ?>
										<?php echo esc_html( sprintf( __( 'You stay signed in only at %1$s, so the toolbar is missing on %2$s. To keep it, add this line to wp-config.php:', 'swiftling' ), $swiftling_home, $swiftling_hosts['subdomain'][ $swiftling_first ]['host'] ) ); ?>
										<span class="swiftling-format-help__line">
											<code id="swiftling-cookie-line"><?php echo esc_html( swiftling_cookie_domain_line() ); ?></code>
											<button type="button" class="swiftling-button is-ghost" data-swiftling-copy="swiftling-cookie-line" hidden><?php esc_html_e( 'Copy', 'swiftling' ); ?></button>
										</span>
									</li>
								<?php else : ?>
									<?php /* translators: %s: the host of the site, e.g. example.com. */ ?>
									<li><?php echo esc_html( sprintf( __( 'You stay signed in only at %s, so the toolbar is missing on the other domains. Edit translations from the dashboard.', 'swiftling' ), $swiftling_home ) ); ?></li>
								<?php endif; ?>
								<li><?php esc_html_e( 'Add each address to Google Search Console.', 'swiftling' ); ?></li>
							</ul>
						</section>
					</aside>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php if ( ! swiftling_directories_supported() ) : ?>
			<?php
			Ui::notice(
				'info',
				array(
					'html'    => sprintf(
						/* translators: %s: link to the Permalinks settings screen. */
						esc_html__( 'Language folders need pretty permalinks. Until you choose a structure other than Plain or one starting with index.php on the %s screen, pages open with ?lang= in either case.', 'swiftling' ),
						'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Permalinks', 'swiftling' ) . '</a>'
					),
					'compact' => true,
					'class'   => 'swiftling-format__permalinks',
				)
			);
			?>
		<?php endif; ?>
		<div class="swiftling-card__foot">
			<span class="swiftling-muted swiftling-format__note"><?php esc_html_e( 'The address of each language, such as de, is set on Languages.', 'swiftling' ); ?></span>
			<?php if ( $swiftling_hosted && $swiftling_languages['targets'] ) : ?>
				<span class="swiftling-format__save-note"><?php esc_html_e( 'Save changes checks every address first, and switches once all of them lead here.', 'swiftling' ); ?></span>
			<?php endif; ?>
			<span class="swiftling-format__buttons">
				<?php if ( $swiftling_hosted && $swiftling_languages['targets'] ) : ?>
					<button type="submit" name="swiftling_do" value="check" class="swiftling-button swiftling-format__check"><?php esc_html_e( 'Check again', 'swiftling' ); ?></button>
				<?php endif; ?>
				<button type="submit" name="swiftling_do" value="save" class="swiftling-button is-primary"><?php esc_html_e( 'Save changes', 'swiftling' ); ?></button>
			</span>
		</div>
	</form>
</div>
