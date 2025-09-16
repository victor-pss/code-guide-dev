<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Screenshot_Analytics_Screenshot_Manager {
    
    private $db_manager;
    private $screenshots_dir;
    private $screenshots_url;
    private $viewport_dimensions;
    
    public function __construct() {
        $this->db_manager = new WP_Screenshot_Analytics_Database_Manager();
        $this->init_directories();
        $this->init_viewport_dimensions();
        $this->init_hooks();
    }
    
    private function init_directories() {
        $upload_dir = wp_upload_dir();
        $this->screenshots_dir = $upload_dir['basedir'] . '/screenshots';
        $this->screenshots_url = $upload_dir['baseurl'] . '/screenshots';
        
        if (!file_exists($this->screenshots_dir)) {
            wp_mkdir_p($this->screenshots_dir);
        }
    }
    
    private function init_viewport_dimensions() {
        $this->viewport_dimensions = array(
            'desktop' => array(
                'width' => 1920,
                'height' => 1080,
                'mobile' => false
            ),
            'tablet' => array(
                'width' => 768,
                'height' => 1024,
                'mobile' => false
            ),
            'mobile' => array(
                'width' => 375,
                'height' => 667,
                'mobile' => true
            )
        );
        
        $this->viewport_dimensions = apply_filters('wp_screenshot_analytics_viewport_dimensions', $this->viewport_dimensions);
    }
    
    private function init_hooks() {
        add_action('wp_screenshot_analytics_process_queue', array($this, 'process_queue'));
        add_action('wp_screenshot_analytics_cleanup', array($this, 'cleanup_old_screenshots'));
    }
    
    public function queue_screenshot($page_url, $viewport = 'desktop', $job_id = null) {
        if (empty($job_id)) {
            $job_id = 'screenshot_' . uniqid();
        }
        
        $page_url = esc_url_raw($page_url);
        $viewport = sanitize_text_field($viewport);
        
        if (empty($page_url) || !$this->is_valid_viewport($viewport)) {
            return false;
        }
        
        $queue_size = $this->get_queue_size();
        $max_queue_size = get_option('wp_screenshot_analytics_max_queue_size', WP_SCREENSHOT_ANALYTICS_MAX_QUEUE_SIZE);
        
        if ($queue_size >= $max_queue_size) {
            WP_Screenshot_Analytics::log_error('Screenshot queue is full', array(
                'current_size' => $queue_size,
                'max_size' => $max_queue_size
            ));
            return false;
        }
        
        $queue_id = $this->db_manager->queue_screenshot($job_id, $page_url, $viewport);
        
        if ($queue_id) {
            do_action('wp_screenshot_analytics_screenshot_queued', $job_id, $page_url, $viewport);
            
            if (!wp_next_scheduled('wp_screenshot_analytics_process_queue')) {
                wp_schedule_single_event(time() + 10, 'wp_screenshot_analytics_process_queue');
            }
        }
        
        return $queue_id;
    }
    
    public function queue_multiple_screenshots($pages, $viewport = 'desktop', $job_id = null) {
        if (empty($job_id)) {
            $job_id = 'batch_' . uniqid();
        }
        
        $queued_count = 0;
        $errors = array();
        
        foreach ($pages as $page_url) {
            $result = $this->queue_screenshot($page_url, $viewport, $job_id);
            if ($result) {
                $queued_count++;
            } else {
                $errors[] = $page_url;
            }
        }
        
        return array(
            'job_id' => $job_id,
            'queued_count' => $queued_count,
            'total_pages' => count($pages),
            'errors' => $errors
        );
    }
    
    public function process_queue($limit = 5) {
        $queue_items = $this->db_manager->get_queue_items(null, 'queued', $limit);
        
        if (empty($queue_items)) {
            return array('processed' => 0, 'errors' => array());
        }
        
        $processed = 0;
        $errors = array();
        
        foreach ($queue_items as $item) {
            $this->db_manager->update_queue_item($item['id'], array(
                'status' => 'processing'
            ));
            
            $result = $this->capture_screenshot(
                $item['page_url'],
                $item['viewport'],
                $item['job_id']
            );
            
            if ($result['success']) {
                $this->db_manager->update_queue_item($item['id'], array(
                    'status' => 'completed',
                    'screenshot_path' => $result['file_path']
                ));
                $processed++;
            } else {
                $this->db_manager->update_queue_item($item['id'], array(
                    'status' => 'failed',
                    'error_message' => $result['error']
                ));
                $errors[] = array(
                    'url' => $item['page_url'],
                    'error' => $result['error']
                );
            }
            
            usleep(500000);
        }
        
        return array(
            'processed' => $processed,
            'errors' => $errors
        );
    }
    
    private function capture_screenshot($page_url, $viewport = 'desktop', $job_id = null) {
        try {
            if (!$this->is_screenshot_capable()) {
                return array(
                    'success' => false,
                    'error' => 'Screenshot capture not available - missing required tools'
                );
            }
            
            $filename = $this->generate_filename($page_url, $viewport, $job_id);
            $file_path = $this->get_dated_directory() . '/' . $filename;
            $full_path = $this->screenshots_dir . '/' . $file_path;
            
            wp_mkdir_p(dirname($full_path));
            
            $dimensions = $this->viewport_dimensions[$viewport];
            $quality = get_option('wp_screenshot_analytics_screenshot_quality', WP_SCREENSHOT_ANALYTICS_SCREENSHOT_QUALITY);
            
            $result = $this->execute_screenshot_command($page_url, $full_path, $dimensions, $quality);
            
            if ($result && file_exists($full_path) && filesize($full_path) > 0) {
                return array(
                    'success' => true,
                    'file_path' => $file_path,
                    'url' => $this->screenshots_url . '/' . $file_path
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Screenshot capture failed - no output generated'
                );
            }
            
        } catch (Exception $e) {
            WP_Screenshot_Analytics::log_error('Screenshot capture exception', array(
                'url' => $page_url,
                'error' => $e->getMessage()
            ));
            
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    private function execute_screenshot_command($page_url, $output_path, $dimensions, $quality) {
        $commands = array(
            'wkhtmltoimage' => $this->build_wkhtmltoimage_command($page_url, $output_path, $dimensions, $quality),
            'chrome' => $this->build_chrome_command($page_url, $output_path, $dimensions, $quality),
            'firefox' => $this->build_firefox_command($page_url, $output_path, $dimensions, $quality)
        );
        
        foreach ($commands as $tool => $command) {
            if ($this->is_command_available($tool)) {
                $output = array();
                $return_code = 0;
                exec($command . ' 2>&1', $output, $return_code);
                
                if ($return_code === 0 && file_exists($output_path)) {
                    WP_Screenshot_Analytics::log_error('Screenshot captured successfully', array(
                        'tool' => $tool,
                        'url' => $page_url,
                        'path' => $output_path
                    ));
                    return true;
                } else {
                    WP_Screenshot_Analytics::log_error('Screenshot command failed', array(
                        'tool' => $tool,
                        'command' => $command,
                        'output' => implode("\n", $output),
                        'return_code' => $return_code
                    ));
                }
            }
        }
        
        return false;
    }
    
    private function build_wkhtmltoimage_command($page_url, $output_path, $dimensions, $quality) {
        $command_parts = array(
            'wkhtmltoimage',
            '--quality ' . intval($quality),
            '--width ' . intval($dimensions['width']),
            '--height ' . intval($dimensions['height']),
            '--javascript-delay 2000',
            '--no-stop-slow-scripts',
            '--disable-smart-width',
            '--format png',
            escapeshellarg($page_url),
            escapeshellarg($output_path)
        );
        
        return implode(' ', $command_parts);
    }
    
    private function build_chrome_command($page_url, $output_path, $dimensions, $quality) {
        $command_parts = array(
            'google-chrome',
            '--headless',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--disable-background-timer-throttling',
            '--disable-backgrounding-occluded-windows',
            '--disable-renderer-backgrounding',
            '--window-size=' . intval($dimensions['width']) . ',' . intval($dimensions['height']),
            '--screenshot=' . escapeshellarg($output_path),
            escapeshellarg($page_url)
        );
        
        return implode(' ', $command_parts);
    }
    
    private function build_firefox_command($page_url, $output_path, $dimensions, $quality) {
        $command_parts = array(
            'firefox',
            '--headless',
            '--screenshot=' . escapeshellarg($output_path),
            '--window-size=' . intval($dimensions['width']) . ',' . intval($dimensions['height']),
            escapeshellarg($page_url)
        );
        
        return implode(' ', $command_parts);
    }
    
    private function is_command_available($command) {
        $commands = array(
            'wkhtmltoimage' => 'which wkhtmltoimage',
            'chrome' => 'which google-chrome || which chromium-browser || which chrome',
            'firefox' => 'which firefox'
        );
        
        if (!isset($commands[$command])) {
            return false;
        }
        
        exec($commands[$command] . ' 2>/dev/null', $output, $return_code);
        return $return_code === 0;
    }
    
    private function is_screenshot_capable() {
        return $this->is_command_available('wkhtmltoimage') ||
               $this->is_command_available('chrome') ||
               $this->is_command_available('firefox');
    }
    
    private function is_valid_viewport($viewport) {
        return isset($this->viewport_dimensions[$viewport]);
    }
    
    private function generate_filename($page_url, $viewport, $job_id) {
        $url_hash = md5($page_url);
        $timestamp = time();
        $job_suffix = $job_id ? '_' . sanitize_file_name($job_id) : '';
        
        return $viewport . '_' . $url_hash . '_' . $timestamp . $job_suffix . '.png';
    }
    
    private function get_dated_directory() {
        return date('Y/m');
    }
    
    public function get_queue_status($job_id) {
        return $this->db_manager->get_queue_status($job_id);
    }
    
    public function get_queue_size() {
        $queue_items = $this->db_manager->get_queue_items(null, 'queued', 1000);
        return count($queue_items);
    }
    
    public function cleanup_old_screenshots($days = null) {
        if (is_null($days)) {
            $days = get_option('wp_screenshot_analytics_cleanup_days', WP_SCREENSHOT_ANALYTICS_CLEANUP_DAYS);
        }
        
        $days = intval($days);
        if ($days <= 0) {
            return 0;
        }
        
        $cutoff_date = date('Y-m-d', strtotime("-{$days} days"));
        $deleted_count = 0;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->screenshots_dir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'png') {
                $file_date = date('Y-m-d', $file->getMTime());
                
                if ($file_date < $cutoff_date) {
                    if (unlink($file->getPathname())) {
                        $deleted_count++;
                    }
                }
            }
        }
        
        $this->cleanup_empty_directories();
        
        $this->db_manager->cleanup_old_queue_items($days);
        
        return $deleted_count;
    }
    
    private function cleanup_empty_directories() {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->screenshots_dir),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $dir) {
            if ($dir->isDir() && !$dir->isDot()) {
                $files = new FilesystemIterator($dir->getPathname());
                if (!$files->valid()) {
                    rmdir($dir->getPathname());
                }
            }
        }
    }
    
    public function get_screenshot_info($job_id) {
        $status = $this->get_queue_status($job_id);
        
        if (!$status) {
            return null;
        }
        
        $info = array(
            'job_id' => $job_id,
            'status' => 'unknown',
            'total' => $status['total'],
            'completed' => $status['completed'],
            'failed' => $status['failed'],
            'processing' => $status['processing'],
            'queued' => $status['queued'],
            'screenshots' => array()
        );
        
        if ($status['total'] === 0) {
            $info['status'] = 'not_found';
        } elseif ($status['completed'] === $status['total']) {
            $info['status'] = 'completed';
        } elseif ($status['failed'] === $status['total']) {
            $info['status'] = 'failed';
        } elseif ($status['processing'] > 0) {
            $info['status'] = 'processing';
        } else {
            $info['status'] = 'queued';
        }
        
        foreach ($status['screenshots'] as $screenshot) {
            $info['screenshots'][] = array(
                'url' => $screenshot['url'],
                'status' => $screenshot['status'],
                'screenshot_url' => $screenshot['screenshot_url'],
                'error_message' => $screenshot['error_message']
            );
        }
        
        return $info;
    }
    
    public function cancel_job($job_id) {
        global $wpdb;
        
        $job_id = sanitize_text_field($job_id);
        if (empty($job_id)) {
            return false;
        }
        
        $table_name = $wpdb->prefix . 'screenshot_queue';
        
        return $wpdb->update(
            $table_name,
            array(
                'status' => 'cancelled',
                'updated_at' => current_time('mysql')
            ),
            array('job_id' => $job_id),
            array('%s', '%s'),
            array('%s')
        );
    }
    
    public function get_capabilities() {
        return array(
            'screenshot_tools' => array(
                'wkhtmltoimage' => $this->is_command_available('wkhtmltoimage'),
                'chrome' => $this->is_command_available('chrome'),
                'firefox' => $this->is_command_available('firefox')
            ),
            'screenshot_capable' => $this->is_screenshot_capable(),
            'viewports' => array_keys($this->viewport_dimensions),
            'max_queue_size' => get_option('wp_screenshot_analytics_max_queue_size', WP_SCREENSHOT_ANALYTICS_MAX_QUEUE_SIZE),
            'current_queue_size' => $this->get_queue_size()
        );
    }
}