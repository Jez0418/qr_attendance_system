<?php
/**
 * Run: php attendance-system/tests/lab_deactivate_test.php
 * A laboratory cannot be set to Inactive while enabled classes meet there (includes/admin_delete.php
 * lab_deactivation_blocker(), used by admin/ajax_laboratories.php). Pure logic, no database.
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/admin_delete.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

check('no classes and no moved-in meetings: allowed', lab_deactivation_blocker(0, 0) === '');
$one = lab_deactivation_blocker(1, 0);
check('one enabled class blocks, singular wording', $one !== '' && strpos($one, '1 enabled class assigned') !== false && strpos($one, 'classes') === false);
$many = lab_deactivation_blocker(3, 0);
check('several enabled classes block, plural wording', strpos($many, '3 enabled classes assigned') !== false);
$moved = lab_deactivation_blocker(0, 2);
check('upcoming meetings rescheduled into the lab block', strpos($moved, '2 upcoming meetings rescheduled into it') !== false);
$both = lab_deactivation_blocker(2, 1);
check('both reasons are listed', strpos($both, 'enabled classes') !== false && strpos($both, 'rescheduled into it') !== false);
check('the message tells the admin what to do', strpos($one, 'Disable those classes or move them') !== false);
check('the message has no markup (toasts use innerHTML)', strpos($both, '<') === false && strpos($both, '>') === false);

$src = file_get_contents(__DIR__ . '/../admin/ajax_laboratories.php');
check('the update action uses the guard', strpos($src, 'lab_deactivation_blocker(') !== false);
check('only an Active -> Inactive change is checked (an inactive lab stays editable)',
    strpos($src, "=== 'active'") !== false);

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
