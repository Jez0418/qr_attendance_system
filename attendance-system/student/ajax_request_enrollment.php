<?php
/**
 * student/ajax_request_enrollment.php
 * Validates and creates an enrollment_requests row. A student should
 * NOT be able to:
 *   1. Request the same subject twice while a request is pending
 *   2. Request enrollment if already enrolled
 *   3. Request a subject that is already full
 *   4. Request a subject whose schedule conflicts with a class
 *      they're already enrolled in
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');
header('Content-Type: application/json');

$studentId = $_SESSION['profile_id'];

try {
    $classId = (int) ($_POST['teacher_subject_id'] ?? 0);
    $remarks = clean($_POST['remarks'] ?? '');
    if (!$classId) throw new Exception('Invalid subject.');

    $classStmt = $pdo->prepare('SELECT * FROM teacher_subjects WHERE teacher_subject_id = ? AND status = "active"');
    $classStmt->execute([$classId]);
    $class = $classStmt->fetch();
    if (!$class) throw new Exception('This subject is not available for enrollment.');

    // Eligibility: never trust the browse page's filtering alone — a
    // student could edit the DOM/devtools and POST a teacher_subject_id
    // for a class outside their institution/program/year/section. This
    // re-derives eligibility straight from the student's own DB row.
    $studentStmt = $pdo->prepare('SELECT * FROM students WHERE student_id = ?');
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch();
    [$eligible, $reason] = student_eligible_for_class($student, $class);
    if (!$eligible) throw new Exception($reason);

    // Rule 2: already enrolled?
    $enrolledCheck = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND teacher_subject_id = ? AND status = "enrolled"');
    $enrolledCheck->execute([$studentId, $classId]);
    if ($enrolledCheck->fetchColumn() > 0) {
        throw new Exception('You are already enrolled in this subject.');
    }

    // Rule 1: identical request already pending?
    $pendingCheck = $pdo->prepare('SELECT COUNT(*) FROM enrollment_requests WHERE student_id = ? AND teacher_subject_id = ? AND status = "pending"');
    $pendingCheck->execute([$studentId, $classId]);
    if ($pendingCheck->fetchColumn() > 0) {
        throw new Exception('You already have a pending enrollment request for this subject.');
    }

    // Rule 3: subject full?
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE teacher_subject_id = ? AND status = "enrolled"');
    $countStmt->execute([$classId]);
    if ((int) $countStmt->fetchColumn() >= (int) $class['max_students']) {
        throw new Exception('This subject is currently full.');
    }

    // Rule 4: schedule conflict with an already-enrolled class on the same day?
    $conflictStmt = $pdo->prepare("
        SELECT ts2.schedule_day, ts2.start_time, ts2.end_time
        FROM enrollments e
        JOIN teacher_subjects ts2 ON ts2.teacher_subject_id = e.teacher_subject_id
        WHERE e.student_id = ? AND e.status = 'enrolled' AND ts2.schedule_day = ?
    ");
    $conflictStmt->execute([$studentId, $class['schedule_day']]);
    foreach ($conflictStmt->fetchAll() as $existing) {
        $newStart = strtotime($class['start_time']);
        $newEnd = strtotime($class['end_time']);
        $exStart = strtotime($existing['start_time']);
        $exEnd = strtotime($existing['end_time']);
        if ($newStart < $exEnd && $newEnd > $exStart) {
            throw new Exception('This schedule conflicts with another subject in your current enrollment.');
        }
    }

    // All checks passed — create the pending request
    $ins = $pdo->prepare('INSERT INTO enrollment_requests (student_id, teacher_subject_id, remarks) VALUES (?, ?, ?)');
    $ins->execute([$studentId, $classId, $remarks !== '' ? $remarks : null]);

    // Notify the teacher who owns this class
    $teacherUser = $pdo->prepare('SELECT u.user_id FROM teachers t JOIN users u ON u.user_id = t.user_id WHERE t.teacher_id = ?');
    $teacherUser->execute([$class['teacher_id']]);
    $teacherUserId = $teacherUser->fetchColumn();
    if ($teacherUserId) {
        create_notification($pdo, $teacherUserId, 'New Enrollment Request', $_SESSION['full_name'] . ' has requested to enroll in one of your classes.');
    }

    log_activity($pdo, $_SESSION['user_id'], "Requested enrollment in class #$classId");
    echo json_encode(['success' => true, 'message' => 'Enrollment request submitted. You will be notified once the teacher reviews it.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
