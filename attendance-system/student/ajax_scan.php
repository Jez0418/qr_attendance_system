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
 *   2.  QR payload a genuine, signed laboratory code?
 *                                                  qr_parse_lab_payload()
 *   3.  Laboratory active and the code its current version?
 *                                                  lab_qr_version() (Regenerate revokes a printout)
 *   4.  Which meeting is ACTIVE in that lab now? Recomputed from the class
 *       schedule (includes/schedule.php), cancelled meetings never match,
 *       one moved into this lab does:                resolve_lab_occurrence()
 *   5.  That meeting's attendance session           ensure_session_for_occurrence()
 *   6.  Session still open (not closed early, not past session_end)?
 *   7.  Student enrolled in the class?
 *   8.  Location provided, accuracy reported and good enough, inside the lab's radius?
 *                                                  server-side Haversine
 *   9.  Not already recorded?
 *  10.  This phone hasn't already checked in another student for this session?
 *                                                  device_conflict() (includes/device.php)
 *
 * Every rejected scan that got as far as a meeting's session (step 5) is also saved in
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
require_once __DIR__ . '/../includes/device.php';
require_role('student');
$deviceId = current_device_id();   // before any output: may set the cookie
header('Content-Type: application/json');

auto_expire_sessions($pdo);

$studentId = (int) $_SESSION['profile_id'];
const INVALID_QR = 'This QR code is invalid or has expired.';
const ALREADY_RECORDED = 'You have already recorded attendance for this class.';
const SAME_DEVICE = 'This phone was already used to check in another student for this class. Ask your teacher to mark you present.';
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
$attemptReason = null;   // what the teacher sees for a rejected scan, when it differs from the student's message
$lat = $_POST['latitude'] ?? null;
$lon = $_POST['longitude'] ?? null;
$accuracy = $_POST['accuracy'] ?? null;

try {
    // ---- 2. QR payload a genuine laboratory code? ----
    $rawPayload = $_POST['qr_payload'] ?? '';
    if ($rawPayload === '') throw new Exception('No QR data received.');
    $parsed = qr_parse_lab_payload($rawPayload);
    if (!$parsed) throw new Exception(INVALID_QR);

    // ---- 3. Laboratory exists, is in use, and the printed code is its current version? ----
    $labStmt = $pdo->prepare("SELECT lab_id, latitude, longitude FROM laboratories WHERE lab_id = ? AND status = 'active'");
    $labStmt->execute([$parsed['lab_id']]);
    $lab = $labStmt->fetch();
    if (!$lab || $parsed['version'] !== lab_qr_version($pdo, (int) $lab['lab_id'])) {
        throw new Exception(INVALID_QR);   // unknown lab, or a code that was regenerated and reprinted
    }

    // ---- 4. Which meeting is ACTIVE in this lab right now (status recomputed from the schedule)? ----
    $now = schedule_now();
    $pick = resolve_lab_occurrence($pdo, (int) $lab['lab_id'], $studentId, $now);
    $occ = $pick['occurrence'];
    if (!$occ) throw new Exception($pick['error']);

    // Where is the student relative to the lab? Worked out now (best effort) so that even a
    // scan rejected for another reason records the distance.
    if ($lab['latitude'] !== null && $lab['longitude'] !== null && is_numeric($lat) && is_numeric($lon)) {
        $distance = round(haversine_distance_meters((float) $lat, (float) $lon, (float) $lab['latitude'], (float) $lab['longitude']), 2);
    }

    // ---- 5. That meeting's attendance session (opened by the first look while the meeting is ACTIVE) ----
    $current = ensure_session_for_occurrence($pdo, $occ, $now);
    if (!$current) {
        throw new Exception($lab['latitude'] === null || $lab['longitude'] === null
            ? 'This laboratory has no GPS coordinates configured. Please contact your administrator.'
            : INVALID_QR);
    }
    $session = $current;
    $attemptSessionId = (int) $current['session_id'];   // from here on a rejection is logged for the teacher

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
    $accuracyProblem = gps_accuracy_problem($accuracy, get_setting_int($pdo, 'max_gps_accuracy_meters', 100));   // missing counts as a problem
    if ($accuracyProblem !== '') throw new Exception($accuracyProblem);
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

    // ---- 10. One phone, one student per meeting ----
    $otherStudent = device_conflict($pdo, (int) $current['session_id'], $deviceId, $studentId);
    if ($otherStudent !== null) {
        $attemptReason = 'Same phone as ' . $otherStudent;   // the teacher sees who; the student does not
        throw new Exception(SAME_DEVICE);
    }

    // ---- Late logic: start + the class's late grace (or the settings default) ----
    $lateAfter = (new DateTimeImmutable($occ['starts_at'], schedule_tz()))->modify('+' . get_late_grace_minutes($pdo, $occ['late_grace_minutes'] ?? null) . ' minutes');
    $recordStatus = $now > $lateAfter ? 'Late' : 'Present';

    $pdo->beginTransaction();
    $ins = $pdo->prepare('
        INSERT INTO attendance_records (session_id, student_id, time_in, status, latitude, longitude, location_accuracy, distance_from_location, device_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $current['session_id'], $studentId, $now->format('Y-m-d H:i:s'), $recordStatus,
        (float) $lat, (float) $lon,
        ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy)) ? (float) $accuracy : null,
        $distance, $deviceId,
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
        log_scan_attempt($pdo, $attemptSessionId, $studentId, $attemptReason ?? $message, $lat, $lon, $accuracy, $distance);
    }
    echo json_encode(['success' => false, 'message' => $message]);
}
