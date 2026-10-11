<?php
/**
 * Run: php attendance-system/tests/housekeeping_test.php
 *      php attendance-system/tests/housekeeping_test.php --db   also runs against the DB from DB_* env vars (attendance-system/.env),
 *                                                              inside a transaction that is rolled back (it hits the LIVE database).
 * Old rows are deleted once a day (includes/housekeeping.php). Attendance records are never touched.
 * tests/ is not deployed (see .vercelignore).
 */
$useDb = in_array('--db', $argv, true);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/housekeeping.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

// ---- The retention rules (user decision 2026-10-11)
$rules = [];
foreach (retention_rules() as $r) $rules[$r['table'] . ($r['where'] !== '' ? ' ' . $r['where'] : '')] = $r['days'];
check('read notifications kept 90 days', ($rules['notifications is_read = 1'] ?? null) === 90);
check('unread notifications kept 180 days', ($rules['notifications is_read = 0'] ?? null) === 180);
check('activity log kept 1 year', ($rules['activity_logs'] ?? null) === 365);
check('rejected scans kept 180 days', ($rules['scan_attempts'] ?? null) === 180);
check('exactly these four rules', count($rules) === 4);
$tables = array_unique(array_column(retention_rules(), 'table'));
foreach (['attendance_records', 'attendance_sessions', 'enrollments', 'users', 'students'] as $t) {
    check("$t is never pruned", !in_array($t, $tables, true));
}
check('runs from require_login (no cron on Vercel)', strpos(file_get_contents(__DIR__ . '/../includes/auth.php'), 'prune_old_rows(') !== false);

// ---- Database (rolled back): old rows go, recent ones stay. DemoPDO turns prune_old_rows()'s own
// transaction into a SAVEPOINT inside ours.
if ($useDb) {
    require_once __DIR__ . '/demo_common.php';
    echo "\n== Database (DB_HOST=" . getenv('DB_HOST') . "), rolled back afterwards\n";
    $pdo = demo_connect();
    $pdo->beginTransaction();
    try {
        $userId = (int) $pdo->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn();
        $note = $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, 'hk test', 'hk test', ?, NOW() - CAST(? AS interval)) RETURNING notification_id");
        $ids = [];
        foreach (['read100' => [1, '100 days'], 'read80' => [1, '80 days'], 'unread100' => [0, '100 days'], 'unread200' => [0, '200 days']] as $k => [$read, $age]) {
            $note->execute([$userId, $read, $age]);
            $ids[$k] = (int) $note->fetchColumn();
        }
        $log = $pdo->prepare("INSERT INTO activity_logs (user_id, action, created_at) VALUES (?, 'hk test', NOW() - CAST(? AS interval)) RETURNING log_id");
        $log->execute([$userId, '400 days']); $ids['log400'] = (int) $log->fetchColumn();
        $log->execute([$userId, '300 days']); $ids['log300'] = (int) $log->fetchColumn();
        $records = (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();

        $deleted = prune_old_rows($pdo, true);
        check('prune_old_rows reports what it deleted', is_array($deleted) && array_sum($deleted) >= 3);

        $exists = fn(string $table, string $col, int $id) => (bool) $pdo->query("SELECT 1 FROM $table WHERE $col = $id")->fetchColumn();
        check('read notification of 100 days is deleted', !$exists('notifications', 'notification_id', $ids['read100']));
        check('read notification of 80 days is kept', $exists('notifications', 'notification_id', $ids['read80']));
        check('unread notification of 100 days is kept', $exists('notifications', 'notification_id', $ids['unread100']));
        check('unread notification of 200 days is deleted', !$exists('notifications', 'notification_id', $ids['unread200']));
        check('activity log of 400 days is deleted', !$exists('activity_logs', 'log_id', $ids['log400']));
        check('activity log of 300 days is kept', $exists('activity_logs', 'log_id', $ids['log300']));
        check('attendance records untouched', (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn() === $records);
        check('a second run the same day does nothing', prune_old_rows($pdo) === []);
    } finally {
        $pdo->rollBackAll();
    }
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
