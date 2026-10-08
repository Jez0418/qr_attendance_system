<?php
/**
 * ------------------------------------------------------------
 * tests/demo_common.php
 * Shared by tests/demo_seed.php and tests/demo_cleanup.php (tests/ is not deployed).
 *
 * - Reads DB_* (and DEMO_PASSWORD) from attendance-system/.env when they are not already set
 *   in the environment. .env is git-ignored; never commit it.
 * - DemoPDO: the app's PDO with nested transactions. App functions that open their own
 *   transaction (override_absent_to_present(), ...) get a SAVEPOINT instead, so the whole
 *   seed or cleanup stays ONE transaction that --dry-run can roll back. If the outer
 *   transaction is ever rolled back, the connection refuses every further statement, so
 *   nothing can be written outside the transaction by accident.
 * ------------------------------------------------------------
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const DEMO_NUMBER_PREFIX = 'DEMO-';
const DEMO_EMAIL_DOMAIN = '@demo.qr-attendance.test';
const DEMO_TRACKING_TABLE = 'demo_data_rows';
const DEMO_CLASS_IDS = [1, 2, 4, 5, 6, 7];

// name, program, year, section, type, profile. Profile: 'absent' => [class => absences] (otherwise 0-1 per
// class at random), 'late' => chance of arriving after the grace period (default 10%).
const DEMO_STUDENTS = [
    ['Kristine Joy Manalo', 'BSCS', 3, '3A', 'regular', []],
    ['Rafael Domingo',      'BSCS', 3, '3A', 'regular', []],
    ['Bea Santiago',        'BSCS', 3, '3A', 'regular', []],
    ['Joshua Mendoza',      'BSCS', 3, '3A', 'regular', ['absent' => [1 => 3]]],          // absence limit (weekly class)
    ['Patricia Lim',        'BSCS', 3, '3A', 'regular', ['absent' => [1 => 0, 2 => 'rate']]], // below 80%
    ['Carlo Miguel Tan',    'BSCS', 3, '3A', 'regular', []],
    ['Danica Ocampo',       'BSCS', 3, '3A', 'regular', []],
    ['Miguel Fernandez',    'BSIT', 3, '3A', 'regular', []],
    ['Alyssa Navarro',      'BSIT', 3, '3A', 'regular', []],
    ['Jerome Pascual',      'BSIT', 3, '3A', 'regular', ['absent' => [5 => 4]]],          // absence limit
    ['Nicole Salazar',      'BSIT', 3, '3A', 'regular', []],
    ['Kevin Castro',        'BSIT', 3, '3A', 'regular', ['absent' => [4 => 2]]],          // 1 absence from limit
    ['Jasmine Torres',      'BSIT', 3, '3A', 'regular', []],
    ['Mark Anthony Gomez',  'BSIT', 3, '3A', 'regular', []],
    ['Shaira Flores',       'BSIT', 3, '3A', 'regular', []],
    ['Ivan Morales',        'BSIT', 3, '3A', 'regular', ['late' => 0.4]],
    ['Camille Robles',      'BSIT', 3, '3A', 'regular', ['transfer' => ['2026-08-21 10:05:00', '2026-08-24 09:30:00']]],
    ['Princess Garcia',     'BSN',  2, '2A', 'regular', []],
    ['John Paul Rivera',    'BSN',  2, '2A', 'regular', ['late' => 0.4]],
    ['Erika Gonzales',      'BSN',  2, '2A', 'regular', []],
    ['Angelica Dela Rosa',  'BSN',  2, '2A', 'regular', []],
    ['Mary Grace Valdez',   'BSN',  2, '2A', 'regular', []],
    ['Christian Soriano',   'BSN',  2, '2A', 'regular', ['absent' => [6 => 3]]],          // absence limit
    ['Hazel Javier',        'BSN',  2, '2A', 'regular', []],
    ['Paolo Velasco',       'BSIT', 4, '4A', 'irregular', []],
    ['Rica Samonte',        'BSCPE', 3, '3B', 'irregular', []],
    ['Leah Fajardo',        'BSMLS', 2, '2B', 'irregular', []],
    ['Dennis Abad',         'BSCRIM', 2, '2A', 'irregular', []],
    ['Trisha Galang',       'BSPH', 3, '3A', 'irregular', []],
    ['Bryan Cabrera',       'BSIT', 2, '2A', 'irregular', []],
];

(function () {
    $file = __DIR__ . '/../.env';
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if ($key !== '' && getenv($key) === false && $value !== '') putenv("$key=$value");
    }
})();

if (!getenv('DB_HOST') || getenv('DB_PASS') === false) {
    fwrite(STDERR, "Set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS and DB_SSL=1 (environment or attendance-system/.env).\n");
    exit(2);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule.php';

class DemoPDO extends AppPDO {
    private int $depth = 0;
    private bool $aborted = false;

    public function beginTransaction(): bool {
        $this->guard();
        if ($this->depth === 0) { parent::beginTransaction(); $this->depth = 1; return true; }
        $this->depth++;
        parent::exec('SAVEPOINT demo_sp' . $this->depth);
        return true;
    }

    public function commit(): bool {
        $this->guard();
        if ($this->depth === 0) throw new LogicException('commit() without a transaction');
        if ($this->depth === 1) { $this->depth = 0; return parent::commit(); }
        parent::exec('RELEASE SAVEPOINT demo_sp' . $this->depth);
        $this->depth--;
        return true;
    }

    public function rollBack(): bool {
        if ($this->depth === 0) throw new LogicException('rollBack() without a transaction');
        if ($this->depth === 1) {
            $this->depth = 0;
            $this->aborted = true;   // from here on nothing may run (it would autocommit)
            return parent::rollBack();
        }
        parent::exec('ROLLBACK TO SAVEPOINT demo_sp' . $this->depth);
        $this->depth--;
        return true;
    }

    public function inTransaction(): bool { return $this->depth > 0; }

    /** Undo everything: every savepoint and the outer transaction. */
    public function rollBackAll(): void {
        while ($this->depth > 0) $this->rollBack();
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) { $this->guard(); return parent::prepare($query, $options); }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs) { $this->guard(); return parent::query($query, $fetchMode, ...$fetchModeArgs); }
    #[\ReturnTypeWillChange]
    public function exec(string $statement) { $this->guard(); return parent::exec($statement); }

    private function guard(): void {
        if ($this->aborted) throw new RuntimeException('The transaction was rolled back; refusing to run more SQL.');
    }
}

/**
 * Connect with DemoPDO. Prepares are emulated client-side (as the app does on the 6543 pooler):
 * a native prepare costs three round trips (prepare, execute, deallocate) and from a laptop to the
 * Sydney database each trip is ~0.3 s, which made the seed take about an hour.
 */
function demo_connect(): DemoPDO {
    $pdo = app_connect(DemoPDO::class);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    return $pdo;
}

/** Does the tracking table exist (database/supabase_demo_data.sql)? */
function demo_tracking_ready(PDO $pdo): bool {
    return (bool) $pdo->query("SELECT to_regclass('public." . DEMO_TRACKING_TABLE . "') IS NOT NULL")->fetchColumn();
}

/** --dry-run or --commit (exactly one), else print usage and exit. */
function demo_mode(array $argv, string $usage): string {
    $dry = in_array('--dry-run', $argv, true);
    $commit = in_array('--commit', $argv, true);
    if ($dry === $commit) { fwrite(STDERR, $usage); exit(2); }
    return $dry ? 'dry-run' : 'commit';
}

/** Print rows as an aligned text table. */
function demo_table(array $rows, array $headers): void {
    if (!$rows) { echo "  (none)\n"; return; }
    $w = [];
    foreach ($headers as $i => $h) {
        $w[$i] = mb_strlen($h);
        foreach ($rows as $r) $w[$i] = max($w[$i], mb_strlen((string) array_values($r)[$i]));
    }
    $line = fn($cells) => '  ' . implode('  ', array_map(fn($c, $i) => str_pad((string) $c, $w[$i] + strlen((string) $c) - mb_strlen((string) $c)), $cells, array_keys($cells))) . "\n";
    echo $line($headers);
    echo '  ' . implode('  ', array_map(fn($n) => str_repeat('-', $n), $w)) . "\n";
    foreach ($rows as $r) echo $line(array_values($r));
}

/* ---------------- helpers ---------------- */

/** Progress line on stderr, with seconds since the start. */
function say(string $msg): void {
    global $started;
    fwrite(STDERR, sprintf("  [%3ds] %s\n", microtime(true) - $started, $msg));
}
function rnd(): float { return mt_rand() / mt_getrandmax(); }
function pick(array $items) { return $items[mt_rand(0, count($items) - 1)]; }
function at(string $when): DateTimeImmutable { return new DateTimeImmutable($when, schedule_tz()); }
function ts(DateTimeInterface $d): string { return $d->format('Y-m-d H:i:s'); }

/** A random point $minM..$maxM meters from (lat, lon). */
function point_near(float $lat, float $lon, float $minM, float $maxM): array {
    $d = $minM + rnd() * ($maxM - $minM);
    $b = rnd() * 2 * M_PI;
    return [round($lat + $d * cos($b) / 111320, 7), round($lon + $d * sin($b) / (111320 * cos(deg2rad($lat))), 7)];
}

/** Record rows in demo_data_rows so tests/demo_cleanup.php finds them. */
function track(PDO $pdo, string $table, array $ids): void {
    foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
        $pdo->prepare('INSERT INTO ' . DEMO_TRACKING_TABLE . ' (table_name, row_id) VALUES '
            . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ' ON CONFLICT DO NOTHING')
            ->execute(array_merge(...array_map(fn($id) => [$table, $id], $chunk)));
    }
}

/**
 * Give the rows the app's functions just created their real date. They were stamped with the
 * transaction's start time (CURRENT_TIMESTAMP is fixed for the whole transaction), a value no other
 * connection's rows can have, so this touches exactly our own rows. Notifications, activity logs and
 * sessions are also listed for the cleanup.
 */
function demo_stamp(PDO $pdo, string $when): void {
    $pdo->prepare("
        WITH t AS (SELECT CAST(? AS timestamp) AS at),
        n AS (UPDATE notifications SET created_at = (SELECT at FROM t) WHERE created_at = LOCALTIMESTAMP RETURNING notification_id),
        l AS (UPDATE activity_logs SET created_at = (SELECT at FROM t) WHERE created_at = LOCALTIMESTAMP RETURNING log_id),
        s AS (UPDATE attendance_sessions SET created_at = (SELECT at FROM t) WHERE created_at = LOCALTIMESTAMP RETURNING session_id),
        r AS (UPDATE attendance_records SET created_at = (SELECT at FROM t) WHERE created_at = LOCALTIMESTAMP RETURNING record_id)
        INSERT INTO " . DEMO_TRACKING_TABLE . " (table_name, row_id)
        SELECT 'notifications', notification_id FROM n
        UNION ALL SELECT 'activity_logs', log_id FROM l
        UNION ALL SELECT 'attendance_sessions', session_id FROM s
        ON CONFLICT DO NOTHING
    ")->execute([$when]);
}

/** Multi-row INSERT; returns the RETURNING column when given. */
function insert_rows(PDO $pdo, string $table, array $cols, array $rows, string $returning = ''): array {
    $out = [];
    foreach (array_chunk($rows, 200) as $chunk) {
        $st = $pdo->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES '
            . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($cols), '?')) . ')'))
            . ($returning ? " RETURNING $returning" : ''));
        $st->execute(array_merge(...array_map('array_values', $chunk)));
        if ($returning) array_push($out, ...$st->fetchAll(PDO::FETCH_COLUMN));
    }
    return $out;
}
