<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

// Load configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/jwt.php';
require_once __DIR__ . '/utils/response.php';

// Get request URI and method
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string from URI
$uri = parse_url($requestUri, PHP_URL_PATH);

// Remove base path if API is in subdirectory
$basePath = '/api';
if (strpos($uri, $basePath) === 0) {
  $uri = substr($uri, strlen($basePath));
}

// Route the request
try {
  // Health check
  if ($uri === '/' || $uri === '') {
    Response::success([
      'message' => 'Split Cash API Server',
      'version' => '1.0.0',
      'status' => 'running'
    ]);
  }

  if ($uri === '/health') {
    require_once __DIR__ . '/controllers/healthController.php';
    healthCheck();
    exit;
  }

  // Auth routes
  if (strpos($uri, '/auth') === 0) {
    require_once __DIR__ . '/controllers/authController.php';
    handleAuthRoutes($uri, $requestMethod);
    exit;
  }

  // Groups routes
  if (strpos($uri, '/groups') === 0) {
    require_once __DIR__ . '/controllers/groupController.php';
    handleGroupRoutes($uri, $requestMethod);
    exit;
  }

  // Expenses routes
  if (strpos($uri, '/expenses') === 0) {
    require_once __DIR__ . '/controllers/expenseController.php';
    handleExpenseRoutes($uri, $requestMethod);
    exit;
  }

  // Balances routes
  if (strpos($uri, '/balances') === 0) {
    require_once __DIR__ . '/controllers/balanceController.php';
    handleBalanceRoutes($uri, $requestMethod);
    exit;
  }

  // 404 Not Found
  Response::error('Route not found', 404);

} catch (Exception $e) {
  Response::error($e->getMessage(), 500);
}
