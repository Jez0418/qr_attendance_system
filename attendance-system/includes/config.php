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

// ---- Start session (needed for auth / role checks) ----
// Serverless instances have no shared disk, so on Vercel sessions are
// stored in the database (see includes/session_db.php).
if (session_status() === PHP_SESSION_NONE) {
    if ($ON_VERCEL) {
        require_once __DIR__ . '/session_db.php';
        register_db_session_handler();
    }
    session_start();
}

// ---- Timezone ----
date_default_timezone_set('Asia/Manila');

// ---- Database credentials (default XAMPP settings) ----
// On Vercel set DB_HOST / DB_NAME / DB_USER / DB_PASS (and optionally
// DB_PORT, DB_SSL=1) as environment variables.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'qr_attendance_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_SSL',  (bool) getenv('DB_SSL'));
define('DB_CHARSET', 'utf8mb4');

// ---- App constants ----
define('APP_NAME', 'MCNP QR Laboratory Attendance System');
// BASE_URL: change this if you rename the project folder in htdocs
define('BASE_URL', getenv('VERCEL') ? '/' : '/attendance-system/');
define('LATE_THRESHOLD_MINUTES', 15);
// Students may scan up to this many minutes BEFORE a class's start time
// (teachers often open attendance a little early).
define('ATTENDANCE_EARLY_GRACE_MINUTES', 30);
define('UPLOAD_DIR', __DIR__ . '/../uploads/photos/');
define('UPLOAD_URL', BASE_URL . 'uploads/photos/');
