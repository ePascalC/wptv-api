<?php
/*
 * Plugin Name:   WordPress.tv REST API
 * Description:   Provides REST API endpoints for WordPress.tv data in JSON format
 * Version:       1.1.0-alpha
 * Author:        WordPress.tv
 * Author URI:    http://wordpress.tv
 */

/*
 * Register all routes
 */
add_action( 'rest_api_init', function() {
	// Events List
	$args = array(
		'methods' => WP_REST_Server::READABLE,
		'callback' => 'wptv_api_events_list',
	);
	register_rest_route( 'wptv-api/1.1', '/events/', $args );

	// Event One only
	$args = array(
		'methods' => WP_REST_Server::READABLE,
		'callback' => 'wptv_api_events_one',
	);
	register_rest_route( 'wptv-api/1.1', '/events/(?P<slug>[a-zA-Z0-9-]+)', $args );
}
);
 
/*
	EVENTS
*/
function wptv_api_events_list() {
	$all_data = array();
	
	$per_page = !isset($_GET['per_page']) ? 50 : (int)$_GET['per_page'];
	if ($per_page > 200) $per_page = 200;
	$page = !isset($_GET['page']) ? 1 : (int)$_GET['page'];

	$all_data['total_events'] = wp_count_terms( 'event' );
	$all_data['total_pages'] = floor( $all_data['total_events'] / $per_page ) + 1;
	if ( $page > $all_data['total_pages'] ) {
		$page = $all_data['total_pages'];
	}
	$all_data['current_page'] = $page;
	$offset = $per_page * ( $page - 1 );

	$terms = get_terms( array (
		'taxonomy' => 'event',
		'hide_empty' => false,
		'number' => $per_page,
		'offset' => $offset,
		));

	foreach ( $terms as $term ) {
		$all_data['events'][] = array(
			'name' => $term->name,
			'slug' => $term->slug,
		);
	}
	
	return $all_data;
}

function wptv_api_events_one( $data ) {
	$all_data = array();

	$event_slug = sanitize_text_field( $data['slug'] );
	if ( $event_slug == '' ) {
		return new WP_Error( 'error', 'Event slug not acceptable!', array( 'status' => 404 ) );
	}
	$event_obj = get_term_by( 'slug', $event_slug, 'event' );
	if ( !$event_obj ) {
		return new WP_Error( 'error', 'Event slug not found!', array( 'status' => 404 ) );
	}
	$all_data['event_name'] = $event_obj->name;
	
	$args = array(
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'tax_query' => array(
			array(
				'taxonomy' => 'event',
				'field' => 'slug',
				'terms' => array( $event_slug )
			)
		)
	);
	$query = get_posts( $args );

	$all_data['total_videos'] = count( $query );
	
	foreach ( $query as $post ) : setup_postdata( $post );
		$term_list = wp_get_post_terms( $post->ID, array( 'post_tag', 'speakers', 'language', 'producer', 'category' ) );

		$tags = array();
		$speakers = array();
		$languages = array();
		$producers = array();
		$categories = array();
		$year = '';
		
		foreach ( $term_list as $term ) {
			if ( $term->taxonomy == 'post_tag' ) {
				$tags[] = $term->name;
			}
			if ( $term->taxonomy == 'speakers' ) {
				$speakers[] = $term->name;
			}
			if ( $term->taxonomy == 'language' ) {
				$languages[] = $term->name;
			}
			if ( $term->taxonomy == 'producer' ) {
				$producers[] = $term->name;
			}
			if ( $term->taxonomy == 'category' ) {
				$categories[] = $term->name;
				if ( $term->parent == 15 ) { // This is the Year parent category
					$year = $term->name;
				}
				if ( $term->parent == 13 ) { // This is the location parent category
					$location = $term->name;
				}
			}
		}
		
		$slides_url = get_post_meta( $post->ID, '_wptv_slides_url', true );

		$videopress_info = array();
		if ( false !== stristr( $post->post_content, '[wpvideo' ) ) {
			preg_match( '#\[wpvideo ([a-zA-Z0-9]+)#i', $post->post_content, $guid );
			$guid = $guid[1];
			if ( function_exists( 'videopress_get_video_details' ) ) {
				$vid_info = json_decode( json_encode( videopress_get_video_details( $guid ) ),true );
			}
			if ( empty( $guid ) || ! $vid_info ) {
				return new WP_Error( 'error', 'An error has occurred on to get the video info.', array( 'status' => 500 ) );
			}
			$videopress_info['guid'] = $guid;
			$videopress_info['width'] = $vid_info['width'];
			$videopress_info['height'] = $vid_info['height'];
			$videopress_info['duration'] = $vid_info['duration'];
			$videopress_info['poster'] = $vid_info['poster'];
			$videopress_info['original'] = $vid_info['original'];
			$videopress_info['file_url_base'] = $vid_info['file_url_base']['https'];
			$videopress_info['files'] = $vid_info['files'];
			$videopress_info['subtitles'] = $vid_info['subtitles'];
		}
		
		$all_data['videos'][] = array(
			'post_id' => $post->ID,
			'post_date_gmt' => $post->post_date_gmt,
			'title' => $post->post_title,
			'permalink' => get_the_permalink(),
			'description' => $post->post_excerpt,
			'speakers' => $speakers,
			'producers' => $producers,
			'tags' => $tags,
			'slides' => $slides_url,
			'languages' => $languages,
			'recording_year' => $year,
			'location' => $location,
			'videopress_info' => $videopress_info,
		);
	endforeach;
	
	return $all_data;	
}
