<?php
/**
 * ------------------------------------------------------------
 * includes/device.php
 * One phone checks in one student per meeting.
 *
 * Each browser gets a random ID in an HttpOnly cookie (set by student/scanner.php, or by
 * student/ajax_scan.php if it is missing). A scan stores it in attendance_records.device_id
 * (database/supabase_scan_device.sql), and a scan from a device that already checked in ANOTHER
 * student for the same session is refused: that stops a student signing in as an absent friend on
 * their own phone. The honest case (dead or borrowed phone) is handled by the teacher's
 * Absent -> Present override. Absent rows and overrides have no device ID, so they never count.
 *
 * Not a fingerprint and no personal data: only "this browser". A private window, cleared cookies
 * or another browser get a fresh ID, so it is a deterrent, not proof.
 * Test: php attendance-system/tests/device_check_test.php
 * ------------------------------------------------------------
 */
const DEVICE_COOKIE = 'qr_device';
const DEVICE_COOKIE_DAYS = 400;   // browsers cap cookie lifetime at about 400 days

/** True for a device ID this app could have issued (32 lowercase hex characters). */
function is_valid_device_id($value): bool {
    return is_string($value) && preg_match('/^[0-9a-f]{32}$/D', $value) === 1;
}

/**
 * This browser's device ID from the cookie. A missing or malformed cookie is replaced with a new
 * random ID (when $create is true) and sent back, so call this before any output.
 */
function current_device_id(bool $create = true): ?string {
    $id = $_COOKIE[DEVICE_COOKIE] ?? null;
    if (is_valid_device_id($id)) return $id;
    if (!$create) return null;

    $id = bin2hex(random_bytes(16));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if (!headers_sent()) {
        setcookie(DEVICE_COOKIE, $id, [
            'expires' => time() + DEVICE_COOKIE_DAYS * 86400,
            'path' => defined('BASE_URL') ? BASE_URL : '/',
            'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[DEVICE_COOKIE] = $id;
    return $id;
}

/**
 * The name of another student this device already checked in for the session, or null when the
 * scan may go ahead (no or malformed device ID, nobody else, or only this same student).
 */
function device_conflict(PDO $pdo, int $sessionId, ?string $deviceId, int $studentId): ?string {
    if (!is_valid_device_id($deviceId)) return null;
    $st = $pdo->prepare('
        SELECT s.full_name FROM attendance_records ar
        JOIN students s ON s.student_id = ar.student_id
        WHERE ar.session_id = ? AND ar.device_id = ? AND ar.student_id <> ?
        ORDER BY ar.time_in LIMIT 1
    ');
    $st->execute([$sessionId, $deviceId, $studentId]);
    $name = $st->fetchColumn();
    return $name === false ? null : (string) $name;
}
