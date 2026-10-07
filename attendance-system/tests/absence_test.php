<?php
/**
 * Run: php attendance-system/tests/absence_test.php
 * Tests how Absent records are displayed and that pages don't count them as check-ins (no database needed).
 * The marking itself (includes/absences.php) is SQL and was checked against the real schema with EXPLAIN.
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$scan = ['time_in' => '2026-10-07 09:12:00', 'status' => 'Present'];
$late = ['time_in' => '2026-10-07 09:40:00', 'status' => 'Late'];
$abs  = ['time_in' => '2026-10-07 11:00:00', 'status' => 'Absent'];   // time_in = end of the meeting
check('Present shows the check-in time', format_record_time($scan) === 'Oct 07, 2026 09:12 AM');
check('Late shows the check-in time', format_record_time($late) === 'Oct 07, 2026 09:40 AM');
check('Absent shows the date and "no check-in", not a time', format_record_time($abs) === 'Oct 07, 2026 · no check-in');
check('Absent never shows the meeting end time', strpos(format_record_time($abs), '11:00') === false);

// Pages that list records must go through format_record_time(), or an absent student would
// look like they checked in at the end of class.
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    if ($f->getExtension() !== 'php' || strpos($path, '/tests/') !== false || strpos($path, '/includes/functions.php') !== false) continue;
    if (strpos(file_get_contents($path), "format_datetime(\$r['time_in'])") !== false) $bad[] = basename($path);
}
check('no page formats a record time directly (' . implode(',', $bad) . ')', !$bad);

// Counts of "scans / check-ins / times attended" must ignore Absent records.
$must = [
    'admin/dashboard.php' => 'status <> "Absent"',
    'teacher/dashboard.php' => 'ar.status <> "Absent"',
    'student/my_subjects.php' => 'ar.status <> "Absent"',
    'teacher/class_view.php' => 'ar.status <> "Absent"',
    'admin/qr_management.php' => 'ar.status <> "Absent"',
];
foreach ($must as $file => $needle) {
    check("$file ignores Absent in its counts", strpos(file_get_contents(__DIR__ . '/../' . $file), $needle) !== false);
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
