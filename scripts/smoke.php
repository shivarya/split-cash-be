<?php
/**
 * End-to-end API smoke test that also leaves a realistic dev dataset behind.
 *
 *   php scripts/smoke.php --reset            wipe the LOCAL database, then seed + test
 *   php scripts/smoke.php --base=http://127.0.0.1:3000
 *
 * Needs the local server (php -S 0.0.0.0:3000 index.php) with APP_ENV=local and DEV_LOGIN_ENABLED=1.
 * Users: asha@test.dev, ben@test.dev, chitra@test.dev, dev@test.dev (+ a placeholder invitee).
 * Refuses to --reset anything but a local split_cash database.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$opts = getopt('', ['reset', 'base:']);
$BASE = rtrim($opts['base'] ?? 'http://127.0.0.1:3000', '/');
$failures = 0;
$checks = 0;

function ok(string $name, bool $cond, $detail = null): void
{
  global $failures, $checks;
  $checks++;
  if (!$cond) {
    $failures++;
    fwrite(STDERR, "FAIL $name" . ($detail !== null ? ' — ' . json_encode($detail) : '') . "\n");
  }
}

function api(string $method, string $path, ?string $token = null, $body = null, array $files = []): array
{
  global $BASE;
  $ch = curl_init($BASE . $path);
  $headers = [];
  if ($token) {
    $headers[] = 'Authorization: Bearer ' . $token;
  }
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
  if (!empty($files)) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $files);
  } elseif ($body !== null) {
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $raw = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  $json = strpos($type, 'json') !== false ? json_decode($raw, true) : null;
  return ['status' => $status, 'json' => $json, 'raw' => $raw, 'type' => $type];
}

function data(array $r)
{
  return $r['json']['data'] ?? null;
}

function near($a, $b): bool
{
  return abs((float) $a - (float) $b) < 0.005;
}

if (isset($opts['reset'])) {
  if (APP_ENV !== 'local' || DB_NAME !== 'split_cash' || !in_array(DB_HOST, ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Refusing to reset: only a local split_cash database with APP_ENV=local can be reset.\n");
    exit(2);
  }
  $db = getDB();
  $db->execute('SET FOREIGN_KEY_CHECKS = 0');
  foreach (['friend_reminders', 'devices', 'expense_comments', 'activities', 'invitations', 'settlements', 'expense_splits', 'expenses', 'group_members', 'balances', 'expense_groups', 'users'] as $t) {
    $db->execute("TRUNCATE TABLE $t");
  }
  $db->execute('SET FOREIGN_KEY_CHECKS = 1');
  echo "Local database reset.\n";
}

// ---------------------------------------------------------------- users
$people = ['asha' => 'Asha Rao', 'ben' => 'Ben Mathew', 'chitra' => 'Chitra Iyer', 'dev' => 'Dev Kapoor'];
$tok = [];
$uid = [];
foreach ($people as $key => $name) {
  $r = api('POST', '/auth/dev-login', null, ['email' => "$key@test.dev", 'name' => $name]);
  ok("dev-login $key", $r['status'] === 200, $r['json']);
  $tok[$key] = data($r)['token'];
  $uid[$key] = data($r)['user']['id'];
}
ok('no auth → 401', api('GET', '/balances/overview')['status'] === 401);

// ---------------------------------------------------------------- groups
$goa = data(api('POST', '/groups', $tok['asha'], ['name' => 'Goa trip', 'category' => 'trip']))['id'];
$flat = data(api('POST', '/groups', $tok['ben'], ['name' => 'Flat 4B', 'category' => 'home']))['id'];
foreach (['ben', 'chitra', 'dev'] as $k) {
  ok("add $k to Goa", api('POST', "/groups/$goa/add-member", $tok['asha'], ['email' => "$k@test.dev"])['status'] === 200);
}
ok('add asha to Flat', api('POST', "/groups/$flat/add-member", $tok['ben'], ['email' => 'asha@test.dev'])['status'] === 200);
ok('add chitra to Flat', api('POST', "/groups/$flat/add-member", $tok['ben'], ['email' => 'chitra@test.dev'])['status'] === 200);
// Flat 4B keeps raw (unsimplified) debts
$r = api('PUT', "/groups/$flat", $tok['ben'], ['simplify_debts' => false]);
ok('PUT /groups simplify off', $r['status'] === 200 && data($r)['simplify_debts'] === false, $r['json']);
ok('non-member cannot see members', api('GET', "/groups/$flat/members", $tok['dev'])['status'] === 403);

// ---------------------------------------------------------------- expenses, every mode
$today = date('Y-m-d');
$e = function ($who, $group, $body) use ($tok) {
  return api('POST', "/expenses/$group", $tok[$who], $body);
};

// v1 (RN) payload: split_type + splits[].user_id, subset of members. v1 servers ignored both keys.
$r = $e('asha', $goa, ['description' => 'Beach shack dinner', 'amount' => 3000, 'paid_by' => $uid['asha'], 'category' => 'food', 'date' => $today,
  'split_type' => 'equal', 'splits' => [['user_id' => $uid['asha'], 'amount' => 1000], ['user_id' => $uid['ben'], 'amount' => 1000], ['user_id' => $uid['chitra'], 'amount' => 1000]]]);
ok('v1 equal subset → 201', $r['status'] === 201, $r['json']);
$dinner = data($r);
ok('v1 equal subset honoured (3 people, not 4)', count($dinner['splits']) === 3, $dinner['splits'] ?? null);

// v1 payload with camelCase keys and no splits: everyone, equally
$r = $e('ben', $goa, ['description' => 'Scooter rental', 'amount' => 1000, 'paidBy' => $uid['ben'], 'category' => 'transport', 'date' => $today, 'splitType' => 'equal']);
ok('v1 everyone equal', $r['status'] === 201 && count(data($r)['splits']) === 4, $r['json']);

$r = $e('chitra', $goa, ['description' => 'Hotel', 'amount' => 9000, 'paid_by' => $uid['chitra'], 'category' => 'hotel', 'date' => $today, 'split_type' => 'shares',
  'splits' => [['user_id' => $uid['asha'], 'shares' => 2], ['user_id' => $uid['ben'], 'shares' => 1], ['user_id' => $uid['chitra'], 'shares' => 1], ['user_id' => $uid['dev'], 'shares' => 2]]]);
ok('shares → 201', $r['status'] === 201, $r['json']);
$hotel = data($r);
$byUser = [];
foreach ($hotel['splits'] as $s) {
  $byUser[$s['user_id']] = $s['amount'];
}
ok('shares amounts', near($byUser[$uid['asha']], 3000) && near($byUser[$uid['ben']], 1500) && near($byUser[$uid['dev']], 3000), $byUser);

$r = $e('dev', $goa, ['description' => 'Parasailing', 'amount' => 2500, 'paid_by' => $uid['dev'], 'category' => 'sports', 'date' => $today, 'split_type' => 'percentage',
  'splits' => [['user_id' => $uid['dev'], 'percentage' => 40], ['user_id' => $uid['ben'], 'percentage' => 60]]]);
ok('percentage → 201', $r['status'] === 201, $r['json']);

$r = $e('asha', $goa, ['description' => 'Groceries for the villa', 'amount' => 1250.50, 'paid_by' => $uid['ben'], 'category' => 'groceries', 'date' => $today, 'split_type' => 'adjustment',
  'splits' => [['user_id' => $uid['asha'], 'adjustment' => 250], ['user_id' => $uid['ben'], 'adjustment' => 0], ['user_id' => $uid['chitra'], 'adjustment' => 0]]]);
ok('adjustment (asha records, ben paid) → 201', $r['status'] === 201, $r['json']);
$groc = data($r);
ok('adjustment sums exactly', near(array_sum(array_column($groc['splits'], 'amount')), 1250.50), $groc['splits']);
ok('created_by is the recorder', $groc['created_by'] === $uid['asha'] && $groc['paid_by'] === $uid['ben']);

ok('exact amounts mismatch → 400', $e('asha', $goa, ['description' => 'Bad', 'amount' => 100, 'date' => $today, 'split_type' => 'unequal',
  'splits' => [['user_id' => $uid['asha'], 'amount' => 30], ['user_id' => $uid['ben'], 'amount' => 30]]])['status'] === 400);
ok('non-member in split → 400', $e('ben', $flat, ['description' => 'Bad', 'amount' => 100, 'date' => $today, 'split_type' => 'equal',
  'splits' => [['user_id' => $uid['dev']]]])['status'] === 400);

$r = $e('ben', $flat, ['description' => 'Rent', 'amount' => 30000, 'paid_by' => $uid['ben'], 'category' => 'rent', 'date' => $today, 'split_type' => 'unequal',
  'splits' => [['user_id' => $uid['asha'], 'amount' => 12000], ['user_id' => $uid['ben'], 'amount' => 12000], ['user_id' => $uid['chitra'], 'amount' => 6000]]]);
ok('unequal → 201', $r['status'] === 201, $r['json']);
$rent = data($r);
$e('chitra', $flat, ['description' => 'Electricity', 'amount' => 1800, 'paid_by' => $uid['chitra'], 'category' => 'electricity', 'date' => $today]);

// ---------------------------------------------------------------- edits
// v1 edit: amount only → rescale in its own mode (shares stay 2:1:1:2)
$r = api('PUT', "/expenses/{$hotel['id']}", $tok['ben'], ['description' => 'Hotel (2 nights)', 'amount' => 12000]);
ok('any member can edit (ben edits chitra\'s expense)', $r['status'] === 200, $r['json']);
$h = data($r)['expense'];
$byUser = [];
foreach ($h['splits'] as $s) {
  $byUser[$s['user_id']] = $s['amount'];
}
ok('rescale keeps shares', near($byUser[$uid['asha']], 4000) && near($byUser[$uid['ben']], 2000), $byUser);
ok('updated_by set', $h['updated_by'] === $uid['ben']);
// v2 edit: change payer and split mode
$r = api('PUT', "/expenses/{$dinner['id']}", $tok['asha'], ['paid_by' => $uid['ben'], 'split_type' => 'equal', 'splits' => [['user_id' => $uid['asha']], ['user_id' => $uid['ben']]]]);
ok('v2 edit re-splits', $r['status'] === 200 && data($r)['paid_by'] === $uid['ben'] && count(data($r)['splits']) === 2, $r['json']);

// ---------------------------------------------------------------- balances: overview == sum of group nets, both modes
$ov = data(api('GET', '/balances/overview', $tok['asha']));
$sumMyNets = array_sum(array_column($ov['groups'], 'my_net'));
ok('overview net == sum of group nets', near($ov['overall']['net'], $sumMyNets), [$ov['overall'], $sumMyNets]);
foreach ($ov['groups'] as $g) {
  ok("group {$g['name']}: balances add up to my_net", near(array_sum(array_column($g['balances'], 'amount')), $g['my_net']), $g);
}
$friendNetSum = array_sum(array_column($ov['friends'], 'net'));
ok('friend nets add up to overall', near($friendNetSum, $ov['overall']['net']), [$friendNetSum, $ov['overall']]);
ok('three friends', count($ov['friends']) === 3, array_column($ov['friends'], 'name'));

$debts = data(api('GET', "/balances/$goa/debts", $tok['asha']));
ok('Goa simplified', $debts['simplify_debts'] === true);
$netSum = array_sum(array_column($debts['nets'], 'balance'));
ok('Goa nets sum to ~0', abs($netSum) < 0.02, $netSum);
$flatDebts = data(api('GET', "/balances/$flat/debts", $tok['asha']));
ok('Flat raw (not simplified)', $flatDebts['simplify_debts'] === false);
// Raw rent: asha owes ben 12000, chitra owes ben 6000; electricity: asha owes chitra 600, ben owes chitra 600
$pair = [];
foreach ($flatDebts['debts'] as $d) {
  $pair[$d['from_user_id'] . '>' . $d['to_user_id']] = $d['amount'];
}
ok('raw pairwise asha→ben 12000', near($pair[$uid['asha'] . '>' . $uid['ben']] ?? 0, 12000), $pair);
ok('raw pairwise ben→chitra nets to chitra→ben 5400', near($pair[$uid['chitra'] . '>' . $uid['ben']] ?? 0, 5400), $pair);

// v1 suggestions use the same engine (the old 4-way JOIN inflated these)
$sugg = data(api('GET', "/balances/$goa/settlements/suggestions", $tok['asha']));
ok('suggestions == debts', count($sugg) === count($debts['debts']));

// ---------------------------------------------------------------- payments both ways
$r = api('POST', "/balances/$flat/settlements", $tok['asha'], ['toUserId' => $uid['ben'], 'amount' => 2000, 'date' => $today, 'notes' => 'UPI']);
ok('v1 payment (I paid)', $r['status'] === 201, $r['json']);
$r = api('POST', "/balances/$flat/settlements", $tok['ben'], ['fromUserId' => $uid['chitra'], 'toUserId' => $uid['ben'], 'amount' => 400, 'date' => $today]);
ok('v2 payment (they paid me)', $r['status'] === 201, $r['json']);
$payId = data($r)['id'];
ok('chitra (the payer) can delete it', api('DELETE', "/balances/$flat/settlements/$payId", $tok['chitra'])['status'] === 200);
$gone = data(api('GET', '/activity?limit=1', $tok['chitra']))['items'][0] ?? [];
// The feed shows what the removed payment was: "Removed: you paid ₹400"
ok('deleted payment keeps the payer\'s side', ($gone['action'] ?? '') === 'delete_settlement' && abs(($gone['my_net'] ?? 0) - 400) < 0.01, $gone);
ok('same person both sides → 400', api('POST', "/balances/$flat/settlements", $tok['ben'], ['fromUserId' => $uid['ben'], 'toUserId' => $uid['ben'], 'amount' => 1, 'date' => $today])['status'] === 400);

// ---------------------------------------------------------------- non-group expenses (hidden 1:1 group)
$r = api('POST', "/friends/{$uid['dev']}/direct", $tok['asha']);
ok('direct group', $r['status'] === 200, $r['json']);
$direct = data($r)['group_id'];
ok('direct group is idempotent', data(api('POST', "/friends/{$uid['asha']}/direct", $tok['dev']))['group_id'] === $direct);
$r = $e('asha', $direct, ['description' => 'Movie tickets', 'amount' => 700, 'paid_by' => $uid['asha'], 'category' => 'movies', 'date' => $today]);
ok('non-group expense', $r['status'] === 201 && count(data($r)['splits']) === 2, $r['json']);
$groupsV1 = data(api('GET', '/groups', $tok['asha']));
ok('v1 /groups hides the 1:1 group', !in_array($direct, array_column($groupsV1, 'id'), true));
$fd = data(api('GET', "/friends/{$uid['dev']}", $tok['asha']));
$nonGroup = array_values(array_filter($fd['groups'], function ($g) { return $g['is_direct']; }));
ok('friend detail shows non-group balance', !empty($nonGroup) && near($nonGroup[0]['amount'], 350), $fd['groups']);
ok('friend detail lists shared expenses', count($fd['expenses']) >= 2, count($fd['expenses']));

// Friend-level settle up spread across groups
$before = data(api('GET', "/friends/{$uid['ben']}", $tok['asha']))['net'];
$r = api('POST', "/friends/{$uid['ben']}/settle", $tok['asha'], ['amount' => 500, 'direction' => 'paid']);
ok('friend settle', $r['status'] === 201, $r['json']);
$after = data(api('GET', "/friends/{$uid['ben']}", $tok['asha']))['net'];
ok('friend settle moves net by 500', near($after - $before, 500), [$before, $after]);

// Remind: only when they owe you, once a day
$owesAsha = null;
foreach (data(api('GET', '/friends', $tok['asha']))['friends'] as $f) {
  if ($f['net'] > 0) {
    $owesAsha = $f['user_id'];
  }
}
if ($owesAsha) {
  ok('remind', api('POST', "/friends/$owesAsha/remind", $tok['asha'])['status'] === 200);
  ok('remind twice → 429', api('POST', "/friends/$owesAsha/remind", $tok['asha'])['status'] === 429);
}

// Add a friend who isn't on Split Cash → placeholder + invite; signing in activates them
$r = api('POST', '/friends', $tok['asha'], ['email' => 'esha@test.dev']);
ok('add unregistered friend', $r['status'] === 201 && data($r)['invited'] === true, $r['json']);
$eshaDirect = data($r)['direct_group_id'];
$eshaId = data($r)['friend']['user_id'];
$e('asha', $eshaDirect, ['description' => 'Coffee', 'amount' => 300, 'paid_by' => $uid['asha'], 'date' => $today]);
$esha = data(api('POST', '/auth/dev-login', null, ['email' => 'esha@test.dev', 'name' => 'Esha Nair']));
ok('placeholder signs in as the same user', $esha['user']['id'] === $eshaId);
$eo = data(api('GET', '/balances/overview', $esha['token']));
ok('former placeholder sees the debt', isset($eo['friends'][0]) && near($eo['friends'][0]['net'], -150), $eo['friends']);

// ---------------------------------------------------------------- invite via link
$r = api('POST', "/groups/$goa/invite-link", $tok['ben']);
ok('invite link', $r['status'] === 200 && strpos(data($r)['url'], '/join?code=') !== false, $r['json']);
$code = data($r)['code'];
ok('same link on second call', data(api('POST', "/groups/$goa/invite-link", $tok['asha']))['code'] === $code);
$preview = api('GET', "/join/$code");
ok('public preview', $preview['status'] === 200 && data($preview)['name'] === 'Goa trip', $preview['json']);
ok('landing page is HTML', strpos(api('GET', "/join?code=$code")['type'], 'text/html') !== false);
ok('join', api('POST', "/join/$code", $esha['token'])['status'] === 201);
ok('join again is a no-op', data(api('POST', "/join/$code", $esha['token']))['already_member'] === true);
ok('non-admin cannot rotate', api('DELETE', "/groups/$goa/invite-link", $tok['ben'])['status'] === 403);
$new = data(api('DELETE', "/groups/$goa/invite-link", $tok['asha']))['code'];
ok('rotated code differs', $new !== $code);
ok('old code is dead', api('GET', "/join/$code")['status'] === 404);

// ---------------------------------------------------------------- comments
$r = api('POST', "/expenses/expense/{$hotel['id']}/comments", $tok['asha'], ['body' => 'Booked through the app, receipt attached']);
ok('comment', $r['status'] === 201, $r['json']);
$commentId = data($r)['id'];
api('POST', "/expenses/expense/{$hotel['id']}/comments", $tok['chitra'], ['body' => 'Thanks!']);
ok('two comments', count(data(api('GET', "/expenses/expense/{$hotel['id']}/comments", $tok['dev']))) === 2);
ok('cannot delete someone else\'s comment', api('DELETE', "/comments/$commentId", $tok['chitra'])['status'] === 403);
ok('comment count on expense', data(api('GET', "/expenses/expense/{$hotel['id']}", $tok['asha']))['comment_count'] === 2);

// ---------------------------------------------------------------- receipts
$png = tempnam(sys_get_temp_dir(), 'rcpt');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$r = api('POST', "/expenses/expense/{$hotel['id']}/receipt", $tok['chitra'], null, ['receipt' => new CURLFile($png, 'image/png', 'receipt.png')]);
ok('receipt upload', $r['status'] === 200, $r['json']);
$img = api('GET', "/expenses/expense/{$hotel['id']}/receipt", $tok['asha']);
ok('receipt stream', $img['status'] === 200 && $img['type'] === 'image/png', [$img['status'], $img['type']]);
ok('receipt needs auth', api('GET', "/expenses/expense/{$hotel['id']}/receipt")['status'] === 401);
ok('receipt needs membership', api('GET', "/expenses/expense/{$rent['id']}/receipt", $tok['dev'])['status'] === 403);
$txt = tempnam(sys_get_temp_dir(), 'rcpt');
file_put_contents($txt, 'not an image');
ok('non-image rejected', api('POST', "/expenses/expense/{$hotel['id']}/receipt", $tok['chitra'], null, ['receipt' => new CURLFile($txt, 'image/png', 'x.png')])['status'] === 415);
@unlink($png);
@unlink($txt);

// ---------------------------------------------------------------- security fixes
ok('GET expense needs auth', api('GET', "/expenses/expense/{$rent['id']}")['status'] === 401);
ok('GET expense needs membership', api('GET', "/expenses/expense/{$rent['id']}", $tok['dev'])['status'] === 403);

// ---------------------------------------------------------------- activity feed
$act = data(api('GET', '/activity?limit=10', $tok['asha']));
ok('activity items', count($act['items']) === 10 && $act['has_more'] === true, count($act['items'] ?? []));
$withImpact = array_filter($act['items'], function ($i) { return $i['my_net'] !== null; });
ok('activity carries my_net', count($withImpact) > 0);
$older = data(api('GET', '/activity?limit=10&before_id=' . end($act['items'])['id'], $tok['asha']));
ok('activity paging', !empty($older['items']) && $older['items'][0]['id'] < end($act['items'])['id']);

// ---------------------------------------------------------------- delete an expense: activity snapshot survives
$r = api('DELETE', "/expenses/{$groc['id']}", $tok['chitra']);
ok('any member can delete', $r['status'] === 200, $r['json']);
$latest = data(api('GET', '/activity?limit=1', $tok['asha']))['items'][0];
ok('delete logged with snapshot', $latest['action'] === 'delete_expense' && $latest['subject'] === 'Groceries for the villa' && $latest['my_net'] !== null, $latest);

// ---------------------------------------------------------------- remove member + devices + account deletion
ok('remove member with a balance → 409', api('DELETE', "/groups/$goa/members/{$uid['dev']}", $tok['asha'])['status'] === 409);
ok('device register', api('POST', '/devices', $tok['dev'], ['token' => 'test-token-dev', 'app_version' => '2.0.0'])['status'] === 200);
$temp = data(api('POST', '/auth/dev-login', null, ['email' => 'temp@test.dev', 'name' => 'Temp User']));
api('POST', "/groups/$flat/add-member", $tok['ben'], ['email' => 'temp@test.dev']);
ok('delete account', api('DELETE', '/auth/account', $temp['token'])['status'] === 200);
ok('deleted account token stops working', api('GET', '/balances/overview', $temp['token'])['status'] === 401);
$row = getDB()->fetchOne('SELECT name, email, google_id FROM users WHERE id = ?', [$temp['user']['id']]);
ok('account anonymised', $row['name'] === 'Deleted user' && $row['google_id'] === null && strpos($row['email'], '@deleted.invalid') !== false, $row);

echo "$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
