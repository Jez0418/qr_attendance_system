<?php
/**
 * includes/admin_delete.php — the admin deleting a class assignment, teacher, student, subject or laboratory.
 *
 * One rule for all five: only a DISABLED item can be deleted (class: Disabled, the others: Inactive).
 * A student or teacher is ARCHIVED, not removed (`users.deleted_at`, database/supabase_archive_users.sql): the account is
 * closed and hidden from the lists, but their classes, sessions and attendance records stay in the history.
 * A class, subject or laboratory is really deleted; the foreign keys are ON DELETE CASCADE, so that removes everything
 * under the item: a class takes its enrollments, sessions and attendance records with it; a subject or laboratory
 * takes its classes (and those) with it. Because that is permanent, the page first asks `admin_delete_preview()` what would go and
 * shows those numbers in the confirmation.
 *
 * A subject, laboratory or teacher is also refused while one of its classes is still ENABLED: that class
 * would silently disappear while running. Disable or delete the class first.
 *
 * Pure rules (`admin_delete_blocker()`, `admin_delete_summary()`) are separate from the queries so
 * tests/admin_delete_test.php can check them without a database.
 */

/**
 * Pure: why a laboratory cannot be set to Inactive, or '' when it can. A lab with enabled classes would keep
 * generating meetings, sessions and absences that students cannot scan into (scans of an inactive lab are
 * refused). Counts only, never names: messages go into toasts (innerHTML).
 */
function lab_deactivation_blocker(int $enabledClasses, int $movedInMeetings): string {
    if ($enabledClasses <= 0 && $movedInMeetings <= 0) return '';
    $parts = [];
    if ($enabledClasses > 0) $parts[] = $enabledClasses . ' enabled class' . ($enabledClasses === 1 ? '' : 'es') . ' assigned to it';
    if ($movedInMeetings > 0) $parts[] = $movedInMeetings . ' upcoming meeting' . ($movedInMeetings === 1 ? '' : 's') . ' rescheduled into it';
    return 'This laboratory cannot be set to Inactive: it still has ' . implode(' and ', $parts)
        . '. Disable those classes or move them to another laboratory first.';
}

/** Enabled classes assigned to the lab, and upcoming rescheduled meetings of enabled classes moved into it. [classes, moved] */
function lab_deactivation_counts(PDO $pdo, int $labId): array {
    $c = $pdo->prepare("SELECT COUNT(*) FROM teacher_subjects WHERE lab_id = ? AND status = 'active'");
    $c->execute([$labId]);
    $m = $pdo->prepare("
        SELECT COUNT(*) FROM schedule_exceptions x
        JOIN teacher_subjects ts ON ts.teacher_subject_id = x.teacher_subject_id
        WHERE x.new_lab_id = ? AND ts.status = 'active' AND ts.lab_id <> ?
          AND x.exception_type = 'RESCHEDULED' AND COALESCE(x.new_date, x.original_date) >= CURRENT_DATE");
    $m->execute([$labId, $labId]);
    return [(int) $c->fetchColumn(), (int) $m->fetchColumn()];
}

/** Kinds that are archived (account hidden, history kept) rather than removed from the database. */
const ADMIN_ARCHIVE_KINDS = ['teacher', 'student'];

/** kind => [SQL column that finds the item's classes, noun, "on" word, "off" word] */
const ADMIN_DELETE_KINDS = [
    'class'   => ['ts.teacher_subject_id', 'class assignment', 'enabled', 'disabled'],
    'teacher' => ['ts.teacher_id',         'teacher',          'active',  'inactive'],
    'subject' => ['ts.subject_id',         'subject',          'active',  'inactive'],
    'lab'     => ['ts.lab_id',             'laboratory',       'active',  'inactive'],
    // A student has no classes of their own: impact is counted from enrollments (see admin_delete_impact()).
    'student' => ['e.student_id',          'student',          'active',  'inactive'],
];

/** The item's status and a display name, or null when it no longer exists. ['status', 'name', 'user_id'] */
function admin_delete_target(PDO $pdo, string $kind, int $id): ?array {
    $sql = match ($kind) {
        'class'   => 'SELECT ts.status, sub.subject_code AS label, ts.section AS extra, 0 AS user_id
                      FROM teacher_subjects ts JOIN subjects sub ON sub.subject_id = ts.subject_id
                      WHERE ts.teacher_subject_id = ?',
        'teacher' => "SELECT u.status, t.full_name AS label, '' AS extra, t.user_id
                      FROM teachers t JOIN users u ON u.user_id = t.user_id WHERE t.teacher_id = ?",
        'subject' => "SELECT status, subject_name AS label, '' AS extra, 0 AS user_id FROM subjects WHERE subject_id = ?",
        'lab'     => "SELECT status, lab_name AS label, '' AS extra, 0 AS user_id FROM laboratories WHERE lab_id = ?",
        'student' => "SELECT u.status, st.full_name AS label, '' AS extra, st.user_id
                      FROM students st JOIN users u ON u.user_id = st.user_id WHERE st.student_id = ?",
    };
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $name = $kind === 'class' ? $row['label'] . ' (' . $row['extra'] . ')' : (string) $row['label'];
    return ['status' => (string) $row['status'], 'name' => $name, 'user_id' => (int) $row['user_id']];
}

/** What deleting the item would remove: classes (and how many are enabled), enrollments, sessions, attendance records. */
function admin_delete_impact(PDO $pdo, string $kind, int $id): array {
    if ($kind === 'student') {
        $n = function (string $table) use ($pdo, $id): int {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE student_id = ?");
            $stmt->execute([$id]);
            return (int) $stmt->fetchColumn();
        };
        return ['classes' => 0, 'enabled_classes' => 0, 'enrollments' => $n('enrollments'),
                'sessions' => 0, 'records' => $n('attendance_records')];
    }
    $col = ADMIN_DELETE_KINDS[$kind][0];   // from the constant above, never from the request
    $one = function (string $sql) use ($pdo, $id) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_NUM);
    };
    [$classes, $enabled] = $one("SELECT COUNT(*), COUNT(*) FILTER (WHERE ts.status = 'active') FROM teacher_subjects ts WHERE $col = ?");
    [$enrollments] = $one("SELECT COUNT(*) FROM enrollments e JOIN teacher_subjects ts ON ts.teacher_subject_id = e.teacher_subject_id WHERE $col = ?");
    [$sessions] = $one("SELECT COUNT(*) FROM attendance_sessions s JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id WHERE $col = ?");
    [$records] = $one("SELECT COUNT(*) FROM attendance_records ar
                       JOIN attendance_sessions s ON s.session_id = ar.session_id
                       JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id WHERE $col = ?");
    return ['classes' => (int) $classes, 'enabled_classes' => (int) $enabled,
            'enrollments' => (int) $enrollments, 'sessions' => (int) $sessions, 'records' => (int) $records];
}

/** Why the item can't be deleted right now (a sentence without names, safe for a toast), or null when it can. */
function admin_delete_blocker(string $kind, string $status, array $impact): ?string {
    [, $noun, $onWord, $offWord] = ADMIN_DELETE_KINDS[$kind];
    if ($status === 'active') {
        return "This $noun is $onWord. Set it to " . ucfirst($offWord) . " first, then delete it.";
    }
    if ($kind !== 'class' && $impact['enabled_classes'] > 0) {
        $n = $impact['enabled_classes'];
        return "This $noun still has $n enabled class assignment" . ($n === 1 ? '' : 's')
            . '. Disable or delete ' . ($n === 1 ? 'it' : 'them') . ' first.';
    }
    return null;
}

/** "3 classes", "1 enrollment" ... */
function admin_delete_count(int $n, string $singular, string $plural = ''): string {
    return $n . ' ' . ($n === 1 ? $singular : ($plural !== '' ? $plural : $singular . 's'));
}

/** The numbers shown in the confirmation and the activity log, e.g. "2 classes, 28 enrollments, 14 sessions, 392 attendance records". */
function admin_delete_summary(string $kind, array $impact): string {
    $parts = [];
    if ($kind !== 'class' && $kind !== 'student') $parts[] = admin_delete_count($impact['classes'], 'class assignment');
    $parts[] = admin_delete_count($impact['enrollments'], 'enrollment');
    if ($kind !== 'student') $parts[] = admin_delete_count($impact['sessions'], 'attendance session');
    $parts[] = admin_delete_count($impact['records'], 'attendance record');
    return implode(', ', $parts);
}

/**
 * First step of a delete (POST action=delete_preview): throws the blocker as an Exception, or returns what the
 * confirmation dialog should say.
 */
function admin_delete_preview(PDO $pdo, string $kind, int $id): array {
    $target = admin_delete_target($pdo, $kind, $id);
    if (!$target) throw new Exception('That ' . ADMIN_DELETE_KINDS[$kind][1] . ' no longer exists. Reload the page.');
    $impact = admin_delete_impact($pdo, $kind, $id);
    if ($blocker = admin_delete_blocker($kind, $target['status'], $impact)) throw new Exception($blocker);
    $noun = ADMIN_DELETE_KINDS[$kind][1];
    if (in_array($kind, ADMIN_ARCHIVE_KINDS, true)) {
        return ['confirm' => "Delete the $noun \"{$target['name']}\"?\n\nThe account is closed and removed from the lists. "
            . "Their classes, attendance sessions and attendance records stay in the history.\n\nThis cannot be undone."];
    }
    return ['confirm' => "Permanently delete the $noun \"{$target['name']}\"?\n\nThis also deletes: "
        . admin_delete_summary($kind, $impact) . ".\n\nThis cannot be undone."];
}

/**
 * Archives a student or teacher account: closed (inactive), hidden (deleted_at) and its username/email released so they
 * can be used again. A student's enrollments are dropped (no more Absent marks) and pending requests cancelled; every
 * attendance record, session and class is left untouched. Call inside the caller's transaction.
 */
function admin_archive_user(PDO $pdo, int $userId, int $studentId = 0): void {
    $row = $pdo->prepare('SELECT username, email FROM users WHERE user_id = ?');
    $row->execute([$userId]);
    $u = $row->fetch(PDO::FETCH_ASSOC);
    if (!$u) return;
    $tag = 'deleted-' . $userId . '-';
    $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive', username = ?, email = ? WHERE user_id = ?")
        ->execute([substr($tag . $u['username'], 0, 50), substr($tag . $u['email'], 0, 100), $userId]);
    if ($studentId > 0) {
        $pdo->prepare("UPDATE enrollments SET status = 'dropped' WHERE student_id = ? AND status = 'enrolled'")->execute([$studentId]);
        $pdo->prepare("UPDATE enrollment_requests SET status = 'cancelled' WHERE student_id = ? AND status = 'pending'")->execute([$studentId]);
    }
}

/**
 * Second step (POST action=delete): re-checks everything inside a transaction, then deletes.
 * Returns the message to show. Throws an Exception (with the blocker) when it must not be deleted.
 */
function admin_delete_perform(PDO $pdo, string $kind, int $id, int $adminUserId): string {
    $noun = ADMIN_DELETE_KINDS[$kind][1];
    $pdo->beginTransaction();
    try {
        $target = admin_delete_target($pdo, $kind, $id);
        if (!$target) throw new Exception('That ' . $noun . ' no longer exists. Reload the page.');
        $impact = admin_delete_impact($pdo, $kind, $id);
        if ($blocker = admin_delete_blocker($kind, $target['status'], $impact)) throw new Exception($blocker);

        if (in_array($kind, ADMIN_ARCHIVE_KINDS, true)) {
            admin_archive_user($pdo, $target['user_id'], $kind === 'student' ? $id : 0);
            log_activity($pdo, $adminUserId, "Deleted $noun: {$target['name']} (archived, history kept: " . admin_delete_summary($kind, $impact) . ')');
        } else {
            [$table, $column, $value] = match ($kind) {
                'class'   => ['teacher_subjects', 'teacher_subject_id', $id],
                'subject' => ['subjects', 'subject_id', $id],
                'lab'     => ['laboratories', 'lab_id', $id],
            };
            $pdo->prepare("DELETE FROM $table WHERE $column = ?")->execute([$value]);
            log_activity($pdo, $adminUserId, "Deleted $noun: {$target['name']} (" . admin_delete_summary($kind, $impact) . ')');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // A deleted teacher's or student's open logins must end (sessions are DB rows; require_login() doesn't re-check the account).
    if ($kind === 'teacher' || $kind === 'student') destroy_user_sessions($pdo, $target['user_id']);
    return ucfirst($noun) . ' deleted.';
}
