<?php
/**
 * Plugin Name: WP Event Manager - Thurman Edition
 * Plugin URI:  https://www.wp-eventmanager.com/
 * Description: A custom event management plugin for Thurman, NY, designed to work with Breakdance Builder and ACF.
 * Version:     5.0.0 (Stable & Integrated)
 * Author:      (Your Name)
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('EVENT_MANAGER_VERSION', '5.0.0');
define('EVENT_MANAGER_PLUGIN_DIR', untrailingslashit(plugin_dir_path(__FILE__)));
define('EVENT_MANAGER_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));

require_once(EVENT_MANAGER_PLUGIN_DIR . '/admin/wp-event-manager-admin.php');

final class WP_Event_Manager_Custom {

    public function __construct() {
        add_action('init', array($this, 'register_event_category_taxonomy'));
        add_action('init', array($this, 'generate_ics_file'));
        add_action('acf/init', array($this, 'add_featured_event_field'));
        add_action('wp_head', array($this, 'add_event_schema_to_head'));
        add_shortcode('events_list', array($this, 'events_list_shortcode'));
        add_shortcode('event_details', array($this, 'event_details_shortcode'));
        add_action('wp_ajax_filter_events', array($this, 'ajax_filter_events_handler'));
        add_action('wp_ajax_nopriv_filter_events', array($this, 'ajax_filter_events_handler'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    private function build_event_query_args($atts) {
        $today = date('Y-m-d H:i:s');
        $args = array(
            'post_type' => 'thurman-event',
            'posts_per_page' => intval($atts['limit']),
            'paged' => intval($atts['paged']),
            'meta_key' => 'event_dates',
            'orderby' => 'meta_value',
        );
        if (!empty($atts['search'])) {
            $args['s'] = sanitize_text_field($atts['search']);
        }
        $meta_query = array('relation' => 'AND');
        if ($atts['display'] === 'past') {
            $args['order'] = 'DESC';
            $meta_query[] = array('key' => 'event_dates', 'value' => $today, 'compare' => '<', 'type' => 'DATETIME');
        } else {
            $args['order'] = 'ASC';
            $meta_query[] = array('key' => 'event_dates', 'value' => $today, 'compare' => '>=', 'type' => 'DATETIME');
        }
        if (filter_var($atts['featured_only'], FILTER_VALIDATE_BOOLEAN)) {
            $meta_query[] = array('key' => 'is_featured', 'value' => '1', 'compare' => '=');
        }
        if (!empty($atts['category'])) {
            $args['tax_query'] = array(array('taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $atts['category']));
        }
        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }
        return $args;
    }

    private function render_list_item_html() {
        $event_name = get_field('event_name');
        $start_date_str = get_field('event_dates');
        $output = '<a href="' . get_permalink() . '" class="event-list-item">';
        $output .= '<div class="event-list-item-date">';
        try {
            $date = new DateTime($start_date_str);
            $output .= '<span class="month">' . $date->format('M') . '</span>';
            $output .= '<span class="day">' . $date->format('d') . '</span>';
        } catch (Exception $e) {
            $output .= '<span class="month">ERR</span><span class="day">!</span>';
        }
        $output .= '</div>';
        $output .= '<div class="event-list-item-title bde-text">' . esc_html($event_name) . '</div>';
        $output .= '</a>';
        return $output;
    }

    private function render_grid_item_html() {
        $button_text = get_option('event_manager_button_text', 'View Event');
        $card_bg_color = get_option('event_manager_card_background_color');
        $card_text_color = get_option('event_manager_card_text_color');
        $btn_bg_color = get_option('event_manager_button_background_color');
        $btn_text_color = get_option('event_manager_button_text_color');
        $card_styles = '';
        if (!empty($card_bg_color)) { $card_styles .= 'background-color: ' . esc_attr($card_bg_color) . ';'; }
        if (!empty($card_text_color)) { $card_styles .= 'color: ' . esc_attr($card_text_color) . ';'; }
        $button_styles = '';
        if (!empty($btn_bg_color)) { $button_styles .= 'background-color: ' . esc_attr($btn_bg_color) . ';'; }
        if (!empty($btn_text_color)) { $button_styles .= 'color: ' . esc_attr($btn_text_color) . ';'; }
        $event_name = get_field('event_name');
        $location_name = get_field('location_name');
        $start_date = get_field('event_dates');
        $event_image = get_field('event_featured_image');
        $is_featured = get_field('is_featured');
        $output = '<div class="event-item-card" style="' . $card_styles . '">';
        if ($is_featured) {
            $output .= '<span class="featured-badge">Featured</span>';
        }
        if ($event_image) {
            $output .= '<div class="bde-image"><img src="' . esc_url($event_image['url']) . '" alt="' . esc_attr($event_image['alt']) . '" /></div>';
        }
        $output .= '<h3 class="bde-heading">' . esc_html($event_name) . '</h3>';
        $output .= '<p class="bde-text"><strong>When:</strong> ' . esc_html($start_date) . '</p>';
        $output .= '<p class="bde-text"><strong>Where:</strong> ' . esc_html($location_name) . '</p>';
        $output .= '<a href="' . get_permalink() . '" class="bde-button" style="' . $button_styles . '"><span class="bde-button-text">' . esc_html($button_text) . '</span></a>';
        $output .= '</div>';
        return $output;
    }
    
    public function get_events_html($args, $layout, $return_pagination = false) {
        $events_query = new WP_Query($args);
        $output = '';
        if ($events_query->have_posts()) {
            while ($events_query->have_posts()) {
                $events_query->the_post();
                if ($layout === 'list') {
                    $output .= $this->render_list_item_html();
                } else {
                    $output .= $this->render_grid_item_html();
                }
            }
        } else {
            $output = '<p>No events found matching your criteria.</p>';
        }

        $pagination_html = '';
        if ($return_pagination) {
            $big = 999999999;
            $pagination_html = paginate_links(array(
                'base' => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
                'format' => '?paged=%#%',
                'current' => max(1, $args['paged']),
                'total' => $events_query->max_num_pages,
                'prev_text' => __('« Previous'),
                'next_text' => __('Next »'),
            ));
        }
        
        wp_reset_postdata();
        
        if ($return_pagination) {
            return array('content' => $output, 'pagination' => $pagination_html);
        }
        return $output;
    }
    
    public function events_list_shortcode($atts) {
        $default_layout = get_option('event_manager_default_layout', 'grid');
        $default_limit = get_option('event_manager_per_page', '9');
        $atts = shortcode_atts(array('limit' => $default_limit, 'featured_only' => 'false', 'display' => 'upcoming', 'layout' => $default_layout), $atts, 'events_list');
        $atts['paged'] = get_query_var('paged') ? get_query_var('paged') : 1;
        
        $initial_args = $this->build_event_query_args($atts);
        $result = $this->get_events_html($initial_args, $atts['layout'], true);

        $categories = get_terms(array('taxonomy' => 'event_category', 'hide_empty' => true));
        $output = '<div class="event-list-container">';
        if (!filter_var($atts['featured_only'], FILTER_VALIDATE_BOOLEAN) && $atts['display'] !== 'past') {
            $output .= '<div class="event-filters">';
            $output .= '<div class="event-search-filter"><label for="event-search-input">Search Events:</label><input type="search" id="event-search-input" placeholder="Enter keywords..."></div>';
            if (!empty($categories) && !is_wp_error($categories)) {
                $output .= '<div class="event-category-filter-wrapper"><label for="event-category-filter">Filter by Category:</label><select id="event-category-filter" data-layout="' . esc_attr($atts['layout']) . '">';
                $output .= '<option value="">All Categories</option>';
                foreach ($categories as $category) {
                    $output .= '<option value="' . esc_attr($category->slug) . '">' . esc_html($category->name) . '</option>';
                }
                $output .= '</select></div>';
            }
            $output .= '</div>';
        }
        
        $container_class = ($atts['layout'] === 'list') ? 'events-list-wrapper' : 'bde-div-grid events-list-grid';
        $output .= '<div id="events-output-wrapper" class="' . $container_class . '">';
        $output .= $result['content'];
        $output .= '</div>';
        $output .= '<div id="events-pagination" class="pagination">' . $result['pagination'] . '</div>';
        $output .= '</div>';
        return $output;
    }

    public function ajax_filter_events_handler() {
        $default_limit = get_option('event_manager_per_page', '9');
        $ajax_atts = array(
            'limit'         => $default_limit,
            'display'       => 'upcoming',
            'featured_only' => 'false',
            'layout'        => isset($_POST['layout']) ? sanitize_text_field($_POST['layout']) : 'grid',
            'category'      => isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '',
            'search'        => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'paged'         => isset($_POST['page']) ? intval($_POST['page']) : 1,
        );
        $args = $this->build_event_query_args($ajax_atts);
        $result = $this->get_events_html($args, $ajax_atts['layout'], true);
        
        wp_send_json_success($result);
    }
    
    public function enqueue_scripts() {
        // Enqueue the new central stylesheet
        wp_enqueue_style('wp-event-manager-frontend-styles', EVENT_MANAGER_PLUGIN_URL . '/assets/css/frontend.css', array(), EVENT_MANAGER_VERSION);
        
        // Enqueue the javascript for filters
        wp_enqueue_script('wp-event-manager-filters', EVENT_MANAGER_PLUGIN_URL . '/assets/js/event-filters.js', array('jquery'), EVENT_MANAGER_VERSION, true);
        wp_localize_script('wp-event-manager-filters', 'event_manager_ajax', array('ajax_url' => admin_url('admin-ajax.php')));
    }

    // --- All other functions remain unchanged ---
    public function add_featured_event_field() { if (function_exists('acf_add_local_field_group')) { acf_add_local_field_group(array('key' => 'group_60a5f8b9e8a1c', 'title' => 'Event Status', 'fields' => array(array('key' => 'field_60a5f8c3e8a1d', 'label' => 'Feature this event?', 'name' => 'is_featured', 'type' => 'true_false', 'instructions' => 'Featured events can be highlighted and displayed separately.', 'required' => 0, 'conditional_logic' => 0, 'message' => '', 'default_value' => 0, 'ui' => 1, 'ui_on_text' => 'Featured', 'ui_off_text' => 'Standard')), 'location' => array(array(array('param' => 'post_type', 'operator' => '==', 'value' => 'thurman-event'))), 'menu_order' => 10, 'position' => 'side', 'style' => 'default', 'label_placement' => 'top', 'instruction_placement' => 'label', 'hide_on_screen' => '', 'active' => true, 'description' => '')); } }
    public function add_event_schema_to_head() { if (is_singular('thurman-event') && function_exists('get_field')) { $post_id = get_the_ID(); $event_name = get_field('event_name', $post_id); $event_description = get_field('event_description', $post_id); $start_datetime_str = get_field('event_dates', $post_id); $end_datetime_str = get_field('end_date', $post_id); $location_name = get_field('location_name', $post_id); $event_address = get_field('event_address', $post_id); $event_image = get_field('event_featured_image', $post_id); if(empty($start_datetime_str)) return; $start_date_iso = (new DateTime($start_datetime_str))->format('c'); $end_date_iso = !empty($end_datetime_str) ? (new DateTime($end_datetime_str))->format('c') : $start_date_iso; $schema = array('@context' => 'https://schema.org', '@type' => 'Event', 'name' => $event_name, 'startDate' => $start_date_iso, 'endDate' => $end_date_iso, 'description' => $event_description, 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'eventStatus' => 'https://schema.org/EventScheduled', 'location' => array('@type' => 'Place', 'name' => $location_name, 'address' => array('@type' => 'PostalAddress', 'streetAddress' => $event_address))); if ($event_image && isset($event_image['url'])) { $schema['image'] = esc_url($event_image['url']); } echo '<script type="application/ld+json">' . json_encode($schema) . '</script>'; } }
    public function register_event_category_taxonomy() { $labels = array('name' => 'Event Categories', 'singular_name' => 'Event Category', 'search_items' => 'Search Event Categories', 'all_items' => 'All Event Categories', 'parent_item' => 'Parent Event Category', 'parent_item_colon' => 'Parent Event Category:', 'edit_item' => 'Edit Event Category', 'update_item' => 'Update Event Category', 'add_new_item' => 'Add New Event Category', 'new_item_name' => 'New Event Category Name', 'menu_name' => 'Event Categories'); register_taxonomy('event_category', array('thurman-event'), array('hierarchical' => true, 'labels' => $labels, 'show_ui' => true, 'show_in_rest' => true, 'show_admin_column' => true, 'query_var' => true, 'rewrite' => array('slug' => 'event-category'))); }
    public function event_details_shortcode() { if (!function_exists('get_field')) return '<p>ACF not found.</p>'; if (is_singular('thurman-event')) { $post_id = get_the_ID(); $event_name = get_field('event_name', $post_id); $location_name = get_field('location_name', $post_id); $event_description = get_field('event_description', $post_id); $event_address = get_field('event_address', $post_id); $start_datetime_str = get_field('event_dates', $post_id); $end_datetime_str = get_field('end_date', $post_id); $event_image = get_field('event_featured_image', $post_id); $event_url = get_permalink($post_id); $map_link = 'https://maps.google.com/?q=' . urlencode($event_address); $start_date = new DateTime($start_datetime_str); $end_date = !empty($end_datetime_str) ? new DateTime($end_datetime_str) : (clone $start_date)->modify('+1 hour'); $google_cal_link = 'https://www.google.com/calendar/render?action=TEMPLATE&text=' . urlencode($event_name) . '&dates=' . $start_date->format('Ymd\THis') . '/' . $end_date->format('Ymd\THis') . '&details=' . urlencode($event_description) . '&location=' . urlencode($event_address); $ics_link = add_query_arg('download_ics', $post_id, home_url()); $twitter_link = 'https://twitter.com/intent/tweet?text=' . urlencode('Check out this event: ' . $event_name) . '&url=' . urlencode($event_url); $facebook_link = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($event_url); $email_link = 'mailto:?subject=' . rawurlencode($event_name) . '&body=Check out this event: ' . rawurlencode($event_url); $output = '<div class="event-details">'; $output .= '<h2 class="bde-heading">' . esc_html($event_name) . '</h2>'; if ($event_image) { $output .= '<img class="event-details-image" src="' . esc_url($event_image['url']) . '" alt="' . esc_attr($event_image['alt']) . '" />'; } $output .= '<div class="event-meta">'; $output .= '<p class="bde-text"><strong>Start:</strong> ' . esc_html($start_datetime_str) . '</p>'; if ($end_datetime_str) { $output .= '<p class="bde-text"><strong>End:</strong> ' . esc_html($end_datetime_str) . '</p>'; } $output .= '<p class="bde-text"><strong>Location:</strong> ' . esc_html($location_name) . '</p>'; $output .= '<p class="bde-text"><strong>Address:</strong> ' . esc_html($event_address) . '</p>'; $output .= '</div>'; $output .= '<div class="event-actions">'; $output .= '<h3 class="bde-heading event-actions-title">Event Tools</h3>'; $output .= '<div class="event-action-buttons">'; $output .= '<a href="' . esc_url($map_link) . '" target="_blank" class="event-action-button map-button">View on Map</a>'; $output .= '<a href="' . esc_url($google_cal_link) . '" target="_blank" class="event-action-button calendar-button">Add to Google Calendar</a>'; $output .= '<a href="' . esc_url($ics_link) . '" class="event-action-button calendar-button">Add to Apple/Outlook</a>'; $output .= '</div></div>'; $output .= '<div class="event-actions social-share">'; $output .= '<h3 class="bde-heading event-actions-title">Share This Event</h3>'; $output .= '<div class="event-action-buttons">'; $output .= '<a href="' . esc_url($facebook_link) . '" target="_blank" class="event-action-button facebook-button">Share on Facebook</a>'; $output .= '<a href="' . esc_url($twitter_link) . '" target="_blank" class="event-action-button twitter-button">Share on Twitter</a>'; $output .= '<a href="' . esc_url($email_link) . '" target="_blank" class="event-action-button email-button">Share via Email</a>'; $output .= '</div></div>'; $output .= '<div class="event-content bde-text">' . wpautop(esc_html($event_description)) . '</div>'; $output .= '</div>'; return $output; } return ''; }
    public function generate_ics_file() { if (isset($_GET['download_ics'])) { $post_id = intval($_GET['download_ics']); $event_name = get_field('event_name', $post_id); $location_name = get_field('location_name', $post_id); $event_description = get_field('event_description', $post_id); $start_datetime_str = get_field('event_dates', $post_id); $end_datetime_str = get_field('end_date', $post_id); if(empty($start_datetime_str)) return; $start_date = new DateTime($start_datetime_str); $end_date = !empty($end_datetime_str) ? new DateTime($end_datetime_str) : (clone $start_date)->modify('+1 hour'); $timestamp = time(); header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="event-' . $post_id . '.ics"'); echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\nBEGIN:VEVENT\r\nUID:" . $timestamp . "@" . $_SERVER['SERVER_NAME'] . "\r\nDTSTAMP:" . gmdate('Ymd\THis\Z', $timestamp) . "\r\nDTSTART:" . $start_date->format('Ymd\THis') . "\r\nDTEND:" . $end_date->format('Ymd\THis') . "\r\nSUMMARY:" . esc_html($event_name) . "\r\nDESCRIPTION:" . str_replace("\r\n", "\\n", esc_html($event_description)) . "\r\nLOCATION:" . esc_html($location_name) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"; exit; } }
}
new WP_Event_Manager_Custom();