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
require_once __DIR__ . '/../includes/schedule.php';
require_role('student');
header('Content-Type: application/json');

$studentId = $_SESSION['profile_id'];

try {
    $classId = (int) ($_POST['teacher_subject_id'] ?? 0);
    $remarks = clean($_POST['remarks'] ?? '');
    if (!$classId) throw new Exception('Invalid subject.');
    if (mb_strlen($remarks) > 500) throw new Exception('Remarks must be at most 500 characters.');

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

    // One request at a time per student and class: the pending check and the insert below must not interleave
    // with a second click or tab (the table has no unique index for this).
    $pdo->beginTransaction();
    $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['enroll-request:' . $studentId . ':' . $classId]);

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

    // Rule 4: does any weekly meeting overlap a class they're already enrolled in?
    $enrolledIds = $pdo->prepare("SELECT teacher_subject_id FROM enrollments WHERE student_id = ? AND status = 'enrolled'");
    $enrolledIds->execute([$studentId]);
    $schedules = get_schedule_rules($pdo, array_merge([$classId], $enrolledIds->fetchAll(PDO::FETCH_COLUMN)));
    $today = schedule_now()->format('Y-m-d');
    $current = fn($r) => empty($r['effective_end_date']) || $r['effective_end_date'] >= $today;   // ignore rules that already ended
    foreach ($schedules as $otherId => $otherSlots) {
        if ($otherId === $classId) continue;
        foreach (array_filter($otherSlots, $current) as $theirs) {
            foreach (array_filter($schedules[$classId], $current) as $mine) {
                if (schedule_rules_conflict($mine, $theirs)) {
                    throw new Exception('This schedule conflicts with another subject in your current enrollment (' . SCHEDULE_DAYS[$mine['day_of_week']] . ').');
                }
            }
        }
    }

    // All checks passed — create the pending request
    $ins = $pdo->prepare('INSERT INTO enrollment_requests (student_id, teacher_subject_id, remarks) VALUES (?, ?, ?)');
    $ins->execute([$studentId, $classId, $remarks !== '' ? $remarks : null]);

    // Notify the teacher who owns this class
    $teacherUser = $pdo->prepare('SELECT u.user_id, sub.subject_code, sub.subject_name FROM teachers t JOIN users u ON u.user_id = t.user_id, subjects sub WHERE t.teacher_id = ? AND sub.subject_id = ?');
    $teacherUser->execute([$class['teacher_id'], $class['subject_id']]);
    if ($t = $teacherUser->fetch()) {
        create_notification($pdo, $t['user_id'], 'New Enrollment Request',
            $_SESSION['full_name'] . ' requested to enroll in ' . $t['subject_code'] . ' - ' . $t['subject_name'] . ' (' . $class['section'] . ').');
    }

    log_activity($pdo, $_SESSION['user_id'], "Requested enrollment in class #$classId");
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Enrollment request submitted. You will be notified once the teacher reviews it.']);

} catch (Exception $e) {   // also catches PDOException (safe_error_message hides its details)
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
