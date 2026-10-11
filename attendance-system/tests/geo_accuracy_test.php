<?php
/**
 * Run: php attendance-system/tests/geo_accuracy_test.php
 * Every scan must carry a real GPS accuracy (includes/geo.php gps_accuracy_problem()). A hand-made request
 * that leaves it out used to skip the accuracy check. No database needed. tests/ is not deployed.
 */
require_once __DIR__ . '/../includes/geo.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$missing = gps_accuracy_problem(null, 100);
check('missing accuracy is refused', $missing !== '');
foreach (['' => "''", 'abc' => "'abc'", '0' => "'0'", '-5' => "'-5'", ' ' => "' '", '1e999' => "'1e999' (infinite)"] as $v => $label) {
    check("accuracy $label is refused like a missing one", gps_accuracy_problem((string) $v, 100) === $missing);
}
check('accuracy 0 (number) is refused', gps_accuracy_problem(0, 100) === $missing);
check('NAN is refused', gps_accuracy_problem(NAN, 100) === $missing);
check('an array is refused', gps_accuracy_problem(['12'], 100) === $missing);
check('a normal reading passes', gps_accuracy_problem('12.5', 100) === '');
check('a numeric float passes', gps_accuracy_problem(12.5, 100) === '');
check('exactly the limit passes', gps_accuracy_problem('100', 100) === '');
$tooWeak = gps_accuracy_problem('100.01', 100);
check('just over the limit is refused', $tooWeak !== '');
check('too weak and missing give different messages', $tooWeak !== $missing);
check('too weak keeps the existing wording', strpos($tooWeak, 'accuracy is too low') !== false);

$scan = file_get_contents(__DIR__ . '/../student/ajax_scan.php');
check('ajax_scan.php uses gps_accuracy_problem()', strpos($scan, 'gps_accuracy_problem(') !== false);
check('ajax_scan.php no longer skips the check when accuracy is missing', strpos($scan, '$accuracy !== null && $accuracy !== \'\' && is_numeric($accuracy) && (float) $accuracy > $maxAccuracy') === false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
