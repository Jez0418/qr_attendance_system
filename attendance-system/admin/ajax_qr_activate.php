<?php
/**
 * admin/ajax_qr_activate.php
 * Administrator activates a QR attendance session for a class. The
 * class picker on the front end only ever lists real teacher_subjects
 * rows, so an invalid teacher/subject combination can't be submitted —
 * this endpoint further re-verifies the class exists server-side
 * before doing anything.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('admin');
header('Content-Type: application/json');

try {
    $classId = (int) ($_POST['teacher_subject_id'] ?? 0);
    $sessionDate = clean($_POST['session_date'] ?? '') ?: date('Y-m-d');
    $startTime = clean($_POST['start_time'] ?? '');
    $endTime = clean($_POST['end_time'] ?? '');
    $radius = (int) ($_POST['radius'] ?? 0);

    if (!$classId) throw new Exception('Please select a class.');
    if ($startTime === '' || $endTime === '') throw new Exception('Start and end time are required.');
    if ($radius < 5 || $radius > 1000) throw new Exception('Allowed radius must be between 5 and 1000 meters.');

    // Re-verify this class actually exists (defense in depth — never trust the client)
    $check = $pdo->prepare('SELECT teacher_subject_id FROM teacher_subjects WHERE teacher_subject_id = ? AND status = "active"');
    $check->execute([$classId]);
    if (!$check->fetch()) throw new Exception('Invalid or inactive class assignment.');

    $result = activate_attendance_session($pdo, $classId, 'admin', $_SESSION['user_id'], $sessionDate, $startTime, $endTime, $radius);
    if (!$result['success']) throw new Exception($result['message']);

    log_activity($pdo, $_SESSION['user_id'], "Admin-activated QR session for class #$classId");
    echo json_encode(['success' => true, 'message' => 'QR session activated.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
