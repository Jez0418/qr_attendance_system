<?php
/**
 * admin/ajax_qr_reactivate.php
 * Administrator reopens a closed attendance session, by session_id.
 * Only while its class meeting is still ACTIVE; the QR token is kept,
 * so the code already shown to students works again.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('admin');
header('Content-Type: application/json');

auto_expire_sessions($pdo);

try {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    if (!$sessionId) throw new Exception('Invalid request.');
    reactivate_attendance_session_by_id($pdo, $sessionId);
    log_activity($pdo, $_SESSION['user_id'], "Admin reopened attendance session #$sessionId");
    echo json_encode(['success' => true, 'message' => 'Attendance reopened. The same QR code works again.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
