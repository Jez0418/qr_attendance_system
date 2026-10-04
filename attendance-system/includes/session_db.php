<?php
/**
 * Database-backed PHP sessions for serverless hosting (Vercel).
 * The `php_sessions` table is created automatically on first use.
 */
function register_db_session_handler() {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if (DB_SSL) {
        $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        $opts[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    $pdo->exec('CREATE TABLE IF NOT EXISTS php_sessions (
        id VARCHAR(128) PRIMARY KEY,
        data MEDIUMTEXT NOT NULL,
        updated_at INT NOT NULL
    )');

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
            $s = $this->pdo->prepare('REPLACE INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)');
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
