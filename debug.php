<?php
// Debug file - shows all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
  // Test 1: PHP working
  $tests = [
    'php_version' => phpversion(),
    'current_dir' => getcwd(),
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
    'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'not set',
  ];

  // Test 2: Files exist
  $tests['files'] = [
    'index.php' => file_exists(__DIR__ . '/index.php'),
    '.htaccess' => file_exists(__DIR__ . '/.htaccess'),
    'config_dir' => is_dir(__DIR__ . '/config'),
    'config.php' => file_exists(__DIR__ . '/config/config.php'),
    'database.php' => file_exists(__DIR__ . '/config/database.php'),
    'controllers_dir' => is_dir(__DIR__ . '/controllers'),
    '.env' => file_exists(__DIR__ . '/.env'),
  ];

  // Test 3: Try loading config
  if (file_exists(__DIR__ . '/config/config.php')) {
    try {
      require_once __DIR__ . '/config/config.php';
      $tests['config_loaded'] = true;
      $tests['db_config'] = [
        'host' => defined('DB_HOST') ? DB_HOST : 'not defined',
        'name' => defined('DB_NAME') ? DB_NAME : 'not defined',
        'user' => defined('DB_USER') ? DB_USER : 'not defined',
      ];
    } catch (Exception $e) {
      $tests['config_error'] = $e->getMessage();
    }
  }

  // Test 4: Try database connection
  if (file_exists(__DIR__ . '/config/database.php')) {
    try {
      require_once __DIR__ . '/config/database.php';
      $db = getDB();
      $tests['database_connected'] = true;
    } catch (Exception $e) {
      $tests['database_error'] = $e->getMessage();
    }
  }

  echo json_encode([
    'success' => true,
    'message' => 'Debug info',
    'tests' => $tests
  ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString()
  ], JSON_PRETTY_PRINT);
}
