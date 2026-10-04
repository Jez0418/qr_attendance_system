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
<body class="auth-body">
    <div class="auth-wrapper">
        <div class="auth-brand-panel">
            <div class="auth-brand-inner">
                <i class="fa-solid fa-qrcode auth-logo"></i>
                <h1>MCNP<br>QR Attendance System</h1>
                <p>Fast, accurate, and paperless attendance tracking for Medical Colleges of Northern Philippines laboratory classes — scan, verify, done.</p>
                <ul class="auth-feature-list">
                    <li><i class="fa-solid fa-check"></i> One fixed QR code per laboratory</li>
                    <li><i class="fa-solid fa-check"></i> Automatic Present / Late detection</li>
                    <li><i class="fa-solid fa-check"></i> Admin, Teacher &amp; Student portals</li>
                </ul>
            </div>
        </div>
        <div class="auth-form-panel">
            <div class="auth-form-box">
                <h2>Welcome Back</h2>
                <p class="auth-subtitle">Select your role and sign in to continue</p>

                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo e($err); ?></div>
                <?php endforeach; ?>

                <div class="role-tabs" id="roleTabs">
                    <div class="role-tab <?php echo $selectedRole === 'admin' ? 'active' : ''; ?>" data-role="admin"><i class="fa-solid fa-user-shield"></i> Admin</div>
                    <div class="role-tab <?php echo $selectedRole === 'teacher' ? 'active' : ''; ?>" data-role="teacher"><i class="fa-solid fa-chalkboard-user"></i> Teacher</div>
                    <div class="role-tab <?php echo $selectedRole === 'student' ? 'active' : ''; ?>" data-role="student"><i class="fa-solid fa-user-graduate"></i> Student</div>
                </div>

                <form method="POST" action="login.php" autocomplete="off">
                    <input type="hidden" name="role" id="roleInput" value="<?php echo e($selectedRole); ?>">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-icon">
                            <i class="fa-solid fa-user"></i>
                            <input type="text" id="username" name="username" placeholder="Enter your username" value="<?php echo e($_POST['username'] ?? ''); ?>" required autofocus>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">Password <span class="text-muted" id="pwHint" style="font-weight:400"></span></label>
                        <div class="input-icon">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" id="password" name="password" placeholder="Enter your ID number" required>
                            <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('password', this)"></i>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Sign In <i class="fa-solid fa-arrow-right"></i></button>
                </form>

                <div class="demo-accounts">
                    <p><strong>Your password is your ID number</strong></p>
                    <p style="margin:6px 0 8px">Demo logins:</p>
                    <div class="demo-chip-row">
                        <span class="demo-chip">admin / ADM-0001</span>
                        <span class="demo-chip">tcruz / EMP-001</span>
                        <span class="demo-chip">s2023001 / 2023-0001</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
function togglePassword(id, icon) {
    const input = document.getElementById(id);
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

// Role tab selector — sets the hidden "role" field the server checks against
const roleHints = {
    admin: '(your Admin ID)',
    teacher: '(your Employee No.)',
    student: '(your Student No.)'
};
function setRoleHint(role) {
    document.getElementById('pwHint').textContent = roleHints[role] || '';
}
document.querySelectorAll('.role-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('roleInput').value = tab.dataset.role;
        setRoleHint(tab.dataset.role);
    });
});
setRoleHint(document.getElementById('roleInput').value);
</script>
</body>
</html>
