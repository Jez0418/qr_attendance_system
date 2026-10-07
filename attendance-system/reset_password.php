<?php
/**
 * ------------------------------------------------------------
 * reset_password.php?token=...
 * Public page reached from the emailed link: checks the token (unused, unexpired), asks for a new
 * password, saves it, signs the account out everywhere and sends the user to the login page.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset.php';
require_once __DIR__ . '/includes/auth_page.php';

if (is_logged_in()) redirect($_SESSION['role'] . '/dashboard.php');

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$reset = null;
$error = '';
try {
    $reset = pwreset_find_valid($pdo, $token);
} catch (Throwable $e) {
    error_log('reset_password: ' . $e->getMessage());
}

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $error = !csrf_request_is_valid()
        ? 'Your session expired. Please try again.'
        : pwreset_password_problem($password, (string) ($_POST['confirm'] ?? ''));
    if ($error === '') {
        try {
            if (pwreset_complete($pdo, $reset, $password)) {
                set_flash('success', 'Your password was changed. You can sign in now.');
                redirect('login.php');
            }
            $reset = null;   // used a moment ago by another request
        } catch (Throwable $e) {
            error_log('reset_password: ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}

auth_page_start('Choose a new password');
?>
        <?php if (!$reset): ?>
            <div class="lg-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <span>This reset link is invalid, has expired or was already used.</span></div>
            <a class="lg-submit" style="display:block;text-align:center;text-decoration:none" href="<?php echo BASE_URL; ?>forgot_password.php">Request a new link</a>
        <?php else: ?>
            <p class="lg-sub">Account: <strong><?php echo e($reset['username']); ?></strong>. Use at least <?php echo PWRESET_MIN_PASSWORD; ?> characters.</p>
            <?php if ($error): ?><div class="lg-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <span><?php echo e($error); ?></span></div><?php endif; ?>
            <form method="POST" action="reset_password.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                <div class="lg-field">
                    <label for="password">New password</label>
                    <div class="lg-input">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <input type="password" id="password" name="password" minlength="<?php echo PWRESET_MIN_PASSWORD; ?>" maxlength="72" autocomplete="new-password" required autofocus>
                    </div>
                </div>
                <div class="lg-field">
                    <label for="confirm">Confirm new password</label>
                    <div class="lg-input">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <input type="password" id="confirm" name="confirm" maxlength="72" autocomplete="new-password" required>
                    </div>
                </div>
                <button type="submit" class="lg-submit">Change password <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </form>
        <?php endif; ?>
<?php auth_page_end();
