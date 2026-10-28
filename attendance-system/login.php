<?php
/**
 * ------------------------------------------------------------
 * login.php
 * Secure login form. Validates credentials via attempt_login()
 * in includes/auth.php (uses password_verify + prepared stmts).
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/login_throttle.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/pwa.php';

// Already logged in? Send to dashboard.
if (is_logged_in()) {
    redirect($_SESSION['role'] . '/dashboard.php');
}

$errors = [];
$selectedRole = $_POST['role'] ?? ($_GET['role'] ?? 'admin');
if (!in_array($selectedRole, ['admin', 'teacher', 'student'], true)) $selectedRole = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    $ip = login_client_ip();
    if ($username === '' || $password === '') {
        $errors[] = 'Please enter both username and password.';
    } elseif (($wait = login_lock_seconds($pdo, $username, $ip)) > 0) {
        $errors[] = login_lock_message($wait);          // locked: do not even check the password
    } else {
        $result = attempt_login($pdo, $username, $password, $selectedRole);
        if ($result === true) {
            login_clear_failures($pdo, $username);
            if ($remember) {
                // Keep the session cookie past the browser closing (30 days) instead of the default
                // session-only cookie. Same HttpOnly/SameSite/Secure hardening as includes/config.php.
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
                setcookie(session_name(), session_id(), [
                    'expires' => time() + 60 * 60 * 24 * 30, 'path' => '/',
                    'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
            redirect($_SESSION['role'] . '/dashboard.php');
        }
        login_record_failure($pdo, $username, $ip);
        if ($result === 'wrong_role') {
            $errors[] = 'These credentials belong to a different account type. Please select the correct role above.';
        } else {
            $errors[] = 'Invalid username or password.';
        }
    }
}

$roleIcons = ['admin' => 'fa-user-shield', 'teacher' => 'fa-chalkboard-user', 'student' => 'fa-graduation-cap'];
$roleNotes = [
    'admin'   => 'Use your assigned admin ID to sign in.',
    'teacher' => 'Use your assigned employee number to sign in.',
    'student' => 'Use your assigned student ID to sign in.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - <?php echo APP_NAME; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/img/school-emblem-96.png">
<link rel="stylesheet" href="<?php echo e(asset_url('assets/css/style.css')); ?>">
<?php echo theme_head_tags(); ?>
<?php echo pwa_head_tags(); ?>
</head>
<body class="login-page">
<header class="login-topbar">
    <a class="login-topbar-brand" href="<?php echo BASE_URL; ?>login.php">
        <img class="login-topbar-logo" src="<?php echo BASE_URL; ?>assets/img/school-emblem-96.png" width="40" height="40" alt="MCNP&ndash;ISAP emblem">
        <span class="login-topbar-text">
            <strong>MCNP&ndash;ISAP</strong>
            <small>Medical Colleges of Northern Philippines and International School of Asia and the Pacific</small>
        </span>
    </a>
    <div class="login-topbar-actions">
        <?php echo theme_switcher(); ?>
    </div>
</header>

<main class="login-shell">
    <div class="login-grid">
        <section class="login-panel" aria-labelledby="lgTitle">
            <h1 id="lgTitle">Welcome back</h1>
            <p class="lg-sub">Sign in to your MCNP&ndash;ISAP attendance account.</p>

            <?php if ($flash = get_flash()): ?>
                <div class="lg-alert<?php echo $flash['type'] === 'success' ? ' ok' : ''; ?>" role="status"><i class="fa-solid fa-circle-<?php echo $flash['type'] === 'success' ? 'check' : 'exclamation'; ?>" aria-hidden="true"></i> <span><?php echo e($flash['message']); ?></span></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="lg-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <span><?php echo e($err); ?></span></div>
            <?php endforeach; ?>

            <p class="login-field-label" id="roleLabel">Sign in as</p>
            <div class="lg-roles" id="roleTabs" role="group" aria-labelledby="roleLabel">
                <?php foreach (['admin' => 'Admin', 'teacher' => 'Teacher', 'student' => 'Student'] as $r => $label): ?>
                    <button type="button" class="lg-role <?php echo $selectedRole === $r ? 'active' : ''; ?>" data-role="<?php echo $r; ?>" aria-pressed="<?php echo $selectedRole === $r ? 'true' : 'false'; ?>"><i class="fa-solid <?php echo $roleIcons[$r]; ?>" aria-hidden="true"></i> <?php echo $label; ?></button>
                <?php endforeach; ?>
            </div>

            <form method="POST" action="login.php" autocomplete="off" novalidate id="loginForm">
                <input type="hidden" name="role" id="roleInput" value="<?php echo e($selectedRole); ?>">

                <div class="lg-field">
                    <label for="username">Username</label>
                    <div class="lg-input">
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                        <input type="text" id="username" name="username" placeholder="Enter your username" value="<?php echo e($_POST['username'] ?? ''); ?>" autocomplete="username" required autofocus>
                    </div>
                </div>

                <div class="lg-field">
                    <label for="password">Password</label>
                    <div class="lg-input">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="lg-eye" id="pwToggle" aria-label="Show password" aria-pressed="false"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                    </div>
                </div>

                <div class="login-remember-row">
                    <label class="login-check"><input type="checkbox" name="remember" value="1"> Remember me</label>
                    <a class="lg-link" href="<?php echo BASE_URL; ?>forgot_password.php">Forgot password?</a>
                </div>

                <button type="submit" class="lg-submit" id="loginSubmit"><span class="lg-submit-label">Sign in</span> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </form>

            <?php // Demo credentials are hidden unless the SHOW_DEMO_LOGINS=1 environment variable is set (local testing only). ?>
            <?php if (getenv('SHOW_DEMO_LOGINS') === '1'): ?>
            <div class="lg-demo">
                <span>Demo logins <small>(click to fill)</small></span>
                <div class="lg-chips">
                    <button type="button" class="lg-chip" data-role="admin" data-user="admin" data-pass="password">Admin</button>
                    <button type="button" class="lg-chip" data-role="teacher" data-user="tcruz" data-pass="password">Teacher</button>
                    <button type="button" class="lg-chip" data-role="student" data-user="s2023001" data-pass="password">Student</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="login-help">
                <h2>Help &amp; Support</h2>
                <p id="loginNoteText"><?php echo e($roleNotes[$selectedRole]); ?></p>
                <p>Need assistance? Contact the MCNP&ndash;ISAP Registrar or Laboratory Department.</p>
            </div>
        </section>
    </div>
</main>

<footer class="login-footer">
    <span>&copy; 2026 MCNP&ndash;ISAP. All rights reserved.</span>
    <span class="login-footer-secure"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Secure institutional access</span>
</footer>

<script>
const roleNotes = {
    admin: 'Use your assigned admin ID to sign in.',
    teacher: 'Use your assigned employee number to sign in.',
    student: 'Use your assigned student ID to sign in.',
};
const roleInput = document.getElementById('roleInput');
const noteText = document.getElementById('loginNoteText');

function setRole(role) {
    roleInput.value = role;
    document.querySelectorAll('.lg-role').forEach(t => {
        const on = t.dataset.role === role;
        t.classList.toggle('active', on);
        t.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    noteText.textContent = roleNotes[role] || '';
}
document.querySelectorAll('.lg-role').forEach(t => t.addEventListener('click', () => setRole(t.dataset.role)));
setRole(roleInput.value);

// Show / hide password
const pw = document.getElementById('password');
const pwToggle = document.getElementById('pwToggle');
pwToggle.addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    pwToggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    pwToggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    pwToggle.firstElementChild.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
});

// Demo logins fill the form
document.querySelectorAll('.lg-chip').forEach(c => c.addEventListener('click', () => {
    setRole(c.dataset.role);
    document.getElementById('username').value = c.dataset.user;
    pw.value = c.dataset.pass;
    document.querySelector('.lg-submit').focus();
}));

// Busy state while the sign-in request is in flight
document.getElementById('loginForm').addEventListener('submit', () => {
    const btn = document.getElementById('loginSubmit');
    btn.classList.add('is-loading');
    btn.disabled = true;
});
</script>
</body>
</html>
