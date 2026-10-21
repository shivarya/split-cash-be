<?php
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/activity.php';
require_once __DIR__ . '/../utils/push.php';

const COMMENT_MAX_CHARS = 1000;

/** DELETE /comments/{id} */
function handleCommentRoutes($uri, $method)
{
  $parts = explode('/', trim($uri, '/'));
  if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'DELETE') {
    return deleteComment((int) $parts[1]);
  }
  Response::error("Route not found: $method $uri", 404);
}

function commentExpense(int $expenseId, int $userId): array
{
  $expense = getDB()->fetchOne('SELECT id, group_id, description, paid_by FROM expenses WHERE id = ?', [$expenseId]);
  if (!$expense) {
    Response::error('Expense not found', 404);
  }
  requireActiveMember((int) $expense['group_id'], $userId);
  return $expense;
}

function commentRow(array $c): array
{
  return [
    'id' => (int) $c['id'],
    'expense_id' => (int) $c['expense_id'],
    'user_id' => (int) $c['user_id'],
    'user_name' => $c['user_name'],
    'profile_picture' => $c['profile_picture'],
    'body' => $c['body'],
    'created_at' => $c['created_at'],
  ];
}

function listComments(int $expenseId)
{
  $userId = requireUserId();
  commentExpense($expenseId, $userId);
  $rows = getDB()->fetchAll(
    'SELECT c.*, u.name AS user_name, u.profile_picture FROM expense_comments c INNER JOIN users u ON u.id = c.user_id
      WHERE c.expense_id = ? ORDER BY c.id ASC',
    [$expenseId]
  );
  Response::success(array_map('commentRow', $rows));
}

function addComment(int $expenseId)
{
  $userId = requireUserId();
  $expense = commentExpense($expenseId, $userId);
  $input = getJsonInput();
  $body = trim((string) ($input['body'] ?? ''));
  if ($body === '') {
    Response::error('Write something first', 400);
  }
  if (mb_strlen($body) > COMMENT_MAX_CHARS) {
    Response::error('Comments can be at most ' . COMMENT_MAX_CHARS . ' characters', 400);
  }

  $db = getDB();
  $id = (int) $db->insert('INSERT INTO expense_comments (expense_id, user_id, body) VALUES (?, ?, ?)', [$expenseId, $userId, $body]);
  $groupId = (int) $expense['group_id'];
  $preview = mb_strlen($body) > 140 ? mb_substr($body, 0, 139) . '…' : $body;
  logActivity($groupId, $userId, 'add_comment', 'expense', $expenseId, "Commented on \"{$expense['description']}\"", [
    'description' => $expense['description'],
    'comment_id' => $id,
    'body' => $preview,
  ]);

  // Everyone involved in the expense, plus anyone else who joined the conversation
  $recipients = [(int) $expense['paid_by']];
  foreach ($db->fetchAll('SELECT user_id FROM expense_splits WHERE expense_id = ?', [$expenseId]) as $r) {
    $recipients[] = (int) $r['user_id'];
  }
  foreach ($db->fetchAll('SELECT DISTINCT user_id FROM expense_comments WHERE expense_id = ?', [$expenseId]) as $r) {
    $recipients[] = (int) $r['user_id'];
  }
  $recipients = array_values(array_diff(array_unique($recipients), [$userId]));
  Push::toUsers($recipients, [
    'type' => 'comment_added',
    'group_id' => $groupId,
    'expense_id' => $expenseId,
    'actor_id' => $userId,
    'title' => firstName(userName($userId)) . " commented on \"{$expense['description']}\"",
    'body' => $preview,
  ]);

  $row = $db->fetchOne('SELECT c.*, u.name AS user_name, u.profile_picture FROM expense_comments c INNER JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$id]);
  Response::success(commentRow($row), 'Comment added', 201);
}

function deleteComment(int $commentId)
{
  $userId = requireUserId();
  $db = getDB();
  $comment = $db->fetchOne('SELECT id, user_id FROM expense_comments WHERE id = ?', [$commentId]);
  if (!$comment) {
    Response::error('Comment not found', 404);
  }
  if ((int) $comment['user_id'] !== $userId) {
    Response::error('You can only delete your own comments', 403);
  }
  $db->execute('DELETE FROM expense_comments WHERE id = ?', [$commentId]);
  Response::success(['id' => $commentId], 'Comment deleted');
}
