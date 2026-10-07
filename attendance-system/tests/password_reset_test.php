<?php
/**
 * Run: php attendance-system/tests/password_reset_test.php
 * Tests the pure parts of "forgot password" (no database, no email is sent).
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/password_reset.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

// --- token hashing: only the hash is stored ---
check('hash is 64 hex chars', (bool) preg_match('/^[0-9a-f]{64}$/', pwreset_hash('abc')));
check('hash is deterministic', pwreset_hash('abc') === pwreset_hash('abc'));
check('different tokens, different hashes', pwreset_hash('abc') !== pwreset_hash('abd'));
check('hash is not the token', pwreset_hash('abc') !== 'abc');

// --- password rules ---
check('empty password rejected', pwreset_password_problem('', '') !== '');
check('mismatch rejected', pwreset_password_problem('longenough1', 'longenough2') !== '');
check('too short rejected', pwreset_password_problem('short', 'short') !== '');
check('over 72 bytes rejected (bcrypt truncates)', pwreset_password_problem(str_repeat('a', 73), str_repeat('a', 73)) !== '');
check('good password accepted', pwreset_password_problem('correct horse', 'correct horse') === '');

// --- base URL is pinned by env vars, not by the Host header ---
putenv('APP_URL='); putenv('VERCEL_PROJECT_PRODUCTION_URL=');
$_SERVER['HTTP_HOST'] = 'evil.example';
putenv('APP_URL=https://school.example.com/');
check('APP_URL wins over a forged Host', pwreset_base_url() === 'https://school.example.com/');
putenv('APP_URL='); putenv('VERCEL_PROJECT_PRODUCTION_URL=myapp.vercel.app');
check('Vercel production host wins over a forged Host', pwreset_base_url() === 'https://myapp.vercel.app/');
putenv('VERCEL_PROJECT_PRODUCTION_URL=');
$_SERVER['HTTP_HOST'] = 'localhost:8080"><script>';
check('Host fallback is stripped of junk', strpos(pwreset_base_url(), '<') === false && strpos(pwreset_base_url(), '"') === false);

// --- mail: no key => logged locally, a failure on Vercel ---
putenv('MAIL_API_KEY='); putenv('MAIL_FROM='); putenv('VERCEL=');
check('local without key: mail "available"', mail_available() === true);
$old = ini_set('error_log', sys_get_temp_dir() . '/pwreset_test.log');
check('local without key: send_mail logs and returns true', send_mail('a@b.c', 'A', 's', '<p>x</p>', 'x') === true);
ini_set('error_log', $old);
putenv('VERCEL=1');
check('on Vercel without key: mail not available', mail_available() === false);
check('on Vercel without key: send_mail fails', send_mail('a@b.c', 'A', 's', '<p>x</p>', 'x') === false);
putenv('VERCEL=');

// --- the sign-out-everywhere patterns match PHP's real session encoding, and only that user ---
function session_blob(array $s): string {
    $_SESSION = $s; $blob = session_encode(); $_SESSION = [];
    return $blob;
}
function matches(string $blob, int $uid): bool {
    $pats = ['%user_id|i:' . $uid . ';%', '%user_id|s:' . strlen((string) $uid) . ':"' . $uid . '";%'];
    foreach ($pats as $p) {   // LIKE '%x%' is "contains x"
        if (preg_match('/' . preg_quote(trim($p, '%'), '/') . '/', $blob)) return true;
    }
    return false;
}
if (session_status() === PHP_SESSION_ACTIVE || @session_start()) {
    check('int user_id session matches', matches(session_blob(['csrf_token' => 'x', 'user_id' => 5, 'role' => 'student']), 5));
    check('string user_id session matches', matches(session_blob(['user_id' => '5']), 5));
    check('user 15 is not matched by user 5', !matches(session_blob(['user_id' => 15]), 5));
    check('profile_id 5 is not matched by user 5', !matches(session_blob(['profile_id' => 5, 'user_id' => 9]), 5));
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
