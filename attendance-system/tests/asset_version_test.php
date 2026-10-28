<?php
/**
 * Run: php attendance-system/tests/asset_version_test.php
 * Stale CSS/JS after a deploy (no database needed). Vercel gives every deployed file the same mtime, so a
 * filemtime() stamp never changes, and sw.php serves assets/ from its cache first: the first load after a deploy
 * paired new HTML with the old style.css. Asset URLs must carry a hash of the file's contents instead.
 * tests/ is not deployed (see .vercelignore).
 */
$root = __DIR__ . '/..';
$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once "$root/includes/asset_url.php";

$css = asset_url('assets/css/style.css');
check('asset_url() adds a content hash', (bool) preg_match('#^/assets/css/style\.css\?v=[0-9a-f]{10}$#', $css));
check('asset_url() hash matches the file contents', $css === '/assets/css/style.css?v=' . substr(md5_file("$root/assets/css/style.css"), 0, 10));
check('asset_url() leaves a missing file unversioned', asset_url('assets/css/missing.css') === '/assets/css/missing.css');

// Every page shell and shared include must link style.css / app.js / theme.js / pwa.js through asset_url().
$files = ['includes/header.php', 'includes/footer.php', 'includes/auth_page.php', 'login.php', 'includes/theme.php', 'includes/pwa.php'];
foreach ($files as $f) {
    $src = file_get_contents("$root/$f");
    check("$f has no filemtime() asset stamp", strpos($src, 'filemtime') === false);
    check("$f has no unversioned style.css link", strpos($src, "BASE_URL; ?>assets/css/style.css\"") === false);
}

$sw = file_get_contents("$root/sw.php");
check('sw.php versions its cache by file contents, not mtime', strpos($sw, 'md5_file') !== false && strpos($sw, '@filemtime') === false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
