<?php
/**
 * admin/ajax_requests.php
 * Lets an administrator approve or reject ANY enrollment request
 * directly — not just the owning teacher. Mirrors the exact same
 * approval logic as teacher/ajax_requests.php (capacity re-check,
 * duplicate-safe enrollment creation, notification, audit trail),
 * just without the teacher-ownership restriction.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$requestId = (int) ($_POST['request_id'] ?? 0);

try {
    if (!$requestId) throw new Exception('Invalid request.');

    $reqStmt = $pdo->prepare('
        SELECT r.*, ts.max_students, s.user_id AS student_user_id, s.full_name AS student_name,
            sub.subject_name
        FROM enrollment_requests r
        JOIN teacher_subjects ts ON ts.teacher_subject_id = r.teacher_subject_id
        JOIN students s ON s.student_id = r.student_id
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        WHERE r.request_id = ?
    ');
    $reqStmt->execute([$requestId]);
    $request = $reqStmt->fetch();

    if (!$request) throw new Exception('Request not found.');
    if ($request['status'] !== 'pending') throw new Exception('This request has already been reviewed.');

    if ($action === 'approve') {
        $pdo->beginTransaction();

        // Re-check capacity at approval time (it may have filled up since the request was made)
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE teacher_subject_id = ? AND status = "enrolled"');
        $countStmt->execute([$request['teacher_subject_id']]);
        if ((int) $countStmt->fetchColumn() >= (int) $request['max_students']) {
            throw new Exception('This subject is now full — cannot approve.');
        }

        // Create or reactivate the enrollment (never duplicate)
        $existing = $pdo->prepare('SELECT enrollment_id FROM enrollments WHERE student_id = ? AND teacher_subject_id = ?');
        $existing->execute([$request['student_id'], $request['teacher_subject_id']]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            $pdo->prepare('UPDATE enrollments SET status = "enrolled" WHERE enrollment_id = ?')->execute([$existingRow['enrollment_id']]);
        } else {
            $pdo->prepare('INSERT INTO enrollments (student_id, teacher_subject_id) VALUES (?, ?)')->execute([$request['student_id'], $request['teacher_subject_id']]);
        }

        $pdo->prepare('UPDATE enrollment_requests SET status = "approved", reviewed_by_role = "admin", reviewed_by_user_id = ?, reviewed_at = NOW() WHERE request_id = ?')
            ->execute([$_SESSION['user_id'], $requestId]);

        create_notification($pdo, $request['student_user_id'], 'Enrollment Approved', "Your request to enroll in {$request['subject_name']} was approved by the administrator. You're now officially enrolled.");
        log_activity($pdo, $_SESSION['user_id'], "Admin approved enrollment request #$requestId");

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Request approved. Student is now enrolled.']);

    } elseif ($action === 'reject') {
        $reason = clean($_POST['rejection_reason'] ?? '');
        if ($reason === '') throw new Exception('A rejection reason is required.');

        $pdo->prepare('UPDATE enrollment_requests SET status = "rejected", rejection_reason = ?, reviewed_by_role = "admin", reviewed_by_user_id = ?, reviewed_at = NOW() WHERE request_id = ?')
            ->execute([$reason, $_SESSION['user_id'], $requestId]);

        create_notification($pdo, $request['student_user_id'], 'Enrollment Rejected', "Your request to enroll in {$request['subject_name']} was rejected by the administrator. Reason: $reason");
        log_activity($pdo, $_SESSION['user_id'], "Admin rejected enrollment request #$requestId");

        echo json_encode(['success' => true, 'message' => 'Request rejected.']);

    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
