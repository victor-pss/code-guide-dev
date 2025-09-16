<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Screenshot_Analytics_Database_Manager {
    
    private $analytics_table;
    private $queue_table;
    
    public function __construct() {
        global $wpdb;
        $this->analytics_table = $wpdb->prefix . 'page_analytics';
        $this->queue_table = $wpdb->prefix . 'screenshot_queue';
    }
    
    public function track_page_view($page_url, $page_type = 'page') {
        global $wpdb;
        
        $page_url = esc_url_raw($page_url);
        $page_type = sanitize_text_field($page_type);
        
        if (empty($page_url)) {
            return false;
        }
        
        $result = $wpdb->query($wpdb->prepare("
            INSERT INTO {$this->analytics_table} (page_url, view_count, page_type, last_updated)
            VALUES (%s, 1, %s, NOW())
            ON DUPLICATE KEY UPDATE 
                view_count = view_count + 1,
                last_updated = NOW()
        ", $page_url, $page_type));
        
        if ($result === false) {
            WP_Screenshot_Analytics::log_error('Failed to track page view', array(
                'url' => $page_url,
                'type' => $page_type,
                'error' => $wpdb->last_error
            ));
            return false;
        }
        
        delete_transient('wp_screenshot_analytics_top_pages');
        
        return true;
    }
    
    public function get_top_pages($limit = 10, $days_filter = null) {
        global $wpdb;
        
        $limit = intval($limit);
        $limit = max(1, min(100, $limit));
        
        $cache_key = 'wp_screenshot_analytics_top_pages_' . $limit;
        if ($days_filter) {
            $cache_key .= '_' . intval($days_filter) . 'd';
        }
        
        $cached_data = get_transient($cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $where_clause = '';
        $prepare_args = array($limit);
        
        if ($days_filter && is_numeric($days_filter)) {
            $where_clause = 'WHERE last_updated >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            array_unshift($prepare_args, intval($days_filter));
        }
        
        $sql = "SELECT page_url, view_count, page_type, last_updated
                FROM {$this->analytics_table}
                {$where_clause}
                ORDER BY view_count DESC, last_updated DESC
                LIMIT %d";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $prepare_args), ARRAY_A);
        
        if ($wpdb->last_error) {
            WP_Screenshot_Analytics::log_error('Failed to get top pages', array(
                'error' => $wpdb->last_error,
                'sql' => $sql
            ));
            return array();
        }
        
        if (empty($results)) {
            $results = array();
        }
        
        foreach ($results as &$result) {
            $result['view_count'] = intval($result['view_count']);
        }
        
        $cache_duration = get_option('wp_screenshot_analytics_cache_duration', WP_SCREENSHOT_ANALYTICS_CACHE_DURATION);
        set_transient($cache_key, $results, $cache_duration);
        
        return $results;
    }
    
    public function queue_screenshot($job_id, $page_url, $viewport = 'desktop') {
        global $wpdb;
        
        $job_id = sanitize_text_field($job_id);
        $page_url = esc_url_raw($page_url);
        $viewport = sanitize_text_field($viewport);
        
        if (empty($job_id) || empty($page_url)) {
            return false;
        }
        
        $result = $wpdb->insert(
            $this->queue_table,
            array(
                'job_id' => $job_id,
                'page_url' => $page_url,
                'viewport' => $viewport,
                'status' => 'queued',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            WP_Screenshot_Analytics::log_error('Failed to queue screenshot', array(
                'job_id' => $job_id,
                'url' => $page_url,
                'error' => $wpdb->last_error
            ));
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    public function get_queue_items($job_id = null, $status = null, $limit = 10) {
        global $wpdb;
        
        $where_conditions = array();
        $prepare_args = array();
        
        if ($job_id) {
            $where_conditions[] = 'job_id = %s';
            $prepare_args[] = sanitize_text_field($job_id);
        }
        
        if ($status) {
            $where_conditions[] = 'status = %s';
            $prepare_args[] = sanitize_text_field($status);
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $prepare_args[] = intval($limit);
        
        $sql = "SELECT * FROM {$this->queue_table} 
                {$where_clause}
                ORDER BY created_at ASC
                LIMIT %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $prepare_args), ARRAY_A);
    }
    
    public function update_queue_item($id, $data) {
        global $wpdb;
        
        $id = intval($id);
        if ($id <= 0) {
            return false;
        }
        
        $allowed_fields = array('status', 'screenshot_path', 'error_message', 'updated_at');
        $update_data = array();
        $format = array();
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                $update_data[$field] = $value;
                $format[] = '%s';
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        $update_data['updated_at'] = current_time('mysql');
        
        return $wpdb->update(
            $this->queue_table,
            $update_data,
            array('id' => $id),
            $format,
            array('%d')
        );
    }
    
    public function get_queue_status($job_id) {
        global $wpdb;
        
        $job_id = sanitize_text_field($job_id);
        if (empty($job_id)) {
            return null;
        }
        
        $sql = "SELECT 
                    status,
                    COUNT(*) as count,
                    page_url,
                    screenshot_path,
                    error_message
                FROM {$this->queue_table}
                WHERE job_id = %s
                GROUP BY status, page_url
                ORDER BY created_at ASC";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $job_id), ARRAY_A);
        
        $status_summary = array(
            'total' => 0,
            'completed' => 0,
            'failed' => 0,
            'processing' => 0,
            'queued' => 0,
            'screenshots' => array()
        );
        
        foreach ($results as $result) {
            $status_summary['total'] += intval($result['count']);
            $status_summary[$result['status']] += intval($result['count']);
            
            $status_summary['screenshots'][] = array(
                'url' => $result['page_url'],
                'status' => $result['status'],
                'screenshot_url' => $result['screenshot_path'] ? wp_get_upload_dir()['baseurl'] . '/screenshots/' . basename($result['screenshot_path']) : null,
                'error_message' => $result['error_message']
            );
        }
        
        return $status_summary;
    }
    
    public function cleanup_old_queue_items($days = 7) {
        global $wpdb;
        
        $days = intval($days);
        if ($days <= 0) {
            $days = 7;
        }
        
        return $wpdb->query($wpdb->prepare("
            DELETE FROM {$this->queue_table}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
            AND status IN ('completed', 'failed')
        ", $days));
    }
    
    public function get_analytics_stats() {
        global $wpdb;
        
        $cache_key = 'wp_screenshot_analytics_stats';
        $cached_stats = get_transient($cache_key);
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        $stats = array();
        
        $stats['total_pages'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->analytics_table}");
        $stats['total_views'] = $wpdb->get_var("SELECT SUM(view_count) FROM {$this->analytics_table}");
        
        $stats['today_views'] = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(view_count) FROM {$this->analytics_table}
            WHERE DATE(last_updated) = %s
        ", current_time('Y-m-d')));
        
        $stats['this_week_views'] = $wpdb->get_var("
            SELECT SUM(view_count) FROM {$this->analytics_table}
            WHERE last_updated >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        $stats['queue_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->queue_table} WHERE status = 'queued'");
        $stats['queue_processing'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->queue_table} WHERE status = 'processing'");
        
        $stats = array_map('intval', $stats);
        
        set_transient($cache_key, $stats, 300);
        
        return $stats;
    }
}