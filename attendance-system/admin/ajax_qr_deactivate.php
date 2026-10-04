<?php
/**
 * admin/ajax_qr_deactivate.php
 * Administrator closes an attendance session early, by session_id.
 * (Sessions open automatically from the class schedule; there is no
 * manual activation.)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('admin');
header('Content-Type: application/json');

try {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    if (!$sessionId) throw new Exception('Invalid request.');
    if (!deactivate_attendance_session_by_id($pdo, $sessionId)) {
        throw new Exception('Session already closed or not found.');
    }
    log_activity($pdo, $_SESSION['user_id'], "Admin closed attendance session #$sessionId");
    echo json_encode(['success' => true, 'message' => 'Attendance closed.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
