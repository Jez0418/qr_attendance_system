<?php
/**
 * ------------------------------------------------------------
 * tests/demo_set_password.php
 * Sets the password of every demo account to DEMO_PASSWORD (environment or the git-ignored attendance-system/.env),
 * stored as a bcrypt hash. tests/ is not deployed.
 *
 *   php attendance-system/tests/demo_set_password.php --dry-run   say how many accounts would change, ROLL BACK
 *   php attendance-system/tests/demo_set_password.php --commit    change them
 *
 * Only demo students are touched: the same test as tests/demo_cleanup.php (a student whose email ends in
 * @demo.qr-attendance.test AND whose student number starts with DEMO-). The password is never printed.
 * Like every password change in the app, it signs the accounts out (destroy_user_sessions()), and it clears
 * their failed-login counters so a lockout does not outlive the reset (login_attempts). One transaction.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/demo_common.php';
$mode = demo_mode($argv, "Usage: php attendance-system/tests/demo_set_password.php --dry-run | --commit\n");

$password = (string) getenv('DEMO_PASSWORD');
if (strlen($password) < 8) {
    fwrite(STDERR, "Set DEMO_PASSWORD (at least 8 characters) in the environment or attendance-system/.env.\n");
    exit(2);
}

$pdo = demo_connect();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare("
        SELECT u.user_id, u.username, u.password FROM users u JOIN students s ON s.user_id = u.user_id
        WHERE u.role = 'student' AND u.email LIKE ? AND s.student_number LIKE ? ORDER BY u.user_id
    ");
    $st->execute(['%' . DEMO_EMAIL_DOMAIN, DEMO_NUMBER_PREFIX . '%']);
    $accounts = $st->fetchAll();
    if (!$accounts) throw new RuntimeException('There are no demo accounts.');

    $stale = array_values(array_filter($accounts, fn($a) => !password_verify($password, $a['password'])));
    echo count($accounts) . ' demo accounts, ' . (count($accounts) - count($stale)) . " already use the DEMO_PASSWORD in .env, " . count($stale) . " would change.\n";
    if (!$stale) {
        echo "Nothing to do. To use a different password, change DEMO_PASSWORD in attendance-system/.env and run this again.\n";
        $pdo->rollBackAll();
        exit(0);
    }

    $ids = $hashes = [];
    foreach ($stale as $a) {
        $ids[] = (int) $a['user_id'];
        $hashes[] = '"' . password_hash($password, PASSWORD_BCRYPT) . '"';   // bcrypt output has no quote or backslash
    }
    $pdo->prepare('UPDATE users u SET password = v.pw, updated_at = NOW()
                   FROM unnest(CAST(? AS int[]), CAST(? AS text[])) AS v(id, pw) WHERE u.user_id = v.id')
        ->execute(['{' . implode(',', $ids) . '}', '{' . implode(',', $hashes) . '}']);
    foreach ($ids as $id) destroy_user_sessions($pdo, $id);
    $cleared = $pdo->prepare('DELETE FROM login_attempts WHERE username_key = ANY(CAST(? AS text[]))');
    $cleared->execute(['{' . implode(',', array_map(fn($a) => '"' . strtolower($a['username']) . '"', $accounts)) . '}']);
    echo 'Failed-login counters cleared: ' . $cleared->rowCount() . "\n";

    // Read back what was written, so a dry run proves the new hashes verify.
    $check = $pdo->prepare('SELECT password FROM users WHERE user_id = ANY(CAST(? AS int[]))');
    $check->execute(['{' . implode(',', $ids) . '}']);
    $bad = count(array_filter($check->fetchAll(PDO::FETCH_COLUMN), fn($h) => !password_verify($password, $h)));
    if ($bad) throw new RuntimeException("$bad new hashes do not verify against DEMO_PASSWORD");

    if ($mode === 'commit') {
        $pdo->commit();
        echo "COMMITTED: " . count($ids) . " demo accounts now use the DEMO_PASSWORD from .env (all verified).\n";
    } else {
        $pdo->rollBackAll();
        echo "DRY RUN: rolled back, nothing changed (the new hashes verified).\n";
    }
} catch (Throwable $e) {
    $pdo->rollBackAll();
    fwrite(STDERR, 'FAILED, nothing was changed: ' . $e->getMessage() . "\n");
    exit(1);
}
