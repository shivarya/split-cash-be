<?php
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/ledger.php';

/**
 * GET /activity?limit=50&before_id=&after_id=
 * The Activity tab: everything that happened in the viewer's groups, newest first, each row
 * with structured fields (so the app writes its own sentence) and the viewer's impact.
 */
function handleActivityRoutes($uri, $method)
{
  if (trim($uri, '/') !== 'activity' || $method !== 'GET') {
    Response::error("Route not found: $method $uri", 404);
  }
  $userId = requireUserId();
  $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 50;
  $beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : 0;
  $afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : 0;

  $groupIds = activeGroupIdsFor($userId);
  if (empty($groupIds)) {
    Response::success(['items' => [], 'has_more' => false]);
  }
  $where = 'a.group_id IN (' . inPlaceholders($groupIds) . ')';
  $params = $groupIds;
  if ($beforeId > 0) {
    $where .= ' AND a.id < ?';
    $params[] = $beforeId;
  }
  if ($afterId > 0) {
    $where .= ' AND a.id > ?';
    $params[] = $afterId;
  }
  $db = getDB();
  $rows = $db->fetchAll(
    "SELECT a.*, u.name AS user_name, u.profile_picture, g.name AS group_name, g.is_direct
       FROM activities a
       INNER JOIN users u ON u.id = a.user_id
       INNER JOIN expense_groups g ON g.id = a.group_id
      WHERE $where
      ORDER BY a.id DESC
      LIMIT " . ($limit + 1),
    $params
  );
  $hasMore = count($rows) > $limit;
  $rows = array_slice($rows, 0, $limit);

  // Live rows for old activity that has no snapshot
  $expenseIds = [];
  $settlementIds = [];
  $directIds = [];
  foreach ($rows as $r) {
    if ($r['meta'] === null && $r['entity_type'] === 'expense' && $r['entity_id']) {
      $expenseIds[] = (int) $r['entity_id'];
    }
    if ($r['meta'] === null && $r['entity_type'] === 'settlement' && $r['entity_id']) {
      $settlementIds[] = (int) $r['entity_id'];
    }
    if ($r['is_direct']) {
      $directIds[] = (int) $r['group_id'];
    }
  }
  $liveExpenses = [];
  foreach (array_unique($expenseIds) as $eid) {
    $row = expenseEngineRow($eid);
    if ($row) {
      $liveExpenses[$eid] = $row;
    }
  }
  $liveSettlements = [];
  if (!empty($settlementIds)) {
    foreach ($db->fetchAll('SELECT id, from_user_id, to_user_id, amount FROM settlements WHERE id IN (' . inPlaceholders($settlementIds) . ')', $settlementIds) as $s) {
      $liveSettlements[(int) $s['id']] = $s;
    }
  }
  $directMembers = loadGroupMembers($directIds);
  $names = [];
  $nameOf = function (int $uid) use (&$names, $db) {
    if (!isset($names[$uid])) {
      $u = $db->fetchOne('SELECT name FROM users WHERE id = ?', [$uid]);
      $names[$uid] = $u ? $u['name'] : 'Someone';
    }
    return $names[$uid];
  };

  $items = [];
  foreach ($rows as $r) {
    $meta = $r['meta'] !== null ? json_decode($r['meta'], true) : null;
    $gid = (int) $r['group_id'];
    $groupName = $r['group_name'];
    if ($r['is_direct']) {
      foreach ($directMembers[$gid] ?? [] as $uid => $m) {
        if ($uid !== $userId) {
          $groupName = $m['name'];
        }
      }
    }

    $subject = null;
    $amount = null;
    $myNet = null;
    $from = null;
    $to = null;
    $comment = null;
    if ($meta !== null) {
      $subject = $meta['description'] ?? null;
      $amount = isset($meta['amount']) ? fromPaise((int) $meta['amount']) : null;
      if (isset($meta['nets'])) {
        $myNet = fromPaise((int) ($meta['nets'][(string) $userId] ?? ($meta['nets'][$userId] ?? 0)));
      }
      $from = isset($meta['from']) ? (int) $meta['from'] : null;
      $to = isset($meta['to']) ? (int) $meta['to'] : null;
      $comment = $meta['body'] ?? null;
    } elseif ($r['entity_type'] === 'expense' && isset($liveExpenses[(int) $r['entity_id']])) {
      $e = $liveExpenses[(int) $r['entity_id']];
      $subject = $e['description'];
      $amount = fromPaise($e['amount']);
      $myNet = fromPaise(expenseNetFor($e, $userId));
    } elseif ($r['entity_type'] === 'settlement' && isset($liveSettlements[(int) $r['entity_id']])) {
      $s = $liveSettlements[(int) $r['entity_id']];
      $from = (int) $s['from_user_id'];
      $to = (int) $s['to_user_id'];
      $amount = (float) $s['amount'];
      $p = toPaise($s['amount']);
      $myNet = fromPaise($from === $userId ? $p : ($to === $userId ? -$p : 0));
    }

    $items[] = [
      'id' => (int) $r['id'],
      'action' => $r['action'],
      'group_id' => $gid,
      'group_name' => $groupName,
      'group_is_direct' => (bool) $r['is_direct'],
      'actor_id' => (int) $r['user_id'],
      'actor_name' => $r['user_name'],
      'actor_picture' => $r['profile_picture'],
      'entity_type' => $r['entity_type'],
      'entity_id' => $r['entity_id'] !== null ? (int) $r['entity_id'] : null,
      'subject' => $subject,
      'amount' => $amount,
      'my_net' => $myNet,
      'from_user_id' => $from,
      'from_user_name' => $from !== null ? $nameOf($from) : null,
      'to_user_id' => $to,
      'to_user_name' => $to !== null ? $nameOf($to) : null,
      'comment' => $comment,
      'description' => $r['description'],
      'created_at' => $r['created_at'],
    ];
  }
  Response::success(['items' => $items, 'has_more' => $hasMore]);
}
