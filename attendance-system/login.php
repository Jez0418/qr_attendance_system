<?php
/**
 * ------------------------------------------------------------
 * login.php
 * Secure login form. Validates credentials via attempt_login()
 * in includes/auth.php (uses password_verify + prepared stmts).
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/auth.php';

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

    if ($username === '' || $password === '') {
        $errors[] = 'Please enter both username and password.';
    } else {
        $result = attempt_login($pdo, $username, $password, $selectedRole);
        if ($result === true) {
            redirect($_SESSION['role'] . '/dashboard.php');
        } elseif ($result === 'wrong_role') {
            $errors[] = 'These credentials belong to a different account type. Please select the correct role above.';
        } else {
            $errors[] = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - <?php echo APP_NAME; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
</head>
<body class="lg-body">
<main class="lg-wrap">
    <header class="lg-brand">
        <div class="lg-logo" aria-hidden="true"><i class="fa-solid fa-graduation-cap"></i></div>
        <h1>MCNP QR Attendance</h1>
        <p>Medical Colleges of Northern Philippines</p>
    </header>

    <section class="lg-card" aria-labelledby="lgTitle">
        <h2 id="lgTitle" class="lg-sr">Sign in</h2>

        <?php foreach ($errors as $err): ?>
            <div class="lg-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <span><?php echo e($err); ?></span></div>
        <?php endforeach; ?>

        <div class="lg-roles" id="roleTabs" role="group" aria-label="Account type">
            <?php foreach (['admin' => 'Admin', 'teacher' => 'Teacher', 'student' => 'Student'] as $r => $label): ?>
                <button type="button" class="lg-role <?php echo $selectedRole === $r ? 'active' : ''; ?>" data-role="<?php echo $r; ?>" aria-pressed="<?php echo $selectedRole === $r ? 'true' : 'false'; ?>"><?php echo $label; ?></button>
            <?php endforeach; ?>
        </div>

        <form method="POST" action="login.php" autocomplete="off" novalidate>
            <input type="hidden" name="role" id="roleInput" value="<?php echo e($selectedRole); ?>">

            <div class="lg-field">
                <label for="username">Username</label>
                <div class="lg-input">
                    <i class="fa-regular fa-user" aria-hidden="true"></i>
                    <input type="text" id="username" name="username" placeholder="Enter your username" value="<?php echo e($_POST['username'] ?? ''); ?>" autocomplete="username" required autofocus>
                </div>
            </div>

            <div class="lg-field">
                <div class="lg-label-row">
                    <label for="password">Password</label>
                    <span class="lg-hint" id="pwHint"></span>
                </div>
                <div class="lg-input">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                    <button type="button" class="lg-eye" id="pwToggle" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                </div>
            </div>

            <button type="submit" class="lg-submit">Sign in <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </form>

        <div class="lg-demo">
            <span>Demo logins <small>(click to fill)</small></span>
            <div class="lg-chips">
                <button type="button" class="lg-chip" data-role="admin" data-user="admin" data-pass="password">Admin</button>
                <button type="button" class="lg-chip" data-role="teacher" data-user="tcruz" data-pass="password">Teacher</button>
                <button type="button" class="lg-chip" data-role="student" data-user="s2023001" data-pass="password">Student</button>
            </div>
        </div>
    </section>

    <p class="lg-help">Need help? Contact the MCNP IT Services or Laboratory Department.</p>
</main>

<script>
const roleHints = { admin: 'Your Admin ID', teacher: 'Your Employee No.', student: 'Your Student No.' };
const roleInput = document.getElementById('roleInput');

function setRole(role) {
    roleInput.value = role;
    document.querySelectorAll('.lg-role').forEach(t => {
        const on = t.dataset.role === role;
        t.classList.toggle('active', on);
        t.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    document.getElementById('pwHint').textContent = roleHints[role] || '';
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
    pwToggle.firstElementChild.className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
});

// Demo logins fill the form
document.querySelectorAll('.lg-chip').forEach(c => c.addEventListener('click', () => {
    setRole(c.dataset.role);
    document.getElementById('username').value = c.dataset.user;
    pw.value = c.dataset.pass;
    document.querySelector('.lg-submit').focus();
}));
</script>
</body>
</html>
