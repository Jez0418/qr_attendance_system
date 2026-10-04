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
        private $readData = null;
        private $readAt = 0;
        function __construct($pdo) { $this->pdo = $pdo; }
        function open($p, $n): bool { return true; }
        function close(): bool { return true; }
        function read($id): string|false {
            $s = $this->pdo->prepare('SELECT data, updated_at FROM php_sessions WHERE id = ?');
            $s->execute([$id]);
            $row = $s->fetch();
            $this->readData = $row ? $row['data'] : null;
            $this->readAt = $row ? (int) $row['updated_at'] : 0;
            return $row ? $row['data'] : '';
        }
        function write($id, $data): bool {
            // Skip the database round trip when nothing changed (refresh the
            // timestamp only every 5 minutes so the session doesn't expire).
            if ($this->readData !== null && $data === $this->readData && time() - $this->readAt < 300) {
                return true;
            }
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
