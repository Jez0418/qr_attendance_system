<?php
/**
 * ============================================================
 * student/ajax_scan.php
 * CORE ATTENDANCE VALIDATION ENGINE.
 *
 * QR codes are temporary and session-scoped: every activation mints
 * a brand-new random token (qr/session_manager.php), so an old
 * screenshot or a token from a previous session can never work
 * again. Every decision below happens server-side, in this order —
 * the browser's claims (about identity, time, or location) are
 * never trusted on their own:
 *
 *   1.  Student authenticated?              (require_role)
 *   2.  QR token well-formed & signed?        qr_parse_session_payload()
 *   3.  Session exists?
 *   4.  Session active?
 *   5.  Session not expired?
 *   6.  Session belongs to the right subject? (implicit — 1 session = 1 class)
 *   7.  Student enrolled in that subject?
 *   8.  Today's date matches the class's meeting date?
 *   9.  Current time within the allowed attendance period?
 *   10. Location available?
 *   11. GPS accuracy acceptable?
 *   12. Inside the allowed radius (Haversine)?
 *   13. Not already recorded?
 *
 * Wrapped in a DB transaction so a failure partway through never
 * leaves a partial/inconsistent record behind.
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../qr/qr_helper.php';
require_role('student');
header('Content-Type: application/json');

auto_expire_sessions($pdo);

$studentId = $_SESSION['profile_id'];

try {
    // ---- Step 2: QR token well-formed and correctly signed? ----
    $rawPayload = $_POST['qr_payload'] ?? '';
    if ($rawPayload === '') throw new Exception('No QR data received.');

    $parsed = qr_parse_session_payload($rawPayload);
    if (!$parsed) throw new Exception('This QR code is invalid or has expired.');

    $stmt = $pdo->prepare('
        SELECT s.*, ts.teacher_subject_id, ts.max_students,
            sub.subject_name, sub.subject_code,
            t.full_name AS teacher_name,
            lab.lab_name, lab.latitude AS lab_lat, lab.longitude AS lab_lon
        FROM attendance_sessions s
        JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        JOIN teachers t ON t.teacher_id = ts.teacher_id
        JOIN laboratories lab ON lab.lab_id = ts.lab_id
        WHERE s.session_id = ?
    ');
    $stmt->execute([$parsed['session_id']]);
    $session = $stmt->fetch();

    // ---- Step 3: Session exists, and the scanned token still matches it? ----
    if (!$session) throw new Exception('This QR code is invalid or has expired.');
    if (empty($session['qr_token']) || !hash_equals((string) $session['qr_token'], $parsed['token'])) {
        throw new Exception('This QR code is invalid or has expired.');
    }

    // ---- Step 4: Session active? ----
    if ((int) $session['is_active'] !== 1) {
        throw new Exception('This attendance QR code is currently inactive.');
    }

    // ---- Step 5: Session not expired? ----
    if ($session['session_end'] && strtotime($session['session_end']) < time()) {
        $pdo->prepare('UPDATE attendance_sessions SET is_active = 0, deactivated_at = NOW() WHERE session_id = ?')->execute([$session['session_id']]);
        throw new Exception('This QR code is invalid or has expired.');
    }

    // ---- Step 6: Session belongs to a real subject/class (guaranteed by the JOINs above) ----

    // ---- Step 7: Student enrolled in that subject? ----
    $enrollCheck = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND teacher_subject_id = ? AND status = "enrolled"');
    $enrollCheck->execute([$studentId, $session['teacher_subject_id']]);
    if ($enrollCheck->fetchColumn() == 0) {
        throw new Exception('You are not enrolled in this subject.');
    }

    // ---- Step 8: Session is for today's meeting? (sessions are only ever
    // opened on a scheduled weekday — qr/session_manager.php) ----
    if ($session['session_date'] !== date('Y-m-d')) {
        throw new Exception('This class is not scheduled for today.');
    }

    // ---- Step 9: Current time within the allowed attendance period? ----
    // (session_end already gates the closing edge — steps 4/5 above. The
    // opening edge allows a configurable grace period before start, since
    // a teacher may open attendance slightly early.)
    $scheduledStartDt = new DateTime($session['scheduled_start']);
    $earliestAllowed = (clone $scheduledStartDt)->modify('-' . ATTENDANCE_EARLY_GRACE_MINUTES . ' minutes');
    if (new DateTime() < $earliestAllowed) {
        throw new Exception('Attendance for this class is not open yet.');
    }

    // ---- Step 10: Location available? ----
    $lat = $_POST['latitude'] ?? null;
    $lon = $_POST['longitude'] ?? null;
    $accuracy = $_POST['accuracy'] ?? null;
    if ($lat === null || $lon === null || $lat === '' || $lon === '' || !is_numeric($lat) || !is_numeric($lon)) {
        throw new Exception('Location access is required to verify attendance.');
    }

    // ---- Step 11: GPS accuracy acceptable? ----
    $maxAccuracy = get_setting_int($pdo, 'max_gps_accuracy_meters', 100);
    if ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy) && (float) $accuracy > $maxAccuracy) {
        throw new Exception('Your location accuracy is too low. Please enable high-accuracy location services and try again.');
    }

    // ---- Step 12: Inside the allowed radius? (server-side Haversine — never trust the client) ----
    if ($session['lab_lat'] === null || $session['lab_lon'] === null) {
        throw new Exception('This laboratory has no GPS coordinates configured. Please contact your administrator.');
    }
    [$withinRadius, $distance] = is_within_geofence((float) $lat, (float) $lon, (float) $session['lab_lat'], (float) $session['lab_lon'], (int) $session['allowed_radius_meters']);
    if (!$withinRadius) {
        throw new Exception('You are outside the allowed attendance area.');
    }

    // ---- Step 13: Not already recorded? ----
    $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM attendance_records WHERE session_id = ? AND student_id = ?');
    $dupCheck->execute([$session['session_id'], $studentId]);
    if ($dupCheck->fetchColumn() > 0) {
        throw new Exception('You have already recorded attendance for this class.');
    }

    // ---- Record attendance (inside a transaction) ----
    $pdo->beginTransaction();

    $now = new DateTime();
    $lateThreshold = (clone $scheduledStartDt)->modify('+' . (int) $session['late_threshold_minutes'] . ' minutes');
    $status = ($now > $lateThreshold) ? 'Late' : 'Present';

    $ins = $pdo->prepare('
        INSERT INTO attendance_records (session_id, student_id, time_in, status, latitude, longitude, location_accuracy, distance_from_location)
        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $session['session_id'], $studentId, $status,
        (float) $lat, (float) $lon,
        ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy)) ? (float) $accuracy : null,
        $distance,
    ]);

    $studentName = $_SESSION['full_name'];
    create_notification($pdo, $_SESSION['user_id'], 'Attendance Recorded', "You were marked $status for {$session['subject_name']} in {$session['lab_name']}.");
    log_activity($pdo, $_SESSION['user_id'], "Scanned attendance for session #{$session['session_id']} - $status ({$distance}m)");

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Marked as $status for {$session['subject_name']}.",
        'status' => $status,
        'student_name' => $studentName,
        'subject_name' => $session['subject_name'],
        'teacher_name' => $session['teacher_name'],
        'lab_name' => $session['lab_name'],
        'date' => date('F j, Y'),
        'time_in' => date('h:i A'),
        'distance' => $distance,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => 'You have already recorded attendance for this class.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
