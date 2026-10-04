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
ini_set('display_errors', 1);

// ---- Start session (needed for auth / role checks) ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Timezone ----
date_default_timezone_set('Asia/Manila');

// ---- Database credentials (default XAMPP settings) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'qr_attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---- App constants ----
define('APP_NAME', 'MCNP QR Laboratory Attendance System');
// BASE_URL: change this if you rename the project folder in htdocs
define('BASE_URL', '/attendance-system/');
define('LATE_THRESHOLD_MINUTES', 15);
// Students may scan up to this many minutes BEFORE a class's start time
// (teachers often open attendance a little early).
define('ATTENDANCE_EARLY_GRACE_MINUTES', 30);
define('UPLOAD_DIR', __DIR__ . '/../uploads/photos/');
define('UPLOAD_URL', BASE_URL . 'uploads/photos/');
