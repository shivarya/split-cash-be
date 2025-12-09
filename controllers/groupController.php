<?php
function handleGroupRoutes($uri, $method)
{
  $parts = explode('/', trim($uri, '/'));

  if (count($parts) == 1 && $method === 'POST') {
    createGroup();
  } elseif (count($parts) == 1 && $method === 'GET') {
    getGroups();
  } elseif (count($parts) == 2 && $method === 'GET') {
    $groupId = $parts[1];
    getGroupDetails($groupId);
  } elseif (count($parts) == 3 && $parts[2] === 'members' && $method === 'GET') {
    $groupId = $parts[1];
    getGroupMembers($groupId);
  } elseif (count($parts) == 3 && $parts[2] === 'invite' && $method === 'POST') {
    $groupId = $parts[1];
    inviteMembers($groupId);
  } elseif (count($parts) == 2 && $parts[1] === 'accept-invitation' && $method === 'POST') {
    acceptInvitation();
  } else {
    Response::error('Route not found', 404);
  }
}

function createGroup()
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  $input = getJsonInput();
  $name = $input['name'] ?? null;
  $description = $input['description'] ?? '';
  $category = $input['category'] ?? 'general';
  $image = $input['image'] ?? null;

  if (!$name)
    Response::error('Group name is required', 400);

  try {
    $db = getDB();
    $groupId = $db->insert(
      'INSERT INTO expense_groups (name, description, category, image, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
      [$name, $description, $category, $image, $tokenData['userId']]
    );

    // Add creator as admin member
    $db->insert(
      'INSERT INTO group_members (group_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())',
      [$groupId, $tokenData['userId'], 'admin']
    );

    // Log activity
    $db->insert(
      'INSERT INTO activities (group_id, user_id, action, entity_type, entity_id, description) VALUES (?, ?, ?, ?, ?, ?)',
      [$groupId, $tokenData['userId'], 'create_group', 'group', $groupId, "Created group \"$name\""]
    );

    $group = $db->fetchOne('SELECT * FROM expense_groups WHERE id = ?', [$groupId]);

    Response::success([
      'id' => (int) $group['id'],
      'name' => $group['name'],
      'description' => $group['description'],
      'category' => $group['category'],
      'image' => $group['image']
    ], 'Group created successfully', 201);

  } catch (Exception $e) {
    Response::error('Failed to create group: ' . $e->getMessage(), 500);
  }
}

function getGroups()
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  try {
    $db = getDB()->getConnection();
    $stmt = $db->prepare('
      SELECT 
        g.id, g.name, g.description, g.category, g.image, g.created_at,
        gm.role,
        COUNT(DISTINCT gm2.user_id) as member_count
      FROM expense_groups g
      INNER JOIN group_members gm ON g.id = gm.group_id
      LEFT JOIN group_members gm2 ON g.id = gm2.group_id
      WHERE gm.user_id = ?
      GROUP BY g.id, g.name, g.description, g.category, g.image, g.created_at, gm.role
      ORDER BY g.updated_at DESC
    ');
    $stmt->execute([$tokenData['userId']]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = array_map(function ($group) {
      return [
        'id' => (int) $group['id'],
        'name' => $group['name'],
        'description' => $group['description'],
        'category' => $group['category'],
        'image' => $group['image'],
        'created_at' => $group['created_at'],
        'role' => $group['role'],
        'member_count' => (int) $group['member_count']
      ];
    }, $groups);

    Response::success($result);

  } catch (Exception $e) {
    Response::error('Failed to get groups: ' . $e->getMessage(), 500);
  }
}

function getGroupDetails($groupId)
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  try {
    $db = getDB();

    // Check if user is a member
    $member = $db->fetchOne(
      'SELECT * FROM group_members WHERE group_id = ? AND user_id = ?',
      [$groupId, $tokenData['userId']]
    );

    if (!$member)
      Response::error('You are not a member of this group', 403);

    $group = $db->fetchOne('SELECT * FROM expense_groups WHERE id = ?', [$groupId]);

    if (!$group)
      Response::error('Group not found', 404);

    // Get members
    $members = $db->fetchAll(
      'SELECT u.id, u.name, u.email, u.profile_picture
             FROM users u
             INNER JOIN group_members gm ON u.id = gm.user_id
             WHERE gm.group_id = ?',
      [$groupId]
    );

    Response::success([
      'group' => [
        'id' => (int) $group['id'],
        'name' => $group['name'],
        'description' => $group['description'],
        'created_by' => (int) $group['created_by'],
        'members' => array_map(function ($m) {
          return [
            'id' => (int) $m['id'],
            'name' => $m['name'],
            'email' => $m['email'],
            'profile_picture' => $m['profile_picture']
          ];
        }, $members)
      ]
    ]);

  } catch (Exception $e) {
    Response::error('Failed to get group details: ' . $e->getMessage(), 500);
  }
}

function getGroupMembers($groupId)
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  try {
    $db = getDB();

    $members = $db->fetchAll(
      'SELECT u.id, u.name, u.email, u.profile_picture
             FROM users u
             INNER JOIN group_members gm ON u.id = gm.user_id
             WHERE gm.group_id = ?',
      [$groupId]
    );

    Response::success([
      'members' => array_map(function ($m) {
        return [
          'id' => (int) $m['id'],
          'name' => $m['name'],
          'email' => $m['email'],
          'profile_picture' => $m['profile_picture']
        ];
      }, $members)
    ]);

  } catch (Exception $e) {
    Response::error('Failed to get members: ' . $e->getMessage(), 500);
  }
}

function inviteMembers($groupId)
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  $input = getJsonInput();
  $emails = $input['emails'] ?? [];

  if (empty($emails))
    Response::error('At least one email is required', 400);

  try {
    $db = getDB();

    // Check if user is group admin
    $group = $db->fetchOne('SELECT * FROM expense_groups WHERE id = ?', [$groupId]);
    if (!$group)
      Response::error('Group not found', 404);

    foreach ($emails as $email) {
      // Generate invitation token
      $token = bin2hex(random_bytes(32));

      // Store invitation
      $db->insert(
        'INSERT INTO invitations (group_id, email, token, created_at) VALUES (?, ?, ?, NOW())',
        [$groupId, $email, $token]
      );

      // Send invitation email (implement if needed)
      // sendInvitationEmail($email, $group['name'], $token);
    }

    Response::success(null, 'Invitations sent successfully');

  } catch (Exception $e) {
    Response::error('Failed to send invitations: ' . $e->getMessage(), 500);
  }
}

function acceptInvitation()
{
  $tokenData = JWTHandler::getUserFromToken();
  if (!$tokenData)
    Response::error('Unauthorized', 401);

  $input = getJsonInput();
  $token = $input['token'] ?? null;

  if (!$token)
    Response::error('Invitation token is required', 400);

  try {
    $db = getDB();

    // Find invitation
    $invitation = $db->fetchOne(
      'SELECT * FROM invitations WHERE token = ?',
      [$token]
    );

    if (!$invitation)
      Response::error('Invalid invitation', 404);

    // Add user to group
    $db->insert(
      'INSERT IGNORE INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())',
      [$invitation['group_id'], $tokenData['userId']]
    );

    // Delete invitation
    $db->execute('DELETE FROM invitations WHERE id = ?', [$invitation['id']]);

    Response::success([
      'groupId' => (int) $invitation['group_id']
    ], 'Invitation accepted successfully');

  } catch (Exception $e) {
    Response::error('Failed to accept invitation: ' . $e->getMessage(), 500);
  }
}
