<?php
/**
 * Run: php attendance-system/tests/theme_test.php
 * Light / Dark / System theme (no database needed): every full HTML page must load the theme script in <head>
 * (so the saved choice is applied before first paint), the dark palette must exist, and surfaces must not be
 * hard-coded white. tests/ is not deployed (see .vercelignore).
 */
$root = __DIR__ . '/..';
$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$css = @file_get_contents("$root/assets/css/style.css") ?: '';
$js  = @file_get_contents("$root/assets/js/theme.js") ?: '';
check('assets/js/theme.js exists', $js !== '');
check('theme.js understands light, dark and system', strpos($js, "'light'") !== false && strpos($js, "'dark'") !== false && strpos($js, "'system'") !== false);
check('theme.js follows the operating system setting', strpos($js, 'prefers-color-scheme') !== false);
check('theme.js sets data-theme before the page paints', strpos($js, "setAttribute('data-theme'") !== false);
check('style.css has a dark palette', strpos($css, ':root[data-theme="dark"]') !== false);
check('style.css tells the browser it is dark (native controls)', strpos($css, 'color-scheme:dark') !== false);

// Pages that print their own <html> must load the theme script (header.php, login.php, auth_page.php).
foreach (['includes/header.php', 'login.php', 'includes/auth_page.php'] as $f) {
    check("$f loads the theme script in <head>", strpos(file_get_contents("$root/$f"), 'theme_head_tags()') !== false);
}
check('header.php shows the theme switcher', strpos(file_get_contents("$root/includes/header.php"), 'theme_switcher()') !== false);
check('login.php shows the theme switcher', strpos(file_get_contents("$root/login.php"), 'theme_switcher()') !== false);

// Surfaces must use the --surface variable, not white. Allowed: the QR code canvas (a QR needs a white quiet zone),
// the toggle knob and the round logos.
$bad = [];
foreach (preg_split('/\R/', $css) as $i => $line) {
    if (!preg_match('/background:\s*#fff(fff)?\b/i', $line)) continue;
    if (preg_match('/qrcodeCanvas|toggle-slider::before|lg-logo|brand-emblem|0 0 0 3px #fff|:root\[data-theme="dark"\]/', $line)) continue;
    $bad[] = $i + 1;
}
check('no hard-coded white surfaces in style.css (lines: ' . implode(',', $bad) . ')', !$bad);

// Pages must not hard-code the light-only status colours inline.
$scanner = file_get_contents("$root/student/scanner.php");
check('scanner result boxes use theme classes, not fixed light backgrounds', strpos($scanner, 'background:#dcfce7') === false && strpos($scanner, 'background:#fee2e2') === false);

// Charts read their colours from the theme and are rebuilt when it changes.
foreach (['admin/dashboard.php', 'admin/reports.php'] as $f) {
    $src = file_get_contents("$root/$f");
    check("$f charts follow the theme", strpos($src, 'themeChartDefaults') !== false && strpos($src, "'themechange'") !== false);
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
