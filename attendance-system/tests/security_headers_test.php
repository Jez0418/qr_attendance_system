<?php
/**
 * Run: php attendance-system/tests/security_headers_test.php
 * Tests the browser security headers and the QR key check (no database needed).
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/security_headers.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$h = security_headers(false);
$csp = $h['Content-Security-Policy'];
check('CSP forbids framing', strpos($csp, "frame-ancestors 'none'") !== false && $h['X-Frame-Options'] === 'DENY');
check('CSP blocks plugins, <base> and foreign form targets',
    strpos($csp, "object-src 'none'") !== false && strpos($csp, "base-uri 'self'") !== false && strpos($csp, "form-action 'self'") !== false);
check('CSP default is same-origin', strpos($csp, "default-src 'self'") !== false);
check('CSP allows the CDNs the app loads scripts from',
    strpos($csp, 'https://cdnjs.cloudflare.com') !== false && strpos($csp, 'https://unpkg.com') !== false && strpos($csp, 'https://cdn.jsdelivr.net') !== false);
check('CSP allows Google Fonts', strpos($csp, 'https://fonts.googleapis.com') !== false && strpos($csp, 'https://fonts.gstatic.com') !== false);
check('CSP allows profile photos (data:) and the camera stream (blob:)', strpos($csp, "img-src 'self' data: blob:") !== false && strpos($csp, "media-src 'self' blob:") !== false);
check('CSP does not allow arbitrary hosts or eval', !preg_match('/\s\*[\s;]|unsafe-eval/', $csp));
check('camera and location allowed for this site only', strpos($h['Permissions-Policy'], 'camera=(self)') !== false && strpos($h['Permissions-Policy'], 'geolocation=(self)') !== false);
check('nosniff', $h['X-Content-Type-Options'] === 'nosniff');
check('no HSTS over plain HTTP', !isset($h['Strict-Transport-Security']));
check('HSTS over HTTPS', (security_headers(true)['Strict-Transport-Security'] ?? '') === 'max-age=31536000');

// QR key check
require_once __DIR__ . '/../qr/qr_helper.php';
putenv('QR_SECRET_KEY=');
check('unset QR_SECRET_KEY is reported', qr_key_is_configured() === false);
putenv('QR_SECRET_KEY=short');
check('too-short QR_SECRET_KEY is reported', qr_key_is_configured() === false);
putenv('QR_SECRET_KEY=' . str_repeat('k', 32));
check('a 32-char QR_SECRET_KEY is accepted', qr_key_is_configured() === true);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
