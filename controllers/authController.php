<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Google\Client;

// Helper to write auth-specific debug logs
/** Step-by-step sign-in trace (IPs, user ids) in debug/auth_debug.log — local development only. */
function authDebugLog($msg)
{
  if (APP_ENV !== 'local') {
    return;
  }
  $dir = __DIR__ . '/../debug';
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  $file = $dir . '/auth_debug.log';
  @file_put_contents($file, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/activity.php';

function handleAuthRoutes($uri, $method)
{
  if ($uri === '/auth/google' && $method === 'POST') {
    googleAuth();
  } elseif ($uri === '/auth/dev-login' && $method === 'POST') {
    devLogin();
  } elseif ($uri === '/auth/account' && $method === 'DELETE') {
    deleteAccount();
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
  $start = microtime(true);
  $input = getJsonInput();
  $idToken = $input['idToken'] ?? null;
  $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

  // Auth debug start
  authDebugLog('START googleAuth from ' . $remote . ' tokenPresent:' . ($idToken ? '1' : '0') . ' tokenLen:' . ($idToken ? strlen($idToken) : 0));

  if (!$idToken) {
    authDebugLog('MISSING idToken - returning 400');
    Response::error('ID token is required', 400);
    return;
  }

  try {
    // Verify Google ID token
    try {
      $client = new Client(['client_id' => GOOGLE_CLIENT_ID]);
      $payload = $client->verifyIdToken($idToken);
      authDebugLog('verifyIdToken returned ' . ($payload ? 'payload' : 'null'));
    } catch (Exception $e) {
      authDebugLog('verifyIdToken exception: ' . $e->getMessage());
      throw $e; // will be caught by outer catch
    }

    authDebugLog('Payload keys: ' . ($payload ? implode(',', array_keys($payload)) : 'null'));

    if (!$payload) {
      authDebugLog('Invalid ID token - verification returned null');
      Response::error('Invalid ID token - verification returned null', 401);
      return;
    }

    $googleId = $payload['sub'];
    $email = $payload['email'];
    $name = $payload['name'] ?? '';
    $picture = $payload['picture'] ?? '';

    // Test DB connection and log
    try {
      $db = getDB();
      $db->query('SELECT 1');
      authDebugLog('DB connection OK');
    } catch (Exception $e) {
      authDebugLog('DB connection error: ' . $e->getMessage());
      throw $e; // propagate to outer catch to be logged
    }

    // Check if user exists
    try {
      $user = $db->fetchOne(
        'SELECT * FROM users WHERE google_id = ? OR email = ?',
        [$googleId, $email]
      );
      authDebugLog('User lookup completed, found: ' . ($user ? 'yes id=' . $user['id'] : 'no'));
    } catch (Exception $e) {
      authDebugLog('User lookup error: ' . $e->getMessage());
      throw $e;
    }

    $isNewUser = false;

    if (!$user) {
      // Create new user
      try {
        $userId = $db->insert(
          'INSERT INTO users (google_id, email, name, profile_picture, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
          [$googleId, $email, $name, $picture]
        );
        authDebugLog('Inserted new user id=' . $userId);
        $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
        $isNewUser = true;
      } catch (Exception $e) {
        authDebugLog('Insert user error: ' . $e->getMessage());
        throw $e;
      }
    } else {
      // Update user info
      try {
        $db->execute(
          'UPDATE users SET google_id = ?, name = ?, profile_picture = ?, updated_at = NOW() WHERE id = ?',
          [$googleId, $name, $picture, $user['id']]
        );
        authDebugLog('Updated user id=' . $user['id']);
        // Fetch updated user
        $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$user['id']]);
      } catch (Exception $e) {
        authDebugLog('Update user error: ' . $e->getMessage());
        throw $e;
      }
    }

    // Auto-accept any pending invitations for this email (ensures newly invited users see the group immediately)
    try {
      activatePendingMemberships($db, (int) $user['id']);
      acceptPendingInvitations($db, (int) $user['id'], $user['email']);
      authDebugLog('Accepted invitations for user id=' . $user['id']);
    } catch (Exception $e) {
      authDebugLog('Error accepting invitations: ' . $e->getMessage());
    }

    // Generate JWT token
    $token = JWTHandler::generate($user['id'], $user['email']);

    // Send welcome email for new users
    if ($isNewUser) {
      try {
        sendWelcomeEmail($user['email'], $user['name']);
        authDebugLog('Sent welcome email to ' . $user['email']);
      } catch (Exception $e) {
        authDebugLog('Error sending welcome email: ' . $e->getMessage());
      }
    }

    $elapsed = microtime(true) - $start;
    authDebugLog('END googleAuth success userId=' . $user['id'] . ' elapsed=' . round($elapsed, 3) . 's peakMem=' . memory_get_peak_usage(true));

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
    // Additional debug logging
    error_log('googleAuth exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $dir = __DIR__ . '/../debug';
    if (!is_dir($dir)) {
      @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/debug.log', '[' . date('c') . '] googleAuth exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL, FILE_APPEND | LOCK_EX);
    authDebugLog('googleAuth exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

// Accept and clear any pending invitations for the authenticated user by email.
function acceptPendingInvitations($db, $userId, $email)
{
  // Fetch pending, non-expired invitations for this email
  $invitations = $db->fetchAll(
    'SELECT id, group_id FROM invitations WHERE email = ? AND expires_at > NOW()',
    [$email]
  );

  if (!$invitations)
    return;

  foreach ($invitations as $invitation) {
    // Add user to group; ignore if already a member
    $db->insert(
      'INSERT IGNORE INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())',
      [$invitation['group_id'], $userId]
    );

    // Delete invitation after successful join
    $db->execute('DELETE FROM invitations WHERE id = ?', [$invitation['id']]);
  }
}

/**
 * Someone added by email before they had an account sits in group_members as 'pending'.
 * Once they sign in (their placeholder row becomes their real account), they're a full member.
 */
function activatePendingMemberships($db, $userId)
{
  $db->execute("UPDATE group_members SET status = 'active' WHERE user_id = ? AND status = 'pending'", [$userId]);
}

/**
 * POST /auth/dev-login {email, name?} — local development only: needs DEV_LOGIN_ENABLED=1 in .env
 * AND a request from this machine (the emulator reaches it through `adb reverse`, so it's 127.0.0.1).
 */
function devLogin()
{
  $remote = $_SERVER['REMOTE_ADDR'] ?? '';
  if (!DEV_LOGIN_ENABLED || !in_array($remote, ['127.0.0.1', '::1'], true)) {
    Response::error('Route not found', 404);
  }
  $input = getJsonInput();
  $email = strtolower(trim((string) ($input['email'] ?? '')));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('email is required', 400);
  }
  $name = trim((string) ($input['name'] ?? '')) ?: ucfirst(strtok($email, '@'));
  $db = getDB();
  $user = $db->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);
  if (!$user) {
    $id = $db->insert('INSERT INTO users (google_id, email, name) VALUES (?, ?, ?)', ['dev:' . $email, $email, $name]);
    $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
  } elseif ($user['google_id'] === null) {
    // A placeholder from an invite: signing in turns it into a real account, like Google sign-in does
    $db->execute('UPDATE users SET google_id = ?, name = ? WHERE id = ?', ['dev:' . $email, $name, $user['id']]);
    $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$user['id']]);
  }
  activatePendingMemberships($db, (int) $user['id']);
  acceptPendingInvitations($db, (int) $user['id'], $user['email']);
  Response::success([
    'user' => publicUser($user),
    'token' => JWTHandler::generate($user['id'], $user['email']),
  ]);
}

/**
 * DELETE /auth/account — Play's in-app account deletion. The row is anonymised, not deleted:
 * a hard delete would cascade through expenses and splits and rewrite other members' balances.
 * Expenses keep showing "Deleted user"; the person leaves every group and can't sign in as before.
 */
function deleteAccount()
{
  $userId = requireUserId();
  $db = getDB();
  $groups = $db->fetchAll("SELECT group_id, role FROM group_members WHERE user_id = ? AND status IN ('active', 'pending')", [$userId]);

  $conn = $db->getConnection();
  $conn->beginTransaction();
  try {
    foreach ($groups as $g) {
      $gid = (int) $g['group_id'];
      if ($g['role'] === 'admin') {
        // Don't leave a group without an admin
        $otherAdmin = $db->fetchOne("SELECT user_id FROM group_members WHERE group_id = ? AND role = 'admin' AND status = 'active' AND user_id != ?", [$gid, $userId]);
        if (!$otherAdmin) {
          $next = $db->fetchOne("SELECT user_id FROM group_members WHERE group_id = ? AND status = 'active' AND user_id != ? ORDER BY joined_at ASC LIMIT 1", [$gid, $userId]);
          if ($next) {
            $db->execute("UPDATE group_members SET role = 'admin' WHERE group_id = ? AND user_id = ?", [$gid, $next['user_id']]);
          }
        }
      }
      logActivity($gid, $userId, 'delete_account', 'user', $userId, 'Deleted their Split Cash account');
    }
    $db->execute("UPDATE group_members SET status = 'left', left_at = NOW() WHERE user_id = ? AND status IN ('active', 'pending')", [$userId]);
    $db->execute(
      "UPDATE users SET name = 'Deleted user', email = CONCAT('deleted-', id, '@deleted.invalid'), google_id = NULL,
              profile_picture = NULL, phone = NULL, deleted_at = NOW() WHERE id = ?",
      [$userId]
    );
    $db->execute('DELETE FROM devices WHERE user_id = ?', [$userId]);
    $db->execute('DELETE FROM friend_reminders WHERE from_user_id = ? OR to_user_id = ?', [$userId, $userId]);
    $db->execute('DELETE FROM invitations WHERE invited_by = ? OR user_id = ?', [$userId, $userId]);
    $conn->commit();
  } catch (Exception $e) {
    $conn->rollBack();
    Response::error('Failed to delete account: ' . $e->getMessage(), 500);
  }
  Response::success(null, 'Your account has been deleted');
}
