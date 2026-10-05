<?php
/**
 * ------------------------------------------------------------
 * login_throttle.php
 * Brute-force protection for login.php.
 *   - 5 failed sign-ins for the same username within 15 minutes -> that username is locked
 *     until 15 minutes after its latest failure
 *   - 50 failed sign-ins from one IP address within 15 minutes   -> that IP is locked
 *     (high on purpose: a whole school can share one public IP)
 * Failures are stored in `login_attempts` (database/supabase_login_attempts.sql). A locked
 * attempt is NOT recorded, so a locked account cannot be kept locked forever by an attacker.
 * If the table is missing the functions fail OPEN (login keeps working) and log the problem.
 * ------------------------------------------------------------
 */
const LOGIN_MAX_FAILS_USER = 5;
const LOGIN_MAX_FAILS_IP   = 50;
const LOGIN_WINDOW_MINUTES = 15;

function login_client_ip(): string {
    // Vercel puts the real client address in these headers (and overwrites any client-sent value).
    foreach (['HTTP_X_VERCEL_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) return substr(trim(explode(',', $_SERVER[$h])[0]), 0, 64);
    }
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function login_username_key(string $username): string {
    return strtolower(substr(trim($username), 0, 100));
}

/** Seconds the caller must still wait (0 = allowed). */
function login_lock_seconds(PDO $pdo, string $username, string $ip): int {
    try {
        $window = LOGIN_WINDOW_MINUTES * 60;
        $sql = "SELECT COUNT(*) AS n, EXTRACT(EPOCH FROM (NOW() - MAX(attempted_at))) AS age
                FROM login_attempts WHERE %s = ? AND attempted_at > NOW() - INTERVAL '" . LOGIN_WINDOW_MINUTES . " minutes'";
        $wait = 0;
        foreach ([['username_key', login_username_key($username), LOGIN_MAX_FAILS_USER],
                  ['ip_address', $ip, LOGIN_MAX_FAILS_IP]] as [$col, $val, $max]) {
            if ($val === '') continue;
            $st = $pdo->prepare(sprintf($sql, $col));
            $st->execute([$val]);
            $r = $st->fetch();
            if ($r && (int) $r['n'] >= $max) $wait = max($wait, (int) ceil($window - (float) $r['age']));
        }
        return max(0, $wait);
    } catch (PDOException $e) {
        error_log('login_throttle (check): ' . $e->getMessage());
        return 0;
    }
}

function login_record_failure(PDO $pdo, string $username, string $ip): void {
    try {
        $pdo->prepare('INSERT INTO login_attempts (username_key, ip_address) VALUES (?, ?)')
            ->execute([login_username_key($username), $ip]);
        if (random_int(1, 20) === 1) {   // tidy up now and then
            $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '1 day'");
        }
    } catch (PDOException $e) {
        error_log('login_throttle (record): ' . $e->getMessage());
    }
}

function login_clear_failures(PDO $pdo, string $username): void {
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE username_key = ?')->execute([login_username_key($username)]);
    } catch (PDOException $e) {
        error_log('login_throttle (clear): ' . $e->getMessage());
    }
}

function login_lock_message(int $seconds): string {
    $min = max(1, (int) ceil($seconds / 60));
    return "Too many failed sign-in attempts. Please try again in $min minute" . ($min === 1 ? '' : 's') . '.';
}
