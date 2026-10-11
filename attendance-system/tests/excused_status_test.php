<?php
/**
 * Run: php attendance-system/tests/excused_status_test.php
 *      php attendance-system/tests/excused_status_test.php --db   also runs against the DB from DB_* env vars,
 *                                                                inside a transaction that is rolled back.
 * The Excused status (database/supabase_excused_status.sql): it must never count as a check-in or an absence,
 * and it must show up wherever statuses are shown. tests/ is not deployed (see .vercelignore).
 */
$useDb = in_array('--db', $argv, true);
require_once __DIR__ . '/../includes/functions.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$root = realpath(__DIR__ . '/..');

// ---- "A check-in" is Present or Late, never "anything but Absent" (that would count Excused rows as scans)
// includes/attendance_override.php is exempt: its !== 'Absent' is the rule "only an Absent record can change".
$exempt = [str_replace('/', DIRECTORY_SEPARATOR, 'includes/attendance_override.php')];
$hits = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') continue;
    $rel = substr($file->getPathname(), strlen($root) + 1);
    if (strpos($rel, 'tests' . DIRECTORY_SEPARATOR) === 0 || in_array($rel, $exempt, true)) continue;
    foreach (file($file->getPathname()) as $i => $line) {
        if (preg_match('/<>\s*["\']Absent["\']|!==?\s*["\']Absent["\']/', $line)) $hits[] = str_replace('\\', '/', $rel) . ':' . ($i + 1);
    }
}
check('no page treats "not Absent" as a check-in (' . implode(', ', $hits) . ')', !$hits);
$live = file_get_contents("$root/teacher/ajax_session_status.php");
check('live list counts only Present/Late as scanned', strpos($live, "['Pending', 'Absent']") === false);

// ---- Stats: Excused is counted for display (never as attended or absent; the --db section proves the numbers)
$stats = file_get_contents("$root/includes/attendance_stats.php");
check('student and roster standings count excused', preg_match_all("/FILTER \\(WHERE ar\\.status = 'Excused'\\) AS excused/", $stats) === 2);

// ---- Database: excuse a real Absent record (rolled back). DemoPDO turns override_absent()'s own
// transaction into a SAVEPOINT inside ours, so everything is undone at the end.
if ($useDb) {
    require_once __DIR__ . '/demo_common.php';   // loads DB_* from .env when not set; exits if there is no database
    require_once __DIR__ . '/../includes/attendance_stats.php';
    require_once __DIR__ . '/../includes/attendance_override.php';
    echo "\n== Database (DB_HOST=" . getenv('DB_HOST') . "), rolled back afterwards\n";
    $pdo = demo_connect();
    $GLOBALS['pdo'] = $pdo;
    $pdo->beginTransaction();
    try {
        $pick = $pdo->query("
            SELECT ar.record_id, ar.student_id, s.teacher_subject_id, ts.teacher_id, t.user_id AS teacher_user_id
            FROM attendance_records ar
            JOIN attendance_sessions s ON s.session_id = ar.session_id
            JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
            JOIN teachers t ON t.teacher_id = ts.teacher_id
            JOIN enrollments e ON e.student_id = ar.student_id AND e.teacher_subject_id = s.teacher_subject_id AND e.status = 'enrolled'
            WHERE ar.status = 'Absent'
            ORDER BY ar.record_id LIMIT 1
        ")->fetch();
        check('found an Absent record to excuse', (bool) $pick);
        if ($pick) {
            $recordId = (int) $pick['record_id'];
            $studentId = (int) $pick['student_id'];
            $classId = (int) $pick['teacher_subject_id'];
            $teacherId = (int) $pick['teacher_id'];
            $teacherUserId = (int) $pick['teacher_user_id'];
            $standingRow = fn() => current(array_filter(student_class_standings($pdo, $studentId), fn($r) => (int) $r['teacher_subject_id'] === $classId));
            $before = $standingRow();

            $name = override_absent($pdo, $recordId, 'Excused', $teacherId, $teacherUserId, 'Medical certificate shown');
            check('override_absent returns the student name', $name !== '');
            $rec = $pdo->query("SELECT status, marked_by_user_id, override_reason FROM attendance_records WHERE record_id = $recordId")->fetch();
            check('record is Excused with who/why', $rec['status'] === 'Excused' && (int) $rec['marked_by_user_id'] === $teacherUserId && $rec['override_reason'] === 'Medical certificate shown');
            $after = $standingRow();
            check('excusing lowers the absence count by 1', $after['standing']['absent'] === $before['standing']['absent'] - 1);
            check('excused count went up by 1', ($after['excused'] ?? null) === ($before['excused'] ?? 0) + 1);
            check('the excused meeting drops out of the meeting count', $after['standing']['meetings'] === $before['standing']['meetings'] - 1);
            $threw = false;
            try { override_absent($pdo, $recordId, 'Present', $teacherId, $teacherUserId, 'second click'); } catch (Exception $e) { $threw = true; }
            check('a second change of the same record is refused', $threw);
            check('class roster map carries excused', (class_student_standings($pdo, $classId)[$studentId]['excused'] ?? null) === $after['excused']);
        }
    } finally {
        $pdo->rollBackAll();
    }
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
