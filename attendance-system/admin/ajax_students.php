<?php
/**
 * admin/ajax_students.php
 * Handles create / update / delete of student records via AJAX.
 * Always returns JSON: { success: bool, message: string }
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

/**
 * Reads+validates institution/department/program/year/section/type from
 * POST, re-verifying the hierarchy server-side (program really belongs to
 * department really belongs to institution) rather than trusting the
 * cascade the browser already filtered.
 */
function read_academic_fields(PDO $pdo) {
    $institutionId = (int) ($_POST['institution_id'] ?? 0);
    $departmentId  = (int) ($_POST['department_id'] ?? 0);
    $programId     = (int) ($_POST['program_id'] ?? 0);
    $yearLevel     = (int) ($_POST['year_level'] ?? 0);
    $sectionLetter = strtoupper(trim($_POST['section_letter'] ?? ''));
    $studentType   = in_array($_POST['student_type'] ?? '', ['regular', 'irregular']) ? $_POST['student_type'] : 'regular';

    if (!$institutionId || !$departmentId || !$programId || !$yearLevel || $sectionLetter === '') {
        throw new Exception('Please complete the academic placement fields (institution, department, program, year, section).');
    }
    if (!preg_match('/^[A-Z][A-Z0-9]{0,4}$/', $sectionLetter)) {
        throw new Exception('Section must start with a letter, e.g. A, B, C.');
    }
    // Stored as year level + letter, e.g. year 2 + "A" = "2A".
    $section = $yearLevel . $sectionLetter;

    $check = $pdo->prepare('
        SELECT p.duration_years FROM programs p
        JOIN departments d ON d.department_id = p.department_id
        WHERE p.program_id = ? AND p.department_id = ? AND d.institution_id = ? AND p.status = "active"
    ');
    $check->execute([$programId, $departmentId, $institutionId]);
    $program = $check->fetch();
    if (!$program) throw new Exception('Invalid institution/department/program combination.');

    $maxYear = program_max_year($program['duration_years']);
    if ($yearLevel < 1 || $yearLevel > $maxYear) {
        throw new Exception("Year level must be between 1 and $maxYear for this program.");
    }

    return [$institutionId, $departmentId, $programId, $yearLevel, $section, $studentType];
}

try {
    if ($action === 'create') {
        $studentNumber = clean($_POST['student_number'] ?? '');
        $fullName      = clean($_POST['full_name'] ?? '');
        $email         = clean($_POST['email'] ?? '');
        $contact       = clean($_POST['contact_number'] ?? '');
        $username      = clean($_POST['username'] ?? '');
        $password      = $_POST['password'] ?? '';
        $status        = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($studentNumber === '' || $fullName === '' || $email === '' || $username === '' || $password === '') {
            throw new Exception('Please fill in all required fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please provide a valid email address.');
        }
        [$institutionId, $departmentId, $programId, $yearLevel, $section, $studentType] = read_academic_fields($pdo);

        $pdo->beginTransaction();

        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
        $check->execute([$username, $email]);
        if ($check->fetchColumn() > 0) throw new Exception('Username or email already in use.');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ins = $pdo->prepare('INSERT INTO users (username, password, role, email, status) VALUES (?, ?, "student", ?, ?)');
        $ins->execute([$username, $hash, $email, $status]);
        $userId = $pdo->lastInsertId();

        $ins2 = $pdo->prepare('
            INSERT INTO students (user_id, student_number, full_name, program_id, year_level, contact_number, institution_id, department_id, section, student_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $ins2->execute([$userId, $studentNumber, $fullName, $programId, $yearLevel, $contact, $institutionId, $departmentId, $section, $studentType]);

        create_notification($pdo, $userId, 'Welcome!', "Your student account has been created. Username: $username");
        log_activity($pdo, $_SESSION['user_id'], "Added student: $fullName");

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Student added successfully.']);

    } elseif ($action === 'update') {
        $studentId     = (int) ($_POST['student_id'] ?? 0);
        $studentNumber = clean($_POST['student_number'] ?? '');
        $fullName      = clean($_POST['full_name'] ?? '');
        $email         = clean($_POST['email'] ?? '');
        $contact       = clean($_POST['contact_number'] ?? '');
        $username      = clean($_POST['username'] ?? '');
        $password      = $_POST['password'] ?? '';
        $status        = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (!$studentId || $studentNumber === '' || $fullName === '' || $email === '' || $username === '') {
            throw new Exception('Please fill in all required fields.');
        }
        [$institutionId, $departmentId, $programId, $yearLevel, $section, $studentType] = read_academic_fields($pdo);

        $find = $pdo->prepare('SELECT user_id FROM students WHERE student_id = ?');
        $find->execute([$studentId]);
        $userId = $find->fetchColumn();
        if (!$userId) throw new Exception('Student not found.');

        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?');
        $check->execute([$username, $email, $userId]);
        if ($check->fetchColumn() > 0) throw new Exception('Username or email already used by another account.');

        $pdo->beginTransaction();

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $upd = $pdo->prepare('UPDATE users SET username=?, email=?, status=?, password=? WHERE user_id=?');
            $upd->execute([$username, $email, $status, $hash, $userId]);
        } else {
            $upd = $pdo->prepare('UPDATE users SET username=?, email=?, status=? WHERE user_id=?');
            $upd->execute([$username, $email, $status, $userId]);
        }

        $upd2 = $pdo->prepare('
            UPDATE students SET student_number=?, full_name=?, program_id=?, year_level=?, contact_number=?,
                institution_id=?, department_id=?, section=?, student_type=?
            WHERE student_id=?
        ');
        $upd2->execute([$studentNumber, $fullName, $programId, $yearLevel, $contact, $institutionId, $departmentId, $section, $studentType, $studentId]);

        log_activity($pdo, $_SESSION['user_id'], "Updated student: $fullName");
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);

    } elseif ($action === 'delete') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if (!$studentId) throw new Exception('Invalid student.');

        $find = $pdo->prepare('SELECT user_id, full_name FROM students WHERE student_id = ?');
        $find->execute([$studentId]);
        $row = $find->fetch();
        if (!$row) throw new Exception('Student not found.');

        // Deleting the user cascades to students, enrollments, attendance_records via FK
        $del = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
        $del->execute([$row['user_id']]);

        log_activity($pdo, $_SESSION['user_id'], "Deleted student: {$row['full_name']}");
        echo json_encode(['success' => true, 'message' => 'Student deleted successfully.']);

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
