<?php
/**
 * ------------------------------------------------------------
 * includes/schedule.php
 * Single source of truth for WHEN classes meet.
 *
 * Meetings ("occurrences") are never stored. They are generated from:
 *   class_schedules      weekly rules: day_of_week (1 = Mon ... 7 = Sun, ISO)
 *                        + start/end time, optionally limited to
 *                        effective_start_date..effective_end_date (inclusive)
 *   schedule_exceptions  one row per class per original meeting date:
 *                        CANCELLED   - the meeting still appears, flagged cancelled
 *                        RESCHEDULED - the meeting moves to new_date (NULL = same
 *                                      date) / new times / new lab / substitute
 *                                      teacher (each NULL = unchanged)
 *
 * Rules for exceptions:
 *   - An exception only applies if its original_date really is a meeting
 *     date under the class's weekly rules (stale exceptions are ignored).
 *   - It covers the whole day: if a class meets twice that day, CANCELLED
 *     cancels both, and RESCHEDULED moves both (or, when new times are
 *     given, replaces them with one meeting at the new times).
 *   - A RESCHEDULED meeting is returned where it lands: it is included
 *     when new_date is in the requested range, even if original_date isn't.
 *
 * All dates/times are Asia/Manila wall-clock time, whatever PHP's default
 * timezone is. An occurrence is ACTIVE from its start time up to, but not
 * including, its end time.
 * ------------------------------------------------------------
 */

const SCHEDULE_TIMEZONE = 'Asia/Manila';
const SCHEDULE_MAX_RANGE_DAYS = 366;

const OCCURRENCE_CANCELLED = 'CANCELLED';
const OCCURRENCE_UPCOMING  = 'UPCOMING';
const OCCURRENCE_ACTIVE    = 'ACTIVE';
const OCCURRENCE_EXPIRED   = 'EXPIRED';

function schedule_tz(): DateTimeZone {
    static $tz = null;
    return $tz ??= new DateTimeZone(SCHEDULE_TIMEZONE);
}

/** A date ('Y-m-d' or DateTimeInterface) as midnight in Manila. */
function schedule_date($date): DateTimeImmutable {
    if ($date instanceof DateTimeInterface) {
        $date = DateTimeImmutable::createFromInterface($date)->setTimezone(schedule_tz())->format('Y-m-d');
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, schedule_tz());
    if (!$d || $d->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException("Invalid date: $date (expected Y-m-d)");
    }
    return $d;
}

/** "Now" (null, a date-time string, or DateTimeInterface) as a Manila DateTimeImmutable. */
function schedule_now($now = null): DateTimeImmutable {
    if ($now === null) return new DateTimeImmutable('now', schedule_tz());
    if ($now instanceof DateTimeInterface) return DateTimeImmutable::createFromInterface($now)->setTimezone(schedule_tz());
    return new DateTimeImmutable((string) $now, schedule_tz());
}

/** 'H:i' or 'H:i:s' -> 'H:i:s' (so times compare correctly as strings). */
function schedule_time($time): string {
    $time = (string) $time;
    return strlen($time) === 5 ? $time . ':00' : substr($time, 0, 8);
}

/**
 * Every meeting between $from and $to (inclusive, 'Y-m-d'), sorted by start.
 *
 * $filters (all optional):
 *   teacher_subject_id  int|int[]  only these class assignments
 *   teacher_id          int        meetings taught by this teacher (incl. as a substitute)
 *   lab_id              int        meetings held in this lab (after any lab change)
 *   student_id          int        classes this student is enrolled in
 *   include_cancelled   bool       default true
 *   include_inactive    bool       include inactive assignments, default false
 *
 * Each occurrence: date, start_time, end_time, starts_at, ends_at, day_of_week,
 * lab_id/lab_name and teacher_id/teacher_name (after exceptions), the assignment's
 * subject/section/program fields, is_cancelled, is_rescheduled, exception_id,
 * exception_reason and original_date/start/end/lab/teacher.
 */
function get_occurrences(PDO $pdo, $from, $to, array $filters = []): array {
    $fromDate = schedule_date($from)->format('Y-m-d');
    $toDate = schedule_date($to)->format('Y-m-d');

    $where = [];
    $params = [];
    if (empty($filters['include_inactive'])) {
        $where[] = "ts.status = 'active'";
    }
    if (!empty($filters['teacher_subject_id'])) {
        $ids = array_map('intval', (array) $filters['teacher_subject_id']);
        $where[] = 'ts.teacher_subject_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    }
    if (!empty($filters['student_id'])) {
        $where[] = "EXISTS (SELECT 1 FROM enrollments e WHERE e.teacher_subject_id = ts.teacher_subject_id
                     AND e.student_id = ? AND e.status = 'enrolled')";
        $params[] = (int) $filters['student_id'];
    }
    // Teacher/lab: also pull classes where an exception moves the meeting to
    // them; the exact match is applied after exceptions in expand_occurrences().
    foreach (['teacher_id' => 'new_teacher_id', 'lab_id' => 'new_lab_id'] as $col => $newCol) {
        if (!empty($filters[$col])) {
            $where[] = "(ts.$col = ? OR EXISTS (SELECT 1 FROM schedule_exceptions x
                         WHERE x.teacher_subject_id = ts.teacher_subject_id AND x.$newCol = ?))";
            $params[] = (int) $filters[$col];
            $params[] = (int) $filters[$col];
        }
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT ts.teacher_subject_id, ts.teacher_id, ts.lab_id, ts.subject_id, ts.section, ts.year_level,
               ts.program_id, ts.institution_id, ts.department_id, ts.max_students, ts.status,
               sub.subject_code, sub.subject_name, t.full_name AS teacher_name, lab.lab_name, pr.program_code
        FROM teacher_subjects ts
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        JOIN teachers t ON t.teacher_id = ts.teacher_id
        JOIN laboratories lab ON lab.lab_id = ts.lab_id
        LEFT JOIN programs pr ON pr.program_id = ts.program_id
        $whereSql
    ");
    $stmt->execute($params);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$assignments) return [];

    $ids = array_map('intval', array_column($assignments, 'teacher_subject_id'));
    $in = implode(',', array_fill(0, count($ids), '?'));

    // All of their rules (an exception moved into range may come from a date outside it)
    $stmt = $pdo->prepare("SELECT * FROM class_schedules WHERE teacher_subject_id IN ($in) ORDER BY day_of_week, start_time");
    $stmt->execute($ids);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT x.*, nl.lab_name AS new_lab_name, nt.full_name AS new_teacher_name
        FROM schedule_exceptions x
        LEFT JOIN laboratories nl ON nl.lab_id = x.new_lab_id
        LEFT JOIN teachers nt ON nt.teacher_id = x.new_teacher_id
        WHERE x.teacher_subject_id IN ($in)
          AND (x.original_date BETWEEN ? AND ? OR x.new_date BETWEEN ? AND ?)
    ");
    $stmt->execute(array_merge($ids, [$fromDate, $toDate, $fromDate, $toDate]));
    $exceptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return expand_occurrences($assignments, $rules, $exceptions, $fromDate, $toDate, $filters);
}

/**
 * Pure expansion of weekly rules + exceptions into occurrences (no DB).
 * $assignments / $rules / $exceptions are rows shaped like the queries in
 * get_occurrences(). Filters as in get_occurrences().
 */
function expand_occurrences(array $assignments, array $rules, array $exceptions, $from, $to, array $filters = []): array {
    $fromD = schedule_date($from);
    $toD = schedule_date($to);
    if ($toD < $fromD) throw new InvalidArgumentException('The end date is before the start date.');
    if ($fromD->diff($toD)->days > SCHEDULE_MAX_RANGE_DAYS) {
        throw new InvalidArgumentException('Date range is too long (max ' . SCHEDULE_MAX_RANGE_DAYS . ' days).');
    }
    $fromDate = $fromD->format('Y-m-d');
    $toDate = $toD->format('Y-m-d');
    $inRange = fn($d) => $d >= $fromDate && $d <= $toDate;

    $rangeDates = [];
    for ($d = $fromD; $d <= $toD; $d = $d->modify('+1 day')) $rangeDates[] = $d->format('Y-m-d');

    $rulesByClass = [];
    foreach ($rules as $r) $rulesByClass[(int) $r['teacher_subject_id']][] = $r;
    $exceptionsByClass = [];
    foreach ($exceptions as $x) $exceptionsByClass[(int) $x['teacher_subject_id']][$x['original_date']] = $x;

    $out = [];
    foreach ($assignments as $a) {
        $classId = (int) $a['teacher_subject_id'];
        $classRules = $rulesByClass[$classId] ?? [];
        if (!$classRules) continue;
        $classExceptions = $exceptionsByClass[$classId] ?? [];

        $dates = array_unique(array_merge($rangeDates, array_keys($classExceptions)));
        foreach ($dates as $date) {
            $base = schedule_rules_on_date($classRules, $date);
            if (!$base) continue;
            $x = $classExceptions[$date] ?? null;

            if (!$x || $x['exception_type'] === 'CANCELLED') {
                if (!$inRange($date)) continue;
                foreach ($base as $r) {
                    $out[] = schedule_build_occurrence($a, $r, $date, $r['start_time'], $r['end_time'], $x, $date);
                }
                continue;
            }

            // RESCHEDULED
            $newDate = $x['new_date'] ?: $date;
            if (!$inRange($newDate)) continue;
            $moved = $x['new_start_time'] ? [$base[0]] : $base;
            foreach ($moved as $r) {
                $out[] = schedule_build_occurrence($a, $r, $newDate,
                    $x['new_start_time'] ?: $r['start_time'], $x['new_end_time'] ?: $r['end_time'], $x, $date);
            }
        }
    }

    $includeCancelled = $filters['include_cancelled'] ?? true;
    $out = array_values(array_filter($out, fn($o) =>
        ($includeCancelled || !$o['is_cancelled'])
        && (empty($filters['teacher_id']) || $o['teacher_id'] === (int) $filters['teacher_id'])
        && (empty($filters['lab_id']) || $o['lab_id'] === (int) $filters['lab_id'])
    ));
    usort($out, fn($p, $q) => [$p['starts_at'], $p['subject_code'], $p['teacher_subject_id']]
                            <=> [$q['starts_at'], $q['subject_code'], $q['teacher_subject_id']]);
    return $out;
}

/** The class's rules that produce a meeting on $date (weekday + effective range), earliest first. */
function schedule_rules_on_date(array $classRules, string $date): array {
    $dow = (int) schedule_date($date)->format('N');
    $matches = array_values(array_filter($classRules, fn($r) =>
        (int) $r['day_of_week'] === $dow
        && (empty($r['effective_start_date']) || $date >= $r['effective_start_date'])
        && (empty($r['effective_end_date']) || $date <= $r['effective_end_date'])
    ));
    usort($matches, fn($p, $q) => strcmp(schedule_time($p['start_time']), schedule_time($q['start_time'])));
    return $matches;
}

function schedule_build_occurrence(array $a, array $rule, string $date, $start, $end, ?array $x, string $originalDate): array {
    $start = schedule_time($start);
    $end = schedule_time($end);
    $rescheduled = $x && $x['exception_type'] === 'RESCHEDULED';
    $newLab = $rescheduled && !empty($x['new_lab_id']);
    $newTeacher = $rescheduled && !empty($x['new_teacher_id']);
    return [
        'occurrence_key'      => $a['teacher_subject_id'] . ':' . $date . ':' . $start,
        'teacher_subject_id'  => (int) $a['teacher_subject_id'],
        'schedule_id'         => (int) $rule['schedule_id'],
        'date'                => $date,
        'day_of_week'         => (int) schedule_date($date)->format('N'),
        'start_time'          => $start,
        'end_time'            => $end,
        'starts_at'           => "$date $start",
        'ends_at'             => "$date $end",
        'lab_id'              => (int) ($newLab ? $x['new_lab_id'] : $a['lab_id']),
        'lab_name'            => $newLab ? ($x['new_lab_name'] ?? null) : $a['lab_name'],
        'teacher_id'          => (int) ($newTeacher ? $x['new_teacher_id'] : $a['teacher_id']),
        'teacher_name'        => $newTeacher ? ($x['new_teacher_name'] ?? null) : $a['teacher_name'],
        'subject_id'          => (int) $a['subject_id'],
        'subject_code'        => $a['subject_code'],
        'subject_name'        => $a['subject_name'],
        'section'             => $a['section'],
        'year_level'          => $a['year_level'] !== null ? (int) $a['year_level'] : null,
        'program_id'          => $a['program_id'] !== null ? (int) $a['program_id'] : null,
        'program_code'        => $a['program_code'] ?? null,
        'institution_id'      => $a['institution_id'] !== null ? (int) $a['institution_id'] : null,
        'department_id'       => $a['department_id'] !== null ? (int) $a['department_id'] : null,
        'max_students'        => (int) $a['max_students'],
        'assignment_status'   => $a['status'],
        'is_cancelled'        => $x !== null && $x['exception_type'] === 'CANCELLED',
        'is_rescheduled'      => $rescheduled,
        'exception_id'        => $x ? (int) $x['exception_id'] : null,
        'exception_reason'    => $x['reason'] ?? null,
        'original_date'       => $originalDate,
        'original_start_time' => schedule_time($rule['start_time']),
        'original_end_time'   => schedule_time($rule['end_time']),
        'original_lab_id'     => (int) $a['lab_id'],
        'original_teacher_id' => (int) $a['teacher_id'],
    ];
}

/**
 * CANCELLED, UPCOMING (before start), ACTIVE (start <= now < end) or EXPIRED.
 * $now: null (current Manila time), a date-time string (read as Manila time) or DateTimeInterface.
 */
function get_occurrence_status(array $occ, $now = null): string {
    if (!empty($occ['is_cancelled'])) return OCCURRENCE_CANCELLED;
    $now = schedule_now($now);
    $start = new DateTimeImmutable($occ['starts_at'], schedule_tz());
    $end = new DateTimeImmutable($occ['ends_at'], schedule_tz());
    if ($now < $start) return OCCURRENCE_UPCOMING;
    if ($now < $end) return OCCURRENCE_ACTIVE;
    return OCCURRENCE_EXPIRED;
}

/** Today's meetings (Manila date of $now), same filters as get_occurrences(). */
function get_todays_occurrences(PDO $pdo, array $filters = [], $now = null): array {
    $today = schedule_now($now)->format('Y-m-d');
    return get_occurrences($pdo, $today, $today, $filters);
}
