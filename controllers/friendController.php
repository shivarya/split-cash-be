<?php
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/overview.php';
require_once __DIR__ . '/../utils/direct.php';
require_once __DIR__ . '/../utils/activity.php';
require_once __DIR__ . '/../utils/push.php';
require_once __DIR__ . '/balanceController.php';

/**
 * GET  /friends                  friends + overall balance
 * POST /friends                  {email} add a friend (invites them by email if they aren't on Split Cash)
 * GET  /friends/{id}             balance, per-group breakdown, shared expenses and payments
 * POST /friends/{id}/direct      the pair's hidden 1:1 group (for a non-group expense)
 * POST /friends/{id}/settle      {amount, date, notes?, direction: paid|received} one payment, spread over shared groups
 * POST /friends/{id}/remind      push "you owe me" (once a day per pair)
 */
function handleFriendRoutes($uri, $method)
{
  $userId = requireUserId();
  $parts = explode('/', trim($uri, '/'));
  $n = count($parts);

  if ($n === 1 && $method === 'GET') {
    $o = buildOverview($userId);
    Response::success(['overall' => $o['overall'], 'friends' => $o['friends']]);
  }
  if ($n === 1 && $method === 'POST') {
    addFriend($userId);
  }
  if ($n >= 2 && is_numeric($parts[1])) {
    $friendId = (int) $parts[1];
    $sub = $parts[2] ?? null;
    if ($friendId === $userId) {
      Response::error('That\'s you', 400);
    }
    if ($sub === null && $method === 'GET') {
      $detail = buildFriendDetail($userId, $friendId);
      if (!$detail) {
        Response::error('Friend not found', 404);
      }
      Response::success($detail);
    }
    if ($sub === 'direct' && $method === 'POST') {
      requireSharedGroup($userId, $friendId);
      Response::success(['group_id' => getOrCreateDirectGroup($userId, $friendId)]);
    }
    if ($sub === 'settle' && $method === 'POST') {
      settleWithFriend($userId, $friendId);
    }
    if ($sub === 'remind' && $method === 'POST') {
      remindFriend($userId, $friendId);
    }
  }
  Response::error("Route not found: $method $uri", 404);
}

function requireSharedGroup(int $userId, int $friendId): void
{
  if (empty(sharedGroupIds($userId, $friendId))) {
    Response::error('Friend not found', 404);
  }
}

function friendEntry(int $userId, int $friendId): ?array
{
  foreach (buildOverview($userId)['friends'] as $f) {
    if ($f['user_id'] === $friendId) {
      return $f;
    }
  }
  return null;
}

/** POST /friends {email} — existing user: create the 1:1 group; unknown email: placeholder + invite email. */
function addFriend(int $userId)
{
  $input = getJsonInput();
  $email = strtolower(trim((string) ($input['email'] ?? '')));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Enter a valid email address', 400);
  }
  $db = getDB();
  $me = $db->fetchOne('SELECT email FROM users WHERE id = ?', [$userId]);
  if ($me && strtolower($me['email']) === $email) {
    Response::error('That\'s your own email', 400);
  }

  // Same spam limit as group invites
  $recent = $db->fetchOne('SELECT COUNT(*) AS c FROM invitations WHERE invited_by = ? AND created_at > ?', [$userId, date('Y-m-d H:i:s', strtotime('-1 hour'))]);
  if ($recent && (int) $recent['c'] >= 10) {
    Response::error('Too many invitations sent. Please wait before sending more.', 429);
  }

  $friend = $db->fetchOne('SELECT id, name, email, profile_picture, google_id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
  $invited = false;
  if (!$friend) {
    $placeholderId = (int) $db->insert('INSERT INTO users (email, name, google_id) VALUES (?, ?, NULL)', [$email, $email]);
    $friend = ['id' => $placeholderId, 'name' => $email, 'email' => $email, 'profile_picture' => null, 'google_id' => null];
  }
  $friendId = (int) $friend['id'];
  $groupId = getOrCreateDirectGroup($userId, $friendId);

  if ($friend['google_id'] === null) {
    $token = bin2hex(random_bytes(32));
    $db->insert(
      'INSERT INTO invitations (group_id, email, invited_by, token, expires_at, user_id) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?)',
      [$groupId, $email, $userId, $token, $friendId]
    );
    sendFriendInviteEmail($email, userName($userId), $token);
    $invited = true;
  } else {
    Push::toUser($friendId, [
      'type' => 'friend_added',
      'friend_id' => $userId,
      'group_id' => $groupId,
      'actor_id' => $userId,
      'title' => firstName(userName($userId)) . ' added you as a friend',
      'body' => 'You can now split expenses with each other',
    ]);
  }

  Response::success([
    'friend' => ['user_id' => $friendId, 'name' => $friend['name'], 'email' => $friend['email'], 'profile_picture' => $friend['profile_picture'], 'is_registered' => !$invited],
    'direct_group_id' => $groupId,
    'invited' => $invited,
  ], $invited ? 'Invitation sent' : 'Friend added', 201);
}

function sendFriendInviteEmail(string $toEmail, string $fromName, string $token): void
{
  $deepLink = 'splitcash://invite/' . urlencode($token);
  $subject = "$fromName wants to split expenses with you on Split Cash";
  $message = "Hello,\n\n$fromName added you as a friend on Split Cash, so you can split bills and keep track of who owes whom.\n\n"
    . "Get the app: " . PLAY_STORE_URL . "\nThen open: $deepLink\n\nSign in with this email address ($toEmail) and you'll see each other straight away.\n\n"
    . "If you did not expect this, you can ignore this email.";
  $headers = ['From: ' . EMAIL_FROM, 'Reply-To: ' . EMAIL_FROM_ADDRESS, 'Content-Type: text/plain; charset=UTF-8'];
  if (!@mail($toEmail, $subject, $message, implode("\r\n", $headers))) {
    error_log('Failed to send a friend invite email');
  }
}

/**
 * One payment between you and a friend, spread over the groups where that direction is owed
 * (biggest debt first). Anything beyond what's owed goes to your non-group balance.
 */
function settleWithFriend(int $userId, int $friendId)
{
  requireSharedGroup($userId, $friendId);
  $input = getJsonInput();
  $amountPaise = toPaise($input['amount'] ?? 0);
  $date = $input['date'] ?? date('Y-m-d');
  $notes = isset($input['notes']) && $input['notes'] !== '' ? $input['notes'] : null;
  $direction = $input['direction'] ?? 'paid';
  if ($amountPaise <= 0) {
    Response::error('Amount must be greater than 0', 400);
  }
  if (!in_array($direction, ['paid', 'received'], true)) {
    Response::error('direction must be "paid" or "received"', 400);
  }
  [$fromId, $toId] = $direction === 'paid' ? [$userId, $friendId] : [$friendId, $userId];

  // amount > 0 in the overview means the friend owes me in that group
  $entry = friendEntry($userId, $friendId);
  $candidates = [];
  foreach ($entry['groups'] ?? [] as $g) {
    $owedPaise = toPaise($g['amount']);
    $relevant = $direction === 'paid' ? -$owedPaise : $owedPaise;
    if ($relevant > 0) {
      $candidates[] = ['group_id' => $g['group_id'], 'amount' => $relevant];
    }
  }
  usort($candidates, function ($a, $b) { return $b['amount'] <=> $a['amount']; });

  $db = getDB();
  $conn = $db->getConnection();
  $created = [];
  $left = $amountPaise;
  $conn->beginTransaction();
  try {
    foreach ($candidates as $c) {
      if ($left <= 0) {
        break;
      }
      // Only groups where both of you are still active can take a payment
      $a = memberRow($c['group_id'], $userId);
      $b = memberRow($c['group_id'], $friendId);
      if (!$a || !$b || $a['status'] !== 'active' || $b['status'] === 'left') {
        continue;
      }
      $x = min($left, $c['amount']);
      $created[] = ['group_id' => $c['group_id'], 'id' => insertSettlement($c['group_id'], $userId, $fromId, $toId, $x, $date, $notes, false), 'amount' => fromPaise($x)];
      $left -= $x;
    }
    if ($left > 0) {
      $gid = getOrCreateDirectGroup($userId, $friendId);
      $created[] = ['group_id' => $gid, 'id' => insertSettlement($gid, $userId, $fromId, $toId, $left, $date, $notes, false), 'amount' => fromPaise($left)];
    }
    $conn->commit();
  } catch (Exception $e) {
    $conn->rollBack();
    Response::error('Failed to record payment: ' . $e->getMessage(), 500);
  }

  $me = firstName(userName($userId));
  Push::toUser($friendId, [
    'type' => 'payment_recorded',
    'friend_id' => $userId,
    'group_id' => $created[0]['group_id'],
    'actor_id' => $userId,
    'title' => $direction === 'paid' ? "$me paid you " . formatInr($amountPaise) : "$me recorded that you paid " . formatInr($amountPaise),
    'body' => count($created) > 1 ? 'Across ' . count($created) . ' groups' : groupDisplayNameFor($created[0]['group_id'], $friendId),
  ]);

  Response::success(['settlements' => $created], 'Payment recorded', 201);
}

function remindFriend(int $userId, int $friendId)
{
  requireSharedGroup($userId, $friendId);
  $entry = friendEntry($userId, $friendId);
  $net = $entry ? toPaise($entry['net']) : 0;
  if ($net <= SETTLED_EPSILON_PAISE) {
    Response::error(firstName(userName($friendId)) . ' doesn\'t owe you anything', 400);
  }
  $db = getDB();
  $recent = $db->fetchOne('SELECT id FROM friend_reminders WHERE from_user_id = ? AND to_user_id = ? AND created_at > ?', [$userId, $friendId, date('Y-m-d H:i:s', strtotime('-1 day'))]);
  if ($recent) {
    Response::error('You already sent a reminder today', 429);
  }
  $db->insert('INSERT INTO friend_reminders (from_user_id, to_user_id) VALUES (?, ?)', [$userId, $friendId]);
  Push::toUser($friendId, [
    'type' => 'reminder',
    'friend_id' => $userId,
    'actor_id' => $userId,
    'title' => firstName(userName($userId)) . ' sent you a reminder',
    'body' => 'You owe ' . formatInr($net) . ' · tap to settle up',
  ]);
  Response::success(['amount' => fromPaise($net)], 'Reminder sent');
}
