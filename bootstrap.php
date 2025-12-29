<?php
/**
 * Bootstrap - Application initialization
 * Load this file in all entry points
 */

// Define base path
define('BASE_PATH', __DIR__);
define('APP_PATH', BASE_PATH . '/application');
define('CONFIG_PATH', BASE_PATH . '/config');
define('LOGS_PATH', BASE_PATH . '/logs');
define('VENDOR_PATH', BASE_PATH . '/vendor');

// Load Composer autoloader
require_once VENDOR_PATH . '/autoload.php';

// Load configuration
require_once CONFIG_PATH . '/config.php';

// Helper function to require application files
function require_app($path) {
    require_once APP_PATH . '/' . $path;
}

// Auto-require common dependencies
// Note: database_setup.php should be called manually when needed
// require_app('core/database_setup.php');
require_app('services/agent_availability.php');
require_app('services/call_orchestrator.php');
?>
