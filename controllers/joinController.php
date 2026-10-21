<?php
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../utils/activity.php';
require_once __DIR__ . '/../utils/push.php';

/**
 * Invite via link.
 *   GET  /join?code=…    landing page: opens the app (or Play) — this is the URL people share
 *   GET  /join/{code}    public group preview for the app's "Join group?" screen
 *   POST /join/{code}    join (or rejoin) the group
 */
function handleJoinRoutes($uri, $method)
{
  $parts = explode('/', trim($uri, '/'));
  if (count($parts) === 1 && $method === 'GET') {
    return joinLandingPage((string) ($_GET['code'] ?? ''));
  }
  if (count($parts) === 2 && $method === 'GET') {
    return joinPreview($parts[1]);
  }
  if (count($parts) === 2 && $method === 'POST') {
    return joinGroup($parts[1]);
  }
  Response::error("Route not found: $method $uri", 404);
}

function groupByInviteCode(string $code): array
{
  if (!preg_match('/^[A-Za-z0-9]{8,32}$/', $code)) {
    Response::error('This invite link is not valid', 404);
  }
  $group = getDB()->fetchOne('SELECT id, name, category, is_direct FROM expense_groups WHERE invite_code = ?', [$code]);
  if (!$group || $group['is_direct']) {
    Response::error('This invite link has expired or was reset', 404);
  }
  return $group;
}

function joinPreview(string $code)
{
  $group = groupByInviteCode($code);
  $members = getDB()->fetchAll(
    "SELECT u.name, u.profile_picture FROM group_members gm INNER JOIN users u ON u.id = gm.user_id
      WHERE gm.group_id = ? AND gm.status = 'active' ORDER BY gm.joined_at ASC LIMIT 6",
    [(int) $group['id']]
  );
  $count = getDB()->fetchOne("SELECT COUNT(*) AS c FROM group_members WHERE group_id = ? AND status = 'active'", [(int) $group['id']]);
  Response::success([
    'group_id' => (int) $group['id'],
    'name' => $group['name'],
    'category' => $group['category'],
    'member_count' => (int) $count['c'],
    // First names only: this endpoint is public to anyone holding the link
    'members' => array_map(function ($m) { return ['name' => firstName($m['name']), 'profile_picture' => $m['profile_picture']]; }, $members),
  ]);
}

function joinGroup(string $code)
{
  $userId = requireUserId();
  $group = groupByInviteCode($code);
  $groupId = (int) $group['id'];
  $db = getDB();
  $existing = memberRow($groupId, $userId);
  if ($existing && $existing['status'] === 'active') {
    Response::success(['group_id' => $groupId, 'already_member' => true], 'You are already in this group');
  }
  $count = $db->fetchOne('SELECT COUNT(*) AS c FROM group_members WHERE group_id = ?', [$groupId]);
  if (!$existing && (int) $count['c'] >= 50) {
    Response::error('This group is full (50 members)', 400);
  }
  if ($existing) {
    $db->execute("UPDATE group_members SET status = 'active', left_at = NULL WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
  } else {
    $db->insert("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'member', 'active')", [$groupId, $userId]);
  }
  $me = userName($userId);
  logActivity($groupId, $userId, 'join_group', 'group', $groupId, "$me joined the group via invite link");

  $others = $db->fetchAll("SELECT user_id FROM group_members WHERE group_id = ? AND status = 'active' AND user_id != ?", [$groupId, $userId]);
  Push::toUsers(array_map(function ($r) { return (int) $r['user_id']; }, $others), [
    'type' => 'member_joined',
    'group_id' => $groupId,
    'actor_id' => $userId,
    'title' => firstName($me) . ' joined "' . $group['name'] . '"',
    'body' => 'They can now add and split expenses',
  ]);
  Response::success(['group_id' => $groupId, 'already_member' => false], 'Joined ' . $group['name'], 201);
}

/** Small HTML page: Android Chrome follows the intent:// link straight into the app, or falls back to Play. */
function joinLandingPage(string $code)
{
  $safe = preg_match('/^[A-Za-z0-9]{8,32}$/', $code) ? $code : '';
  $deepLink = 'splitcash://join/' . $safe;
  $intent = 'intent://join/' . $safe . '#Intent;scheme=splitcash;package=dev.shivarya.splitcash;S.browser_fallback_url=' . rawurlencode(PLAY_STORE_URL) . ';end';
  $name = null;
  if ($safe !== '') {
    $g = getDB()->fetchOne('SELECT name FROM expense_groups WHERE invite_code = ? AND is_direct = 0', [$safe]);
    $name = $g ? $g['name'] : null;
  }
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
  $title = $name ? 'Join "' . $h($name) . '" on Split Cash' : 'Join a group on Split Cash';
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>' . $title . '</title>';
  echo '<meta property="og:title" content="' . $title . '"><meta property="og:description" content="Split bills and see who owes whom.">';
  echo '<style>body{font-family:system-ui,sans-serif;margin:0;background:#f4fbf8;color:#161d1b;display:flex;min-height:100vh;align-items:center;justify-content:center}'
    . 'main{max-width:420px;padding:32px 24px;text-align:center}h1{font-size:1.5rem;margin:0 0 8px}p{color:#3f4947;line-height:1.5}'
    . 'a.btn{display:block;margin:12px 0;padding:14px;border-radius:28px;text-decoration:none;font-weight:600}'
    . '.primary{background:#00796B;color:#fff}.secondary{border:1px solid #6f7977;color:#00796B}</style></head><body><main>';
  if ($name) {
    echo '<h1>You\'re invited to “' . $h($name) . '”</h1><p>Open Split Cash to join the group and start splitting expenses.</p>';
  } elseif ($safe === '') {
    echo '<h1>This invite link looks incomplete</h1><p>Ask for a new link from someone in the group.</p>';
  } else {
    echo '<h1>This invite link has expired</h1><p>Ask someone in the group to share a fresh link.</p>';
  }
  if ($name) {
    echo '<a class="btn primary" href="' . $h($intent) . '">Open in Split Cash</a>';
    echo '<a class="btn secondary" href="' . $h($deepLink) . '">Already installed? Tap here</a>';
  }
  echo '<a class="btn secondary" href="' . $h(PLAY_STORE_URL) . '">Get Split Cash on Google Play</a>';
  echo '</main></body></html>';
  exit;
}
