<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';

function handleBalanceRoutes($uri, $method)
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  $userId = $tokenData['userId'];
  $parts = explode('/', trim($uri, '/'));

  // Route handling
  if ($method === 'GET' && count($parts) == 2 && $parts[1] === 'my-balances') {
    // GET /balances/my-balances
    getMyBalances($userId);
  } elseif ($method === 'GET' && count($parts) == 2 && is_numeric($parts[1])) {
    // GET /balances/{groupId}
    $groupId = $parts[1];
    getGroupBalances($groupId, $userId);
  } elseif ($method === 'GET' && count($parts) == 4 && $parts[2] === 'settlements' && $parts[3] === 'suggestions') {
    // GET /balances/{groupId}/settlements/suggestions
    $groupId = $parts[1];
    getSettlementSuggestions($groupId, $userId);
  } elseif ($method === 'GET' && count($parts) == 4 && $parts[2] === 'settlements' && $parts[3] === 'history') {
    // GET /balances/{groupId}/settlements/history
    $groupId = $parts[1];
    getSettlementHistory($groupId, $userId);
  } elseif ($method === 'POST' && count($parts) == 3 && $parts[2] === 'settlements') {
    // POST /balances/{groupId}/settlements
    $groupId = $parts[1];
    recordSettlement($groupId, $userId);
  } elseif ($method === 'GET' && count($parts) == 3 && $parts[2] === 'activity') {
    // GET /balances/{groupId}/activity
    $groupId = $parts[1];
    getGroupActivity($groupId, $userId);
  } else {
    Response::error("Route not found: $method $uri", 404);
  }
}

function getMyBalances($userId)
{
  try {
    $db = getDB()->getConnection();

    // Get all groups the user is part of with balances
    $stmt = $db->prepare("
      SELECT 
        g.id as group_id,
        g.name as group_name,
        g.category,
        g.image,
        COUNT(DISTINCT gm.user_id) as member_count
      FROM expense_groups g
      INNER JOIN group_members gm ON g.id = gm.group_id
      WHERE gm.user_id = ?
      GROUP BY g.id, g.name, g.category, g.image
      ORDER BY g.updated_at DESC
    ");
    $stmt->execute([$userId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];

    foreach ($groups as $group) {
      $groupId = $group['group_id'];

      // Get user's balance in this group
      $stmt = $db->prepare("
        SELECT 
          COALESCE(SUM(CASE WHEN e.paid_by = ? THEN e.amount ELSE 0 END), 0) as total_paid,
          COALESCE(SUM(CASE WHEN es.user_id = ? THEN es.amount ELSE 0 END), 0) as total_owed
        FROM expenses e
        LEFT JOIN expense_splits es ON e.id = es.expense_id
        WHERE e.group_id = ?
      ");
      $stmt->execute([$userId, $userId, $groupId]);
      $balance = $stmt->fetch(PDO::FETCH_ASSOC);

      $totalPaid = floatval($balance['total_paid']);
      $totalOwed = floatval($balance['total_owed']);
      $netBalance = $totalPaid - $totalOwed;

      $result[] = [
        'group_id' => (int) $groupId,
        'group_name' => $group['group_name'],
        'category' => $group['category'],
        'image' => $group['image'],
        'member_count' => (int) $group['member_count'],
        'balance' => $netBalance,
        'total_paid' => $totalPaid,
        'total_owed' => $totalOwed
      ];
    }

    Response::success($result);
  } catch (Exception $e) {
    Response::error('Failed to fetch balances: ' . $e->getMessage(), 500);
  }
}

function getGroupBalances($groupId, $userId)
{
  try {
    $db = getDB()->getConnection();

    // Check if user is member
    $stmt = $db->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    if (!$stmt->fetch()) {
      Response::error('You are not a member of this group', 403);
    }

    // Get balances with user info
    $stmt = $db->prepare("
      SELECT 
        u.id,
        u.name,
        u.email,
        u.profile_picture,
        COALESCE(SUM(CASE WHEN e.paid_by = u.id THEN e.amount ELSE 0 END), 0) as total_paid,
        COALESCE(SUM(CASE WHEN es.user_id = u.id THEN es.amount ELSE 0 END), 0) as total_owed
      FROM users u
      INNER JOIN group_members gm ON u.id = gm.user_id
      LEFT JOIN expenses e ON e.group_id = gm.group_id AND e.paid_by = u.id
      LEFT JOIN expense_splits es ON es.expense_id = e.id AND es.user_id = u.id
      WHERE gm.group_id = ?
      GROUP BY u.id, u.name, u.email, u.profile_picture
      ORDER BY (COALESCE(SUM(CASE WHEN e.paid_by = u.id THEN e.amount ELSE 0 END), 0) - 
                COALESCE(SUM(CASE WHEN es.user_id = u.id THEN es.amount ELSE 0 END), 0)) DESC
    ");
    $stmt->execute([$groupId]);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = array_map(function ($b) {
      $totalPaid = floatval($b['total_paid']);
      $totalOwed = floatval($b['total_owed']);
      return [
        'user_id' => (int) $b['id'],
        'name' => $b['name'],
        'email' => $b['email'],
        'profile_picture' => $b['profile_picture'],
        'total_paid' => $totalPaid,
        'total_owed' => $totalOwed,
        'balance' => $totalPaid - $totalOwed
      ];
    }, $balances);

    Response::success($result);
  } catch (Exception $e) {
    Response::error('Failed to fetch group balances: ' . $e->getMessage(), 500);
  }
}

function getSettlementSuggestions($groupId, $userId)
{
  try {
    $db = getDB()->getConnection();

    // Check if user is member
    $stmt = $db->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    if (!$stmt->fetch()) {
      Response::error('You are not a member of this group', 403);
    }

    // Get all members' balances
    $stmt = $db->prepare("
      SELECT 
        u.id,
        u.name,
        COALESCE(SUM(CASE WHEN e.paid_by = u.id THEN e.amount ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN es.user_id = u.id THEN es.amount ELSE 0 END), 0) as balance
      FROM users u
      INNER JOIN group_members gm ON u.id = gm.user_id
      LEFT JOIN expenses e ON e.group_id = gm.group_id
      LEFT JOIN expense_splits es ON es.expense_id = e.id AND es.user_id = u.id
      WHERE gm.group_id = ?
      GROUP BY u.id, u.name
      HAVING balance != 0
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Separate debtors and creditors
    $debtors = [];
    $creditors = [];

    foreach ($members as $member) {
      $balance = floatval($member['balance']);
      if ($balance < 0) {
        $debtors[] = ['id' => (int) $member['id'], 'name' => $member['name'], 'amount' => abs($balance)];
      } elseif ($balance > 0) {
        $creditors[] = ['id' => (int) $member['id'], 'name' => $member['name'], 'amount' => $balance];
      }
    }

    // Calculate settlement suggestions
    $suggestions = [];
    $i = 0;
    $j = 0;

    while ($i < count($debtors) && $j < count($creditors)) {
      $debt = $debtors[$i]['amount'];
      $credit = $creditors[$j]['amount'];
      $amount = min($debt, $credit);

      if ($amount > 0.01) {
        $suggestions[] = [
          'from_user_id' => $debtors[$i]['id'],
          'from_user_name' => $debtors[$i]['name'],
          'to_user_id' => $creditors[$j]['id'],
          'to_user_name' => $creditors[$j]['name'],
          'amount' => round($amount, 2)
        ];
      }

      $debtors[$i]['amount'] -= $amount;
      $creditors[$j]['amount'] -= $amount;

      if ($debtors[$i]['amount'] < 0.01)
        $i++;
      if ($creditors[$j]['amount'] < 0.01)
        $j++;
    }

    Response::success($suggestions);
  } catch (Exception $e) {
    Response::error('Failed to fetch settlement suggestions: ' . $e->getMessage(), 500);
  }
}

function getSettlementHistory($groupId, $userId)
{
  try {
    $db = getDB()->getConnection();

    // Check if user is member
    $stmt = $db->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    if (!$stmt->fetch()) {
      Response::error('You are not a member of this group', 403);
    }

    // Get settlement history
    $stmt = $db->prepare("
      SELECT 
        s.*,
        u1.name as from_user_name,
        u2.name as to_user_name
      FROM settlements s
      INNER JOIN users u1 ON s.from_user_id = u1.id
      INNER JOIN users u2 ON s.to_user_id = u2.id
      WHERE s.group_id = ?
      ORDER BY s.date DESC, s.created_at DESC
    ");
    $stmt->execute([$groupId]);
    $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = array_map(function ($s) {
      return [
        'id' => (int) $s['id'],
        'from_user_id' => (int) $s['from_user_id'],
        'from_user_name' => $s['from_user_name'],
        'to_user_id' => (int) $s['to_user_id'],
        'to_user_name' => $s['to_user_name'],
        'amount' => floatval($s['amount']),
        'date' => $s['date'],
        'notes' => $s['notes'],
        'created_at' => $s['created_at']
      ];
    }, $settlements);

    Response::success($result);
  } catch (Exception $e) {
    Response::error('Failed to fetch settlement history: ' . $e->getMessage(), 500);
  }
}

function recordSettlement($groupId, $userId)
{
  $input = getJsonInput();

  $toUserId = $input['toUserId'] ?? null;
  $amount = $input['amount'] ?? null;
  $date = $input['date'] ?? null;
  $notes = $input['notes'] ?? null;

  if (!$toUserId || !$amount || !$date) {
    Response::error('To user, amount, and date are required', 400);
  }

  if ($amount <= 0) {
    Response::error('Amount must be greater than 0', 400);
  }

  try {
    $db = getDB()->getConnection();

    // Check if user is member
    $stmt = $db->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    if (!$stmt->fetch()) {
      Response::error('You are not a member of this group', 403);
    }

    // Record settlement
    $stmt = $db->prepare('
      INSERT INTO settlements (group_id, from_user_id, to_user_id, amount, date, notes)
      VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$groupId, $userId, $toUserId, $amount, $date, $notes]);
    $settlementId = $db->lastInsertId();

    // Log activity
    $stmt = $db->prepare('
      INSERT INTO activities (group_id, user_id, action, entity_type, entity_id, description)
      VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
      $groupId,
      $userId,
      'record_settlement',
      'settlement',
      $settlementId,
      "Recorded settlement of ₹$amount"
    ]);

    Response::success([
      'id' => (int) $settlementId,
      'message' => 'Settlement recorded successfully'
    ], 'Settlement recorded successfully', 201);

  } catch (Exception $e) {
    Response::error('Failed to record settlement: ' . $e->getMessage(), 500);
  }
}

function getGroupActivity($groupId, $userId)
{
  try {
    $db = getDB()->getConnection();

    // Check if user is member
    $stmt = $db->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    if (!$stmt->fetch()) {
      Response::error('You are not a member of this group', 403);
    }

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

    // Get activity
    $stmt = $db->prepare("
      SELECT 
        a.*,
        u.name as user_name,
        u.profile_picture
      FROM activities a
      INNER JOIN users u ON a.user_id = u.id
      WHERE a.group_id = ?
      ORDER BY a.created_at DESC
      LIMIT ?
    ");
    $stmt->execute([$groupId, $limit]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = array_map(function ($a) {
      return [
        'id' => (int) $a['id'],
        'user_id' => (int) $a['user_id'],
        'user_name' => $a['user_name'],
        'profile_picture' => $a['profile_picture'],
        'action' => $a['action'],
        'entity_type' => $a['entity_type'],
        'entity_id' => $a['entity_id'] ? (int) $a['entity_id'] : null,
        'description' => $a['description'],
        'created_at' => $a['created_at']
      ];
    }, $activities);

    Response::success($result);
  } catch (Exception $e) {
    Response::error('Failed to fetch activity: ' . $e->getMessage(), 500);
  }
}
