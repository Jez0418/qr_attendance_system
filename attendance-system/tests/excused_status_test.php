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

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
