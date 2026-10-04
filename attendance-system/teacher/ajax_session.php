<?php
/**
 * teacher/ajax_session.php
 * Activate or deactivate the QR attendance session for one of the
 * teacher's own classes. Delegates the actual session lifecycle to
 * qr/session_manager.php, which is shared with the admin-side
 * activation flow so both stay perfectly in sync.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('teacher');
header('Content-Type: application/json');

auto_expire_sessions($pdo);

$teacherId = $_SESSION['profile_id'];
$action = $_POST['action'] ?? '';
$classId = (int) ($_POST['teacher_subject_id'] ?? 0);

try {
    if (!$classId) throw new Exception('Invalid class.');

    $classStmt = $pdo->prepare('SELECT * FROM teacher_subjects WHERE teacher_subject_id = ? AND teacher_id = ?');
    $classStmt->execute([$classId, $teacherId]);
    if (!$classStmt->fetch()) throw new Exception('You do not have access to this class.');

    if ($action === 'activate') {
        $result = activate_attendance_session($pdo, $classId, 'teacher', $_SESSION['user_id']);
        if (!$result['success']) throw new Exception($result['message']);

        log_activity($pdo, $_SESSION['user_id'], "Activated attendance session for class #$classId");
        echo json_encode(['success' => true, 'message' => $result['message']]);

    } elseif ($action === 'deactivate') {
        if (!deactivate_attendance_session($pdo, $classId)) {
            throw new Exception('No active session found for this class.');
        }
        log_activity($pdo, $_SESSION['user_id'], "Deactivated attendance session for class #$classId");
        echo json_encode(['success' => true, 'message' => 'Attendance session deactivated.']);

    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
