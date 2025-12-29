<?php
/**
 * Index - API Entry Point
 * Simple router for API endpoints
 */

require_once __DIR__ . '/../bootstrap.php';

// Get the request URI and method
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Simple router
header('Content-Type: application/json');

// Parse the path - handle different URL patterns
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove base path from the URL
$basePath = '/public/';
$path = str_replace($basePath, '', $path);
$path = trim($path, '/');

print_r($path);die;

// If empty, treat as health check
if (empty($path)) {
    $path = 'health';
}

switch ($path) {
    case 'health':
    case '':
        echo json_encode([
            'status' => 'ok',
            'message' => 'Twilio Lead Calling System',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0'
        ]);
        break;

        
    case 'test-db':
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM tblleads");
            $result = $stmt->fetch();
            echo json_encode([
                'status' => 'ok',
                'database' => 'connected',
                'leads_count' => $result['count']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    default:
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Endpoint not found'
        ]);
}
?>
