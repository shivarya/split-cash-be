<?php
// Load configuration first: APP_ENV decides how much error detail responses may carry
require_once __DIR__ . '/config/config.php';

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'local' ? '1' : '0');
ini_set('log_errors', '1');

// Warnings and notices become exceptions (→ JSON 500). Deprecations are only logged:
// a library deprecation on a newer PHP must not take the API down.
set_error_handler(function ($severity, $message, $file, $line) {
  if (!(error_reporting() & $severity)) {
    return false;
  }
  if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
    error_log("Deprecated: $message in $file:$line");
    return true;
  }
  throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) {
  error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  http_response_code(500);
  header('Content-Type: application/json');
  $body = ['success' => false, 'error' => APP_ENV === 'local' ? $e->getMessage() : 'Something went wrong on our side'];
  if (APP_ENV === 'local') {
    $body['file'] = $e->getFile();
    $body['line'] = $e->getLine();
    $body['trace'] = $e->getTraceAsString();
  }
  echo json_encode($body);
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

/** "/groups" matches "/groups" and "/groups/…", but not "/groupsx". */
function routeIs(string $uri, string $prefix): bool
{
  return $uri === $prefix || strpos($uri, $prefix . '/') === 0;
}

// Email-invite landing page: opens the app (or falls back to Play / the web page)
if ($uri === '/invite') {
  $token = isset($_GET['token']) ? trim($_GET['token']) : '';
  $safe = preg_match('/^[A-Za-z0-9]+$/', $token) ? $token : '';
  $deepLink = $safe ? 'splitcash://invite/' . $safe : 'splitcash://';
  $intent = 'intent://invite/' . $safe . '#Intent;scheme=splitcash;package=dev.shivarya.splitcash;S.browser_fallback_url=' . rawurlencode(PLAY_STORE_URL) . ';end';
  $webFallback = rtrim(FRONTEND_URL, '/') . ($safe ? '/invite?token=' . urlencode($safe) : '/');

  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-cache, no-store, must-revalidate');
  header('Pragma: no-cache');
  header('Expires: 0');

  $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES); };
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>Opening Split Cash...</title>';
  echo '</head><body style="font-family: sans-serif; text-align: center; padding: 2rem;">';
  echo $safe ? '<p>Opening Split Cash to join your invite...</p>' : '<p>Opening Split Cash...</p>';
  echo '<p><a href="' . $h($intent) . '">Open in Split Cash</a> · <a href="' . $h($deepLink) . '">Already installed? Tap here</a></p>';
  echo '<p>Not installed? <a href="' . $h(PLAY_STORE_URL) . '">Get it on Google Play</a> or <a href="' . $h($webFallback) . '">open the web version</a>.</p>';
  echo '<script>setTimeout(function(){ if (/Android/i.test(navigator.userAgent)) { window.location.href = ' . json_encode($intent) . '; } else { window.location.href = ' . json_encode($deepLink) . '; } }, 50);</script>';
  echo '</body></html>';
  exit;
}

// Account-deletion instructions (the URL in Play's Data safety form)
if ($uri === '/delete-account') {
  require_once __DIR__ . '/controllers/pageController.php';
  deleteAccountPage();
}

// Route the request
try {
  if ($uri === '/' || $uri === '') {
    Response::success([
      'message' => 'Split Cash API Server',
      'version' => '2.0.0',
      'status' => 'running'
    ]);
  }

  if ($uri === '/health') {
    require_once __DIR__ . '/controllers/healthController.php';
    healthCheck();
    exit;
  }

  // Debug routes (temporary)
  if (routeIs($uri, '/debug')) {
    require_once __DIR__ . '/controllers/debugController.php';
    handleDebugRoutes($uri, $requestMethod);
    exit;
  }

  // Invitation token routes (public metadata, protected acceptance)
  if (routeIs($uri, '/invitations')) {
    require_once __DIR__ . '/controllers/groupController.php';
    handleInvitationRoutes($uri, $requestMethod);
    exit;
  }

  $routes = [
    '/auth' => ['authController.php', 'handleAuthRoutes'],
    '/groups' => ['groupController.php', 'handleGroupRoutes'],
    '/expenses' => ['expenseController.php', 'handleExpenseRoutes'],
    '/balances' => ['balanceController.php', 'handleBalanceRoutes'],
    '/friends' => ['friendController.php', 'handleFriendRoutes'],
    '/activity' => ['activityController.php', 'handleActivityRoutes'],
    '/devices' => ['deviceController.php', 'handleDeviceRoutes'],
    '/comments' => ['commentController.php', 'handleCommentRoutes'],
    '/join' => ['joinController.php', 'handleJoinRoutes'],
  ];
  foreach ($routes as $prefix => [$file, $handler]) {
    if (routeIs($uri, $prefix)) {
      require_once __DIR__ . '/controllers/' . $file;
      $handler($uri, $requestMethod);
      exit;
    }
  }

  Response::error('Route not found', 404);

} catch (Exception $e) {
  error_log('Request failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  Response::error(APP_ENV === 'local' ? $e->getMessage() : 'Something went wrong on our side', 500);
}
