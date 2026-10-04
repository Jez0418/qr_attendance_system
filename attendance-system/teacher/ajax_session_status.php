<?php
/**
 * teacher/ajax_session_status.php
 * Polled every few seconds by session.php to show newly scanned
 * students in real time without a full page reload.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
header('Content-Type: application/json');

$teacherId = $_SESSION['profile_id'];
$sessionId = (int) ($_GET['session_id'] ?? 0);

try {
    if (!$sessionId) throw new Exception('Invalid session.');

    // Ownership check + fetch the class this session belongs to
    $own = $pdo->prepare('
        SELECT ts.teacher_subject_id FROM attendance_sessions s
        JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
        WHERE s.session_id = ? AND ts.teacher_id = ?
    ');
    $own->execute([$sessionId, $teacherId]);
    $classId = $own->fetchColumn();
    if (!$classId) throw new Exception('Access denied.');

    // Full enrolled roster, LEFT JOINed against this session's scan records
    // so students who haven't scanned yet still appear with status "Pending".
    $stmt = $pdo->prepare('
        SELECT s.full_name, s.student_number, ar.time_in, ar.status
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        LEFT JOIN attendance_records ar ON ar.student_id = s.student_id AND ar.session_id = ?
        WHERE e.teacher_subject_id = ? AND e.status = "enrolled"
        ORDER BY (ar.time_in IS NULL), ar.time_in DESC, s.full_name ASC
    ');
    $stmt->execute([$sessionId, $classId]);
    $records = array_map(function ($r) {
        return [
            'full_name' => $r['full_name'],
            'student_number' => $r['student_number'],
            'time_in' => $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '—',
            'status' => $r['status'] ?? 'Pending',
        ];
    }, $stmt->fetchAll());

    $scannedCount = count(array_filter($records, fn($r) => $r['status'] !== 'Pending'));
    echo json_encode(['success' => true, 'records' => $records, 'scanned_count' => $scannedCount, 'total_count' => count($records)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
