<?php
/**
 * Shared frame for the public account pages (forgot_password.php, reset_password.php):
 * the same logo, card and styling as login.php.
 *   auth_page_start('Title'); ...card content...; auth_page_end();
 */
function auth_page_start(string $title): void {
    require_once __DIR__ . '/theme.php';
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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
<?php echo theme_head_tags(); ?>
</head>
<body class="lg-body">
<div class="theme-float"><?php echo theme_switcher(); ?></div>
<main class="lg-wrap">
    <header class="lg-brand">
        <img class="lg-logo" src="<?php echo BASE_URL; ?>assets/img/school-emblem-256.png" width="72" height="72" alt="MCNP and ISAP emblem">
        <h1>MCNP-ISAP QR Attendance</h1>
        <p>Medical Colleges of Northern Philippines and International School of Asia and the Pacific</p>
    </header>
    <section class="lg-card" aria-labelledby="lgTitle">
        <h2 id="lgTitle" style="margin:0 0 6px;font-size:20px"><?php echo e($title); ?></h2>
<?php
}

function auth_page_end(): void {
    ?>
        <a class="lg-link lg-back" href="<?php echo BASE_URL; ?>login.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a>
    </section>
    <p class="lg-help">Need help? Contact the MCNP-ISAP IT Services or Laboratory Department.</p>
</main>
</body>
</html>
<?php
}
