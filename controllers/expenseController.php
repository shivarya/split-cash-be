<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';

function handleExpenseRoutes($uri, $method)
{
  // Parse URI: /expenses/{groupId} or /expenses/{expenseId}
  $parts = explode('/', trim($uri, '/'));

  if (count($parts) >= 2 && $method === 'POST') {
    // POST /expenses/{groupId}
    $groupId = $parts[1];
    createExpense($groupId);
  } elseif (count($parts) >= 2 && $method === 'GET') {
    // GET /expenses/{groupId}
    $groupId = $parts[1];
    getGroupExpenses($groupId);
  } elseif (count($parts) >= 2 && $method === 'PUT') {
    // PUT /expenses/{expenseId}
    $expenseId = $parts[1];
    updateExpense($expenseId);
  } elseif (count($parts) >= 2 && $method === 'DELETE') {
    // DELETE /expenses/{expenseId}
    $expenseId = $parts[1];
    deleteExpense($expenseId);
  } else {
    Response::error("Route not found: $method $uri", 404);
  }
}

function createExpense($groupId)
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  $userId = $tokenData['userId'];
  $input = getJsonInput();

  $description = $input['description'] ?? null;
  $amount = $input['amount'] ?? null;
  $category = $input['category'] ?? 'general';
  $date = $input['date'] ?? null;
  $notes = $input['notes'] ?? null;
  $splitType = $input['splitType'] ?? 'equal';
  $splits = $input['splits'] ?? [];

  // Validate input
  if (!$description || !$amount || !$date) {
    Response::error('Description, amount, and date are required', 400);
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

    // Get group members
    $stmt = $db->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $memberIds = array_column($members, 'user_id');

    // Calculate splits
    $expenseSplits = [];

    if ($splitType === 'equal' || !$splitType) {
      $splitAmount = round($amount / count($memberIds), 2);
      foreach ($memberIds as $memberId) {
        $expenseSplits[] = [
          'userId' => $memberId,
          'amount' => $splitAmount,
          'percentage' => round(100 / count($memberIds), 2)
        ];
      }
    } elseif ($splitType === 'unequal') {
      if (empty($splits)) {
        Response::error('Splits are required for unequal split type', 400);
      }
      $totalSplit = array_sum(array_column($splits, 'amount'));
      if (abs($totalSplit - $amount) > 0.01) {
        Response::error('Split amounts must equal total amount', 400);
      }
      foreach ($splits as $split) {
        $expenseSplits[] = [
          'userId' => $split['userId'],
          'amount' => floatval($split['amount']),
          'percentage' => round(($split['amount'] / $amount) * 100, 2)
        ];
      }
    } elseif ($splitType === 'percentage') {
      if (empty($splits)) {
        Response::error('Splits are required for percentage split type', 400);
      }
      $totalPercentage = array_sum(array_column($splits, 'percentage'));
      if (abs($totalPercentage - 100) > 0.01) {
        Response::error('Percentages must total 100%', 400);
      }
      foreach ($splits as $split) {
        $expenseSplits[] = [
          'userId' => $split['userId'],
          'amount' => round(($amount * $split['percentage']) / 100, 2),
          'percentage' => floatval($split['percentage'])
        ];
      }
    }

    // Start transaction
    $db->beginTransaction();

    try {
      // Insert expense
      $stmt = $db->prepare('
        INSERT INTO expenses (group_id, description, amount, category, paid_by, split_type, date, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ');
      $stmt->execute([$groupId, $description, $amount, $category, $userId, $splitType, $date, $notes]);
      $expenseId = $db->lastInsertId();

      // Insert splits
      $stmt = $db->prepare('
        INSERT INTO expense_splits (expense_id, user_id, amount, percentage)
        VALUES (?, ?, ?, ?)
      ');
      foreach ($expenseSplits as $split) {
        $stmt->execute([$expenseId, $split['userId'], $split['amount'], $split['percentage']]);
      }

      // Log activity
      $stmt = $db->prepare('
        INSERT INTO activities (group_id, user_id, action, entity_type, entity_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
      ');
      $stmt->execute([
        $groupId,
        $userId,
        'create_expense',
        'expense',
        $expenseId,
        "Added expense \"$description\" for ₹$amount"
      ]);

      $db->commit();

      Response::success([
        'id' => (int) $expenseId,
        'description' => $description,
        'amount' => floatval($amount),
        'category' => $category,
        'date' => $date
      ], 'Expense created successfully', 201);

    } catch (Exception $e) {
      $db->rollBack();
      throw $e;
    }

  } catch (Exception $e) {
    Response::error('Failed to create expense: ' . $e->getMessage(), 500);
  }
}

function getGroupExpenses($groupId)
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  $userId = $tokenData['userId'];

  try {
    $db = getDB()->getConnection();

    // Check if user is member
    $stmt = $db->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    if (!$stmt->fetch()) {
      Response::error('You are not a member of this group', 403);
    }

    // Get expenses
    $stmt = $db->prepare('
      SELECT 
        e.*,
        u.name as paid_by_name,
        u.profile_picture as paid_by_picture
      FROM expenses e
      INNER JOIN users u ON e.paid_by = u.id
      WHERE e.group_id = ?
      ORDER BY e.date DESC, e.created_at DESC
    ');
    $stmt->execute([$groupId]);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get splits for each expense
    foreach ($expenses as &$expense) {
      $stmt = $db->prepare('
        SELECT 
          es.*,
          u.name as user_name
        FROM expense_splits es
        INNER JOIN users u ON es.user_id = u.id
        WHERE es.expense_id = ?
      ');
      $stmt->execute([$expense['id']]);
      $expense['splits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Convert numeric fields
      $expense['id'] = (int) $expense['id'];
      $expense['group_id'] = (int) $expense['group_id'];
      $expense['paid_by'] = (int) $expense['paid_by'];
      $expense['amount'] = floatval($expense['amount']);
    }

    Response::success($expenses);

  } catch (Exception $e) {
    Response::error('Failed to fetch expenses: ' . $e->getMessage(), 500);
  }
}

function updateExpense($expenseId)
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  $userId = $tokenData['userId'];
  $input = getJsonInput();

  try {
    $db = getDB()->getConnection();

    // Get expense
    $stmt = $db->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
      Response::error('Expense not found', 404);
    }

    // Check if user created the expense
    if ($expense['paid_by'] != $userId) {
      Response::error('Only the creator can update this expense', 403);
    }

    $updates = [];
    $values = [];

    if (isset($input['description'])) {
      $updates[] = 'description = ?';
      $values[] = $input['description'];
    }
    if (isset($input['amount'])) {
      $updates[] = 'amount = ?';
      $values[] = $input['amount'];
    }
    if (isset($input['category'])) {
      $updates[] = 'category = ?';
      $values[] = $input['category'];
    }
    if (isset($input['date'])) {
      $updates[] = 'date = ?';
      $values[] = $input['date'];
    }
    if (isset($input['notes'])) {
      $updates[] = 'notes = ?';
      $values[] = $input['notes'];
    }

    if (empty($updates)) {
      Response::error('No fields to update', 400);
    }

    $values[] = $expenseId;

    $stmt = $db->prepare('UPDATE expenses SET ' . implode(', ', $updates) . ' WHERE id = ?');
    $stmt->execute($values);

    Response::success(['message' => 'Expense updated successfully']);

  } catch (Exception $e) {
    Response::error('Failed to update expense: ' . $e->getMessage(), 500);
  }
}

function deleteExpense($expenseId)
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  $userId = $tokenData['userId'];

  try {
    $db = getDB()->getConnection();

    // Get expense
    $stmt = $db->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
      Response::error('Expense not found', 404);
    }

    // Check if user created the expense or is group admin
    $stmt = $db->prepare('
      SELECT role FROM group_members 
      WHERE group_id = ? AND user_id = ?
    ');
    $stmt->execute([$expense['group_id'], $userId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($expense['paid_by'] != $userId && (!$member || $member['role'] !== 'admin')) {
      Response::error('Only the creator or group admin can delete this expense', 403);
    }

    // Delete expense (splits will be cascade deleted)
    $stmt = $db->prepare('DELETE FROM expenses WHERE id = ?');
    $stmt->execute([$expenseId]);

    Response::success(['message' => 'Expense deleted successfully']);

  } catch (Exception $e) {
    Response::error('Failed to delete expense: ' . $e->getMessage(), 500);
  }
}
