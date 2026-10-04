<?php
/**
 * Database-backed PHP sessions for serverless hosting (Vercel).
 * Uses the `php_sessions` table created by database/supabase_schema.sql.
 */
function register_db_session_handler() {
    require_once __DIR__ . '/db_connect.php';
    $GLOBALS['pdo'] = $pdo = app_connect(); // reused by includes/db.php

    session_set_save_handler(new class($pdo) implements SessionHandlerInterface {
        private $pdo;
        function __construct($pdo) { $this->pdo = $pdo; }
        function open($p, $n): bool { return true; }
        function close(): bool { return true; }
        function read($id): string|false {
            $s = $this->pdo->prepare('SELECT data FROM php_sessions WHERE id = ?');
            $s->execute([$id]);
            $d = $s->fetchColumn();
            return $d === false ? '' : $d;
        }
        function write($id, $data): bool {
            $s = $this->pdo->prepare('INSERT INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)
                ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = EXCLUDED.updated_at');
            return $s->execute([$id, $data, time()]);
        }
        function destroy($id): bool {
            return $this->pdo->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]);
        }
        function gc($max): int|false {
            $s = $this->pdo->prepare('DELETE FROM php_sessions WHERE updated_at < ?');
            $s->execute([time() - $max]);
            return $s->rowCount();
        }
    }, true);
}
