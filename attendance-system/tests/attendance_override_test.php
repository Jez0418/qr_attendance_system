<?php
/**
 * Run: php attendance-system/tests/attendance_override_test.php
 * Tests the teacher "Absent -> Present" override rules and how a manual Present is displayed (no database needed).
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attendance_override.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$abs = ['status' => 'Absent', 'teacher_id' => 7];
check('own class, Absent, with a reason: allowed', override_problem($abs, 7, 'Scanner was broken') === '');
check('record not found', override_problem(null, 7, 'reason') !== '');
check("someone else's class is refused", override_problem($abs, 8, 'reason') !== '');
check("someone else's class looks the same as a missing record", override_problem($abs, 8, 'reason') === override_problem(null, 7, 'reason'));
check('teacher id 0 is refused', override_problem(['status' => 'Absent', 'teacher_id' => 0], 0, 'reason') !== '');
foreach (['Present', 'Late'] as $s) {
    check("$s record cannot be overridden", override_problem(['status' => $s, 'teacher_id' => 7], 7, 'reason') !== '');
}
check('reason is required', override_problem($abs, 7, '') !== '');
check('too-short reason is refused', override_problem($abs, 7, 'ok') !== '');
check('reason of exactly 3 characters is accepted', override_problem($abs, 7, 'abc') === '');
check('reason of 255 characters is accepted', override_problem($abs, 7, str_repeat('a', 255)) === '');
check('reason over 255 characters is refused', override_problem($abs, 7, str_repeat('a', 256)) !== '');
check('multibyte reason is counted in characters, not bytes', override_problem($abs, 7, str_repeat('é', 255)) === '');

// Display: a manual Present must not look like a scan.
$scan   = ['time_in' => '2026-10-07 11:00:00', 'status' => 'Present', 'marked_by_user_id' => null];
$manual = ['time_in' => '2026-10-07 11:00:00', 'status' => 'Present', 'marked_by_user_id' => 5];
check('a scanned Present shows its time', format_record_time($scan) === 'Oct 07, 2026 11:00 AM');
check('a manual Present says it was marked by the teacher', format_record_time($manual) === 'Oct 07, 2026 · marked by teacher');
check('a manual Present does not show a fake check-in time', strpos(format_record_time($manual), '11:00') === false);
check('rows without the column still render', format_record_time(['time_in' => '2026-10-07 11:00:00', 'status' => 'Present']) === 'Oct 07, 2026 11:00 AM');

// Excused: the same override, with 'Excused' as the target (one-way, like Present).
check('Absent -> Excused allowed', override_problem($abs, 7, 'Medical certificate', 'Excused') === '');
check('three-argument call still means Present', override_problem($abs, 7, 'reason') === override_problem($abs, 7, 'reason', 'Present'));
foreach (['Late', 'Absent', 'excused', 'EXCUSED', '', 'Present ', 'Excused;'] as $t) {
    check("target '$t' is refused", override_problem($abs, 7, 'reason', $t) !== '');
}
foreach (['Present', 'Late', 'Excused'] as $s) {
    check("$s record cannot be excused", override_problem(['status' => $s, 'teacher_id' => 7], 7, 'reason', 'Excused') !== '');
}
check('Excused record cannot become Present', override_problem(['status' => 'Excused', 'teacher_id' => 7], 7, 'reason', 'Present') !== '');
check("someone else's class is refused for Excused too", override_problem($abs, 8, 'reason', 'Excused') === override_problem(null, 7, 'reason', 'Excused'));
$exc = ['time_in' => '2026-10-07 11:00:00', 'status' => 'Excused', 'marked_by_user_id' => 5];
check('an Excused row says excused', format_record_time($exc) === 'Oct 07, 2026 · excused');
check('an Excused row shows no check-in time', strpos(format_record_time($exc), '11:00') === false);
check('override_absent_to_present() is gone', !function_exists('override_absent_to_present'));
check('override_absent() exists', function_exists('override_absent'));
check('endpoint refuses a non-string target', strpos(file_get_contents(__DIR__ . '/../teacher/ajax_attendance_override.php'), 'is_string($target)') !== false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
