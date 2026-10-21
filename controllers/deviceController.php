<?php
require_once __DIR__ . '/../utils/auth.php';

/**
 * POST   /devices {token, platform?, app_version?}  register / refresh this phone's FCM token
 * DELETE /devices {token}                           stop pushes to it (sign-out)
 */
function handleDeviceRoutes($uri, $method)
{
  if (trim($uri, '/') !== 'devices') {
    Response::error("Route not found: $method $uri", 404);
  }
  $userId = requireUserId();
  $input = getJsonInput();
  $token = trim((string) ($input['token'] ?? ''));
  if ($token === '' || strlen($token) > 512) {
    Response::error('A device token is required', 400);
  }
  $db = getDB();

  if ($method === 'POST') {
    $platform = in_array($input['platform'] ?? 'android', ['android', 'ios'], true) ? ($input['platform'] ?? 'android') : 'android';
    $version = isset($input['app_version']) ? substr((string) $input['app_version'], 0, 32) : null;
    // A token moves with the phone: re-registering under another account takes it over
    $db->execute(
      'INSERT INTO devices (user_id, fcm_token, platform, app_version, is_active) VALUES (?, ?, ?, ?, 1)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), app_version = VALUES(app_version), is_active = 1',
      [$userId, $token, $platform, $version]
    );
    Response::success(null, 'Device registered');
  }
  if ($method === 'DELETE') {
    $db->execute('UPDATE devices SET is_active = 0 WHERE fcm_token = ? AND user_id = ?', [$token, $userId]);
    Response::success(null, 'Device removed');
  }
  Response::error("Route not found: $method $uri", 404);
}
