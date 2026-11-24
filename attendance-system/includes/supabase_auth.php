<?php
/** Server-side Supabase Auth helpers for the PHP session based application. */

function supabase_auth_enabled(): bool {
    return in_array(strtolower(trim((string) getenv('SUPABASE_AUTH_ENABLED'))), ['1', 'true', 'yes', 'on'], true);
}

function supabase_auth_base_url(): string {
    $url = trim((string) getenv('SUPABASE_URL'));
    return $url !== '' ? rtrim($url, '/') . '/auth/v1' : '';
}

function supabase_auth_public_key(): string {
    return trim((string) (getenv('SUPABASE_ANON_KEY') ?: getenv('SUPABASE_PUBLISHABLE_KEY')));
}

function supabase_auth_admin_key(): string {
    return trim((string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_SECRET_KEY')));
}

function supabase_auth_configured(bool $admin = false): bool {
    return supabase_auth_base_url() !== ''
        && supabase_auth_public_key() !== ''
        && (!$admin || supabase_auth_admin_key() !== '');
}

/**
 * Make a request to Supabase Auth. The raw response is kept internal because
 * successful responses can contain access and refresh tokens.
 */
function supabase_auth_request(string $method, string $path, ?array $body = null, ?string $accessToken = null, bool $admin = false): array {
    if (!supabase_auth_configured($admin) || !function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => null];
    }

    $apiKey = $admin ? supabase_auth_admin_key() : supabase_auth_public_key();
    $bearer = $accessToken !== null && $accessToken !== '' ? $accessToken : $apiKey;
    $headers = [
        'apikey: ' . $apiKey,
        'Authorization: Bearer ' . $bearer,
        'Accept: application/json',
    ];
    if ($body !== null) $headers[] = 'Content-Type: application/json';

    $curl = curl_init(supabase_auth_base_url() . '/' . ltrim($path, '/'));
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl) !== 0;
    curl_close($curl);
    if ($curlError || !is_string($raw)) return ['ok' => false, 'status' => $status, 'data' => null];

    $decoded = json_decode($raw, true);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : null,
    ];
}

function supabase_auth_sign_in(string $email, string $password): array {
    return supabase_auth_request('POST', 'token?grant_type=password', [
        'email' => $email,
        'password' => $password,
    ]);
}

/** Create an Auth user from a legacy PHP bcrypt hash without handling plaintext. */
function supabase_auth_create_from_legacy(array $user): ?string {
    if (!supabase_auth_configured(true)) return null;
    $hash = (string) ($user['password'] ?? '');
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    $userId = (int) ($user['user_id'] ?? 0);
    if (!$userId || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) {
        return null;
    }

    $result = supabase_auth_request('POST', 'admin/users', [
        'email' => $email,
        'password_hash' => $hash,
        'email_confirm' => true,
        'app_metadata' => [
            'app_role' => (string) ($user['role'] ?? ''),
            'legacy_profile_id' => $userId,
        ],
        'user_metadata' => ['username' => (string) ($user['username'] ?? '')],
    ], null, true);

    $authId = $result['ok'] ? ($result['data']['id'] ?? null) : null;
    return is_string($authId) && preg_match('/^[0-9a-f-]{36}$/i', $authId) ? $authId : null;
}

/**
 * Migrate a single active app profile. A caller may pass clearLegacyPassword=false
 * while preparing a staged deployment, then clear hashes after all profiles link.
 */
function supabase_auth_migrate_profile(PDO $pdo, array $user, bool $clearLegacyPassword = true): ?string {
    $existing = trim((string) ($user['auth_user_id'] ?? ''));
    if ($existing !== '') return $existing;
    if ((string) ($user['status'] ?? '') !== 'active') return null;

    $authId = supabase_auth_create_from_legacy($user);
    if ($authId === null) return null;

    $sql = $clearLegacyPassword
        ? 'UPDATE users SET auth_user_id = ?, password = NULL WHERE user_id = ? AND auth_user_id IS NULL'
        : 'UPDATE users SET auth_user_id = ? WHERE user_id = ? AND auth_user_id IS NULL';
    $link = $pdo->prepare($sql);
    $link->execute([$authId, (int) $user['user_id']]);
    if ($link->rowCount() !== 1) {
        // Avoid leaving an Auth account that is not linked to its app profile.
        supabase_auth_admin_delete_user($authId);
        return null;
    }
    return $authId;
}

function supabase_auth_admin_update_user(string $authId, array $attributes): bool {
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authId) || !$attributes) return false;
    $result = supabase_auth_request('PUT', 'admin/users/' . rawurlencode($authId), $attributes, null, true);
    return $result['ok'];
}

/** Keep a linked Auth account in step with an administrator's account edits. */
function supabase_auth_sync_admin_changes(array $profile, string $email, string $password = ''): bool {
    $authId = trim((string) ($profile['auth_user_id'] ?? ''));
    if ($authId === '') return true;
    $attributes = [];
    if (strtolower(trim((string) ($profile['email'] ?? ''))) !== strtolower(trim($email))) {
        $attributes['email'] = strtolower(trim($email));
        $attributes['email_confirm'] = true;
    }
    if ($password !== '') $attributes['password'] = $password;
    return !$attributes || supabase_auth_admin_update_user($authId, $attributes);
}

/** Provision a newly-created local account immediately when Auth is enabled. */
function supabase_auth_provision_profile(PDO $pdo, int $userId): bool {
    if (!supabase_auth_enabled()) return true;
    $stmt = $pdo->prepare('SELECT user_id, username, password, role, email, status, auth_user_id FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;
    if ((string) $user['status'] !== 'active') return true;
    return supabase_auth_migrate_profile($pdo, $user, true) !== null;
}

function supabase_auth_admin_delete_user(string $authId): bool {
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authId)) return false;
    $result = supabase_auth_request('DELETE', 'admin/users/' . rawurlencode($authId), null, null, true);
    return $result['ok'];
}

function supabase_auth_send_recovery(string $email, string $redirectTo): bool {
    $result = supabase_auth_request('POST', 'recover?redirect_to=' . rawurlencode($redirectTo), [
        'email' => $email,
    ]);
    return $result['ok'];
}

function supabase_auth_update_password(string $accessToken, string $password): bool {
    if ($accessToken === '') return false;
    $result = supabase_auth_request('PUT', 'user', ['password' => $password], $accessToken);
    return $result['ok'];
}

function supabase_auth_get_user(string $accessToken): ?array {
    if ($accessToken === '') return null;
    $result = supabase_auth_request('GET', 'user', null, $accessToken);
    return $result['ok'] && is_array($result['data']) && !empty($result['data']['id']) ? $result['data'] : null;
}

function supabase_auth_refresh_session(): bool {
    $refreshToken = (string) ($_SESSION['supabase_refresh_token'] ?? '');
    if ($refreshToken === '') return false;
    $result = supabase_auth_request('POST', 'token?grant_type=refresh_token', ['refresh_token' => $refreshToken]);
    if (!$result['ok'] || empty($result['data']['access_token']) || empty($result['data']['refresh_token'])) return false;
    $_SESSION['supabase_access_token'] = $result['data']['access_token'];
    $_SESSION['supabase_refresh_token'] = $result['data']['refresh_token'];
    $_SESSION['supabase_expires_at'] = time() + (int) ($result['data']['expires_in'] ?? 3600);
    return true;
}

function supabase_auth_sign_out_session(): void {
    $accessToken = (string) ($_SESSION['supabase_access_token'] ?? '');
    if ($accessToken !== '') supabase_auth_request('POST', 'logout', null, $accessToken);
}

/** Bulk-link active local profiles for the staged account migration. */
function supabase_auth_migrate_pending_profiles(PDO $pdo, bool $clearLegacyPassword = true, int $limit = 5): array {
    $limit = max(1, min(25, $limit));
    $rows = $pdo->query("SELECT user_id, username, password, role, email, status, auth_user_id
                         FROM users WHERE status = 'active' AND auth_user_id IS NULL ORDER BY user_id LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    $linked = 0;
    $failed = [];
    foreach ($rows as $user) {
        try {
            if (supabase_auth_migrate_profile($pdo, $user, $clearLegacyPassword) !== null) $linked++;
            else $failed[] = (int) $user['user_id'];
        } catch (Throwable $e) {
            error_log('Supabase Auth migration failed for profile #' . (int) $user['user_id'] . ': ' . $e->getMessage());
            $failed[] = (int) $user['user_id'];
        }
    }
    return ['linked' => $linked, 'failed' => $failed, 'remaining' => count($failed)];
}

function supabase_auth_clear_linked_legacy_passwords(PDO $pdo): int {
    $stmt = $pdo->prepare('UPDATE users SET password = NULL WHERE auth_user_id IS NOT NULL AND password IS NOT NULL');
    $stmt->execute();
    return $stmt->rowCount();
}

function supabase_auth_pending_profile_count(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND auth_user_id IS NULL")->fetchColumn();
}
