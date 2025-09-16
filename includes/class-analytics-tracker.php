<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Screenshot_Analytics_Tracker {
    
    private $db_manager;
    private $bot_user_agents;
    
    public function __construct() {
        $this->db_manager = new WP_Screenshot_Analytics_Database_Manager();
        $this->init_bot_user_agents();
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp', array($this, 'track_page_view'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracking_script'));
    }
    
    private function init_bot_user_agents() {
        $this->bot_user_agents = array(
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'facebookexternalhit',
            'twitterbot',
            'linkedinbot',
            'whatsapp',
            'telegrambot',
            'applebot',
            'crawler',
            'spider',
            'bot',
            'pingdom',
            'uptimerobot',
            'monitor',
            'lighthouse',
            'pagespeed',
            'gtmetrix',
            'prerender'
        );
        
        $this->bot_user_agents = apply_filters('wp_screenshot_analytics_bot_user_agents', $this->bot_user_agents);
    }
    
    public function track_page_view() {
        if (!$this->should_track_view()) {
            return;
        }
        
        $page_url = $this->get_current_page_url();
        $page_type = $this->get_current_page_type();
        
        if (empty($page_url)) {
            return;
        }
        
        $this->db_manager->track_page_view($page_url, $page_type);
        
        do_action('wp_screenshot_analytics_page_view_tracked', $page_url, $page_type);
    }
    
    private function should_track_view() {
        if (is_admin()) {
            return false;
        }
        
        if (wp_doing_ajax()) {
            return false;
        }
        
        if (wp_doing_cron()) {
            return false;
        }
        
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return false;
        }
        
        if ($this->is_preview()) {
            return false;
        }
        
        if (get_option('wp_screenshot_analytics_exclude_admins', 1) && current_user_can('manage_options')) {
            return false;
        }
        
        if (get_option('wp_screenshot_analytics_bot_detection', 1) && $this->is_bot()) {
            return false;
        }
        
        if ($this->is_duplicate_request()) {
            return false;
        }
        
        return apply_filters('wp_screenshot_analytics_should_track', true);
    }
    
    private function is_preview() {
        return is_preview() || 
               (isset($_GET['preview']) && $_GET['preview'] === 'true') ||
               (isset($_GET['p']) && isset($_GET['preview']));
    }
    
    private function is_bot() {
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return true;
        }
        
        $user_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        
        foreach ($this->bot_user_agents as $bot_pattern) {
            if (strpos($user_agent, $bot_pattern) !== false) {
                return true;
            }
        }
        
        if (preg_match('/bot|crawl|spider|archiver|audit|curl|wget|fetcher|scanner/i', $user_agent)) {
            return true;
        }
        
        $suspicious_patterns = array(
            '/^[a-z0-9]{8,}$/i',
            '/python|perl|ruby|java|php/i',
            '/http|www/i'
        );
        
        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function is_duplicate_request() {
        $ip = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $page_url = $this->get_current_page_url();
        
        $request_hash = md5($ip . $user_agent . $page_url);
        $transient_key = 'wp_sa_req_' . $request_hash;
        
        if (get_transient($transient_key)) {
            return true;
        }
        
        set_transient($transient_key, true, 60);
        
        return false;
    }
    
    private function get_client_ip() {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    private function get_current_page_url() {
        if (is_admin() || wp_doing_ajax()) {
            return '';
        }
        
        $url = home_url(add_query_arg(null, null));
        
        $url = remove_query_arg(array(
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'gclid',
            'fbclid',
            'msclkid',
            '_ga',
            '_gl'
        ), $url);
        
        return $url;
    }
    
    private function get_current_page_type() {
        if (is_front_page()) {
            return 'front_page';
        } elseif (is_home()) {
            return 'home';
        } elseif (is_single()) {
            return get_post_type();
        } elseif (is_page()) {
            return 'page';
        } elseif (is_category()) {
            return 'category';
        } elseif (is_tag()) {
            return 'tag';
        } elseif (is_author()) {
            return 'author';
        } elseif (is_date()) {
            return 'date';
        } elseif (is_search()) {
            return 'search';
        } elseif (is_404()) {
            return '404';
        } elseif (is_archive()) {
            if (is_post_type_archive()) {
                return get_post_type() . '_archive';
            }
            return 'archive';
        }
        
        return 'other';
    }
    
    public function enqueue_tracking_script() {
        if (!$this->should_track_view()) {
            return;
        }
        
        wp_add_inline_script('jquery', $this->get_tracking_script());
    }
    
    private function get_tracking_script() {
        $script = "
        (function($) {
            $(document).ready(function() {
                var trackingData = {
                    action: 'wp_screenshot_analytics_track_js',
                    page_url: window.location.href,
                    page_title: document.title,
                    referrer: document.referrer,
                    nonce: '" . wp_create_nonce('wp_screenshot_analytics_track') . "'
                };
                
                setTimeout(function() {
                    $.post('" . admin_url('admin-ajax.php') . "', trackingData);
                }, 1000);
                
                $(window).on('beforeunload', function() {
                    var endTime = Date.now();
                    if (window.startTime) {
                        var timeOnPage = Math.round((endTime - window.startTime) / 1000);
                        $.post('" . admin_url('admin-ajax.php') . "', {
                            action: 'wp_screenshot_analytics_track_time',
                            page_url: window.location.href,
                            time_on_page: timeOnPage,
                            nonce: '" . wp_create_nonce('wp_screenshot_analytics_time') . "'
                        });
                    }
                });
                
                window.startTime = Date.now();
            });
        })(jQuery);
        ";
        
        return $script;
    }
    
    public function get_top_pages($limit = 10, $days_filter = null) {
        return $this->db_manager->get_top_pages($limit, $days_filter);
    }
    
    public function get_analytics_stats() {
        return $this->db_manager->get_analytics_stats();
    }
    
    public function clear_cache() {
        global $wpdb;
        
        $transients = $wpdb->get_col("
            SELECT option_name FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_wp_screenshot_analytics_%'
        ");
        
        foreach ($transients as $transient) {
            $key = str_replace('_transient_', '', $transient);
            delete_transient($key);
        }
        
        return count($transients);
    }
    
    public function export_analytics_data($format = 'json', $days = 30) {
        $data = array(
            'exported_at' => current_time('Y-m-d H:i:s'),
            'days_filter' => intval($days),
            'stats' => $this->get_analytics_stats(),
            'top_pages' => $this->get_top_pages(100, $days)
        );
        
        switch ($format) {
            case 'csv':
                return $this->export_to_csv($data);
            case 'xml':
                return $this->export_to_xml($data);
            default:
                return wp_json_encode($data, JSON_PRETTY_PRINT);
        }
    }
    
    private function export_to_csv($data) {
        $output = fopen('php://temp', 'w');
        
        fputcsv($output, array('Page URL', 'View Count', 'Page Type', 'Last Updated'));
        
        foreach ($data['top_pages'] as $page) {
            fputcsv($output, array(
                $page['page_url'],
                $page['view_count'],
                $page['page_type'],
                $page['last_updated']
            ));
        }
        
        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    private function export_to_xml($data) {
        $xml = new SimpleXMLElement('<analytics_export/>');
        $xml->addAttribute('exported_at', $data['exported_at']);
        
        $stats_xml = $xml->addChild('stats');
        foreach ($data['stats'] as $key => $value) {
            $stats_xml->addChild($key, $value);
        }
        
        $pages_xml = $xml->addChild('pages');
        foreach ($data['top_pages'] as $page) {
            $page_xml = $pages_xml->addChild('page');
            foreach ($page as $key => $value) {
                $page_xml->addChild($key, htmlspecialchars($value));
            }
        }
        
        return $xml->asXML();
    }
}