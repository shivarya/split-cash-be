<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';

function handleBalanceRoutes($uri, $method)
{
  // Authenticate user
  $headers = getallheaders();
  $authHeader = $headers['Authorization'] ?? '';

  if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    Response::error('Unauthorized - No token provided', 401);
    return;
  }

  $token = $matches[1];
  $decoded = JWTHandler::verify($token);

  if (!$decoded) {
    Response::error('Unauthorized - Invalid token', 401);
    return;
  }

  $userId = $decoded['userId'];

  // Route handling
  if ($method === 'GET' && ($uri === '/balances' || $uri === '/balances/')) {
    getBalances($userId);
  } else {
    Response::error("Route not found: $method $uri", 404);
  }
}

function getBalances($userId)
{
  try {
    $db = getDB();

    // Get all groups the user is part of
    $stmt = $db->prepare("
      SELECT DISTINCT g.id, g.name 
      FROM groups g
      INNER JOIN group_members gm ON g.id = gm.group_id
      WHERE gm.user_id = ?
    ");
    $stmt->execute([$userId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $balances = [];

    foreach ($groups as $group) {
      $groupId = $group['id'];

      // Calculate balances for this group
      $stmt = $db->prepare("
        SELECT 
          u.id,
          u.email,
          u.name,
          COALESCE(SUM(CASE 
            WHEN e.paid_by = u.id THEN s.amount 
            ELSE -s.amount 
          END), 0) as balance
        FROM users u
        INNER JOIN group_members gm ON u.id = gm.user_id
        LEFT JOIN expenses e ON e.group_id = gm.group_id
        LEFT JOIN expense_splits s ON s.expense_id = e.id AND s.user_id = u.id
        WHERE gm.group_id = ?
        GROUP BY u.id, u.email, u.name
      ");
      $stmt->execute([$groupId]);
      $groupBalances = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $balances[] = [
        'group_id' => $groupId,
        'group_name' => $group['name'],
        'balances' => $groupBalances
      ];
    }

    Response::success($balances);
  } catch (Exception $e) {
    error_log("Balance fetch error: " . $e->getMessage());
    Response::error('Failed to fetch balances: ' . $e->getMessage(), 500);
  }
}
