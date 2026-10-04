<?php
/**
 * ------------------------------------------------------------
 * db.php
 * Creates the global $pdo PDO connection object.
 * Using PDO + prepared statements everywhere prevents SQL injection.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
    ]);
} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;padding:40px;color:#b91c1c">
            <h2>Database Connection Failed</h2>
            <p>' . htmlspecialchars($e->getMessage()) . '</p>
            <p>Check <code>includes/config.php</code> and make sure the
            <code>qr_attendance_system</code> database has been imported via phpMyAdmin
            (see <code>database/schema.sql</code>).</p>
        </div>');
}
