<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Screenshot_Analytics_REST_Controller extends WP_REST_Controller {
    
    protected $namespace = 'wp-screenshot-analytics/v1';
    protected $analytics_tracker;
    protected $screenshot_manager;
    
    public function __construct() {
        $this->analytics_tracker = new WP_Screenshot_Analytics_Tracker();
        $this->screenshot_manager = new WP_Screenshot_Analytics_Screenshot_Manager();
    }
    
    public function register_routes() {
        register_rest_route($this->namespace, '/top-pages', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_top_pages'),
            'permission_callback' => array($this, 'get_top_pages_permissions_check'),
            'args' => $this->get_top_pages_args()
        ));
        
        register_rest_route($this->namespace, '/screenshot', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_screenshot'),
            'permission_callback' => array($this, 'create_screenshot_permissions_check'),
            'args' => $this->get_create_screenshot_args()
        ));
        
        register_rest_route($this->namespace, '/screenshot-top-pages', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'screenshot_top_pages'),
            'permission_callback' => array($this, 'screenshot_top_pages_permissions_check'),
            'args' => $this->get_screenshot_top_pages_args()
        ));
        
        register_rest_route($this->namespace, '/screenshot-status/(?P<job_id>[a-zA-Z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_screenshot_status'),
            'permission_callback' => array($this, 'get_screenshot_status_permissions_check'),
            'args' => array(
                'job_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => array($this, 'validate_job_id')
                )
            )
        ));
        
        register_rest_route($this->namespace, '/analytics/stats', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_analytics_stats'),
            'permission_callback' => array($this, 'get_analytics_stats_permissions_check')
        ));
        
        register_rest_route($this->namespace, '/capabilities', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_capabilities'),
            'permission_callback' => array($this, 'get_capabilities_permissions_check')
        ));
    }
    
    public function get_top_pages($request) {
        try {
            $limit = $request->get_param('limit') ?: 10;
            $days = $request->get_param('days');
            
            $top_pages = $this->analytics_tracker->get_top_pages($limit, $days);
            
            $formatted_pages = array();
            foreach ($top_pages as $page) {
                $formatted_pages[] = array(
                    'url' => $page['page_url'],
                    'views' => intval($page['view_count']),
                    'page_type' => $page['page_type'],
                    'last_updated' => $page['last_updated']
                );
            }
            
            return new WP_REST_Response(array(
                'success' => true,
                'data' => $formatted_pages,
                'pagination' => array(
                    'limit' => intval($limit),
                    'total_returned' => count($formatted_pages),
                    'days_filter' => $days ? intval($days) : null
                )
            ), 200);
            
        } catch (Exception $e) {
            WP_Screenshot_Analytics::log_error('REST API get_top_pages error', array(
                'error' => $e->getMessage()
            ));
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Failed to retrieve top pages'
            ), 500);
        }
    }
    
    public function create_screenshot($request) {
        try {
            $url = $request->get_param('url');
            $viewport = $request->get_param('viewport') ?: 'desktop';
            
            if (!$this->is_valid_url($url)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'Invalid URL provided'
                ), 400);
            }
            
            $capabilities = $this->screenshot_manager->get_capabilities();
            if (!$capabilities['screenshot_capable']) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'Screenshot functionality is not available - missing required tools'
                ), 503);
            }
            
            $job_id = 'single_' . uniqid();
            $queue_id = $this->screenshot_manager->queue_screenshot($url, $viewport, $job_id);
            
            if (!$queue_id) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'Failed to queue screenshot - queue may be full'
                ), 503);
            }
            
            wp_schedule_single_event(time() + 5, 'wp_screenshot_analytics_process_queue');
            
            return new WP_REST_Response(array(
                'success' => true,
                'job_id' => $job_id,
                'status_url' => rest_url($this->namespace . '/screenshot-status/' . $job_id),
                'message' => 'Screenshot queued successfully'
            ), 202);
            
        } catch (Exception $e) {
            WP_Screenshot_Analytics::log_error('REST API create_screenshot error', array(
                'url' => $url,
                'error' => $e->getMessage()
            ));
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Internal server error'
            ), 500);
        }
    }
    
    public function screenshot_top_pages($request) {
        try {
            $limit = $request->get_param('limit') ?: 10;
            $viewport = $request->get_param('viewport') ?: 'desktop';
            $days = $request->get_param('days');
            
            $top_pages = $this->analytics_tracker->get_top_pages($limit, $days);
            
            if (empty($top_pages)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'No pages found to screenshot'
                ), 404);
            }
            
            $capabilities = $this->screenshot_manager->get_capabilities();
            if (!$capabilities['screenshot_capable']) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'Screenshot functionality is not available - missing required tools'
                ), 503);
            }
            
            $urls = array_column($top_pages, 'page_url');
            $job_id = 'batch_' . uniqid();
            
            $result = $this->screenshot_manager->queue_multiple_screenshots($urls, $viewport, $job_id);
            
            if ($result['queued_count'] === 0) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'Failed to queue any screenshots - queue may be full'
                ), 503);
            }
            
            wp_schedule_single_event(time() + 10, 'wp_screenshot_analytics_process_queue');
            
            return new WP_REST_Response(array(
                'success' => true,
                'job_id' => $result['job_id'],
                'pages_queued' => $result['queued_count'],
                'total_pages' => $result['total_pages'],
                'status_url' => rest_url($this->namespace . '/screenshot-status/' . $result['job_id']),
                'errors' => $result['errors']
            ), 202);
            
        } catch (Exception $e) {
            WP_Screenshot_Analytics::log_error('REST API screenshot_top_pages error', array(
                'error' => $e->getMessage()
            ));
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Internal server error'
            ), 500);
        }
    }
    
    public function get_screenshot_status($request) {
        try {
            $job_id = $request->get_param('job_id');
            
            $status_info = $this->screenshot_manager->get_screenshot_info($job_id);
            
            if (!$status_info) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'Job not found'
                ), 404);
            }
            
            $response_data = array(
                'success' => true,
                'job_id' => $status_info['job_id'],
                'status' => $status_info['status'],
                'completed' => $status_info['completed'],
                'total' => $status_info['total'],
                'failed' => $status_info['failed'],
                'processing' => $status_info['processing'],
                'queued' => $status_info['queued'],
                'screenshots' => array()
            );
            
            foreach ($status_info['screenshots'] as $screenshot) {
                $screenshot_data = array(
                    'url' => $screenshot['url'],
                    'status' => $screenshot['status']
                );
                
                if ($screenshot['screenshot_url']) {
                    $screenshot_data['screenshot_url'] = $screenshot['screenshot_url'];
                }
                
                if ($screenshot['error_message']) {
                    $screenshot_data['error'] = $screenshot['error_message'];
                }
                
                $response_data['screenshots'][] = $screenshot_data;
            }
            
            return new WP_REST_Response($response_data, 200);
            
        } catch (Exception $e) {
            WP_Screenshot_Analytics::log_error('REST API get_screenshot_status error', array(
                'job_id' => $job_id,
                'error' => $e->getMessage()
            ));
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Internal server error'
            ), 500);
        }
    }
    
    public function get_analytics_stats($request) {
        try {
            $stats = $this->analytics_tracker->get_analytics_stats();
            
            return new WP_REST_Response(array(
                'success' => true,
                'data' => $stats
            ), 200);
            
        } catch (Exception $e) {
            WP_Screenshot_Analytics::log_error('REST API get_analytics_stats error', array(
                'error' => $e->getMessage()
            ));
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Failed to retrieve analytics stats'
            ), 500);
        }
    }
    
    public function get_capabilities($request) {
        try {
            $capabilities = $this->screenshot_manager->get_capabilities();
            
            return new WP_REST_Response(array(
                'success' => true,
                'data' => $capabilities
            ), 200);
            
        } catch (Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Failed to retrieve capabilities'
            ), 500);
        }
    }
    
    public function get_top_pages_permissions_check($request) {
        return $this->check_api_permissions('read_analytics');
    }
    
    public function create_screenshot_permissions_check($request) {
        return $this->check_api_permissions('create_screenshots');
    }
    
    public function screenshot_top_pages_permissions_check($request) {
        return $this->check_api_permissions('create_screenshots');
    }
    
    public function get_screenshot_status_permissions_check($request) {
        return $this->check_api_permissions('read_screenshots');
    }
    
    public function get_analytics_stats_permissions_check($request) {
        return $this->check_api_permissions('read_analytics');
    }
    
    public function get_capabilities_permissions_check($request) {
        return $this->check_api_permissions('read_capabilities');
    }
    
    private function check_api_permissions($context) {
        $api_access = get_option('wp_screenshot_analytics_api_access', 'manage_options');
        
        if ($api_access === 'public') {
            return true;
        }
        
        if ($api_access === 'logged_in') {
            return is_user_logged_in();
        }
        
        return current_user_can($api_access);
    }
    
    private function get_top_pages_args() {
        return array(
            'limit' => array(
                'required' => false,
                'type' => 'integer',
                'default' => 10,
                'minimum' => 1,
                'maximum' => 100,
                'sanitize_callback' => 'absint'
            ),
            'days' => array(
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 365,
                'sanitize_callback' => 'absint'
            )
        );
    }
    
    private function get_create_screenshot_args() {
        return array(
            'url' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'validate_callback' => array($this, 'validate_url')
            ),
            'viewport' => array(
                'required' => false,
                'type' => 'string',
                'default' => 'desktop',
                'enum' => array('desktop', 'tablet', 'mobile'),
                'sanitize_callback' => 'sanitize_text_field'
            )
        );
    }
    
    private function get_screenshot_top_pages_args() {
        return array(
            'limit' => array(
                'required' => false,
                'type' => 'integer',
                'default' => 10,
                'minimum' => 1,
                'maximum' => 50,
                'sanitize_callback' => 'absint'
            ),
            'viewport' => array(
                'required' => false,
                'type' => 'string',
                'default' => 'desktop',
                'enum' => array('desktop', 'tablet', 'mobile'),
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'days' => array(
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 365,
                'sanitize_callback' => 'absint'
            )
        );
    }
    
    public function validate_url($value, $request, $param) {
        if (empty($value)) {
            return new WP_Error('invalid_url', 'URL cannot be empty');
        }
        
        if (!$this->is_valid_url($value)) {
            return new WP_Error('invalid_url', 'Invalid URL format');
        }
        
        return true;
    }
    
    public function validate_job_id($value, $request, $param) {
        if (empty($value)) {
            return new WP_Error('invalid_job_id', 'Job ID cannot be empty');
        }
        
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            return new WP_Error('invalid_job_id', 'Job ID contains invalid characters');
        }
        
        return true;
    }
    
    private function is_valid_url($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], array('http', 'https'))) {
            return false;
        }
        
        $blocked_domains = get_option('wp_screenshot_analytics_blocked_domains', array());
        if (!empty($blocked_domains) && isset($parsed_url['host'])) {
            foreach ($blocked_domains as $blocked_domain) {
                if (strpos($parsed_url['host'], $blocked_domain) !== false) {
                    return false;
                }
            }
        }
        
        return true;
    }
}