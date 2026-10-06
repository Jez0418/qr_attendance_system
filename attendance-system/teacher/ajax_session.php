<?php
/**
 * teacher/ajax_session.php
 * Close (end early) or reopen an attendance session. Sessions open
 * automatically when a meeting becomes ACTIVE (qr/session_manager.php);
 * a closed one can be reopened (same QR code) while the meeting is still
 * ACTIVE. Allowed for the class's own teacher and for a substitute
 * teaching that meeting (activated_by).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('teacher');
header('Content-Type: application/json');

auto_expire_sessions($pdo);

$teacherId = (int) $_SESSION['profile_id'];
$action = $_POST['action'] ?? '';
$sessionId = (int) ($_POST['session_id'] ?? 0);

try {
    if (!in_array($action, ['close', 'reopen'], true)) throw new Exception('Unknown action.');
    if (!$sessionId) throw new Exception('Invalid session.');

    $own = $pdo->prepare('
        SELECT s.session_id FROM attendance_sessions s
        JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
        WHERE s.session_id = ? AND (ts.teacher_id = ? OR s.activated_by = ?)
    ');
    $own->execute([$sessionId, $teacherId, $teacherId]);
    if (!$own->fetch()) throw new Exception('You do not have access to this session.');

    if ($action === 'reopen') {
        reactivate_attendance_session_by_id($pdo, $sessionId);
        log_activity($pdo, $_SESSION['user_id'], "Reopened attendance session #$sessionId");
        echo json_encode(['success' => true, 'message' => 'Attendance reopened. The same QR code works again.']);
        exit;
    }

    if (!deactivate_attendance_session_by_id($pdo, $sessionId)) {
        throw new Exception('This session is already closed.');
    }
    log_activity($pdo, $_SESSION['user_id'], "Closed attendance session #$sessionId");
    echo json_encode(['success' => true, 'message' => 'Attendance closed.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
