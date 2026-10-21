<?php
/**
 * CLI self-test for the split and balance engines (no DB needed).
 *   php scripts/balance-selftest.php        → exits 0 when every check passes
 */
require_once __DIR__ . '/../utils/splits.php';
require_once __DIR__ . '/../utils/balances.php';

$failures = 0;
$checks = 0;
function check(string $name, $actual, $expected): void
{
  global $failures, $checks;
  $checks++;
  if ($actual !== $expected) {
    $failures++;
    fwrite(STDERR, "FAIL $name\n  expected: " . json_encode($expected) . "\n  actual:   " . json_encode($actual) . "\n");
  }
}
function throwsSplit(string $name, callable $fn): void
{
  global $failures, $checks;
  $checks++;
  try {
    $fn();
    $failures++;
    fwrite(STDERR, "FAIL $name: expected SplitError\n");
  } catch (SplitError $e) {
    // expected
  }
}
function amounts(array $splits): array
{
  $m = [];
  foreach ($splits as $s) {
    $m[$s['user_id']] = $s['amount'];
  }
  return $m;
}

// ---- formatInr
check('inr small', formatInr(5), '₹0.05');
check('inr thousands', formatInr(123456789), '₹12,34,567.89');
check('inr negative', formatInr(-100000), '-₹1,000.00');

// ---- equal: remainder goes to the payer first, then ascending ids
check('equal 100/3 payer in', amounts(computeSplits('equal', 10000, 2, [['user_id' => 1], ['user_id' => 2], ['user_id' => 3]])), [1 => 3333, 2 => 3334, 3 => 3333]);
check('equal 100/3 payer out', amounts(computeSplits('equal', 10000, 9, [['user_id' => 3], ['user_id' => 1], ['user_id' => 2]])), [1 => 3334, 2 => 3333, 3 => 3333]);
check('equal 0.02/3', amounts(computeSplits('equal', 2, 3, [['user_id' => 1], ['user_id' => 2], ['user_id' => 3]])), [1 => 1, 3 => 1]);
throwsSplit('equal empty', function () { computeSplits('equal', 100, 1, []); });
throwsSplit('equal duplicate', function () { computeSplits('equal', 100, 1, [['user_id' => 1], ['user_id' => 1]]); });

// ---- unequal (exact amounts), 1 paisa of slack is absorbed
check('unequal exact', amounts(computeSplits('unequal', 50000, 1, [['user_id' => 1, 'amount' => 200], ['user_id' => 2, 'amount' => 300]])), [1 => 20000, 2 => 30000]);
check('unequal 1p slack', amounts(computeSplits('unequal', 10000, 1, [['user_id' => 1, 'amount' => 33.33], ['user_id' => 2, 'amount' => 33.33], ['user_id' => 3, 'amount' => 33.33]])), [1 => 3334, 2 => 3333, 3 => 3333]);
check('unequal zero dropped', amounts(computeSplits('unequal', 10000, 1, [['user_id' => 1, 'amount' => 100], ['user_id' => 2, 'amount' => 0]])), [1 => 10000]);
throwsSplit('unequal mismatch', function () { computeSplits('unequal', 10000, 1, [['user_id' => 1, 'amount' => 40], ['user_id' => 2, 'amount' => 50]]); });

// ---- percentage
check('pct 50/25/25', amounts(computeSplits('percentage', 10001, 1, [['user_id' => 1, 'percentage' => 50], ['user_id' => 2, 'percentage' => 25], ['user_id' => 3, 'percentage' => 25]])), [1 => 5001, 2 => 2500, 3 => 2500]);
check('pct thirds', amounts(computeSplits('percentage', 10000, 2, [['user_id' => 1, 'percentage' => 33.33], ['user_id' => 2, 'percentage' => 33.33], ['user_id' => 3, 'percentage' => 33.34]])), [1 => 3333, 2 => 3333, 3 => 3334]);
throwsSplit('pct not 100', function () { computeSplits('percentage', 10000, 1, [['user_id' => 1, 'percentage' => 60], ['user_id' => 2, 'percentage' => 30]]); });

// ---- shares
check('shares 2:1', amounts(computeSplits('shares', 30000, 1, [['user_id' => 1, 'shares' => 2], ['user_id' => 2, 'shares' => 1]])), [1 => 20000, 2 => 10000]);
check('shares 1.5:1:1 of 100', amounts(computeSplits('shares', 10000, 3, [['user_id' => 1, 'shares' => 1.5], ['user_id' => 2, 'shares' => 1], ['user_id' => 3, 'shares' => 1]])), [1 => 4285, 2 => 2857, 3 => 2858]);
$s = computeSplits('shares', 30000, 1, [['user_id' => 1, 'shares' => 2], ['user_id' => 2, 'shares' => 1]]);
check('shares stored', $s[0]['shares'], 2.0);

// ---- adjustment: equal share of (total - adjustments) plus each adjustment
check('adj +100 on 500/2', amounts(computeSplits('adjustment', 50000, 1, [['user_id' => 1, 'adjustment' => 100], ['user_id' => 2, 'adjustment' => 0]])), [1 => 30000, 2 => 20000]);
throwsSplit('adj too big', function () { computeSplits('adjustment', 10000, 1, [['user_id' => 1, 'adjustment' => 150], ['user_id' => 2, 'adjustment' => 0]]); });

// Every mode sums exactly
foreach ([
  ['equal', [['user_id' => 1], ['user_id' => 2], ['user_id' => 3], ['user_id' => 4], ['user_id' => 5], ['user_id' => 6], ['user_id' => 7]]],
  ['percentage', [['user_id' => 1, 'percentage' => 14.29], ['user_id' => 2, 'percentage' => 14.29], ['user_id' => 3, 'percentage' => 71.42]]],
  ['shares', [['user_id' => 1, 'shares' => 3], ['user_id' => 2, 'shares' => 7], ['user_id' => 3, 'shares' => 11]]],
  ['adjustment', [['user_id' => 1, 'adjustment' => 12.34], ['user_id' => 2, 'adjustment' => -5], ['user_id' => 3, 'adjustment' => 0]]],
] as [$type, $entries]) {
  foreach (($type === 'adjustment' ? [5000, 100001, 999999999] : [1, 99, 100001, 999999999]) as $total) {
    check("$type sums $total", array_sum(amounts(computeSplits($type, $total, 2, $entries))), $total);
  }
}

// ---- v1 / v2 payload reading
check('read v1', readSplitInput(['splitType' => 'equal', 'splits' => [['userId' => 4]]])[1][0]['user_id'], 4);
check('read v2', readSplitInput(['split_type' => 'shares', 'splits' => [['user_id' => 5, 'shares' => 2]]])[0], 'shares');
check('read none', readSplitInput(['description' => 'x'])[1], null);

// ---- balances
// A(1) pays 300 split 3 ways; B(2) pays 90 for A and B; C(3) pays A 50.
$ledger = [
  'expenses' => [
    ['id' => 1, 'paid_by' => 1, 'amount' => 30000, 'splits' => [1 => 10000, 2 => 10000, 3 => 10000]],
    ['id' => 2, 'paid_by' => 2, 'amount' => 9000, 'splits' => [1 => 4500, 2 => 4500]],
  ],
  'settlements' => [['from' => 3, 'to' => 1, 'amount' => 5000]],
];
$nets = ledgerNets($ledger);
check('nets', $nets, [1 => 10500, 2 => -5500, 3 => -5000]);  // A: +300 - 100 - 45 - 50 received
check('nets sum to zero', array_sum($nets), 0);

$raw = pairwiseDebts($ledger);
check('pairwise raw', $raw, [
  ['from' => 2, 'to' => 1, 'amount' => 5500],   // 100 - 45
  ['from' => 3, 'to' => 1, 'amount' => 5000],   // 100 - 50 paid back
]);
$simple = simplifyDebts($nets);
check('simplified', $simple, [
  ['from' => 2, 'to' => 1, 'amount' => 5500],
  ['from' => 3, 'to' => 1, 'amount' => 5000],
]);

// Simplify removes a chain: 1 owes 2, 2 owes 3 → 1 pays 3 directly
$chain = [
  'expenses' => [
    ['id' => 1, 'paid_by' => 2, 'amount' => 10000, 'splits' => [1 => 10000]],
    ['id' => 2, 'paid_by' => 3, 'amount' => 10000, 'splits' => [2 => 10000]],
  ],
  'settlements' => [],
];
check('chain raw has 2 debts', count(pairwiseDebts($chain)), 2);
check('chain simplified', simplifyDebts(ledgerNets($chain)), [['from' => 1, 'to' => 3, 'amount' => 10000]]);

// Each person's debts add up to their net, in both modes
foreach ([true, false] as $simplify) {
  foreach ([$ledger, $chain] as $i => $l) {
    $n = ledgerNets($l);
    $d = groupDebts($l, $simplify);
    foreach ($n as $uid => $v) {
      check("debts == net (simplify=" . ($simplify ? 1 : 0) . ", ledger $i, user $uid)", array_sum(debtsWith($d, $uid)), $v);
    }
  }
}

// Payment in the other direction: 1 pays 2 back what 2 never owed → 2 now owes 1
$over = ['expenses' => [], 'settlements' => [['from' => 1, 'to' => 2, 'amount' => 700]]];
check('payment creates reverse debt', pairwiseDebts($over), [['from' => 2, 'to' => 1, 'amount' => 700]]);

// 1-paisa residue from old rounding is treated as settled
$residue = ['expenses' => [['id' => 1, 'paid_by' => 1, 'amount' => 10000, 'splits' => [1 => 3333, 2 => 3333, 3 => 3333]]], 'settlements' => []];
check('residue ignored in simplify', count(simplifyDebts(ledgerNets($residue))), 2);

// Regression: the v1 suggestions query summed over a 4-way JOIN, so a member with 2 expenses and
// 2 settlements had each sum counted several times. The engine must not depend on row counts.
$many = [
  'expenses' => [
    ['id' => 1, 'paid_by' => 1, 'amount' => 10000, 'splits' => [1 => 5000, 2 => 5000]],
    ['id' => 2, 'paid_by' => 1, 'amount' => 10000, 'splits' => [1 => 5000, 2 => 5000]],
  ],
  'settlements' => [['from' => 2, 'to' => 1, 'amount' => 2000], ['from' => 2, 'to' => 1, 'amount' => 3000]],
];
check('join-inflation regression', simplifyDebts(ledgerNets($many)), [['from' => 2, 'to' => 1, 'amount' => 5000]]);

// Per-expense impact
$e = ['paid_by' => 1, 'amount' => 30000, 'splits' => [1 => 10000, 2 => 10000, 3 => 10000]];
check('impact payer', expenseNetFor($e, 1), 20000);
check('impact borrower', expenseNetFor($e, 2), -10000);
check('impact not involved', expenseNetFor($e, 9), 0);
check('pair impact payer side', expensePairImpact($e, 1, 2), 10000);
check('pair impact borrower side', expensePairImpact($e, 2, 1), -10000);
check('pair impact neither paid', expensePairImpact($e, 2, 3), 0);
check('expense nets', expenseNets($e), [1 => 20000, 2 => -10000, 3 => -10000]);

echo "$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
