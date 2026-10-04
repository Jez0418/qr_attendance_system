<?php
/**
 * admin/ajax_qr_deactivate.php
 * Administrator deactivates a QR session — either by class (closes
 * today's active session for that class) or directly by session_id
 * (used by the "Force Stop" button on the live sessions grid).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('admin');
header('Content-Type: application/json');

try {
    $classId = (int) ($_POST['teacher_subject_id'] ?? 0);
    $sessionId = (int) ($_POST['session_id'] ?? 0);

    if ($sessionId) {
        if (!deactivate_attendance_session_by_id($pdo, $sessionId)) {
            throw new Exception('Session already closed or not found.');
        }
        log_activity($pdo, $_SESSION['user_id'], "Admin force-stopped attendance session #$sessionId");
    } elseif ($classId) {
        if (!deactivate_attendance_session($pdo, $classId)) {
            throw new Exception('No active session found for this class.');
        }
        log_activity($pdo, $_SESSION['user_id'], "Admin deactivated attendance session for class #$classId");
    } else {
        throw new Exception('Invalid request.');
    }

    echo json_encode(['success' => true, 'message' => 'Session deactivated successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
