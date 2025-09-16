# WordPress Screenshot & Analytics Plugin

A lightweight WordPress plugin that tracks page views natively and provides screenshot capabilities via REST API endpoints. Perfect for automation workflows and analytics without external dependencies.

## Features

### 📊 Native Analytics Tracking
- Track page views without external analytics services
- Bot detection and filtering
- Configurable user exclusions (admins, authenticated users)
- Efficient database operations with caching
- Real-time analytics dashboard

### 📷 Screenshot Capabilities  
- Queue-based screenshot processing
- Multiple viewport support (desktop, tablet, mobile)
- Batch screenshot generation
- Support for multiple screenshot tools (wkhtmltoimage, Chrome, Firefox)
- Automatic cleanup of old screenshots
- Background processing via WordPress cron

### 🔌 REST API Endpoints
- Get top pages by view count
- Single page screenshot capture
- Batch screenshot generation for top pages
- Job status tracking
- Comprehensive error handling

### ⚙️ Admin Interface
- Visual dashboard with statistics
- Configuration options
- System capabilities overview
- Manual queue processing
- Cache management

## Installation

1. Upload the plugin files to `/wp-content/plugins/wp-screenshot-analytics/`
2. Activate the plugin through WordPress admin
3. Configure settings under Settings > Screenshot Analytics
4. Install screenshot tools for full functionality (optional)

## Screenshot Tool Requirements

The plugin supports multiple screenshot tools. Install at least one for screenshot functionality:

### wkhtmltoimage (Recommended)
```bash
# Ubuntu/Debian
sudo apt-get install wkhtmltopdf

# CentOS/RHEL
sudo yum install wkhtmltopdf

# macOS
brew install wkhtmltopdf
```

### Google Chrome/Chromium
```bash
# Ubuntu/Debian
sudo apt-get install google-chrome-stable
# or
sudo apt-get install chromium-browser

# CentOS/RHEL
sudo yum install google-chrome-stable
```

### Firefox
```bash
# Ubuntu/Debian
sudo apt-get install firefox

# CentOS/RHEL  
sudo yum install firefox
```

## REST API Usage

### Authentication
Configure API access in plugin settings:
- `public` - No authentication required
- `logged_in` - Requires logged-in user
- `manage_options` - Requires admin capabilities (default)

### Get Top Pages
```http
GET /wp-json/wp-screenshot-analytics/v1/top-pages
```

Parameters:
- `limit` (optional): Number of pages to return (1-100, default: 10)  
- `days` (optional): Filter pages updated within last N days (1-365)

Example response:
```json
{
  "success": true,
  "data": [
    {
      "url": "https://example.com/popular-page",
      "views": 1250,
      "page_type": "post",
      "last_updated": "2025-09-16 10:30:00"
    }
  ],
  "pagination": {
    "limit": 10,
    "total_returned": 5,
    "days_filter": null
  }
}
```

### Create Single Screenshot
```http
POST /wp-json/wp-screenshot-analytics/v1/screenshot
Content-Type: application/json

{
  "url": "https://example.com/page-to-capture",
  "viewport": "desktop"
}
```

Parameters:
- `url` (required): URL to capture
- `viewport` (optional): desktop, tablet, or mobile (default: desktop)

Example response:
```json
{
  "success": true,
  "job_id": "single_abc123",
  "status_url": "/wp-json/wp-screenshot-analytics/v1/screenshot-status/single_abc123",
  "message": "Screenshot queued successfully"
}
```

### Screenshot Top Pages
```http
POST /wp-json/wp-screenshot-analytics/v1/screenshot-top-pages
Content-Type: application/json

{
  "limit": 10,
  "viewport": "desktop",
  "days": 30
}
```

Parameters:
- `limit` (optional): Number of top pages to capture (1-50, default: 10)
- `viewport` (optional): desktop, tablet, or mobile (default: desktop)
- `days` (optional): Only capture pages with recent activity

Example response:
```json
{
  "success": true,
  "job_id": "batch_def456",
  "pages_queued": 10,
  "total_pages": 10,
  "status_url": "/wp-json/wp-screenshot-analytics/v1/screenshot-status/batch_def456",
  "errors": []
}
```

### Check Screenshot Status
```http
GET /wp-json/wp-screenshot-analytics/v1/screenshot-status/batch_def456
```

Example response:
```json
{
  "success": true,
  "job_id": "batch_def456",
  "status": "completed",
  "completed": 8,
  "total": 10,
  "failed": 2,
  "processing": 0,
  "queued": 0,
  "screenshots": [
    {
      "url": "https://example.com/page1",
      "status": "completed",
      "screenshot_url": "https://example.com/wp-content/uploads/screenshots/2025/09/desktop_hash_timestamp.png"
    }
  ]
}
```

Status values:
- `queued` - Job is waiting to be processed
- `processing` - Job is currently being processed  
- `completed` - All screenshots completed successfully
- `failed` - All screenshots failed
- `not_found` - Job ID not found

## Configuration

### Plugin Constants
You can define these constants in `wp-config.php` to override defaults:

```php
// Screenshot quality (1-100)
define('WP_SCREENSHOT_ANALYTICS_SCREENSHOT_QUALITY', 80);

// Maximum queue size
define('WP_SCREENSHOT_ANALYTICS_MAX_QUEUE_SIZE', 50);

// Days to keep screenshots
define('WP_SCREENSHOT_ANALYTICS_CLEANUP_DAYS', 30);

// Cache duration in seconds  
define('WP_SCREENSHOT_ANALYTICS_CACHE_DURATION', 3600);
```

### WordPress Filters
Customize plugin behavior with filters:

```php
// Add custom bot user agents
add_filter('wp_screenshot_analytics_bot_user_agents', function($bots) {
    $bots[] = 'CustomBot';
    return $bots;
});

// Modify viewport dimensions
add_filter('wp_screenshot_analytics_viewport_dimensions', function($viewports) {
    $viewports['custom'] = array(
        'width' => 1366,
        'height' => 768,
        'mobile' => false
    );
    return $viewports;
});

// Control tracking behavior
add_filter('wp_screenshot_analytics_should_track', function($should_track) {
    // Custom logic to determine if view should be tracked
    return $should_track;
});
```

### WordPress Actions
Hook into plugin events:

```php
// Screenshot queued
add_action('wp_screenshot_analytics_screenshot_queued', function($job_id, $url, $viewport) {
    // Custom logic when screenshot is queued
});

// Page view tracked
add_action('wp_screenshot_analytics_page_view_tracked', function($url, $page_type) {
    // Custom logic when page view is tracked
});
```

## Database Schema

### Page Analytics Table (`wp_page_analytics`)
```sql
CREATE TABLE wp_page_analytics (
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
);
```

### Screenshot Queue Table (`wp_screenshot_queue`)
```sql
CREATE TABLE wp_screenshot_queue (
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
);
```

## Troubleshooting

### Screenshots Not Working
1. Check if screenshot tools are installed and accessible
2. Verify file permissions on uploads directory
3. Check WordPress error logs for detailed error messages
4. Test API endpoint with simple URL

### High Memory Usage  
1. Reduce screenshot quality setting
2. Lower max queue size
3. Implement more frequent cleanup
4. Monitor concurrent screenshot processing

### Performance Issues
1. Enable object caching
2. Increase cache duration
3. Monitor database query performance
4. Consider bot detection sensitivity

## File Structure

```
wp-screenshot-analytics/
├── wp-screenshot-analytics.php     # Main plugin file
├── README.md                       # Documentation
├── admin/
│   └── admin-page.php             # Admin interface
└── includes/
    ├── class-database-manager.php  # Database operations
    ├── class-analytics-tracker.php # Page view tracking
    ├── class-screenshot-manager.php # Screenshot processing
    └── class-rest-controller.php   # REST API endpoints
```

## Security

- All input is sanitized and validated
- Permission checks on all API endpoints
- Nonce verification for admin actions
- SQL injection protection via prepared statements
- File upload security with htaccess protection

## Support

For support and feature requests, please visit the plugin's GitHub repository or WordPress.org support forum.

## License

This plugin is licensed under GPL v2 or later.

## Changelog

### 1.0.0
- Initial release
- Native page view tracking
- Screenshot capture functionality
- REST API endpoints
- Admin dashboard
- Queue-based processing
- Multiple screenshot tool support