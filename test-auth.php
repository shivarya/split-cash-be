<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;

$input = json_decode(file_get_contents('php://input'), true);
$idToken = $input['idToken'] ?? null;

$debug = [
  'step' => 'init',
  'token_received' => $idToken ? true : false,
  'token_length' => $idToken ? strlen($idToken) : 0,
  'client_id' => substr(GOOGLE_CLIENT_ID, 0, 30) . '...',
];

if (!$idToken) {
  echo json_encode(['success' => false, 'error' => 'No token provided', 'debug' => $debug]);
  exit;
}

try {
  $debug['step'] = 'creating_client';
  $client = new Client(['client_id' => GOOGLE_CLIENT_ID]);

  $debug['step'] = 'verifying_token';
  $payload = $client->verifyIdToken($idToken);

  $debug['step'] = 'verification_complete';
  $debug['payload_received'] = $payload ? true : false;

  if ($payload) {
    $debug['user_email'] = $payload['email'] ?? 'not set';
    $debug['user_name'] = $payload['name'] ?? 'not set';
    $debug['google_id'] = $payload['sub'] ?? 'not set';

    echo json_encode([
      'success' => true,
      'message' => 'Token verified successfully!',
      'debug' => $debug
    ]);
  } else {
    echo json_encode([
      'success' => false,
      'error' => 'Token verification returned null',
      'debug' => $debug
    ]);
  }
} catch (Exception $e) {
  $debug['step'] = 'error';
  $debug['error_message'] = $e->getMessage();
  $debug['error_file'] = $e->getFile();
  $debug['error_line'] = $e->getLine();

  echo json_encode([
    'success' => false,
    'error' => $e->getMessage(),
    'debug' => $debug
  ]);
}
