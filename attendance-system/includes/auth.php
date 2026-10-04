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

    if ($user && password_verify($password, $user['password'])) {
        if ($expectedRole && $user['role'] !== $expectedRole) {
            return 'wrong_role';
        }
        // Regenerate session ID on login to prevent session fixation
        session_regenerate_id(true);

        $_SESSION['user_id']  = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['email']    = $user['email'];

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
    return 'invalid';
}

/** Log the current user out */
function do_logout(PDO $pdo) {
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
