<?php
/**
 * teacher/ajax_requests.php
 * Approve or reject an enrollment request. A teacher can only act on
 * requests for classes THEY own (re-verified server-side — a teacher
 * cannot approve their own... nor anyone else's unrelated request by
 * guessing a request_id in the URL).
 *
 * On APPROVE:
 *   1. request status -> approved
 *   2. enrollments row created (or reactivated if previously dropped)
 *   3. subject's enrollment count naturally increases (COUNT query)
 *   4. duplicate enrollment prevented
 *   5. student notified
 *   6/7. reviewed_by + reviewed_at recorded
 *
 * On REJECT:
 *   1. request status -> rejected
 *   2. rejection_reason saved (required)
 *   3. reviewed_at recorded
 *   4. student notified
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
header('Content-Type: application/json');

$teacherId = $_SESSION['profile_id'];
$action = $_POST['action'] ?? '';
$requestId = (int) ($_POST['request_id'] ?? 0);

try {
    if (!$requestId) throw new Exception('Invalid request.');

    // Ownership check: the request's class must belong to this teacher
    $reqStmt = $pdo->prepare('
        SELECT r.*, ts.teacher_id, ts.max_students, s.user_id AS student_user_id, s.full_name AS student_name,
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
    if ((int) $request['teacher_id'] !== (int) $teacherId) throw new Exception('You do not have access to this request.');
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

        $pdo->prepare('UPDATE enrollment_requests SET status = "approved", reviewed_by = ?, reviewed_by_role = "teacher", reviewed_by_user_id = ?, reviewed_at = NOW() WHERE request_id = ?')
            ->execute([$teacherId, $_SESSION['user_id'], $requestId]);

        create_notification($pdo, $request['student_user_id'], 'Enrollment Approved', "Your request to enroll in {$request['subject_name']} was approved. You're now officially enrolled.");
        log_activity($pdo, $_SESSION['user_id'], "Approved enrollment request #$requestId");

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Request approved. Student is now enrolled.']);

    } elseif ($action === 'reject') {
        $reason = clean($_POST['rejection_reason'] ?? '');
        if ($reason === '') throw new Exception('A rejection reason is required.');

        $pdo->prepare('UPDATE enrollment_requests SET status = "rejected", rejection_reason = ?, reviewed_by = ?, reviewed_by_role = "teacher", reviewed_by_user_id = ?, reviewed_at = NOW() WHERE request_id = ?')
            ->execute([$reason, $teacherId, $_SESSION['user_id'], $requestId]);

        create_notification($pdo, $request['student_user_id'], 'Enrollment Rejected', "Your request to enroll in {$request['subject_name']} was rejected. Reason: $reason");
        log_activity($pdo, $_SESSION['user_id'], "Rejected enrollment request #$requestId");

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
