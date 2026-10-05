<?php
/**
 * Run: php attendance-system/tests/security_test.php
 * Checks the security hardening helpers (no database needed). tests/ is not deployed.
 */
putenv('QR_SECRET_KEY=');
require_once __DIR__ . '/../includes/functions.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

// --- safe_error_message ---
$secret = 'SQLSTATE[42P01]: relation "students" does not exist at host aws-0-secret.pooler.supabase.com';
$msg = safe_error_message(new PDOException($secret));
check('PDOException text is hidden', strpos($msg, 'SQLSTATE') === false && strpos($msg, 'secret') === false && $msg !== '');
$dup = new PDOException('duplicate key'); (function () use ($dup) { $r = new ReflectionProperty('Exception', 'code'); $r->setAccessible(true); $r->setValue($dup, '23505'); })();
check('unique violation gets a friendly message', safe_error_message($dup) === 'That record already exists.');
check('our own validation message is shown as written', safe_error_message(new Exception('Username already in use.')) === 'Username already in use.');

// --- no endpoint may echo raw exception text / SQL errors again ---
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || strpos($f->getPathname(), '/tests/') !== false || strpos($f->getPathname(), '/database/') !== false) continue;
    $src = file_get_contents($f->getPathname());
    if (preg_match("/'message'\\s*=>\\s*(\\\$e->getMessage\\(\\)|'Database error)/", $src)) $bad[] = basename($f->getPathname());
}
check('no endpoint returns raw exception text (' . implode(',', $bad) . ')', !$bad);

// --- QR key is private ---
define('DB_USER', 'u'); define('DB_PASS', 'p'); define('DB_HOST', 'h');
$src = file_get_contents(__DIR__ . '/../qr/qr_helper.php');
check('QR key is not a hard-coded string', strpos($src, 'CHANGE_THIS_SECRET') === false);

exit($fail ? 1 : 0);
