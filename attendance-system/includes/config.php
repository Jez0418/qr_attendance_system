<?php
/**
 * ------------------------------------------------------------
 * config.php
 * Global configuration, session bootstrap and app constants.
 * This file must be included FIRST (before any HTML output)
 * on every page because it starts the PHP session.
 * ------------------------------------------------------------
 */

// ---- Error reporting (set display_errors to 0 in production) ----
error_reporting(E_ALL);
$ON_VERCEL = (bool) getenv('VERCEL');
ini_set('display_errors', $ON_VERCEL ? 0 : 1);


// ---- Timezone ----
date_default_timezone_set('Asia/Manila');

// ---- Database credentials (default XAMPP settings) ----
// PostgreSQL (Supabase). Set these as environment variables (Vercel) or
// edit the fallbacks below for local development.
//   DB_HOST  Supabase pooler host, e.g. aws-0-xx.pooler.supabase.com
//   DB_PORT  5432 (session pooler / direct) or 6543 (transaction pooler)
//   DB_USER  postgres.<project-ref> on the pooler, or postgres when direct
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_SSL',  getenv('DB_SSL') !== false ? (bool) getenv('DB_SSL') : (bool) getenv('VERCEL'));

// ---- App constants ----
define('APP_NAME', 'MCNP-ISAP QR Laboratory Attendance System');
// BASE_URL: change this if you rename the project folder in htdocs
define('BASE_URL', getenv('VERCEL') ? '/' : '/attendance-system/');
// Late grace period: Admin > Settings (settings.late_grace_minutes, default 15).
define('UPLOAD_DIR', __DIR__ . '/../uploads/photos/');
define('UPLOAD_URL', BASE_URL . 'uploads/photos/');

// ---- Browser security headers (CSP, frame blocking, nosniff, HSTS over HTTPS) ----
require_once __DIR__ . '/security_headers.php';
send_security_headers((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// ---- Start session (needed for auth / role checks) ----
// Serverless instances have no shared disk, so on Vercel sessions are
// stored in the database (see includes/session_db.php).
if (session_status() === PHP_SESSION_NONE) {
    // Harden the login cookie: not readable by JavaScript (HttpOnly), HTTPS-only when served over
    // HTTPS (Secure), not sent on cross-site requests (SameSite=Lax), and no session IDs from URLs.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    if ($ON_VERCEL) {
        require_once __DIR__ . '/session_db.php';
        register_db_session_handler();
    }
    session_start();
}
