<?php
/**
 * ------------------------------------------------------------
 * includes/attendance_override.php
 * A teacher changes an ABSENT record of one of their own classes to PRESENT, with a required reason.
 * The record keeps who/when/why (marked_by_user_id, marked_at, override_reason; see
 * database/supabase_attendance_override.sql), the student is notified and the activity log gets a line.
 * Only Absent -> Present is allowed; Late/Present records cannot be edited here.
 * ------------------------------------------------------------
 */
const OVERRIDE_REASON_MIN = 3;
const OVERRIDE_REASON_MAX = 255;

/** Why this override is not allowed ('' = fine). $record = ['status' => ..., 'teacher_id' => ...] or null if not found. */
function override_problem(?array $record, int $teacherId, string $reason): string {
    // "Not found" and "someone else's class" give the same answer, so record ids can't be probed.
    if (!$record || (int) $record['teacher_id'] !== $teacherId || $teacherId <= 0) return 'Record not found.';
    if ($record['status'] !== 'Absent') return 'Only an Absent record can be changed to Present.';
    $len = mb_strlen($reason);
    if ($len < OVERRIDE_REASON_MIN) return 'Please enter a reason for the change.';
    if ($len > OVERRIDE_REASON_MAX) return 'The reason must be at most ' . OVERRIDE_REASON_MAX . ' characters.';
    return '';
}

/**
 * Do the override. Returns the student's name on success; throws Exception with a user-facing message otherwise.
 * The UPDATE only matches a row that is still Absent, so two clicks (or two teachers) can't both apply.
 */
function override_absent_to_present(PDO $pdo, int $recordId, int $teacherId, int $teacherUserId, string $reason): string {
    $st = $pdo->prepare('
        SELECT ar.status, ar.session_id, ts.teacher_id, ses.session_date, sub.subject_name, stu.full_name, stu.user_id AS student_user_id
        FROM attendance_records ar
        JOIN attendance_sessions ses ON ses.session_id = ar.session_id
        JOIN teacher_subjects ts ON ts.teacher_subject_id = ses.teacher_subject_id
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        JOIN students stu ON stu.student_id = ar.student_id
        WHERE ar.record_id = ?
    ');
    $st->execute([$recordId]);
    $record = $st->fetch() ?: null;

    $problem = override_problem($record, $teacherId, $reason);
    if ($problem !== '') throw new Exception($problem);

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("
            UPDATE attendance_records
            SET status = 'Present', marked_by_user_id = ?, marked_at = NOW(), override_reason = ?
            WHERE record_id = ? AND status = 'Absent'
        ");
        $up->execute([$teacherUserId, $reason, $recordId]);
        if ($up->rowCount() !== 1) { $pdo->rollBack(); throw new Exception('This record was already changed. Reload the page.'); }

        $date = date('M d, Y', strtotime($record['session_date']));
        create_notification($pdo, (int) $record['student_user_id'], 'Attendance Updated',
            "Your attendance for {$record['subject_name']} on $date was changed to Present by your teacher. Reason: $reason");
        log_activity($pdo, $teacherUserId, "Changed {$record['full_name']} from Absent to Present for session #{$record['session_id']} - $reason");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $record['full_name'];
}
