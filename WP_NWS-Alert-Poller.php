<?php
/**
 * Plugin Name: NWS Alerts Importer
 * Plugin URI: https://wxalerts.org
 * Description: Polls the National Weather Service API for active alerts with parent-child relationship tracking
 * Version: 2.0.0
 * Author: WxAlerts
 * License: GPL v2 or later
 * Text Domain: nws-alerts-importer
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class NWS_Alerts_Importer {
    
    private $last_poll_option = 'nws_last_poll_time';
    private $processed_alerts_option = 'nws_processed_alerts';
    private $settings_option = 'nws_alerts_settings';
    
    public function __construct() {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Register custom post type
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_meta_fields'));
        
        // Hook into WordPress cron
        add_action('nws_poll_alerts', array($this, 'poll_nws_alerts'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // AJAX endpoint for polling
        add_action('wp_ajax_nws_poll_now', array($this, 'ajax_poll_now'));
        
        // Add custom cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_one_minute'] = array(
            'interval' => 60,
            'display' => __('Every 1 Minute', 'nws-alerts-importer')
        );
        $schedules['every_two_minutes'] = array(
            'interval' => 120,
            'display' => __('Every 2 Minutes', 'nws-alerts-importer')
        );
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'nws-alerts-importer')
        );
        return $schedules;
    }
    
    /**
     * Get settings with defaults
     */
    private function get_settings() {
        $defaults = array(
            'poll_interval' => 'every_two_minutes',
            'user_agent' => 'wxalerts.org/1.0 (support@wxalerts.org)'
        );
        
        return wp_parse_args(get_option($this->settings_option, array()), $defaults);
    }
    
    /**
     * Activate the plugin
     */
    public function activate() {
        // Register post type for flush_rewrite_rules
        $this->register_post_type();
        $this->register_meta_fields();
        flush_rewrite_rules();
        
        // Schedule cron event with default interval
        $settings = $this->get_settings();
        if (!wp_next_scheduled('nws_poll_alerts')) {
            wp_schedule_event(time(), $settings['poll_interval'], 'nws_poll_alerts');
        }
        
        // Initialize processed alerts array
        if (!get_option($this->processed_alerts_option)) {
            update_option($this->processed_alerts_option, array());
        }
    }
    
    /**
     * Deactivate the plugin
     */
    public function deactivate() {
        $timestamp = wp_next_scheduled('nws_poll_alerts');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'nws_poll_alerts');
        }
    }
    
    /**
     * Register custom post type for weather alerts
     */
    public function register_post_type() {
        $labels = array(
            'name' => 'Weather Alerts',
            'singular_name' => 'Weather Alert',
            'menu_name' => 'Weather Alerts',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Alert',
            'edit_item' => 'Edit Alert',
            'new_item' => 'New Alert',
            'view_item' => 'View Alert',
            'search_items' => 'Search Alerts',
            'not_found' => 'No alerts found',
            'not_found_in_trash' => 'No alerts found in trash'
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'menu_icon' => 'dashicons-warning',
            'supports' => array('title', 'editor', 'custom-fields'),
            'show_in_rest' => true,
            'rest_base' => 'wx-alert',
            'rewrite' => array('slug' => 'weather-alert'),
            'hierarchical' => true // Enable parent support
        );
        
        register_post_type('wx-alert', $args);
    }
    
    /**
     * Register meta fields (replaces ACF)
     */
    public function register_meta_fields() {
        $meta_fields = array(
            'nws_id' => 'string',
            'nws_identifier' => 'string',
            'nws_sequence' => 'string',
            'nws_version' => 'string',
            'area_desc' => 'string',
            'same_codes' => 'string',
            'ugc_codes' => 'string',
            'sent' => 'string',
            'effective' => 'string',
            'onset' => 'string',
            'ends' => 'string',
            'status' => 'string',
            'message_type' => 'string',
            'severity' => 'string',
            'certainty' => 'string',
            'urgency' => 'string',
            'event' => 'string',
            'sendername' => 'string',
            'headline' => 'string',
            'description' => 'string',
            'instruction' => 'string',
            'vtec' => 'string',
            'polly_binding' => 'string'
        );
        
        foreach ($meta_fields as $key => $type) {
            register_post_meta('wx-alert', $key, array(
                'type' => $type,
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ));
        }
    }
    
    /**
     * Parse NWS alert ID into components
     */
    private function parse_nws_id($nws_id) {
        // Format: urn:oid:2.49.0.1.840.0.{identifier}.{sequence}.{version}
        $parts = explode('.', $nws_id);
        
        if (count($parts) < 9) {
            return false;
        }
        
        return array(
            'full_id' => $nws_id,
            'identifier' => $parts[6],
            'sequence' => $parts[7],
            'version' => $parts[8]
        );
    }
    
    /**
     * Find existing alert by NWS ID
     */
    private function find_existing_alert($nws_id) {
        global $wpdb;
        
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = 'nws_id' AND meta_value = %s 
             LIMIT 1",
            $nws_id
        ));
        
        return $post_id;
    }
    
    /**
     * Find parent alert by identifier (sequence 001)
     */
    private function find_parent_alert($identifier) {
        global $wpdb;
        
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = 'nws_identifier' AND meta_value = %s 
             AND post_id IN (
                 SELECT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = 'nws_sequence' AND meta_value = '001'
             )
             LIMIT 1",
            $identifier
        ));
        
        return $post_id;
    }
    
    /**
     * Fetch a specific alert from NWS API
     */
    private function fetch_alert_by_id($nws_id) {
        $settings = $this->get_settings();
        $url = 'https://api.weather.gov/alerts/' . urlencode($nws_id);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'User-Agent' => $settings['user_agent'],
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data;
    }
    
    /**
     * Poll the NWS API for active alerts
     */
    public function poll_nws_alerts() {
        $log = array();
        $log[] = '=== NWS Poll Task Started ===';
        $log[] = 'Timestamp: ' . current_time('mysql');
        
        $settings = $this->get_settings();
        $now = time();
        
        // Get poll interval in seconds
        $intervals = array(
            'every_one_minute' => 60,
            'every_two_minutes' => 120,
            'every_five_minutes' => 300
        );
        $poll_interval = $intervals[$settings['poll_interval']] ?? 120;
        
        // Check polling interval
        $last_poll_time = get_option($this->last_poll_option, 0);
        $time_since_poll = $now - $last_poll_time;
        
        $log[] = 'Last poll: ' . ($last_poll_time ? date('Y-m-d H:i:s', $last_poll_time) : 'Never');
        $log[] = 'Time since last poll: ' . $time_since_poll . ' seconds';
        $log[] = 'Required interval: ' . $poll_interval . ' seconds';
        
        if ($time_since_poll < $poll_interval) {
            $log[] = '⏭️  Polling skipped - too soon since last poll';
            $this->log_to_file($log);
            return array(
                'status' => 'skipped',
                'message' => 'Polling skipped. Last polled at ' . date('Y-m-d H:i:s', $last_poll_time)
            );
        }
        
        // Fetch NWS alerts
        $nws_url = 'https://api.weather.gov/alerts/active';
        $log[] = 'Fetching alerts from: ' . $nws_url;
        
        $response = wp_remote_get($nws_url, array(
            'headers' => array(
                'User-Agent' => $settings['user_agent'],
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $log[] = '❌ Error: ' . $response->get_error_message();
            $this->log_to_file($log);
            return array('status' => 'error', 'message' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['features']) || !is_array($data['features'])) {
            $log[] = '❌ Invalid API response';
            $this->log_to_file($log);
            return array('status' => 'error', 'message' => 'Invalid API response');
        }
        
        $alerts = $data['features'];
        $log[] = '✅ Fetched ' . count($alerts) . ' alerts from NWS';
        
        $results = array(
            'total' => count($alerts),
            'uploaded' => 0,
            'skipped' => 0,
            'dismissed' => 0,
            'errors' => array()
        );
        
        $processed_alerts = get_option($this->processed_alerts_option, array());
        
        // Process each alert
        $log[] = "\n--- Processing Alerts ---";
        foreach ($alerts as $index => $alert) {
            $nws_id = $alert['properties']['id'];
            $log[] = "\n[" . ($index + 1) . "/" . count($alerts) . "] Processing alert: " . $nws_id;
            $log[] = "  Event: " . $alert['properties']['event'];
            $log[] = "  Area: " . $alert['properties']['areaDesc'];
            
            // Parse the NWS ID
            $parsed = $this->parse_nws_id($nws_id);
            if (!$parsed) {
                $log[] = "  ❌ Could not parse NWS ID format";
                $results['errors'][] = $nws_id . ': Invalid ID format';
                continue;
            }
            
            $log[] = "  Identifier: " . $parsed['identifier'];
            $log[] = "  Sequence: " . $parsed['sequence'];
            $log[] = "  Version: " . $parsed['version'];
            
            // Check if already processed
            if (in_array($nws_id, $processed_alerts)) {
                $log[] = "  ⏭️  Skipped - already processed";
                $results['skipped']++;
                continue;
            }
            
            // Check WordPress for duplicate
            $existing = $this->find_existing_alert($nws_id);
            if ($existing) {
                $log[] = "  ⏭️  Skipped - already in WordPress (Post ID: " . $existing . ")";
                $processed_alerts[] = $nws_id;
                $results['skipped']++;
                continue;
            }
            
            $parent_id = null;
            
            // Handle parent-child relationships for sequences > 001
            if ($parsed['sequence'] !== '001') {
                $log[] = "  → This is a follow-up (sequence " . $parsed['sequence'] . "), looking for parent...";
                
                // Try to find existing parent
                $parent_id = $this->find_parent_alert($parsed['identifier']);
                
                if ($parent_id) {
                    $log[] = "  ✓ Found parent alert (Post ID: " . $parent_id . ")";
                } else {
                    $log[] = "  ⚠️  Parent (sequence 001) not found, attempting to fetch...";
                    
                    // Construct parent ID
                    $parent_nws_id = str_replace(
                        '.' . $parsed['sequence'] . '.' . $parsed['version'],
                        '.001.1',
                        $nws_id
                    );
                    
                    $log[] = "  → Fetching parent: " . $parent_nws_id;
                    
                    // Try to fetch parent from API
                    $parent_alert = $this->fetch_alert_by_id($parent_nws_id);
                    
                    if ($parent_alert && isset($parent_alert['properties'])) {
                        $log[] = "  ✓ Successfully fetched parent from API";
                        $parent_id = $this->create_alert_post($parent_alert, null, $log);
                        
                        if ($parent_id) {
                            $log[] = "  ✓ Created parent post (ID: " . $parent_id . ")";
                            $processed_alerts[] = $parent_nws_id;
                        } else {
                            $log[] = "  ❌ Failed to create parent post";
                        }
                    } else {
                        $log[] = "  ❌ Could not fetch parent from API";
                    }
                    
                    // If we still don't have a parent, dismiss this alert
                    if (!$parent_id) {
                        $log[] = "  🗑️  Dismissed - parent (sequence 001) could not be found or created";
                        $results['dismissed']++;
                        continue;
                    }
                }
            }
            
            // Create WordPress post
            try {
                $log[] = "  → Creating WordPress post...";
                $post_id = $this->create_alert_post($alert, $parent_id, $log);
                
                if ($post_id) {
                    $log[] = "  ✅ Created post ID: " . $post_id;
                    if ($parent_id) {
                        $log[] = "  ✓ Linked to parent ID: " . $parent_id;
                    }
                    $processed_alerts[] = $nws_id;
                    $results['uploaded']++;
                } else {
                    $log[] = "  ❌ Failed to create post";
                    $results['errors'][] = $nws_id . ': Failed to create post';
                }
            } catch (Exception $e) {
                $log[] = "  ❌ Error: " . $e->getMessage();
                $results['errors'][] = $nws_id . ': ' . $e->getMessage();
            }
        }
        
        // Update processed alerts list (keep last 10,000)
        if (count($processed_alerts) > 10000) {
            $processed_alerts = array_slice($processed_alerts, -10000);
        }
        update_option($this->processed_alerts_option, $processed_alerts);
        
        // Update last poll time
        update_option($this->last_poll_option, $now);
        $log[] = "\n✓ Set last poll time to: " . date('Y-m-d H:i:s', $now);
        
        // Final stats
        $log[] = "\n=== Poll Complete ===";
        $log[] = "Total alerts: " . $results['total'];
        $log[] = "Uploaded: " . $results['uploaded'];
        $log[] = "Skipped: " . $results['skipped'];
        $log[] = "Dismissed: " . $results['dismissed'];
        $log[] = "Errors: " . count($results['errors']);
        
        $this->log_to_file($log);
        
        return array(
            'status' => 'success',
            'polledAt' => date('Y-m-d H:i:s', $now),
            'results' => $results
        );
    }
    
    /**
     * Create WordPress post from alert data
     */
    private function create_alert_post($alert, $parent_id = null, &$log = array()) {
        $props = $alert['properties'];
        
        // Parse NWS ID
        $parsed = $this->parse_nws_id($props['id']);
        if (!$parsed) {
            return false;
        }
        
        // Create post content
        $content = sprintf(
            '<p><strong>%s</strong></p>
            <p><strong>Issued:</strong> %s</p>
            <p><strong>Effective:</strong> %s</p>
            <p><strong>Expires:</strong> %s</p>
            <hr>
            %s
            <p><em>Source: <a href="%s" target="_blank">National Weather Service</a></em></p>',
            esc_html($props['event'] ?? ''),
            date('F j, Y g:i A', strtotime($props['sent'])),
            date('F j, Y g:i A', strtotime($props['effective'])),
            date('F j, Y g:i A', strtotime($props['expires'])),
            wpautop(esc_html($props['description'] ?? '')),
            esc_url($alert['id'])
        );
        
        // Create post
        $post_data = array(
            'post_title' => $props['headline'] ?? 'NWS Weather Alert',
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'wx-alert',
            'post_author' => 1,
            'post_parent' => $parent_id ? $parent_id : 0
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return false;
        }
        
        // Add meta fields
        $meta_fields = array(
            'nws_id' => $parsed['full_id'],
            'nws_identifier' => $parsed['identifier'],
            'nws_sequence' => $parsed['sequence'],
            'nws_version' => $parsed['version'],
            'area_desc' => $props['areaDesc'] ?? '',
            'same_codes' => json_encode($props['geocode']['SAME'] ?? array()),
            'ugc_codes' => json_encode($props['geocode']['UGC'] ?? array()),
            'sent' => $props['sent'] ?? '',
            'effective' => $props['effective'] ?? '',
            'onset' => $props['onset'] ?? '',
            'ends' => $props['ends'] ?? '',
            'status' => $props['status'] ?? '',
            'message_type' => $props['messageType'] ?? '',
            'severity' => $props['severity'] ?? '',
            'certainty' => $props['certainty'] ?? '',
            'urgency' => $props['urgency'] ?? '',
            'event' => $props['event'] ?? '',
            'sendername' => $props['senderName'] ?? '',
            'headline' => $props['headline'] ?? '',
            'description' => $props['description'] ?? '',
            'instruction' => $props['instruction'] ?? '',
            'vtec' => $props['parameters']['VTEC'][0] ?? '',
            'polly_binding' => isset($alert['geometry']['coordinates']) ? json_encode($alert['geometry']['coordinates']) : ''
        );
        
        foreach ($meta_fields as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
        
        return $post_id;
    }
    
    /**
     * Log to file
     */
    private function log_to_file($log_array) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/nws-alerts-log.txt';
        
        $log_content = implode("\n", $log_array) . "\n\n";
        file_put_contents($log_file, $log_content, FILE_APPEND);
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('nws_alerts_settings_group', $this->settings_option, array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        $sanitized['poll_interval'] = in_array($input['poll_interval'], array('every_one_minute', 'every_two_minutes', 'every_five_minutes'))
            ? $input['poll_interval']
            : 'every_two_minutes';
        
        $sanitized['user_agent'] = sanitize_text_field($input['user_agent']);
        
        // Reschedule cron if interval changed
        $old_settings = $this->get_settings();
        if ($sanitized['poll_interval'] !== $old_settings['poll_interval']) {
            $timestamp = wp_next_scheduled('nws_poll_alerts');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'nws_poll_alerts');
            }
            wp_schedule_event(time(), $sanitized['poll_interval'], 'nws_poll_alerts');
        }
        
        return $sanitized;
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=wx-alert',
            'NWS Settings',
            'Settings',
            'manage_options',
            'nws-alerts-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'edit.php?post_type=wx-alert',
            'NWS Poll Status',
            'Poll Status',
            'manage_options',
            'nws-poll-status',
            array($this, 'status_page')
        );
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>NWS Alerts Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('nws_alerts_settings_group'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Polling Interval</th>
                        <td>
                            <select name="<?php echo $this->settings_option; ?>[poll_interval]">
                                <option value="every_one_minute" <?php selected($settings['poll_interval'], 'every_one_minute'); ?>>Every 1 Minute</option>
                                <option value="every_two_minutes" <?php selected($settings['poll_interval'], 'every_two_minutes'); ?>>Every 2 Minutes</option>
                                <option value="every_five_minutes" <?php selected($settings['poll_interval'], 'every_five_minutes'); ?>>Every 5 Minutes</option>
                            </select>
                            <p class="description">How often to poll the NWS API for new alerts.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">User Agent</th>
                        <td>
                            <input type="text" name="<?php echo $this->settings_option; ?>[user_agent]" 
                                   value="<?php echo esc_attr($settings['user_agent']); ?>" 
                                   class="regular-text" />
                            <p class="description">Identify your application to the NWS API (format: AppName/Version (contact@email.com))</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Status page
     */
    public function status_page() {
        $last_poll = get_option($this->last_poll_option, 0);
        $processed_count = count(get_option($this->processed_alerts_option, array()));
        $settings = $this->get_settings();
        
        ?>
        <div class="wrap">
            <h1>NWS Alerts Poll Status</h1>
            
            <div class="card">
                <h2>Status</h2>
                <table class="form-table">
                    <tr>
                        <th>Last Poll Time:</th>
                        <td><?php echo $last_poll ? date('F j, Y g:i:s A', $last_poll) : 'Never'; ?></td>
                    </tr>
                    <tr>
                        <th>Next Scheduled Poll:</th>
                        <td><?php 
                            $next = wp_next_scheduled('nws_poll_alerts');
                            echo $next ? date('F j, Y g:i:s A', $next) : 'Not scheduled';
                        ?></td>
                    </tr>
                    <tr>
                        <th>Processed Alerts:</th>
                        <td><?php echo number_format($processed_count); ?></td>
                    </tr>
                    <tr>
                        <th>Poll Interval:</th>
                        <td><?php 
                            $intervals = array(
                                'every_one_minute' => 'Every 1 Minute',
                                'every_two_minutes' => 'Every 2 Minutes',
                                'every_five_minutes' => 'Every 5 Minutes'
                            );
                            echo $intervals[$settings['poll_interval']] ?? 'Unknown';
                        ?></td>
                    </tr>
                    <tr>
                        <th>User Agent:</th>
                        <td><code><?php echo esc_html($settings['user_agent']); ?></code></td>
                    </tr>
                </table>
                
                <p>
                    <button id="nws-poll-now" class="button button-primary">Poll Now</button>
                    <span id="nws-poll-status"></span>
                </p>
            </div>
            
            <div class="card">
                <h2>Recent Posts</h2>
                <?php
                $recent_alerts = get_posts(array(
                    'post_type' => 'wx-alert',
                    'posts_per_page' => 20,
                    'orderby' => 'date',
                    'order' => 'DESC'
                ));
                
                if ($recent_alerts) {
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr><th>Title</th><th>Sequence</th><th>Date</th><th>Parent</th></tr></thead>';
                    echo '<tbody>';
                    foreach ($recent_alerts as $alert) {
                        $sequence = get_post_meta($alert->ID, 'nws_sequence', true);
                        $parent = $alert->post_parent;
                        echo '<tr>';
                        echo '<td><a href="' . get_edit_post_link($alert->ID) . '">' . esc_html($alert->post_title) . '</a></td>';
                        echo '<td><span class="badge">' . esc_html($sequence) . '</span></td>';
                        echo '<td>' . get_the_date('F j, Y g:i A', $alert->ID) . '</td>';
                        echo '<td>';
                        if ($parent) {
                            echo '<a href="' . get_edit_post_link($parent) . '">Parent #' . $parent . '</a>';
                        } else {
                            echo '—';
                        }
                        echo '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody>';
                    echo '</table>';
                } else {
                    echo '<p>No alerts yet.</p>';
                }
                ?>
            </div>
            
            <div class="card">
                <h2>Parent-Child Relationships</h2>
                <?php
                // Get alerts with children
                $parents = get_posts(array(
                    'post_type' => 'wx-alert',
                    'posts_per_page' => 10,
                    'meta_key' => 'nws_sequence',
                    'meta_value' => '001',
                    'orderby' => 'date',
                    'order' => 'DESC'
                ));
                
                if ($parents) {
                    echo '<ul>';
                    foreach ($parents as $parent) {
                        $children = get_posts(array(
                            'post_type' => 'wx-alert',
                            'post_parent' => $parent->ID,
                            'orderby' => 'date',
                            'order' => 'ASC'
                        ));
                        
                        if ($children) {
                            echo '<li>';
                            echo '<strong><a href="' . get_edit_post_link($parent->ID) . '">' . esc_html($parent->post_title) . '</a></strong>';
                            echo ' <span class="badge">001</span>';
                            echo '<ul style="margin-top: 5px;">';
                            foreach ($children as $child) {
                                $seq = get_post_meta($child->ID, 'nws_sequence', true);
                                echo '<li>';
                                echo '<a href="' . get_edit_post_link($child->ID) . '">' . esc_html($child->post_title) . '</a>';
                                echo ' <span class="badge">' . esc_html($seq) . '</span>';
                                echo '</li>';
                            }
                            echo '</ul>';
                            echo '</li>';
                        }
                    }
                    echo '</ul>';
                } else {
                    echo '<p>No parent alerts with children yet.</p>';
                }
                ?>
            </div>
            
            <div class="card">
                <h2>Log</h2>
                <p>
                    <?php
                    $upload_dir = wp_upload_dir();
                    $log_file = $upload_dir['basedir'] . '/nws-alerts-log.txt';
                    if (file_exists($log_file)) {
                        echo '<a href="' . $upload_dir['baseurl'] . '/nws-alerts-log.txt" target="_blank" class="button">View Log File</a> ';
                        echo '<a href="' . admin_url('admin-post.php?action=nws_clear_log') . '" class="button">Clear Log</a>';
                    } else {
                        echo 'No log file yet.';
                    }
                    ?>
                </p>
            </div>
        </div>
        
        <style>
            .badge {
                display: inline-block;
                padding: 2px 8px;
                font-size: 11px;
                font-weight: bold;
                line-height: 1;
                color: #fff;
                background-color: #2271b1;
                border-radius: 3px;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#nws-poll-now').on('click', function() {
                var $btn = $(this);
                var $status = $('#nws-poll-status');
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: #999;">Polling...</span>');
                
                $.post(ajaxurl, {
                    action: 'nws_poll_now'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        var msg = '✓ Poll complete! Uploaded: ' + data.results.uploaded + 
                                  ', Skipped: ' + data.results.skipped + 
                                  ', Dismissed: ' + data.results.dismissed;
                        
                        if (data.results.errors.length > 0) {
                            msg += ', Errors: ' + data.results.errors.length;
                        }
                        
                        $status.html('<span style="color: green;">' + msg + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $status.html('<span style="color: red;">✗ Error: ' + response.data + '</span>');
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX poll now
     */
    public function ajax_poll_now() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $result = $this->poll_nws_alerts();
        wp_send_json_success($result);
    }
}

// Handle log clearing
add_action('admin_post_nws_clear_log', 'nws_handle_clear_log');
function nws_handle_clear_log() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/nws-alerts-log.txt';
    
    if (file_exists($log_file)) {
        unlink($log_file);
    }
    
    wp_redirect(admin_url('edit.php?post_type=wx-alert&page=nws-poll-status&log=cleared'));
    exit;
}

// Initialize the plugin
new NWS_Alerts_Importer();