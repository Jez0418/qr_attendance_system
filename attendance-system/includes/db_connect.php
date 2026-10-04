<?php
/**
 * ------------------------------------------------------------
 * db_connect.php
 * PostgreSQL / Supabase connection helpers (no side effects). Using PDO + prepared statements everywhere prevents
 * SQL injection.
 *
 * The app's queries were written in MySQL style, so $pdo is a thin
 * PDO subclass that rewrites the few MySQL-only constructs into their
 * PostgreSQL equivalents before sending (see pg_compat_sql()).
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

/** Rewrite MySQL-flavoured SQL into PostgreSQL. */
function pg_compat_sql($sql) {
    // "double-quoted" string literals -> 'single-quoted' (Postgres reads "x" as an identifier)
    $sql = preg_replace('/"([^"\'\\\\]*)"/', "'$1'", $sql);
    // CURDATE() -> CURRENT_DATE ; DATE_SUB/DATE_ADD(x, INTERVAL n UNIT)
    $sql = preg_replace('/\bCURDATE\(\)/i', 'CURRENT_DATE', $sql);
    $sql = preg_replace('/\bDATE_SUB\(\s*(.+?)\s*,\s*INTERVAL\s+(\d+)\s+(\w+?)S?\s*\)/i', "($1 - INTERVAL '$2 $3')", $sql);
    $sql = preg_replace('/\bDATE_ADD\(\s*(.+?)\s*,\s*INTERVAL\s+(\d+)\s+(\w+?)S?\s*\)/i', "($1 + INTERVAL '$2 $3')", $sql);
    // MySQL LIKE is case-insensitive by default; Postgres' is not
    $sql = preg_replace('/\bLIKE\b/i', 'ILIKE', $sql);
    // SUM(col = 'x')  (boolean sum) -> SUM((col = 'x')::int)
    $sql = preg_replace('/\bSUM\(\s*([\w.]+\s*=\s*\'[^\']*\')\s*\)/i', 'SUM(($1)::int)', $sql);
    return $sql;
}

class AppPDO extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) {
        return parent::prepare(pg_compat_sql($query), $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs) {
        $query = pg_compat_sql($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    #[\ReturnTypeWillChange]
    public function exec(string $statement) {
        return parent::exec(pg_compat_sql($statement));
    }
}

/** Open a connection (shared by the app and the DB session handler). */
function app_connect() {
    $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME
         . (DB_SSL ? ';sslmode=require' : '');
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Supabase's transaction pooler (port 6543) has no prepared-statement
        // support, so emulate them client-side in that case.
        PDO::ATTR_EMULATE_PREPARES   => (DB_PORT == 6543),
    ];
    $pdo = new AppPDO($dsn, DB_USER, DB_PASS, $opts);
    $pdo->exec("SET TIME ZONE 'Asia/Manila'"); // keep NOW()/CURRENT_DATE in the app's timezone
    return $pdo;
}
