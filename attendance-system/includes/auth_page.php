<?php
/**
 * Shared frame for the public pages.
 *   public_topbar() / public_footer()  the top bar (emblem, school name, theme switch) and footer, also used by login.php,
 *                                      so every public page has the same chrome on the campus photo.
 *   auth_page_start('Title'); ...card content...; auth_page_end();
 *                                      the account pages (forgot_password.php, reset_password.php): login.php's shell and
 *                                      card style (.login-page / .login-grid / .login-panel) around their own form.
 */
function public_topbar(): string {
    $b = BASE_URL;
    return '<header class="login-topbar">' . "\n"
         . '    <a class="login-topbar-brand" href="' . $b . 'login.php">' . "\n"
         . '        <img class="login-topbar-logo" src="' . $b . 'assets/img/school-emblem-96.png" width="40" height="40" alt="MCNP-ISAP emblem">' . "\n"
         . '        <span class="login-topbar-text">' . "\n"
         . '            <strong>MCNP-ISAP</strong>' . "\n"
         . '            <small>Medical Colleges of Northern Philippines and International School of Asia and the Pacific</small>' . "\n"
         . '        </span>' . "\n"
         . '    </a>' . "\n"
         . '    <div class="login-topbar-actions">' . theme_switcher() . '</div>' . "\n"
         . '</header>' . "\n";
}

function public_footer(): string {
    return '<footer class="login-footer">' . "\n"
         . '    <span>&copy; ' . date('Y') . ' MCNP-ISAP. All rights reserved.</span>' . "\n"
         . '    <span class="login-footer-secure"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Secure institutional access</span>' . "\n"
         . '</footer>' . "\n";
}

function auth_page_start(string $title): void {
    require_once __DIR__ . '/theme.php';
    require_once __DIR__ . '/pwa.php';
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');   // the reset token is in the URL: never leak it in a Referer header
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?php echo e($title); ?> - <?php echo APP_NAME; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/img/school-emblem-96.png">
<link rel="stylesheet" href="<?php echo e(asset_url('assets/css/style.css')); ?>">
<?php echo theme_head_tags(); ?>
<?php echo pwa_head_tags(); ?>
</head>
<body class="login-page">
<?php echo public_topbar(); ?>
<main class="login-shell">
    <div class="login-grid">
        <section class="login-panel" aria-labelledby="lgTitle">
            <h1 id="lgTitle"><?php echo e($title); ?></h1>
<?php
}

function auth_page_end(): void {
    ?>
            <a class="lg-link lg-back" href="<?php echo BASE_URL; ?>login.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a>
            <p class="lg-help">Need help? Contact the MCNP-ISAP IT Services or Laboratory Department.</p>
        </section>
    </div>
</main>
<?php echo public_footer(); ?>
</body>
</html>
<?php
}
