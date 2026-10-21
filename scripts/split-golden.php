<?php
/**
 * Writes the split goldens shared with the Android app's SplitCalculatorGoldenTest:
 *   php scripts/split-golden.php [output path]
 * Default output: ../android/app/src/test/resources/splits/golden.json
 *
 * Run it after any change to utils/splits.php, then run the Android unit tests: both
 * implementations must produce identical paise for every case (or the same error).
 */
require_once __DIR__ . '/../utils/splits.php';

$out = $argv[1] ?? (__DIR__ . '/../../android/app/src/test/resources/splits/golden.json');

$people = function (array $ids, ?string $field = null, array $values = []) {
  $e = [];
  foreach ($ids as $i => $id) {
    $row = ['user_id' => $id];
    if ($field !== null) {
      $row[$field] = $values[$i];
    }
    $e[] = $row;
  }
  return $e;
};

$cases = [];
$add = function (string $name, string $type, int $total, int $payer, array $entries) use (&$cases) {
  $cases[] = ['name' => $name, 'type' => $type, 'total_paise' => $total, 'payer_id' => $payer, 'entries' => $entries];
};

foreach ([1, 2, 7, 99, 100, 10000, 10001, 33333, 123456789] as $total) {
  foreach ([[1, 2, 3], [5, 3, 9, 1], [4], [2, 7]] as $ids) {
    foreach ([$ids[0], 99] as $payer) {
      $add("equal $total/" . count($ids) . " payer $payer", 'equal', $total, $payer, $people($ids));
    }
  }
}
$add('exact 200+300', 'unequal', 50000, 1, $people([1, 2], 'amount', [200, 300]));
$add('exact thirds 1p short', 'unequal', 10000, 2, $people([1, 2, 3], 'amount', [33.33, 33.33, 33.33]));
$add('exact with zero', 'unequal', 10000, 1, $people([1, 2], 'amount', [100, 0]));
$add('exact mismatch', 'unequal', 10000, 1, $people([1, 2], 'amount', [40, 50]));
$add('exact negative', 'unequal', 10000, 1, $people([1, 2], 'amount', [150, -50]));
$add('pct 50/25/25', 'percentage', 10001, 1, $people([1, 2, 3], 'percentage', [50, 25, 25]));
$add('pct thirds', 'percentage', 10000, 2, $people([1, 2, 3], 'percentage', [33.33, 33.33, 33.34]));
$add('pct 12.5 x8', 'percentage', 99999, 3, $people([1, 2, 3, 4, 5, 6, 7, 8], 'percentage', array_fill(0, 8, 12.5)));
$add('pct not 100', 'percentage', 10000, 1, $people([1, 2], 'percentage', [60, 30]));
$add('shares 2:1', 'shares', 30000, 1, $people([1, 2], 'shares', [2, 1]));
$add('shares 1.5:1:1', 'shares', 10000, 3, $people([1, 2, 3], 'shares', [1.5, 1, 1]));
$add('shares 3:7:11', 'shares', 123457, 2, $people([1, 2, 3], 'shares', [3, 7, 11]));
$add('shares all zero', 'shares', 10000, 1, $people([1, 2], 'shares', [0, 0]));
$add('adj +100', 'adjustment', 50000, 1, $people([1, 2], 'adjustment', [100, 0]));
$add('adj mixed', 'adjustment', 123456, 2, $people([1, 2, 3], 'adjustment', [12.34, -5, 0]));
$add('adj too big', 'adjustment', 10000, 1, $people([1, 2], 'adjustment', [150, 0]));
$add('adj negative share', 'adjustment', 1000, 2, $people([1, 2, 3], 'adjustment', [12.34, -5, 0]));
$add('duplicate person', 'equal', 100, 1, $people([1, 1]));
$add('nobody', 'equal', 100, 1, []);

$golden = [];
foreach ($cases as $c) {
  try {
    $result = computeSplits($c['type'], $c['total_paise'], $c['payer_id'], $c['entries']);
    $c['expected'] = array_map(function ($s) { return ['user_id' => $s['user_id'], 'amount_paise' => $s['amount']]; }, $result);
    $c['error'] = null;
  } catch (SplitError $e) {
    $c['expected'] = null;
    $c['error'] = $e->getMessage();
  }
  $golden[] = $c;
}

if (!is_dir(dirname($out))) {
  mkdir(dirname($out), 0777, true);
}
file_put_contents($out, json_encode(['generated_by' => 'server/scripts/split-golden.php', 'cases' => $golden], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo count($golden) . " cases → $out\n";
