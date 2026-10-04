<?php
/**
 * ------------------------------------------------------------
 * qr/session_manager.php
 * Single source of truth for creating/closing an attendance
 * session, since BOTH a teacher (their own class) and an admin
 * (any class) can activate/deactivate attendance for a class.
 * Keeping this logic in one place means the two entry points can
 * never drift out of sync or apply different validation rules.
 *
 * Note: activating a session no longer generates a QR code — the
 * QR code is now a single fixed code per LABORATORY (see
 * qr/qr_helper.php). Activating a session just opens a time window
 * during which scans of that laboratory's QR code will be recorded
 * against this specific class.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/qr_helper.php';

/**
 * Activate an attendance session for a class (teacher_subjects row).
 *
 * @param PDO    $pdo
 * @param int    $classId          teacher_subject_id
 * @param string $role             'teacher' or 'admin' — who is activating
 * @param int    $activatorUserId  users.user_id of whoever clicked Activate
 * @param string|null $sessionDate  'Y-m-d', defaults to today
 * @param string|null $startTime    'H:i' or 'H:i:s', defaults to the class's own start_time
 * @param string|null $endTime      'H:i' or 'H:i:s', defaults to the class's own end_time
 * @param int|null    $radiusMeters overrides the laboratory's configured geofence radius
 * @return array ['success' => bool, 'message' => string, 'session' => array|null]
 */
function activate_attendance_session(PDO $pdo, $classId, $role, $activatorUserId, $sessionDate = null, $startTime = null, $endTime = null, $radiusMeters = null) {
    $classStmt = $pdo->prepare('
        SELECT ts.*, lab.allowed_radius_meters AS lab_radius, lab.latitude, lab.longitude
        FROM teacher_subjects ts
        JOIN laboratories lab ON lab.lab_id = ts.lab_id
        WHERE ts.teacher_subject_id = ?
    ');
    $classStmt->execute([$classId]);
    $class = $classStmt->fetch();

    if (!$class) {
        return ['success' => false, 'message' => 'Class not found.', 'session' => null];
    }
    if ($class['latitude'] === null || $class['longitude'] === null) {
        return ['success' => false, 'message' => 'This laboratory has no GPS coordinates configured yet. An administrator must set them (Admin > Laboratories) before attendance can be geofenced.', 'session' => null];
    }

    // CRITICAL: a class assignment's meeting_date is the single source of
    // truth for "is this class today?" — never day-of-week alone, and
    // never anything the client claims. A class scheduled for yesterday
    // (or any other date) must never be activatable today, and a class
    // scheduled for a future date can't be activated early either.
    if (!empty($class['meeting_date'])) {
        $today = date('Y-m-d');
        if ($class['meeting_date'] !== $today) {
            return ['success' => false, 'message' => 'This class is not scheduled for today. Its meeting date is ' . format_date($class['meeting_date']) . '.', 'session' => null];
        }
        // Right date — but has today's own time window already closed?
        if (compute_class_status($class['meeting_date'], $class['start_time'], $class['end_time']) === 'expired') {
            return ['success' => false, 'message' => 'Today\'s scheduled time window for this class (' . format_time($class['start_time']) . '\u2013' . format_time($class['end_time']) . ') has already ended.', 'session' => null];
        }
    }

    $sessionDate = $sessionDate ?: date('Y-m-d');

    // Only one active session per class per day, regardless of who activates it
    $existing = $pdo->prepare('SELECT session_id FROM attendance_sessions WHERE teacher_subject_id = ? AND session_date = ? AND is_active = 1');
    $existing->execute([$classId, $sessionDate]);
    if ($existing->fetch()) {
        return ['success' => false, 'message' => 'A session is already active for this class today.', 'session' => null];
    }

    $startTime = $startTime ?: $class['start_time'];
    $endTime = $endTime ?: $class['end_time'];
    $scheduledStart = $sessionDate . ' ' . $startTime;
    $sessionEnd = $sessionDate . ' ' . $endTime;

    // Guard against an end time that's before/equal to the start (e.g. bad manual input)
    if (strtotime($sessionEnd) <= strtotime($scheduledStart)) {
        $sessionEnd = date('Y-m-d H:i:s', strtotime($scheduledStart) + 3 * 3600); // fall back to +3h
    }

    $radius = $radiusMeters !== null && $radiusMeters !== '' ? (int) $radiusMeters : (int) $class['lab_radius'];

    // A brand-new, cryptographically random token every activation — never
    // reused across sessions, so an old screenshot can never match a new one.
    $qrToken = bin2hex(random_bytes(24));

    $ins = $pdo->prepare('
        INSERT INTO attendance_sessions
            (teacher_subject_id, session_date, qr_token, scheduled_start, session_end,
             late_threshold_minutes, allowed_radius_meters, is_active, activated_by, created_by_role, created_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
    ');
    $ins->execute([
        $classId, $sessionDate, $qrToken, $scheduledStart, $sessionEnd,
        LATE_THRESHOLD_MINUTES, $radius, $class['teacher_id'], $role, $activatorUserId,
    ]);
    $sessionId = $pdo->lastInsertId();

    // Notify enrolled students
    $students = $pdo->prepare('
        SELECT s.user_id FROM enrollments e JOIN students s ON s.student_id = e.student_id
        WHERE e.teacher_subject_id = ? AND e.status = "enrolled"
    ');
    $students->execute([$classId]);
    foreach ($students->fetchAll() as $s) {
        create_notification($pdo, $s['user_id'], 'Attendance Session Started', 'Attendance is now open — scan the QR code posted in the laboratory to be marked present!');
    }

    return ['success' => true, 'message' => 'Attendance session activated.', 'session' => ['session_id' => $sessionId, 'qr_token' => $qrToken]];
}

/**
 * Deactivate whatever active session exists today for a class.
 * Works regardless of whether a teacher or an admin originally
 * activated it — either party may close it.
 */
function deactivate_attendance_session(PDO $pdo, $classId, $sessionDate = null) {
    $sessionDate = $sessionDate ?: date('Y-m-d');
    $upd = $pdo->prepare('UPDATE attendance_sessions SET is_active = 0, deactivated_at = NOW() WHERE teacher_subject_id = ? AND session_date = ? AND is_active = 1');
    $upd->execute([$classId, $sessionDate]);
    return $upd->rowCount() > 0;
}

/** Deactivate a specific session by its own ID (used by admin force-stop from the live list). */
function deactivate_attendance_session_by_id(PDO $pdo, $sessionId) {
    $upd = $pdo->prepare('UPDATE attendance_sessions SET is_active = 0, deactivated_at = NOW() WHERE session_id = ? AND is_active = 1');
    $upd->execute([$sessionId]);
    return $upd->rowCount() > 0;
}
