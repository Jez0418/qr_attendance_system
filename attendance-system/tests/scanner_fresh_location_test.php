<?php
/**
 * Run: php attendance-system/tests/scanner_fresh_location_test.php
 * The scan must carry a location read at the moment the QR code is scanned, not the one read when the page
 * opened (a student could open the page in the lab, walk out, and scan a forwarded QR). The browser code
 * can't run here, so this checks student/scanner.php's source. No database needed. tests/ is not deployed.
 */
$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$src = file_get_contents(__DIR__ . '/../student/scanner.php');
$fn = function (string $name) use ($src): string {   // body of a JS function, up to the next top-level function
    return preg_match('/(?:async\s+)?function\s+' . $name . '\s*\(.*?(?=\n(?:async\s+)?function\s|\n\/\/ |<\/script>)/s', $src, $m) ? $m[0] : '';
};
$fresh = $fn('getFreshPosition');
$scan = $fn('onScanSuccess');

check('getFreshPosition() exists', $fresh !== '');
check('it asks for a new high-accuracy reading (no cached position)', strpos($fresh, 'maximumAge: 0') !== false && strpos($fresh, 'enableHighAccuracy: true') !== false);
check('it gives up after a time limit', preg_match('/timeout:\s*\d+/', $fresh) === 1);
check('onScanSuccess takes a fresh reading', strpos($scan, 'getFreshPosition(') !== false);
check('the fresh reading comes before the scan is sent', strpos($scan, 'getFreshPosition(') !== false && strpos($scan, 'getFreshPosition(') < strpos($scan, "ajaxPost('ajax_scan.php'"));
check('the scan posts the fresh coordinates', preg_match('/latitude:\s*fresh\.latitude/', $scan) === 1 && preg_match('/accuracy:\s*fresh\.accuracy/', $scan) === 1);
check('the scan never posts the page-open reading', strpos($scan, 'currentPosition.latitude') === false);
check('a failed reading stops the scan (no fallback)', preg_match('/catch\s*\([^)]*\)\s*\{[^}]*return;/s', substr($scan, 0, (int) strpos($scan, "ajaxPost('ajax_scan.php'"))) === 1);

// The page's own JavaScript must parse (php -l can't see a broken JS string). Needs Node; skipped without it.
$node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
if ($node !== '') {
    preg_match_all('#<script>(.*?)</script>#s', $src, $m);
    $tmp = tempnam(sys_get_temp_dir(), 'scan') . '.js';
    file_put_contents($tmp, implode("\n", $m[1]));
    exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    @unlink($tmp);
    check('scanner.php JavaScript parses (node --check)', $code === 0);
} else {
    echo "SKIP  scanner.php JavaScript syntax (node not installed)\n";
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
