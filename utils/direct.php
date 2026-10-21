<?php
/**
 * Non-group expenses (Splitwise's "expenses with a friend") are stored in a hidden 1:1 group
 * per pair of people: expense_groups.is_direct = 1, direct_key = "<lower id>-<higher id>".
 * Everything else (splits, payments, comments, receipts, balances) then works unchanged.
 */
require_once __DIR__ . '/../config/database.php';

function directKey(int $a, int $b): string
{
  return min($a, $b) . '-' . max($a, $b);
}

/** The pair's hidden group id, creating it on first use. */
function getOrCreateDirectGroup(int $creatorId, int $friendId): int
{
  $db = getDB();
  $key = directKey($creatorId, $friendId);
  $existing = $db->fetchOne('SELECT id FROM expense_groups WHERE direct_key = ?', [$key]);
  if ($existing) {
    return (int) $existing['id'];
  }

  $friend = $db->fetchOne('SELECT google_id FROM users WHERE id = ?', [$friendId]);
  $friendStatus = ($friend && $friend['google_id'] !== null) ? 'active' : 'pending';
  $conn = $db->getConnection();
  try {
    $conn->beginTransaction();
    $stmt = $conn->prepare("INSERT INTO expense_groups (name, description, category, simplify_debts, is_direct, direct_key, created_by) VALUES ('Non-group expenses', '', 'other', 0, 1, ?, ?)");
    $stmt->execute([$key, $creatorId]);
    $groupId = (int) $conn->lastInsertId();
    $conn->prepare("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'admin', 'active'), (?, ?, 'admin', ?)")
      ->execute([$groupId, $creatorId, $groupId, $friendId, $friendStatus]);
    $conn->commit();
    return $groupId;
  } catch (PDOException $e) {
    $conn->rollBack();
    // Lost a race with the other person's first non-group expense: use theirs
    $existing = $db->fetchOne('SELECT id FROM expense_groups WHERE direct_key = ?', [$key]);
    if ($existing) {
      return (int) $existing['id'];
    }
    throw $e;
  }
}
