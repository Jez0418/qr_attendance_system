<?php
/**
 * ------------------------------------------------------------
 * auth.php
 * Session management + role-based access control (RBAC).
 * Include this at the top of every protected page:
 *
 *   require_once '../includes/auth.php';
 *   require_role('admin');   // or 'teacher' / 'student'
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/supabase_auth.php';

/** True if a user is currently logged in */
function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

/** Force login; redirect to login page if not authenticated */
function require_login() {
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
    if (supabase_auth_enabled()) {
        if (empty($_SESSION['auth_user_id']) || empty($_SESSION['supabase_refresh_token'])) {
            do_logout($GLOBALS['pdo']);
            set_flash('error', 'Your session expired. Please sign in again.');
            redirect('login.php');
        }
        if ((int) ($_SESSION['supabase_expires_at'] ?? 0) <= time() + 90 && !supabase_auth_refresh_session()) {
            do_logout($GLOBALS['pdo']);
            set_flash('error', 'Your session expired. Please sign in again.');
            redirect('login.php');
        }
    }
    csrf_guard();   // every POST from a logged-in user must carry the CSRF token
    // Mark students absent for meetings that just ended (at most once a minute; see includes/absences.php).
    // Skipped for the AJAX endpoints so a scan or a live poll never pays for it.
    if (strpos(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'ajax_') !== 0) {
        require_once __DIR__ . '/absences.php';
        mark_absent_for_ended_sessions($GLOBALS['pdo']);
        // Delete old notifications / activity log / rejected scans (at most once a day; see includes/housekeeping.php).
        require_once __DIR__ . '/housekeeping.php';
        prune_old_rows($GLOBALS['pdo']);
    }
}

/**
 * Force a specific role (or array of allowed roles).
 * Redirects to login if not authenticated, or shows 403 if
 * authenticated but wrong role.
 */
function require_role($roles) {
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:60px;text-align:center">
                <h1>403 - Access Denied</h1>
                <p>You do not have permission to view this page.</p>
                <a href="' . BASE_URL . 'login.php">Back to Login</a>
             </div>');
    }
}

/**
 * Attempt to log a user in. Returns true, or a string error code on failure:
 *   'invalid'      - bad username/password
 *   'wrong_role'   - credentials correct but selected role doesn't match account
 */
function attempt_login(PDO $pdo, $username, $password, $expectedRole = null) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND status = "active" LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) return 'invalid';

    $authSession = null;
    if (supabase_auth_enabled()) {
        if (!supabase_auth_configured(true)) return 'unavailable';
        $authId = trim((string) ($user['auth_user_id'] ?? ''));

        // On the first valid sign-in, import the stored bcrypt hash into Supabase Auth.
        // This keeps every existing username/password working without a forced reset.
        if ($authId === '') {
            if (empty($user['password']) || !password_verify((string) $password, (string) $user['password'])) return 'invalid';
            $authId = supabase_auth_create_from_legacy($user) ?? '';
            if ($authId === '') return 'unavailable';
            $authSession = supabase_auth_sign_in((string) $user['email'], (string) $password);
            if (!$authSession['ok'] || empty($authSession['data']['user']['id'])
                || strtolower((string) $authSession['data']['user']['id']) !== strtolower($authId)) {
                supabase_auth_admin_delete_user($authId);
                return 'unavailable';
            }
            $link = $pdo->prepare('UPDATE users SET auth_user_id = ?, password = NULL WHERE user_id = ? AND auth_user_id IS NULL');
            $link->execute([$authId, (int) $user['user_id']]);
            if ($link->rowCount() !== 1) {
                supabase_auth_admin_delete_user($authId);
                return 'unavailable';
            }
        } else {
            $authSession = supabase_auth_sign_in((string) $user['email'], (string) $password);
            if (!$authSession['ok']) return $authSession['status'] >= 500 || $authSession['status'] === 0 ? 'unavailable' : 'invalid';
            if (empty($authSession['data']['user']['id'])
                || strtolower((string) $authSession['data']['user']['id']) !== strtolower($authId)) return 'invalid';
        }
    } elseif (empty($user['password']) || !password_verify((string) $password, (string) $user['password'])) {
        return 'invalid';
    }

    if ($expectedRole && $user['role'] !== $expectedRole) {
        if ($authSession && !empty($authSession['data']['access_token'])) {
            supabase_auth_request('POST', 'logout', null, $authSession['data']['access_token']);
        }
        return 'wrong_role';
    }

        // Regenerate session ID on login to prevent session fixation
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);   // fresh CSRF token for the new login session

        $_SESSION['user_id']  = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['email']    = $user['email'];
        if ($authSession) {
            $_SESSION['auth_user_id'] = $authSession['data']['user']['id'];
            $_SESSION['supabase_access_token'] = $authSession['data']['access_token'];
            $_SESSION['supabase_refresh_token'] = $authSession['data']['refresh_token'];
            $_SESSION['supabase_expires_at'] = time() + (int) ($authSession['data']['expires_in'] ?? 3600);
        }

        // Fetch role-specific profile info (name, ids) for convenience
        if ($user['role'] === 'student') {
            $s = $pdo->prepare('SELECT student_id, full_name, student_number FROM students WHERE user_id = ?');
            $s->execute([$user['user_id']]);
            $profile = $s->fetch();
            $_SESSION['profile_id'] = $profile['student_id'] ?? null;
            $_SESSION['full_name']  = $profile['full_name'] ?? $user['username'];
        } elseif ($user['role'] === 'teacher') {
            $t = $pdo->prepare('SELECT teacher_id, full_name FROM teachers WHERE user_id = ?');
            $t->execute([$user['user_id']]);
            $profile = $t->fetch();
            $_SESSION['profile_id'] = $profile['teacher_id'] ?? null;
            $_SESSION['full_name']  = $profile['full_name'] ?? $user['username'];
        } else {
            $_SESSION['profile_id'] = null;
            $_SESSION['full_name']  = 'Administrator';
        }

        log_activity($pdo, $user['user_id'], 'Logged in');
        return true;
}

/** Log the current user out */
function do_logout(PDO $pdo) {
    if (supabase_auth_enabled()) supabase_auth_sign_out_session();
    if (isset($_SESSION['user_id'])) {
        log_activity($pdo, $_SESSION['user_id'], 'Logged out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
