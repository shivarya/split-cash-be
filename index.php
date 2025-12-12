<?php
// Enable error reporting for debugging - SHOW ALL ERRORS
error_reporting(E_ALL);
ini_set('display_errors', 1); // Display errors in output
ini_set('log_errors', 1); // Log errors

// Custom error handler to return JSON
set_error_handler(function ($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

// Custom exception handler
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'trace' => $e->getTraceAsString()
  ]);
  exit;
});

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

// Check if URL is passed via query parameter (from .htaccess)
if (isset($_GET['url'])) {
  $uri = '/' . trim($_GET['url'], '/');
} else {
  // Remove base path if API is in subdirectory
  $basePath = '/split_cash';
  if (strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
  }
}
$uri = $uri ?: '/';

// Handle invite deep link landing page to open the app (or fallback to web)
if ($uri === '/invite') {
  $token = isset($_GET['token']) ? trim($_GET['token']) : '';
  $deepLink = $token ? 'splitcash://invite/' . urlencode($token) : 'splitcash://';
  $webFallback = rtrim(FRONTEND_URL, '/') . ($token ? '/invite?token=' . urlencode($token) : '/');

  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-cache, no-store, must-revalidate');
  header('Pragma: no-cache');
  header('Expires: 0');

  // Try to open the app immediately
  header('Location: ' . $deepLink);

  echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
  echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($deepLink, ENT_QUOTES) . '">';
  echo '<title>Opening Split Cash...</title>';
  echo '</head><body style="font-family: sans-serif; text-align: center; padding: 2rem;">';
  if ($token) {
    echo '<p>Opening Split Cash to join your invite...</p>';
  } else {
    echo '<p>Opening Split Cash...</p>';
  }
  echo '<p>If nothing happens, <a href="' . htmlspecialchars($deepLink, ENT_QUOTES) . '">tap here</a>. If the app is not installed, <a href="' . htmlspecialchars($webFallback, ENT_QUOTES) . '">open the web version</a>.</p>';
  echo '<script>setTimeout(function(){ window.location.href = ' . json_encode($deepLink) . '; setTimeout(function(){ window.location.href = ' . json_encode($webFallback) . '; }, 1500); }, 50);</script>';
  echo '</body></html>';
  exit;
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
    exit;
  }

  if ($uri === '/health') {
    require_once __DIR__ . '/controllers/healthController.php';
    healthCheck();
    exit;
  }

  // Invitation token routes (public metadata, protected acceptance)
  if (strpos($uri, '/invitations') === 0) {
    require_once __DIR__ . '/controllers/groupController.php';
    handleInvitationRoutes($uri, $requestMethod);
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
