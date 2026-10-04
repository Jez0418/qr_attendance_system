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
    die('<div style="font-family:sans-serif;padding:40px;color:#b91c1c">
            <h2>Database Connection Failed</h2>
            <p>' . htmlspecialchars($e->getMessage()) . '</p>
            <p>Check the <code>DB_*</code> settings (<code>includes/config.php</code> or your
            environment variables) and make sure <code>database/supabase_schema.sql</code>
            has been run on the database.</p>
        </div>');
}
