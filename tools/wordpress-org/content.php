<?php
/**
 * Content of the screenshot site, before SwiftLing is set up: a plant nursery, with the public
 * domain (CC0) photos Twenty Twenty-Five ships, so every image of the screenshots may be shown.
 *
 *   wp eval-file tools/wordpress-org/content.php --user=admin
 *
 * @package SwiftLing
 */

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$theme_images = get_theme_root() . '/twentytwentyfive/assets/images/';
$images       = array();
foreach ( array(
	'botany-flowers'             => 'Flowers in the greenhouse',
	'category-sunflowers'        => 'Sunflowers',
	'category-anthuriums'        => 'Anthuriums',
	'category-cactus'            => 'Cacti on a windowsill',
	'delphinium-flowers'         => 'Delphiniums in bloom',
	'flower-meadow-square'       => 'A meadow of wild flowers',
	'northern-buttercups-flowers' => 'Buttercups',
	'red-hibiscus-closeup'       => 'A red hibiscus flower',
) as $file => $alt ) {
	$tmp = wp_tempnam( $file );
	copy( $theme_images . $file . '.webp', $tmp );
	$id = media_handle_sideload( array( 'name' => $file . '.webp', 'tmp_name' => $tmp ), 0, $alt );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $file . ': ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	$images[ $file ] = array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'alt' => $alt );
}

$image = static function ( $name ) use ( $images ) {
	$i = $images[ $name ];
	return '<!-- wp:image {"id":' . $i['id'] . ',"sizeSlug":"large","linkDestination":"none","style":{"border":{"radius":"6px"}}} -->
<figure class="wp-block-image size-large has-custom-border"><img src="' . $i['url'] . '" alt="' . $i['alt'] . '" class="wp-image-' . $i['id'] . '" style="border-radius:6px"/></figure>
<!-- /wp:image -->';
};
$column = static function ( $name, $title, $text ) use ( $image ) {
	return '<!-- wp:column -->
<div class="wp-block-column">' . $image( $name ) . '
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">' . $title . '</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>' . $text . '</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->';
};
$heading = static function ( $text, $level = 2 ) {
	return '<!-- wp:heading' . ( 2 === $level ? '' : ' {"level":' . $level . '}' ) . ' -->
<h' . $level . ' class="wp-block-heading">' . $text . '</h' . $level . '>
<!-- /wp:heading -->';
};
$p = static function ( $text ) {
	return '<!-- wp:paragraph -->
<p>' . $text . '</p>
<!-- /wp:paragraph -->';
};
$list = static function ( $items ) {
	$out = '<!-- wp:list -->
<ul class="wp-block-list">';
	foreach ( $items as $item ) {
		$out .= '<!-- wp:list-item -->
<li>' . $item . '</li>
<!-- /wp:list-item -->';
	}
	return $out . '</ul>
<!-- /wp:list -->';
};
$page = static function ( $title, $content, $args = array() ) {
	return wp_insert_post(
		array_merge(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			),
			$args
		)
	);
};

$hero = $images['botany-flowers'];
$home = $page(
	'Home',
	'<!-- wp:cover {"url":"' . $hero['url'] . '","id":' . $hero['id'] . ',"dimRatio":40,"minHeight":520,"align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull" style="min-height:520px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-40 has-background-dim"></span><img class="wp-block-cover__image-background wp-image-' . $hero['id'] . '" alt="" src="' . $hero['url'] . '" data-object-fit="cover"/><div class="wp-block-cover__inner-container">
<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"3.4rem"}}} -->
<h1 class="wp-block-heading" style="font-size:3.4rem">Plants grown slowly, the way they like it</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.25rem"}}} -->
<p style="font-size:1.25rem">A family nursery on the edge of town since 1987. Perennials, houseplants and cacti, raised in our own greenhouses.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/visit-us/">Plan your visit</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/plant-care/">Plant care guides</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover -->
' . $heading( 'What grows here this season' ) . '
<!-- wp:columns -->
<div class="wp-block-columns">' .
	$column( 'category-sunflowers', 'Sunflowers', 'Twelve varieties, from dwarf ‘Teddy Bear’ to giants taller than our greenhouse doors.' ) .
	$column( 'category-anthuriums', 'Anthuriums', 'Glossy leaves and long-lasting flowers for bright rooms without direct sun.' ) .
	$column( 'category-cactus', 'Cacti and succulents', 'Hardy, patient and forgiving: ideal for a sunny windowsill and a busy life.' ) . '</div>
<!-- /wp:columns -->
<!-- wp:pullquote -->
<figure class="wp-block-pullquote"><blockquote><p>We grow everything we sell, so we can tell you exactly how to keep it alive.</p><cite>Marta Lindqvist, head grower</cite></blockquote></figure>
<!-- /wp:pullquote -->
' . $p( 'Open Tuesday to Sunday, 9 am to 6 pm. Free parking at the main gate, and a garden café under the old walnut tree.' )
);

$about = $page(
	'About us',
	$p( 'Fernhill started as a vegetable plot behind Anna and Josef Lindqvist’s house. Today their daughter Marta runs six greenhouses and an outdoor nursery of two hectares, and the plot still grows the tomatoes for our café.' ) . "\n" .
	$image( 'delphinium-flowers' ) . "\n" .
	$heading( 'How we grow' ) . "\n" .
	$p( 'We raise our plants without peat and without growth regulators, and we water them with the rain our roofs collect. It takes a little longer, and the plants are stronger for it.' ) . "\n" .
	$list( array( 'Peat-free compost, mixed on site', 'Rainwater from 3,000 m² of glass', 'Beneficial insects instead of sprays' ) )
);

$care = $page(
	'Plant care',
	$p( 'Most plants that fail at home were given too much love: too much water, too much food, too many moves. These guides keep it simple.' ) . "\n" .
	$heading( 'Watering' ) . "\n" .
	$p( 'Push a finger into the soil. If the first two centimetres are dry, water until it runs out of the bottom of the pot, then leave it alone.' ) . "\n" .
	$heading( 'Light' ) . "\n" .
	$list( array( 'Cacti and succulents: the sunniest window you have.', 'Anthuriums and ferns: bright, but out of direct sun.', 'Snake plants and ZZ plants: almost anywhere.' ) ) . "\n" .
	$heading( 'Repotting' ) . "\n" .
	$p( 'Repot in spring, and only when roots grow out of the drainage holes. Choose a pot one size larger, not three.' )
);

$visit = $page(
	'Visit us',
	$p( 'Fernhill Garden Nursery<br>Mill Lane 14, Ashbourne' ) . "\n" .
	$heading( 'Opening hours' ) . "\n" .
	$list( array( 'Tuesday to Friday: 9 am to 6 pm', 'Saturday and Sunday: 8 am to 5 pm', 'Monday: closed' ) ) . "\n" .
	$p( 'Our growers are happy to look at a plant that is not doing well. Bring a photo, or the plant itself.' )
);

$journal = $page( 'Journal', '' );

$posts = array(
	array( 'Five sunflowers for small gardens', 'category-sunflowers', 'No room for a three-metre giant? These varieties stay short and flower for weeks.', $p( 'Sunflowers do not need a field. Dwarf varieties stay under a metre, flower from July to the first frost and grow happily in a large pot on a balcony.' ) . "\n" . $list( array( 'Teddy Bear: fluffy, double flowers on 60 cm stems.', 'Little Becka: red and gold, ideal for cutting.', 'Sunny Smile: a single bright head on a sturdy stem.' ) ) ),
	array( 'Why we stopped using peat', 'flower-meadow-square', 'Peat bogs store more carbon than forests. Here is what we use instead, and how our plants took it.', $p( 'Five years ago we grew everything in peat, as nearly every nursery did. Today our compost is a mix of bark, coir and green waste from the town, and our plants have never been healthier.' ) . "\n" . $p( 'The change was not easy. Peat-free compost dries out faster on top while staying wet below, so we had to learn to water all over again.' ) ),
	array( 'A hibiscus that flowers all summer', 'red-hibiscus-closeup', 'Three things a hibiscus needs to keep flowering, and the one thing it hates.', $p( 'A hibiscus wants sun, warmth and a steady supply of water. Give it all three and it opens a new flower almost every day from June to September.' ) . "\n" . $p( 'What it hates is a draught. Keep it away from doors that open often, and bring it inside before the nights fall below 10 °C.' ) ),
);
foreach ( $posts as $i => $post ) {
	$id = wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_title'   => $post[0],
			'post_excerpt' => $post[2],
			'post_content' => $post[3],
			'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '2026-10-01' ) - $i * 9 * DAY_IN_SECONDS ),
		)
	);
	set_post_thumbnail( $id, $images[ $post[1] ]['id'] );
}
wp_update_term( 1, 'category', array( 'name' => 'Garden notes', 'slug' => 'garden-notes' ) );
wp_delete_post( 1, true );
wp_delete_post( 2, true );
wp_delete_post( 3, true );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );
update_option( 'page_for_posts', $journal );
update_option( 'blogname', 'Fernhill' );
update_option( 'blogdescription', 'Garden nursery' );

// The header's menu: the pages in this order.
$links = '';
foreach ( array( $about, $care, $journal, $visit ) as $id ) {
	$links .= '<!-- wp:navigation-link {"label":"' . get_the_title( $id ) . '","type":"page","id":' . $id . ',"url":"' . get_permalink( $id ) . '","kind":"post-type"} /-->';
}
wp_insert_post(
	array(
		'post_type'    => 'wp_navigation',
		'post_status'  => 'publish',
		'post_title'   => 'Header',
		'post_content' => $links,
	)
);

WP_CLI::success( 'Home ' . $home . ', About ' . $about . ', Care ' . $care . ', Visit ' . $visit . ', Journal ' . $journal );
