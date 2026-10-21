<?php
/**
 * Builders for the native app's summary screens. Each runs a fixed handful of queries
 * (ledger + members for all of the user's groups at once) instead of one call per group.
 * Amounts in responses are rupees; a positive amount means the other person owes the viewer.
 */
require_once __DIR__ . '/ledger.php';

/** The other member of a hidden 1:1 group, from the viewer's side. */
function directCounterpart(array $members, int $viewerId): ?array
{
  foreach ($members as $uid => $m) {
    if ($uid !== $viewerId) {
      return $m;
    }
  }
  return null;
}

function memberSummary(array $m): array
{
  return [
    'user_id' => $m['user_id'],
    'name' => $m['name'],
    'email' => $m['email'],
    'profile_picture' => $m['profile_picture'],
    'status' => $m['status'],
    'is_deleted' => $m['is_deleted'],
  ];
}

/** Everything the Groups and Friends tabs need, in one response. */
function buildOverview(int $userId): array
{
  $db = getDB();
  $groups = $db->fetchAll(
    'SELECT g.id, g.name, g.description, g.category, g.image, g.is_direct, g.simplify_debts, g.created_at, g.updated_at,
            gm.status AS my_status, gm.role AS my_role
       FROM group_members gm INNER JOIN expense_groups g ON g.id = gm.group_id
      WHERE gm.user_id = ? AND gm.status IN ("active", "left")',
    [$userId]
  );
  $ids = array_map(function ($g) { return (int) $g['id']; }, $groups);
  $ledgers = loadLedgers($ids);
  $members = loadGroupMembers($ids);
  $lastActivity = [];
  if (!empty($ids)) {
    foreach ($db->fetchAll('SELECT group_id, MAX(created_at) AS t FROM activities WHERE group_id IN (' . inPlaceholders($ids) . ') GROUP BY group_id', $ids) as $r) {
      $lastActivity[(int) $r['group_id']] = $r['t'];
    }
  }

  $outGroups = [];
  $outLeft = [];
  $friends = []; // user_id => friend
  foreach ($groups as $g) {
    $gid = (int) $g['id'];
    $isDirect = (bool) $g['is_direct'];
    $simplify = (bool) $g['simplify_debts'];
    $ledger = $ledgers[$gid];
    $nets = ledgerNets($ledger);
    $myNet = $nets[$userId] ?? 0;
    $groupMembers = $members[$gid] ?? [];
    $counterpart = $isDirect ? directCounterpart($groupMembers, $userId) : null;
    $name = $isDirect ? ($counterpart['name'] ?? 'Unknown') : $g['name'];

    if ($g['my_status'] === 'left') {
      if (!$isDirect) {
        $outLeft[] = ['id' => $gid, 'name' => $name, 'category' => $g['category'], 'my_net' => fromPaise($myNet)];
      }
      continue;
    }

    $with = debtsWith(groupDebts($ledger, $simplify), $userId);
    $balances = [];
    foreach ($with as $otherId => $amt) {
      $m = $groupMembers[$otherId] ?? ['user_id' => $otherId, 'name' => userNameFallback($otherId), 'email' => null, 'profile_picture' => null, 'status' => 'left', 'is_deleted' => false];
      $balances[] = ['user_id' => $otherId, 'name' => $m['name'], 'profile_picture' => $m['profile_picture'], 'amount' => fromPaise($amt)];
    }
    usort($balances, function ($a, $b) { return abs($b['amount']) <=> abs($a['amount']); });

    $activeCount = 0;
    foreach ($groupMembers as $m) {
      if ($m['status'] === 'active') {
        $activeCount++;
      }
    }

    if (!$isDirect) {
      $outGroups[] = [
        'id' => $gid,
        'name' => $name,
        'description' => $g['description'],
        'category' => $g['category'] ?: 'general',
        'image' => $g['image'],
        'simplify_debts' => $simplify,
        'role' => $g['my_role'],
        'member_count' => $activeCount,
        'my_net' => fromPaise($myNet),
        'balances' => $balances,
        'created_at' => $g['created_at'],
        'last_activity_at' => $lastActivity[$gid] ?? $g['updated_at'],
      ];
    }

    // Everyone who shares a group with me is a friend, settled up or not
    foreach ($groupMembers as $otherId => $m) {
      if ($otherId === $userId || $m['status'] === 'left') {
        continue;
      }
      if (!isset($friends[$otherId])) {
        $friends[$otherId] = memberSummary($m) + ['net' => 0, 'groups' => [], 'direct_group_id' => null, 'last_activity_at' => null];
      }
      if ($isDirect) {
        $friends[$otherId]['direct_group_id'] = $gid;
      }
      $t = $lastActivity[$gid] ?? null;
      if ($t !== null && ($friends[$otherId]['last_activity_at'] === null || $t > $friends[$otherId]['last_activity_at'])) {
        $friends[$otherId]['last_activity_at'] = $t;
      }
    }
    foreach ($with as $otherId => $amt) {
      if (!isset($friends[$otherId])) {
        // A member who left still owes / is owed: keep them visible until settled
        $m = $groupMembers[$otherId] ?? ['user_id' => $otherId, 'name' => userNameFallback($otherId), 'email' => null, 'profile_picture' => null, 'status' => 'left', 'is_deleted' => false];
        $friends[$otherId] = memberSummary($m) + ['net' => 0, 'groups' => [], 'direct_group_id' => null, 'last_activity_at' => null];
      }
      $friends[$otherId]['net'] += $amt;
      $friends[$otherId]['groups'][] = [
        'group_id' => $gid,
        'group_name' => $isDirect ? null : $g['name'],
        'is_direct' => $isDirect,
        'amount' => fromPaise($amt),
      ];
    }
  }

  usort($outGroups, function ($a, $b) { return strcmp((string) $b['last_activity_at'], (string) $a['last_activity_at']); });

  $owed = 0;
  $owe = 0;
  $outFriends = [];
  foreach ($friends as $f) {
    if ($f['is_deleted'] && $f['net'] === 0) {
      continue;
    }
    if ($f['net'] > 0) {
      $owed += $f['net'];
    } elseif ($f['net'] < 0) {
      $owe -= $f['net'];
    }
    $f['net'] = fromPaise($f['net']);
    $outFriends[] = $f;
  }
  usort($outFriends, function ($a, $b) {
    $az = abs($a['net']) < 0.005;
    $bz = abs($b['net']) < 0.005;
    if ($az !== $bz) {
      return $az ? 1 : -1;
    }
    return strcasecmp($a['name'], $b['name']);
  });

  return [
    'overall' => ['net' => fromPaise($owed - $owe), 'owed' => fromPaise($owed), 'owe' => fromPaise($owe)],
    'groups' => $outGroups,
    'left_groups' => $outLeft,
    'friends' => $outFriends,
  ];
}

function userNameFallback(int $userId): string
{
  $row = getDB()->fetchOne('SELECT name FROM users WHERE id = ?', [$userId]);
  return $row ? $row['name'] : 'Unknown';
}

/** Group balances screen: every member's net plus who pays whom (simplified or raw, per the group setting). */
function buildGroupDebts(int $groupId): array
{
  $group = getDB()->fetchOne('SELECT id, simplify_debts FROM expense_groups WHERE id = ?', [$groupId]);
  $ledger = loadLedgers([$groupId])[$groupId];
  $members = loadGroupMembers([$groupId])[$groupId] ?? [];
  $simplify = (bool) $group['simplify_debts'];
  $nets = ledgerNets($ledger);
  $name = function ($uid) use ($members) { return $members[$uid]['name'] ?? userNameFallback($uid); };
  $pic = function ($uid) use ($members) { return $members[$uid]['profile_picture'] ?? null; };

  $outNets = [];
  foreach ($members as $uid => $m) {
    if ($m['status'] === 'left' && ($nets[$uid] ?? 0) === 0) {
      continue;
    }
    $outNets[] = ['user_id' => $uid, 'name' => $m['name'], 'profile_picture' => $m['profile_picture'], 'status' => $m['status'], 'balance' => fromPaise($nets[$uid] ?? 0)];
  }
  $outDebts = [];
  foreach (groupDebts($ledger, $simplify) as $d) {
    $outDebts[] = [
      'from_user_id' => $d['from'], 'from_user_name' => $name($d['from']), 'from_user_picture' => $pic($d['from']),
      'to_user_id' => $d['to'], 'to_user_name' => $name($d['to']), 'to_user_picture' => $pic($d['to']),
      'amount' => fromPaise($d['amount']),
    ];
  }
  return ['group_id' => $groupId, 'simplify_debts' => $simplify, 'nets' => $outNets, 'debts' => $outDebts];
}

/** Ids of groups (active for the viewer) that the friend is also in, any status. */
function sharedGroupIds(int $userId, int $friendId): array
{
  $rows = getDB()->fetchAll(
    'SELECT a.group_id FROM group_members a INNER JOIN group_members b ON b.group_id = a.group_id
      WHERE a.user_id = ? AND a.status = "active" AND b.user_id = ?',
    [$userId, $friendId]
  );
  return array_map(function ($r) { return (int) $r['group_id']; }, $rows);
}

/** Friend screen: balance, per-group breakdown, and the expenses + payments the two of you share. */
function buildFriendDetail(int $userId, int $friendId): ?array
{
  $groupIds = sharedGroupIds($userId, $friendId);
  if (empty($groupIds)) {
    return null;
  }
  $db = getDB();
  $friend = $db->fetchOne('SELECT id, name, email, profile_picture, google_id, deleted_at FROM users WHERE id = ?', [$friendId]);
  if (!$friend) {
    return null;
  }
  $overview = buildOverview($userId);
  $entry = null;
  foreach ($overview['friends'] as $f) {
    if ($f['user_id'] === $friendId) {
      $entry = $f;
    }
  }

  $in = inPlaceholders($groupIds);
  // Expenses where both of you are involved (one paid or is in the split, and so is the other)
  $expenses = loadExpenses(
    "e.group_id IN ($in)
     AND (e.paid_by = ? OR EXISTS (SELECT 1 FROM expense_splits s1 WHERE s1.expense_id = e.id AND s1.user_id = ?))
     AND (e.paid_by = ? OR EXISTS (SELECT 1 FROM expense_splits s2 WHERE s2.expense_id = e.id AND s2.user_id = ?))",
    array_merge($groupIds, [$userId, $userId, $friendId, $friendId]),
    $userId,
    200
  );
  foreach ($expenses as &$e) {
    $engine = ['paid_by' => $e['paid_by'], 'amount' => toPaise($e['amount']), 'splits' => []];
    foreach ($e['splits'] as $s) {
      $engine['splits'][$s['user_id']] = ($engine['splits'][$s['user_id']] ?? 0) + toPaise($s['amount']);
    }
    $e['with_friend'] = fromPaise(expensePairImpact($engine, $userId, $friendId));
  }
  unset($e);

  $payments = $db->fetchAll(
    "SELECT s.id, s.group_id, s.from_user_id, s.to_user_id, s.amount, s.date, s.notes, s.created_at, g.name AS group_name, g.is_direct
       FROM settlements s INNER JOIN expense_groups g ON g.id = s.group_id
      WHERE s.group_id IN ($in) AND ((s.from_user_id = ? AND s.to_user_id = ?) OR (s.from_user_id = ? AND s.to_user_id = ?))
      ORDER BY s.date DESC, s.created_at DESC LIMIT 200",
    array_merge($groupIds, [$userId, $friendId, $friendId, $userId])
  );
  $outPayments = array_map(function ($p) {
    return [
      'id' => (int) $p['id'],
      'group_id' => (int) $p['group_id'],
      'group_name' => $p['group_name'],
      'group_is_direct' => (bool) $p['is_direct'],
      'from_user_id' => (int) $p['from_user_id'],
      'to_user_id' => (int) $p['to_user_id'],
      'amount' => (float) $p['amount'],
      'date' => $p['date'],
      'notes' => $p['notes'],
      'created_at' => $p['created_at'],
    ];
  }, $payments);

  return [
    'friend' => [
      'user_id' => (int) $friend['id'],
      'name' => $friend['name'],
      'email' => $friend['deleted_at'] ? null : $friend['email'],
      'profile_picture' => $friend['profile_picture'],
      'is_registered' => $friend['google_id'] !== null,
      'is_deleted' => $friend['deleted_at'] !== null,
    ],
    'net' => $entry ? $entry['net'] : 0.0,
    'groups' => $entry ? $entry['groups'] : [],
    'direct_group_id' => $entry ? $entry['direct_group_id'] : null,
    'expenses' => $expenses,
    'payments' => $outPayments,
  ];
}
