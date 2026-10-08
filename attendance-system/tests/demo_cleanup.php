<?php
/**
 * ------------------------------------------------------------
 * tests/demo_cleanup.php
 * Removes the demo semester created by tests/demo_seed.php, and nothing else.
 *
 *   php attendance-system/tests/demo_cleanup.php --dry-run   show what would be deleted, ROLL BACK
 *   php attendance-system/tests/demo_cleanup.php --commit    delete it
 *
 * What goes:
 *   - every row listed in demo_data_rows (sessions with their records and failed scans, the demo
 *     schedule exceptions, activity log lines, notifications sent to teachers);
 *   - the demo accounts: a user is deleted only if it is a student whose email ends in
 *     @demo.qr-attendance.test AND whose student number starts with DEMO-. Their student row,
 *     enrollments, requests, attendance and notifications go with it (ON DELETE CASCADE), and so do
 *     any records the live app added since (demo students stay enrolled, so they collect absences);
 *   - their log lines and login sessions, and the teacher notices about them that the live app sent
 *     after the seed ("Absence Limit Reached", "New Enrollment Request" starting with a demo name).
 * The class-time fix (database/supabase_fix_class_times.sql) is not undone. One transaction.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/demo_common.php';
$mode = demo_mode($argv, "Usage: php attendance-system/tests/demo_cleanup.php --dry-run | --commit\n");

$pdo = demo_connect();
if (!demo_tracking_ready($pdo)) {
    fwrite(STDERR, "Table demo_data_rows is missing (database/supabase_demo_data.sql): nothing was seeded through it.\n");
    exit(2);
}
$pdo->beginTransaction();

try {
    $tracked = fn(string $table) => "SELECT row_id FROM " . DEMO_TRACKING_TABLE . " WHERE table_name = '$table'";
    $users = $pdo->prepare("
        SELECT u.user_id FROM users u JOIN students s ON s.user_id = u.user_id
        WHERE u.role = 'student' AND u.email LIKE ? AND s.student_number LIKE ?
    ");
    $users->execute(['%' . DEMO_EMAIL_DOMAIN, DEMO_NUMBER_PREFIX . '%']);
    $userIds = array_map('intval', $users->fetchAll(PDO::FETCH_COLUMN));
    $userArray = '{' . implode(',', $userIds ?: [0]) . '}';

    // A tracked user that fails the checks above is reported and kept, never deleted.
    $odd = $pdo->prepare("SELECT COUNT(*) FROM (" . $tracked('users') . ") t WHERE row_id <> ALL(CAST(? AS int[]))");
    $odd->execute([$userArray]);
    if ($keep = (int) $odd->fetchColumn()) echo "Note: $keep tracked user(s) are not demo students by email/number and are kept.\n";

    $deleted = [];
    $run = function (string $label, string $sql, array $params = []) use ($pdo, &$deleted) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $deleted[] = [$label, $st->rowCount()];
    };

    $run('activity_logs', "DELETE FROM activity_logs WHERE log_id IN (" . $tracked('activity_logs') . ") OR user_id = ANY(CAST(? AS int[]))", [$userArray]);
    $run('notifications (teachers, from the seed)', "DELETE FROM notifications WHERE notification_id IN (" . $tracked('notifications') . ")");
    $run('notifications (teachers, since the seed)', "
        DELETE FROM notifications n
        WHERE n.title IN ('Absence Limit Reached', 'New Enrollment Request')
          AND EXISTS (SELECT 1 FROM students s WHERE s.user_id = ANY(CAST(? AS int[])) AND n.message LIKE s.full_name || ' %')",
        [$userArray]);
    $run('attendance_sessions (+ their records and failed scans)', "DELETE FROM attendance_sessions WHERE session_id IN (" . $tracked('attendance_sessions') . ")");
    $run('schedule_exceptions', "DELETE FROM schedule_exceptions WHERE exception_id IN (" . $tracked('schedule_exceptions') . ")");
    foreach ($userIds as $id) destroy_user_sessions($pdo, $id);   // signed-in demo students are logged out
    $run('users (+ students, enrollments, requests, attendance, notifications)', "DELETE FROM users WHERE user_id = ANY(CAST(? AS int[]))", [$userArray]);
    $run('demo_data_rows', "DELETE FROM " . DEMO_TRACKING_TABLE);

    echo "\nDeleted:\n";
    demo_table($deleted, ['what', 'rows']);

    if ($mode === 'commit') {
        $pdo->commit();
        echo "\nCOMMITTED: the demo data is gone.\n";
    } else {
        $pdo->rollBackAll();
        echo "\nDRY RUN: rolled back, nothing deleted.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBackAll();
    fwrite(STDERR, "\nFAILED, nothing was deleted: " . $e->getMessage() . "\n");
    exit(1);
}
