<?php
/**
 * Balance engine (pure functions, integer paise).
 *
 * A group "ledger" is:
 *   ['expenses'    => [['id' => int, 'paid_by' => int, 'amount' => paise, 'splits' => [user_id => paise]], ...],
 *    'settlements' => [['from' => int, 'to' => int, 'amount' => paise], ...]]
 *
 * Sign convention (same as the v1 endpoints): a positive net means the others owe you.
 *   net = paid for expenses - your shares - payments received + payments made
 */

/** Debts at or below this many paise are treated as settled (old data has 1-paisa rounding residue). */
const SETTLED_EPSILON_PAISE = 1;

/** @return array<int,int> user_id => net paise */
function ledgerNets(array $ledger): array
{
  $nets = [];
  foreach ($ledger['expenses'] as $e) {
    $nets[$e['paid_by']] = ($nets[$e['paid_by']] ?? 0) + $e['amount'];
    foreach ($e['splits'] as $uid => $share) {
      $nets[$uid] = ($nets[$uid] ?? 0) - $share;
    }
  }
  foreach ($ledger['settlements'] as $s) {
    $nets[$s['from']] = ($nets[$s['from']] ?? 0) + $s['amount'];
    $nets[$s['to']] = ($nets[$s['to']] ?? 0) - $s['amount'];
  }
  ksort($nets);
  return $nets;
}

/**
 * Raw who-owes-whom: everyone in a split owes the payer their share; a payment from A to B
 * works like B now owing A. Opposite debts between the same two people cancel.
 *
 * @return array list of ['from' => debtor, 'to' => creditor, 'amount' => paise]
 */
function pairwiseDebts(array $ledger): array
{
  $owes = []; // owes[a][b] = paise a owes b
  $add = function ($a, $b, $x) use (&$owes) {
    if ($a === $b || $x === 0) {
      return;
    }
    $owes[$a][$b] = ($owes[$a][$b] ?? 0) + $x;
  };
  foreach ($ledger['expenses'] as $e) {
    foreach ($e['splits'] as $uid => $share) {
      $add((int) $uid, (int) $e['paid_by'], $share);
    }
  }
  foreach ($ledger['settlements'] as $s) {
    $add((int) $s['to'], (int) $s['from'], $s['amount']);
  }

  $users = [];
  foreach ($owes as $a => $row) {
    $users[$a] = true;
    foreach ($row as $b => $_) {
      $users[$b] = true;
    }
  }
  $ids = array_keys($users);
  sort($ids);

  $debts = [];
  $n = count($ids);
  for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
      $a = $ids[$i];
      $b = $ids[$j];
      $d = ($owes[$a][$b] ?? 0) - ($owes[$b][$a] ?? 0);
      if ($d > SETTLED_EPSILON_PAISE) {
        $debts[] = ['from' => $a, 'to' => $b, 'amount' => $d];
      } elseif ($d < -SETTLED_EPSILON_PAISE) {
        $debts[] = ['from' => $b, 'to' => $a, 'amount' => -$d];
      }
    }
  }
  return $debts;
}

/**
 * "Simplify debts": settle everyone's net with as few payments as the greedy method finds,
 * always matching the biggest debtor with the biggest creditor (ties: lower user id first).
 *
 * @param array<int,int> $nets user_id => paise
 * @return array list of ['from', 'to', 'amount']
 */
function simplifyDebts(array $nets): array
{
  $debtors = [];
  $creditors = [];
  foreach ($nets as $uid => $v) {
    if ($v < -SETTLED_EPSILON_PAISE) {
      $debtors[(int) $uid] = -$v;
    } elseif ($v > SETTLED_EPSILON_PAISE) {
      $creditors[(int) $uid] = $v;
    }
  }
  $pickMax = function (array $m) {
    $best = null;
    foreach ($m as $uid => $v) {
      if ($best === null || $v > $m[$best] || ($v === $m[$best] && $uid < $best)) {
        $best = $uid;
      }
    }
    return $best;
  };

  $debts = [];
  while (!empty($debtors) && !empty($creditors)) {
    $d = $pickMax($debtors);
    $c = $pickMax($creditors);
    $x = min($debtors[$d], $creditors[$c]);
    if ($x > SETTLED_EPSILON_PAISE) {
      $debts[] = ['from' => $d, 'to' => $c, 'amount' => $x];
    }
    $debtors[$d] -= $x;
    $creditors[$c] -= $x;
    if ($debtors[$d] <= SETTLED_EPSILON_PAISE) {
      unset($debtors[$d]);
    }
    if ($creditors[$c] <= SETTLED_EPSILON_PAISE) {
      unset($creditors[$c]);
    }
  }
  return $debts;
}

function groupDebts(array $ledger, bool $simplify): array
{
  return $simplify ? simplifyDebts(ledgerNets($ledger)) : pairwiseDebts($ledger);
}

/** What an expense does to one person's balance: + they get money back, - they owe. */
function expenseNetFor(array $expense, int $userId): int
{
  $net = ((int) $expense['paid_by'] === $userId) ? $expense['amount'] : 0;
  return $net - ($expense['splits'][$userId] ?? 0);
}

/** @return array<int,int> every involved user's net for one expense */
function expenseNets(array $expense): array
{
  $nets = [(int) $expense['paid_by'] => 0];
  foreach ($expense['splits'] as $uid => $_) {
    $nets[(int) $uid] = 0;
  }
  foreach ($nets as $uid => $_) {
    $nets[$uid] = expenseNetFor($expense, $uid);
  }
  return $nets;
}

/**
 * How an expense changes what $a and $b owe each other: + means $b now owes $a more.
 * Only the payer is owed, so this is non-zero only when one of the two paid.
 */
function expensePairImpact(array $expense, int $a, int $b): int
{
  $payer = (int) $expense['paid_by'];
  if ($payer === $a) {
    return $expense['splits'][$b] ?? 0;
  }
  if ($payer === $b) {
    return -($expense['splits'][$a] ?? 0);
  }
  return 0;
}

/** Each user's debts in a debt list, from $userId's side: other_user_id => paise (+ they owe me). */
function debtsWith(array $debts, int $userId): array
{
  $with = [];
  foreach ($debts as $d) {
    if ($d['to'] === $userId) {
      $with[$d['from']] = ($with[$d['from']] ?? 0) + $d['amount'];
    } elseif ($d['from'] === $userId) {
      $with[$d['to']] = ($with[$d['to']] ?? 0) - $d['amount'];
    }
  }
  return $with;
}
