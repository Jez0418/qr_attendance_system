<?php
/**
 * ------------------------------------------------------------
 * includes/attendance_stats.php
 * Attendance rate and absence-limit warnings, per student per class.
 *
 * A "meeting" here is a session the student has a record for: Present, Late (both count as
 * attended) or Absent (added by includes/absences.php when a session ends). Meetings that never
 * had a session are not counted, the same rule the absence marking uses.
 *
 * Two limits, both in Admin > Settings (database/supabase_attendance_insights.sql):
 *   absence_limit        absences in one class that put a student over the limit (default 3, 0 = off)
 *   min_attendance_rate  attendance % below which a student is flagged (default 80, 0 = off). It only
 *                        applies after ATTENDANCE_RATE_MIN_MEETINGS meetings, so one missed class in
 *                        week one does not read as "0% attendance".
 * attendance_standing() is pure (no database) so tests/attendance_stats_test.php can check it.
 * ------------------------------------------------------------
 */
const ATTENDANCE_RATE_MIN_MEETINGS = 5;

const STANDING_OK = 'ok';
const STANDING_WARNING = 'warning';   // one absence away from the limit
const STANDING_OVER = 'over';         // reached the absence limit, or below the minimum rate

/** The two limits from settings, clamped to sane ranges. */
function attendance_limits(PDO $pdo): array {
    return [
        'absence_limit' => max(0, min(50, get_setting_int($pdo, 'absence_limit', 3))),
        'min_rate'      => max(0, min(100, get_setting_int($pdo, 'min_attendance_rate', 80))),
    ];
}

/**
 * Standing of one student in one class.
 * Returns meetings, attended, absent, rate (0-100, or null with no meetings yet),
 * level (STANDING_*), label (short badge text, '' = show no badge) and badge (CSS class).
 */
function attendance_standing(int $attended, int $absent, array $limits): array {
    $meetings = $attended + $absent;
    $rate = $meetings > 0 ? (int) floor($attended * 100 / $meetings) : null;
    $limit = (int) $limits['absence_limit'];
    $minRate = (int) $limits['min_rate'];

    // "Good standing" only once there is enough history to mean it: 1 of 2 attended is 50%, but
    // it is not a verdict yet. Early on an OK student gets no badge (label '').
    $level = STANDING_OK;
    $label = $meetings >= ATTENDANCE_RATE_MIN_MEETINGS ? 'Good standing' : '';
    if ($limit > 0 && $absent >= $limit) {
        $level = STANDING_OVER;
        $label = 'Absence limit reached';
    } elseif ($minRate > 0 && $rate !== null && $meetings >= ATTENDANCE_RATE_MIN_MEETINGS && $rate < $minRate) {
        $level = STANDING_OVER;
        $label = "Below {$minRate}%";
    } elseif ($limit > 1 && $absent === $limit - 1) {
        $level = STANDING_WARNING;
        $label = '1 absence from limit';
    }
    return [
        'meetings' => $meetings, 'attended' => $attended, 'absent' => $absent, 'rate' => $rate,
        'level' => $level, 'label' => $label,
        'badge' => [STANDING_OK => 'badge-present', STANDING_WARNING => 'badge-late', STANDING_OVER => 'badge-absent'][$level],
    ];
}

/** "1 of 3 absences allowed" / "2 absences" (no limit set) — wording shared by pages and notifications. */
function absence_count_label(int $absent, array $limits): string {
    $limit = (int) $limits['absence_limit'];
    if ($limit > 0) return "$absent of $limit " . ($limit === 1 ? 'absence' : 'absences') . ' allowed';
    return "$absent " . ($absent === 1 ? 'absence' : 'absences');
}

/**
 * Every class the student is enrolled in, with counts and standing, worst first.
 * Rows: teacher_subject_id, subject_code, subject_name, section, late, standing.
 */
function student_class_standings(PDO $pdo, int $studentId, ?array $limits = null): array {
    $limits = $limits ?? attendance_limits($pdo);
    $stmt = $pdo->prepare("
        SELECT ts.teacher_subject_id, sub.subject_code, sub.subject_name, ts.section,
               COUNT(ar.record_id) FILTER (WHERE ar.status IN ('Present', 'Late')) AS attended,
               COUNT(ar.record_id) FILTER (WHERE ar.status = 'Late') AS late,
               COUNT(ar.record_id) FILTER (WHERE ar.status = 'Absent') AS absent
        FROM enrollments e
        JOIN teacher_subjects ts ON ts.teacher_subject_id = e.teacher_subject_id
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        LEFT JOIN attendance_sessions s ON s.teacher_subject_id = ts.teacher_subject_id
        LEFT JOIN attendance_records ar ON ar.session_id = s.session_id AND ar.student_id = e.student_id
        WHERE e.student_id = ? AND e.status = 'enrolled'
        GROUP BY ts.teacher_subject_id, sub.subject_code, sub.subject_name, ts.section
        ORDER BY sub.subject_code, ts.section
    ");
    $stmt->execute([$studentId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['late'] = (int) $r['late'];
        $r['standing'] = attendance_standing((int) $r['attended'], (int) $r['absent'], $limits);
        $rows[] = $r;
    }
    return sort_by_standing($rows);
}

/**
 * Per-student counts for one class (teacher roster). Keyed by student_id; students with no
 * records yet are absent from the map (treat as attended 0 / absent 0).
 */
function class_student_standings(PDO $pdo, int $teacherSubjectId, ?array $limits = null): array {
    $limits = $limits ?? attendance_limits($pdo);
    $stmt = $pdo->prepare("
        SELECT ar.student_id,
               COUNT(*) FILTER (WHERE ar.status IN ('Present', 'Late')) AS attended,
               COUNT(*) FILTER (WHERE ar.status = 'Absent') AS absent
        FROM attendance_records ar
        JOIN attendance_sessions s ON s.session_id = ar.session_id
        WHERE s.teacher_subject_id = ?
        GROUP BY ar.student_id
    ");
    $stmt->execute([$teacherSubjectId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['student_id']] = attendance_standing((int) $r['attended'], (int) $r['absent'], $limits);
    }
    return $out;
}

/**
 * Students in the teacher's classes who are at or near a limit (level warning/over), worst first.
 * Rows: teacher_subject_id, subject_code, subject_name, section, student_id, student_number, full_name, standing.
 */
function teacher_students_at_risk(PDO $pdo, int $teacherId, ?array $limits = null): array {
    $limits = $limits ?? attendance_limits($pdo);
    $stmt = $pdo->prepare("
        SELECT ts.teacher_subject_id, sub.subject_code, sub.subject_name, ts.section,
               st.student_id, st.student_number, st.full_name,
               COUNT(*) FILTER (WHERE ar.status IN ('Present', 'Late')) AS attended,
               COUNT(*) FILTER (WHERE ar.status = 'Absent') AS absent
        FROM teacher_subjects ts
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        JOIN enrollments e ON e.teacher_subject_id = ts.teacher_subject_id AND e.status = 'enrolled'
        JOIN students st ON st.student_id = e.student_id
        JOIN attendance_sessions s ON s.teacher_subject_id = ts.teacher_subject_id
        JOIN attendance_records ar ON ar.session_id = s.session_id AND ar.student_id = e.student_id
        WHERE ts.teacher_id = ?
        GROUP BY ts.teacher_subject_id, sub.subject_code, sub.subject_name, ts.section,
                 st.student_id, st.student_number, st.full_name
        HAVING COUNT(*) FILTER (WHERE ar.status = 'Absent') > 0
    ");
    $stmt->execute([$teacherId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['standing'] = attendance_standing((int) $r['attended'], (int) $r['absent'], $limits);
        if ($r['standing']['level'] !== STANDING_OK) $rows[] = $r;
    }
    return sort_by_standing($rows);
}

/** Over the limit first, then warnings, then the rest; within a level, most absences first. */
function sort_by_standing(array $rows): array {
    $rank = [STANDING_OVER => 0, STANDING_WARNING => 1, STANDING_OK => 2];
    usort($rows, fn($a, $b) => [$rank[$a['standing']['level']], -$a['standing']['absent'], $a['standing']['rate'] ?? 101]
                           <=> [$rank[$b['standing']['level']], -$b['standing']['absent'], $b['standing']['rate'] ?? 101]);
    return $rows;
}
