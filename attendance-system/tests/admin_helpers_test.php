<?php
/**
 * Run: php attendance-system/tests/admin_helpers_test.php
 * Tests the helpers from the admin review (no database needed): text length limits for notifications and the
 * activity log, friendly length errors, id/date filters, and the session-ending patterns.
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

/** Stand-in for PDO that records what would have been sent. */
class FakeStmt { public array $args = []; public function execute($a = null) { $this->args = $a ?? []; return true; } }
class FakePdo extends PDO {
    public ?FakeStmt $last = null;
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) { return $this->last = new FakeStmt(); }
}
$pdo = new FakePdo();

// --- fit_text ---
check('short text is unchanged', fit_text('hello', 10) === 'hello');
check('text of exactly the limit is unchanged', fit_text(str_repeat('a', 10), 10) === str_repeat('a', 10));
$cut = fit_text(str_repeat('a', 50), 10);
check('long text is cut to the limit', mb_strlen($cut) === 10 && substr($cut, -3) === '…');
check('multibyte text is cut by characters', mb_strlen(fit_text(str_repeat('é', 50), 10)) === 10);

// --- the two helpers that write long free text ---
log_activity($pdo, 1, 'Changed ' . str_repeat('N', 150) . ' from Absent to Present for session #12 - ' . str_repeat('r', 255));
check('log_activity never exceeds the 255-character column', mb_strlen($pdo->last->args[1]) <= 255);
log_activity($pdo, 1, 'Logged in');
check('a normal log line is stored as written', $pdo->last->args[1] === 'Logged in');
create_notification($pdo, 1, str_repeat('T', 400), str_repeat('m', 900));
check('notification title is cut to 150', mb_strlen($pdo->last->args[1]) === 150);
check('notification message is cut to 500', mb_strlen($pdo->last->args[2]) === 500);
create_notification($pdo, 1, 'Class Cancelled', 'Short message');
check('a normal notification is stored as written', $pdo->last->args[1] === 'Class Cancelled' && $pdo->last->args[2] === 'Short message');

// --- check_lengths ---
$threw = function (array $f) { try { check_lengths($f); return false; } catch (Exception $e) { return $e->getMessage(); } };
check('values within the limits pass', $threw(['Username' => ['abc', 50]]) === false);
check('a too-long value names the field and the limit', $threw(['Username' => [str_repeat('u', 51), 50]]) === 'Username must be at most 50 characters.');
check('length is counted in characters', $threw(['Name' => [str_repeat('é', 50), 50]]) === false);

// --- id_param ---
check('id_param accepts a number', id_param('12') === '12');
check('id_param normalises leading zeros', id_param('007') === '7');
foreach (['abc', '', '0', '-1', '1.5', '1 OR 1=1', '99999999999', null] as $v) check('id_param rejects ' . var_export($v, true), id_param($v) === '');

// --- the session-ending patterns match PHP's real encoding and only that user ---
$capture = new class extends PDO {
    public array $calls = [];
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) {
        $s = new class { public $owner; public function execute($a = null) { $this->owner->calls[] = $a; return true; } };
        $s->owner = $this;
        return $s;
    }
};
destroy_user_sessions($capture, 5);
check('destroy_user_sessions sends the int and the string pattern', $capture->calls[0] === ['%user_id|i:5;%', '%user_id|s:1:"5";%']);
$like = fn(string $pattern, string $data) => (bool) preg_match('/' . str_replace('%', '.*', preg_quote(trim($pattern, '%'), '/')) . '/s', $data);
check('the pattern matches that user', $like($capture->calls[0][0], 'csrf_token|s:3:"abc";user_id|i:5;role|s:7:"teacher";'));
check('the pattern does not match user 15', !$like($capture->calls[0][0], 'user_id|i:15;'));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
