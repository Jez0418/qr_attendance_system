<?php
/**
 * ------------------------------------------------------------
 * includes/theme.php
 * Light / Dark / System theme markup shared by every page shell (header.php, login.php, auth_page.php).
 * The behaviour lives in assets/js/theme.js; the colours in assets/css/style.css ([data-theme="dark"]).
 *
 *   theme_head_tags()  goes in <head>, after style.css: loads theme.js synchronously so the saved choice
 *                      is on <html data-theme> before the first paint (no white flash in dark mode).
 *   theme_switcher()   the sun/moon button with its Light / Dark / System menu.
 * ------------------------------------------------------------
 */
function theme_head_tags(): string {
    $v = (int) @filemtime(__DIR__ . '/../assets/js/theme.js');
    return '<meta name="color-scheme" content="light dark">' . "\n"
         . '<script src="' . BASE_URL . 'assets/js/theme.js?v=' . $v . '"></script>' . "\n";
}

function theme_switcher(): string {
    $items = ['light' => ['fa-sun', 'Light'], 'dark' => ['fa-moon', 'Dark'], 'system' => ['fa-circle-half-stroke', 'System']];
    $html = '<div class="theme-switch">'
          . '<button type="button" class="icon-btn theme-btn" aria-label="Colour theme" title="Theme" aria-haspopup="true" aria-expanded="false"><i class="fa-solid fa-circle-half-stroke"></i></button>'
          . '<div class="theme-menu" role="menu" aria-label="Colour theme">';
    foreach ($items as $key => [$icon, $label]) {
        $html .= '<button type="button" role="menuitemradio" aria-checked="false" data-theme-choice="' . $key . '"><i class="fa-solid ' . $icon . '"></i> ' . $label . '<i class="fa-solid fa-check theme-check"></i></button>';
    }
    return $html . '</div></div>';
}
