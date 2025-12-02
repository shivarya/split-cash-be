<?php
// Simple test file to verify PHP is working
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
  'success' => true,
  'message' => 'PHP is working!',
  'test' => 'If you see this, upload the rest of the files',
  'php_version' => phpversion(),
  'current_dir' => getcwd(),
  'files_exist' => [
    'index.php' => file_exists('index.php'),
    'htaccess' => file_exists('.htaccess'),
    'config_dir' => is_dir('config'),
    'controllers_dir' => is_dir('controllers'),
  ]
]);
