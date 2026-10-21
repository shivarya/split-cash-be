<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Appends to a group's activity feed. $meta is a JSON snapshot (description, amount, each
 * person's net in paise …) so the feed and its "you owe / you get back" lines still work
 * after the expense or payment is edited or deleted.
 */
function logActivity(int $groupId, int $userId, string $action, ?string $entityType, ?int $entityId, string $description, ?array $meta = null): int
{
  return (int) getDB()->insert(
    'INSERT INTO activities (group_id, user_id, action, entity_type, entity_id, description, meta) VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$groupId, $userId, $action, $entityType, $entityId, $description, $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]
  );
}

/** A user's display name (for activity text and push bodies). */
function userName(int $userId): string
{
  static $cache = [];
  if (!isset($cache[$userId])) {
    $row = getDB()->fetchOne('SELECT name FROM users WHERE id = ?', [$userId]);
    $cache[$userId] = $row ? $row['name'] : 'Someone';
  }
  return $cache[$userId];
}

/** First name only, as push notifications show it ("Asha added …"). */
function firstName(string $name): string
{
  $name = trim($name);
  if ($name === '' || strpos($name, '@') !== false) {
    return $name;
  }
  $parts = preg_split('/\s+/', $name);
  return $parts[0];
}
