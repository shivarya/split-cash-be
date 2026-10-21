<?php
/**
 * Receipt photos. Files live under UPLOADS_DIR (outside the web root on prod) and are only ever
 * served through GET /expenses/expense/{id}/receipt, which checks group membership.
 * The app downscales to ≤1600 px JPEG before uploading, so 2 MB is plenty.
 */
require_once __DIR__ . '/../utils/auth.php';

const RECEIPT_MAX_BYTES = 2 * 1024 * 1024;
const RECEIPT_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/** Expense row + membership check shared by the three receipt routes. */
function receiptExpense(int $expenseId, int $userId): array
{
  $expense = getDB()->fetchOne('SELECT id, group_id, receipt_url FROM expenses WHERE id = ?', [$expenseId]);
  if (!$expense) {
    Response::error('Expense not found', 404);
  }
  requireActiveMember((int) $expense['group_id'], $userId);
  return $expense;
}

/** Absolute path for a stored relative receipt path, or null if it's missing or escapes UPLOADS_DIR. */
function receiptPath(?string $relative): ?string
{
  if (!$relative || strpos($relative, '..') !== false || $relative[0] === '/' || $relative[0] === '\\') {
    return null;
  }
  return UPLOADS_DIR . '/' . $relative;
}

function removeReceiptFile(?string $relative): void
{
  $path = receiptPath($relative);
  if ($path && is_file($path)) {
    @unlink($path);
  }
}

function ensureUploadsDir(string $dir): void
{
  if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
  }
  // Belt and braces for when UPLOADS_DIR sits inside the web root (local dev default)
  $guard = UPLOADS_DIR . '/.htaccess';
  if (!is_file($guard)) {
    @file_put_contents($guard, "Require all denied\nDeny from all\n");
  }
}

function uploadReceipt(int $expenseId)
{
  $userId = requireUserId();
  $expense = receiptExpense($expenseId, $userId);

  $file = $_FILES['receipt'] ?? null;
  if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    Response::error('Attach the photo as multipart field "receipt"', 400);
  }
  if ($file['size'] > RECEIPT_MAX_BYTES) {
    Response::error('That photo is too big (2 MB max)', 413);
  }
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
  if (!isset(RECEIPT_TYPES[$mime])) {
    Response::error('Receipts must be JPEG, PNG or WebP images', 415);
  }

  $groupId = (int) $expense['group_id'];
  $relative = "receipts/$groupId/$expenseId-" . bin2hex(random_bytes(8)) . '.' . RECEIPT_TYPES[$mime];
  $target = receiptPath($relative);
  ensureUploadsDir(dirname($target));
  $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $target) : rename($file['tmp_name'], $target);
  if (!$moved) {
    Response::error('Could not store the photo', 500);
  }

  removeReceiptFile($expense['receipt_url']);
  getDB()->execute('UPDATE expenses SET receipt_url = ?, updated_by = ? WHERE id = ?', [$relative, $userId, $expenseId]);
  Response::success(['expense_id' => $expenseId, 'has_receipt' => true], 'Receipt saved');
}

function streamReceipt(int $expenseId)
{
  $userId = requireUserId();
  $expense = receiptExpense($expenseId, $userId);
  $path = receiptPath($expense['receipt_url']);
  if (!$path || !is_file($path)) {
    Response::error('No receipt for this expense', 404);
  }
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
  header_remove('Content-Type');
  header('Content-Type: ' . $mime);
  header('Content-Length: ' . filesize($path));
  header('Cache-Control: private, max-age=86400');
  // The file name changes on every upload, so the URL+ETag pair is stable per photo
  header('ETag: "' . basename($path) . '"');
  readfile($path);
  exit;
}

function deleteReceipt(int $expenseId)
{
  $userId = requireUserId();
  $expense = receiptExpense($expenseId, $userId);
  removeReceiptFile($expense['receipt_url']);
  getDB()->execute('UPDATE expenses SET receipt_url = NULL, updated_by = ? WHERE id = ?', [$userId, $expenseId]);
  Response::success(['expense_id' => $expenseId, 'has_receipt' => false], 'Receipt removed');
}
