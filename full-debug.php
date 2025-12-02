<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simulate what index.php receives when accessing /split_cash/health
$requestUri = $_SERVER['REQUEST_URI'];
$uri = parse_url($requestUri, PHP_URL_PATH);

$debug = [
  'step1_raw_uri' => $requestUri,
  'step2_parsed_uri' => $uri,
  'step3_get_params' => $_GET,
];

// Check if URL is passed via query parameter (from .htaccess)
if (isset($_GET['url'])) {
  $cleanUri = '/' . trim($_GET['url'], '/');
  $debug['step4_from_htaccess'] = $cleanUri;
} else {
  // Remove base path if API is in subdirectory
  $basePath = '/split_cash';
  if (strpos($uri, $basePath) === 0) {
    $cleanUri = substr($uri, strlen($basePath));
  }
  $debug['step4_manual_strip'] = $cleanUri;
}
$cleanUri = $cleanUri ?: '/';
$debug['step5_final_uri'] = $cleanUri;

// Test if it matches routes
$debug['matches'] = [
  'root' => ($cleanUri === '/' || $cleanUri === ''),
  'health' => ($cleanUri === '/health'),
  'auth' => (strpos($cleanUri, '/auth') === 0),
];

// Try to load and test the actual health controller
try {
  require_once __DIR__ . '/config/config.php';
  require_once __DIR__ . '/config/database.php';
  require_once __DIR__ . '/utils/response.php';

  if ($cleanUri === '/health') {
    $debug['health_test'] = 'Loading health controller...';
    require_once __DIR__ . '/controllers/healthController.php';

    // Capture output
    ob_start();
    healthCheck();
    $output = ob_get_clean();

    $debug['health_output'] = $output;
    $debug['health_decoded'] = json_decode($output);
  }
} catch (Exception $e) {
  $debug['error'] = $e->getMessage();
  $debug['trace'] = $e->getTraceAsString();
}

echo json_encode([
  'success' => true,
  'message' => 'Full debug of index.php routing',
  'debug' => $debug
], JSON_PRETTY_PRINT);
