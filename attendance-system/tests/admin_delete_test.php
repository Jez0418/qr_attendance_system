<?php
/**
 * Run: php attendance-system/tests/admin_delete_test.php
 *      php attendance-system/tests/admin_delete_test.php --db   also runs against the DB from DB_* env vars,
 *                                                               inside a transaction that is rolled back (LIVE database).
 * Deleting a class assignment, teacher, subject or laboratory (includes/admin_delete.php): only a disabled item
 * can go, its children go with it, and a parent with an enabled class is refused. tests/ is not deployed.
 */
$useDb = in_array('--db', $argv, true);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_delete.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }
function throws(callable $fn): string { try { $fn(); } catch (Exception $e) { return $e->getMessage(); } return ''; }

$root = realpath(__DIR__ . '/..');

// ---- Pure rules
$none = ['classes' => 0, 'enabled_classes' => 0, 'enrollments' => 0, 'sessions' => 0, 'records' => 0];
$busy = ['classes' => 3, 'enabled_classes' => 2, 'enrollments' => 28, 'sessions' => 14, 'records' => 392];
check('enabled class is refused', str_contains((string) admin_delete_blocker('class', 'active', $none), 'Disabled'));
check('active teacher is refused', str_contains((string) admin_delete_blocker('teacher', 'active', $none), 'Inactive'));
check('disabled class with history may go', admin_delete_blocker('class', 'inactive', $busy) === null);
check('inactive subject with only disabled classes may go', admin_delete_blocker('subject', 'inactive', ['enabled_classes' => 0] + $busy) === null);
$b = (string) admin_delete_blocker('lab', 'inactive', $busy);
check('inactive lab with enabled classes is refused (says how many)', str_contains($b, '2 enabled class assignments'));
check('singular wording', str_contains((string) admin_delete_blocker('subject', 'inactive', ['enabled_classes' => 1] + $busy), '1 enabled class assignment.'));
check('blocker text carries no item name (it goes into a toast)', !str_contains($b, '<') && !str_contains($b, '"'));
check('summary for a class has no class count', admin_delete_summary('class', $busy) === '28 enrollments, 14 attendance sessions, 392 attendance records');
check('summary for a lab lists its classes first', str_starts_with(admin_delete_summary('lab', $busy), '3 class assignments, 28 enrollments'));
check('summary singular', admin_delete_summary('class', ['classes' => 1, 'enrollments' => 1, 'sessions' => 1, 'records' => 1] + $none)
    === '1 enrollment, 1 attendance session, 1 attendance record');
check('active student is refused', str_contains((string) admin_delete_blocker('student', 'active', $none), 'Inactive'));
check('inactive student with history may go', admin_delete_blocker('student', 'inactive', ['enabled_classes' => 0] + $busy) === null);
check('summary for a student is enrollments and records only', admin_delete_summary('student', $busy) === '28 enrollments, 392 attendance records');
check('every kind has a column, noun and two status words', count(array_filter(ADMIN_DELETE_KINDS, fn($k) => count($k) === 4)) === 5);

function admin_delete_preview_text(string $kind): string {
    $src = file_get_contents(__DIR__ . '/../includes/admin_delete.php');
    return str_contains($src, 'ADMIN_ARCHIVE_KINDS, true)) {') ? $src : '';
}

// ---- Wiring: endpoints use the helper, pages go through deleteDisabled(), the old "in use" blocks are gone
foreach (['assignments' => ['class', 'ajax_assignments.php'], 'teachers' => ['teacher', 'ajax_teachers.php'],
          'students' => ['student', 'ajax_students.php'], 'subjects' => ['subject', 'ajax_subjects.php'], 'laboratories' => ['lab', 'ajax_laboratories.php']] as $page => [$kind, $endpoint]) {
    $ajax = file_get_contents("$root/admin/$endpoint");
    check("$endpoint previews and deletes through the helper", str_contains($ajax, "admin_delete_preview(\$pdo, '$kind'")
        && str_contains($ajax, "admin_delete_perform(\$pdo, '$kind'") && str_contains($ajax, 'includes/admin_delete.php'));
    check("$endpoint has no DELETE FROM of its own", !preg_match('/DELETE FROM (teacher_subjects|users|students|subjects|laboratories)/', $ajax));
    $pageSrc = file_get_contents("$root/admin/$page.php");
    check("$page.php deletes through deleteDisabled()", str_contains($pageSrc, "deleteDisabled('$endpoint'") && !str_contains($pageSrc, "action: 'delete'"));
}
$adminSrc = file_get_contents("$root/includes/admin_delete.php");
check('teacher and student are archived, not removed', ADMIN_ARCHIVE_KINDS === ['teacher', 'student']
    && str_contains($adminSrc, 'admin_archive_user(') && !str_contains($adminSrc, "'users', 'user_id'"));
check('archive keeps history: it never deletes attendance rows', preg_match('/function admin_archive_user.*?\n\}/s', $adminSrc, $m) && !str_contains($m[0], 'DELETE FROM'));
check('archive drops a student\'s enrollments', str_contains($adminSrc, "UPDATE enrollments SET status = 'dropped'"));
check('archived preview says history stays', str_contains(admin_delete_preview_text('teacher'), 'stay in the history'));
check('teacher delete signs the account out', str_contains(file_get_contents("$root/includes/admin_delete.php"), "destroy_user_sessions(\$pdo, \$target['user_id'])"));

// ---- Teacher history: the two override actions sit in one Actions menu
$hist = file_get_contents("$root/teacher/history.php");
check('history has an Actions menu button', str_contains($hist, 'aria-haspopup="menu"') && str_contains($hist, 'toggleRowMenu(this)'));
check('history menu keeps both items', substr_count($hist, 'role="menuitem"') === 2);
check('menu closes before the override dialog opens', preg_match('/function openOverride\([^)]*\) \{\s*closeRowMenus\(\);/', $hist) === 1);
$css = file_get_contents("$root/assets/css/style.css");
check('menu styles exist and use theme tokens', str_contains($css, '.row-menu-list{') && str_contains($css, 'background:var(--surface)'));

// ---- Database: delete a real class, then a real laboratory, inside a transaction that is rolled back
if ($useDb) {
    require_once __DIR__ . '/demo_common.php';
    echo "\n== Database (DB_HOST=" . getenv('DB_HOST') . "), rolled back afterwards\n";
    $pdo = demo_connect();
    $GLOBALS['pdo'] = $pdo;
    $adminId = (int) $pdo->query("SELECT user_id FROM users WHERE role = 'admin' ORDER BY user_id LIMIT 1")->fetchColumn();
    $pdo->beginTransaction();
    try {
        // A class with the most sessions, so the cascade has something to remove.
        $classId = (int) $pdo->query("
            SELECT ts.teacher_subject_id FROM teacher_subjects ts
            ORDER BY (SELECT COUNT(*) FROM attendance_sessions s WHERE s.teacher_subject_id = ts.teacher_subject_id) DESC, ts.teacher_subject_id LIMIT 1
        ")->fetchColumn();
        check('found a class to delete', $classId > 0);
        if ($classId) {
            $before = admin_delete_impact($pdo, 'class', $classId);
            $pdo->prepare("UPDATE teacher_subjects SET status = 'active' WHERE teacher_subject_id = ?")->execute([$classId]);
            check('an enabled class is refused', throws(fn() => admin_delete_perform($pdo, 'class', $classId, $adminId)) !== '');
            check('...and is still there', admin_delete_target($pdo, 'class', $classId) !== null);

            $pdo->prepare("UPDATE teacher_subjects SET status = 'inactive' WHERE teacher_subject_id = ?")->execute([$classId]);
            $preview = admin_delete_preview($pdo, 'class', $classId);
            check('preview lists the records that go', str_contains($preview['confirm'], admin_delete_summary('class', $before)));
            admin_delete_perform($pdo, 'class', $classId, $adminId);
            check('the class is gone', admin_delete_target($pdo, 'class', $classId) === null);
            $left = admin_delete_impact($pdo, 'class', $classId);
            check('its enrollments, sessions and records are gone', $left['enrollments'] === 0 && $left['sessions'] === 0 && $left['records'] === 0);
            check('the delete is in the activity log', (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'Deleted class assignment:%'")->fetchColumn() > 0);
        }

        // A laboratory with classes: refused while one of them is enabled, deleted (with them) once none is.
        $labId = (int) $pdo->query("SELECT lab_id FROM teacher_subjects GROUP BY lab_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
        if ($labId) {
            $pdo->prepare("UPDATE laboratories SET status = 'inactive' WHERE lab_id = ?")->execute([$labId]);
            $pdo->prepare("UPDATE teacher_subjects SET status = 'active' WHERE lab_id = ?")->execute([$labId]);
            check('an inactive lab with enabled classes is refused', str_contains(throws(fn() => admin_delete_perform($pdo, 'lab', $labId, $adminId)), 'enabled class'));
            $pdo->prepare("UPDATE teacher_subjects SET status = 'inactive' WHERE lab_id = ?")->execute([$labId]);
            admin_delete_perform($pdo, 'lab', $labId, $adminId);
            check('the lab and its classes are gone', admin_delete_target($pdo, 'lab', $labId) === null
                && (int) $pdo->query("SELECT COUNT(*) FROM teacher_subjects WHERE lab_id = $labId")->fetchColumn() === 0);
        }
    } finally {
        $pdo->rollBackAll();
    }
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
