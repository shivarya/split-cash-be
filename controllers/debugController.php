<?php

function handleDebugRoutes($uri, $method)
{
  if ($uri === '/debug' && $method === 'GET') {
    debugIndex();
  } elseif ($uri === '/debug/google-verify' && $method === 'POST') {
    debugGoogleVerify();
  } elseif ($uri === '/debug/status' && $method === 'GET') {
    debugStatusPage();
  } else {
    Response::error('Debug route not found', 404);
  }
}

function debugStatusPage()
{
  // Simple HTML status page for browser access (temporary - remove after debugging)
  debugLog('DEBUG STATUS accessed from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

  // Basic checks
  $php = PHP_VERSION;
  $time = date('c');

  $dbStatus = 'unknown';
  try {
    $db = getDB();
    $db->query('SELECT 1');
    $dbStatus = 'connected';
  } catch (Exception $e) {
    $dbStatus = 'error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
  }

  // Read last lines of php_errors.log and debug log
  $errorLogPath = __DIR__ . '/../php_errors.log';
  $debugLogPath = __DIR__ . '/../debug/debug.log';

  $tail = function ($file, $lines = 30) {
    if (!file_exists($file))
      return 'File not found: ' . htmlspecialchars($file, ENT_QUOTES);
    $f = fopen($file, 'r');
    if (!$f)
      return 'Cannot open file';
    $buffer = '';
    $chunkSize = 4096;
    $pos = -1;
    $lineCount = 0;
    $data = '';
    fseek($f, 0, SEEK_END);
    $filesize = ftell($f);
    while ($filesize + $pos > 0 && $lineCount <= $lines) {
      $seek = max($filesize + $pos - $chunkSize, 0);
      $len = $filesize + $pos - $seek;
      fseek($f, $seek);
      $chunk = fread($f, $len);
      $buffer = $chunk . $buffer;
      $lineCount = substr_count($buffer, "\n");
      $pos -= $chunkSize;
    }
    fclose($f);
    $linesArr = explode("\n", trim($buffer));
    $last = array_slice($linesArr, -$lines);
    return implode("\n", $last);
  };

  $phpErrors = htmlspecialchars($tail($errorLogPath, 50), ENT_QUOTES | ENT_SUBSTITUTE);
  $debugLogs = htmlspecialchars($tail($debugLogPath, 50), ENT_QUOTES | ENT_SUBSTITUTE);

  // Output HTML
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Split Cash - Debug Status</title>';
  echo '<style>body{font-family: Arial, sans-serif;line-height:1.5;padding:20px}pre{background:#f8f8f8;padding:10px;border:1px solid #eee;overflow:auto;max-height:400px}</style>';
  echo '</head><body>';
  echo '<h1>Split Cash - Debug Status</h1>';
  echo '<p><strong>Time:</strong> ' . htmlspecialchars($time, ENT_QUOTES) . '</p>';
  echo '<p><strong>PHP version:</strong> ' . htmlspecialchars($php, ENT_QUOTES) . '</p>';
  echo '<p><strong>Database:</strong> ' . $dbStatus . '</p>';
  echo '<h2>PHP Error Log (last 50 lines)</h2>';
  echo '<pre>' . ($phpErrors ?: 'No errors found') . '</pre>';
  echo '<h2>Debug Log (last 50 lines)</h2>';
  echo '<pre>' . ($debugLogs ?: 'No debug logs found') . '</pre>';
  echo '<p style="color:#c33"><strong>Security note:</strong> This page is temporary and exposes debug information. Remove it when finished.</p>';
  echo '</body></html>';
  exit;
}

function debugLog($msg)
{
  $dir = __DIR__ . '/../debug';
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  $file = $dir . '/debug.log';
  @file_put_contents($file, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function debugIndex()
{
  debugLog('DEBUG INDEX accessed from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

  // Basic env info
  $info = [
    'php_version' => PHP_VERSION,
    'loaded_extensions' => get_loaded_extensions(),
    'memory_usage_bytes' => memory_get_usage(false),
    'disk_free_bytes' => @disk_free_space(__DIR__ . '/..'),
    'timestamp' => date('c')
  ];

  // Test database connection
  try {
    $db = getDB();
    $db->query('SELECT 1');
    $info['database'] = 'connected';
  } catch (Exception $e) {
    $info['database'] = 'error: ' . $e->getMessage();
    debugLog('DB check error: ' . $e->getMessage());
  }

  // Test outbound connectivity to Google tokeninfo endpoint (no token sent)
  $googleTest = null;
  if (function_exists('curl_init')) {
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = @curl_exec($ch);
    $errno = curl_errno($ch);
    $infoCurl = curl_getinfo($ch);
    curl_close($ch);
    $googleTest = ['errno' => $errno, 'http_code' => $infoCurl['http_code'] ?? null];
  } else {
    $googleTest = ['curl' => false];
  }

  $info['google_connectivity'] = $googleTest;
  debugLog('DEBUG INDEX result: ' . json_encode($info));

  Response::success($info);
}

function debugGoogleVerify()
{
  $headers = function_exists('getallheaders') ? getallheaders() : [];
  $secretHeader = $headers['X-Debug-Secret'] ?? ($headers['x-debug-secret'] ?? null);

  $expected = getenv('DEBUG_SECRET') ?: null;
  if ($expected && $secretHeader !== $expected) {
    Response::error('Unauthorized', 401);
  }

  $input = getJsonInput();
  $idToken = $input['idToken'] ?? null;

  debugLog('Google verify called from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' tokenPresent:' . ($idToken ? 'yes' : 'no'));

  if (!$idToken) {
    Response::error('idToken is required', 400);
  }

  try {
    $client = new Google\Client(['client_id' => GOOGLE_CLIENT_ID]);
    $payload = $client->verifyIdToken($idToken);

    debugLog('Google verify result: ' . ($payload ? 'verified' : 'null'));

    Response::success([
      'verified' => (bool) $payload,
      'payload_summary' => $payload ? ['sub' => $payload['sub'] ?? null, 'email' => $payload['email'] ?? null] : null
    ]);
  } catch (Exception $e) {
    debugLog('Google verify exception: ' . $e->getMessage());
    Response::error('Google verification failed: ' . $e->getMessage(), 500);
  }
}
