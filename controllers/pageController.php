<?php
/**
 * Public HTML pages that aren't API responses.
 */

/** GET /delete-account — the account-deletion URL given to Google Play (Data safety). */
function deleteAccountPage()
{
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: public, max-age=3600');
  $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
  $email = $h(EMAIL_USER);
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>Delete your Split Cash account</title>';
  echo '<style>body{font-family:system-ui,sans-serif;margin:0;background:#f4fbf8;color:#161d1b}'
    . 'main{max-width:640px;margin:0 auto;padding:32px 20px}h1{font-size:1.6rem;margin:0 0 12px}h2{font-size:1.1rem;margin:28px 0 8px}'
    . 'p,li{color:#3f4947;line-height:1.55}a{color:#00796B}</style></head><body><main>';
  echo '<h1>Delete your Split Cash account</h1>';
  echo '<p>Split Cash (by shivarya.dev) lets you delete your account and personal data at any time.</p>';
  echo '<h2>In the app</h2><ol>';
  echo '<li>Open Split Cash and go to the <strong>Account</strong> tab.</li>';
  echo '<li>Tap <strong>Delete account</strong> and confirm.</li></ol>';
  echo '<p>Deletion happens immediately and signs you out on this device.</p>';
  echo '<h2>Without the app</h2>';
  echo '<p>Email <a href="mailto:' . $email . '?subject=Delete%20my%20Split%20Cash%20account">' . $email . '</a> from the Google address you sign in with. '
    . 'We delete the account within 30 days and reply when it\'s done.</p>';
  echo '<h2>What is deleted</h2><ul>';
  echo '<li>Your name, email address, Google account link and profile photo.</li>';
  echo '<li>Your membership of every group, your devices\' notification tokens, and pending invitations and reminders.</li></ul>';
  echo '<h2>What is kept</h2>';
  echo '<p>Expenses, payments, comments and receipt photos you shared with a group belong to that group\'s shared record, '
    . 'so the other members\' balances stay correct. They remain visible to those members under the name '
    . '“Deleted user” and can no longer be linked to you.</p>';
  echo '</main></body></html>';
  exit;
}
