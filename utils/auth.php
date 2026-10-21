<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/jwt.php';

/** The signed-in user's id, or a 401 response. */
function requireUserId(): int
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData) {
    Response::error('Unauthorized', 401);
  }
  return (int) $tokenData['userId'];
}

/** @return array|false the group_members row (role, status) for this user */
function memberRow(int $groupId, int $userId)
{
  return getDB()->fetchOne(
    'SELECT role, status FROM group_members WHERE group_id = ? AND user_id = ?',
    [$groupId, $userId]
  );
}

/** The caller's membership row, or a 403 when they aren't an active member of the group. */
function requireActiveMember(int $groupId, int $userId): array
{
  $member = memberRow($groupId, $userId);
  if (!$member || $member['status'] !== 'active') {
    Response::error('You are not a member of this group', 403);
  }
  return $member;
}

function requireAdmin(int $groupId, int $userId): array
{
  $member = requireActiveMember($groupId, $userId);
  if ($member['role'] !== 'admin') {
    Response::error('Only a group admin can do that', 403);
  }
  return $member;
}

/** "IN (?,?,?)" for a list of ids; callers must not pass an empty list. */
function inPlaceholders(array $ids): string
{
  return implode(',', array_fill(0, count($ids), '?'));
}

/** A boolean field that may arrive as true/false, 1/0 or "true"/"false". */
function inputBool(array $input, string $key, ?bool $default = null): ?bool
{
  if (!array_key_exists($key, $input)) {
    return $default;
  }
  $v = $input[$key];
  if (is_bool($v)) {
    return $v;
  }
  return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
}

/** Normalises a DB user row into the public shape every endpoint returns. */
function publicUser(array $u): array
{
  return [
    'id' => (int) $u['id'],
    'name' => $u['name'],
    'email' => $u['email'],
    'profile_picture' => $u['profile_picture'] ?? null,
  ];
}
