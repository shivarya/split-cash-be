<?php
/**
 * Split engine: turns an expense amount + split mode + participants into per-person shares.
 *
 * All arithmetic is in integer paise, and the shares always add up to the amount exactly.
 * Leftover paise go one at a time to the payer (when they're in the split), then to the
 * other participants in ascending user id.
 *
 * The Android app mirrors this in SplitCalculator.kt for its live preview. Keep the two in
 * step and regenerate the shared goldens with `php scripts/split-golden.php`.
 */

class SplitError extends InvalidArgumentException
{
}

const SPLIT_TYPES = ['equal', 'unequal', 'percentage', 'shares', 'adjustment'];

function toPaise($rupees): int
{
  return (int) round(((float) $rupees) * 100);
}

function fromPaise(int $paise): float
{
  return round($paise / 100, 2);
}

/** "₹1,23,456.50" (Indian digit grouping), used in activity text and emails. */
function formatInr(int $paise): string
{
  $neg = $paise < 0;
  $paise = abs($paise);
  $rupees = (string) intdiv($paise, 100);
  $fraction = str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
  if (strlen($rupees) > 3) {
    $last3 = substr($rupees, -3);
    $rest = substr($rupees, 0, -3);
    $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
    $rupees = $rest . ',' . $last3;
  }
  return ($neg ? '-' : '') . '₹' . $rupees . '.' . $fraction;
}

/**
 * Hands out $remainder paise (may be negative) one at a time: payer first if present,
 * then the others by ascending user id, cycling as needed.
 *
 * @param array<int,int> $amounts user_id => paise, modified in place
 */
function distributeRemainder(array &$amounts, int $remainder, int $payerId): void
{
  if ($remainder === 0 || empty($amounts)) {
    return;
  }
  $order = array_keys($amounts);
  sort($order);
  if (isset($amounts[$payerId])) {
    $order = array_values(array_diff($order, [$payerId]));
    array_unshift($order, $payerId);
  }
  $step = $remainder > 0 ? 1 : -1;
  $left = abs($remainder);
  $guard = 0;
  while ($left > 0 && $guard < 100000) {
    foreach ($order as $uid) {
      if ($left === 0) {
        break;
      }
      if ($step < 0 && $amounts[$uid] <= 0) {
        continue;
      }
      $amounts[$uid] += $step;
      $left--;
    }
    $guard++;
  }
}

/**
 * @param string $type        one of SPLIT_TYPES
 * @param int    $totalPaise  expense amount in paise, > 0
 * @param int    $payerId     who paid
 * @param array  $entries     list of ['user_id' => int, 'amount'|'percentage'|'shares'|'adjustment' => number]
 *                            (only the field the mode needs is read; equal needs just user_id)
 * @return array list of ['user_id' => int, 'amount' => paise, 'percentage' => float, 'shares' => ?float, 'adjustment' => ?float],
 *               sorted by user_id, zero-share participants dropped
 * @throws SplitError with a message fit to show the user
 */
function computeSplits(string $type, int $totalPaise, int $payerId, array $entries): array
{
  if (!in_array($type, SPLIT_TYPES, true)) {
    throw new SplitError("Unknown split type \"$type\"");
  }
  if ($totalPaise <= 0) {
    throw new SplitError('Amount must be greater than 0');
  }

  $byUser = [];
  foreach ($entries as $e) {
    $uid = (int) ($e['user_id'] ?? 0);
    if ($uid <= 0) {
      throw new SplitError('Every split needs a user');
    }
    if (isset($byUser[$uid])) {
      throw new SplitError('A person appears twice in the split');
    }
    $byUser[$uid] = $e;
  }
  if (empty($byUser)) {
    throw new SplitError('Choose at least one person to split with');
  }
  ksort($byUser);

  $amounts = [];      // uid => paise
  $percentages = [];  // uid => given percentage (percentage mode only)
  $shares = [];       // uid => shares (shares mode only)
  $adjustments = [];  // uid => adjustment in rupees (adjustment mode only)

  switch ($type) {
    case 'equal':
      $n = count($byUser);
      $base = intdiv($totalPaise, $n);
      foreach ($byUser as $uid => $_) {
        $amounts[$uid] = $base;
      }
      distributeRemainder($amounts, $totalPaise - $base * $n, $payerId);
      break;

    case 'unequal':
      foreach ($byUser as $uid => $e) {
        $p = toPaise($e['amount'] ?? 0);
        if ($p < 0) {
          throw new SplitError('Split amounts can\'t be negative');
        }
        if ($p > 0) {
          $amounts[$uid] = $p;
        }
      }
      if (empty($amounts)) {
        throw new SplitError('Enter at least one amount');
      }
      $diff = $totalPaise - array_sum($amounts);
      if (abs($diff) > 1) {
        throw new SplitError('Split amounts must add up to ' . formatInr($totalPaise)
          . ' (they add up to ' . formatInr(array_sum($amounts)) . ')');
      }
      distributeRemainder($amounts, $diff, $payerId);
      break;

    case 'percentage':
      $bp = []; // basis points of a percent: 100% = 10000
      foreach ($byUser as $uid => $e) {
        $pct = (float) ($e['percentage'] ?? 0);
        if ($pct < 0) {
          throw new SplitError('Percentages can\'t be negative');
        }
        $v = (int) round($pct * 100);
        if ($v > 0) {
          $bp[$uid] = $v;
          $percentages[$uid] = round($pct, 2);
        }
      }
      if (empty($bp)) {
        throw new SplitError('Enter at least one percentage');
      }
      $sum = array_sum($bp);
      if (abs($sum - 10000) > 1) {
        throw new SplitError('Percentages must add up to 100% (they add up to ' . rtrim(rtrim(number_format($sum / 100, 2, '.', ''), '0'), '.') . '%)');
      }
      foreach ($bp as $uid => $v) {
        $amounts[$uid] = intdiv($totalPaise * $v, $sum);
      }
      distributeRemainder($amounts, $totalPaise - array_sum($amounts), $payerId);
      break;

    case 'shares':
      $units = [];
      foreach ($byUser as $uid => $e) {
        $s = (float) ($e['shares'] ?? 0);
        if ($s < 0) {
          throw new SplitError('Shares can\'t be negative');
        }
        $v = (int) round($s * 100);
        if ($v > 0) {
          $units[$uid] = $v;
          $shares[$uid] = round($s, 2);
        }
      }
      if (empty($units)) {
        throw new SplitError('Give at least one person a share');
      }
      $sum = array_sum($units);
      foreach ($units as $uid => $v) {
        $amounts[$uid] = intdiv($totalPaise * $v, $sum);
      }
      distributeRemainder($amounts, $totalPaise - array_sum($amounts), $payerId);
      break;

    case 'adjustment':
      $adj = [];
      foreach ($byUser as $uid => $e) {
        $adj[$uid] = toPaise($e['adjustment'] ?? 0);
        $adjustments[$uid] = round((float) ($e['adjustment'] ?? 0), 2);
      }
      $pool = $totalPaise - array_sum($adj);
      if ($pool < 0) {
        throw new SplitError('Adjustments add up to more than ' . formatInr($totalPaise));
      }
      $n = count($adj);
      $base = intdiv($pool, $n);
      $equalPart = [];
      foreach ($adj as $uid => $_) {
        $equalPart[$uid] = $base;
      }
      distributeRemainder($equalPart, $pool - $base * $n, $payerId);
      foreach ($adj as $uid => $a) {
        $amounts[$uid] = $equalPart[$uid] + $a;
        if ($amounts[$uid] < 0) {
          throw new SplitError('An adjustment leaves someone with a negative share');
        }
      }
      break;
  }

  ksort($amounts);
  $result = [];
  foreach ($amounts as $uid => $paise) {
    if ($paise === 0 && $type !== 'adjustment') {
      continue;
    }
    $result[] = [
      'user_id' => (int) $uid,
      'amount' => $paise,
      'percentage' => $type === 'percentage' ? $percentages[$uid] : round($paise * 100 / $totalPaise, 2),
      'shares' => $type === 'shares' ? $shares[$uid] : null,
      'adjustment' => $type === 'adjustment' ? $adjustments[$uid] : null,
    ];
  }
  return $result;
}

/**
 * Reads the split part of an expense request in either payload dialect:
 *   v2 (native app): split_type + splits[].user_id / amount / percentage / shares / adjustment
 *   v1 (RN app):     split_type or splitType + splits[].user_id or splits[].userId
 * Returns [type, entries]; entries is null when the request names no participants.
 */
function readSplitInput(array $input): array
{
  $type = $input['split_type'] ?? ($input['splitType'] ?? null);
  $raw = $input['splits'] ?? null;
  $entries = null;
  if (is_array($raw) && !empty($raw)) {
    $entries = [];
    foreach ($raw as $s) {
      if (!is_array($s)) {
        continue;
      }
      $entries[] = [
        'user_id' => (int) ($s['user_id'] ?? ($s['userId'] ?? 0)),
        'amount' => $s['amount'] ?? null,
        'percentage' => $s['percentage'] ?? null,
        'shares' => $s['shares'] ?? null,
        'adjustment' => $s['adjustment'] ?? null,
      ];
    }
  }
  return [$type, $entries];
}
