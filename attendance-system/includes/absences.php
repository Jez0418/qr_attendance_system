<?php
/**
 * ------------------------------------------------------------
 * includes/absences.php
 * Marks students ABSENT when a class meeting ends without them scanning.
 *
 * Who: when a session's session_end has passed, every student who was enrolled (status 'enrolled',
 * enrolled before the meeting ended, so one added a few minutes after the start still counts because
 * they could still scan) and has no attendance record for that session gets an
 * 'Absent' record. Only meetings that had a session are touched: if nobody ever opened attendance
 * there was no QR to scan, so the class is not marked absent (meeting didn't run, lab had no GPS...).
 *
 * When: there is no cron job on Vercel, so it runs lazily from require_login() (includes/auth.php),
 * at most once a minute. The settings row `absence_processed_until` remembers how far it got. The
 * first run only sets it to "now": meetings that ended before the feature existed are NOT back-filled.
 *
 * The record's time_in is the meeting's end time (the column can't be empty and the history pages
 * filter and sort by it); pages show "no check-in" for Absent rows via format_record_time().
 * The INSERT is idempotent (UNIQUE (session_id, student_id) + ON CONFLICT DO NOTHING), and an
 * advisory lock keeps two requests from doing the same work at once.
 *
 * After the commit, notify_new_absences() tells each student (with their absence count for that
 * class) so they can contest it the same day, and tells the teacher when a student reaches the
 * absence limit (includes/attendance_stats.php).
 * ------------------------------------------------------------
 */
const ABSENCE_WATERMARK_KEY = 'absence_processed_until';
const ABSENCE_CHECK_SECONDS = 60;

/**
 * Insert the Absent records for sessions that ended since the last run. Returns how many rows were
 * added. Never throws: this runs on page loads and must not take a page down.
 */
function mark_absent_for_ended_sessions(PDO $pdo): int {
    try {
        $st = $pdo->prepare('SELECT setting_value, (updated_at > NOW() - CAST(? AS interval)) AS fresh FROM settings WHERE setting_key = ?');
        $st->execute([ABSENCE_CHECK_SECONDS . ' seconds', ABSENCE_WATERMARK_KEY]);
        $row = $st->fetch();
        if (!$row) {
            // First run ever: start counting from now (no back-fill of old sessions).
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, CAST(NOW()::timestamp(0) AS varchar)) ON CONFLICT (setting_key) DO NOTHING')
                ->execute([ABSENCE_WATERMARK_KEY]);
            return 0;
        }
        if (in_array($row['fresh'], [true, 't', 'true', '1', 1], true)) return 0;   // checked within the last minute

        $pdo->beginTransaction();
        try {
            $lock = $pdo->query("SELECT pg_try_advisory_xact_lock(hashtext('absence-marker'))")->fetchColumn();
            if (!$lock) { $pdo->rollBack(); return 0; }   // another request is already doing it

            $from = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $from->execute([ABSENCE_WATERMARK_KEY]);
            $until = (string) $pdo->query('SELECT NOW()::timestamp(0)')->fetchColumn();
            $added = insert_absent_records($pdo, (string) $from->fetchColumn(), $until);

            set_setting($pdo, ABSENCE_WATERMARK_KEY, $until);   // also refreshes updated_at (the once-a-minute throttle)
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // After the commit: a notification problem must never undo (or block) the absences themselves.
        notify_new_absences($pdo, $added);
        return count($added);
    } catch (Throwable $e) {
        error_log('mark_absent_for_ended_sessions: ' . $e->getMessage());
        return 0;
    }
}

/**
 * The rule itself: an Absent record for every student enrolled (by the meeting's end) with no record, in each
 * session whose session_end is in ($after, $until]. Returns the inserted rows (session_id, student_id).
 * $sessionIds / $studentIds narrow it further (tests/demo_seed.php replays past meetings one at a time).
 */
function insert_absent_records(PDO $pdo, string $after, string $until, array $sessionIds = [], array $studentIds = []): array {
    $only = '';
    $params = [$after, $until];
    foreach (['s.session_id' => $sessionIds, 'e.student_id' => $studentIds] as $col => $ids) {
        if (!$ids) continue;
        $only .= " AND $col = ANY(CAST(? AS int[]))";
        $params[] = '{' . implode(',', array_map('intval', $ids)) . '}';
    }
    $ins = $pdo->prepare("
        INSERT INTO attendance_records (session_id, student_id, time_in, status)
        SELECT s.session_id, e.student_id, s.session_end, 'Absent'
        FROM attendance_sessions s
        JOIN enrollments e ON e.teacher_subject_id = s.teacher_subject_id
        WHERE e.status = 'enrolled'
          AND (e.enrolled_at IS NULL OR e.enrolled_at <= s.session_end)
          AND s.session_end IS NOT NULL
          AND s.session_end > CAST(? AS timestamp)
          AND s.session_end <= CAST(? AS timestamp)
          $only
        ON CONFLICT (session_id, student_id) DO NOTHING
        RETURNING session_id, student_id
    ");
    $ins->execute($params);
    return $ins->fetchAll();
}

/**
 * Tell each student they were marked absent, the same day, so they can contest it while the teacher
 * still remembers (the teacher can change Absent to Present on teacher/history.php). The message
 * carries their absence count for that class; when a student reaches the absence limit the teacher
 * gets one notification too. $pairs = rows of session_id + student_id that were just inserted.
 */
function notify_new_absences(PDO $pdo, array $pairs): void {
    if (!$pairs) return;
    require_once __DIR__ . '/attendance_stats.php';
    try {
        $limits = attendance_limits($pdo);
        $sessionIds = array_map(fn($p) => (int) $p['session_id'], $pairs);
        $studentIds = array_map(fn($p) => (int) $p['student_id'], $pairs);
        // One query for every new absence: who, which class, and their absence count in that class.
        $st = $pdo->prepare("
            SELECT st.user_id AS student_user_id, st.full_name, s.scheduled_start,
                   sub.subject_code, sub.subject_name, ts.section, tch.user_id AS teacher_user_id,
                   (SELECT COUNT(*) FROM attendance_records ar2
                      JOIN attendance_sessions s2 ON s2.session_id = ar2.session_id
                     WHERE s2.teacher_subject_id = s.teacher_subject_id
                       AND ar2.student_id = n.student_id AND ar2.status = 'Absent') AS absences
            FROM unnest(CAST(? AS int[]), CAST(? AS int[])) AS n(session_id, student_id)
            JOIN attendance_sessions s ON s.session_id = n.session_id
            JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
            JOIN subjects sub ON sub.subject_id = ts.subject_id
            JOIN teachers tch ON tch.teacher_id = ts.teacher_id
            JOIN students st ON st.student_id = n.student_id
        ");
        $st->execute(['{' . implode(',', $sessionIds) . '}', '{' . implode(',', $studentIds) . '}']);

        $notes = [];
        foreach ($st->fetchAll() as $r) {
            $absences = (int) $r['absences'];
            $when = date('D, M j, g:i A', strtotime($r['scheduled_start']));
            $message = "You were marked absent from {$r['subject_code']} - {$r['subject_name']} ({$when}). "
                . 'You now have ' . absence_count_label($absences, $limits) . ' in this class. '
                . 'If you were there, tell your teacher today: they can correct it.';
            $notes[] = [(int) $r['student_user_id'], 'Marked Absent', $message];
            // Keyed so two meetings ending in the same run don't tell the teacher twice.
            if ($limits['absence_limit'] > 0 && $absences === $limits['absence_limit']) {
                $notes["limit:{$r['teacher_user_id']}:{$r['student_user_id']}:{$r['subject_code']}:{$r['section']}"] = [(int) $r['teacher_user_id'], 'Absence Limit Reached',
                    "{$r['full_name']} has reached {$absences} absences in {$r['subject_code']} ({$r['section']})."];
            }
        }
        // Multi-row insert, in chunks: a big class must not cost one round trip per student.
        foreach (array_chunk($notes, 100) as $chunk) {
            $sql = 'INSERT INTO notifications (user_id, title, message) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $params = [];
            foreach ($chunk as [$uid, $title, $msg]) array_push($params, $uid, fit_text($title, 150), fit_text($msg, 500));
            $pdo->prepare($sql)->execute($params);
        }
    } catch (Throwable $e) {
        error_log('notify_new_absences: ' . $e->getMessage());
    }
}
