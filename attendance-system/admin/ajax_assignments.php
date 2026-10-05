<?php
/**
 * admin/ajax_assignments.php — create / update / delete teacher_subjects
 * (class assignments) together with their recurring weekly schedule
 * (class_schedules). The institution/department/program cascade shown
 * to the admin is purely a UX convenience — this endpoint independently
 * re-verifies that the submitted program actually belongs to the
 * submitted department, which belongs to the submitted institution,
 * before ever writing to the database. Never trust the browser.
 *
 * One assignment = one class for the whole term. Its weekly slots say
 * when it meets; attendance sessions are opened per meeting from those
 * slots, so the admin never creates an assignment per meeting.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

/**
 * Parse + validate the recurring schedule: the checked weekdays ("days" =
 * comma-separated ISO numbers, 1 = Monday ... 7 = Sunday) sharing one start
 * and end time. Returns one weekly slot per checked day.
 */
function validate_schedule_input() {
    $days = array_values(array_unique(array_map('intval', array_filter(explode(',', (string) ($_POST['days'] ?? ''))))));
    sort($days);
    if (!$days) {
        throw new Exception('Select at least one day for the recurring schedule.');
    }
    foreach ($days as $d) {
        if (!isset(SCHEDULE_DAYS[$d])) throw new Exception('Invalid schedule day.');
    }
    $start = normalize_time($_POST['start_time'] ?? '');
    $end = normalize_time($_POST['end_time'] ?? '');
    if (!$start || !$end) {
        throw new Exception('Enter the class start time and end time.');
    }
    if ($end <= $start) {
        throw new Exception('End time must be after start time.');
    }
    return array_map(fn($d) => ['day_of_week' => $d, 'start_time' => $start, 'end_time' => $end], $days);
}

function validate_assignment_input($pdo) {
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $labId     = (int) ($_POST['lab_id'] ?? 0);
    $section   = clean($_POST['section'] ?? '');
    // Enabled/Disabled switch for the assignment itself (stored as teacher_subjects.status).
    // Whether a class is in session is never set here; it is computed from the schedule.
    $status    = ($_POST['enabled'] ?? '1') === '1' ? 'active' : 'inactive';
    $institutionId = (int) ($_POST['institution_id'] ?? 0);
    $departmentId  = (int) ($_POST['department_id'] ?? 0);
    $programId = (int) ($_POST['program_id'] ?? 0);
    $yearLevel = ($_POST['year_level'] ?? '') !== '' ? (int) $_POST['year_level'] : null;
    $maxStudents = (int) ($_POST['max_students'] ?? 40);

    if (!$teacherId || !$subjectId || !$labId || $section === ''
        || !$institutionId || !$departmentId || !$programId || !$yearLevel) {
        throw new Exception('Please fill in all required fields.');
    }
    if ($maxStudents < 1 || $maxStudents > 200) {
        throw new Exception('Max students must be between 1 and 200.');
    }

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

    return [
        'teacher_id' => $teacherId, 'subject_id' => $subjectId, 'lab_id' => $labId, 'section' => $section,
        'status' => $status, 'institution_id' => $institutionId, 'department_id' => $departmentId,
        'program_id' => $programId, 'year_level' => $yearLevel, 'max_students' => $maxStudents,
        'schedules' => validate_schedule_input(),
    ];
}

/**
 * Reject a duplicate class (same subject for the same program/year/section)
 * and, for active classes, any weekly slot that double-books the teacher
 * or the laboratory against another active class.
 */
function check_assignment_conflicts(PDO $pdo, array $a, $excludeId = 0) {
    // On edit, only check for duplicates when the class identity changes, so
    // older per-meeting duplicate rows can still be edited or deactivated.
    $identityChanged = true;
    if ($excludeId) {
        $cur = $pdo->prepare('SELECT subject_id, program_id, year_level, section FROM teacher_subjects WHERE teacher_subject_id = ?');
        $cur->execute([$excludeId]);
        $cur = $cur->fetch();
        $identityChanged = !$cur || (int) $cur['subject_id'] !== $a['subject_id'] || (int) $cur['program_id'] !== $a['program_id']
            || (int) $cur['year_level'] !== $a['year_level'] || strcasecmp($cur['section'], $a['section']) !== 0;
    }
    $dup = $pdo->prepare('
        SELECT COUNT(*) FROM teacher_subjects
        WHERE subject_id = ? AND program_id = ? AND year_level = ? AND LOWER(section) = LOWER(?) AND teacher_subject_id <> ?
    ');
    $dup->execute([$a['subject_id'], $a['program_id'], $a['year_level'], $a['section'], $excludeId]);
    if ($identityChanged && $dup->fetchColumn() > 0) {
        throw new Exception('This subject is already assigned to that program, year level and section. Edit the existing assignment (and its schedule) instead of adding another one.');
    }

    if ($a['status'] !== 'active') return;

    $others = $pdo->prepare('
        SELECT ts.teacher_subject_id, ts.teacher_id, ts.lab_id, ts.section, sub.subject_code, t.full_name AS teacher_name, lab.lab_name
        FROM teacher_subjects ts
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        JOIN teachers t ON t.teacher_id = ts.teacher_id
        JOIN laboratories lab ON lab.lab_id = ts.lab_id
        WHERE ts.status = "active" AND ts.teacher_subject_id <> ? AND (ts.teacher_id = ? OR ts.lab_id = ?)
    ');
    $others->execute([$excludeId, $a['teacher_id'], $a['lab_id']]);
    $others = $others->fetchAll();
    $rules = get_schedule_rules($pdo, array_column($others, 'teacher_subject_id'));
    $today = schedule_now()->format('Y-m-d');

    foreach ($others as $o) {
        foreach ($rules[(int) $o['teacher_subject_id']] as $theirs) {
            if (!empty($theirs['effective_end_date']) && $theirs['effective_end_date'] < $today) continue;
            foreach ($a['schedules'] as $mine) {
                if (!schedule_rules_conflict($mine, $theirs)) continue;
                $when = SCHEDULE_DAYS[$mine['day_of_week']] . ' ' . format_time_range($theirs['start_time'], $theirs['end_time']);
                $what = $o['subject_code'] . ' (' . $o['section'] . ')';
                if ((int) $o['teacher_id'] === $a['teacher_id']) {
                    throw new Exception("Schedule conflict: {$o['teacher_name']} already teaches $what on $when.");
                }
                throw new Exception("Schedule conflict: {$o['lab_name']} is already used by $what on $when.");
            }
        }
    }
}

/**
 * Replace a class's weekly rules (call inside the save transaction).
 * If all of the old rules shared one effective
 * date range (e.g. a semester), the new rules keep it.
 */
function save_schedules(PDO $pdo, $classId, array $slots) {
    $old = get_schedule_rules($pdo, [$classId])[(int) $classId] ?? [];
    $ranges = array_unique(array_map(fn($r) => ($r['effective_start_date'] ?? '') . '|' . ($r['effective_end_date'] ?? ''), $old));
    [$effStart, $effEnd] = count($ranges) === 1 ? explode('|', reset($ranges)) : ['', ''];

    $pdo->prepare('DELETE FROM class_schedules WHERE teacher_subject_id = ?')->execute([$classId]);
    $ins = $pdo->prepare('INSERT INTO class_schedules (teacher_subject_id, day_of_week, start_time, end_time, effective_start_date, effective_end_date)
                          VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($slots as $s) {
        $ins->execute([$classId, $s['day_of_week'], $s['start_time'], $s['end_time'], $effStart ?: null, $effEnd ?: null]);
    }
}

try {
    if ($action === 'create') {
        $a = validate_assignment_input($pdo);
        check_assignment_conflicts($pdo, $a);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('
            INSERT INTO teacher_subjects
                (teacher_id, subject_id, lab_id, section, status, institution_id, department_id, program_id, year_level, max_students)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING teacher_subject_id
        ');
        $stmt->execute([$a['teacher_id'], $a['subject_id'], $a['lab_id'], $a['section'], $a['status'],
            $a['institution_id'], $a['department_id'], $a['program_id'], $a['year_level'], $a['max_students']]);
        $id = (int) $stmt->fetchColumn();
        save_schedules($pdo, $id, $a['schedules']);
        $pdo->commit();

        log_activity($pdo, $_SESSION['user_id'], "Created class assignment ID $id (section {$a['section']}, " . format_schedule_label($a['schedules']) . ')');
        echo json_encode(['success' => true, 'message' => 'Class assignment created successfully.']);

    } elseif ($action === 'update') {
        $id = (int) ($_POST['teacher_subject_id'] ?? 0);
        if (!$id) throw new Exception('Invalid assignment.');
        $a = validate_assignment_input($pdo);

        // Don't let max_students drop below the number of students already enrolled
        $enrolledCountStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE teacher_subject_id = ? AND status = "enrolled"');
        $enrolledCountStmt->execute([$id]);
        $enrolledCount = (int) $enrolledCountStmt->fetchColumn();
        if ($a['max_students'] < $enrolledCount) {
            throw new Exception("Max students cannot be lower than the number of students already enrolled ($enrolledCount).");
        }
        check_assignment_conflicts($pdo, $a, $id);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('
            UPDATE teacher_subjects SET
                teacher_id=?, subject_id=?, lab_id=?, section=?, status=?,
                institution_id=?, department_id=?, program_id=?, year_level=?, max_students=?
            WHERE teacher_subject_id=?
        ');
        $stmt->execute([$a['teacher_id'], $a['subject_id'], $a['lab_id'], $a['section'], $a['status'],
            $a['institution_id'], $a['department_id'], $a['program_id'], $a['year_level'], $a['max_students'], $id]);
        if ($stmt->rowCount() === 0) throw new Exception('Assignment not found.');
        save_schedules($pdo, $id, $a['schedules']);
        $pdo->commit();

        log_activity($pdo, $_SESSION['user_id'], "Updated class assignment ID $id");
        echo json_encode(['success' => true, 'message' => 'Class assignment updated successfully.']);

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['teacher_subject_id'] ?? 0);
        if (!$id) throw new Exception('Invalid assignment.');

        $usage = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM enrollments WHERE teacher_subject_id = ? AND status = 'enrolled') AS enrolled,
                (SELECT COUNT(*) FROM attendance_sessions WHERE teacher_subject_id = ?) AS sessions
        ");
        $usage->execute([$id, $id]);
        $usage = $usage->fetch();
        $enrolled = (int) $usage['enrolled'];
        $sessions = (int) $usage['sessions'];
        if ($enrolled > 0 || $sessions > 0) {
            throw new Exception("This class has $enrolled enrolled student(s) and $sessions attendance session(s). Disable it instead of deleting it.");
        }

        $stmt = $pdo->prepare('DELETE FROM teacher_subjects WHERE teacher_subject_id = ?');
        $stmt->execute([$id]);
        log_activity($pdo, $_SESSION['user_id'], "Deleted class assignment ID $id");
        echo json_encode(['success' => true, 'message' => 'Class assignment deleted successfully.']);
    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $e instanceof PDOException ? 'Database error: ' . $e->getMessage() : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $message]);
}
