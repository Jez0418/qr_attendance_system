<?php
/**
 * Run: php attendance-system/tests/qr_payload_test.php
 * Tests the rotating, signed QR payload (no database needed).
 * tests/ is not deployed (see .vercelignore).
 */
putenv('QR_SECRET_KEY=test-secret-key-for-qr-payload-tests');
require_once __DIR__ . '/../qr/qr_helper.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$t = 1_800_000_010;   // an arbitrary moment; its window starts at floor($t / 30) * 30
$payload = qr_build_session_payload(12, 'tok', $t);

check('fresh payload parses', qr_parse_session_payload($payload, $t) === ['session_id' => 12, 'token' => 'tok']);
check('valid in the same window, later', qr_parse_session_payload($payload, $t + QR_WINDOW_SECONDS - 11) !== false);
check('valid for ' . QR_GRACE_WINDOWS . ' windows after it was issued',
    qr_parse_session_payload($payload, $t + QR_GRACE_WINDOWS * QR_WINDOW_SECONDS) !== false);
check('stale after the grace windows (a forwarded photo)',
    qr_parse_session_payload($payload, $t + (QR_GRACE_WINDOWS + 1) * QR_WINDOW_SECONDS) === false);
check('payload from the future is rejected', qr_parse_session_payload($payload, $t - QR_WINDOW_SECONDS) === false);

$d = json_decode($payload, true);
$bump = fn(array $x) => json_encode($x);
check('tampered session id rejected', qr_parse_session_payload($bump(['session_id' => 13] + $d), $t) === false);
check('tampered token rejected', qr_parse_session_payload($bump(['token' => 'other'] + $d), $t) === false);
check('window cannot be rewritten to stay fresh', qr_parse_session_payload($bump(['w' => $d['w'] + 5] + $d), $t + 5 * QR_WINDOW_SECONDS) === false);
check('missing window (old-format payload) rejected',
    qr_parse_session_payload(json_encode(['session_id' => 12, 'token' => 'tok',
        'sig' => hash_hmac('sha256', 'session|12|tok', QR_SECRET_KEY)]), $t) === false);
check('missing signature rejected', qr_parse_session_payload($bump(['sig' => ''] + $d), $t) === false);
check('non-numeric window rejected', qr_parse_session_payload($bump(['w' => 'abc'] + $d), $t) === false);
check('garbage rejected', qr_parse_session_payload('not json', $t) === false);
check('refresh interval is shorter than one window', QR_REFRESH_SECONDS < QR_WINDOW_SECONDS);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
