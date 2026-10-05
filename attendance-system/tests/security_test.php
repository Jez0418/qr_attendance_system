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

// --- CSRF ---
$_SESSION = [];
$t = csrf_token();
check('csrf token is 64 hex chars and stable', preg_match('/^[a-f0-9]{64}$/', $t) && csrf_token() === $t);
$_POST = []; $_SERVER['HTTP_X_CSRF_TOKEN'] = '';
check('missing token is invalid', !csrf_request_is_valid());
$_POST = ['csrf' => 'x' . substr($t, 1)];
check('wrong token is invalid', !csrf_request_is_valid());
$_POST = ['csrf' => $t];
check('correct token (form field) is valid', csrf_request_is_valid());
$_POST = []; $_SERVER['HTTP_X_CSRF_TOKEN'] = $t;
check('correct token (header) is valid', csrf_request_is_valid());
check('csrf_field() renders the token', strpos(csrf_field(), $t) !== false);

// --- every <form method="POST"> in a logged-in page must carry csrf_field() ---
$missing = [];
foreach ($it as $f) {
    $path = $f->getPathname();
    if ($f->getExtension() !== 'php' || strpos($path, '/tests/') !== false || strpos($path, '/database/') !== false || basename($path) === 'login.php') continue;
    $src = file_get_contents($path);
    preg_match_all('/<form[^>]*method="POST"[^>]*>(.{0,200})/is', $src, $m);
    foreach ($m[1] as $after) if (strpos($after, 'csrf_field') === false) $missing[] = basename($path);
}
check('every POST form has csrf_field() (' . implode(',', $missing) . ')', !$missing);

// --- login throttle helpers ---
require_once __DIR__ . '/../includes/login_throttle.php';
check('throttle message pluralises', login_lock_message(60) === 'Too many failed sign-in attempts. Please try again in 1 minute.'
    && login_lock_message(900) === 'Too many failed sign-in attempts. Please try again in 15 minutes.');
check('throttle username key is case-insensitive', login_username_key('  S2023001 ') === 's2023001');

exit($fail ? 1 : 0);
