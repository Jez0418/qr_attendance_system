<?php
/**
 * admin/ajax_assignments.php — create / update / delete teacher_subjects
 * (class assignments). The institution/department/program cascade shown
 * to the admin is purely a UX convenience — this endpoint independently
 * re-verifies that the submitted program actually belongs to the
 * submitted department, which belongs to the submitted institution,
 * before ever writing to the database. Never trust the browser.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

function validate_assignment_input($pdo) {
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $labId     = (int) ($_POST['lab_id'] ?? 0);
    $section   = clean($_POST['section'] ?? '');
    $day       = clean($_POST['schedule_day'] ?? '');
    $start     = clean($_POST['start_time'] ?? '');
    $end       = clean($_POST['end_time'] ?? '');
    $status    = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
    $institutionId = (int) ($_POST['institution_id'] ?? 0);
    $departmentId  = (int) ($_POST['department_id'] ?? 0);
    $programId = (int) ($_POST['program_id'] ?? 0);
    $yearLevel = $_POST['year_level'] !== '' ? (int) $_POST['year_level'] : null;
    $maxStudents = (int) ($_POST['max_students'] ?? 40);
    $meetingDate = clean($_POST['meeting_date'] ?? '');

    if (!$teacherId || !$subjectId || !$labId || $section === '' || $start === '' || $end === ''
        || !$institutionId || !$departmentId || !$programId || !$yearLevel || !$meetingDate) {
        throw new Exception('Please fill in all required fields.');
    }
    if (strtotime($end) <= strtotime($start)) {
        throw new Exception('End time must be after start time.');
    }
    if ($maxStudents < 1 || $maxStudents > 200) {
        throw new Exception('Max students must be between 1 and 200.');
    }
    $dateObj = DateTime::createFromFormat('Y-m-d', $meetingDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $meetingDate) {
        throw new Exception('Invalid meeting date.');
    }
    // Derive the day name from the date itself server-side — never trust the client's "day" value.
    $day = $dateObj->format('l');

    // Re-verify the hierarchy server-side: program must belong to department must belong to institution.
    $check = $pdo->prepare('
        SELECT p.program_id, p.duration_years FROM programs p
        JOIN departments d ON d.department_id = p.department_id
        WHERE p.program_id = ? AND p.department_id = ? AND d.institution_id = ? AND p.status = "active"
    ');
    $check->execute([$programId, $departmentId, $institutionId]);
    $program = $check->fetch();
    if (!$program) {
        throw new Exception('Invalid institution/department/program combination.');
    }
    $maxYear = program_max_year($program['duration_years']);
    if ($yearLevel < 1 || $yearLevel > $maxYear) {
        throw new Exception("Year level must be between 1 and $maxYear for this program.");
    }

    return [$teacherId, $subjectId, $labId, $section, $day, $start, $end, $status, $institutionId, $departmentId, $programId, $yearLevel, $maxStudents, $meetingDate];
}

try {
    if ($action === 'create') {
        [$teacherId, $subjectId, $labId, $section, $day, $start, $end, $status, $institutionId, $departmentId, $programId, $yearLevel, $maxStudents, $meetingDate] = validate_assignment_input($pdo);
        $stmt = $pdo->prepare('
            INSERT INTO teacher_subjects
                (teacher_id, subject_id, lab_id, section, schedule_day, meeting_date, start_time, end_time, status,
                 institution_id, department_id, program_id, year_level, max_students)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$teacherId, $subjectId, $labId, $section, $day, $meetingDate, $start, $end, $status, $institutionId, $departmentId, $programId, $yearLevel, $maxStudents]);
        log_activity($pdo, $_SESSION['user_id'], "Created class assignment (section $section, meeting $meetingDate)");
        echo json_encode(['success' => true, 'message' => 'Class assignment created successfully.']);

    } elseif ($action === 'update') {
        $id = (int) ($_POST['teacher_subject_id'] ?? 0);
        if (!$id) throw new Exception('Invalid assignment.');
        [$teacherId, $subjectId, $labId, $section, $day, $start, $end, $status, $institutionId, $departmentId, $programId, $yearLevel, $maxStudents, $meetingDate] = validate_assignment_input($pdo);

        // Don't let max_students drop below the number of students already enrolled
        $enrolledCountStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE teacher_subject_id = ? AND status = "enrolled"');
        $enrolledCountStmt->execute([$id]);
        $enrolledCount = (int) $enrolledCountStmt->fetchColumn();
        if ($maxStudents < $enrolledCount) {
            throw new Exception("Max students cannot be lower than the number of students already enrolled ($enrolledCount).");
        }

        $stmt = $pdo->prepare('
            UPDATE teacher_subjects SET
                teacher_id=?, subject_id=?, lab_id=?, section=?, schedule_day=?, meeting_date=?, start_time=?, end_time=?, status=?,
                institution_id=?, department_id=?, program_id=?, year_level=?, max_students=?
            WHERE teacher_subject_id=?
        ');
        $stmt->execute([$teacherId, $subjectId, $labId, $section, $day, $meetingDate, $start, $end, $status, $institutionId, $departmentId, $programId, $yearLevel, $maxStudents, $id]);
        log_activity($pdo, $_SESSION['user_id'], "Updated class assignment ID $id");
        echo json_encode(['success' => true, 'message' => 'Class assignment updated successfully.']);

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['teacher_subject_id'] ?? 0);
        if (!$id) throw new Exception('Invalid assignment.');
        $stmt = $pdo->prepare('DELETE FROM teacher_subjects WHERE teacher_subject_id = ?');
        $stmt->execute([$id]);
        log_activity($pdo, $_SESSION['user_id'], "Deleted class assignment ID $id");
        echo json_encode(['success' => true, 'message' => 'Class assignment deleted successfully.']);
    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
