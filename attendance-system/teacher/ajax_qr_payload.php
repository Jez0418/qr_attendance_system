<?php
/**
 * teacher/ajax_qr_payload.php
 * Polled by session.php so the on-screen QR code keeps rotating (see qr/qr_helper.php).
 * Only the class's teacher can fetch a code, and only while the session is open.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('teacher');
header('Content-Type: application/json');
header('Cache-Control: no-store');

$teacherId = $_SESSION['profile_id'];
$sessionId = (int) ($_GET['session_id'] ?? 0);

try {
    if (!$sessionId) throw new Exception('Invalid session.');
    auto_expire_sessions($pdo);

    $stmt = $pdo->prepare('
        SELECT s.session_id, s.qr_token, s.is_active
        FROM attendance_sessions s
        JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
        WHERE s.session_id = ? AND (ts.teacher_id = ? OR s.activated_by = ?)
    ');
    $stmt->execute([$sessionId, $teacherId, $teacherId]);
    $s = $stmt->fetch();
    if (!$s) throw new Exception('Access denied.');
    if ((int) $s['is_active'] !== 1) throw new Exception('Attendance is closed.');

    echo json_encode(['success' => true, 'payload' => qr_build_session_payload($s['session_id'], $s['qr_token'])]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
