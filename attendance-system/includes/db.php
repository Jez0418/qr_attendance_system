<?php
/**
 * ------------------------------------------------------------
 * db.php
 * Creates the global $pdo PDO connection object (PostgreSQL /
 * Supabase). Using PDO + prepared statements everywhere prevents
 * SQL injection. See db_connect.php for the MySQL->Postgres SQL
 * compatibility layer.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';

try {
    // On Vercel the session handler may already have opened a connection.
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        $pdo = app_connect();
    }
} catch (PDOException $e) {
    // The real reason (host, user, SQLSTATE) goes to the server log only, never to the browser.
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('<div style="font-family:sans-serif;padding:40px;color:#b91c1c">
            <h2>Service temporarily unavailable</h2>
            <p>The system could not reach its database. Please try again in a few minutes.</p>
            <p style="color:#64748b;font-size:14px">If this keeps happening, contact the system administrator.
            (Administrators: check the <code>DB_*</code> settings and the server log.)</p>
        </div>');
}
