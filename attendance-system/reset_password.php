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

$useSupabaseAuth = supabase_auth_enabled();
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$reset = null;
$error = '';

if ($useSupabaseAuth) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accessToken = trim((string) ($_POST['access_token'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $error = !csrf_request_is_valid()
            ? 'Your session expired. Please try again.'
            : pwreset_password_problem($password, (string) ($_POST['confirm'] ?? ''));
        if ($error === '') {
            try {
                $authUser = supabase_auth_get_user($accessToken);
                if (!$authUser) {
                    $error = 'This reset link is invalid or has expired. Request a new one.';
                } else {
                    $find = $pdo->prepare("SELECT user_id, username FROM users WHERE auth_user_id = ? AND status = 'active' LIMIT 1");
                    $find->execute([$authUser['id']]);
                    $profile = $find->fetch(PDO::FETCH_ASSOC);
                    if (!$profile || !supabase_auth_update_password($accessToken, $password)) {
                        $error = 'This reset link is invalid or has expired. Request a new one.';
                    } else {
                        destroy_user_sessions($pdo, (int) $profile['user_id']);
                        login_clear_failures($pdo, $profile['username']);
                        log_activity($pdo, $profile['user_id'], 'Reset password with Supabase Auth');
                        set_flash('success', 'Your password was changed. You can sign in now.');
                        redirect('login.php');
                    }
                }
            } catch (Throwable $e) {
                error_log('reset_password: Supabase Auth update failed.');
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
} else {
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
                $reset = null;
            } catch (Throwable $e) {
                error_log('reset_password: ' . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

auth_page_start('Choose a new password');
?>
        <?php if ($useSupabaseAuth): ?>
            <p class="lg-sub">Choose a new password for your account.</p>
            <?php if ($error): ?><div class="lg-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <span><?php echo e($error); ?></span></div><?php endif; ?>
            <div class="lg-alert" id="resetLinkError" role="alert" style="display:none"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <span>This reset link is invalid or has expired. Request a new one.</span></div>
            <form method="POST" action="reset_password.php" autocomplete="off" novalidate id="supabaseResetForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="access_token" id="resetAccessToken">
                <div class="lg-field">
                    <label for="password">New password</label>
                    <div class="lg-input"><i class="fa-solid fa-lock" aria-hidden="true"></i><input type="password" id="password" name="password" minlength="<?php echo PWRESET_MIN_PASSWORD; ?>" maxlength="72" autocomplete="new-password" required autofocus></div>
                </div>
                <div class="lg-field">
                    <label for="confirm">Confirm new password</label>
                    <div class="lg-input"><i class="fa-solid fa-lock" aria-hidden="true"></i><input type="password" id="confirm" name="confirm" maxlength="72" autocomplete="new-password" required></div>
                </div>
                <button type="submit" class="lg-submit" id="changePasswordBtn" disabled>Change password <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </form>
            <script>
            (function () {
                var params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
                var accessToken = params.get('access_token') || '';
                var recoveryType = params.get('type') || '';
                var tokenInput = document.getElementById('resetAccessToken');
                var button = document.getElementById('changePasswordBtn');
                if (accessToken && recoveryType === 'recovery') {
                    tokenInput.value = accessToken;
                    button.disabled = false;
                    history.replaceState(null, document.title, window.location.pathname + window.location.search);
                } else {
                    document.getElementById('resetLinkError').style.display = 'flex';
                }
            })();
            </script>
        <?php elseif (!$reset): ?>
            <div class="lg-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <span>This reset link is invalid, has expired or was already used.</span></div>
            <a class="lg-submit" href="<?php echo BASE_URL; ?>forgot_password.php">Request a new link</a>
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
