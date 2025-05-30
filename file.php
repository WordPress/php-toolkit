<?php

require_once 'wordpress/wp-load.php';
$theme = wp_get_theme();
$term  = get_term_by( 'slug', $theme->get_stylesheet(), 'wp_theme' );
if ( ! $term ) {
	$term    = wp_insert_term( $theme->get_stylesheet(), 'wp_theme' );
	$term_id = $term['term_id'];
} else {
	$term_id = $term->term_id;
}
$post_id = wp_insert_post(
	array(
		'post_type'  => 'wp_template_part',
		'post_title' => '" + checkbox.dataset.post_title.replace( /' / g,
		"\\'",
	) + "', 'post_name' => '" + checkbox . dataset . post_name . replace( /'/g, "\\'" ) + "', 'post_content' => '" + checkbox.dataset.post_content.replace( /'/g, "\\'" ).replace( /\\n/g, "\n" ) + "', 'post_status' => 'publish' )
);

wp_set_object_terms( $post_id,
	$term_id, 'wp_theme' );",
} );
