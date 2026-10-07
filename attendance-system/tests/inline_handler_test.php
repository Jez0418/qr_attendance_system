<?php
/**
 * Run: php attendance-system/tests/inline_handler_test.php
 * Guards two things found in the teacher/student review (no database needed):
 *  - no page puts plain json_encode() into a single-quoted onclick='...' (a name or a student's remark
 *    containing an apostrophe would end the attribute: broken button, and script injection from student text)
 *  - valid_ymd() keeps hand-edited date filters away from the database
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    if ($f->getExtension() !== 'php' || strpos($path, '/tests/') !== false) continue;
    // Looks for: onclick='fn(  then a PHP echo of plain json_encode, i.e. raw JSON inside a single-quoted attribute
    if (preg_match("/on\w+='[^']*<\?php\s+echo\s+json_encode\(/", file_get_contents($path))) $bad[] = str_replace(realpath(__DIR__ . '/..') . '/', '', $path);
}
check('no single-quoted handler uses plain json_encode (' . implode(', ', $bad) . ')', !$bad);

check('the unused all-students search endpoint is gone', !file_exists(__DIR__ . '/../teacher/ajax_search_students.php'));

check('valid_ymd accepts a real date', valid_ymd('2026-10-07') === '2026-10-07');
check('valid_ymd trims spaces', valid_ymd(' 2026-10-07 ') === '2026-10-07');
foreach (['abc', '2026-02-30', '2026-13-01', '2026-10-07 10:00', "2026-10-07'; --", '', null] as $v) {
    check('valid_ymd rejects ' . var_export($v, true), valid_ymd($v) === '');
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
