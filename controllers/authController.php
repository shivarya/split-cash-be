<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Google\Client;

function handleAuthRoutes($uri, $method)
{
  if ($uri === '/auth/google' && $method === 'POST') {
    googleAuth();
  } elseif ($uri === '/auth/profile' && $method === 'GET') {
    getProfile();
  } elseif ($uri === '/auth/profile' && $method === 'PUT') {
    updateProfile();
  } else {
    Response::error('Route not found', 404);
  }
}

function googleAuth()
{
  $input = getJsonInput();
  $idToken = $input['idToken'] ?? null;

  // Debug: Log received data
  error_log("Google Auth - Token received: " . ($idToken ? "Yes (length: " . strlen($idToken) . ")" : "No"));

  if (!$idToken) {
    Response::error('ID token is required', 400);
    return;
  }

  try {
    // Debug: Log client ID being used
    error_log("Google Auth - Using Client ID: " . substr(GOOGLE_CLIENT_ID, 0, 20) . "...");

    // Verify Google ID token
    $client = new Client(['client_id' => GOOGLE_CLIENT_ID]);
    $payload = $client->verifyIdToken($idToken);

    error_log("Google Auth - Payload: " . ($payload ? json_encode($payload) : "null"));

    if (!$payload) {
      Response::error('Invalid ID token - verification returned null', 401);
      return;
    }

    $googleId = $payload['sub'];
    $email = $payload['email'];
    $name = $payload['name'] ?? '';
    $picture = $payload['picture'] ?? '';

    $db = getDB();

    // Check if user exists
    $user = $db->fetchOne(
      'SELECT * FROM users WHERE google_id = ? OR email = ?',
      [$googleId, $email]
    );

    $isNewUser = false;

    if (!$user) {
      // Create new user
      $userId = $db->insert(
        'INSERT INTO users (google_id, email, name, profile_picture, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
        [$googleId, $email, $name, $picture]
      );

      $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
      $isNewUser = true;
    } else {
      // Update user info
      $db->execute(
        'UPDATE users SET google_id = ?, name = ?, profile_picture = ?, updated_at = NOW() WHERE id = ?',
        [$googleId, $name, $picture, $user['id']]
      );

      // Fetch updated user
      $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$user['id']]);
    }

    // Generate JWT token
    $token = JWTHandler::generate($user['id'], $user['email']);

    // Send welcome email for new users
    if ($isNewUser) {
      sendWelcomeEmail($user['email'], $user['name']);
    }

    Response::success([
      'user' => [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'profile_picture' => $user['profile_picture']
      ],
      'token' => $token
    ]);

  } catch (Exception $e) {
    Response::error('Authentication failed: ' . $e->getMessage(), 500);
  }
}

function getProfile()
{
  $tokenData = JWTHandler::getUserFromToken();

  if (!$tokenData) {
    Response::error('Unauthorized', 401);
  }

  try {
    $db = getDB();
    $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$tokenData['userId']]);

    if (!$user) {
      Response::error('User not found', 404);
    }

    Response::success([
      'user' => [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'profile_picture' => $user['profile_picture']
      ]
    ]);

  } catch (Exception $e) {
    Response::error('Failed to get profile: ' . $e->getMessage(), 500);
  }
}

function updateProfile()
{
  $tokenData = JWTHandler::getUserFromToken();

  if (!$tokenData) {
    Response::error('Unauthorized', 401);
  }

  $input = getJsonInput();
  $name = $input['name'] ?? null;

  if (!$name) {
    Response::error('Name is required', 400);
  }

  try {
    $db = getDB();
    $db->execute(
      'UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?',
      [$name, $tokenData['userId']]
    );

    $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$tokenData['userId']]);

    Response::success([
      'user' => [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'profile_picture' => $user['profile_picture']
      ]
    ]);

  } catch (Exception $e) {
    Response::error('Failed to update profile: ' . $e->getMessage(), 500);
  }
}

function sendWelcomeEmail($email, $name)
{
  // Email sending implementation (optional for now)
  // Can be implemented later using PHP mail() or PHPMailer
}
