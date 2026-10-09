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

// Admin dashboard: absences appear in "Recent Attendance Activity" and in the attendance graph,
// but "Laboratory Usage Today" counts scans only.
$dash = file_get_contents(__DIR__ . '/../admin/dashboard.php');
check('recent activity no longer hides Absent records', strpos($dash, 'WHERE ar.status <> "Absent"') === false);
check('recent activity words an absence differently from a check-in', strpos($dash, 'was absent from') !== false);
check('attendance graph counts Absent per day', strpos($dash, 'SUM(status = "Absent")') !== false && strpos($dash, "label: 'Absent'") !== false);
check('laboratory usage counts scans only', strpos($dash, 'ar.session_id = s.session_id AND ar.status <> "Absent"') !== false);

// A student enrolled after the meeting started (but before it ended) could still scan, so must still be
// marked absent. Comparing against scheduled_start silently skipped them.
$sql = file_get_contents(__DIR__ . '/../includes/absences.php');
check('absence marking counts students enrolled during the meeting', strpos($sql, 'e.enrolled_at <= s.session_end') !== false);
check('absence marking does not require enrolment before the meeting start', strpos($sql, 'enrolled_at <= COALESCE(s.scheduled_start') === false);

// Meetings nobody opened (create_missed_sessions): pick the ended, scheduled meetings without a session.
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/absences.php';
$occ = fn(int $class, string $date, string $start, string $end, int $lab = 1, bool $cancelled = false) => [
    'teacher_subject_id' => $class, 'date' => $date, 'starts_at' => "$date $start", 'ends_at' => "$date $end",
    'lab_id' => $lab, 'is_cancelled' => $cancelled,
];
$meetings = [
    $occ(5, '2026-10-09', '08:00:00', '10:30:00'),                 // ended, no session -> needs one
    $occ(6, '2026-10-09', '08:00:00', '11:00:00'),                 // ended, already has a session
    $occ(7, '2026-10-09', '13:00:00', '14:30:00', 1, true),        // cancelled
    $occ(8, '2026-10-09', '09:00:00', '10:00:00', 2),              // lab without GPS
    $occ(4, '2026-10-09', '15:00:00', '17:00:00'),                 // not over yet
    $occ(2, '2026-10-08', '08:00:00', '09:00:00'),                 // ended before the window
];
$session = fn(int $class, string $start, ?string $end) => ['teacher_subject_id' => $class, 'scheduled_start' => $start, 'session_end' => $end];
$picked = select_missed_meetings($meetings, [$session(6, '2026-10-09 08:00', '2026-10-09 11:00:00')], [1 => true],
    '2026-10-08 12:00:00', '2026-10-09 16:00:00');
check('a meeting that ended without a session gets one', array_column($picked, 'teacher_subject_id') === [5]);
check('a session opened late by hand (08:52) counts as that meeting\'s session',
    select_missed_meetings([$meetings[0]], [$session(5, '2026-10-09 08:52:00', '2026-10-09 11:52:00')], [1 => true], '2026-10-09 00:00:00', '2026-10-09 23:00:00') === []);
check('a session of another class does not count',
    count(select_missed_meetings([$meetings[0]], [$session(6, '2026-10-09 08:00:00', '2026-10-09 10:30:00')], [1 => true], '2026-10-09 00:00:00', '2026-10-09 23:00:00')) === 1);
check('a same-class session that day that does not overlap does not count',
    count(select_missed_meetings([$meetings[0]], [$session(5, '2026-10-09 13:00:00', '2026-10-09 14:00:00')], [1 => true], '2026-10-09 00:00:00', '2026-10-09 23:00:00')) === 1);
check('a meeting ending exactly at the window start is not picked twice',
    select_missed_meetings([$occ(5, '2026-10-09', '08:00:00', '10:30:00')], [], [1 => true], '2026-10-09 10:30:00', '2026-10-09 11:00:00') === []);
check('a meeting ending exactly at the window end is picked',
    count(select_missed_meetings([$occ(5, '2026-10-09', '08:00:00', '10:30:00')], [], [1 => true], '2026-10-09 10:00:00', '2026-10-09 10:30:00')) === 1);
check('the same meeting listed twice gets one session',
    count(select_missed_meetings([$meetings[0], $meetings[0]], [], [1 => true], '2026-10-09 00:00:00', '2026-10-09 23:00:00')) === 1);
check('the absence run creates missed sessions before marking absences',
    preg_match('/create_missed_sessions\(\$pdo, \$after, \$until\);\s*\$added = insert_absent_records/', $sql) === 1);
check('missed sessions are created closed', strpos($sql, "VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'teacher', NULL, ?)") !== false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
