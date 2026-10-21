<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/overview.php';
require_once __DIR__ . '/../utils/activity.php';
require_once __DIR__ . '/../utils/push.php';

function handleBalanceRoutes($uri, $method)
{
  $userId = requireUserId();
  $parts = explode('/', trim($uri, '/'));
  $n = count($parts);

  if ($method === 'GET' && $n == 2 && $parts[1] === 'overview') {
    // GET /balances/overview (native app: Groups + Friends tabs)
    Response::success(buildOverview($userId));
  } elseif ($method === 'GET' && $n == 2 && $parts[1] === 'my-balances') {
    getMyBalances($userId);
  } elseif ($method === 'GET' && $n == 2 && is_numeric($parts[1])) {
    getGroupBalances((int) $parts[1], $userId);
  } elseif ($method === 'GET' && $n == 3 && is_numeric($parts[1]) && $parts[2] === 'debts') {
    // GET /balances/{groupId}/debts (native app: group balances + settle up)
    requireActiveMember((int) $parts[1], $userId);
    Response::success(buildGroupDebts((int) $parts[1]));
  } elseif ($method === 'GET' && $n == 4 && $parts[2] === 'settlements' && $parts[3] === 'suggestions') {
    getSettlementSuggestions((int) $parts[1], $userId);
  } elseif ($method === 'GET' && $n == 4 && $parts[2] === 'settlements' && $parts[3] === 'history') {
    getSettlementHistory((int) $parts[1], $userId);
  } elseif ($method === 'POST' && $n == 3 && $parts[2] === 'settlements') {
    recordSettlement((int) $parts[1], $userId);
  } elseif ($method === 'DELETE' && $n == 4 && $parts[2] === 'settlements' && is_numeric($parts[3])) {
    deleteSettlement((int) $parts[1], $userId, (int) $parts[3]);
  } elseif ($method === 'GET' && $n == 3 && $parts[2] === 'activity') {
    getGroupActivity((int) $parts[1], $userId);
  } else {
    Response::error("Route not found: $method $uri", 404);
  }
}

/** v1 dashboard: the user's net in each regular group (hidden 1:1 groups are native-only). */
function getMyBalances(int $userId)
{
  $overview = buildOverview($userId);
  $result = [];
  $ledgers = loadLedgers(array_map(function ($g) { return $g['id']; }, $overview['groups']));
  foreach ($overview['groups'] as $g) {
    $paid = 0;
    $owed = 0;
    foreach ($ledgers[$g['id']]['expenses'] as $e) {
      if ($e['paid_by'] === $userId) {
        $paid += $e['amount'];
      }
      $owed += $e['splits'][$userId] ?? 0;
    }
    $result[] = [
      'group_id' => $g['id'],
      'group_name' => $g['name'],
      'category' => $g['category'],
      'image' => $g['image'],
      'member_count' => $g['member_count'],
      'balance' => $g['my_net'],
      'total_paid' => fromPaise($paid),
      'total_owed' => fromPaise($owed),
    ];
  }
  Response::success($result);
}

/** v1 group balances: every member's paid / owed / net. */
function getGroupBalances(int $groupId, int $userId)
{
  requireActiveMember($groupId, $userId);
  $ledger = loadLedgers([$groupId])[$groupId];
  $members = loadGroupMembers([$groupId])[$groupId] ?? [];
  $nets = ledgerNets($ledger);
  $result = [];
  foreach ($members as $uid => $m) {
    $paid = 0;
    $owed = 0;
    foreach ($ledger['expenses'] as $e) {
      if ($e['paid_by'] === $uid) {
        $paid += $e['amount'];
      }
      $owed += $e['splits'][$uid] ?? 0;
    }
    $result[] = [
      'user_id' => $uid,
      'name' => $m['name'],
      'email' => $m['email'],
      'profile_picture' => $m['profile_picture'],
      'total_paid' => fromPaise($paid),
      'total_owed' => fromPaise($owed),
      'balance' => fromPaise($nets[$uid] ?? 0),
    ];
  }
  Response::success($result);
}

/** Who should pay whom. Respects the group's simplify setting (v1 always showed simplified). */
function getSettlementSuggestions(int $groupId, int $userId)
{
  requireActiveMember($groupId, $userId);
  $debts = buildGroupDebts($groupId)['debts'];
  Response::success(array_map(function ($d) {
    return [
      'from_user_id' => $d['from_user_id'],
      'from_user_name' => $d['from_user_name'],
      'to_user_id' => $d['to_user_id'],
      'to_user_name' => $d['to_user_name'],
      'amount' => $d['amount'],
    ];
  }, $debts));
}

function getSettlementHistory(int $groupId, int $userId)
{
  requireActiveMember($groupId, $userId);
  $rows = getDB()->fetchAll(
    'SELECT s.*, u1.name AS from_user_name, u1.profile_picture AS from_user_picture,
            u2.name AS to_user_name, u2.profile_picture AS to_user_picture
       FROM settlements s
       INNER JOIN users u1 ON s.from_user_id = u1.id
       INNER JOIN users u2 ON s.to_user_id = u2.id
      WHERE s.group_id = ?
      ORDER BY s.date DESC, s.created_at DESC',
    [$groupId]
  );
  Response::success(array_map('settlementRow', $rows));
}

function settlementRow(array $s): array
{
  return [
    'id' => (int) $s['id'],
    'group_id' => (int) $s['group_id'],
    'from_user_id' => (int) $s['from_user_id'],
    'from_user_name' => $s['from_user_name'],
    'from_user_picture' => $s['from_user_picture'] ?? null,
    'to_user_id' => (int) $s['to_user_id'],
    'to_user_name' => $s['to_user_name'],
    'to_user_picture' => $s['to_user_picture'] ?? null,
    'created_by' => isset($s['created_by']) && $s['created_by'] !== null ? (int) $s['created_by'] : null,
    'amount' => (float) $s['amount'],
    'date' => $s['date'],
    'notes' => $s['notes'],
    'created_at' => $s['created_at'],
  ];
}

/**
 * Inserts one payment, logs it and pushes to the other party. Shared with POST /friends/{id}/settle.
 * @return int settlement id
 */
function insertSettlement(int $groupId, int $actorId, int $fromId, int $toId, int $amountPaise, string $date, ?string $notes, bool $notify = true): int
{
  $db = getDB();
  $id = (int) $db->insert(
    'INSERT INTO settlements (group_id, from_user_id, to_user_id, created_by, amount, date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$groupId, $fromId, $toId, $actorId, fromPaise($amountPaise), $date, $notes]
  );
  $fromName = userName($fromId);
  $toName = userName($toId);
  logActivity($groupId, $actorId, 'record_settlement', 'settlement', $id, "$fromName paid $toName " . formatInr($amountPaise), [
    'amount' => $amountPaise,
    'from' => $fromId,
    'to' => $toId,
    'nets' => [$fromId => $amountPaise, $toId => -$amountPaise],
  ]);
  $db->execute('UPDATE expense_groups SET updated_at = NOW() WHERE id = ?', [$groupId]);

  if ($notify) {
    foreach ([$fromId, $toId] as $uid) {
      if ($uid === $actorId) {
        continue;
      }
      $title = $uid === $toId
        ? firstName($fromName) . ' paid you ' . formatInr($amountPaise)
        : firstName(userName($actorId)) . " recorded that you paid " . firstName($toName) . ' ' . formatInr($amountPaise);
      Push::toUser($uid, [
        'type' => 'payment_recorded',
        'group_id' => $groupId,
        'settlement_id' => $id,
        'actor_id' => $actorId,
        'friend_id' => $uid === $toId ? $fromId : $toId,
        'title' => $title,
        'body' => groupDisplayNameFor($groupId, $uid),
      ]);
    }
  }
  return $id;
}

/** Group name, or for a hidden 1:1 group "Non-group" (pushes need something readable). */
function groupDisplayNameFor(int $groupId, int $viewerId): string
{
  $g = getDB()->fetchOne('SELECT name, is_direct FROM expense_groups WHERE id = ?', [$groupId]);
  if (!$g) {
    return '';
  }
  return $g['is_direct'] ? 'Non-group payment' : $g['name'];
}

/**
 * POST /balances/{groupId}/settlements
 * {toUserId, amount, date, notes?, fromUserId?} — fromUserId defaults to the caller, so v1 keeps
 * recording "I paid X"; the native app can also record "X paid me" or a payment between two others.
 */
function recordSettlement(int $groupId, int $userId)
{
  $input = getJsonInput();
  $fromId = (int) ($input['fromUserId'] ?? ($input['from_user_id'] ?? $userId));
  $toId = (int) ($input['toUserId'] ?? ($input['to_user_id'] ?? 0));
  $amountPaise = toPaise($input['amount'] ?? 0);
  $date = $input['date'] ?? null;
  $notes = isset($input['notes']) && $input['notes'] !== '' ? $input['notes'] : null;

  if (!$toId || $amountPaise <= 0 || !$date) {
    Response::error('To user, amount, and date are required', 400);
  }
  if ($fromId === $toId) {
    Response::error('Choose two different people', 400);
  }
  requireActiveMember($groupId, $userId);
  foreach ([$fromId, $toId] as $uid) {
    $m = memberRow($groupId, $uid);
    if (!$m) {
      Response::error('Both people must be members of this group', 400);
    }
  }

  $id = insertSettlement($groupId, $userId, $fromId, $toId, $amountPaise, $date, $notes);
  Response::success(['id' => $id, 'message' => 'Settlement recorded successfully'], 'Settlement recorded successfully', 201);
}

/** Either side of the payment, whoever recorded it, or a group admin may delete it. */
function deleteSettlement(int $groupId, int $userId, int $settlementId)
{
  $member = requireActiveMember($groupId, $userId);
  $db = getDB();
  $s = $db->fetchOne('SELECT * FROM settlements WHERE id = ? AND group_id = ?', [$settlementId, $groupId]);
  if (!$s) {
    Response::error('Settlement not found', 404);
  }
  $allowed = in_array($userId, [(int) $s['from_user_id'], (int) $s['to_user_id'], (int) ($s['created_by'] ?? 0)], true) || $member['role'] === 'admin';
  if (!$allowed) {
    Response::error('Only the two people involved or a group admin can delete this payment', 403);
  }
  $db->execute('DELETE FROM settlements WHERE id = ? AND group_id = ?', [$settlementId, $groupId]);
  $amountPaise = toPaise($s['amount']);
  logActivity($groupId, $userId, 'delete_settlement', 'settlement', $settlementId, 'Deleted a payment of ' . formatInr($amountPaise), [
    'amount' => $amountPaise,
    'from' => (int) $s['from_user_id'],
    'to' => (int) $s['to_user_id'],
    // The payment as it was (like delete_expense's meta): the feed shows "Removed: you paid ₹X"
    'nets' => [(int) $s['from_user_id'] => $amountPaise, (int) $s['to_user_id'] => -$amountPaise],
  ]);
  Response::success(['message' => 'Settlement deleted successfully'], 'Settlement deleted successfully');
}

/** v1 per-group activity list. */
function getGroupActivity(int $groupId, int $userId)
{
  requireActiveMember($groupId, $userId);
  $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;
  $rows = getDB()->fetchAll(
    'SELECT a.*, u.name AS user_name, u.profile_picture FROM activities a INNER JOIN users u ON a.user_id = u.id
      WHERE a.group_id = ? ORDER BY a.id DESC LIMIT ' . $limit,
    [$groupId]
  );
  Response::success(array_map(function ($a) {
    return [
      'id' => (int) $a['id'],
      'user_id' => (int) $a['user_id'],
      'user_name' => $a['user_name'],
      'profile_picture' => $a['profile_picture'],
      'action' => $a['action'],
      'entity_type' => $a['entity_type'],
      'entity_id' => $a['entity_id'] ? (int) $a['entity_id'] : null,
      'description' => $a['description'],
      'created_at' => $a['created_at'],
    ];
  }, $rows));
}
