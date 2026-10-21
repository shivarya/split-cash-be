<?php
/**
 * DB loaders that feed the pure engines in balances.php, plus the one expense serializer
 * every endpoint uses. Everything loads in a fixed number of queries per call (no per-row
 * queries), however many groups or expenses are involved.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/splits.php';
require_once __DIR__ . '/balances.php';

/** @return array<int,array> group_id => ledger (see balances.php) */
function loadLedgers(array $groupIds): array
{
  $ledgers = [];
  $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
  foreach ($groupIds as $gid) {
    $ledgers[$gid] = ['expenses' => [], 'settlements' => []];
  }
  if (empty($groupIds)) {
    return $ledgers;
  }
  $db = getDB();
  $in = inPlaceholders($groupIds);

  $expenses = $db->fetchAll("SELECT id, group_id, paid_by, amount FROM expenses WHERE group_id IN ($in)", $groupIds);
  $byId = [];
  foreach ($expenses as $e) {
    $byId[(int) $e['id']] = [
      'id' => (int) $e['id'],
      'group_id' => (int) $e['group_id'],
      'paid_by' => (int) $e['paid_by'],
      'amount' => toPaise($e['amount']),
      'splits' => [],
    ];
  }
  if (!empty($byId)) {
    $ids = array_keys($byId);
    $splits = $db->fetchAll('SELECT expense_id, user_id, amount FROM expense_splits WHERE expense_id IN (' . inPlaceholders($ids) . ')', $ids);
    foreach ($splits as $s) {
      $eid = (int) $s['expense_id'];
      $uid = (int) $s['user_id'];
      $byId[$eid]['splits'][$uid] = ($byId[$eid]['splits'][$uid] ?? 0) + toPaise($s['amount']);
    }
  }
  foreach ($byId as $e) {
    $ledgers[$e['group_id']]['expenses'][] = $e;
  }

  $settlements = $db->fetchAll("SELECT group_id, from_user_id, to_user_id, amount FROM settlements WHERE group_id IN ($in)", $groupIds);
  foreach ($settlements as $s) {
    $ledgers[(int) $s['group_id']]['settlements'][] = [
      'from' => (int) $s['from_user_id'],
      'to' => (int) $s['to_user_id'],
      'amount' => toPaise($s['amount']),
    ];
  }
  return $ledgers;
}

/** @return array<int,array<int,array>> group_id => user_id => member (with user fields), every status */
function loadGroupMembers(array $groupIds): array
{
  $out = [];
  $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
  if (empty($groupIds)) {
    return $out;
  }
  $rows = getDB()->fetchAll(
    'SELECT gm.group_id, gm.role, gm.status, gm.joined_at, u.id, u.name, u.email, u.profile_picture, u.deleted_at
       FROM group_members gm INNER JOIN users u ON u.id = gm.user_id
      WHERE gm.group_id IN (' . inPlaceholders($groupIds) . ')
      ORDER BY u.name ASC',
    $groupIds
  );
  foreach ($rows as $r) {
    $out[(int) $r['group_id']][(int) $r['id']] = [
      'user_id' => (int) $r['id'],
      'name' => $r['name'],
      'email' => $r['email'],
      'profile_picture' => $r['profile_picture'],
      'role' => $r['role'],
      'status' => $r['status'],
      'joined_at' => $r['joined_at'],
      'is_deleted' => $r['deleted_at'] !== null,
    ];
  }
  return $out;
}

/**
 * Expenses in API shape, newest first. $where is a SQL fragment over alias `e`.
 * Each row carries the viewer's impact ("my_net", rupees) so lists can say lent/borrowed.
 */
function loadExpenses(string $where, array $params, int $viewerId, ?int $limit = null): array
{
  $db = getDB();
  $sql = "SELECT e.*, u.name AS paid_by_name, u.profile_picture AS paid_by_picture,
                 cu.name AS created_by_name, uu.name AS updated_by_name,
                 g.name AS group_name, g.is_direct AS group_is_direct
            FROM expenses e
            INNER JOIN users u ON u.id = e.paid_by
            INNER JOIN expense_groups g ON g.id = e.group_id
            LEFT JOIN users cu ON cu.id = e.created_by
            LEFT JOIN users uu ON uu.id = e.updated_by
           WHERE $where
           ORDER BY e.date DESC, e.created_at DESC, e.id DESC";
  if ($limit !== null) {
    $sql .= ' LIMIT ' . (int) $limit;
  }
  $rows = $db->fetchAll($sql, $params);
  if (empty($rows)) {
    return [];
  }

  $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
  $in = inPlaceholders($ids);
  $splitRows = $db->fetchAll(
    "SELECT es.expense_id, es.user_id, es.amount, es.percentage, es.shares, es.adjustment, u.name AS user_name, u.profile_picture
       FROM expense_splits es INNER JOIN users u ON u.id = es.user_id
      WHERE es.expense_id IN ($in) ORDER BY es.id ASC",
    $ids
  );
  $splitsBy = [];
  foreach ($splitRows as $s) {
    $splitsBy[(int) $s['expense_id']][] = $s;
  }
  $commentCounts = [];
  foreach ($db->fetchAll("SELECT expense_id, COUNT(*) AS n FROM expense_comments WHERE expense_id IN ($in) GROUP BY expense_id", $ids) as $c) {
    $commentCounts[(int) $c['expense_id']] = (int) $c['n'];
  }

  $out = [];
  foreach ($rows as $r) {
    $id = (int) $r['id'];
    $splits = [];
    $engine = ['paid_by' => (int) $r['paid_by'], 'amount' => toPaise($r['amount']), 'splits' => []];
    foreach ($splitsBy[$id] ?? [] as $s) {
      $uid = (int) $s['user_id'];
      $engine['splits'][$uid] = ($engine['splits'][$uid] ?? 0) + toPaise($s['amount']);
      $splits[] = [
        'user_id' => $uid,
        'user_name' => $s['user_name'],
        'profile_picture' => $s['profile_picture'],
        'amount' => (float) $s['amount'],
        'percentage' => $s['percentage'] !== null ? (float) $s['percentage'] : null,
        'shares' => $s['shares'] !== null ? (float) $s['shares'] : null,
        'adjustment' => $s['adjustment'] !== null ? (float) $s['adjustment'] : null,
      ];
    }
    $out[] = [
      'id' => $id,
      'group_id' => (int) $r['group_id'],
      'group_name' => $r['group_name'],
      'group_is_direct' => (bool) $r['group_is_direct'],
      'description' => $r['description'],
      'amount' => (float) $r['amount'],
      'currency' => $r['currency'] ?: 'INR',
      'category' => $r['category'] ?: 'general',
      'paid_by' => (int) $r['paid_by'],
      'paid_by_name' => $r['paid_by_name'],
      'paid_by_picture' => $r['paid_by_picture'],
      'created_by' => $r['created_by'] !== null ? (int) $r['created_by'] : null,
      'created_by_name' => $r['created_by_name'],
      'updated_by' => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
      'updated_by_name' => $r['updated_by_name'],
      'split_type' => $r['split_type'] ?: 'equal',
      'date' => $r['date'],
      'notes' => $r['notes'],
      'has_receipt' => !empty($r['receipt_url']),
      'comment_count' => $commentCounts[$id] ?? 0,
      'created_at' => $r['created_at'],
      'updated_at' => $r['updated_at'],
      'my_net' => fromPaise(expenseNetFor($engine, $viewerId)),
      'splits' => $splits,
    ];
  }
  return $out;
}

/** The engine view (paise) of one stored expense, for activity snapshots. */
function expenseEngineRow(int $expenseId): ?array
{
  $db = getDB();
  $e = $db->fetchOne('SELECT id, group_id, paid_by, amount, description, category FROM expenses WHERE id = ?', [$expenseId]);
  if (!$e) {
    return null;
  }
  $row = [
    'id' => (int) $e['id'],
    'group_id' => (int) $e['group_id'],
    'paid_by' => (int) $e['paid_by'],
    'amount' => toPaise($e['amount']),
    'description' => $e['description'],
    'category' => $e['category'],
    'splits' => [],
  ];
  foreach ($db->fetchAll('SELECT user_id, amount FROM expense_splits WHERE expense_id = ?', [$expenseId]) as $s) {
    $row['splits'][(int) $s['user_id']] = ($row['splits'][(int) $s['user_id']] ?? 0) + toPaise($s['amount']);
  }
  return $row;
}

/** Every group (active membership) the user belongs to, including hidden 1:1 groups. */
function activeGroupIdsFor(int $userId): array
{
  $rows = getDB()->fetchAll("SELECT group_id FROM group_members WHERE user_id = ? AND status = 'active'", [$userId]);
  return array_map(function ($r) { return (int) $r['group_id']; }, $rows);
}
