<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/ledger.php';
require_once __DIR__ . '/../utils/activity.php';
require_once __DIR__ . '/../utils/push.php';
require_once __DIR__ . '/receiptController.php';
require_once __DIR__ . '/commentController.php';

function handleExpenseRoutes($uri, $method)
{
  $parts = explode('/', trim($uri, '/'));
  $n = count($parts);

  // /expenses/expense/{id}[/comments|/receipt]
  if ($n >= 3 && $parts[1] === 'expense' && is_numeric($parts[2])) {
    $expenseId = (int) $parts[2];
    $sub = $parts[3] ?? null;
    if ($sub === null && $method === 'GET') {
      return getExpense($expenseId);
    }
    if ($sub === 'comments' && $method === 'GET') {
      return listComments($expenseId);
    }
    if ($sub === 'comments' && $method === 'POST') {
      return addComment($expenseId);
    }
    if ($sub === 'receipt' && $method === 'POST') {
      return uploadReceipt($expenseId);
    }
    if ($sub === 'receipt' && $method === 'GET') {
      return streamReceipt($expenseId);
    }
    if ($sub === 'receipt' && $method === 'DELETE') {
      return deleteReceipt($expenseId);
    }
  } elseif ($n === 2 && is_numeric($parts[1])) {
    $id = (int) $parts[1];
    if ($method === 'POST') {
      return createExpense($id);       // id = group
    }
    if ($method === 'GET') {
      return getGroupExpenses($id);    // id = group
    }
    if ($method === 'PUT') {
      return updateExpense($id);       // id = expense
    }
    if ($method === 'DELETE') {
      return deleteExpense($id);       // id = expense
    }
  }
  Response::error("Route not found: $method $uri", 404);
}

/** Members who can appear in a split: active and invited-but-not-joined (pending). */
function splittableMemberIds(int $groupId): array
{
  $rows = getDB()->fetchAll("SELECT user_id FROM group_members WHERE group_id = ? AND status IN ('active', 'pending')", [$groupId]);
  return array_map(function ($r) { return (int) $r['user_id']; }, $rows);
}

/**
 * Validates and computes the splits for a create/full-update request.
 * @return array [splitType, splits (computeSplits output)]
 */
function resolveSplits(array $input, int $groupId, int $totalPaise, int $paidBy): array
{
  [$type, $entries] = readSplitInput($input);
  $type = $type ?: 'equal';
  $allowed = splittableMemberIds($groupId);
  if ($entries === null) {
    // No participants named: everyone in the group (incl. people invited but not joined yet), equally
    if ($type !== 'equal') {
      Response::error('Splits are required for this split type', 400);
    }
    $entries = array_map(function ($uid) { return ['user_id' => $uid]; }, $allowed);
  }
  foreach ($entries as $e) {
    if (!in_array((int) $e['user_id'], $allowed, true)) {
      Response::error('Everyone in the split must be a member of this group', 400);
    }
  }
  try {
    return [$type, computeSplits($type, $totalPaise, $paidBy, $entries)];
  } catch (SplitError $e) {
    Response::error($e->getMessage(), 400);
  }
}

function writeSplits(PDO $conn, int $expenseId, array $splits): void
{
  $stmt = $conn->prepare('INSERT INTO expense_splits (expense_id, user_id, amount, percentage, shares, adjustment) VALUES (?, ?, ?, ?, ?, ?)');
  foreach ($splits as $s) {
    $stmt->execute([$expenseId, $s['user_id'], fromPaise($s['amount']), $s['percentage'], $s['shares'], $s['adjustment']]);
  }
}

/** Activity snapshot: what the expense does to each person (paise). */
function expenseMeta(array $engine): array
{
  return [
    'description' => $engine['description'],
    'amount' => $engine['amount'],
    'category' => $engine['category'],
    'paid_by' => $engine['paid_by'],
    'nets' => expenseNets($engine),
  ];
}

function groupDisplayName(int $groupId, int $forUserId): string
{
  $g = getDB()->fetchOne('SELECT name, is_direct FROM expense_groups WHERE id = ?', [$groupId]);
  if (!$g) {
    return '';
  }
  if (!$g['is_direct']) {
    return $g['name'];
  }
  $other = getDB()->fetchOne('SELECT u.name FROM group_members gm INNER JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? AND gm.user_id != ? LIMIT 1', [$groupId, $forUserId]);
  return $other ? $other['name'] : '';
}

/** Per-recipient push for an expense event. $nets = user_id => paise (from the snapshot). */
function pushExpenseEvent(string $type, int $actorId, int $groupId, ?int $expenseId, string $description, int $amountPaise, array $nets): void
{
  $actor = firstName(userName($actorId));
  $verb = ['expense_added' => 'added', 'expense_updated' => 'updated', 'expense_deleted' => 'deleted'][$type];
  $group = getDB()->fetchOne('SELECT is_direct FROM expense_groups WHERE id = ?', [$groupId]);
  $isDirect = $group && $group['is_direct'];
  foreach ($nets as $uid => $net) {
    if ((int) $uid === $actorId) {
      continue;
    }
    $groupName = groupDisplayName($groupId, (int) $uid);
    $impact = $type === 'expense_deleted' ? '' : ($net < 0 ? ' · you owe ' . formatInr(-$net) : ($net > 0 ? ' · you get back ' . formatInr($net) : ''));
    Push::toUser((int) $uid, [
      'type' => $type,
      'group_id' => $groupId,
      'expense_id' => $expenseId,
      'actor_id' => $actorId,
      'title' => $isDirect ? "$actor $verb \"$description\"" : "$actor $verb \"$description\" in $groupName",
      'body' => formatInr($amountPaise) . $impact,
    ]);
  }
}

function createExpense(int $groupId)
{
  $userId = requireUserId();
  $input = getJsonInput();
  requireActiveMember($groupId, $userId);

  $description = trim((string) ($input['description'] ?? ''));
  $amountPaise = toPaise($input['amount'] ?? 0);
  $date = $input['date'] ?? null;
  $category = $input['category'] ?? 'general';
  $notes = isset($input['notes']) && $input['notes'] !== '' ? $input['notes'] : null;
  $paidBy = (int) ($input['paid_by'] ?? ($input['paidBy'] ?? $userId));

  if ($description === '' || $amountPaise <= 0 || !$date) {
    Response::error('Description, amount, and date are required', 400);
  }
  if (!in_array($paidBy, splittableMemberIds($groupId), true)) {
    Response::error('The person who paid must be a member of this group', 400);
  }

  [$splitType, $splits] = resolveSplits($input, $groupId, $amountPaise, $paidBy);

  $conn = getDB()->getConnection();
  $conn->beginTransaction();
  try {
    $stmt = $conn->prepare('INSERT INTO expenses (group_id, description, amount, category, paid_by, created_by, split_type, date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$groupId, $description, fromPaise($amountPaise), $category, $paidBy, $userId, $splitType, $date, $notes]);
    $expenseId = (int) $conn->lastInsertId();
    writeSplits($conn, $expenseId, $splits);
    $conn->commit();
  } catch (Exception $e) {
    $conn->rollBack();
    Response::error('Failed to create expense: ' . $e->getMessage(), 500);
  }

  $engine = expenseEngineRow($expenseId);
  $meta = expenseMeta($engine);
  logActivity($groupId, $userId, 'create_expense', 'expense', $expenseId, "Added expense \"$description\" for " . formatInr($amountPaise), $meta);
  getDB()->execute('UPDATE expense_groups SET updated_at = NOW() WHERE id = ?', [$groupId]);
  pushExpenseEvent('expense_added', $userId, $groupId, $expenseId, $description, $amountPaise, $meta['nets']);

  $expense = loadExpenses('e.id = ?', [$expenseId], $userId)[0];
  // v1 clients read id/description/amount/category/date from the top level — those keys are all here
  Response::success($expense, 'Expense created successfully', 201);
}

function getGroupExpenses(int $groupId)
{
  $userId = requireUserId();
  requireActiveMember($groupId, $userId);
  Response::success(loadExpenses('e.group_id = ?', [$groupId], $userId));
}

function getExpense(int $expenseId)
{
  $userId = requireUserId();
  $row = getDB()->fetchOne('SELECT group_id FROM expenses WHERE id = ?', [$expenseId]);
  if (!$row) {
    Response::error('Expense not found', 404);
  }
  requireActiveMember((int) $row['group_id'], $userId);
  Response::success(loadExpenses('e.id = ?', [$expenseId], $userId)[0]);
}

/**
 * Any active member can edit. A request with paid_by / split_type / splits re-splits the expense
 * from scratch; a request with only a new amount (the v1 edit screen) rescales the existing split
 * in its own mode.
 */
function updateExpense(int $expenseId)
{
  $userId = requireUserId();
  $input = getJsonInput();
  $db = getDB();
  $expense = $db->fetchOne('SELECT * FROM expenses WHERE id = ?', [$expenseId]);
  if (!$expense) {
    Response::error('Expense not found', 404);
  }
  $groupId = (int) $expense['group_id'];
  requireActiveMember($groupId, $userId);
  $before = expenseEngineRow($expenseId);

  $fields = [];
  $values = [];
  foreach (['description', 'category', 'date', 'notes'] as $f) {
    if (array_key_exists($f, $input)) {
      $fields[] = "$f = ?";
      $values[] = $f === 'description' ? trim((string) $input[$f]) : ($input[$f] === '' ? null : $input[$f]);
    }
  }
  if (array_key_exists('description', $input) && trim((string) $input['description']) === '') {
    Response::error('Description can\'t be empty', 400);
  }

  $amountPaise = array_key_exists('amount', $input) ? toPaise($input['amount']) : toPaise($expense['amount']);
  if ($amountPaise <= 0) {
    Response::error('Amount must be greater than 0', 400);
  }
  $paidBy = (int) ($input['paid_by'] ?? ($input['paidBy'] ?? $expense['paid_by']));
  $fullResplit = isset($input['paid_by']) || isset($input['paidBy']) || isset($input['split_type']) || isset($input['splitType']) || isset($input['splits']);
  $newSplits = null;
  $splitType = $expense['split_type'];

  if ($fullResplit) {
    if (!in_array($paidBy, splittableMemberIds($groupId), true)) {
      Response::error('The person who paid must be a member of this group', 400);
    }
    if (!isset($input['split_type']) && !isset($input['splitType'])) {
      $input['split_type'] = $expense['split_type'];
    }
    if (!isset($input['splits'])) {
      $input['splits'] = storedSplitEntries($expenseId);
    }
    [$splitType, $newSplits] = resolveSplits($input, $groupId, $amountPaise, $paidBy);
  } elseif ($amountPaise !== toPaise($expense['amount'])) {
    $newSplits = rescaleSplits($expense, $expenseId, $amountPaise);
  }

  if (empty($fields) && $newSplits === null) {
    Response::error('No fields to update', 400);
  }

  $conn = $db->getConnection();
  $conn->beginTransaction();
  try {
    $fields[] = 'amount = ?';
    $values[] = fromPaise($amountPaise);
    $fields[] = 'paid_by = ?';
    $values[] = $paidBy;
    $fields[] = 'split_type = ?';
    $values[] = $splitType;
    $fields[] = 'updated_by = ?';
    $values[] = $userId;
    $values[] = $expenseId;
    $conn->prepare('UPDATE expenses SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
    if ($newSplits !== null) {
      $conn->prepare('DELETE FROM expense_splits WHERE expense_id = ?')->execute([$expenseId]);
      writeSplits($conn, $expenseId, $newSplits);
    }
    $conn->commit();
  } catch (Exception $e) {
    $conn->rollBack();
    Response::error('Failed to update expense: ' . $e->getMessage(), 500);
  }

  $after = expenseEngineRow($expenseId);
  $meta = expenseMeta($after);
  $meta['before'] = ['amount' => $before['amount'], 'description' => $before['description'], 'nets' => expenseNets($before)];
  logActivity($groupId, $userId, 'update_expense', 'expense', $expenseId, "Updated expense \"{$after['description']}\"", $meta);
  getDB()->execute('UPDATE expense_groups SET updated_at = NOW() WHERE id = ?', [$groupId]);
  // Tell everyone involved before or after the edit
  $notify = $meta['nets'] + array_map(function () { return 0; }, $meta['before']['nets']);
  pushExpenseEvent('expense_updated', $userId, $groupId, $expenseId, $after['description'], $after['amount'], $notify);

  $updated = loadExpenses('e.id = ?', [$expenseId], $userId)[0];
  // v1 clients read data.expense
  Response::success(array_merge($updated, ['message' => 'Expense updated successfully', 'expense' => $updated]));
}

/** The stored split as computeSplits() input, so a payer-only change keeps the same split. */
function storedSplitEntries(int $expenseId): array
{
  $rows = getDB()->fetchAll('SELECT user_id, amount, percentage, shares, adjustment FROM expense_splits WHERE expense_id = ? ORDER BY user_id', [$expenseId]);
  return array_map(function ($r) {
    return [
      'user_id' => (int) $r['user_id'],
      'amount' => (float) $r['amount'],
      'percentage' => $r['percentage'] !== null ? (float) $r['percentage'] : null,
      'shares' => $r['shares'] !== null ? (float) $r['shares'] : null,
      'adjustment' => $r['adjustment'] !== null ? (float) $r['adjustment'] : null,
    ];
  }, $rows);
}

/** New amount, same split: equal/percentage/shares/adjustment recompute; exact amounts scale proportionally. */
function rescaleSplits(array $expense, int $expenseId, int $amountPaise): array
{
  $entries = storedSplitEntries($expenseId);
  $type = $expense['split_type'] ?: 'equal';
  if ($type === 'unequal') {
    $old = toPaise($expense['amount']);
    $scaled = [];
    foreach ($entries as $e) {
      $scaled[] = ['user_id' => $e['user_id'], 'amount' => $old > 0 ? round($e['amount'] * $amountPaise / $old, 2) : 0];
    }
    $entries = $scaled;
    // Proportional rounding can be off by more than a paisa; spread it like any remainder
    $sum = array_sum(array_map(function ($e) { return toPaise($e['amount']); }, $entries));
    $amounts = [];
    foreach ($entries as $e) {
      $amounts[$e['user_id']] = toPaise($e['amount']);
    }
    distributeRemainder($amounts, $amountPaise - $sum, (int) $expense['paid_by']);
    $entries = [];
    foreach ($amounts as $uid => $p) {
      $entries[] = ['user_id' => $uid, 'amount' => fromPaise($p)];
    }
  } elseif ($type === 'percentage' && array_sum(array_map(function ($e) { return (float) $e['percentage']; }, $entries)) < 99.99) {
    // Old rows without usable percentages: fall back to equal
    $type = 'equal';
  }
  try {
    return computeSplits($type, $amountPaise, (int) $expense['paid_by'], $entries);
  } catch (SplitError $e) {
    return computeSplits('equal', $amountPaise, (int) $expense['paid_by'], array_map(function ($e) { return ['user_id' => $e['user_id']]; }, $entries));
  }
}

function deleteExpense(int $expenseId)
{
  $userId = requireUserId();
  $db = getDB();
  $expense = $db->fetchOne('SELECT * FROM expenses WHERE id = ?', [$expenseId]);
  if (!$expense) {
    Response::error('Expense not found', 404);
  }
  $groupId = (int) $expense['group_id'];
  requireActiveMember($groupId, $userId);

  $engine = expenseEngineRow($expenseId);
  $meta = expenseMeta($engine);
  removeReceiptFile($expense['receipt_url']);
  $db->execute('DELETE FROM expenses WHERE id = ?', [$expenseId]);

  logActivity($groupId, $userId, 'delete_expense', 'expense', $expenseId, "Deleted expense \"{$engine['description']}\"", $meta);
  getDB()->execute('UPDATE expense_groups SET updated_at = NOW() WHERE id = ?', [$groupId]);
  pushExpenseEvent('expense_deleted', $userId, $groupId, null, $engine['description'], $engine['amount'], $meta['nets']);

  Response::success(['message' => 'Expense deleted successfully']);
}
