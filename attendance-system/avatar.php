<?php
/**
 * avatar.php - the logged-in user's own profile photo as an image (see includes/avatar.php).
 * Linked as avatar.php?v=<hash>; the hash changes with the photo, so the browser may keep this
 * response for a year. "private": shared caches/CDNs must never store someone's photo.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/avatar.php';
require_login();

$table = photo_table_for_role($_SESSION['role'] ?? '');
$photo = '';
if ($table && !empty($_SESSION['profile_id'])) {
    $st = $pdo->prepare("SELECT photo FROM {$table[0]} WHERE {$table[1]} = ?");
    $st->execute([$_SESSION['profile_id']]);
    $photo = (string) $st->fetchColumn();
}

if ($photo === '') {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}
// Older rows hold a filename under uploads/photos/ (local installs only).
if (strpos($photo, 'data:image/') !== 0) {
    header('Location: ' . UPLOAD_URL . rawurlencode(basename($photo)));
    exit;
}
if (!preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $photo, $m) || ($bytes = base64_decode($m[2], true)) === false) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

$etag = '"' . md5($photo) . '"';
header_remove('Pragma');    // session_start() sends no-cache headers; this response is meant to be cached
header_remove('Expires');
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: ' . $etag);
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $m[1]);
header('Content-Length: ' . strlen($bytes));
echo $bytes;
