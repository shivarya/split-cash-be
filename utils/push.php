<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

/**
 * FCM HTTP v1 sender (ported from clear-call). Data-only messages with Android priority HIGH;
 * the app turns each into a notification on the channel its `type` maps to.
 *
 * When FCM isn't configured (local dev without a service-account key) sends are skipped and
 * logged, so every flow stays testable with curl.
 */
class Fcm
{
  private static $accessToken = null;

  private static function accessToken(): ?string
  {
    if (self::$accessToken !== null) {
      return self::$accessToken;
    }
    $client = new Google\Client();
    $client->setAuthConfig(FCM_SERVICE_ACCOUNT_PATH);
    $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
    $tokenInfo = $client->fetchAccessTokenWithAssertion();
    self::$accessToken = $tokenInfo['access_token'] ?? null;
    return self::$accessToken;
  }

  /**
   * @param string[] $tokens active device tokens
   * @param array $data flat payload; every value is sent as a string (FCM v1 requirement)
   * @return array{sent:int, failed:int, skipped:bool, staleTokens:string[]}
   */
  public static function sendData(array $tokens, array $data, string $ttl = '86400s'): array
  {
    $result = ['sent' => 0, 'failed' => 0, 'skipped' => false, 'staleTokens' => []];
    if (empty($tokens)) {
      return $result;
    }
    if (FCM_PROJECT_ID === '' || FCM_SERVICE_ACCOUNT_PATH === '' || !file_exists(FCM_SERVICE_ACCOUNT_PATH)) {
      error_log('[FCM] Not configured — skipping push type=' . ($data['type'] ?? '?'));
      $result['skipped'] = true;
      return $result;
    }

    $stringData = [];
    foreach ($data as $k => $v) {
      if ($v !== null) {
        $stringData[(string) $k] = (string) $v;
      }
    }

    try {
      $accessToken = self::accessToken();
      if (!$accessToken) {
        throw new Exception('No access_token in service-account assertion response');
      }
    } catch (Throwable $e) {
      error_log('[FCM] Service-account auth failed: ' . $e->getMessage());
      $result['failed'] = count($tokens);
      return $result;
    }

    $url = 'https://fcm.googleapis.com/v1/projects/' . FCM_PROJECT_ID . '/messages:send';
    foreach ($tokens as $token) {
      $body = ['message' => ['token' => $token, 'data' => $stringData, 'android' => ['priority' => 'HIGH', 'ttl' => $ttl]]];
      $ch = curl_init();
      curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
      ]);
      $response = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($httpCode >= 200 && $httpCode < 300) {
        $result['sent']++;
      } else {
        $result['failed']++;
        error_log("[FCM] send failed http=$httpCode resp=" . substr((string) $response, 0, 300));
        // UNREGISTERED / NOT_FOUND, or a malformed token: it will never work again
        $resp = (string) $response;
        if ($httpCode === 404 || strpos($resp, 'UNREGISTERED') !== false || strpos($resp, 'not a valid FCM registration token') !== false) {
          $result['staleTokens'][] = $token;
        }
      }
    }
    return $result;
  }
}

/**
 * Pushes are queued while a request runs and sent after the response has gone out
 * (fastcgi_finish_request on PHP-FPM), so a slow FCM call never delays the app.
 */
class Push
{
  private static $queue = [];
  private static $registered = false;

  /**
   * @param int[] $userIds recipients (callers leave out whoever did the action)
   * @param array $data    type, title, body, group_id, expense_id …
   */
  public static function toUsers(array $userIds, array $data): void
  {
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
      if ($uid > 0) {
        self::$queue[] = [$uid, $data];
      }
    }
    if (!self::$registered && !empty(self::$queue)) {
      self::$registered = true;
      register_shutdown_function([self::class, 'flush']);
    }
  }

  /** One recipient with their own text (bodies differ per person: "you owe ₹250"). */
  public static function toUser(int $userId, array $data): void
  {
    self::toUsers([$userId], $data);
  }

  public static function flush(): void
  {
    if (empty(self::$queue)) {
      return;
    }
    if (function_exists('fastcgi_finish_request')) {
      fastcgi_finish_request();
    }
    try {
      $db = getDB();
      foreach (self::$queue as [$uid, $data]) {
        $rows = $db->fetchAll('SELECT fcm_token FROM devices WHERE user_id = ? AND is_active = 1', [$uid]);
        $result = Fcm::sendData(array_column($rows, 'fcm_token'), $data);
        foreach ($result['staleTokens'] as $stale) {
          $db->execute('UPDATE devices SET is_active = 0 WHERE fcm_token = ?', [$stale]);
        }
      }
    } catch (Throwable $e) {
      error_log('[push] flush failed: ' . $e->getMessage());
    }
    self::$queue = [];
  }
}
