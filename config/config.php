<?php
// Load .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
      list($name, $value) = explode('=', $line, 2);
      $_ENV[trim($name)] = trim($value);
      putenv(trim($name) . '=' . trim($value));
    }
  }
}

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'split_cash');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// JWT configuration
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'your-secret-key-here');
define('JWT_EXPIRES_IN', 7 * 24 * 60 * 60); // 7 days in seconds

// Google OAuth configuration
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

// Email configuration
define('EMAIL_HOST', getenv('EMAIL_HOST') ?: 'shivarya.dev');
define('EMAIL_PORT', getenv('EMAIL_PORT') ?: 465);
define('EMAIL_SECURE', getenv('EMAIL_SECURE') === 'true');
define('EMAIL_USER', getenv('EMAIL_USER') ?: 'splitcash@shivarya.dev');
define('EMAIL_PASS', getenv('EMAIL_PASS') ?: '');
define('EMAIL_FROM_NAME', 'Split Cash');
define('EMAIL_FROM_ADDRESS', getenv('EMAIL_USER') ?: 'splitcash@shivarya.dev');
define('EMAIL_FROM', getenv('EMAIL_FROM') ?: 'Split Cash <splitcash@shivarya.dev>');

// Frontend URL
define('FRONTEND_URL', getenv('FRONTEND_URL') ?: 'https://shivarya.github.io/split-cash');

// App URLs
define('WEBSITE_URL', getenv('WEBSITE_URL') ?: 'https://shivarya.github.io/split-cash');
define('PRIVACY_POLICY_URL', getenv('PRIVACY_POLICY_URL') ?: 'https://shivarya.github.io/split-cash/privacy');
define('TERMS_URL', getenv('TERMS_URL') ?: 'https://shivarya.github.io/split-cash/terms');

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
