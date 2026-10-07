<?php
/**
 * ------------------------------------------------------------
 * includes/absences.php
 * Marks students ABSENT when a class meeting ends without them scanning.
 *
 * Who: when a session's session_end has passed, every student who was enrolled (status 'enrolled',
 * enrolled before the meeting started) and has no attendance record for that session gets an
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

            $ins = $pdo->prepare("
                INSERT INTO attendance_records (session_id, student_id, time_in, status)
                SELECT s.session_id, e.student_id, s.session_end, 'Absent'
                FROM attendance_sessions s
                JOIN enrollments e ON e.teacher_subject_id = s.teacher_subject_id
                WHERE e.status = 'enrolled'
                  AND (e.enrolled_at IS NULL OR e.enrolled_at <= COALESCE(s.scheduled_start, s.session_end))
                  AND s.session_end IS NOT NULL
                  AND s.session_end > CAST(? AS timestamp)
                  AND s.session_end <= CAST(? AS timestamp)
                ON CONFLICT (session_id, student_id) DO NOTHING
            ");
            $ins->execute([(string) $from->fetchColumn(), $until]);
            $added = $ins->rowCount();

            set_setting($pdo, ABSENCE_WATERMARK_KEY, $until);   // also refreshes updated_at (the once-a-minute throttle)
            $pdo->commit();
            return $added;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('mark_absent_for_ended_sessions: ' . $e->getMessage());
        return 0;
    }
}
