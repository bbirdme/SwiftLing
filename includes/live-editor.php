<?php
/**
 * The translation editor for real content: links to it and the parts the server renders.
 *
 * The editor screen probes the page in the browser and sends the result to the REST route in
 * rest.php, which answers with the rows rendered here, so every row looks like the rest of the
 * SwiftLing screens.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Translate link to posts and pages in the admin lists.
 *
 * @param array   $actions Row actions.
 * @param WP_Post $post    Post.
 * @return array
 */
function swiftling_row_actions( $actions, $post ) {
	if ( current_user_can( SWIFTLING_TRANSLATE ) && ( current_user_can( 'edit_post', $post->ID ) || swiftling_is_translator() ) && is_post_type_viewable( $post->post_type ) && ! swiftling_copy_original_id( $post ) ) {
		$actions['swiftling'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( swiftling_editor_url( $post->ID ) ),
			esc_html__( 'Translate', 'swiftling' )
		);
	}
	return $actions;
}
add_filter( 'post_row_actions', 'swiftling_row_actions', 10, 2 );
add_filter( 'page_row_actions', 'swiftling_row_actions', 10, 2 );

/**
 * Renders the rows of the editor, by section: for a page its text, then its excerpt for lists of
 * posts and its texts for search results, when it has any; for a template its own text, then
 * what every page shows. The first section needs no heading.
 *
 * Images, videos, links and code are rows like the texts, in the order the page shows them, but
 * marked as extras, which the editor hides until the user asks to see them.
 *
 * @param array  $subject Subject the editor is open for, see swiftling_subject().
 * @param string $lang    Language shown.
 * @param array  $result  Result of swiftling_save_probe() or swiftling_save_term_list().
 * @return string HTML: one tbody per section.
 */
function swiftling_live_rows_html( $subject, $lang, $result ) {
	// Every place the editor shows, with its link and the previous version of a changed text.
	$places = array();
	foreach ( $result['sections'] as $units ) {
		foreach ( $units as $unit ) {
			foreach ( isset( $unit['link'] ) ? array( $unit, $unit['link'] ) : array( $unit ) as $entry ) {
				$map = isset( $result['maps'][ $entry['o'] ] ) ? $result['maps'][ $entry['o'] ]['units'] : array();
				array_push( $places, ...array_filter( swiftling_live_places( $entry, $map ) ) );
			}
		}
	}
	$language     = swiftling_target_language( $lang );
	$translations = swiftling_get_translations( $language['locale'], $places );
	swiftling_live_placeholder( $language );
	// The fields stored with a page and its excerpt follow its texts without a title: each row
	// names its field, see swiftling_live_row().
	$titles = array(
		'search' => __( 'Search results', 'swiftling' ),
		'site'   => __( 'Shown on every page: header, footer, menu and site title', 'swiftling' ),
	);
	// What a section holds, where its title alone does not say it.
	$intros = array();
	$empty  = 'post' === $subject['kind'] ? __( 'No text of this page shows on it.', 'swiftling' ) : __( 'This template has no text of its own: what it shows comes from the posts and from the parts every page shows.', 'swiftling' );
	if ( in_array( $subject['kind'], array( 'terms', 'fields' ), true ) ) {
		$empty = __( 'Nothing to translate yet.', 'swiftling' );
	}
	if ( 'terms' === $subject['kind'] && ! empty( $result['list']['search'] ) ) {
		$empty = __( 'No term has these words.', 'swiftling' );
	}
	if ( 'package' === $subject['kind'] ) {
		$empty = __( 'No texts in this list.', 'swiftling' );
	}
	if ( 'comments' === $subject['kind'] ) {
		$empty = ! empty( $result['list']['search'] ) ? __( 'No comment has these words.', 'swiftling' ) : __( 'No comments here.', 'swiftling' );
	}
	// Rows of a menu without a field, such as the language switcher, by the number of texts before them.
	$info = 'menu' === $subject['kind'] && ! empty( $result['info'] ) ? $result['info'] : array();
	// Items of a menu left out in the language have no rows: a closed list names them instead.
	$left_out = 'menu' === $subject['kind'] ? swiftling_menu_left_out_names( (int) substr( $subject['key'], 5 ), $language['locale'] ) : array();
	if ( $left_out ) {
		list( $result['sections']['page'], $info ) = swiftling_live_without_left_out( $result['sections']['page'], $info, $left_out );
	}
	if ( 'menu' === $subject['kind'] ) {
		$empty = $info ? __( 'Nothing in this menu to translate here.', 'swiftling' ) : __( 'This menu has no items yet.', 'swiftling' );
		// Said once for every item that shows its page's title, rather than under each of them.
		$follows = array_filter(
			$result['sections']['page'],
			static function ( $unit ) {
				return ! empty( $unit['follows'] );
			}
		);
		if ( $follows ) {
			$intros['page'] = __( 'An item without a label of its own shows its page’s title, translated with the page. A label written here shows in this menu only, instead of it.', 'swiftling' );
		}
	}
	// The classic menus a template shows are translated on the Menus screen: its section of what
	// every page shows points there.
	$menus = in_array( $subject['kind'], array( 'template', 'view' ), true ) && isset( $result['units'] ) && current_user_can( 'edit_theme_options' ) ? swiftling_template_menus( $result['units'] ) : array();

	// Each group of rows: the sections of a page or template; for a list of terms, each term, under
	// its name, with the fields of the term its page does not show.
	$groups = array();
	if ( 'terms' === $subject['kind'] && ! empty( $result['terms'] ) && $result['sections']['page'] ) {
		foreach ( $result['sections']['page'] as $unit ) {
			if ( ! isset( $groups[ $unit['o'] ] ) ) {
				$groups[ $unit['o'] ] = array(
					'section' => 'page',
					'title'   => isset( $result['terms'][ $unit['o'] ] ) ? $result['terms'][ $unit['o'] ] : '',
					'units'   => array(),
					// The list itself, as the name of an attribute, has no address or fields of a term.
					'term'    => 0 === strpos( $unit['o'], 'term:' ) ? $unit['o'] : '',
				);
			}
			$groups[ $unit['o'] ]['units'][] = $unit;
		}
	} else {
		foreach ( $result['sections'] as $section => $units ) {
			$groups[] = array(
				'section' => $section,
				'title'   => isset( $titles[ $section ] ) ? $titles[ $section ] : '',
				'units'   => $units,
				'term'    => '',
			);
		}
	}

	// The translated slugs of the terms on this page, when their list has pages of its own.
	$addresses = null;
	if ( 'terms' === $subject['kind'] && '' !== $subject['url'] && $groups ) {
		$addresses = swiftling_directories_supported() ? swiftling_term_slug_entries( array_keys( $groups ), $language['locale'] ) : array();
	}

	ob_start();
	foreach ( $groups as $group ) {
		$section = $group['section'];
		$units   = $group['units'];
		$pointer = 'site' === $section && $menus;
		// A section without a title that holds only images, links or code hides with them: a note
		// that it holds them would stand under the page's texts without saying whose they are.
		$extras_only = 'page' !== $section && '' === $group['title'] && $units && ! array_filter( $units, 'swiftling_live_is_text_row' );
		if ( ! $units && 'page' !== $section && ! $pointer ) {
			continue;
		}
		?>
		<tbody class="swiftling-live-group<?php echo '' !== $group['term'] ? ' is-term' : ''; ?><?php echo $extras_only ? ' is-extras-only' : ''; ?>">
			<?php if ( '' !== $group['title'] ) : ?>
				<tr class="swiftling-editor__section">
					<th colspan="4"><h2><?php echo esc_html( $group['title'] ); ?></h2></th>
				</tr>
			<?php endif; ?>
			<?php if ( isset( $intros[ $section ] ) ) : ?>
				<tr class="swiftling-editor__intro"><td colspan="4" class="swiftling-muted"><?php echo esc_html( $intros[ $section ] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( ! $units && ! $pointer ) : ?>
				<tr><td colspan="4" class="swiftling-muted"><?php echo esc_html( $empty ); ?></td></tr>
			<?php elseif ( ! $extras_only && ! array_filter( $units, 'swiftling_live_is_text_row' ) ) : ?>
				<tr class="swiftling-live-only-extras"><td colspan="4" class="swiftling-muted"><?php esc_html_e( 'Only images, videos, links or code here. Switch on “Show images, links and code” to see them.', 'swiftling' ); ?></td></tr>
			<?php endif; ?>
			<?php
			// Every place is a row of its own, also the same words a second time: each can be translated differently.
			$rows = swiftling_live_image_groups( $units );
			foreach ( $rows as $index => $unit ) {
				foreach ( isset( $info[ $index ] ) ? $info[ $index ] : array() as $row ) {
					swiftling_live_info_row( $row );
				}
				$map = isset( $result['maps'][ $unit['o'] ] ) ? $result['maps'][ $unit['o'] ]['units'] : array();
				if ( 'search' === $section ) {
					// The title of the section already says where these texts show.
					$unit['region'] = '';
				}
				if ( 'menu' === $subject['kind'] ) {
					$unit['note'] = swiftling_menu_row_note( $unit, $lang );
					if ( ! empty( $unit['follows'] ) ) {
						$unit['fallback']    = swiftling_menu_row_fallback( $unit, $lang );
						$unit['placeholder'] = '' !== $unit['fallback']['text']
							/* translators: %s: the page's translated title. */
							? sprintf( __( 'Shows the page’s title: “%s”', 'swiftling' ), wp_strip_all_tags( $unit['fallback']['text'] ) )
							: __( 'Shows the page’s title, not translated yet', 'swiftling' );
					}
				} elseif ( 'post' === $subject['kind'] && $unit['o'] === $subject['key'] && 'field/post_title' === $unit['p'] ) {
					// The page's own title, wherever its page check found it: as a heading, or only
					// as a link of a menu, as a builder's page in a theme that leaves out the title.
					$unit['label']  = __( 'Title', 'swiftling' );
					$unit['region'] = '';
					$unit['note']   = swiftling_title_menus_note( $subject['post']->ID, $lang );
				}
				swiftling_live_row( $unit, $map, $translations, swiftling_can_translate_object( $unit['o'] ) );
			}
			// Rows after the last text, such as the language switcher at the end of a menu.
			foreach ( $info as $at => $after ) {
				if ( $at >= count( $rows ) ) {
					array_map( 'swiftling_live_info_row', $after );
				}
			}
			if ( $pointer ) {
				swiftling_live_menu_pointer( $menus, $lang );
			}
			if ( $left_out && 'page' === $section ) {
				swiftling_live_left_out_row( $subject, $language, $left_out );
			}
			if ( '' !== $group['term'] && null !== $addresses ) {
				swiftling_live_term_address( $lang, $group['term'], $addresses );
			}
			if ( '' !== $group['term'] ) {
				swiftling_live_term_more( $subject, $lang, $group['term'], $result['fields'] );
			}
			?>
		</tbody>
		<?php
	}
	return (string) ob_get_clean();
}

/**
 * Prints the address of a term in the editor of its list, under the term's texts: its original
 * slug, and a field for its slug in the language, saved on its own, see swiftling_rest_slug(). An
 * empty field means the original slug. Translated addresses need pretty permalinks; without them
 * the row says so.
 *
 * @param string     $lang      Language slug the editor shows.
 * @param string     $owner     Object key of the term.
 * @param array|null $addresses Translated slugs of the page's terms, see swiftling_term_slug_entries();
 *                              empty without pretty permalinks.
 */
function swiftling_live_term_address( $lang, $owner, $addresses ) {
	$term = get_term( (int) substr( $owner, 5 ) );
	if ( ! $term instanceof WP_Term ) {
		return;
	}
	$language  = swiftling_target_language( $lang );
	$permalink = swiftling_directories_supported();
	$address   = $permalink ? swiftling_term_address( $term, $lang, isset( $addresses[ $owner ] ) ? $addresses[ $owner ] : null ) : null;
	$id        = 'swiftling-term-slug-' . $term->term_id;
	$link      = get_term_link( $term );
	$original  = is_wp_error( $link ) ? '' : rawurldecode( (string) wp_parse_url( $link, PHP_URL_PATH ) );
	?>
	<tr class="swiftling-term-address-row">
		<th scope="row"><?php esc_html_e( 'Address', 'swiftling' ); ?></th>
		<td class="swiftling-live-original"><code><?php echo esc_html( $original ); ?></code></td>
		<td class="swiftling-editor__translation">
			<?php if ( ! $address || '' === $address['url'] ) : ?>
				<p class="swiftling-muted">
					<?php esc_html_e( 'Translated addresses need pretty permalinks: with plain ones, addresses name pages by their number.', 'swiftling' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'Permalink settings', 'swiftling' ); ?></a>
				</p>
			<?php else : ?>
				<form class="swiftling-term-address" data-term="<?php echo esc_attr( (string) $term->term_id ); ?>">
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
						<?php
						/* translators: 1: name of a category or tag, 2: language name, e.g. Deutsch. */
						echo esc_html( sprintf( __( 'Slug of “%1$s” in %2$s', 'swiftling' ), $term->name, $language['name'] ) );
						?>
					</label>
					<code class="swiftling-address__prefix swiftling-mono swiftling-term-address__prefix"><?php echo esc_html( $address['prefix'] ); ?></code>
					<span class="swiftling-address__field">
						<input type="text" id="<?php echo esc_attr( $id ); ?>" class="swiftling-mono" value="<?php echo esc_attr( rawurldecode( $address['slug'] ) ); ?>" placeholder="<?php echo esc_attr( rawurldecode( $address['original'] ) ); ?>" maxlength="<?php echo esc_attr( (string) SWIFTLING_SLUG_LENGTH ); ?>" autocomplete="off" spellcheck="false">
						<button type="submit" class="swiftling-button is-secondary"><?php esc_html_e( 'Save', 'swiftling' ); ?></button>
					</span>
					<p class="swiftling-muted swiftling-term-address__note"><?php esc_html_e( 'Empty: the original slug. A changed address keeps leading here from the old one.', 'swiftling' ); ?></p>
					<?php swiftling_print_base_note( 'tax', $term->taxonomy, $lang ); ?>
				</form>
			<?php endif; ?>
		</td>
		<td class="is-numeric"></td>
	</tr>
	<?php
}

/**
 * Prints, under the texts of a term in the editor of its list, the fields of the term its page does
 * not show, folded: each with Translate and Keep original. Translate makes the field a text of every
 * term of the list that has it; Keep original takes it away again. A term without such fields gets
 * nothing. See includes/terms.php.
 *
 * @param array  $subject Subject "terms:<taxonomy>".
 * @param string $lang    Language slug the editor shows.
 * @param string $owner   Object key of the term.
 * @param array  $fields  Fields of the list's terms, see swiftling_term_fields().
 */
function swiftling_live_term_more( $subject, $lang, $owner, $fields ) {
	$taxonomy = get_taxonomy( substr( $subject['key'], 6 ) );
	$more     = array();
	foreach ( $fields as $field ) {
		if ( true === $field['shown'] ) {
			continue;
		}
		foreach ( $field['texts'] as $text ) {
			if ( $owner === $text['o'] ) {
				$more[] = $field + array( 'value' => $text['v'] );
				break;
			}
		}
	}
	if ( ! $more || ! $taxonomy ) {
		return;
	}
	// One nonce for every form here; wp_nonce_field() would give each the same element ID.
	$nonce = wp_create_nonce( 'swiftling_term_field_' . $taxonomy->name );
	?>
	<tr class="swiftling-term-more">
		<td colspan="4">
			<details>
				<summary>
					<?php
					/* translators: %s: number of fields. */
					echo esc_html( sprintf( _n( '%s more field, not shown on the page', '%s more fields, not shown on the page', count( $more ), 'swiftling' ), number_format_i18n( count( $more ) ) ) );
					?>
				</summary>
				<p class="swiftling-muted">
					<?php
					/* translators: %s: name of a list, e.g. Categories. */
					echo esc_html( sprintf( __( 'Translate makes the field translatable in all %s that have it. Until then it shows the original in every language.', 'swiftling' ), $taxonomy->labels->name ) );
					?>
				</p>
				<ul>
					<?php foreach ( $more as $field ) : ?>
						<li>
							<span class="swiftling-term-more__about">
								<strong><?php echo esc_html( $field['label'] ); ?></strong>
								<?php if ( '' !== $field['source'] ) : ?>
									<span class="swiftling-muted"><?php echo esc_html( $field['source'] ); ?></span>
								<?php endif; ?>
								<span class="swiftling-muted"><?php echo esc_html( '“' . wp_html_excerpt( wp_strip_all_tags( $field['value'] ), 80, '…' ) . '”' ); ?></span>
							</span>
							<span class="swiftling-term-more__choice" role="group" aria-label="<?php echo esc_attr( $field['label'] ); ?>">
								<?php
								foreach ( array(
									'translate' => __( 'Translate', 'swiftling' ),
									'keep'      => __( 'Keep original', 'swiftling' ),
								) as $choice => $label ) :
									$pressed = ( 'translate' === $choice ) === $field['translated'];
									?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="swiftling_term_field">
										<input type="hidden" name="subject" value="<?php echo esc_attr( $subject['key'] ); ?>">
										<input type="hidden" name="key" value="<?php echo esc_attr( $field['key'] ); ?>">
										<input type="hidden" name="choice" value="<?php echo esc_attr( $choice ); ?>">
										<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
										<button type="submit" class="<?php echo $pressed ? 'is-current' : ''; ?>" aria-pressed="<?php echo $pressed ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
									</form>
								<?php endforeach; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		</td>
	</tr>
	<?php
}

/**
 * Takes the texts and the rows without a field of items left out in a language out of a menu's
 * editor. The rows without a field stay where they were among the texts that remain.
 *
 * @param array               $units    Texts of the menu, see swiftling_menu_texts().
 * @param array<int, array[]> $info     Rows without a field, by the number of texts before them.
 * @param array<int, string>  $left_out Item ID => name, see swiftling_menu_left_out_names().
 * @return array{0: array, 1: array<int, array[]>} The texts and the rows without a field that remain.
 */
function swiftling_live_without_left_out( $units, $info, $left_out ) {
	$kept  = array();
	$moved = array();
	$count = count( $units );
	for ( $index = 0; $index <= $count; $index++ ) {
		foreach ( isset( $info[ $index ] ) ? $info[ $index ] : array() as $row ) {
			if ( empty( $row['item'] ) || ! isset( $left_out[ $row['item'] ] ) ) {
				$moved[ count( $kept ) ][] = $row;
			}
		}
		if ( $index < $count && ! ( isset( $units[ $index ]['item'] ) && isset( $left_out[ $units[ $index ]['item'] ] ) ) ) {
			$kept[] = $units[ $index ];
		}
	}
	return array( $kept, $moved );
}

/**
 * Prints the closed list of the items of a menu left out in the language the editor shows, with a
 * link to the menu's table of items by language.
 *
 * @param array              $subject  Subject "menu:<id>".
 * @param array              $language Language the editor shows.
 * @param array<int, string> $left_out Item ID => name, see swiftling_menu_left_out_names().
 */
function swiftling_live_left_out_row( $subject, $language, $left_out ) {
	$names = array_filter( array_map( 'trim', $left_out ) );
	?>
	<tr class="swiftling-live-left-out">
		<td colspan="4">
			<details>
				<summary>
					<?php
					/* translators: 1: language name, e.g. Deutsch, 2: number of menu items. */
					echo esc_html( sprintf( __( 'Left out in %1$s (%2$s)', 'swiftling' ), $language['name'], number_format_i18n( count( $left_out ) ) ) );
					?>
					<span class="swiftling-muted"><?php echo esc_html( implode( ', ', $names ) ); ?></span>
				</summary>
				<p class="swiftling-muted">
					<?php
					/* translators: %s: language name, e.g. Deutsch. */
					echo esc_html( sprintf( __( 'Visitors in %s do not see these items, so there is nothing to translate. Show one again and it comes back here with its field.', 'swiftling' ), $language['name'] ) );
					?>
					<a href="<?php echo esc_url( swiftling_menu_items_url( (int) substr( $subject['key'], 5 ) ) ); ?>"><?php esc_html_e( 'Choose items', 'swiftling' ); ?></a>
				</p>
			</details>
		</td>
	</tr>
	<?php
}

/**
 * Prints a row of a menu without a field: something the menu shows that is translated elsewhere or
 * not at all, such as SwiftLing's language switcher. See swiftling_menu_texts().
 *
 * @param array $row title, text, note, link (array( label, url ) or empty) and depth.
 */
function swiftling_live_info_row( $row ) {
	$classes = array( 'swiftling-live-info' );
	if ( ! empty( $row['depth'] ) ) {
		$classes[] = 'is-depth-' . min( 3, (int) $row['depth'] );
	}
	?>
	<tr class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<th scope="row"><?php echo esc_html( $row['title'] ); ?></th>
		<td class="swiftling-live-original"><?php echo esc_html( $row['text'] ); ?></td>
		<td class="swiftling-editor__translation">
			<p class="swiftling-muted">
				<?php echo esc_html( $row['note'] ); ?>
				<?php if ( ! empty( $row['link'] ) ) : ?>
					<a href="<?php echo esc_url( $row['link'][1] ); ?>"><?php echo esc_html( $row['link'][0] ); ?></a>
				<?php endif; ?>
			</p>
		</td>
		<td class="is-numeric"></td>
	</tr>
	<?php
}

/**
 * Prints, in the section of what every page shows in the editor of a template, where its classic
 * menus are translated: each menu with its state in the language and a link to its editor.
 *
 * @param WP_Term[] $menus Menus, see swiftling_template_menus().
 * @param string    $lang  Language slug the editor shows.
 */
function swiftling_live_menu_pointer( $menus, $lang ) {
	$states = swiftling_menu_states( $menus );
	?>
	<tr class="swiftling-menu-pointer">
		<td colspan="4">
			<div class="swiftling-menu-pointer__box">
				<p>
					<strong><?php esc_html_e( 'The items of menus are translated on the Menus screen', 'swiftling' ); ?></strong>
					<span class="swiftling-muted"><?php esc_html_e( 'Once for the whole site, wherever a menu shows.', 'swiftling' ); ?></span>
				</p>
				<ul>
					<?php foreach ( $menus as $menu ) : ?>
						<?php $key = 'menu:' . $menu->term_id; ?>
						<li>
							<?php \SwiftLing\Admin\Ui::statusIcon( isset( $states[ $key ][ $lang ] ) ? $states[ $key ][ $lang ] : 'missing' ); ?>
							<span class="swiftling-menu-pointer__name"><?php echo esc_html( $menu->name ); ?></span>
							<?php \SwiftLing\Admin\Ui::button( __( 'Translate the menu', 'swiftling' ), 'secondary', swiftling_editor_url( $key, $lang ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</td>
	</tr>
	<?php
}

/**
 * Groups an image with its alt text when the alt text comes right after it and sits on the same
 * image of the page, so the editor shows them as one thing.
 *
 * @param array $rows Texts in the order the editor shows them.
 * @return array The same texts; grouped ones carry group "start" or "end".
 */
function swiftling_live_image_groups( $rows ) {
	foreach ( $rows as $index => $unit ) {
		$next = isset( $rows[ $index + 1 ] ) ? $rows[ $index + 1 ] : null;
		if ( 'image' === $unit['kind'] && $next && 'alt' === $next['kind'] && isset( $unit['el'], $next['el'] ) && $unit['el'] >= 0 && $unit['el'] === $next['el'] ) {
			$rows[ $index ]['group']     = 'start';
			$rows[ $index + 1 ]['group'] = 'end';
		}
	}
	return $rows;
}

/**
 * Whether a value belongs to an image or a file of the media library, such as its address or its
 * alt text.
 *
 * @param string $owner Object key.
 * @return bool
 */
function swiftling_is_media_owner( $owner ) {
	return 0 === strpos( $owner, 'post:' ) && 'attachment' === get_post_type( (int) substr( $owner, 5 ) );
}

/**
 * Whether a text is code: HTML that cannot be edited as formatted text, such as an embed or a
 * shortcode, what a code block holds, or a value from a field the builder declares as code.
 *
 * @param array $unit Text found by the probe.
 * @return bool
 */
function swiftling_live_is_code( $unit ) {
	if ( swiftling_is_address_kind( $unit['kind'] ) ) {
		return false;
	}
	if ( 1 === preg_match( '~<[a-z/!][^>]*>~i', $unit['v'] ) && null === swiftling_rich_parts( $unit['v'] ) ) {
		return true;
	}
	// What a code block holds is code, whatever its tags: edited by hand, never machine translated.
	if ( isset( $unit['r'] ) && 0 === strpos( (string) $unit['r'], 'block:core/code#html|' ) ) {
		return true;
	}
	return \SwiftLing\Builders\Registry::isCode( $unit['o'], $unit['p'], isset( $unit['r'] ) ? $unit['r'] : '' );
}

/**
 * Returns what a row of code is called, after what it shows: a table, a quote, a verse, an image or
 * a list read better than "Code". Anything else is code.
 *
 * @param string $code Code.
 * @return string
 */
function swiftling_live_code_label( $code ) {
	$kinds = array(
		'~<table\b~i'                                  => __( 'Table', 'swiftling' ),
		'~\bwp-block-(?:pull)?quote\b|<blockquote\b~i' => __( 'Quote', 'swiftling' ),
		'~\bwp-block-verse\b~i'                        => __( 'Verse', 'swiftling' ),
		'~<figure\b[^>]*>.*<figcaption\b~is'           => __( 'Image with caption', 'swiftling' ),
		'~^\s*<(?:ul|ol)\b~i'                          => __( 'List', 'swiftling' ),
	);
	foreach ( $kinds as $pattern => $label ) {
		if ( 1 === preg_match( $pattern, $code ) ) {
			return $label;
		}
	}
	return \SwiftLing\Admin\Ui::partLabels()['code'];
}

/**
 * Whether code shows words to visitors: text between its tags, such as the cells of a table, a
 * quote or the caption of an image. The insides of scripts, styles and comments are no such
 * text, and neither are shortcodes or code without any tag, such as CSS a builder keeps in a field.
 *
 * @param string $code Code.
 * @return bool
 */
function swiftling_has_visitor_text( $code ) {
	if ( 1 !== preg_match( '~<[a-z/!][^>]*>~i', $code ) ) {
		return false;
	}
	$text = (string) preg_replace( '~<(script|style|noscript|template|svg)\b.*?</\1\s*>|<!--.*?-->~is', ' ', $code );
	$text = (string) preg_replace( '~\[/?[a-z][\w-]*(?:\s[^\]]*)?\]~i', ' ', wp_strip_all_tags( $text ) );
	return 1 === preg_match( '~[\p{L}\p{N}]~u', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Whether a row is shown from the start: every text, and code with words for visitors. Its
 * translation counts toward the state of the page, so the editor never hides it. Images, videos,
 * link addresses and code without such words show only on request: addresses count for nothing,
 * as most stay the same in every language, and "Show only empty fields" shows the code too.
 *
 * @param array $unit Text found by the probe.
 * @return bool
 */
function swiftling_live_is_text_row( $unit ) {
	if ( swiftling_is_address_kind( $unit['kind'] ) ) {
		return false;
	}
	return ! swiftling_live_is_code( $unit ) || swiftling_has_visitor_text( $unit['v'] );
}

/**
 * Counts what the tab of images, links and code shows: images, videos, links and all code, each
 * place once, also the addresses of links around texts. Code with words for visitors is among the
 * texts too.
 *
 * @param array $result Result of swiftling_save_probe().
 * @return int
 */
function swiftling_live_extras_count( $result ) {
	$count = 0;
	foreach ( $result['sections'] as $units ) {
		foreach ( $units as $unit ) {
			$count += ( ! swiftling_live_is_text_row( $unit ) || swiftling_live_is_code( $unit ) ? 1 : 0 ) + ( isset( $unit['link'] ) ? 1 : 0 );
		}
	}
	return $count;
}

/**
 * Renders one text: what it is, with the field it is stored in where a builder names it, the
 * original, the translation and its state.
 *
 * The translation field depends on the original. Plain text gets a plain field, and so does HTML
 * with nothing to format inside its wrapping tags, such as a heading. HTML with simple formatting,
 * such as a paragraph with bold text and links, gets the WordPress visual editor for the text
 * inside its wrapping tags. The wrapping tags stay as they are. Other HTML, such as an image with its
 * caption or a shortcode, gets a code field, and the original shows as code, as it is stored. A
 * link, image or video address gets an address field, an image also a button to choose another one
 * from the media library, and a text inside a link shows the address of that link under it.
 *
 * Every place is a row with a translation of its own, also where the page shows the same words
 * again. A text from a shared object, such as a widget's default text, is one place however often
 * the page shows it.
 *
 * A text may carry a depth, for an item of a submenu, and a note shown under its field: whose text
 * it is and links to where else it is translated, see swiftling_menu_row_note(). A text that falls
 * back to another while it is empty carries that one's translation and state as fallback, and what
 * its empty field says as placeholder: the row then counts as the fallback does, and filling empty
 * fields leaves it alone, see assets/js/editor.js.
 *
 * @param array $unit         Text or link found by the probe.
 * @param array $map          Map of the object the text belongs to.
 * @param array $translations Translations into the language shown.
 * @param bool  $can_edit     Whether the user may translate the object.
 */
function swiftling_live_row( $unit, $map, $translations, $can_edit ) {
	$labels  = \SwiftLing\Admin\Ui::partLabels();
	$regions = \SwiftLing\Admin\Ui::regionLabels();
	$kind    = isset( $labels[ $unit['kind'] ] ) ? $unit['kind'] : 'text';
	$field   = swiftling_live_translation( $unit, $map, $translations );
	$is_link = swiftling_is_address_kind( $kind );
	$is_html = ! $is_link && 1 === preg_match( '~<[a-z/!][^>]*>~i', $unit['v'] );
	$is_code = swiftling_live_is_code( $unit );
	// Code is edited as code, also when it would fit the visual editor.
	$parts = $is_html && ! $is_code ? swiftling_rich_parts( $unit['v'] ) : null;
	$state = $is_link && 'missing' === $field['state'] ? 'same' : $field['state'];
	// A text that falls back to another, such as a menu's label of a page's title: empty here, it
	// shows the other, in that one's state.
	$fallback = isset( $unit['fallback'] ) ? $unit['fallback'] : null;
	if ( $fallback && 'missing' === $field['state'] && '' !== $fallback['text'] ) {
		$state = $fallback['state'];
	}
	$classes = array( 'is-' . $state, 'is-type-' . $kind );
	if ( ! swiftling_live_is_text_row( $unit ) ) {
		$classes[] = 'is-extra';
	}
	// Code is in the tab of images, links and code, also code that shows among the texts.
	if ( $is_code ) {
		$classes[] = 'is-code';
	}
	if ( ! empty( $unit['group'] ) ) {
		$classes[] = 'is-image-group';
		$classes[] = 'is-group-' . $unit['group'];
	}
	// An item of a submenu, indented under its parent, see includes/menus.php.
	if ( ! empty( $unit['depth'] ) ) {
		$classes[] = 'is-depth-' . min( 3, (int) $unit['depth'] );
	}
	$is_media = swiftling_is_media_owner( $unit['o'] );
	// What the row is called: its kind, or the label of the field it is, such as a field of a term.
	$title = isset( $unit['label'] ) && '' !== $unit['label'] ? (string) $unit['label'] : $labels[ $kind ];
	// The field the text is stored in, where a builder names it, such as "Team members 2 › Name".
	$place = isset( $unit['place'] ) ? (string) $unit['place'] : \SwiftLing\Builders\Registry::fieldLabel( $unit['o'], $unit['p'] );
	if ( '' === $place && 'field/post_excerpt' === $unit['p'] && 0 === strpos( $unit['o'], 'post:' ) ) {
		$place = __( 'Excerpt', 'swiftling' );
	}
	?>
	<tr class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-key="<?php echo esc_attr( $field['key'] ); ?>"<?php echo $fallback ? ' data-follows="1"' . ( '' !== $fallback['text'] ? ' data-fallback="' . esc_attr( $fallback['state'] ) . '"' : '' ) : ''; ?>>
		<th scope="row">
			<?php echo esc_html( $is_code ? swiftling_live_code_label( $unit['v'] ) : $title ); ?>
			<?php if ( isset( $regions[ $unit['region'] ] ) ) : ?>
				<span class="swiftling-muted"><?php echo esc_html( $regions[ $unit['region'] ] ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $place ) : ?>
				<span class="swiftling-muted swiftling-live-place"><?php echo esc_html( $place ); ?></span>
			<?php endif; ?>
		</th>
		<td class="swiftling-live-original">
			<?php if ( 'image' === $kind ) : ?>
				<img class="swiftling-live-thumb" src="<?php echo esc_url( $unit['v'] ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<?php if ( $is_link ) : ?>
				<code><?php echo esc_html( $unit['v'] ); ?></code>
			<?php elseif ( $is_code ) : ?>
				<?php // Code shows as it is stored, so it can be copied into the translation. ?>
				<pre class="swiftling-code swiftling-live-code"><?php echo esc_html( $unit['v'] ); ?></pre>
			<?php elseif ( $is_html ) : ?>
				<div class="swiftling-live-html"><?php echo wp_kses_post( $unit['v'] ); ?></div>
			<?php else : ?>
				<?php echo nl2br( esc_html( $unit['v'] ) ); ?>
			<?php endif; ?>
			<?php if ( isset( $unit['link'] ) ) : ?>
				<span class="swiftling-live-address"><?php esc_html_e( 'Link', 'swiftling' ); ?> <code><?php echo esc_html( $unit['link']['v'] ); ?></code></span>
			<?php endif; ?>
		</td>
		<td class="swiftling-editor__translation is-<?php echo esc_attr( $kind ); ?>">
			<?php if ( 'outdated' === $field['state'] ) : ?>
				<del><?php echo esc_html( wp_strip_all_tags( $field['previous'] ) ); ?></del>
				<p class="swiftling-code-note"><?php esc_html_e( 'The original changed since it was translated.', 'swiftling' ); ?></p>
			<?php endif; ?>
			<?php
			if ( $is_link ) {
				swiftling_live_link_field( $unit, $field, $can_edit, swiftling_live_address_label( $kind ), 'image' === $kind, $is_media );
			} else {
				swiftling_live_text_field( $unit, $field, $parts, $is_html, $can_edit, $is_code );
			}
			if ( isset( $unit['link'] ) ) {
				swiftling_live_link_field( $unit['link'], swiftling_live_translation( $unit['link'], $map, $translations ), $can_edit, __( 'Link', 'swiftling' ) );
			}
			?>
			<?php if ( ! $can_edit ) : ?>
				<p class="swiftling-code-note"><?php esc_html_e( 'You may not edit this part of the site, so you cannot translate it either.', 'swiftling' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $unit['note'] ) ) : ?>
				<p class="swiftling-code-note swiftling-live-whose">
					<?php echo esc_html( $unit['note']['text'] ); ?>
					<?php foreach ( $unit['note']['links'] as $note_link ) : ?>
						<a href="<?php echo esc_url( $note_link[1] ); ?>"><?php echo esc_html( $note_link[0] ); ?></a>
					<?php endforeach; ?>
					<?php if ( ! empty( $unit['note']['more'] ) ) : ?>
						<?php echo esc_html( $unit['note']['more'] ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</td>
		<td class="is-numeric">
			<?php
			if ( ! $is_link || 'same' !== $state ) {
				\SwiftLing\Admin\Ui::statusIcon( $state );
			}
			?>
		</td>
	</tr>
	<?php
}

/**
 * Returns the translation of a text or link and its state.
 *
 * @param array $unit         Text or link found by the probe.
 * @param array $map          Map of the object it belongs to.
 * @param array $translations Translations into the language shown, keyed by place.
 * @return array{hash: string, text: string, previous: string, state: string} State is reviewed, machine, outdated or missing.
 */
function swiftling_live_translation( $unit, $map, $translations ) {
	list( $key, $prev ) = swiftling_live_places( $unit, $map );

	if ( isset( $translations[ $key ] ) ) {
		return array(
			'key'      => $key,
			'text'     => $translations[ $key ]['text'],
			'previous' => '',
			'state'    => swiftling_status_state( $translations[ $key ]['status'] ),
		);
	}

	return array(
		'key'      => $key,
		'text'     => '',
		'previous' => '' !== $prev && isset( $translations[ $prev ] ) ? $translations[ $prev ]['text'] : '',
		'state'    => '' !== $prev && isset( $translations[ $prev ] ) ? 'outdated' : 'missing',
	);
}

/**
 * Returns where the translation of a text or link is stored: the key of its place for the current
 * version and, while a changed text waits for its new translation, for the previous one.
 *
 * @param array $unit Text or link found by the probe.
 * @param array $map  Map of the object it belongs to.
 * @return array{0: string, 1: string} Key now, and of the previous version or "".
 */
function swiftling_live_places( $unit, $map ) {
	$unit_key = swiftling_unit_key( $unit );
	$entry    = isset( $map[ $unit_key ] ) ? $map[ $unit_key ] : swiftling_hash( $unit['v'] );
	$hash     = is_array( $entry ) ? $entry[0] : $entry;
	$previous = is_array( $entry ) ? $entry[1] : '';
	return array(
		swiftling_place_key( $unit['o'], $unit_key, $hash ),
		'' === $previous ? '' : swiftling_place_key( $unit['o'], $unit_key, $previous ),
	);
}

/**
 * Returns what an empty translation field says: what to write there, and what visitors read until
 * then, naming both languages, as "Write the Deutsch translation here. Until you do, visitors read
 * the original (Hrvatski)."
 *
 * @param array|null $language The language the editor shows; given once, before the rows render.
 * @return string
 */
function swiftling_live_placeholder( $language = null ) {
	static $text = '';
	if ( null !== $language ) {
		$text = sprintf(
			/* translators: 1: language of the translation, e.g. Deutsch, 2: language of the original, e.g. Hrvatski. */
			__( 'Write the %1$s translation here. Until you do, visitors read the original (%2$s).', 'swiftling' ),
			$language['name'],
			swiftling_languages()['source']['name']
		);
	}
	return '' !== $text ? $text : __( 'Write the translation here. Until you do, visitors read the original.', 'swiftling' );
}

/**
 * Returns how many rows the field of a translation gets: room for its text, at about 70
 * characters a row, and, while it is empty, for its placeholder too, which says what to do. The
 * placeholder gets 60 characters a row, as its words wrap earlier in a narrow column; a title of
 * a few words gave one row, and the placeholder showed half of its first line.
 *
 * @param string $text        Text the field holds, or its original.
 * @param string $placeholder Placeholder the empty field shows; empty when it has a translation.
 * @return int From 1 to 12.
 */
function swiftling_live_field_rows( $text, $placeholder = '' ) {
	$rows = (int) ceil( strlen( $text ) / 70 ) + substr_count( $text, "\n" );
	if ( '' !== $placeholder ) {
		$rows = max( $rows, (int) ceil( mb_strlen( $placeholder ) / 60 ) );
	}
	return max( 1, min( 12, $rows ) );
}

/**
 * Renders the field for the translation of a text.
 *
 * @param array      $unit     Text found by the probe.
 * @param array      $field    Its translation, from swiftling_live_translation().
 * @param array|null $parts    Wrapping tags and editable text, from swiftling_rich_parts(), or null.
 * @param bool       $is_html  Whether the original is HTML.
 * @param bool       $can_edit Whether the user may translate it.
 * @param bool       $is_code  Whether it is code, which gets a code field.
 */
function swiftling_live_text_field( $unit, $field, $parts, $is_html, $can_edit, $is_code = false ) {
	$input_id    = 'swiftling-t-' . $field['key'];
	$placeholder = isset( $unit['placeholder'] ) ? $unit['placeholder'] : swiftling_live_placeholder();
	$rows        = swiftling_live_field_rows( $unit['v'], '' === $field['text'] ? $placeholder : '' );
	// A field whose rows its placeholder decides fits it exactly where the browser can, see admin.css.
	$fit = $rows > swiftling_live_field_rows( $unit['v'] ) ? ' data-fit' : '';
	// A text from code may go through sprintf(): its placeholders must stay, see swiftling_format_problems().
	$placeholders = 0 === strpos( $unit['o'], 'gettext:' ) ? (array) swiftling_format_placeholders( $unit['v'] ) : array();
	$format       = $placeholders ? implode( ' ', array_unique( wp_list_pluck( $placeholders, 'raw' ) ) ) : '';
	$format_attr  = '' !== $format ? ' data-format="' . esc_attr( $format ) . '" aria-describedby="' . esc_attr( $input_id . '-format' ) . '"' : '';
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php esc_html_e( 'Translation', 'swiftling' ); ?></label>
	<?php
	if ( $parts ) {
		list( $prefix, $middle, $suffix, $blocks ) = $parts;
		// Show only the text inside the wrapping tags when the translation kept them.
		$text  = '' === $field['text'] ? '' : swiftling_rich_inner( $field['text'], $prefix, $suffix );
		$plain = swiftling_plain_text( $middle );
		// A translation formatted in the visual editor keeps it, also where the original has no formatting.
		$plain_text = '' === $text ? '' : swiftling_plain_text( $text );
	}
	?>
	<?php if ( $parts && null !== $plain && null !== $plain_text ) : ?>
		<?php // Text with nothing to format inside its wrapping tags, such as a heading, gets a plain field; the tags are put back when it is saved. ?>
		<?php
		$rows = swiftling_live_field_rows( $plain, '' === $plain_text ? $placeholder : '' );
		$fit  = $rows > swiftling_live_field_rows( $plain ) ? ' data-fit' : '';
		?>
		<textarea id="<?php echo esc_attr( $input_id ); ?>" class="swiftling-live-input swiftling-live-wrapped" rows="<?php echo esc_attr( (string) $rows ); ?>" data-key="<?php echo esc_attr( $field['key'] ); ?>" data-original="<?php echo esc_attr( $plain ); ?>" data-prefix="<?php echo esc_attr( $prefix ); ?>" data-suffix="<?php echo esc_attr( $suffix ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php echo $fit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed attribute. ?><?php echo $format_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?><?php disabled( ! $can_edit ); ?>><?php echo esc_textarea( $plain_text ); ?></textarea>
	<?php elseif ( $parts ) : ?>
		<textarea id="<?php echo esc_attr( $input_id ); ?>" class="swiftling-live-input swiftling-live-rich" rows="<?php echo esc_attr( (string) $rows ); ?>" data-key="<?php echo esc_attr( $field['key'] ); ?>" data-original="<?php echo esc_attr( $middle ); ?>" data-prefix="<?php echo esc_attr( $prefix ); ?>" data-suffix="<?php echo esc_attr( $suffix ); ?>" data-blocks="<?php echo $blocks ? '1' : '0'; ?>"<?php echo $format_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?><?php disabled( ! $can_edit ); ?>><?php echo esc_textarea( $text ); ?></textarea>
	<?php else : ?>
		<textarea id="<?php echo esc_attr( $input_id ); ?>" class="swiftling-live-input<?php echo $is_html || $is_code ? ' swiftling-code' : ''; ?>" rows="<?php echo esc_attr( (string) $rows ); ?>" data-key="<?php echo esc_attr( $field['key'] ); ?>" data-original="<?php echo esc_attr( $unit['v'] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php echo $fit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed attribute. ?><?php echo $format_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?><?php disabled( ! $can_edit ); ?>><?php echo esc_textarea( $field['text'] ); ?></textarea>
		<?php if ( $is_html ) : ?>
			<p class="swiftling-code-note"><?php esc_html_e( 'Change only the text. Keep tags, attributes and shortcode names as they are.', 'swiftling' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( '' !== $format ) : ?>
		<p class="swiftling-code-note swiftling-format-note" id="<?php echo esc_attr( $input_id . '-format' ); ?>" aria-live="polite">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: placeholders, e.g. %1$s %2$d. */
					_n( 'Keep %s as it is: the site puts a value there. It may move to another place in the sentence.', 'Keep %s as they are: the site puts values there. They may move to another place in the sentence.', count( explode( ' ', $format ) ), 'swiftling' ),
					$format
				)
			);
			?>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Returns the label of the field for an address.
 *
 * @param string $kind url, image or video.
 * @return string
 */
function swiftling_live_address_label( $kind ) {
	$labels = array(
		'url'   => __( 'Address', 'swiftling' ),
		'image' => __( 'Image address', 'swiftling' ),
		'video' => __( 'Video address', 'swiftling' ),
	);
	return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $labels['url'];
}

/**
 * Renders the field for an address in the language shown: of a link, an image or a video. Empty
 * means the same address as in the original.
 *
 * @param array  $link     Address found by the probe.
 * @param array  $field    Its translation, from swiftling_live_translation().
 * @param bool   $can_edit Whether the user may translate it.
 * @param string $label    Label of the field.
 * @param bool   $pick     Whether to offer a button that picks an image from the media library.
 * @param bool   $media    Whether the address is the file of an image in the media library, which
 *                         changes wherever the image is used.
 */
function swiftling_live_link_field( $link, $field, $can_edit, $label, $pick = false, $media = false ) {
	$input_id = 'swiftling-t-' . $field['key'];
	?>
	<span class="swiftling-live-link">
		<?php if ( $pick ) : ?>
			<?php // The image this language shows, as the original column shows its own; the original's, faded, while the field is empty. ?>
			<img class="swiftling-live-thumb swiftling-live-chosen<?php echo '' === $field['text'] ? ' is-same' : ''; ?>" src="<?php echo esc_url( '' !== $field['text'] ? $field['text'] : $link['v'] ); ?>" alt="" loading="lazy" data-for="<?php echo esc_attr( $input_id ); ?>">
		<?php endif; ?>
		<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
		<span class="swiftling-live-link__field">
			<input type="url" id="<?php echo esc_attr( $input_id ); ?>" class="swiftling-live-input is-link swiftling-mono" data-key="<?php echo esc_attr( $field['key'] ); ?>" data-original="<?php echo esc_attr( $link['v'] ); ?>" value="<?php echo esc_attr( $field['text'] ); ?>" placeholder="<?php echo esc_attr( $link['v'] ); ?>"<?php disabled( ! $can_edit ); ?>>
			<?php if ( $pick && $can_edit ) : ?>
				<button type="button" class="swiftling-button is-secondary swiftling-live-pick" data-for="<?php echo esc_attr( $input_id ); ?>"><?php esc_html_e( 'Choose image', 'swiftling' ); ?></button>
			<?php endif; ?>
		</span>
		<span class="swiftling-muted">
			<?php
			echo esc_html(
				$media
					? __( 'Empty: the same as in the original. The image and its alt text change wherever this image is used.', 'swiftling' )
					: __( 'Empty: the same as in the original.', 'swiftling' )
			);
			?>
		</span>
	</span>
	<?php
}

/**
 * Returns the part of a translation inside the tags that wrap its original, for the visual editor.
 *
 * The whitespace around the tags does not count: a block saves its HTML with line breaks around
 * it, and translations saved before 2026-10-03 lost them, so their wrapping tags no longer matched
 * and the whole value went into the editor, where saving wrapped it a second time. Tags wrapped
 * twice in this way come off as well, so the next save repairs such a translation.
 *
 * @param string $text   Translation.
 * @param string $prefix Tags before the editable part of the original, from swiftling_rich_parts().
 * @param string $suffix Tags after it.
 * @return string The part inside the tags, or the translation as it is when it does not have them.
 */
function swiftling_rich_inner( $text, $prefix, $suffix ) {
	$open  = trim( $prefix );
	$close = trim( $suffix );
	if ( '' === $open && '' === $close ) {
		return $text;
	}
	$open_length  = strlen( $open );
	$close_length = strlen( $close );
	$inner        = trim( $text );
	$found        = false;
	for ( $length = strlen( $inner ); $length >= $open_length + $close_length; $length = strlen( $inner ) ) {
		$opens  = '' === $open || 0 === strpos( $inner, $open );
		$closes = '' === $close || substr( $inner, -$close_length ) === $close;
		if ( ! $opens || ! $closes ) {
			break;
		}
		$inner = trim( substr( $inner, $open_length, $length - $open_length - $close_length ) );
		$found = true;
	}
	return $found ? $inner : $text;
}

/**
 * Returns HTML without any tags as the text a person reads and types, entities decoded.
 *
 * @param string $html Part of an HTML value, such as the text inside a heading.
 * @return string|null The text, or null when the HTML holds a tag or a comment.
 */
function swiftling_plain_text( $html ) {
	if ( false !== strpos( $html, '<' ) ) {
		return null;
	}
	return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Splits an HTML value for the visual editor. The tags that wrap the whole text, such as a
 * paragraph or a list item, and tags a fragment opens or closes for its surroundings stay as they
 * are; only the text with simple formatting in between is edited, so a translation cannot change
 * the structure a builder relies on.
 *
 * @param string $html Original value.
 * @return array{0: string, 1: string, 2: string, 3: bool}|null Prefix, editable middle, suffix, and whether
 *         the middle holds paragraphs or lists; null when the value holds more than simple formatting.
 */
function swiftling_rich_parts( $html ) {
	$inline = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'del', 'dfn', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );
	$blocks = array( 'p', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote' );
	$wraps  = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'figcaption', 'blockquote', 'cite', 'summary', 'td', 'th', 'dt', 'dd', 'div', 'label' );
	$void   = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	// Comments and shortcodes are edited as code.
	if ( false !== strpos( $html, '<!--' ) || preg_match( '/\[\/?[a-z][a-z0-9_-]*[\s\]\/]/i', $html ) ) {
		return null;
	}

	preg_match_all( '~<(/?)([a-zA-Z][a-zA-Z0-9-]*)(?:\s[^<>]*)?>|[^<]+~', $html, $found, PREG_SET_ORDER );
	$tokens = array();
	$copy   = '';
	foreach ( $found as $match ) {
		$copy .= $match[0];
		if ( '<' !== $match[0][0] ) {
			$tokens[] = array(
				'type' => 'text',
				'raw'  => $match[0],
			);
			continue;
		}
		$name = strtolower( $match[2] );
		if ( '/' === $match[1] ) {
			$type = 'close';
		} elseif ( in_array( $name, $void, true ) || '/>' === substr( $match[0], -2 ) ) {
			$type = 'void';
		} else {
			$type = 'open';
		}
		$tokens[] = array(
			'type' => $type,
			'name' => $name,
			'raw'  => $match[0],
		);
	}
	// A "<" that is not a tag, as in "a < b": leave it to the code field.
	if ( $copy !== $html ) {
		return null;
	}

	// Pair opening and closing tags.
	$pair  = array();
	$stack = array();
	foreach ( $tokens as $index => $token ) {
		if ( 'open' === $token['type'] ) {
			$stack[] = $index;
		} elseif ( 'close' === $token['type'] ) {
			for ( $depth = count( $stack ) - 1; $depth >= 0; $depth-- ) {
				if ( $tokens[ $stack[ $depth ] ]['name'] === $token['name'] ) {
					$pair[ $stack[ $depth ] ] = $index;
					$pair[ $index ]           = $stack[ $depth ];
					array_splice( $stack, $depth );
					break;
				}
			}
		}
	}

	$is_blank = function ( $token ) {
		return 'text' === $token['type'] && '' === trim( $token['raw'] );
	};

	// Tags the fragment opens for content outside it, or closes for content before it.
	$start = 0;
	$end   = count( $tokens ) - 1;
	while ( $start <= $end && ( $is_blank( $tokens[ $start ] ) || ( 'open' === $tokens[ $start ]['type'] && ! isset( $pair[ $start ] ) ) ) ) {
		++$start;
	}
	while ( $end >= $start && ( $is_blank( $tokens[ $end ] ) || ( 'close' === $tokens[ $end ]['type'] && ! isset( $pair[ $end ] ) ) ) ) {
		--$end;
	}

	// One element around all of the text.
	while ( $start < $end && 'open' === $tokens[ $start ]['type'] && isset( $pair[ $start ] ) && $pair[ $start ] === $end && in_array( $tokens[ $start ]['name'], $wraps, true ) ) {
		++$start;
		--$end;
	}

	$has_blocks = false;
	for ( $index = $start; $index <= $end; $index++ ) {
		$token = $tokens[ $index ];
		if ( 'text' === $token['type'] ) {
			continue;
		}
		$allowed = in_array( $token['name'], $inline, true ) || in_array( $token['name'], $blocks, true );
		$inside  = 'void' === $token['type'] || ( isset( $pair[ $index ] ) && $pair[ $index ] >= $start && $pair[ $index ] <= $end );
		if ( ! $allowed || ! $inside ) {
			return null;
		}
		if ( in_array( $token['name'], $blocks, true ) ) {
			$has_blocks = true;
		}
	}

	$raw    = function ( $from, $to ) use ( $tokens ) {
		return implode( '', wp_list_pluck( array_slice( $tokens, $from, max( 0, $to - $from ) ), 'raw' ) );
	};
	$prefix = $raw( 0, $start );
	$middle = $raw( $start, $end + 1 );
	$suffix = $raw( $end + 1, count( $tokens ) );

	if ( '' === trim( wp_strip_all_tags( $middle ) ) ) {
		return null;
	}

	// Text in an attribute of a wrapping tag, such as a title or a data attribute a script shows,
	// would be locked outside the editable part, so such HTML is edited as code.
	if ( preg_match_all( '~\s(?:title|alt|aria-label|placeholder|data-[\w-]+)="([^"]*)"~i', $prefix, $attributes ) ) {
		foreach ( $attributes[1] as $value ) {
			if ( swiftling_is_text_like( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) ) ) {
				return null;
			}
		}
	}

	return array( $prefix, $middle, $suffix, $has_blocks );
}
