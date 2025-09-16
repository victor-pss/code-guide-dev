<?php
/**
 * Plugin Name: WordPress Screenshot & Analytics
 * Description: A lightweight WordPress plugin that tracks page views natively and provides screenshot capabilities via REST API endpoints.
 * Version: 1.0.0
 * Author: Code Guide Dev
 * Text Domain: wp-screenshot-analytics
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_SCREENSHOT_ANALYTICS_VERSION', '1.0.0');
define('WP_SCREENSHOT_ANALYTICS_FILE', __FILE__);
define('WP_SCREENSHOT_ANALYTICS_PATH', plugin_dir_path(__FILE__));
define('WP_SCREENSHOT_ANALYTICS_URL', plugin_dir_url(__FILE__));
define('WP_SCREENSHOT_ANALYTICS_BASENAME', plugin_basename(__FILE__));

define('WP_SCREENSHOT_ANALYTICS_SCREENSHOT_QUALITY', 80);
define('WP_SCREENSHOT_ANALYTICS_MAX_QUEUE_SIZE', 50);
define('WP_SCREENSHOT_ANALYTICS_CLEANUP_DAYS', 30);
define('WP_SCREENSHOT_ANALYTICS_CACHE_DURATION', 3600);

class WP_Screenshot_Analytics {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'init_rest_api'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        
        add_action('wp_screenshot_analytics_cleanup', array($this, 'cleanup_old_screenshots'));
        add_action('wp_screenshot_analytics_process_queue', array($this, 'process_screenshot_queue'));
    }
    
    public function init() {
        $this->load_classes();
        $this->init_analytics_tracking();
    }
    
    public function init_rest_api() {
        $this->init_rest_controller();
    }
    
    private function load_classes() {
        require_once WP_SCREENSHOT_ANALYTICS_PATH . 'includes/class-database-manager.php';
        require_once WP_SCREENSHOT_ANALYTICS_PATH . 'includes/class-analytics-tracker.php';
        require_once WP_SCREENSHOT_ANALYTICS_PATH . 'includes/class-screenshot-manager.php';
        require_once WP_SCREENSHOT_ANALYTICS_PATH . 'includes/class-rest-controller.php';
    }
    
    private function init_analytics_tracking() {
        if (get_option('wp_screenshot_analytics_tracking_enabled', 1)) {
            new WP_Screenshot_Analytics_Tracker();
        }
    }
    
    private function init_rest_controller() {
        $rest_controller = new WP_Screenshot_Analytics_REST_Controller();
        $rest_controller->register_routes();
    }
    
    public function activate() {
        $this->create_database_tables();
        $this->set_default_options();
        $this->create_uploads_directory();
        $this->schedule_cron_jobs();
        
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        $this->clear_cron_jobs();
        flush_rewrite_rules();
    }
    
    private function create_database_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'page_analytics';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            page_url varchar(255) NOT NULL,
            view_count int(11) NOT NULL DEFAULT 0,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            page_type varchar(50) NOT NULL DEFAULT 'page',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY page_url_unique (page_url),
            KEY page_type_idx (page_type),
            KEY view_count_idx (view_count DESC),
            KEY last_updated_idx (last_updated DESC)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $screenshot_queue_table = $wpdb->prefix . 'screenshot_queue';
        
        $sql_queue = "CREATE TABLE $screenshot_queue_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            job_id varchar(100) NOT NULL,
            page_url varchar(255) NOT NULL,
            viewport varchar(20) DEFAULT 'desktop',
            status varchar(20) DEFAULT 'queued',
            screenshot_path varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY job_id_idx (job_id),
            KEY status_idx (status),
            KEY created_at_idx (created_at)
        ) $charset_collate;";
        
        dbDelta($sql_queue);
        
        update_option('wp_screenshot_analytics_db_version', WP_SCREENSHOT_ANALYTICS_VERSION);
    }
    
    private function set_default_options() {
        $default_options = array(
            'wp_screenshot_analytics_tracking_enabled' => 1,
            'wp_screenshot_analytics_exclude_admins' => 1,
            'wp_screenshot_analytics_bot_detection' => 1,
            'wp_screenshot_analytics_screenshot_quality' => WP_SCREENSHOT_ANALYTICS_SCREENSHOT_QUALITY,
            'wp_screenshot_analytics_max_queue_size' => WP_SCREENSHOT_ANALYTICS_MAX_QUEUE_SIZE,
            'wp_screenshot_analytics_cleanup_days' => WP_SCREENSHOT_ANALYTICS_CLEANUP_DAYS,
            'wp_screenshot_analytics_cache_duration' => WP_SCREENSHOT_ANALYTICS_CACHE_DURATION
        );
        
        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }
    
    private function create_uploads_directory() {
        $upload_dir = wp_upload_dir();
        $screenshots_dir = $upload_dir['basedir'] . '/screenshots';
        
        if (!file_exists($screenshots_dir)) {
            wp_mkdir_p($screenshots_dir);
        }
        
        $htaccess_file = $screenshots_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Options -Indexes\n");
        }
        
        $index_file = $screenshots_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden');
        }
    }
    
    private function schedule_cron_jobs() {
        if (!wp_next_scheduled('wp_screenshot_analytics_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wp_screenshot_analytics_cleanup');
        }
        
        if (!wp_next_scheduled('wp_screenshot_analytics_process_queue')) {
            wp_schedule_event(time(), 'hourly', 'wp_screenshot_analytics_process_queue');
        }
    }
    
    private function clear_cron_jobs() {
        wp_clear_scheduled_hook('wp_screenshot_analytics_cleanup');
        wp_clear_scheduled_hook('wp_screenshot_analytics_process_queue');
    }
    
    public function cleanup_old_screenshots() {
        $screenshot_manager = new WP_Screenshot_Analytics_Screenshot_Manager();
        $screenshot_manager->cleanup_old_screenshots();
    }
    
    public function process_screenshot_queue() {
        $screenshot_manager = new WP_Screenshot_Analytics_Screenshot_Manager();
        $screenshot_manager->process_queue();
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Screenshot Analytics Settings',
            'Screenshot Analytics',
            'manage_options',
            'wp-screenshot-analytics',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('wp_screenshot_analytics_settings', 'wp_screenshot_analytics_tracking_enabled');
        register_setting('wp_screenshot_analytics_settings', 'wp_screenshot_analytics_exclude_admins');
        register_setting('wp_screenshot_analytics_settings', 'wp_screenshot_analytics_bot_detection');
        register_setting('wp_screenshot_analytics_settings', 'wp_screenshot_analytics_screenshot_quality');
        register_setting('wp_screenshot_analytics_settings', 'wp_screenshot_analytics_max_queue_size');
        register_setting('wp_screenshot_analytics_settings', 'wp_screenshot_analytics_cleanup_days');
        register_setting('wp_screenshot_analytics_settings', 'wp_screenshot_analytics_cache_duration');
    }
    
    public function admin_page() {
        include WP_SCREENSHOT_ANALYTICS_PATH . 'admin/admin-page.php';
    }
    
    public static function log_error($message, $context = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Screenshot Analytics] ' . $message . ' Context: ' . print_r($context, true));
        }
    }
    
    public static function get_database_manager() {
        return new WP_Screenshot_Analytics_Database_Manager();
    }
}

function wp_screenshot_analytics() {
    return WP_Screenshot_Analytics::get_instance();
}

wp_screenshot_analytics();