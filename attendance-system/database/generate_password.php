<?php
/**
 * ------------------------------------------------------------
 * generate_password.php
 * One-time utility: verifies the demo hash shipped in schema.sql
 * and lets you generate a fresh bcrypt hash for any password.
 * Run this in your browser:
 *   http://localhost/attendance-system/database/generate_password.php
 * Delete this file (or move it out of the webroot) once done.
 * ------------------------------------------------------------
 */

$shippedHash  = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
$testPassword = 'password';
$verifyResult = password_verify($testPassword, $shippedHash);

$newHash = null;
$customPassword = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['custom_password'])) {
    $customPassword = $_POST['custom_password'];
    if ($customPassword !== '') {
        $newHash = password_hash($customPassword, PASSWORD_BCRYPT);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Password Hash Utility</title>
<style>
    body{font-family:Segoe UI,Arial,sans-serif;background:#f4f6f9;padding:40px;color:#1f2937}
    .card{background:#fff;max-width:640px;margin:0 auto;padding:24px 28px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
    h2{margin-top:0}
    code{background:#eef1f5;padding:2px 6px;border-radius:4px;word-break:break-all}
    .ok{color:#15803d;font-weight:600}
    .fail{color:#b91c1c;font-weight:600}
    input[type=text]{width:100%;padding:8px;margin:8px 0;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box}
    button{background:#2563eb;color:#fff;border:0;padding:8px 16px;border-radius:6px;cursor:pointer}
    .box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-top:12px}
</style>
</head>
<body>
<div class="card">
    <h2>Password Hash Utility</h2>
    <p>Shipped demo accounts use password: <code><?php echo $testPassword; ?></code></p>
    <p>Verification against the hash stored in <code>schema.sql</code>:
        <?php if ($verifyResult): ?>
            <span class="ok">VALID on this PHP build &mdash; log in with "password".</span>
        <?php else: ?>
            <span class="fail">NOT valid on this PHP/OpenSSL build.</span>
            <div class="box">
                Generate a hash for <code>password</code> below, copy it, then in phpMyAdmin run:<br>
                <code>UPDATE users SET password = 'NEW_HASH_HERE';</code>
            </div>
        <?php endif; ?>
    </p>

    <form method="post">
        <label>Generate a new bcrypt hash for any password:</label>
        <input type="text" name="custom_password" placeholder="Type a password..." value="<?php echo htmlspecialchars($customPassword); ?>">
        <button type="submit">Generate Hash</button>
    </form>

    <?php if ($newHash): ?>
        <div class="box">
            <strong>Hash for "<?php echo htmlspecialchars($customPassword); ?>":</strong><br>
            <code><?php echo $newHash; ?></code>
        </div>
    <?php endif; ?>

    <p style="margin-top:20px;font-size:13px;color:#64748b">Security note: delete this file once your accounts are set up.</p>
</div>
</body>
</html>
