<?php
/**
 * ------------------------------------------------------------
 * qr/qr_helper.php
 * Builds and validates the QR payload for an attendance SESSION.
 *
 * No permanent or unrestricted QR codes are used anywhere in this
 * system. Every time a teacher or admin opens attendance for a
 * class, a brand-new, cryptographically random token is generated
 * (qr/session_manager.php) and signed into a small JSON payload:
 *
 *   { "session_id": 12, "token": "...", "w": 59999999, "sig": "..." }
 *
 * "w" is the 30-second time window the code was issued in (signed). The
 * on-screen code is re-issued every few seconds and a scan is only accepted
 * for the current window or the two before it, so a photo of the screen
 * stops working within about a minute.
 *
 * The payload contains no student information. It stops being valid
 * the moment that session closes or expires, and a token is never
 * reused across sessions — so an old screenshot can never be reused
 * for a later class meeting. Student-facing validation
 * (student/ajax_scan.php) re-checks the signature, the session's
 * active/expired state, enrollment, meeting date, time window, and
 * GPS geofence on every single scan.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/config.php';   // DB_* constants (used for the fallback key)

// Secret key used only for signing QR payloads. Set the QR_SECRET_KEY environment variable
// (a long random string) in Vercel. If it is missing, a key is derived from the database
// credentials so it is still private (never a value published in the repository).
define('QR_SECRET_KEY', (function () {
    $key = getenv('QR_SECRET_KEY');
    if ($key !== false && strlen($key) >= 16) return $key;
    return hash_hmac('sha256', 'qr-payload-signing-v1', DB_USER . '|' . DB_PASS . '|' . DB_HOST);
})());

// The QR rotates: each payload carries the time window it was issued in (signed), and a scan is
// only accepted for the current window or the QR_GRACE_WINDOWS before it. A photo of the screen
// is therefore useless after about a minute, so it can't be forwarded to someone outside the room.
const QR_WINDOW_SECONDS = 30;
const QR_GRACE_WINDOWS  = 2;   // covers scan + upload time, so a scan is valid for 60-90 s after the code was shown
const QR_REFRESH_SECONDS = 15; // how often the teacher/admin screen fetches a fresh code

function qr_window($time = null) {
    return intdiv($time ?? time(), QR_WINDOW_SECONDS);
}

function qr_sign($sessionId, $token, $window) {
    return hash_hmac('sha256', 'session|' . (int) $sessionId . '|' . $token . '|' . (int) $window, QR_SECRET_KEY);
}

/** Build the signed payload for one active attendance session (valid for the current time window). */
function qr_build_session_payload($sessionId, $token, $time = null) {
    $w = qr_window($time);
    return json_encode(['session_id' => (int) $sessionId, 'token' => $token, 'w' => $w, 'sig' => qr_sign($sessionId, $token, $w)]);
}

/** Returns ['session_id' => int, 'token' => string] on success, or false (bad, forged or stale payload). */
function qr_parse_session_payload($raw, $time = null) {
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['session_id']) || empty($data['token']) || empty($data['sig']) || !isset($data['w'])) {
        return false;
    }
    if (!is_int($data['w']) && !ctype_digit((string) $data['w'])) return false;
    $w = (int) $data['w'];
    $now = qr_window($time);
    if ($w > $now || $w < $now - QR_GRACE_WINDOWS) return false;
    if (!hash_equals(qr_sign($data['session_id'], $data['token'], $w), (string) $data['sig'])) {
        return false;
    }
    return ['session_id' => (int) $data['session_id'], 'token' => (string) $data['token']];
}
