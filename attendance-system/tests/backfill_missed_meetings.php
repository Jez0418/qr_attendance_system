<?php
/**
 * ------------------------------------------------------------
 * tests/backfill_missed_meetings.php
 * One-off: meetings that ended before create_missed_sessions() existed (includes/absences.php) and that
 * nobody opened got no session, so nobody was marked absent. This runs the same app functions for a
 * past window: create_missed_sessions(), insert_absent_records(), notify_new_absences().
 * tests/ is not deployed.
 *
 *   php attendance-system/tests/backfill_missed_meetings.php --dry-run   do it, print the report, ROLL BACK
 *   php attendance-system/tests/backfill_missed_meetings.php --commit    the same, then COMMIT
 *
 * Window: (BACKFILL_FROM, the absence watermark]. Meetings after the watermark are the live marker's job.
 * Safe to run twice: a meeting that already has a session is skipped and absences are ON CONFLICT DO NOTHING.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/demo_common.php';
$mode = demo_mode($argv, "Usage: php attendance-system/tests/backfill_missed_meetings.php --dry-run | --commit\n");
require_once __DIR__ . '/../includes/absences.php';

const BACKFILL_FROM = '2026-10-08 00:00:00';

$pdo = demo_connect();
$pdo->beginTransaction();
try {
    // Same lock as the live marker, so a page load can't mark the same meetings at the same moment.
    $pdo->query("SELECT pg_advisory_xact_lock(hashtext('absence-marker'))");
    $w = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $w->execute([ABSENCE_WATERMARK_KEY]);
    $until = (string) $w->fetchColumn();
    if ($until === '') throw new RuntimeException('No absence watermark yet: nothing to back-fill.');
    say("Window: after " . BACKFILL_FROM . " up to $until (Manila time)");

    $sessionIds = create_missed_sessions($pdo, BACKFILL_FROM, $until);
    say(count($sessionIds) . ' missed meeting(s) get a closed session:');
    $rows = [];
    if ($sessionIds) {
        $st = $pdo->prepare("
            SELECT s.session_id, sub.subject_code, ts.section, s.scheduled_start, s.session_end,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.teacher_subject_id = s.teacher_subject_id AND e.status = 'enrolled'
                       AND (e.enrolled_at IS NULL OR e.enrolled_at <= s.session_end)) AS enrolled
            FROM attendance_sessions s
            JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
            JOIN subjects sub ON sub.subject_id = ts.subject_id
            WHERE s.session_id = ANY(CAST(? AS int[])) ORDER BY s.scheduled_start
        ");
        $st->execute(['{' . implode(',', $sessionIds) . '}']);
        $rows = $st->fetchAll();
    }
    demo_table(array_map(fn($r) => [$r['session_id'], $r['subject_code'], $r['section'], $r['scheduled_start'], $r['session_end'], $r['enrolled']], $rows),
        ['session', 'class', 'section', 'start', 'end', 'enrolled']);

    $added = insert_absent_records($pdo, BACKFILL_FROM, $until);
    $demo = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_id = ANY(CAST(? AS int[])) AND student_number LIKE 'DEMO-%'");
    $demo->execute(['{' . implode(',', array_map(fn($p) => (int) $p['student_id'], $added)) . '}']);
    $demoCount = (int) $demo->fetchColumn();
    say(count($added) . " Absent record(s) added ($demoCount for DEMO- students, " . (count($added) - $demoCount) . ' for real students)');

    $before = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    notify_new_absences($pdo, $added);
    say(((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() - $before) . ' notification(s) ("Marked Absent", plus any "Absence Limit Reached" for teachers)');

    if ($mode === 'commit') {
        $pdo->commit();
        say('COMMITTED.');
    } else {
        $pdo->rollBackAll();
        say('Dry run: everything rolled back, nothing was saved.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBackAll();
    fwrite(STDERR, 'Failed, rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}
