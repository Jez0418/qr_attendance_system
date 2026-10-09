<?php
/**
 * Run: php attendance-system/tests/session_move_test.php
 * A class's START time moved while its session was running (qr/session_manager.php): the session is
 * re-attached to the meeting instead of a second one being opened, and its scans are re-checked for Late.
 * Pure logic, no database. tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../qr/session_manager.php';   // before any output (config.php starts a session)

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$occ = fn(string $start, string $end, bool $cancelled = false) => ['starts_at' => "2026-10-09 $start", 'ends_at' => "2026-10-09 $end", 'is_cancelled' => $cancelled];
$sess = fn(int $id, string $start, string $end) => ['session_id' => $id, 'scheduled_start' => "2026-10-09 $start", 'session_end' => "2026-10-09 $end"];
$starts = fn(array $pairs) => array_map(fn($o) => substr($o['starts_at'], 11), $pairs);
$now = '2026-10-09 17:28:00';

check('start moved later: the running session follows it',
    $starts(pair_moved_sessions([$sess(177, '17:20:00', '18:00:00')], [$occ('17:30:00', '18:00:00')], $now)) === [177 => '17:30:00']);
check('start moved earlier: the running session follows it',
    $starts(pair_moved_sessions([$sess(177, '17:20:00', '18:00:00')], [$occ('17:00:00', '18:00:00')], $now)) === [177 => '17:00:00']);
check('start unchanged: nothing moves',
    pair_moved_sessions([$sess(177, '17:20:00', '18:00:00')], [$occ('17:20:00', '18:00:00')], $now) === []);
check('a session that already ended is a record and never moves',
    pair_moved_sessions([$sess(170, '08:00:00', '10:00:00')], [$occ('08:30:00', '10:00:00')], $now) === []);
check('a cancelled meeting gets no session',
    pair_moved_sessions([$sess(177, '17:20:00', '18:00:00')], [$occ('17:30:00', '18:00:00', true)], $now) === []);
check('a meeting that already has its session is not given a second one',
    pair_moved_sessions([$sess(177, '17:20:00', '18:00:00'), $sess(178, '17:30:00', '18:00:00')], [$occ('17:30:00', '18:00:00')], $now) === []);
check('two meetings that day: the moved session goes to the nearest start',
    $starts(pair_moved_sessions([$sess(177, '17:20:00', '18:00:00')], [$occ('08:00:00', '09:00:00'), $occ('17:25:00', '18:00:00')], $now)) === [177 => '17:25:00']);
check('two meetings that day: the one that kept its time keeps its session, the moved one gets the other',
    $starts(pair_moved_sessions([$sess(176, '13:00:00', '18:30:00'), $sess(177, '17:20:00', '18:00:00')],
        [$occ('13:00:00', '18:30:00'), $occ('17:40:00', '18:00:00')], $now)) === [177 => '17:40:00']);
check('the time format of the start does not matter',
    pair_moved_sessions([$sess(177, '17:20:00', '18:00:00')], [['starts_at' => '2026-10-09 17:20', 'ends_at' => '2026-10-09 18:00', 'is_cancelled' => false]], $now) === []);

// Late re-checked against the new start (start 17:30, grace 15 -> Late after 17:45)
$rec = fn(string $status, string $time, $by = null) => ['status' => $status, 'time_in' => "2026-10-09 $time", 'marked_by_user_id' => $by];
check('Late becomes Present when the new start makes it on time', rechecked_scan_status($rec('Late', '17:40:00'), '2026-10-09 17:30:00', 15) === 'Present');
check('Present becomes Late when the new start makes it late', rechecked_scan_status($rec('Present', '17:50:00'), '2026-10-09 17:30:00', 15) === 'Late');
check('exactly at the end of the grace is still Present', rechecked_scan_status($rec('Late', '17:45:00'), '2026-10-09 17:30:00', 15) === 'Present');
check('Absent is never changed', rechecked_scan_status($rec('Absent', '18:00:00'), '2026-10-09 17:30:00', 15) === 'Absent');
check('a teacher\'s manual Present is never changed', rechecked_scan_status($rec('Present', '17:55:00', 6), '2026-10-09 17:30:00', 15) === 'Present');

// Wiring: every place that matches a session to its meeting re-attaches first.
$sm = file_get_contents(__DIR__ . '/../qr/session_manager.php');
check('ensure_session_for_occurrence() re-attaches before opening a new session', strpos($sm, "if (!\$found && reattach_moved_sessions(") !== false);
check('find_occurrence_for_session() re-attaches when the start no longer matches', strpos($sm, "if (!reattach_moved_sessions(\$pdo, (int) \$session['teacher_subject_id']") !== false);
check('re-attaching takes the same lock as opening a session', substr_count($sm, "'attendance-session:' . \$occ['occurrence_key']") >= 2);
check('a scan uses find_occurrence_for_session()', strpos(file_get_contents(__DIR__ . '/../student/ajax_scan.php'), 'find_occurrence_for_session($pdo, $session, $now)') !== false);
check('saving a class re-attaches today\'s sessions', strpos(file_get_contents(__DIR__ . '/../admin/ajax_assignments.php'), "reattach_moved_sessions(\$pdo, \$classId, schedule_now()->format('Y-m-d'))") !== false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
