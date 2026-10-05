<?php
/**
 * Run: php attendance-system/tests/import_csv_test.php
 * Tests the CSV parsing helpers used by the batch imports (no database needed).
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/import_csv.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }
function csv_file(string $content, string $name = 'x.csv'): array {
    $p = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($p, $content);
    return ['name' => $name, 'tmp_name' => $p, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
}
function fails(callable $fn): bool { try { $fn(); return false; } catch (Exception $e) { return true; } }

check('header normalisation', import_norm_header(' Student Number ') === 'student_number');
check('BOM stripped from header', import_norm_header("\xEF\xBB\xBFEmail") === 'email');

[$h, $rows] = import_read_csv(csv_file("\xEF\xBB\xBFStudent Number,Full Name\n2024-1,Ana\n\n2024-2,\"<b>Ben</b>, Jr\"\n"), 10);
check('headers parsed', $h === ['student_number', 'full_name']);
check('blank line skipped, 2 rows', count($rows) === 2);
check('line numbers kept', $rows[0]['_line'] === 2 && $rows[1]['_line'] === 4);
check('tags stripped, comma in quotes kept', $rows[1]['full_name'] === 'Ben, Jr');

[, $semi] = import_read_csv(csv_file("a;b\n1;2\n"), 10);
check('semicolon delimiter detected', $semi[0]['b'] === '2');

[, $noHead] = import_read_csv(csv_file("2023-1\n2023-2\n"), 10, ['student_number'], false);
check('headerless file', count($noHead) === 2 && $noHead[1]['student_number'] === '2023-2');

check('rejects .txt', fails(fn() => import_read_csv(csv_file("a\n1\n", 'x.txt'), 10)));
check('rejects too many rows', fails(fn() => import_read_csv(csv_file("a\n1\n2\n3\n"), 2)));
check('rejects non-UTF-8', fails(fn() => import_read_csv(csv_file("a\n\xff\xfe\n"), 10)));
check('rejects empty file', fails(fn() => import_read_csv(csv_file(""), 10)));
check('rejects header-only file', fails(fn() => import_read_csv(csv_file("a,b\n"), 10)));
check('rejects upload error', fails(fn() => import_read_csv(['error' => UPLOAD_ERR_NO_FILE], 10)));

exit($fail ? 1 : 0);
