<?php
/**
 * tests/schedule_test.php — CLI check of includes/schedule.php
 *
 *   php tests/schedule_test.php          pure logic only (no database)
 *   php tests/schedule_test.php --db     also runs against the DB from DB_* env vars,
 *                                        inside a transaction that is always rolled back
 *
 * Scenario: a class that meets Monday + Wednesday 3:00-5:00 PM (Mon 2026-10-05 week).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/schedule.php';
$useDb = in_array('--db', $argv, true);
if ($useDb) require_once __DIR__ . '/../includes/db.php';   // before any output (config.php starts a session)

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) $failures++;
    printf("  [%s] %s => %s%s\n", $ok ? 'PASS' : 'FAIL', $label, json_encode($actual), $ok ? '' : ' (expected ' . json_encode($expected) . ')');
}
function print_occurrences(array $occs, $now) {
    printf("  %-10s %-3s %-11s %-22s %-12s %-9s %-24s %s\n", 'date', 'day', 'time', 'lab', 'teacher', 'status', 'exception', 'original');
    foreach ($occs as $o) {
        $exc = $o['is_cancelled'] ? 'CANCELLED' : ($o['is_rescheduled'] ? 'RESCHEDULED' : '');
        if ($o['exception_reason']) $exc .= " ({$o['exception_reason']})";
        printf("  %-10s %-3s %s-%s %-22s %-12s %-9s %-24s %s\n",
            $o['date'], substr(date('D', strtotime($o['date'])), 0, 3), substr($o['start_time'], 0, 5), substr($o['end_time'], 0, 5),
            $o['lab_name'], $o['teacher_name'], get_occurrence_status($o, $now), $exc,
            $o['is_rescheduled'] ? $o['original_date'] . ' ' . substr($o['original_start_time'], 0, 5) : '');
    }
}

// ------------------------------------------------------------------ fixture
$class = [
    'teacher_subject_id' => 101, 'teacher_id' => 1, 'lab_id' => 1, 'subject_id' => 1, 'section' => '3A',
    'year_level' => 3, 'program_id' => 1, 'institution_id' => 1, 'department_id' => 1, 'max_students' => 40,
    'status' => 'active', 'subject_code' => 'CS301', 'subject_name' => 'Networks', 'teacher_name' => 'T. Cruz',
    'lab_name' => 'Lab A', 'program_code' => 'BSCS',
];
$rules = [
    ['schedule_id' => 1, 'teacher_subject_id' => 101, 'day_of_week' => 1, 'start_time' => '15:00:00', 'end_time' => '17:00:00', 'effective_start_date' => null, 'effective_end_date' => null],
    ['schedule_id' => 2, 'teacher_subject_id' => 101, 'day_of_week' => 3, 'start_time' => '15:00:00', 'end_time' => '17:00:00', 'effective_start_date' => null, 'effective_end_date' => null],
];
$exceptions = [
    ['exception_id' => 1, 'teacher_subject_id' => 101, 'original_date' => '2026-10-14', 'exception_type' => 'CANCELLED',
     'new_date' => null, 'new_start_time' => null, 'new_end_time' => null, 'new_lab_id' => null, 'new_teacher_id' => null, 'reason' => 'Holiday'],
    ['exception_id' => 2, 'teacher_subject_id' => 101, 'original_date' => '2026-10-19', 'exception_type' => 'RESCHEDULED',
     'new_date' => '2026-10-20', 'new_start_time' => '16:00:00', 'new_end_time' => '18:00:00', 'new_lab_id' => 2, 'new_teacher_id' => 2,
     'reason' => 'Make-up', 'new_lab_name' => 'Lab B', 'new_teacher_name' => 'J. Santos'],
];
$now = '2026-10-05 15:30:00';

echo "== Monday + Wednesday 3-5 PM, Oct 5-21 2026, now = Mon $now (Asia/Manila)\n";
$occs = expand_occurrences([$class], $rules, $exceptions, '2026-10-05', '2026-10-21');
print_occurrences($occs, $now);

echo "\n== Status boundaries for Mon 2026-10-05 3:00-5:00 PM\n";
$mon = $occs[0];
foreach (['2026-10-05 14:59:59' => 'UPCOMING', '2026-10-05 15:00:00' => 'ACTIVE', '2026-10-05 16:59:59' => 'ACTIVE',
          '2026-10-05 17:00:00' => 'EXPIRED', '2026-10-06 09:00:00' => 'EXPIRED'] as $t => $want) {
    check("status at $t", get_occurrence_status($mon, $t), $want);
}
check('status at 07:30 UTC (= 15:30 Manila)', get_occurrence_status($mon, new DateTime('2026-10-05 07:30:00', new DateTimeZone('UTC'))), 'ACTIVE');
check('cancelled Wed 10-14', get_occurrence_status($occs[3], $now), 'CANCELLED');

echo "\n== Checks\n";
check('occurrence count Oct 5-21', count($occs), 6);
check('dates', array_column($occs, 'date'), ['2026-10-05', '2026-10-07', '2026-10-12', '2026-10-14', '2026-10-20', '2026-10-21']);
check('Mon 10-19 not listed (moved)', in_array('2026-10-19', array_column($occs, 'date'), true), false);
$moved = expand_occurrences([$class], $rules, $exceptions, '2026-10-20', '2026-10-20');
check('moved meeting found by its new date only', [$moved[0]['starts_at'], $moved[0]['lab_name'], $moved[0]['teacher_name']], ['2026-10-20 16:00:00', 'Lab B', 'J. Santos']);
check('include_cancelled=false drops 10-14', count(expand_occurrences([$class], $rules, $exceptions, '2026-10-05', '2026-10-21', ['include_cancelled' => false])), 5);
check('teacher_id=2 sees only the substitute meeting', array_column(expand_occurrences([$class], $rules, $exceptions, '2026-10-05', '2026-10-21', ['teacher_id' => 2]), 'date'), ['2026-10-20']);
$termRules = $rules;
$termRules[0]['effective_end_date'] = '2026-10-11';     // Monday rule ends after Oct 11
$termRules[1]['effective_start_date'] = '2026-10-08';   // Wednesday rule starts Oct 8
check('effective dates', array_column(expand_occurrences([$class], $termRules, [], '2026-10-05', '2026-10-21'), 'date'), ['2026-10-05', '2026-10-14', '2026-10-21']);
check('Tuesday has no meetings', expand_occurrences([$class], $rules, $exceptions, '2026-10-06', '2026-10-06'), []);

// ------------------------------------------------------------------ database
if ($useDb) {
    echo "\n== Database (DB_HOST=" . getenv('DB_HOST') . ", port " . getenv('DB_PORT') . "), rolled back afterwards\n";
    $pdo->beginTransaction();
    try {
        $teacher = $pdo->query('SELECT teacher_id, full_name FROM teachers ORDER BY teacher_id LIMIT 2')->fetchAll();
        $lab = $pdo->query('SELECT lab_id, lab_name FROM laboratories ORDER BY lab_id LIMIT 2')->fetchAll();
        $subjectId = $pdo->query('SELECT subject_id FROM subjects ORDER BY subject_id LIMIT 1')->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, lab_id, section, max_students, status)
                               VALUES (?, ?, ?, 'TEST-SCHED', 40, 'active') RETURNING teacher_subject_id");
        $stmt->execute([$teacher[0]['teacher_id'], $subjectId, $lab[0]['lab_id']]);
        $id = (int) $stmt->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO class_schedules (teacher_subject_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)');
        $ins->execute([$id, 1, '15:00', '17:00']);
        $ins->execute([$id, 3, '15:00', '17:00']);
        $pdo->prepare("INSERT INTO schedule_exceptions (teacher_subject_id, original_date, exception_type, reason) VALUES (?, '2026-10-14', 'CANCELLED', 'Holiday')")->execute([$id]);
        $pdo->prepare("INSERT INTO schedule_exceptions (teacher_subject_id, original_date, exception_type, new_date, new_start_time, new_end_time, new_lab_id, new_teacher_id, reason)
                       VALUES (?, '2026-10-19', 'RESCHEDULED', '2026-10-20', '16:00', '18:00', ?, ?, 'Make-up')")
            ->execute([$id, $lab[1]['lab_id'], $teacher[1]['teacher_id']]);

        $dbOccs = get_occurrences($pdo, '2026-10-05', '2026-10-21', ['teacher_subject_id' => $id]);
        print_occurrences($dbOccs, $now);
        check('DB dates match pure expansion', array_column($dbOccs, 'date'), array_column($occs, 'date'));
        check('DB statuses', array_map(fn($o) => get_occurrence_status($o, $now), $dbOccs), ['ACTIVE', 'UPCOMING', 'UPCOMING', 'CANCELLED', 'UPCOMING', 'UPCOMING']);
        check('DB moved meeting uses new lab + teacher', [$dbOccs[4]['lab_id'], $dbOccs[4]['teacher_id']], [(int) $lab[1]['lab_id'], (int) $teacher[1]['teacher_id']]);
        check('substitute teacher filter', array_column(get_occurrences($pdo, '2026-10-05', '2026-10-21', ['teacher_id' => $teacher[1]['teacher_id'], 'teacher_subject_id' => $id]), 'date'), ['2026-10-20']);

        $today = get_todays_occurrences($pdo, ['teacher_subject_id' => $id], '2026-10-07 16:00:00');
        check('get_todays_occurrences on Wed 10-07 4 PM', [count($today), $today[0]['date'] ?? null, isset($today[0]) ? get_occurrence_status($today[0], '2026-10-07 16:00:00') : null], [1, '2026-10-07', 'ACTIVE']);
        check('get_todays_occurrences on Tue 10-06', get_todays_occurrences($pdo, ['teacher_subject_id' => $id], '2026-10-06 16:00:00'), []);
    } finally {
        $pdo->rollBack();
    }
}

echo "\n" . ($failures ? "$failures check(s) FAILED\n" : "All checks passed\n");
exit($failures ? 1 : 0);
