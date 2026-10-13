<?php
/**
 * ------------------------------------------------------------
 * qr/qr_helper.php
 * Builds and validates the QR payload that students scan.
 *
 * Each LABORATORY has one permanent QR code, printed and posted on its
 * wall. The payload names only the lab and its code version and is signed
 * with QR_SECRET_KEY (HMAC), so it cannot be forged or edited:
 *
 *   { "t": "lab", "lab": 3, "v": 1, "sig": "..." }
 *
 * The payload says nothing about a class or a student. The server finds
 * the meeting itself: the class that is ACTIVE in that laboratory right
 * now (student/ajax_scan.php -> resolve_lab_occurrence()), then re-checks
 * enrollment, the session's open/closed state, the lab's GPS radius and
 * accuracy, late grace and the one-phone rule on every single scan.
 *
 * A code is revoked by bumping the lab's version (admin/qr_management.php
 * "Regenerate"): every printout of the old version stops working at once.
 * The signing key must stay the same for printed codes to keep working.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/config.php';   // DB_* constants (used for the fallback key)

// Secret key used only for signing QR payloads. Set the QR_SECRET_KEY environment variable
// (a long random string) in Vercel. If it is missing, a key is derived from the database
// credentials so it is still private (never a value published in the repository).
/** True when QR_SECRET_KEY is set (16+ chars). Otherwise the signing key is derived from the DB credentials. */
function qr_key_is_configured(): bool {
    $key = getenv('QR_SECRET_KEY');
    return $key !== false && strlen($key) >= 16;
}

define('QR_SECRET_KEY', (function () {
    if (qr_key_is_configured()) return getenv('QR_SECRET_KEY');
    // The fallback key changes whenever the DB password or host changes, which silently breaks every QR on screen.
    if (getenv('VERCEL')) error_log('WARNING: QR_SECRET_KEY is not set; QR codes are signed with a key derived from the DB credentials. Set QR_SECRET_KEY in Vercel.');
    return hash_hmac('sha256', 'qr-payload-signing-v1', DB_USER . '|' . DB_PASS . '|' . DB_HOST);
})());

/** HMAC over the lab id and its version. The 'lab|' prefix keeps it a different message from any other signature. */
function qr_lab_signature($labId, $version): string {
    return hash_hmac('sha256', 'lab|' . (int) $labId . '|' . (int) $version, QR_SECRET_KEY);
}

/** Build the signed, permanent payload printed for one laboratory. */
function qr_build_lab_payload($labId, $version): string {
    return json_encode(['t' => 'lab', 'lab' => (int) $labId, 'v' => (int) $version, 'sig' => qr_lab_signature($labId, $version)]);
}

/** Returns ['lab_id' => int, 'version' => int] when the payload is a genuine lab code, or false. */
function qr_parse_lab_payload($raw) {
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || ($data['t'] ?? null) !== 'lab'
        || !isset($data['lab'], $data['v'], $data['sig'])
        || !is_int($data['lab']) || !is_int($data['v']) || !is_string($data['sig'])
        || $data['lab'] < 1 || $data['v'] < 1) {
        return false;
    }
    if (!hash_equals(qr_lab_signature($data['lab'], $data['v']), $data['sig'])) {
        return false;
    }
    return ['lab_id' => $data['lab'], 'version' => $data['v']];
}
