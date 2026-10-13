<?php
/**
 * Run: php attendance-system/tests/lab_qr_test.php
 * The permanent QR code of each laboratory (qr/qr_helper.php) and how a scan of it is mapped to the class
 * that is in session there (qr/session_manager.php pick_lab_occurrence()). Pure logic, no database.
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../qr/session_manager.php';   // before any output (config.php starts a session)

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

// ---- payload ----
$payload = qr_build_lab_payload(3, 1);
check('a lab payload parses back to the lab and version', qr_parse_lab_payload($payload) === ['lab_id' => 3, 'version' => 1]);
check('the same lab and version always give the same code (it is printed once)', qr_build_lab_payload(3, 1) === $payload);
check('a new version gives a different code', qr_build_lab_payload(3, 2) !== $payload);
$data = json_decode($payload, true);
foreach ([['lab', 4], ['v', 2]] as [$field, $value]) {
    $t = $data; $t[$field] = $value;
    check("editing $field breaks the signature", qr_parse_lab_payload(json_encode($t)) === false);
}
$t = $data; $t['sig'] = strrev($t['sig']);
check('a wrong signature is refused', qr_parse_lab_payload(json_encode($t)) === false);
$t = $data; unset($t['sig']);
check('a missing signature is refused', qr_parse_lab_payload(json_encode($t)) === false);
$t = $data; $t['lab'] = '3';
check('a lab id sent as a string is refused', qr_parse_lab_payload(json_encode($t)) === false);
$t = $data; $t['t'] = 'session';
check('the type marker must be "lab"', qr_parse_lab_payload(json_encode($t)) === false);
check('garbage is refused', qr_parse_lab_payload('hello') === false && qr_parse_lab_payload('') === false && qr_parse_lab_payload(null) === false);
$old = json_encode(['session_id' => 12, 'token' => 'abc', 'sig' => hash_hmac('sha256', 'session|12|abc', QR_SECRET_KEY)]);
check('an old per-meeting session code is no longer accepted', qr_parse_lab_payload($old) === false);
$forged = json_encode(['t' => 'lab', 'lab' => 3, 'v' => 1, 'sig' => hash_hmac('sha256', 'session|3|1', QR_SECRET_KEY)]);
check('a signature made for another message type is refused', qr_parse_lab_payload($forged) === false);

// ---- which meeting does a scan belong to ----
$occ = fn(int $class, string $start, string $end, bool $cancelled = false, ?string $reason = null) => [
    'teacher_subject_id' => $class, 'starts_at' => "2026-10-12 $start", 'ends_at' => "2026-10-12 $end",
    'is_cancelled' => $cancelled, 'exception_reason' => $reason,
];
$now = '2026-10-12 09:30:00';
$pick = fn(array $occs, array $mine) => pick_lab_occurrence($occs, $mine, $now);
$classOf = fn(array $r) => $r['occurrence']['teacher_subject_id'] ?? null;

$r = $pick([$occ(10, '09:00:00', '11:00:00')], [10]);
check('the student\'s class that is in session is found', $classOf($r) === 10 && $r['error'] === null);

$r = $pick([$occ(10, '07:00:00', '09:00:00'), $occ(11, '09:00:00', '11:00:00')], [11]);
check('earlier class over, the student\'s class in session: found', $classOf($r) === 11);

$r = $pick([$occ(10, '09:00:00', '11:00:00')], [99]);
check('a student of another class gets the active meeting, so the scan says "not enrolled"', $classOf($r) === 10);

$r = $pick([$occ(10, '11:00:00', '13:00:00')], [10]);
check('class not started yet', $r['occurrence'] === null && $r['error'] === 'This class has not started yet.');

$r = $pick([$occ(10, '07:00:00', '09:00:00')], [10]);
check('class already over', $r['occurrence'] === null && $r['error'] === 'This class has already ended.');

$r = $pick([$occ(10, '09:00:00', '11:00:00', true, 'Typhoon')], [10]);
check('cancelled class says why', $r['occurrence'] === null && $r['error'] === 'This class has been cancelled: Typhoon');

$r = $pick([$occ(10, '09:00:00', '11:00:00', true)], [10]);
check('a cancelled class is never picked', $r['occurrence'] === null);

$r = $pick([$occ(10, '07:00:00', '08:00:00'), $occ(10, '10:00:00', '12:00:00')], [10]);
check('two meetings of the student, the next one is the nearest: "not started"', $r['error'] === 'This class has not started yet.');

$r = $pick([], [10]);
check('nothing scheduled in the lab', $r['occurrence'] === null && $r['error'] === 'No class is in session in this laboratory right now.');

$r = $pick([$occ(10, '09:00:00', '11:00:00'), $occ(11, '09:00:00', '11:00:00')], [10, 11]);
check('two of the student\'s classes at once is refused, not guessed', $r['occurrence'] === null && $r['error'] !== null);

$r = $pick([$occ(10, '09:00:00', '11:00:00'), $occ(11, '09:00:00', '11:00:00')], [11]);
check('two classes at once in the lab: the student\'s own is chosen', $classOf($r) === 11);

// ---- the scan endpoint no longer accepts per-meeting codes ----
$scan = file_get_contents(__DIR__ . '/../student/ajax_scan.php');
check('ajax_scan.php parses lab codes', strpos($scan, 'qr_parse_lab_payload(') !== false);
check('ajax_scan.php no longer parses per-meeting session codes', strpos($scan, 'qr_parse_session_payload(') === false);
check('ajax_scan.php checks the lab code version', strpos($scan, 'lab_qr_version(') !== false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
