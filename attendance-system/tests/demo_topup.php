<?php
/**
 * ------------------------------------------------------------
 * tests/demo_topup.php
 * Adds demo attendance for the meetings tests/demo_seed.php skipped on Oct 5-7, 2026: the seed left out
 * every class-day that already had a real (test) session, which left the last week of the dashboard graph
 * nearly empty. tests/ is not deployed.
 *
 *   php attendance-system/tests/demo_topup.php --dry-run   build it, print the report, ROLL BACK
 *   php attendance-system/tests/demo_topup.php --commit    the same, then COMMIT
 *
 * Needs the demo data from demo_seed.php --commit. Safe to run twice: a meeting whose session already has
 * demo attendance is skipped.
 *
 * How it differs from the seed: the meeting's session is whatever the app finds for it. Where a real session
 * already has the meeting's start time (Oct 5 IT301, Oct 6 IT401 BSIT) the demo students' scans are added
 * to it and the real session is left exactly as it was; otherwise the app opens a new session. Only DEMO
 * students get records, and the cleanup removes them with the students (a new session is also tracked).
 * The attendance warnings must not change: students set up for a warning always attend, and only a student
 * with no absence yet in the class can miss one of these meetings (so no new warning or limit notice).
 * Same app functions as the seed: ensure_session_for_occurrence(), get_late_grace_minutes(),
 * is_within_geofence(), insert_absent_records(), notify_new_absences(). One transaction.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/demo_common.php';
$mode = demo_mode($argv, "Usage: php attendance-system/tests/demo_topup.php --dry-run | --commit\n");
require_once __DIR__ . '/../qr/session_manager.php';
require_once __DIR__ . '/../includes/absences.php';
require_once __DIR__ . '/../includes/attendance_stats.php';
require_once __DIR__ . '/../includes/geo.php';

const TOPUP_FROM = '2026-10-05';
const TOPUP_TO = '2026-10-07';
const TOPUP_RANDOM_SEED = 20261005;    // same data on every run (dry run = what --commit will write)
const TOPUP_ABSENT_PERCENT = 6;        // chance that an eligible student misses one of these meetings

/** Standing of every demo student in every demo class: "student_id|class_id" => attendance_standing(). */
function topup_standings(PDO $pdo, array $demoUserOf): array {
    $out = [];
    foreach (DEMO_CLASS_IDS as $classId) {
        foreach (class_student_standings($pdo, $classId) as $studentId => $standing) {
            if (isset($demoUserOf[$studentId])) $out["$studentId|$classId"] = $standing;
        }
    }
    return $out;
}

$started = microtime(true);
$pdo = demo_connect();
if (!demo_tracking_ready($pdo)) {
    fwrite(STDERR, "Table demo_data_rows is missing: run database/supabase_demo_data.sql in Supabase first.\n");
    exit(2);
}
mt_srand(TOPUP_RANDOM_SEED);
$pdo->beginTransaction();

try {
    // ---- the demo students, their classes and what they have so far ----
    $profiles = [];
    foreach (DEMO_STUDENTS as $row) $profiles[$row[0]] = $row[5];
    $students = [];       // student_id => ['user_id', 'full_name', 'profile']
    foreach ($pdo->query("SELECT student_id, user_id, full_name FROM students WHERE student_number LIKE 'DEMO-%' ORDER BY student_id")->fetchAll() as $s) {
        $students[(int) $s['student_id']] = ['user_id' => (int) $s['user_id'], 'full_name' => $s['full_name'], 'profile' => $profiles[$s['full_name']] ?? []];
    }
    if (!$students) throw new RuntimeException('There are no demo students. Run tests/demo_seed.php --commit first.');
    $demoUserOf = array_map(fn($s) => $s['user_id'], $students);
    $demoArray = '{' . implode(',', array_keys($students)) . '}';

    $enrolledAt = [];     // student_id => [class_id => 'Y-m-d H:i:s']
    $st = $pdo->prepare("SELECT student_id, teacher_subject_id, to_char(enrolled_at, 'YYYY-MM-DD HH24:MI:SS') AS at
                         FROM enrollments WHERE status = 'enrolled' AND student_id = ANY(CAST(? AS int[]))");
    $st->execute([$demoArray]);
    foreach ($st->fetchAll() as $e) $enrolledAt[(int) $e['student_id']][(int) $e['teacher_subject_id']] = $e['at'];

    $absences = [];       // "student_id|class_id" => Absent records so far
    $st = $pdo->prepare("SELECT ar.student_id, s.teacher_subject_id, COUNT(*) AS n FROM attendance_records ar
                         JOIN attendance_sessions s ON s.session_id = ar.session_id
                         WHERE ar.status = 'Absent' AND ar.student_id = ANY(CAST(? AS int[])) GROUP BY ar.student_id, s.teacher_subject_id");
    $st->execute([$demoArray]);
    foreach ($st->fetchAll() as $a) $absences[$a['student_id'] . '|' . $a['teacher_subject_id']] = (int) $a['n'];

    $labs = [];
    foreach ($pdo->query('SELECT lab_id, latitude, longitude FROM laboratories')->fetchAll() as $l) $labs[(int) $l['lab_id']] = $l;
    $teacherUserOf = [];
    foreach ($pdo->query('SELECT teacher_id, user_id FROM teachers')->fetchAll() as $t) $teacherUserOf[(int) $t['teacher_id']] = (int) $t['user_id'];

    // Same stop as the seed: nothing after the absence watermark (the live app would mark it again).
    $now = schedule_now();
    $watermark = get_setting($pdo, ABSENCE_WATERMARK_KEY);
    $cutoff = $watermark ? min($now, at($watermark)) : $now;

    // ---- the meetings still without demo attendance ----
    $todo = [];
    foreach (get_occurrences($pdo, TOPUP_FROM, TOPUP_TO, ['teacher_subject_id' => DEMO_CLASS_IDS, 'include_cancelled' => false]) as $occ) {
        if (at($occ['ends_at']) > $cutoff) continue;
        $session = find_session_for_occurrence($pdo, $occ);
        if ($session) {
            $has = $pdo->prepare('SELECT COUNT(*) FROM attendance_records WHERE session_id = ? AND student_id = ANY(CAST(? AS int[]))');
            $has->execute([$session['session_id'], $demoArray]);
            if ((int) $has->fetchColumn() > 0) continue;   // the seed's own meeting, or a previous top-up
        }
        $todo[] = $occ + ['existing_session' => $session];
    }
    usort($todo, fn($a, $b) => [$a['ends_at'], $a['teacher_subject_id']] <=> [$b['ends_at'], $b['teacher_subject_id']]);
    if (!$todo) { echo "Nothing to do: every meeting from " . TOPUP_FROM . ' to ' . TOPUP_TO . " already has demo attendance.\n"; $pdo->rollBackAll(); exit(0); }

    $before = topup_standings($pdo, $demoUserOf);
    say(count($todo) . ' meetings to fill');

    // ---- replay them in time order ----
    $report = [];
    foreach ($todo as $occ) {
        $classId = $occ['teacher_subject_id'];
        $key = $occ['occurrence_key'];
        $start = at($occ['starts_at']);
        $end = at($occ['ends_at']);
        $existed = $occ['existing_session'];

        $session = ensure_session_for_occurrence($pdo, $occ, $occ['starts_at']);
        if (!$session) throw new RuntimeException("No session for $key (lab without GPS?)");
        $sid = (int) $session['session_id'];

        $roster = [];   // students enrolled by the start of this meeting
        foreach ($students as $studentId => $s) {
            if (($enrolledAt[$studentId][$classId] ?? '9999') <= $occ['starts_at']) $roster[$studentId] = $s;
        }
        if (!$roster) throw new RuntimeException("$key: no demo student is enrolled by then");
        if (!$existed) {
            // The app told every enrolled student, real ones too: keep only the demo roster's notices.
            $pdo->prepare("DELETE FROM notifications WHERE created_at = LOCALTIMESTAMP AND title = 'Attendance Open' AND user_id <> ALL(CAST(? AS int[]))")
                ->execute(['{' . implode(',', array_column($roster, 'user_id')) . '}']);
        }
        demo_stamp($pdo, ts($start));

        // Who misses it: only a student with no absence in this class yet and no warning to keep.
        $missing = [];
        foreach ($roster as $studentId => $s) {
            if (isset($s['profile']['absent']) || ($absences["$studentId|$classId"] ?? 0) > 0) continue;
            if (mt_rand(1, 100) <= TOPUP_ABSENT_PERCENT) { $missing[$studentId] = true; $absences["$studentId|$classId"] = 1; }
        }

        $grace = get_late_grace_minutes($pdo, $occ['late_grace_minutes'] ?? null);
        $lateAfter = $start->modify("+$grace minutes");
        $lab = $labs[$occ['lab_id']];
        $radius = (int) $session['allowed_radius_meters'];
        $length = $end->getTimestamp() - $start->getTimestamp();
        $records = $notes = $logs = [];
        $present = $late = 0;
        foreach ($roster as $studentId => $s) {
            if (isset($missing[$studentId])) continue;
            if (rnd() < ($s['profile']['late'] ?? 0.10)) {
                $time = $lateAfter->modify('+' . mt_rand(60, max(120, min(40 * 60, $length - $grace * 60 - 300))) . ' seconds');
            } else {
                $time = $start->modify('+' . (int) (rnd() ** 2 * $grace * 60) . ' seconds');
            }
            $status = $time > $lateAfter ? 'Late' : 'Present';   // student/ajax_scan.php
            [$lat, $lon] = point_near((float) $lab['latitude'], (float) $lab['longitude'], 2, min(35, $radius * 0.6));
            [$inside, $distance] = is_within_geofence($lat, $lon, (float) $lab['latitude'], (float) $lab['longitude'], $radius);
            if (!$inside) throw new RuntimeException("$key: a generated point fell outside the $radius m radius");
            $records[] = [$sid, $studentId, ts($time), $status, $lat, $lon, mt_rand(4, 20), $distance, ts($time)];
            $notes[] = [$s['user_id'], 'Attendance Recorded', "You were marked $status for {$occ['subject_name']} in {$occ['lab_name']}.", ts($time)];
            $logs[] = [$s['user_id'], 'Logged in', ts($time->modify('-' . mt_rand(40, 300) . ' seconds'))];
            $logs[] = [$s['user_id'], "Scanned attendance for session #$sid - $status ({$distance}m)", ts($time)];
            $status === 'Late' ? $late++ : $present++;
        }
        if ($records) {
            insert_rows($pdo, 'attendance_records', ['session_id', 'student_id', 'time_in', 'status', 'latitude', 'longitude', 'location_accuracy', 'distance_from_location', 'created_at'], $records);
            insert_rows($pdo, 'notifications', ['user_id', 'title', 'message', 'created_at'], $notes);
            track($pdo, 'activity_logs', insert_rows($pdo, 'activity_logs', ['user_id', 'action', 'created_at'], $logs, 'log_id'));
        }

        // The meeting ends: a session the app opened is closed; then the absence marker runs for the demo roster.
        if (!$existed) $pdo->prepare('UPDATE attendance_sessions SET is_active = 0, deactivated_at = session_end WHERE session_id = ?')->execute([$sid]);
        $sessionEnd = at($session['session_end']);
        $added = insert_absent_records($pdo, ts($sessionEnd->modify('-1 second')), ts($sessionEnd), [$sid], array_keys($students));
        if (count($added) !== count($missing)) throw new RuntimeException("$key: expected " . count($missing) . ' absences, the app marked ' . count($added));
        notify_new_absences($pdo, $added);
        demo_stamp($pdo, ts($sessionEnd->modify('+' . mt_rand(1, 25) . ' minutes')));

        $report[] = ["#$classId {$occ['subject_code']} {$occ['section']}", $occ['date'], substr($occ['start_time'], 0, 5) . '-' . substr($occ['end_time'], 0, 5),
                     $existed ? "real #$sid (kept as is)" : "new #$sid", count($roster), $present, $late, count($missing)];
        say(count($report) . '/' . count($todo) . ' meetings');
    }

    // Notifications more than three days old have been seen by now (same rule as the seed).
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND created_at < ? AND user_id = ANY(CAST(? AS int[]))')
        ->execute([ts($now->modify('-3 days')), '{' . implode(',', $demoUserOf) . '}']);

    // ---------------- report ----------------
    echo "\nTop-up " . TOPUP_FROM . ' to ' . TOPUP_TO . ' (up to ' . ts($cutoff) . "), demo students only:\n";
    demo_table($report, ['class', 'date', 'time', 'session', 'roster', 'Present', 'Late', 'Absent']);

    $after = topup_standings($pdo, $demoUserOf);
    $changed = [];
    foreach ($after as $k => $s) {
        $b = $before[$k] ?? null;
        if (!$b || $b['level'] !== $s['level'] || $b['absent'] !== $s['absent']) {
            [$studentId, $classId] = explode('|', $k);
            $changed[] = [$students[(int) $studentId]['full_name'], "#$classId", $b ? "{$b['level']}, {$b['absent']} absent" : '(none)', "{$s['level']}, {$s['absent']} absent"];
        }
    }
    echo "\nAttendance warnings after the top-up:\n";
    $warn = [];
    foreach ($after as $k => $s) {
        if ($s['level'] === STANDING_OK) continue;
        [$studentId, $classId] = explode('|', $k);
        $warn[] = [$students[(int) $studentId]['full_name'], "#$classId", "{$s['attended']}/{$s['meetings']}", $s['absent'], $s['rate'] . '%', $s['label']];
    }
    demo_table($warn, ['student', 'class', 'attended', 'absent', 'rate', 'warning']);
    echo "\nStandings that changed (level or absence count):\n";
    demo_table($changed, ['student', 'class', 'before', 'after']);
    $levelChanges = count(array_filter($after, fn($s, $k) => ($before[$k]['level'] ?? null) !== $s['level'], ARRAY_FILTER_USE_BOTH));
    echo "\nWarning levels changed for $levelChanges student/class pair(s).\n";

    if ($mode === 'commit') {
        $pdo->commit();
        echo "\nCOMMITTED in " . round(microtime(true) - $started) . "s. Undo everything demo with: php attendance-system/tests/demo_cleanup.php --commit\n";
    } else {
        $pdo->rollBackAll();
        echo "\nDRY RUN: rolled back, nothing saved (" . round(microtime(true) - $started) . "s).\n";
    }
} catch (Throwable $e) {
    $pdo->rollBackAll();
    fwrite(STDERR, "\nFAILED, nothing was saved: " . $e->getMessage() . "\n  at " . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
