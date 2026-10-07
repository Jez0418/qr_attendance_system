<?php
/**
 * teacher/ajax_attendance_override.php
 * A teacher changes an Absent record of one of their own classes to Present, with a required reason
 * (includes/attendance_override.php). POST: record_id, reason.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_override.php';
require_role('teacher');
header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new Exception('Invalid request.');
    $recordId = (int) ($_POST['record_id'] ?? 0);
    $reason = clean($_POST['reason'] ?? '');
    $name = override_absent_to_present($pdo, $recordId, (int) $_SESSION['profile_id'], (int) $_SESSION['user_id'], $reason);
    echo json_encode(['success' => true, 'message' => "$name is now marked Present."]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
