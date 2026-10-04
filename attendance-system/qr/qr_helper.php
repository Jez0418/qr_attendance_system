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
 *   { "session_id": 12, "token": "...", "sig": "..." }
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

// Secret key used only for signing QR payloads. Change this in production.
define('QR_SECRET_KEY', 'CHANGE_THIS_SECRET_QR_SIGNING_KEY_2026');

/** Build the signed payload for one active attendance session. */
function qr_build_session_payload($sessionId, $token) {
    $sig = hash_hmac('sha256', 'session|' . $sessionId . '|' . $token, QR_SECRET_KEY);
    return json_encode(['session_id' => (int) $sessionId, 'token' => $token, 'sig' => $sig]);
}

/** Returns ['session_id' => int, 'token' => string] on success, or false. */
function qr_parse_session_payload($raw) {
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['session_id']) || empty($data['token']) || empty($data['sig'])) {
        return false;
    }
    $expected = hash_hmac('sha256', 'session|' . $data['session_id'] . '|' . $data['token'], QR_SECRET_KEY);
    if (!hash_equals($expected, (string) $data['sig'])) {
        return false;
    }
    return ['session_id' => (int) $data['session_id'], 'token' => (string) $data['token']];
}
