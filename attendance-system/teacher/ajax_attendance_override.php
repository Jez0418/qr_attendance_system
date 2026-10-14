<?php
/**
 * teacher/ajax_attendance_override.php
 * A teacher changes an Absent record of one of their own classes to Present or Excused, with a required reason
 * (includes/attendance_override.php). POST: record_id, reason, target ('Present' (default) or 'Excused').
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_override.php';
require_role('teacher');
header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new Exception('Invalid request.');
    $recordId = (int) ($_POST['record_id'] ?? 0);
    $reason = clean($_POST['reason'] ?? '');
    $target = $_POST['target'] ?? 'Present';
    if (!is_string($target)) $target = '';   // target[]=x: refused by override_problem(), not a PHP error
    $name = override_absent($pdo, $recordId, $target, (int) $_SESSION['profile_id'], (int) $_SESSION['user_id'], $reason);
    // Read the row back: the page shows what is stored, and a success is only reported when it really changed.
    $check = $pdo->prepare('SELECT status FROM attendance_records WHERE record_id = ?');
    $check->execute([$recordId]);
    $stored = (string) $check->fetchColumn();
    if ($stored !== $target) throw new Exception('The change could not be saved. Please try again.');
    echo json_encode(['success' => true, 'message' => "$name is now marked $target.", 'record_id' => $recordId, 'status' => $stored]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
