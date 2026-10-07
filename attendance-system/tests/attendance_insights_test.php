<?php
/**
 * Run: php attendance-system/tests/attendance_insights_test.php
 * Attendance warnings, absence notifications, per-class late grace, the PWA files and the cached
 * avatar (no database needed). tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attendance_stats.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }
$root = __DIR__ . '/..';
$read = fn(string $f) => file_get_contents("$root/$f");

// ---- Standing rules (includes/attendance_stats.php) ----
$limits = ['absence_limit' => 3, 'min_rate' => 80];
$s = attendance_standing(0, 0, $limits);
check('no meetings: no rate, not flagged', $s['rate'] === null && $s['level'] === STANDING_OK);
$s = attendance_standing(9, 1, $limits);
check('9 of 10: 90%, good standing', $s['rate'] === 90 && $s['level'] === STANDING_OK && $s['meetings'] === 10 && $s['label'] === 'Good standing');
check('1 of 2 early in term: OK but no badge yet', attendance_standing(1, 1, $limits)['label'] === '');
$s = attendance_standing(8, 2, $limits);
check('2 absences with a limit of 3: warning', $s['level'] === STANDING_WARNING && $s['badge'] === 'badge-late');
$s = attendance_standing(7, 3, $limits);
check('3 absences with a limit of 3: over', $s['level'] === STANDING_OVER && $s['badge'] === 'badge-absent');
$s = attendance_standing(0, 1, $limits);
check('1 absence in week one is not "below 80%" (minimum meetings)', $s['level'] === STANDING_OK && $s['rate'] === 0);
$s = attendance_standing(3, 2, ['absence_limit' => 0, 'min_rate' => 80]);
check('below the minimum rate after enough meetings: over', $s['level'] === STANDING_OVER && $s['rate'] === 60);
$s = attendance_standing(1, 9, ['absence_limit' => 0, 'min_rate' => 0]);
check('both limits off: never flagged', $s['level'] === STANDING_OK);
$s = attendance_standing(2, 1, ['absence_limit' => 1, 'min_rate' => 0]);
check('limit 1: no "1 away" warning at 0, over at 1', $s['level'] === STANDING_OVER
    && attendance_standing(2, 0, ['absence_limit' => 1, 'min_rate' => 0])['level'] === STANDING_OK);
check('rate rounds down (2 of 3 = 66%)', attendance_standing(2, 1, $limits)['rate'] === 66);
check('absence label with a limit', absence_count_label(2, $limits) === '2 of 3 absences allowed');
check('absence label stays plural for 1 of N', absence_count_label(1, $limits) === '1 of 3 absences allowed');
check('absence notice date reads "Thu, Oct 8, 1:59 AM"', strpos(file_get_contents(__DIR__ . '/../includes/absences.php'), "date('D, M j, g:i A'") !== false);
check('absence label, singular, no limit', absence_count_label(1, ['absence_limit' => 0, 'min_rate' => 0]) === '1 absence');
$sorted = sort_by_standing([
    ['standing' => attendance_standing(9, 1, $limits)],
    ['standing' => attendance_standing(5, 3, $limits)],
    ['standing' => attendance_standing(8, 2, $limits)],
]);
check('worst standing first', array_map(fn($r) => $r['standing']['level'], $sorted) === [STANDING_OVER, STANDING_WARNING, STANDING_OK]);

// ---- Absence notifications (includes/absences.php) ----
$abs = $read('includes/absences.php');
check('absence insert returns the new rows', strpos($abs, 'RETURNING session_id, student_id') !== false);
check('students are notified after the commit', strpos($abs, 'notify_new_absences($pdo, $added)') !== false
    && strpos($abs, '$pdo->commit();') < strpos($abs, 'notify_new_absences($pdo, $added)'));
check('a notification failure is caught, never undoes absences', strpos($abs, "error_log('notify_new_absences: '") !== false);
check('notifications are inserted in batches, not one query per student', strpos($abs, 'array_chunk($notes, 100)') !== false);
check('teacher is told when a student reaches the limit', strpos($abs, 'Absence Limit Reached') !== false);

// ---- Per-class late grace ----
check('schedule exposes the class late grace', strpos($read('includes/schedule.php'), "'late_grace_minutes'") !== false);
check('scan uses the class late grace', strpos($read('student/ajax_scan.php'), "get_late_grace_minutes(\$pdo, \$occ['late_grace_minutes']") !== false);
check('teacher session page uses the class late grace', strpos($read('teacher/session.php'), "get_late_grace_minutes(\$pdo, \$occ['late_grace_minutes']") !== false);
check('new sessions store the class late grace', strpos($read('qr/session_manager.php'), "get_late_grace_minutes(\$pdo, \$occ['late_grace_minutes'] ?? null), (int) \$lab") !== false);
check('assignment form saves late_grace_minutes', substr_count($read('admin/ajax_assignments.php'), "\$a['late_grace_minutes']") === 2);
check('migration adds the column', strpos($read('database/supabase_attendance_insights.sql'), 'ADD COLUMN IF NOT EXISTS late_grace_minutes') !== false);
check('full schema has the column', strpos($read('database/supabase_schema.sql'), 'late_grace_minutes SMALLINT NULL') !== false);

// ---- PWA ----
$manifest = json_decode($read('assets/manifest.json'), true);
check('manifest is valid JSON', is_array($manifest));
check('manifest is standalone with a scope', ($manifest['display'] ?? '') === 'standalone' && ($manifest['scope'] ?? '') === '../');
$sizes = [];
foreach ($manifest['icons'] ?? [] as $icon) {
    $sizes[] = $icon['sizes'];
    check("icon {$icon['src']} exists", is_file("$root/assets/{$icon['src']}"));
}
check('manifest has 192 and 512 icons', in_array('192x192', $sizes, true) && in_array('512x512', $sizes, true));
check('apple-touch-icon exists', is_file("$root/assets/img/app-icon-180.png"));
foreach (['includes/header.php', 'login.php', 'includes/auth_page.php'] as $shell) {
    check("$shell has the PWA head tags", strpos($read($shell), 'pwa_head_tags()') !== false);
}
$sw = $read('sw.php');
check('service worker needs no login or database', strpos($sw, 'require') === false && strpos($sw, '$pdo') === false);
check('service worker never caches pages (network first for navigations)', strpos($sw, "req.mode === 'navigate'") !== false && strpos($sw, 'fetch(req).catch(') !== false);
check('service worker ignores POST', strpos($sw, "req.method !== 'GET'") !== false);
check('offline page exists', is_file("$root/assets/offline.html"));
check('installed app sends students to the scanner', strpos($read('index.php'), "redirect('student/scanner.php')") !== false);

// ---- Cached avatar ----
$header = $read('includes/header.php');
check('header no longer reads the photo column', strpos($header, 'SELECT photo') === false);
check('header uses the cached avatar URL', strpos($header, 'current_photo_url($pdo)') !== false);
$avatar = $read('avatar.php');
check('avatar requires a login', strpos($avatar, 'require_login()') !== false);
check('avatar is only cached privately', strpos($avatar, 'Cache-Control: private, max-age=31536000') !== false);
check('saving a photo forgets the cached hash', strpos($read('student/profile.php'), 'forget_photo_cache()') !== false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
