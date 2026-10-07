<?php
/**
 * ------------------------------------------------------------
 * includes/pwa.php
 * Installable app (PWA): students add it to the home screen and it opens full screen on the scanner.
 *
 *   pwa_head_tags()      goes in <head> of every page shell (header.php, login.php, auth_page.php):
 *                        manifest, theme colour, iOS icon, and assets/js/pwa.js (registers sw.php,
 *                        drives the install card).
 *   pwa_install_card()   the "Install the app" card (student dashboard). Hidden until the browser
 *                        says the app can be installed (or on iPhone/iPad, where Safari only allows
 *                        Share > Add to Home Screen), and gone once dismissed or installed.
 *
 * The manifest is assets/manifest.json (relative URLs, so it works at "/" and "/attendance-system/");
 * its start_url is index.php?source=pwa, which sends a student straight to the scanner.
 * ------------------------------------------------------------
 */
function pwa_head_tags(): string {
    $b = BASE_URL;
    $v = (int) @filemtime(__DIR__ . '/../assets/js/pwa.js');
    return '<link rel="manifest" href="' . $b . 'assets/manifest.json">' . "\n"
         . '<meta name="theme-color" content="#eef4ff" media="(prefers-color-scheme: light)">' . "\n"
         . '<meta name="theme-color" content="#0a1328" media="(prefers-color-scheme: dark)">' . "\n"
         . '<link rel="apple-touch-icon" href="' . $b . 'assets/img/app-icon-180.png">' . "\n"
         . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
         . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
         . '<meta name="apple-mobile-web-app-title" content="Attendance">' . "\n"
         . '<script src="' . $b . 'assets/js/pwa.js?v=' . $v . '" data-base="' . e($b) . '" defer></script>' . "\n";
}

function pwa_install_card(): string {
    return '<div class="install-card" id="installCard" hidden>'
         . '<img src="' . BASE_URL . 'assets/img/app-icon-192.png" alt="" width="48" height="48" class="install-card-icon">'
         . '<div class="install-card-text">'
         .   '<strong>Install the attendance app</strong>'
         .   '<span class="install-when-prompt">Open the scanner straight from your home screen, full screen.</span>'
         .   '<span class="install-when-ios">Tap <i class="fa-solid fa-arrow-up-from-bracket" aria-label="Share"></i> Share, then <strong>Add to Home Screen</strong>, to open the scanner in one tap.</span>'
         . '</div>'
         . '<div class="install-card-actions">'
         .   '<button type="button" class="btn btn-primary btn-sm install-when-prompt" data-install><i class="fa-solid fa-download"></i> Install</button>'
         .   '<button type="button" class="btn btn-outline btn-sm" data-dismiss>Not now</button>'
         . '</div>'
         . '</div>';
}
