<?php
/**
 * WP Event Manager Uninstall
 *
 * Uninstalling WP Event Manager deletes events, custom post types, options,
 * and pages.
 *
 * @author   WP Event Manager
 * @category Core
 * @package  WP Event Manager/Uninstaller
 * @version  2.5
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete the custom post type posts
$args = array(
	'post_type'   => 'thurman-event',
	'numberposts' => -1,
	'post_status' => 'any',
);

$posts = get_posts( $args );

if ( ! empty( $posts ) ) {
	foreach ( $posts as $post ) {
		wp_delete_post( $post->ID, true );
	}
}

// Note: The original uninstall script removed several custom options from the wp_options table.
// Since our custom plugin no longer uses these, we don't need to include the deletion calls here.
// This keeps the uninstall process clean and specific to our custom 'thurman-event' post type.