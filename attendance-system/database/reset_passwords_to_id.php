<?php
/**
 * ------------------------------------------------------------
 * reset_passwords_to_id.php
 * Sets every user's password to their own ID number:
 *   - Admin    -> a fixed Admin ID (ADM-0001)
 *   - Teacher  -> their employee_number
 *   - Student  -> their student_number
 *
 * Run this ONCE in your browser after importing schema.sql:
 *   http://localhost/attendance-system/database/reset_passwords_to_id.php
 * Delete this file afterwards (or move it out of the webroot) —
 * it's a setup utility, not meant to stay publicly reachable.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/db.php';

// Fixed ID used for every admin account (there's no separate "admin ID"
// column in the schema, so we standardize on this value).
const ADMIN_ID = 'ADM-0001';

$updated = [];
$errors = [];

try {
    $pdo->beginTransaction();

    // --- Admins ---
    $admins = $pdo->query("SELECT user_id, username FROM users WHERE role = 'admin'")->fetchAll();
    foreach ($admins as $a) {
        $hash = password_hash(ADMIN_ID, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([$hash, $a['user_id']]);
        $updated[] = ['role' => 'Admin', 'username' => $a['username'], 'password' => ADMIN_ID];
    }

    // --- Teachers ---
    $teachers = $pdo->query("
        SELECT u.user_id, u.username, t.employee_number
        FROM users u JOIN teachers t ON t.user_id = u.user_id
        WHERE u.role = 'teacher'
    ")->fetchAll();
    foreach ($teachers as $t) {
        $hash = password_hash($t['employee_number'], PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([$hash, $t['user_id']]);
        $updated[] = ['role' => 'Teacher', 'username' => $t['username'], 'password' => $t['employee_number']];
    }

    // --- Students ---
    $students = $pdo->query("
        SELECT u.user_id, u.username, s.student_number
        FROM users u JOIN students s ON s.user_id = u.user_id
        WHERE u.role = 'student'
    ")->fetchAll();
    foreach ($students as $s) {
        $hash = password_hash($s['student_number'], PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([$hash, $s['user_id']]);
        $updated[] = ['role' => 'Student', 'username' => $s['username'], 'password' => $s['student_number']];
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Passwords to ID Numbers</title>
<style>
    body{font-family:Segoe UI,Arial,sans-serif;background:#f4f6f9;padding:40px;color:#1f2937}
    .card{background:#fff;max-width:720px;margin:0 auto;padding:24px 28px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
    h2{margin-top:0}
    table{width:100%;border-collapse:collapse;margin-top:16px;font-size:13.5px}
    th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #eef1f5}
    th{background:#f8fafc;font-size:12px;text-transform:uppercase;color:#64748b}
    code{background:#eef1f5;padding:2px 6px;border-radius:4px}
    .ok{color:#15803d;font-weight:600}
    .fail{color:#b91c1c;font-weight:600}
    .box{background:#eff6ff;border:1px solid #dbeafe;border-radius:8px;padding:12px;margin-top:16px;font-size:13.5px}
</style>
</head>
<body>
<div class="card">
    <h2>Password Reset Utility</h2>
    <?php if ($errors): ?>
        <p class="fail">Something went wrong:</p>
        <ul><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
    <?php else: ?>
        <p class="ok">✔ Done! <?php echo count($updated); ?> account(s) updated — each user's password is now their own ID number.</p>
        <table>
            <tr><th>Role</th><th>Username</th><th>New Password</th></tr>
            <?php foreach ($updated as $u): ?>
                <tr><td><?php echo htmlspecialchars($u['role']); ?></td><td><code><?php echo htmlspecialchars($u['username']); ?></code></td><td><code><?php echo htmlspecialchars($u['password']); ?></code></td></tr>
            <?php endforeach; ?>
        </table>
        <div class="box">
            Going forward, whenever a new Student or Teacher is added through the
            Admin panel, their password is whatever was typed into the "Password"
            field at creation time — set it to their student/employee number there
            to keep this convention consistent.
        </div>
    <?php endif; ?>
    <p style="margin-top:20px;font-size:13px;color:#64748b">Security note: delete this file once you're done running it.</p>
</div>
</body>
</html>
