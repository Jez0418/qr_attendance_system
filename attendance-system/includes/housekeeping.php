<?php
/**
 * ------------------------------------------------------------
 * includes/housekeeping.php
 * Deletes old rows that only grow: notifications, the activity log and rejected scans, so a whole
 * school stays inside the database plan. Attendance records, sessions and enrollments are NEVER deleted
 * (an override keeps who/when/why on the record itself).
 *
 * When: there is no cron job on Vercel, so it runs lazily from require_login() (includes/auth.php),
 * at most once a day. The settings row `housekeeping_ran_at` remembers the last run (its updated_at is
 * the throttle, like the absence marker's watermark), and an advisory lock keeps two requests from
 * doing it at once. Each rule deletes at most HOUSEKEEPING_BATCH rows per run, so a first run on a
 * big table never slows a page load; the rest goes on the following days.
 * Test: php attendance-system/tests/housekeeping_test.php
 * ------------------------------------------------------------
 */
const HOUSEKEEPING_KEY = 'housekeeping_ran_at';
const HOUSEKEEPING_INTERVAL_SECONDS = 86400;
const HOUSEKEEPING_BATCH = 5000;

/** What is deleted after how many days (decided 2026-10-11). Tables/columns/conditions are constants, never input. */
function retention_rules(): array {
    return [
        ['table' => 'notifications', 'column' => 'created_at',   'where' => 'is_read = 1', 'days' => 90],
        ['table' => 'notifications', 'column' => 'created_at',   'where' => 'is_read = 0', 'days' => 180],
        ['table' => 'activity_logs', 'column' => 'created_at',   'where' => '',            'days' => 365],
        ['table' => 'scan_attempts', 'column' => 'attempted_at', 'where' => '',            'days' => 180],
    ];
}

/**
 * Delete rows past their retention. Returns rows deleted per rule ('table where' => n), or [] when it
 * did not run (already ran in the last day, or another request holds the lock). $force skips the
 * once-a-day check (tests). Never throws: this runs on page loads and must not take a page down.
 */
function prune_old_rows(PDO $pdo, bool $force = false): array {
    try {
        if (!$force) {
            $st = $pdo->prepare('SELECT (updated_at > NOW() - CAST(? AS interval)) AS fresh FROM settings WHERE setting_key = ?');
            $st->execute([HOUSEKEEPING_INTERVAL_SECONDS . ' seconds', HOUSEKEEPING_KEY]);
            $fresh = $st->fetchColumn();
            if (in_array($fresh, [true, 't', 'true', '1', 1], true)) return [];
        }
        $pdo->beginTransaction();
        try {
            if (!$pdo->query("SELECT pg_try_advisory_xact_lock(hashtext('housekeeping'))")->fetchColumn()) { $pdo->rollBack(); return []; }
            $deleted = [];
            foreach (retention_rules() as $r) {
                $cond = $r['column'] . ' < NOW() - CAST(? AS interval)' . ($r['where'] !== '' ? ' AND ' . $r['where'] : '');
                $del = $pdo->prepare("DELETE FROM {$r['table']} WHERE ctid IN (SELECT ctid FROM {$r['table']} WHERE $cond LIMIT " . HOUSEKEEPING_BATCH . ')');
                $del->execute([$r['days'] . ' days']);
                $deleted[trim($r['table'] . ' ' . $r['where'])] = $del->rowCount();
            }
            set_setting($pdo, HOUSEKEEPING_KEY, date('Y-m-d H:i:s'));   // also refreshes updated_at (the once-a-day throttle)
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $deleted;
    } catch (Throwable $e) {
        error_log('prune_old_rows: ' . $e->getMessage());
        return [];
    }
}
