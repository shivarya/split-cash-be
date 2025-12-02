<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Show what the routing is seeing
$requestUri = $_SERVER['REQUEST_URI'];
$uri = parse_url($requestUri, PHP_URL_PATH);

$basePath = '/split_cash';
$cleanUri = $uri;
if (strpos($uri, $basePath) === 0) {
  $cleanUri = substr($uri, strlen($basePath));
}
$cleanUri = $cleanUri ?: '/';

echo json_encode([
  'success' => true,
  'routing_debug' => [
    'raw_request_uri' => $requestUri,
    'parsed_uri' => $uri,
    'base_path' => $basePath,
    'clean_uri' => $cleanUri,
    'would_match_health' => ($cleanUri === '/health'),
    'would_match_root' => ($cleanUri === '/' || $cleanUri === ''),
  ]
], JSON_PRETTY_PRINT);
