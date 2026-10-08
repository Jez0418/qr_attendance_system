<?php
/**
 * ------------------------------------------------------------
 * tests/demo_seed.php
 * Fills the database with a demo semester (Aug 10, 2026 up to now) so the dashboards, history,
 * reports and attendance warnings look like a real term in progress. tests/ is not deployed.
 *
 *   php attendance-system/tests/demo_seed.php --dry-run   build it all, print the counts, ROLL BACK
 *   php attendance-system/tests/demo_seed.php --commit    the same, then COMMIT
 *
 * Needs: DB_* and DEMO_PASSWORD (environment or the git-ignored attendance-system/.env),
 *        database/supabase_demo_data.sql (tracking table) and realistic class times
 *        (database/supabase_fix_class_times.sql). Undo with tests/demo_cleanup.php.
 *
 * Everything demo is marked: student numbers DEMO-0001.., emails ...@demo.qr-attendance.test, and every
 * other row it creates (sessions, schedule exceptions, activity logs, teachers' notifications) is listed in
 * demo_data_rows. Only DEMO students get attendance: the existing accounts in these classes are left alone.
 *
 * The app's own rules do the work, replayed meeting by meeting in time order:
 *   meetings        get_occurrences() (class_schedules + schedule_exceptions; cancelled = no session)
 *   sessions        ensure_session_for_occurrence() with "now" = the meeting's start
 *   Present / Late  get_late_grace_minutes() and the scan endpoint's rule (late after start + grace)
 *   GPS             is_within_geofence() against the meeting's lab and the session's radius
 *   absences        insert_absent_records() + notify_new_absences() when the meeting ends
 *   overrides       override_absent_to_present()
 *   enrollment      enrollment_block_reason() / student_eligible_for_class(), schedule_rules_conflict()
 * Rows those functions create get their real date afterwards (see demo_stamp()).
 * One transaction (tests/demo_common.php): any error and nothing is saved.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/demo_common.php';
$mode = demo_mode($argv, "Usage: php attendance-system/tests/demo_seed.php --dry-run | --commit\n");
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_once __DIR__ . '/../includes/absences.php';
require_once __DIR__ . '/../includes/attendance_stats.php';
require_once __DIR__ . '/../includes/attendance_override.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/import_students.php';

const SEMESTER_START = '2026-08-10';
const RANDOM_SEED = 20260810;          // same data on every run (dry run = what --commit will write)
const NO_SESSION_PERCENT = 8;          // meetings where nobody opened attendance
const OUTSIDE_AREA = 'You are outside the allowed attendance area.';   // student/ajax_scan.php

// Regular students are enrolled by the class teacher into their cohort's classes.
const COHORT_CLASSES = ['BSCS|3|3A' => [1, 2], 'BSIT|3|3A' => [4, 5, 7], 'BSN|2|2A' => [6]];

// Irregular students' classes come through requests: student, class, requested, outcome, reviewed, text
// (the student's remark for pending/approved, the teacher's reason for rejected).
const DEMO_REQUESTS = [
    ['Paolo Velasco',  5, '2026-08-05 10:12:00', 'approved', '2026-08-06 08:30:00', 'Back subject from last year.'],
    ['Paolo Velasco',  7, '2026-08-05 10:15:00', 'approved', '2026-08-06 08:32:00', null],
    ['Paolo Velasco',  4, '2026-08-05 10:20:00', 'rejected', '2026-08-06 08:35:00', 'IT201 is already credited to you from last year, so you do not need to retake it.'],
    ['Leah Fajardo',   6, '2026-08-05 14:02:00', 'approved', '2026-08-06 13:10:00', null],
    ['Rica Samonte',   1, '2026-08-06 09:41:00', 'approved', '2026-08-07 10:05:00', 'Shifted from BSCS, need this to catch up.'],
    ['Rica Samonte',   4, '2026-08-06 09:44:00', 'approved', '2026-08-07 10:07:00', null],
    ['Trisha Galang',  1, '2026-08-06 15:20:00', 'approved', '2026-08-07 10:12:00', 'Cross-enrolling from MCNP (elective).'],
    ['Trisha Galang',  7, '2026-08-06 15:23:00', 'rejected', '2026-08-07 10:15:00', 'Please complete IT201 first; IT301 builds on it.'],
    ['Bryan Cabrera',  4, '2026-08-07 08:50:00', 'approved', '2026-08-08 07:45:00', null],
    ['Bryan Cabrera',  2, '2026-08-07 08:53:00', 'rejected', '2026-08-08 07:48:00', 'IT302 is a 3rd-year subject; take it after IT201 and IT301.'],
    ['Dennis Abad',    2, '2026-08-18 19:05:00', 'approved', '2026-08-19 08:20:00', 'Late enrollment, my documents were only cleared today.'],
    ['Trisha Galang',  6, '2026-10-05 20:14:00', 'pending',  null, 'Needed for my second-semester prerequisites.'],
    ['Rica Samonte',   2, '2026-10-06 19:40:00', 'pending',  null, 'I need IT302 to graduate on time.'],
    ['Leah Fajardo',   4, '2026-10-07 21:02:00', 'pending',  null, null],
    ['Dennis Abad',    1, '2026-10-08 18:30:00', 'pending',  null, 'Can I join from next week? Thank you po.'],
];

// Past exceptions: [class, original date, type, new date, reason]
const DEMO_EXCEPTIONS = [
    [5, '2026-08-21', 'CANCELLED', null, 'Ninoy Aquino Day (holiday)'],
    [6, '2026-08-21', 'CANCELLED', null, 'Ninoy Aquino Day (holiday)'],
    [1, '2026-08-31', 'CANCELLED', null, 'National Heroes Day (holiday)'],
    [2, '2026-09-16', 'RESCHEDULED', '2026-09-17', 'Lab used for the department practical exam'],
];

const OVERRIDE_REASONS = [
    'Was in the lab; the phone GPS placed them outside the radius (failed scan on record). Confirmed on the class sheet.',
    'Present in class; the scan failed because of weak GPS indoors. Verified with the lab attendance logbook.',
];

/* ---------------- run ---------------- */

$password = (string) getenv('DEMO_PASSWORD');
if (strlen($password) < 8) {
    fwrite(STDERR, "Set DEMO_PASSWORD (at least 8 characters) in the environment or attendance-system/.env.\n");
    exit(2);
}

$started = microtime(true);
$pdo = demo_connect();
if (!demo_tracking_ready($pdo)) {
    fwrite(STDERR, "Table demo_data_rows is missing: run database/supabase_demo_data.sql in Supabase first.\n");
    exit(2);
}
mt_srand(RANDOM_SEED);
$pdo->beginTransaction();

try {
    if ((int) $pdo->query("SELECT COUNT(*) FROM students WHERE student_number LIKE 'DEMO-%'")->fetchColumn() > 0) {
        throw new RuntimeException('Demo students already exist. Run tests/demo_cleanup.php --commit first.');
    }
    $taken = $pdo->prepare('SELECT full_name FROM students WHERE full_name = ANY(CAST(? AS text[]))');
    $taken->execute(['{' . implode(',', array_map(fn($s) => '"' . $s[0] . '"', DEMO_STUDENTS)) . '}']);
    if ($dup = $taken->fetchAll(PDO::FETCH_COLUMN)) {
        throw new RuntimeException('A real student already has a demo name (cleanup matches names): ' . implode(', ', $dup));
    }

    $now = schedule_now();
    // Meetings after the absence watermark would be marked absent again by the live app, for every enrolled
    // student (real ones too). Stop at the watermark so the app never touches a demo session.
    $watermark = get_setting($pdo, ABSENCE_WATERMARK_KEY);
    $cutoff = $watermark ? min($now, at($watermark)) : $now;

    // ---- reference data ----
    $adminUserId = (int) $pdo->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY user_id LIMIT 1")->fetchColumn();
    $st = $pdo->prepare('
        SELECT ts.*, sub.subject_code, sub.subject_name, t.user_id AS teacher_user_id
        FROM teacher_subjects ts JOIN subjects sub ON sub.subject_id = ts.subject_id JOIN teachers t ON t.teacher_id = ts.teacher_id
        WHERE ts.teacher_subject_id = ANY(CAST(? AS int[]))');
    $st->execute(['{' . implode(',', DEMO_CLASS_IDS) . '}']);
    $classes = [];
    foreach ($st->fetchAll() as $c) $classes[(int) $c['teacher_subject_id']] = $c;
    if (count($classes) !== count(DEMO_CLASS_IDS)) throw new RuntimeException('Expected classes ' . implode(',', DEMO_CLASS_IDS));
    $programs = [];
    foreach ($pdo->query("SELECT program_id, program_code, institution_id, department_id FROM programs WHERE status = 'active'")->fetchAll() as $p) {
        $programs[$p['program_code']] = $p;
    }
    $labs = [];
    foreach ($pdo->query('SELECT lab_id, latitude, longitude FROM laboratories')->fetchAll() as $l) $labs[(int) $l['lab_id']] = $l;

    // ---- 1. accounts (created by the admin, Aug 3) ----
    say('creating ' . count(DEMO_STUDENTS) . ' accounts');
    $students = [];   // name => row incl. student_id, user_id
    foreach (DEMO_STUDENTS as $i => [$name, $prog, $year, $section, $type, $profile]) {
        if (!isset($programs[$prog])) throw new RuntimeException("Unknown program $prog");
        $p = $programs[$prog];
        $number = DEMO_NUMBER_PREFIX . sprintf('%04d', $i + 1);
        $username = student_import_username(['student_number' => $number]);
        $created = $profile['transfer'][0] ?? ts(at('2026-08-03 09:00:00')->modify('+' . (2 * $i) . ' minutes'));
        $uid = $pdo->prepare("INSERT INTO users (username, password, role, email, status, created_at, updated_at)
                              VALUES (?, ?, 'student', ?, 'active', ?, ?) RETURNING user_id");
        $uid->execute([$username, password_hash($password, PASSWORD_BCRYPT), strtolower($number) . DEMO_EMAIL_DOMAIN, $created, $created]);
        $userId = (int) $uid->fetchColumn();
        $sid = $pdo->prepare('INSERT INTO students (user_id, student_number, full_name, program_id, institution_id, department_id,
                              student_type, year_level, section, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING student_id');
        $sid->execute([$userId, $number, $name, $p['program_id'], $p['institution_id'], $p['department_id'], $type, $year, $section, $created]);
        $students[$name] = [
            'student_id' => (int) $sid->fetchColumn(), 'user_id' => $userId, 'full_name' => $name, 'program_code' => $prog,
            'program_id' => (int) $p['program_id'], 'institution_id' => $p['institution_id'], 'year_level' => $year,
            'section' => $section, 'student_type' => $type, 'profile' => $profile, 'enrolled' => [],
        ];
        track($pdo, 'users', [$userId]);
        log_activity($pdo, $adminUserId, "Added student: $name");
        demo_stamp($pdo, $created);
    }
    $byStudentId = [];
    foreach ($students as $s) $byStudentId[$s['student_id']] = $s['full_name'];
    $demoStudentIds = array_keys($byStudentId);

    // ---- 2. enrollment: direct (regular students) and requests (irregular) ----
    say('enrollments and requests');
    $enroll = function (array &$s, int $classId, string $when) use ($pdo) {
        $pdo->prepare("INSERT INTO enrollments (student_id, teacher_subject_id, enrolled_at, status) VALUES (?, ?, ?, 'enrolled')")
            ->execute([$s['student_id'], $classId, $when]);
        $s['enrolled'][$classId] = $when;
    };
    // Same checks as the request endpoint: eligibility, capacity, no clash with a class already taken.
    $checkJoin = function (array $s, int $classId) use ($pdo, $classes) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE teacher_subject_id = ? AND status = 'enrolled'");
        $count->execute([$classId]);
        if ((int) $count->fetchColumn() >= (int) $classes[$classId]['max_students']) throw new RuntimeException("Class #$classId is full");
        $rules = get_schedule_rules($pdo, array_merge([$classId], array_keys($s['enrolled'])));
        foreach (array_keys($s['enrolled']) as $other) {
            foreach ($rules[$other] as $theirs) foreach ($rules[$classId] as $mine) {
                if (schedule_rules_conflict($mine, $theirs)) throw new RuntimeException("{$s['full_name']}: class #$classId clashes with #$other");
            }
        }
    };

    // One timeline, so each step is checked against what the student had at that moment.
    $timeline = [];   // [when, kind, name, classId, request index]
    foreach ($students as $name => $s) {
        if ($s['student_type'] !== 'regular') continue;
        foreach (COHORT_CLASSES["{$s['program_code']}|{$s['year_level']}|{$s['section']}"] ?? [] as $classId) {
            $when = $s['profile']['transfer'][1] ?? ts(at('2026-08-07 14:00:00')->modify('+' . mt_rand(0, 150) . ' minutes'));
            $timeline[] = [$when, 'enroll', $name, $classId, null];
        }
    }
    foreach (DEMO_REQUESTS as $i => [$name, $classId, $requested, $outcome, $reviewed]) {
        $timeline[] = [$requested, 'request', $name, $classId, $i];
        if ($outcome !== 'pending') $timeline[] = [$reviewed, 'review', $name, $classId, $i];
    }
    usort($timeline, fn($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

    $requestIds = [];
    foreach ($timeline as [$when, $kind, $name, $classId, $i]) {
        $c = $classes[$classId];
        if ($kind === 'enroll') {   // teacher/ajax_enrollment.php
            $reason = enrollment_block_reason($students[$name], $c);
            if ($reason !== '') throw new RuntimeException("$name -> #$classId: $reason");
            $checkJoin($students[$name], $classId);
            $enroll($students[$name], $classId, $when);
            create_notification($pdo, $students[$name]['user_id'], 'Enrolled in a class', 'You have been enrolled in a new class. Check your schedule for details.');
            demo_stamp($pdo, $when);
            continue;
        }
        [, , , $outcome, , $text] = DEMO_REQUESTS[$i];
        if ($kind === 'request') {   // student/ajax_request_enrollment.php
            [$ok, $why] = student_eligible_for_class($students[$name], $c);
            if (!$ok) throw new RuntimeException("$name -> #$classId: $why");
            $checkJoin($students[$name], $classId);
            $ins = $pdo->prepare('INSERT INTO enrollment_requests (student_id, teacher_subject_id, remarks, requested_at) VALUES (?, ?, ?, ?) RETURNING request_id');
            $ins->execute([$students[$name]['student_id'], $classId, $outcome === 'rejected' ? null : $text, $when]);
            $requestIds[$i] = (int) $ins->fetchColumn();
            create_notification($pdo, (int) $c['teacher_user_id'], 'New Enrollment Request',
                "$name requested to enroll in {$c['subject_code']} - {$c['subject_name']} ({$c['section']}).");
            log_activity($pdo, $students[$name]['user_id'], "Requested enrollment in class #$classId");
            demo_stamp($pdo, $when);
            continue;
        }
        $requestId = $requestIds[$i];   // review: teacher/ajax_requests.php
        if ($outcome === 'approved') {
            $enroll($students[$name], $classId, $when);
            $pdo->prepare("UPDATE enrollment_requests SET status = 'approved', reviewed_by = ?, reviewed_by_role = 'teacher', reviewed_by_user_id = ?, reviewed_at = ? WHERE request_id = ?")
                ->execute([$c['teacher_id'], $c['teacher_user_id'], $when, $requestId]);
            create_notification($pdo, $students[$name]['user_id'], 'Enrollment Approved', "Your request to enroll in {$c['subject_name']} was approved. You're now officially enrolled.");
            log_activity($pdo, (int) $c['teacher_user_id'], "Approved enrollment request #$requestId");
        } else {
            $pdo->prepare("UPDATE enrollment_requests SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_by_role = 'teacher', reviewed_by_user_id = ?, reviewed_at = ? WHERE request_id = ?")
                ->execute([$text, $c['teacher_id'], $c['teacher_user_id'], $when, $requestId]);
            create_notification($pdo, $students[$name]['user_id'], 'Enrollment Rejected', "Your request to enroll in {$c['subject_name']} was rejected. Reason: $text");
            log_activity($pdo, (int) $c['teacher_user_id'], "Rejected enrollment request #$requestId");
        }
        demo_stamp($pdo, $when);
    }

    // ---- 3. past schedule exceptions (announced a week ahead) ----
    foreach (DEMO_EXCEPTIONS as [$classId, $date, $type, $newDate, $reason]) {
        $c = $classes[$classId];
        if ($type === 'RESCHEDULED') {
            $rule = get_schedule_rules($pdo, [$classId])[$classId][0];
            if ($clash = find_schedule_conflict($pdo, $newDate, $rule['start_time'], $rule['end_time'], (int) $c['teacher_id'], (int) $c['lab_id'], $classId, $date)) {
                throw new RuntimeException("Rescheduling #$classId to $newDate clashes with {$clash['subject_code']} ({$clash['conflict']})");
            }
        }
        $x = $pdo->prepare('INSERT INTO schedule_exceptions (teacher_subject_id, original_date, exception_type, new_date, reason, created_at)
                            VALUES (?, ?, ?, ?, ?, ?) RETURNING exception_id');
        $x->execute([$classId, $date, $type, $newDate, $reason, ts(at("$date 16:00:00")->modify('-7 days'))]);
        track($pdo, 'schedule_exceptions', [(int) $x->fetchColumn()]);
    }

    // ---- 4. which meetings get a session ----
    $realDays = [];
    foreach ($pdo->query('SELECT teacher_subject_id, session_date FROM attendance_sessions')->fetchAll() as $r) {
        $realDays[$r['teacher_subject_id'] . '|' . $r['session_date']] = true;
    }
    $count = array_fill_keys(DEMO_CLASS_IDS, ['meetings' => 0, 'cancelled' => 0, 'rescheduled' => 0, 'real' => 0, 'no_session' => 0, 'sessions' => 0, 'attempts' => 0]);
    $meetings = [];
    foreach (get_occurrences($pdo, SEMESTER_START, $now->format('Y-m-d'), ['teacher_subject_id' => DEMO_CLASS_IDS]) as $occ) {
        if (at($occ['ends_at']) > $cutoff) continue;   // not over yet (or after the watermark)
        $k = &$count[$occ['teacher_subject_id']];
        $k['meetings']++;
        if ($occ['is_cancelled']) { $k['cancelled']++; unset($k); continue; }
        if ($occ['is_rescheduled']) $k['rescheduled']++;
        if (isset($realDays[$occ['teacher_subject_id'] . '|' . $occ['date']])) { $k['real']++; unset($k); continue; }
        if (mt_rand(1, 100) <= NO_SESSION_PERCENT) { $k['no_session']++; unset($k); continue; }
        $k['sessions']++;
        unset($k);
        $meetings[] = $occ;
    }
    usort($meetings, fn($a, $b) => [$a['ends_at'], $a['teacher_subject_id']] <=> [$b['ends_at'], $b['teacher_subject_id']]);

    // ---- 5. plan who misses which meeting ----
    $eligible = [];   // "student|class" => [occurrence_key, ...] meetings held after they enrolled
    foreach ($meetings as $occ) {
        foreach ($students as $s) {
            $since = $s['enrolled'][$occ['teacher_subject_id']] ?? null;
            if ($since !== null && $since <= $occ['starts_at']) $eligible[$s['student_id'] . '|' . $occ['teacher_subject_id']][] = $occ['occurrence_key'];
        }
    }
    $absent = [];     // occurrence_key => [student_id => true]
    $override = [];   // occurrence_key => [student_id => reason]
    $retry = [];      // occurrence_key => [student_id => true]  failed scan from outside, then a good one
    $zeroPairs = [];
    foreach ($eligible as $pair => $keys) {
        [$studentId, $classId] = array_map('intval', explode('|', $pair));
        $profile = $students[$byStudentId[$studentId]]['profile'];
        $k = $profile['absent'][$classId] ?? (rnd() < 0.45 ? 1 : 0);
        if ($k === 'rate') {   // the fewest absences (under the limit) that put the rate below the minimum
            $m = count($keys);
            for ($k = 1; $k < 3 && floor(($m - $k) * 100 / $m) >= 80; $k++);
        }
        shuffle($keys);
        foreach (array_slice($keys, 0, (int) $k) as $key) $absent[$key][$studentId] = true;
        if ((int) $k === 0 && !isset($profile['absent'])) $zeroPairs[] = [$studentId, $keys];
    }
    // Two "my GPS failed" stories: scan rejected from outside the area, marked absent, the teacher overrides it.
    shuffle($zeroPairs);
    foreach (array_slice($zeroPairs, 0, 2) as $i => [$studentId, $keys]) {
        $early = array_values(array_filter($keys, fn($key) => explode(':', $key)[1] < '2026-10-01'));
        $key = pick($early ?: $keys);
        $absent[$key][$studentId] = true;
        $override[$key][$studentId] = OVERRIDE_REASONS[$i];
    }
    // Four more failed scans that were retried from inside the lab a few minutes later.
    $attendedPairs = [];
    foreach ($eligible as $pair => $keys) foreach ($keys as $key) {
        $studentId = (int) explode('|', $pair)[0];
        if (empty($absent[$key][$studentId])) $attendedPairs[] = [$key, $studentId];
    }
    shuffle($attendedPairs);
    foreach (array_slice($attendedPairs, 0, 4) as [$key, $studentId]) $retry[$key][$studentId] = true;

    // ---- 6. replay every meeting in time order ----
    say('replaying ' . count($meetings) . ' meetings');
    $userOf = [];
    foreach ($students as $s) $userOf[$s['student_id']] = $s['user_id'];
    $done = 0;
    foreach ($meetings as $occ) {
        $classId = $occ['teacher_subject_id'];
        $start = at($occ['starts_at']);
        $end = at($occ['ends_at']);
        $session = ensure_session_for_occurrence($pdo, $occ, $occ['starts_at']);
        if (!$session) throw new RuntimeException("No session for {$occ['occurrence_key']} (lab without GPS?)");
        $sid = (int) $session['session_id'];

        // The app tells every enrolled student attendance is open; keep that only for demo students
        // who were already in the class then.
        $roster = [];   // demo student_id => user_id, enrolled by the start of this meeting
        foreach ($students as $s) {
            if (($s['enrolled'][$classId] ?? '9999') <= $occ['starts_at']) $roster[$s['student_id']] = $s['user_id'];
        }
        $pdo->prepare("DELETE FROM notifications WHERE created_at = LOCALTIMESTAMP AND title = 'Attendance Open' AND user_id <> ALL(CAST(? AS int[]))")
            ->execute(['{' . implode(',', $roster ?: [0]) . '}']);
        demo_stamp($pdo, ts($start));

        $grace = get_late_grace_minutes($pdo, $occ['late_grace_minutes'] ?? null);
        $lateAfter = $start->modify("+$grace minutes");
        $lab = $labs[$occ['lab_id']];
        $radius = (int) $session['allowed_radius_meters'];
        $length = $end->getTimestamp() - $start->getTimestamp();
        $records = $notes = $logs = $attempts = [];
        foreach ($roster as $studentId => $userId) {
            $key = $occ['occurrence_key'];
            $failed = isset($override[$key][$studentId]) || isset($retry[$key][$studentId]);
            $tried = $start;
            if ($failed) {
                [$lat, $lon] = point_near((float) $lab['latitude'], (float) $lab['longitude'], $radius + 40, $radius + 400);
                [$inside, $distance] = is_within_geofence($lat, $lon, (float) $lab['latitude'], (float) $lab['longitude'], $radius);
                if ($inside) throw new RuntimeException('Bad outside point');
                $tried = $start->modify('+' . mt_rand(60, 12 * 60) . ' seconds');
                $attempts[] = [$sid, $studentId, ts($tried), OUTSIDE_AREA, $lat, $lon, mt_rand(8, 45), $distance];
                $count[$classId]['attempts']++;
            }
            if (!empty($absent[$key][$studentId])) continue;

            $late = rnd() < ($students[$byStudentId[$studentId]]['profile']['late'] ?? 0.10);
            if (isset($retry[$key][$studentId])) {
                $time = $tried->modify('+' . mt_rand(3 * 60, 9 * 60) . ' seconds');
            } elseif ($late) {
                $time = $lateAfter->modify('+' . mt_rand(60, max(120, min(40 * 60, $length - $grace * 60 - 300))) . ' seconds');
            } else {
                $time = $start->modify('+' . (int) (rnd() ** 2 * $grace * 60) . ' seconds');
            }
            $status = $time > $lateAfter ? 'Late' : 'Present';   // student/ajax_scan.php
            [$lat, $lon] = point_near((float) $lab['latitude'], (float) $lab['longitude'], 2, min(35, $radius * 0.6));
            [$inside, $distance] = is_within_geofence($lat, $lon, (float) $lab['latitude'], (float) $lab['longitude'], $radius);
            if (!$inside) throw new RuntimeException('Bad inside point');
            $records[] = [$sid, $studentId, ts($time), $status, $lat, $lon, mt_rand(4, 20), $distance, ts($time)];
            $notes[] = [$userId, 'Attendance Recorded', "You were marked $status for {$occ['subject_name']} in {$occ['lab_name']}.", ts($time)];
            $logs[] = [$userId, 'Logged in', ts($time->modify('-' . mt_rand(40, 300) . ' seconds'))];
            $logs[] = [$userId, "Scanned attendance for session #$sid - $status ({$distance}m)", ts($time)];
        }
        if ($attempts) insert_rows($pdo, 'scan_attempts', ['session_id', 'student_id', 'attempted_at', 'reason', 'latitude', 'longitude', 'location_accuracy', 'distance_from_location'], $attempts);
        if ($records) {
            insert_rows($pdo, 'attendance_records', ['session_id', 'student_id', 'time_in', 'status', 'latitude', 'longitude', 'location_accuracy', 'distance_from_location', 'created_at'], $records);
            insert_rows($pdo, 'notifications', ['user_id', 'title', 'message', 'created_at'], $notes);
            track($pdo, 'activity_logs', insert_rows($pdo, 'activity_logs', ['user_id', 'action', 'created_at'], $logs, 'log_id'));
        }

        // The meeting ends: the session closes and the absence marker runs (a few minutes later, on the next page view).
        $pdo->prepare('UPDATE attendance_sessions SET is_active = 0, deactivated_at = session_end WHERE session_id = ?')->execute([$sid]);
        $added = insert_absent_records($pdo, ts($end->modify('-1 second')), ts($end), [$sid], $demoStudentIds);
        $expected = count(array_intersect_key($absent[$occ['occurrence_key']] ?? [], $roster));
        if (count($added) !== $expected) throw new RuntimeException("{$occ['occurrence_key']}: expected $expected absences, the app marked " . count($added));
        notify_new_absences($pdo, $added);
        demo_stamp($pdo, ts($end->modify('+' . mt_rand(1, 25) . ' minutes')));

        // Contested absences: the teacher corrects them the next morning.
        foreach ($override[$occ['occurrence_key']] ?? [] as $studentId => $reason) {
            $rid = $pdo->prepare('SELECT record_id FROM attendance_records WHERE session_id = ? AND student_id = ?');
            $rid->execute([$sid, $studentId]);
            $recordId = (int) $rid->fetchColumn();
            override_absent_to_present($pdo, $recordId, (int) $classes[$classId]['teacher_id'], (int) $classes[$classId]['teacher_user_id'], $reason);
            $when = ts(at($occ['date'] . ' 07:30:00')->modify('+1 day')->modify('+' . mt_rand(0, 90) . ' minutes'));
            $pdo->prepare('UPDATE attendance_records SET marked_at = ? WHERE record_id = ?')->execute([$when, $recordId]);
            demo_stamp($pdo, $when);
        }
        if (++$done % 10 === 0) say("$done/" . count($meetings) . ' meetings');
    }

    // Notifications older than three days have been seen by now (demo students, and teachers' demo notices).
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE created_at < ? AND (user_id = ANY(CAST(? AS int[]))
                   OR notification_id IN (SELECT row_id FROM " . DEMO_TRACKING_TABLE . " WHERE table_name = 'notifications'))")
        ->execute([ts($now->modify('-3 days')), '{' . implode(',', $userOf) . '}']);

    // ---------------- report ----------------
    echo "\nDemo semester " . SEMESTER_START . ' to ' . ts($cutoff)
        . ($cutoff < $now ? ' (stops at the absence watermark ' . ts($cutoff) . ')' : '') . "\n";
    echo count($students) . " demo students, password from DEMO_PASSWORD (bcrypt)\n\n";

    $statusRows = $pdo->prepare("
        SELECT s.teacher_subject_id,
               COUNT(*) FILTER (WHERE ar.status = 'Present') AS present,
               COUNT(*) FILTER (WHERE ar.status = 'Late') AS late,
               COUNT(*) FILTER (WHERE ar.status = 'Absent') AS absent,
               COUNT(*) FILTER (WHERE ar.marked_by_user_id IS NOT NULL) AS overrides
        FROM attendance_records ar JOIN attendance_sessions s ON s.session_id = ar.session_id
        WHERE ar.student_id = ANY(CAST(? AS int[])) GROUP BY s.teacher_subject_id");
    $statusRows->execute(['{' . implode(',', $demoStudentIds) . '}']);
    $byClass = [];
    foreach ($statusRows->fetchAll() as $r) $byClass[(int) $r['teacher_subject_id']] = $r;
    $rows = [];
    $total = ['present' => 0, 'late' => 0, 'absent' => 0];
    foreach (DEMO_CLASS_IDS as $classId) {
        $c = $classes[$classId];
        $k = $count[$classId];
        $r = $byClass[$classId] ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'overrides' => 0];
        foreach ($total as $col => $_) $total[$col] += (int) $r[$col];
        $enrolledDemo = count(array_filter($students, fn($s) => isset($s['enrolled'][$classId])));
        $rows[] = ["#$classId {$c['subject_code']} {$c['section']}", $enrolledDemo, $k['meetings'], $k['cancelled'], $k['rescheduled'],
                   $k['real'], $k['no_session'], $k['sessions'], $r['present'], $r['late'], $r['absent'], $r['overrides'], $k['attempts']];
    }
    echo "Per class (meetings up to the cutoff; 'real day' = skipped because the class already has a real session that day):\n";
    demo_table($rows, ['class', 'demo students', 'meetings', 'cancelled', 'moved', 'real day', 'no session', 'sessions', 'Present', 'Late', 'Absent', 'overrides', 'failed scans']);
    $all = array_sum($total);
    echo "\nBy status: " . implode(', ', array_map(fn($k, $v) => ucfirst($k) . " $v (" . ($all ? round($v * 100 / $all) : 0) . '%)', array_keys($total), $total)) . "\n";

    echo "\nAttendance warnings (demo students; absence limit / minimum rate from Admin > Settings):\n";
    $warn = [];
    foreach (DEMO_CLASS_IDS as $classId) {
        foreach (class_student_standings($pdo, $classId) as $studentId => $standing) {
            if (!isset($byStudentId[$studentId]) || $standing['level'] === STANDING_OK) continue;
            $warn[] = [$byStudentId[$studentId], "#$classId {$classes[$classId]['subject_code']} {$classes[$classId]['section']}",
                       "{$standing['attended']}/{$standing['meetings']}", $standing['absent'], $standing['rate'] . '%', $standing['label']];
        }
    }
    demo_table($warn, ['student', 'class', 'attended', 'absent', 'rate', 'warning']);

    echo "\nEnrollment requests:\n";
    $req = $pdo->prepare('SELECT status, COUNT(*) FROM enrollment_requests WHERE student_id = ANY(CAST(? AS int[])) GROUP BY status ORDER BY status');
    $req->execute(['{' . implode(',', $demoStudentIds) . '}']);
    demo_table(array_map(fn($r) => array_values($r), $req->fetchAll()), ['status', 'count']);

    echo "\nNotifications created:\n";
    $n = $pdo->prepare("SELECT title, COUNT(*) FROM notifications
        WHERE user_id = ANY(CAST(? AS int[])) OR notification_id IN (SELECT row_id FROM " . DEMO_TRACKING_TABLE . " WHERE table_name = 'notifications')
        GROUP BY title ORDER BY 2 DESC");
    $n->execute(['{' . implode(',', $userOf) . '}']);
    demo_table(array_map(fn($r) => array_values($r), $n->fetchAll()), ['title', 'count']);
    $logCount = $pdo->query("SELECT COUNT(*) FROM " . DEMO_TRACKING_TABLE . " WHERE table_name = 'activity_logs'")->fetchColumn();
    echo "\nActivity log lines: $logCount\n";

    if ($mode === 'commit') {
        $pdo->commit();
        echo "\nCOMMITTED in " . round(microtime(true) - $started) . "s. Undo with: php attendance-system/tests/demo_cleanup.php --commit\n";
    } else {
        $pdo->rollBackAll();
        echo "\nDRY RUN: rolled back, nothing saved (" . round(microtime(true) - $started) . "s). Ids skipped by the rollback are not reused.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBackAll();
    fwrite(STDERR, "\nFAILED, nothing was saved: " . $e->getMessage() . "\n  at " . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
