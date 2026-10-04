<?php
/**
 * teacher/ajax_search_students.php
 * Powers the "Enroll Student" search box on class_view.php. Searches
 * across all students (not just this teacher's) by ID, name, course,
 * year, or section, so a teacher can find and directly enroll anyone.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
header('Content-Type: application/json');

$classId = (int) ($_GET['class_id'] ?? 0);
$q = clean($_GET['q'] ?? '');

if (!$classId || $q === '' || strlen($q) < 2) {
    echo json_encode(['success' => true, 'students' => []]);
    exit;
}

try {
    // Ownership check
    $own = $pdo->prepare('SELECT COUNT(*) FROM teacher_subjects WHERE teacher_subject_id = ? AND teacher_id = ?');
    $own->execute([$classId, $_SESSION['profile_id']]);
    if ($own->fetchColumn() == 0) throw new Exception('Access denied.');

    $stmt = $pdo->prepare('
        SELECT s.student_id, s.student_number, s.full_name, s.year_level, pr.program_code,
            (SELECT status FROM enrollments e WHERE e.student_id = s.student_id AND e.teacher_subject_id = ?) AS enrollment_status
        FROM students s
        LEFT JOIN programs pr ON pr.program_id = s.program_id
        WHERE s.full_name LIKE ? OR s.student_number LIKE ?
        ORDER BY s.full_name LIMIT 15
    ');
    $stmt->execute([$classId, "%$q%", "%$q%"]);
    echo json_encode(['success' => true, 'students' => $stmt->fetchAll()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
