<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/config.php';

echo json_encode([
  'success' => true,
  'config_check' => [
    'GOOGLE_CLIENT_ID_defined' => defined('GOOGLE_CLIENT_ID'),
    'GOOGLE_CLIENT_ID_length' => defined('GOOGLE_CLIENT_ID') ? strlen(GOOGLE_CLIENT_ID) : 0,
    'GOOGLE_CLIENT_ID_starts_with' => defined('GOOGLE_CLIENT_ID') ? substr(GOOGLE_CLIENT_ID, 0, 20) . '...' : 'not defined',
    'env_file_exists' => file_exists(__DIR__ . '/.env'),
  ]
], JSON_PRETTY_PRINT);
