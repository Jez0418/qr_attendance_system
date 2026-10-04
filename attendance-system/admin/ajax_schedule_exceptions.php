<?php
/**
 * admin/ajax_schedule_exceptions.php
 * Changes ONE meeting date of a class (a schedule_exceptions row) without
 * touching its weekly schedule:
 *   cancel      reason required
 *   reschedule  new date + start/end time, optional lab and substitute teacher, optional reason
 *   restore     remove the exception, so the meeting goes back to its usual date/time
 *
 * A class has at most one exception per original date, so cancelling or
 * rescheduling an already-changed meeting replaces its exception.
 * Everything about "is this a meeting / what clashes" is read through
 * includes/schedule.php; never trust the browser.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    $classId = (int) ($_POST['teacher_subject_id'] ?? 0);
    $originalDate = schedule_date(clean($_POST['original_date'] ?? ''))->format('Y-m-d');

    $classStmt = $pdo->prepare('
        SELECT ts.teacher_subject_id, ts.teacher_id, ts.lab_id, ts.section, ts.status, sub.subject_code, sub.subject_name, t.user_id AS teacher_user_id
        FROM teacher_subjects ts
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        JOIN teachers t ON t.teacher_id = ts.teacher_id
        WHERE ts.teacher_subject_id = ?
    ');
    $classStmt->execute([$classId]);
    $class = $classStmt->fetch();
    if (!$class) throw new Exception('Class assignment not found.');

    // The date must be a real meeting under the class's weekly schedule.
    $rules = get_schedule_rules($pdo, [$classId])[$classId] ?? [];
    $meetings = schedule_rules_on_date($rules, $originalDate);
    if (!$meetings) throw new Exception('This class does not meet on ' . schedule_date($originalDate)->format('l, M d, Y') . '.');

    $existing = $pdo->prepare('SELECT * FROM schedule_exceptions WHERE teacher_subject_id = ? AND original_date = ?');
    $existing->execute([$classId, $originalDate]);
    $existing = $existing->fetch() ?: null;

    // Once attendance has been taken for this meeting (on its usual or moved date), it can't be changed.
    $sessionDates = array_unique(array_filter([$originalDate, $existing['new_date'] ?? null]));
    $in = implode(',', array_fill(0, count($sessionDates), '?'));
    $taken = $pdo->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE teacher_subject_id = ? AND session_date IN ($in)");
    $taken->execute(array_merge([$classId], $sessionDates));
    if ($taken->fetchColumn() > 0) {
        throw new Exception('Attendance has already been taken for this meeting, so it can no longer be changed.');
    }

    $label = $class['subject_code'] . ' (' . $class['section'] . ')';
    $origLabel = schedule_date($originalDate)->format('D, M d') . ' ' . format_time_range($meetings[0]['start_time'], $meetings[0]['end_time']);

    if ($action === 'cancel') {
        $reason = clean($_POST['reason'] ?? '');
        if ($reason === '') throw new Exception('Please give a reason for the cancellation.');
        if (mb_strlen($reason) > 500) throw new Exception('Reason is too long (max 500 characters).');

        $stmt = $pdo->prepare("
            INSERT INTO schedule_exceptions (teacher_subject_id, original_date, exception_type, reason)
            VALUES (?, ?, 'CANCELLED', ?)
            ON CONFLICT (teacher_subject_id, original_date) DO UPDATE SET
                exception_type = 'CANCELLED', new_date = NULL, new_start_time = NULL, new_end_time = NULL,
                new_lab_id = NULL, new_teacher_id = NULL, reason = EXCLUDED.reason, created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$classId, $originalDate, $reason]);

        // Also tell a substitute teacher who had been assigned to this meeting
        $substituteUserId = null;
        if (!empty($existing['new_teacher_id'])) {
            $sub = $pdo->prepare('SELECT user_id FROM teachers WHERE teacher_id = ?');
            $sub->execute([$existing['new_teacher_id']]);
            $substituteUserId = $sub->fetchColumn() ?: null;
        }
        $message = "$label on $origLabel has been cancelled. Reason: $reason";
        notify_class($pdo, $classId, [$class['teacher_user_id'], $substituteUserId], 'Class Cancelled', $message);
        log_activity($pdo, $_SESSION['user_id'], "Cancelled class #$classId meeting on $originalDate");
        echo json_encode(['success' => true, 'message' => 'Meeting cancelled.']);

    } elseif ($action === 'reschedule') {
        $newDate = schedule_date(clean($_POST['new_date'] ?? ''))->format('Y-m-d');
        $newStart = schedule_valid_time($_POST['new_start_time'] ?? '');
        $newEnd = schedule_valid_time($_POST['new_end_time'] ?? '');
        if (!$newStart || !$newEnd) throw new Exception('Enter the new start and end time.');
        if ($newEnd <= $newStart) throw new Exception('End time must be after start time.');
        if (new DateTimeImmutable("$newDate $newStart", schedule_tz()) <= schedule_now()) {
            throw new Exception('The new date and time must be in the future.');
        }
        $reason = clean($_POST['reason'] ?? '');
        if (mb_strlen($reason) > 500) throw new Exception('Reason is too long (max 500 characters).');

        // Optional lab / substitute teacher; storing the usual one as NULL keeps "unchanged" explicit.
        $labId = (int) ($_POST['new_lab_id'] ?? 0) ?: (int) $class['lab_id'];
        $teacherId = (int) ($_POST['new_teacher_id'] ?? 0) ?: (int) $class['teacher_id'];
        $lab = $pdo->prepare("SELECT lab_name FROM laboratories WHERE lab_id = ? AND status = 'active'");
        $lab->execute([$labId]);
        $labName = $lab->fetchColumn();
        if ($labName === false) throw new Exception('Select an active laboratory.');
        $teacher = $pdo->prepare('SELECT full_name, user_id FROM teachers WHERE teacher_id = ?');
        $teacher->execute([$teacherId]);
        $teacher = $teacher->fetch();
        if (!$teacher) throw new Exception('Select a valid teacher.');

        $conflict = find_schedule_conflict($pdo, $newDate, $newStart, $newEnd, $teacherId, $labId, $classId, $originalDate);
        if ($conflict) {
            $who = $conflict['conflict'] === 'teacher' ? $conflict['teacher_name'] . ' is already teaching' : $conflict['lab_name'] . ' is already used by';
            throw new Exception("Schedule conflict: $who {$conflict['subject_code']} ({$conflict['section']}) "
                . format_time_range($conflict['start_time'], $conflict['end_time']) . ' on that day.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO schedule_exceptions
                (teacher_subject_id, original_date, exception_type, new_date, new_start_time, new_end_time, new_lab_id, new_teacher_id, reason)
            VALUES (?, ?, 'RESCHEDULED', ?, ?, ?, ?, ?, ?)
            ON CONFLICT (teacher_subject_id, original_date) DO UPDATE SET
                exception_type = 'RESCHEDULED', new_date = EXCLUDED.new_date, new_start_time = EXCLUDED.new_start_time,
                new_end_time = EXCLUDED.new_end_time, new_lab_id = EXCLUDED.new_lab_id, new_teacher_id = EXCLUDED.new_teacher_id,
                reason = EXCLUDED.reason, created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $classId, $originalDate, $newDate, $newStart, $newEnd,
            $labId !== (int) $class['lab_id'] ? $labId : null,
            $teacherId !== (int) $class['teacher_id'] ? $teacherId : null,
            $reason !== '' ? $reason : null,
        ]);

        $message = "$label on $origLabel has been moved to " . schedule_date($newDate)->format('D, M d') . ' '
            . format_time_range($newStart, $newEnd) . " in $labName" . ($teacherId !== (int) $class['teacher_id'] ? " with {$teacher['full_name']}" : '') . '.'
            . ($reason !== '' ? " Reason: $reason" : '');
        notify_class($pdo, $classId, [$class['teacher_user_id'], $teacher['user_id']], 'Class Rescheduled', $message);
        log_activity($pdo, $_SESSION['user_id'], "Rescheduled class #$classId meeting $originalDate to $newDate $newStart-$newEnd");
        echo json_encode(['success' => true, 'message' => 'Meeting rescheduled.']);

    } elseif ($action === 'restore') {
        if (!$existing) throw new Exception('This meeting has no change to undo.');
        $pdo->prepare('DELETE FROM schedule_exceptions WHERE exception_id = ?')->execute([$existing['exception_id']]);
        notify_class($pdo, $classId, [$class['teacher_user_id']], 'Class Restored',
            "$label is back on its usual schedule: $origLabel.");
        log_activity($pdo, $_SESSION['user_id'], "Restored class #$classId meeting on $originalDate");
        echo json_encode(['success' => true, 'message' => 'Meeting restored to its usual schedule.']);

    } else {
        throw new Exception('Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid date.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/** 'H:i' or 'H:i:s' from a form, as 'H:i:s', or null if it isn't a valid time. */
function schedule_valid_time($time) {
    $time = trim((string) $time);
    foreach (['H:i', 'H:i:s'] as $fmt) {
        $t = DateTimeImmutable::createFromFormat('!' . $fmt, $time);
        if ($t && $t->format($fmt) === $time) return $t->format('H:i:s');
    }
    return null;
}

/** Notify the class's enrolled students plus the given teacher user ids. */
function notify_class(PDO $pdo, int $classId, array $teacherUserIds, string $title, string $message) {
    $students = $pdo->prepare("
        SELECT s.user_id FROM enrollments e JOIN students s ON s.student_id = e.student_id
        WHERE e.teacher_subject_id = ? AND e.status = 'enrolled'
    ");
    $students->execute([$classId]);
    $userIds = array_merge($students->fetchAll(PDO::FETCH_COLUMN), $teacherUserIds);
    foreach (array_unique(array_filter(array_map('intval', $userIds))) as $uid) {
        create_notification($pdo, $uid, $title, $message);
    }
}
