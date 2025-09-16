<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

if (isset($_GET['action'])) {
    $action = sanitize_text_field($_GET['action']);
    $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    
    if ($action === 'clear_cache' && wp_verify_nonce($nonce, 'clear_cache')) {
        $tracker = new WP_Screenshot_Analytics_Tracker();
        $cleared = $tracker->clear_cache();
        echo '<div class="notice notice-success"><p>Cache cleared! Removed ' . $cleared . ' cached entries.</p></div>';
    }
    
    if ($action === 'process_queue' && wp_verify_nonce($nonce, 'process_queue')) {
        $manager = new WP_Screenshot_Analytics_Screenshot_Manager();
        $result = $manager->process_queue(3);
        echo '<div class="notice notice-success"><p>Processed ' . $result['processed'] . ' screenshots. Errors: ' . count($result['errors']) . '</p></div>';
    }
    
    if ($action === 'cleanup_screenshots' && wp_verify_nonce($nonce, 'cleanup_screenshots')) {
        $manager = new WP_Screenshot_Analytics_Screenshot_Manager();
        $deleted = $manager->cleanup_old_screenshots();
        echo '<div class="notice notice-success"><p>Cleaned up ' . $deleted . ' old screenshot files.</p></div>';
    }
}

$tracker = new WP_Screenshot_Analytics_Tracker();
$manager = new WP_Screenshot_Analytics_Screenshot_Manager();
$stats = $tracker->get_analytics_stats();
$capabilities = $manager->get_capabilities();
$top_pages = $tracker->get_top_pages(5);

?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php settings_errors(); ?>
    
    <!-- Statistics Dashboard -->
    <div class="wp-screenshot-analytics-dashboard">
        <div class="card-container" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
            
            <!-- Analytics Stats Card -->
            <div class="card">
                <h2 class="title">📊 Analytics Overview</h2>
                <table class="widefat">
                    <tbody>
                        <tr><td><strong>Total Pages Tracked:</strong></td><td><?php echo number_format($stats['total_pages']); ?></td></tr>
                        <tr><td><strong>Total Page Views:</strong></td><td><?php echo number_format($stats['total_views']); ?></td></tr>
                        <tr><td><strong>Today's Views:</strong></td><td><?php echo number_format($stats['today_views']); ?></td></tr>
                        <tr><td><strong>This Week's Views:</strong></td><td><?php echo number_format($stats['this_week_views']); ?></td></tr>
                    </tbody>
                </table>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=wp-screenshot-analytics&action=clear_cache'), 'clear_cache'); ?>" class="button">Clear Cache</a>
                </p>
            </div>
            
            <!-- Screenshot Queue Stats Card -->
            <div class="card">
                <h2 class="title">📷 Screenshot Queue</h2>
                <table class="widefat">
                    <tbody>
                        <tr><td><strong>Pending:</strong></td><td><?php echo number_format($stats['queue_pending']); ?></td></tr>
                        <tr><td><strong>Processing:</strong></td><td><?php echo number_format($stats['queue_processing']); ?></td></tr>
                        <tr><td><strong>Max Queue Size:</strong></td><td><?php echo $capabilities['max_queue_size']; ?></td></tr>
                        <tr><td><strong>Screenshot Capable:</strong></td><td><?php echo $capabilities['screenshot_capable'] ? '✅ Yes' : '❌ No'; ?></td></tr>
                    </tbody>
                </table>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=wp-screenshot-analytics&action=process_queue'), 'process_queue'); ?>" class="button">Process Queue</a>
                    <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=wp-screenshot-analytics&action=cleanup_screenshots'), 'cleanup_screenshots'); ?>" class="button">Cleanup Old</a>
                </p>
            </div>
            
            <!-- System Capabilities Card -->
            <div class="card">
                <h2 class="title">⚙️ System Capabilities</h2>
                <table class="widefat">
                    <tbody>
                        <?php foreach ($capabilities['screenshot_tools'] as $tool => $available): ?>
                        <tr><td><strong><?php echo ucfirst($tool); ?>:</strong></td><td><?php echo $available ? '✅ Available' : '❌ Missing'; ?></td></tr>
                        <?php endforeach; ?>
                        <tr><td><strong>Viewports:</strong></td><td><?php echo implode(', ', $capabilities['viewports']); ?></td></tr>
                    </tbody>
                </table>
            </div>
            
        </div>
    </div>
    
    <!-- Top Pages Preview -->
    <?php if (!empty($top_pages)): ?>
    <div class="card">
        <h2>🏆 Top 5 Pages</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Page URL</th>
                    <th>Views</th>
                    <th>Type</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_pages as $page): ?>
                <tr>
                    <td><a href="<?php echo esc_url($page['page_url']); ?>" target="_blank"><?php echo esc_html($page['page_url']); ?></a></td>
                    <td><?php echo number_format($page['view_count']); ?></td>
                    <td><?php echo esc_html($page['page_type']); ?></td>
                    <td><?php echo esc_html($page['last_updated']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- API Information -->
    <div class="card">
        <h2>🔌 REST API Endpoints</h2>
        <p>Your plugin provides the following REST API endpoints for automation:</p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Endpoint</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo rest_url('wp-screenshot-analytics/v1/top-pages'); ?></code></td>
                    <td>Get top pages by view count</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code><?php echo rest_url('wp-screenshot-analytics/v1/screenshot'); ?></code></td>
                    <td>Queue screenshot for a single page</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code><?php echo rest_url('wp-screenshot-analytics/v1/screenshot-top-pages'); ?></code></td>
                    <td>Queue screenshots for top pages</td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo rest_url('wp-screenshot-analytics/v1/screenshot-status/{job_id}'); ?></code></td>
                    <td>Check screenshot job status</td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <!-- Settings Form -->
    <form method="post" action="options.php">
        <?php settings_fields('wp_screenshot_analytics_settings'); ?>
        <?php do_settings_sections('wp_screenshot_analytics_settings'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">Page View Tracking</th>
                <td>
                    <label>
                        <input type="checkbox" name="wp_screenshot_analytics_tracking_enabled" value="1" <?php checked(1, get_option('wp_screenshot_analytics_tracking_enabled', 1)); ?> />
                        Enable page view tracking
                    </label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Exclude Administrators</th>
                <td>
                    <label>
                        <input type="checkbox" name="wp_screenshot_analytics_exclude_admins" value="1" <?php checked(1, get_option('wp_screenshot_analytics_exclude_admins', 1)); ?> />
                        Exclude admin users from tracking
                    </label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Bot Detection</th>
                <td>
                    <label>
                        <input type="checkbox" name="wp_screenshot_analytics_bot_detection" value="1" <?php checked(1, get_option('wp_screenshot_analytics_bot_detection', 1)); ?> />
                        Enable bot detection and filtering
                    </label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Screenshot Quality</th>
                <td>
                    <input type="number" name="wp_screenshot_analytics_screenshot_quality" value="<?php echo esc_attr(get_option('wp_screenshot_analytics_screenshot_quality', WP_SCREENSHOT_ANALYTICS_SCREENSHOT_QUALITY)); ?>" min="1" max="100" />
                    <p class="description">Screenshot quality (1-100, higher is better but larger files)</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Max Queue Size</th>
                <td>
                    <input type="number" name="wp_screenshot_analytics_max_queue_size" value="<?php echo esc_attr(get_option('wp_screenshot_analytics_max_queue_size', WP_SCREENSHOT_ANALYTICS_MAX_QUEUE_SIZE)); ?>" min="1" max="200" />
                    <p class="description">Maximum number of screenshots that can be queued at once</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Cleanup Old Screenshots</th>
                <td>
                    <input type="number" name="wp_screenshot_analytics_cleanup_days" value="<?php echo esc_attr(get_option('wp_screenshot_analytics_cleanup_days', WP_SCREENSHOT_ANALYTICS_CLEANUP_DAYS)); ?>" min="1" max="365" />
                    <p class="description">Delete screenshots older than this many days</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Cache Duration</th>
                <td>
                    <input type="number" name="wp_screenshot_analytics_cache_duration" value="<?php echo esc_attr(get_option('wp_screenshot_analytics_cache_duration', WP_SCREENSHOT_ANALYTICS_CACHE_DURATION)); ?>" min="300" max="86400" />
                    <p class="description">How long to cache analytics data (in seconds)</p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
</div>

<style>
.wp-screenshot-analytics-dashboard .card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 15px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}

.wp-screenshot-analytics-dashboard .card h2 {
    margin-top: 0;
    font-size: 14px;
    font-weight: 600;
}

.wp-screenshot-analytics-dashboard .card table {
    margin: 10px 0;
}

.wp-screenshot-analytics-dashboard .card table td {
    padding: 5px 0;
    border: none;
}

.wp-screenshot-analytics-dashboard .card p {
    margin-top: 15px;
    margin-bottom: 5px;
}
</style>