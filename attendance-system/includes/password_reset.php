<?php
/**
 * ------------------------------------------------------------
 * includes/password_reset.php
 * "Forgot password" logic (table: password_resets, database/supabase_password_resets.sql).
 *   - the emailed token is 32 random bytes; only its SHA-256 hash is stored
 *   - a link works once and for PWRESET_TTL_MINUTES
 *   - asking for a new link cancels the older ones
 *   - at most PWRESET_MAX_PER_USER links per account and PWRESET_MAX_PER_IP per address per hour
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/login_throttle.php';

const PWRESET_TTL_MINUTES = 60;
const PWRESET_MAX_PER_USER = 3;
const PWRESET_MAX_PER_IP = 10;
const PWRESET_MIN_PASSWORD = 8;

function pwreset_hash(string $token): string {
    return hash('sha256', $token);
}

/**
 * Absolute URL of this site. Set APP_URL (e.g. https://yourapp.vercel.app) to pin it; otherwise Vercel's own
 * production hostname, then the request host. Pinning matters: a link built from a forged Host header
 * could send a victim's reset token to an attacker's site.
 */
function pwreset_base_url(): string {
    $app = trim((string) getenv('APP_URL'));
    if ($app !== '') return rtrim($app, '/') . '/';
    $vercelHost = trim((string) getenv('VERCEL_PROJECT_PRODUCTION_URL'));
    if ($vercelHost !== '') return 'https://' . $vercelHost . '/';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https://' : 'http://') . $host . BASE_URL;
}

/** Active account by username, else by email (case-insensitive). */
function pwreset_find_user(PDO $pdo, string $identifier): ?array {
    foreach (['username = ?', 'LOWER(email) = LOWER(?)'] as $where) {
        $st = $pdo->prepare("SELECT user_id, username, email, role FROM users WHERE $where AND status = 'active' LIMIT 1");
        $st->execute([$identifier]);
        if ($u = $st->fetch()) return $u;
    }
    return null;
}

/** True when this account or address has asked for too many links in the last hour. */
function pwreset_rate_limited(PDO $pdo, int $userId, string $ip): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL '1 hour'");
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() >= PWRESET_MAX_PER_USER) return true;
    if ($ip !== '') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE ip_address = ? AND created_at > NOW() - INTERVAL '1 hour'");
        $st->execute([$ip]);
        if ((int) $st->fetchColumn() >= PWRESET_MAX_PER_IP) return true;
    }
    return false;
}

/** Cancel the user's older links and store a new one. Returns the plain token to email. */
function pwreset_create(PDO $pdo, int $userId, string $ip): string {
    $token = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, ip_address, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL '" . PWRESET_TTL_MINUTES . " minutes')")
            ->execute([$userId, pwreset_hash($token), $ip !== '' ? $ip : null]);
        if (random_int(1, 20) === 1) {   // tidy up now and then
            $pdo->exec("DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL '7 days'");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $token;
}

/** The unused, unexpired link for this token (with its active user), or null. */
function pwreset_find_valid(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $st = $pdo->prepare('
        SELECT pr.reset_id, pr.user_id, u.username
        FROM password_resets pr JOIN users u ON u.user_id = pr.user_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = \'active\'
    ');
    $st->execute([pwreset_hash($token)]);
    return $st->fetch() ?: null;
}

/** Why a new password is not acceptable ('' = fine). */
function pwreset_password_problem(string $password, string $confirm): string {
    if ($password === '') return 'Please enter a new password.';
    if ($password !== $confirm) return 'The two passwords do not match.';
    if (strlen($password) < PWRESET_MIN_PASSWORD) return 'The password must be at least ' . PWRESET_MIN_PASSWORD . ' characters.';
    if (strlen($password) > 72) return 'The password must be at most 72 characters.';   // bcrypt ignores anything longer
    return '';
}

/**
 * Set the new password and use up the link, in one transaction. Also signs the account out everywhere
 * (database-backed sessions) and clears its login lockout. Returns false if the link was already used.
 */
function pwreset_complete(PDO $pdo, array $reset, string $password): bool {
    $pdo->beginTransaction();
    try {
        $use = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = ? AND used_at IS NULL');
        $use->execute([$reset['reset_id']]);
        if ($use->rowCount() !== 1) { $pdo->rollBack(); return false; }   // someone else used it first
        $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $reset['user_id']]);
        destroy_user_sessions($pdo, (int) $reset['user_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    login_clear_failures($pdo, $reset['username']);
    log_activity($pdo, $reset['user_id'], 'Reset password with an emailed link');
    return true;
}

/** Email the reset link. Returns whether the mail was accepted. */
function pwreset_send_email(array $user, string $token): bool {
    $link = pwreset_base_url() . 'reset_password.php?token=' . $token;
    $mins = PWRESET_TTL_MINUTES;
    $text = "Hello {$user['username']},\n\nWe received a request to reset your " . APP_NAME . " password.\n"
          . "Open this link to choose a new one (it works once and expires in $mins minutes):\n\n$link\n\n"
          . "If you did not ask for this, ignore this email: your password stays the same.\n";
    $html = '<p>Hello ' . e($user['username']) . ',</p>'
          . '<p>We received a request to reset your ' . e(APP_NAME) . ' password.</p>'
          . '<p><a href="' . e($link) . '" style="display:inline-block;padding:10px 18px;background:#4f46e5;color:#fff;border-radius:6px;text-decoration:none">Choose a new password</a></p>'
          . '<p style="color:#555;font-size:13px">The link works once and expires in ' . $mins . ' minutes. If the button does not work, copy this address into your browser:<br>' . e($link) . '</p>'
          . '<p style="color:#555;font-size:13px">If you did not ask for this, ignore this email: your password stays the same.</p>';
    return send_mail($user['email'], $user['username'], 'Reset your password - ' . APP_NAME, $html, $text);
}
