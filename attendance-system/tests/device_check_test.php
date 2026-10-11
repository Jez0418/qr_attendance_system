<?php
/**
 * Run: php attendance-system/tests/device_check_test.php
 *      php attendance-system/tests/device_check_test.php --db   also runs against the DB from DB_* env vars,
 *                                                              inside a transaction that is rolled back.
 * Tests the "one phone checks in one student per meeting" rule (includes/device.php).
 * tests/ is not deployed (see .vercelignore).
 */
$useDb = in_array('--db', $argv, true);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/device.php';
if ($useDb) require_once __DIR__ . '/../includes/db.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

// ---- Device ID format (the cookie is browser input: anything else is replaced, never stored)
check('32 lowercase hex characters is valid', is_valid_device_id(str_repeat('a1', 16)));
check('uppercase hex is refused', !is_valid_device_id(str_repeat('A1', 16)));
check('31 characters is refused', !is_valid_device_id(str_repeat('a', 31)));
check('33 characters is refused', !is_valid_device_id(str_repeat('a', 33)));
check('non-hex characters are refused', !is_valid_device_id(str_repeat('g', 32)));
check('SQL in the cookie is refused', !is_valid_device_id("' OR 1=1 --" . str_repeat('a', 21)));
check('null is refused', !is_valid_device_id(null));
check('an array is refused', !is_valid_device_id(['a']));
check('empty string is refused', !is_valid_device_id(''));

// ---- Reading / creating the cookie
unset($_COOKIE[DEVICE_COOKIE]);
check('no cookie and create=false gives null', current_device_id(false) === null);
$new = current_device_id();
check('no cookie: a new valid ID is created', is_valid_device_id($new));
check('the new ID is remembered for the rest of the request', current_device_id() === $new);
$_COOKIE[DEVICE_COOKIE] = str_repeat('b2', 16);
check('a valid cookie is reused as-is', current_device_id() === str_repeat('b2', 16));
$_COOKIE[DEVICE_COOKIE] = 'not-a-device-id';
$replaced = current_device_id();
check('a malformed cookie is replaced', is_valid_device_id($replaced) && $replaced !== 'not-a-device-id');
check('two new IDs differ', (function () { unset($_COOKIE[DEVICE_COOKIE]); $a = current_device_id(); unset($_COOKIE[DEVICE_COOKIE]); return $a !== current_device_id(); })());

// ---- The scan endpoint is wired up (a refactor must not silently drop the check)
$scan = file_get_contents(__DIR__ . '/../student/ajax_scan.php');
check('ajax_scan.php calls device_conflict()', strpos($scan, 'device_conflict(') !== false);
check('ajax_scan.php stores device_id with the record', preg_match('/INSERT INTO attendance_records \([^)]*device_id/', $scan) === 1);
check('scanner.php sets the device cookie', strpos(file_get_contents(__DIR__ . '/../student/scanner.php'), 'current_device_id(') !== false);

// ---- Database: the conflict rule against real tables (rolled back)
if ($useDb) {
    echo "\n== Database (DB_HOST=" . getenv('DB_HOST') . "), rolled back afterwards\n";
    $pdo->beginTransaction();
    try {
        $sessionId = (int) $pdo->query('SELECT session_id FROM attendance_sessions ORDER BY session_id LIMIT 1')->fetchColumn();
        $st = $pdo->prepare('SELECT student_id, full_name FROM students s
                             WHERE NOT EXISTS (SELECT 1 FROM attendance_records ar WHERE ar.session_id = ? AND ar.student_id = s.student_id)
                             ORDER BY student_id LIMIT 3');
        $st->execute([$sessionId]);
        [$a, $b, $c] = $st->fetchAll();
        $phone = str_repeat('c3', 16);
        $other = str_repeat('d4', 16);
        $ins = $pdo->prepare('INSERT INTO attendance_records (session_id, student_id, time_in, status, device_id) VALUES (?, ?, NOW(), ?, ?)');
        $ins->execute([$sessionId, $a['student_id'], 'Present', $phone]);

        check('same phone, another student: blocked with the first student\'s name',
            device_conflict($pdo, $sessionId, $phone, (int) $b['student_id']) === $a['full_name']);
        check('same phone, same student: not a conflict', device_conflict($pdo, $sessionId, $phone, (int) $a['student_id']) === null);
        check('another phone: not a conflict', device_conflict($pdo, $sessionId, $other, (int) $b['student_id']) === null);
        check('no device ID: not a conflict', device_conflict($pdo, $sessionId, null, (int) $b['student_id']) === null);
        check('malformed device ID: not a conflict', device_conflict($pdo, $sessionId, 'x', (int) $b['student_id']) === null);

        $ins->execute([$sessionId, $c['student_id'], 'Absent', null]);   // Absent rows / overrides carry no device
        check('Absent rows without a device never count', device_conflict($pdo, $sessionId, $other, (int) $b['student_id']) === null);
        $next = (int) $pdo->query('SELECT COALESCE(MAX(session_id), 0) + 1 FROM attendance_sessions')->fetchColumn();
        check('another session: not a conflict', device_conflict($pdo, $next, $phone, (int) $b['student_id']) === null);
    } finally {
        $pdo->rollBack();
    }
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
