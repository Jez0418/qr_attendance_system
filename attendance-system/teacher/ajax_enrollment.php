<?php
/**
 * teacher/ajax_enrollment.php
 * Enroll / unenroll a student into one of the teacher's own classes.
 * Verifies the class actually belongs to the logged-in teacher.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
header('Content-Type: application/json');

$teacherId = $_SESSION['profile_id'];
$action = $_POST['action'] ?? '';
$studentId = (int) ($_POST['student_id'] ?? 0);
$classId = (int) ($_POST['teacher_subject_id'] ?? 0);

try {
    if (!$studentId || !$classId) throw new Exception('Invalid request.');

    // Ownership check: this class must belong to the logged-in teacher
    $own = $pdo->prepare('SELECT COUNT(*) FROM teacher_subjects WHERE teacher_subject_id = ? AND teacher_id = ?');
    $own->execute([$classId, $teacherId]);
    if ($own->fetchColumn() == 0) throw new Exception('You do not have access to this class.');

    if ($action === 'enroll') {
        $check = $pdo->prepare('SELECT enrollment_id, status FROM enrollments WHERE student_id = ? AND teacher_subject_id = ?');
        $check->execute([$studentId, $classId]);
        $existing = $check->fetch();

        if ($existing) {
            $upd = $pdo->prepare('UPDATE enrollments SET status = "enrolled" WHERE enrollment_id = ?');
            $upd->execute([$existing['enrollment_id']]);
        } else {
            $ins = $pdo->prepare('INSERT INTO enrollments (student_id, teacher_subject_id) VALUES (?, ?)');
            $ins->execute([$studentId, $classId]);
        }

        $stu = $pdo->prepare('SELECT user_id FROM students WHERE student_id = ?');
        $stu->execute([$studentId]);
        $userId = $stu->fetchColumn();
        if ($userId) create_notification($pdo, $userId, 'Enrolled in a class', 'You have been enrolled in a new class. Check your schedule for details.');

        echo json_encode(['success' => true, 'message' => 'Student enrolled successfully.']);

    } elseif ($action === 'unenroll') {
        $upd = $pdo->prepare('UPDATE enrollments SET status = "dropped" WHERE student_id = ? AND teacher_subject_id = ?');
        $upd->execute([$studentId, $classId]);
        echo json_encode(['success' => true, 'message' => 'Student removed from class.']);

    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
