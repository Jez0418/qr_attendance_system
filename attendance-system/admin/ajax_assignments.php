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
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

/** Parse + validate the JSON list of weekly slots sent by the form. */
function validate_schedule_input() {
    $raw = json_decode($_POST['schedules'] ?? '[]', true);
    if (!is_array($raw) || !$raw) {
        throw new Exception('Add at least one meeting day to the recurring schedule.');
    }
    if (count($raw) > 14) {
        throw new Exception('A class can have at most 14 weekly meetings.');
    }
    $slots = [];
    foreach ($raw as $r) {
        $day = (int) ($r['day_of_week'] ?? 0);
        $start = normalize_time($r['start_time'] ?? '');
        $end = normalize_time($r['end_time'] ?? '');
        if (!isset(SCHEDULE_DAYS[$day]) || !$start || !$end) {
            throw new Exception('Every schedule row needs a day, a start time and an end time.');
        }
        if ($end <= $start) {
            throw new Exception('End time must be after start time (' . SCHEDULE_DAYS[$day] . ').');
        }
        $slot = ['day_of_week' => $day, 'start_time' => $start, 'end_time' => $end];
        foreach ($slots as $other) {
            if (schedule_slots_overlap($slot, $other)) {
                throw new Exception('Two schedule rows overlap on ' . SCHEDULE_DAYS[$day] . '.');
            }
        }
        $slots[] = $slot;
    }
    usort($slots, fn($a, $b) => [$a['day_of_week'], $a['start_time']] <=> [$b['day_of_week'], $b['start_time']]);
    return $slots;
}

function validate_assignment_input($pdo) {
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $labId     = (int) ($_POST['lab_id'] ?? 0);
    $section   = clean($_POST['section'] ?? '');
    $status    = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
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
    $others = attach_class_schedules($pdo, $others->fetchAll());

    foreach ($others as $o) {
        foreach ($o['schedules'] as $theirs) {
            foreach ($a['schedules'] as $mine) {
                if (!schedule_slots_overlap($mine, $theirs)) continue;
                $when = SCHEDULE_DAYS[$mine['day_of_week']] . ' ' . format_time($theirs['start_time']) . '–' . format_time($theirs['end_time']);
                $what = $o['subject_code'] . ' (' . $o['section'] . ')';
                if ((int) $o['teacher_id'] === $a['teacher_id']) {
                    throw new Exception("Schedule conflict: {$o['teacher_name']} already teaches $what on $when.");
                }
                throw new Exception("Schedule conflict: {$o['lab_name']} is already used by $what on $when.");
            }
        }
    }
}

/** Replace a class's weekly slots and refresh the summary columns on teacher_subjects. */
function save_schedules(PDO $pdo, $classId, array $slots) {
    $pdo->prepare('DELETE FROM class_schedules WHERE teacher_subject_id = ?')->execute([$classId]);
    $ins = $pdo->prepare('INSERT INTO class_schedules (teacher_subject_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)');
    foreach ($slots as $s) {
        $ins->execute([$classId, $s['day_of_week'], $s['start_time'], $s['end_time']]);
    }
    // Summary only (kept for older screens/exports); class_schedules is the source of truth.
    $days = implode('/', array_unique(array_map(fn($s) => substr(SCHEDULE_DAYS[$s['day_of_week']], 0, 3), $slots)));
    $pdo->prepare('UPDATE teacher_subjects SET schedule_day = ?, start_time = ?, end_time = ?, meeting_date = NULL WHERE teacher_subject_id = ?')
        ->execute([$days, $slots[0]['start_time'], $slots[0]['end_time'], $classId]);
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

        log_activity($pdo, $_SESSION['user_id'], "Created class assignment ID $id (section {$a['section']}, " . format_class_schedule($a['schedules']) . ')');
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
