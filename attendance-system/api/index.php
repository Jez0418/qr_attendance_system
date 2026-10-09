<?php
/**
 * Vercel front controller: maps the requested URL to the matching PHP
 * file in the project and runs it (Vercel's PHP runtime has one entrypoint).
 */
$root = realpath(__DIR__ . '/..');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$path = '/' . ltrim($path, '/');
if (substr($path, -1) === '/') {
    $path .= 'index.php';
}

$file = realpath($root . $path);
// Checked against the RESOLVED file, not the URL: /admin/../includes/x.php or /./includes/x.php
// would otherwise slip past a prefix check on the raw path.
$blocked = ['/includes/', '/database/', '/api/', '/tests/', '/qr/session_manager.php', '/qr/qr_helper.php'];
$denied = true;
if ($file && strpos($file, $root . DIRECTORY_SEPARATOR) === 0) {
    $relative = '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
    $denied = false;
    foreach ($blocked as $b) {
        if (strpos($relative, $b) === 0) { $denied = true; }
    }
}

if ($denied || pathinfo($file, PATHINFO_EXTENSION) !== 'php' || !is_file($file)) {
    http_response_code(404);
    echo '404 - Not Found';
    exit;
}

$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME']     = $path;
$_SERVER['PHP_SELF']        = $path;
chdir(dirname($file));
require $file;
