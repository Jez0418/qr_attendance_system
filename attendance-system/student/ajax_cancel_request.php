<?php
/**
 * student/ajax_cancel_request.php
 * Lets a student withdraw their own PENDING enrollment request.
 * Ownership is re-verified server-side (a student can only cancel
 * their own request, and only while it's still pending).
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');
header('Content-Type: application/json');

$studentId = $_SESSION['profile_id'];
$requestId = (int) ($_POST['request_id'] ?? 0);

try {
    if (!$requestId) throw new Exception('Invalid request.');

    $stmt = $pdo->prepare('SELECT status FROM enrollment_requests WHERE request_id = ? AND student_id = ?');
    $stmt->execute([$requestId, $studentId]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Request not found.');
    if ($row['status'] !== 'pending') throw new Exception('Only a pending request can be cancelled.');

    $pdo->prepare('UPDATE enrollment_requests SET status = "cancelled", reviewed_at = NOW() WHERE request_id = ?')->execute([$requestId]);
    log_activity($pdo, $_SESSION['user_id'], "Cancelled enrollment request #$requestId");

    echo json_encode(['success' => true, 'message' => 'Request cancelled.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
