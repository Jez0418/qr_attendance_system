<?php
/**
 * ============================================================
 * student/ajax_scan.php
 * CORE ATTENDANCE VALIDATION ENGINE.
 *
 * Every decision happens server-side, in this order; the browser's
 * claims (identity, time, location) are never trusted on their own:
 *
 *   1.  Student authenticated?                     require_role
 *   2.  QR payload well-formed?                    qr_parse_session_payload()
 *   3.  Session exists and the token matches it?   hash_equals
 *   4.  Status recomputed from the class schedule (includes/schedule.php):
 *       the meeting this session belongs to must exist, not be CANCELLED,
 *       and be ACTIVE right now (start <= now < end)
 *   5.  The scanned session IS that meeting's session?
 *                                                  ensure_session_for_occurrence()
 *   6.  Session still open (not closed early, not past session_end)?
 *   7.  Student enrolled in the class?
 *   8.  Location provided, accurate enough, inside the lab's radius?
 *                                                  server-side Haversine
 *   9.  Not already recorded?
 *
 * Every rejected scan that got as far as a valid QR (step 3) is also saved in
 * `scan_attempts` with the student's distance from the laboratory, so the teacher
 * can see who tried to check in from where (database/supabase_scan_attempts.sql).
 *
 * Late: a scan more than the class's late grace (Admin > Class Assignments, else settings default 15) after
 * the meeting's start is "Late", otherwise "Present". The insert runs in a
 * transaction so a failure never leaves a partial record.
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('student');
header('Content-Type: application/json');

auto_expire_sessions($pdo);

$studentId = (int) $_SESSION['profile_id'];
const INVALID_QR = 'This QR code is invalid or has expired.';
const ALREADY_RECORDED = 'You have already recorded attendance for this class.';
const MAX_ATTEMPTS_PER_STUDENT = 30;   // per session, so a spammed scan can't fill the table

/** Save a rejected scan for the teacher to see. Never throws: logging must not break the scan response. */
function log_scan_attempt(PDO $pdo, int $sessionId, int $studentId, string $reason, $lat, $lon, $accuracy, $distance): void {
    try {
        $n = $pdo->prepare('SELECT COUNT(*) FROM scan_attempts WHERE session_id = ? AND student_id = ?');
        $n->execute([$sessionId, $studentId]);
        if ((int) $n->fetchColumn() >= MAX_ATTEMPTS_PER_STUDENT) return;
        $hasPos = is_numeric($lat) && is_numeric($lon) && abs((float) $lat) <= 90 && abs((float) $lon) <= 180;
        $pdo->prepare('
            INSERT INTO scan_attempts (session_id, student_id, reason, latitude, longitude, location_accuracy, distance_from_location)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $sessionId, $studentId, mb_substr($reason, 0, 255),
            $hasPos ? (float) $lat : null, $hasPos ? (float) $lon : null,
            (is_numeric($accuracy) && (float) $accuracy < 100000) ? (float) $accuracy : null,
            $distance,
        ]);
    } catch (Throwable $e) {
        error_log('scan_attempts: ' . $e->getMessage());
    }
}

$session = null; $occ = null; $lab = null; $distance = null; $attemptSessionId = null;
$lat = $_POST['latitude'] ?? null;
$lon = $_POST['longitude'] ?? null;
$accuracy = $_POST['accuracy'] ?? null;

try {
    // ---- 2. QR payload well-formed? ----
    $rawPayload = $_POST['qr_payload'] ?? '';
    if ($rawPayload === '') throw new Exception('No QR data received.');
    $parsed = qr_parse_session_payload($rawPayload);
    if (!$parsed) throw new Exception(INVALID_QR);

    // ---- 3. Session exists and the token matches it? ----
    $stmt = $pdo->prepare('
        SELECT s.*, sub.subject_name
        FROM attendance_sessions s
        JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        WHERE s.session_id = ?
    ');
    $stmt->execute([$parsed['session_id']]);
    $session = $stmt->fetch();
    if (!$session || empty($session['qr_token']) || !hash_equals((string) $session['qr_token'], (string) $parsed['token'])) {
        throw new Exception(INVALID_QR);
    }
    $attemptSessionId = (int) $session['session_id'];   // a genuine QR: from here on a rejection is logged

    // ---- 4. Recompute the meeting's status from the schedule, server-side ----
    $now = schedule_now();
    $occ = find_occurrence_for_session($pdo, $session, $now);   // also re-attaches a session whose class start moved
    if (!$occ) throw new Exception('This class is not scheduled at this time.');

    // Where is the student relative to the meeting's lab? Worked out now (best effort) so that even a
    // scan rejected for another reason records the distance.
    $labStmt = $pdo->prepare('SELECT latitude, longitude FROM laboratories WHERE lab_id = ?');   // the meeting's lab (may be a rescheduled room)
    $labStmt->execute([$occ['lab_id']]);
    $lab = $labStmt->fetch();
    if ($lab && $lab['latitude'] !== null && $lab['longitude'] !== null && is_numeric($lat) && is_numeric($lon)) {
        $distance = round(haversine_distance_meters((float) $lat, (float) $lon, (float) $lab['latitude'], (float) $lab['longitude']), 2);
    }
    $status = get_occurrence_status($occ, $now);
    if ($status === OCCURRENCE_CANCELLED) {
        throw new Exception('This class has been cancelled' . ($occ['exception_reason'] ? ': ' . $occ['exception_reason'] : '.'));
    }
    if ($status !== OCCURRENCE_ACTIVE) {
        throw new Exception($status === OCCURRENCE_UPCOMING ? 'This class has not started yet.' : 'This class has already ended.');
    }

    // ---- 5. Is the scanned session this meeting's session? ----
    $current = ensure_session_for_occurrence($pdo, $occ, $now);
    if (!$current || (int) $current['session_id'] !== (int) $session['session_id']) {
        throw new Exception(INVALID_QR);
    }

    // ---- 6. Session still open? ----
    if ((int) $current['is_active'] !== 1) {
        throw new Exception('Attendance for this class has been closed.');
    }
    if ($current['session_end'] && new DateTimeImmutable($current['session_end'], schedule_tz()) <= $now) {
        throw new Exception(INVALID_QR);
    }

    // ---- 7. Student enrolled in the class? ----
    $enrollCheck = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND teacher_subject_id = ? AND status = 'enrolled'");
    $enrollCheck->execute([$studentId, $occ['teacher_subject_id']]);
    if ($enrollCheck->fetchColumn() == 0) {
        throw new Exception('You are not enrolled in this subject.');
    }

    // ---- 8. GPS: location present, accurate enough, inside the meeting's lab radius ----
    if ($lat === null || $lon === null || $lat === '' || $lon === '' || !is_numeric($lat) || !is_numeric($lon)) {
        throw new Exception('Location access is required to verify attendance.');
    }
    $maxAccuracy = get_setting_int($pdo, 'max_gps_accuracy_meters', 100);
    if ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy) && (float) $accuracy > $maxAccuracy) {
        throw new Exception('Your location accuracy is too low. Please enable high-accuracy location services and try again.');
    }
    if (!$lab || $lab['latitude'] === null || $lab['longitude'] === null) {
        throw new Exception('This laboratory has no GPS coordinates configured. Please contact your administrator.');
    }
    [$withinRadius, $distance] = is_within_geofence((float) $lat, (float) $lon, (float) $lab['latitude'], (float) $lab['longitude'], (int) $current['allowed_radius_meters']);
    if (!$withinRadius) {
        throw new Exception('You are outside the allowed attendance area.');
    }

    // ---- 9. Not already recorded? ----
    $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM attendance_records WHERE session_id = ? AND student_id = ?');
    $dupCheck->execute([$current['session_id'], $studentId]);
    if ($dupCheck->fetchColumn() > 0) {
        throw new Exception(ALREADY_RECORDED);
    }

    // ---- Late logic: start + the class's late grace (or the settings default) ----
    $lateAfter = (new DateTimeImmutable($occ['starts_at'], schedule_tz()))->modify('+' . get_late_grace_minutes($pdo, $occ['late_grace_minutes'] ?? null) . ' minutes');
    $recordStatus = $now > $lateAfter ? 'Late' : 'Present';

    $pdo->beginTransaction();
    $ins = $pdo->prepare('
        INSERT INTO attendance_records (session_id, student_id, time_in, status, latitude, longitude, location_accuracy, distance_from_location)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $current['session_id'], $studentId, $now->format('Y-m-d H:i:s'), $recordStatus,
        (float) $lat, (float) $lon,
        ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy)) ? (float) $accuracy : null,
        $distance,
    ]);
    create_notification($pdo, $_SESSION['user_id'], 'Attendance Recorded', "You were marked $recordStatus for {$occ['subject_name']} in {$occ['lab_name']}.");
    log_activity($pdo, $_SESSION['user_id'], "Scanned attendance for session #{$current['session_id']} - $recordStatus ({$distance}m)");
    $pdo->commit();

    echo json_encode([
        'success'      => true,
        'message'      => "Marked as $recordStatus for {$occ['subject_name']}.",
        'status'       => $recordStatus,
        'student_name' => $_SESSION['full_name'],
        'subject_name' => $occ['subject_name'],
        'teacher_name' => $occ['teacher_name'],
        'lab_name'     => $occ['lab_name'],
        'date'         => $now->format('F j, Y'),
        'time_in'      => $now->format('h:i A'),
        'distance'     => $distance,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (in_array($e->getCode(), ['23505', '23000'], true)) {   // unique (session_id, student_id)
        echo json_encode(['success' => false, 'message' => ALREADY_RECORDED]);
    } else {
        echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = safe_error_message($e);
    if ($attemptSessionId && $message !== ALREADY_RECORDED) {
        log_scan_attempt($pdo, $attemptSessionId, $studentId, $message, $lat, $lon, $accuracy, $distance);
    }
    echo json_encode(['success' => false, 'message' => $message]);
}
