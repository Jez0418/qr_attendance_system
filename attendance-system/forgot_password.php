<?php
/**
 * ------------------------------------------------------------
 * forgot_password.php
 * Public page: the user enters their username or email and we email a one-hour, single-use link
 * (includes/password_reset.php). The answer is the same whether or not the account exists, so
 * the page can't be used to find out who has an account.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset.php';
require_once __DIR__ . '/includes/auth_page.php';

if (is_logged_in()) redirect($_SESSION['role'] . '/dashboard.php');

$error = '';
$sent = false;
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = clean($_POST['identifier'] ?? '');
    if (!csrf_request_is_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($identifier === '' || strlen($identifier) > 100) {
        $error = 'Please enter your username or email.';
    } elseif (!mail_available()) {
        $error = 'Password reset by email is not set up yet. Please contact the MCNP-ISAP IT Services.';
    } else {
        $sent = true;   // always the same answer from here on
        try {
            $ip = login_client_ip();
            $user = pwreset_find_user($pdo, $identifier);
            if ($user && !pwreset_rate_limited($pdo, (int) $user['user_id'], $ip)) {
                $token = pwreset_create($pdo, (int) $user['user_id'], $ip);
                if (!pwreset_send_email($user, $token)) error_log('forgot_password: email to user #' . $user['user_id'] . ' was not sent');
                else log_activity($pdo, $user['user_id'], 'Requested a password reset link');
            }
        } catch (Throwable $e) {
            error_log('forgot_password: ' . $e->getMessage());   // e.g. password_resets table not created yet
        }
    }
}

auth_page_start('Forgot password');
?>
        <?php if ($sent): ?>
            <div class="lg-alert ok" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>If an account matches, we sent a link to its email address. It works once and expires in <?php echo PWRESET_TTL_MINUTES; ?> minutes. Check your spam folder too.</span></div>
        <?php else: ?>
            <p class="lg-sub">Enter your username or the email address on your account and we will email you a link to choose a new password.</p>
            <?php if ($error): ?><div class="lg-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <span><?php echo e($error); ?></span></div><?php endif; ?>
            <form method="POST" action="forgot_password.php" novalidate>
                <?php echo csrf_field(); ?>
                <div class="lg-field">
                    <label for="identifier">Username or email</label>
                    <div class="lg-input">
                        <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                        <input type="text" id="identifier" name="identifier" maxlength="100" value="<?php echo e($identifier); ?>" autocomplete="username" required autofocus>
                    </div>
                </div>
                <button type="submit" class="lg-submit">Send reset link <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </form>
        <?php endif; ?>
<?php auth_page_end();
