<?php
/**
 * Plugin Name: WP Event Manager
 * Plugin URI:  https://www.wp-eventmanager.com/
 * Description: A lightweight, open source and full-featured event management plugin for adding event listing functionality to your WordPress site.
 * Version:     3.1.38
 * Author:      WP Event Manager
 * Author URI:  https://www.wp-eventmanager.com/
 * Text Domain: wp-event-manager
 * Domain Path: /languages/
 *
 * Copyright 2023 WP Event Manager (https://www.wp-eventmanager.com/)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP_Event_Manager class.
 */
class WP_Event_Manager {

    /**
     * Constructor.
     */
    public function __construct() {
        // Define constants
        $this->define_constants();

        // Include required files
        $this->includes();

        // Init hooks
        $this->init_hooks();
    }

    /**
     * Define constants.
     */
    private function define_constants() {
        define('EVENT_MANAGER_VERSION', '3.1.38');
        define('EVENT_MANAGER_PLUGIN_DIR', untrailingslashit(plugin_dir_path(__FILE__)));
        define('EVENT_MANAGER_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
        define('EVENT_MANAGER_PLUGIN_BASENAME', plugin_basename(__FILE__));
    }

    /**
     * Include required files.
     */
    private function includes() {
        include_once EVENT_MANAGER_PLUGIN_DIR . '/includes/wp-event-manager-functions.php';
        include_once EVENT_MANAGER_PLUGIN_DIR . '/includes/wp-event-manager-shortcodes.php';
        include_once EVENT_MANAGER_PLUGIN_DIR . '/admin/wp-event-manager-admin.php';
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Load the plugin text domain for translation.
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain('wp-event-manager', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Enqueue scripts and stylesheets.
     */
    public function enqueue_scripts() {
        wp_enqueue_style('wp-event-manager-frontend', EVENT_MANAGER_PLUGIN_URL . '/assets/css/frontend.css');
        wp_enqueue_script('wp-event-manager-frontend', EVENT_MANAGER_PLUGIN_URL . '/assets/js/frontend.js', array('jquery'), EVENT_MANAGER_VERSION, true);
    }
}

// Instantiate the plugin
$wp_event_manager = new WP_Event_Manager();

/**
 * Shortcode to display a list of events.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function custom_events_list_shortcode($atts) {
    // Shortcode attributes
    $atts = shortcode_atts(array(
        'limit' => -1, // -1 for all events
    ), $atts, 'events_list');

    // WP_Query arguments
    $args = array(
        'post_type' => 'thurman-event',
        'posts_per_page' => $atts['limit'],
        'meta_key' => 'event_dates', // Sort by the start date
        'orderby' => 'meta_value',
        'order' => 'ASC',
    );

    $events_query = new WP_Query($args);

    // The Loop
    if ($events_query->have_posts()) {
        $output = '<div class="events-list">';
        while ($events_query->have_posts()) {
            $events_query->the_post();
            $output .= '<div class="event-item">';
            // Get ACF Fields
            $event_name = get_field('event_name');
            $location_name = get_field('location_name');
            $start_date = get_field('event_dates');
            $event_image = get_field('event_featured_image');

            if ($event_image) {
                $output .= '<img src="' . esc_url($event_image['url']) . '" alt="' . esc_attr($event_image['alt']) . '" />';
            }
            $output .= '<h3>' . esc_html($event_name) . '</h3>';
            $output .= '<p><strong>When:</strong> ' . esc_html($start_date) . '</p>';
            $output .= '<p><strong>Where:</strong> ' . esc_html($location_name) . '</p>';
            $output .= '<a href="' . get_permalink() . '">View Event</a>';
            $output .= '</div>'; // .event-item
        }
        $output .= '</div>'; // .events-list
    } else {
        $output = '<p>No events found.</p>';
    }

    // Restore original Post Data
    wp_reset_postdata();

    return $output;
}
add_shortcode('events_list', 'custom_events_list_shortcode');

/**
 * Shortcode to display details for a single event.
 *
 * @return string
 */
function custom_event_details_shortcode() {
    // Check if we are on a single 'thurman-event' post
    if (is_singular('thurman-event')) {
        $post_id = get_the_ID();

        // Get ACF Fields
        $event_name = get_field('event_name', $post_id);
        $location_name = get_field('location_name', $post_id);
        $event_description = get_field('event_description', $post_id);
        $event_address = get_field('event_address', $post_id);
        $start_date = get_field('event_dates', $post_id);
        $end_date = get_field('end_date', $post_id);
        $event_image = get_field('event_featured_image', $post_id);

        $output = '<div class="event-details">';
        $output .= '<h2>' . esc_html($event_name) . '</h2>';

        if ($event_image) {
            $output .= '<img src="' . esc_url($event_image['url']) . '" alt="' . esc_attr($event_image['alt']) . '" />';
        }

        $output .= '<div class="event-meta">';
        $output .= '<p><strong>Start:</strong> ' . esc_html($start_date) . '</p>';
        if($end_date){
             $output .= '<p><strong>End:</strong> ' . esc_html($end_date) . '</p>';
        }
        $output .= '<p><strong>Location:</strong> ' . esc_html($location_name) . '</p>';
        $output .= '<p><strong>Address:</strong> ' . esc_html($event_address) . '</p>';
        $output .= '</div>'; // .event-meta

        $output .= '<div class="event-content">';
        $output .= wpautop(esc_html($event_description)); // wpautop to format text with paragraphs
        $output .= '</div>'; // .event-content

        $output .= '</div>'; // .event-details

        return $output;
    }
    return ''; // Return nothing if not on a single event page
}
add_shortcode('event_details', 'custom_event_details_shortcode');
?>