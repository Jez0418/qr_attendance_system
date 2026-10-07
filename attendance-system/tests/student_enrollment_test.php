<?php
/**
 * Run: php attendance-system/tests/student_enrollment_test.php
 * Tests the student enrollment pages' helpers (no database needed).
 * tests/ is not deployed (see .vercelignore).
 */
require_once __DIR__ . '/../includes/functions.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

// --- js_attr_json: safe inside onclick='...' AND onclick="..." ---
$data = ['subject_name' => "Women's Health \"Nursing\" <b>&amp;", 'teacher_name' => "D'Souza", 'n' => 5];
$json = js_attr_json($data);
check("no raw ' in the attribute JSON", strpos($json, "'") === false);
check('no raw < > & in the attribute JSON', !preg_match('/[<>&]/', $json));
check('it still decodes to the original data', json_decode($json, true) === $data);
$html = "<button onclick='fn($json)'>";
check('the onclick attribute is not ended early', substr_count($html, "'") === 2);
check('non-ASCII text is kept readable', strpos(js_attr_json(['n' => 'Niño']), 'Niño') !== false);

// --- the "Different Cohort" badge uses the same rule as the server ---
$student = ['institution_id' => 1, 'program_id' => 2, 'year_level' => 3, 'section' => '3A', 'student_type' => 'irregular'];
$asRegular = array_merge($student, ['student_type' => 'regular']);
$offCohort = fn(array $class) => class_cohort_mismatch($asRegular, $class) !== '';
check('same cohort: no badge', !$offCohort(['institution_id' => 1, 'program_id' => 2, 'year_level' => 3, 'section' => 'BSIT-3A']));
check('class with no year/section/program set: no badge (it used to show one)', !$offCohort(['institution_id' => null, 'program_id' => null, 'year_level' => null, 'section' => null]));
check('different year: badge', $offCohort(['institution_id' => 1, 'program_id' => 2, 'year_level' => 2, 'section' => '2A']));
check('different section: badge', $offCohort(['institution_id' => 1, 'program_id' => 2, 'year_level' => 3, 'section' => '3B']));
check('different program: badge', $offCohort(['institution_id' => 1, 'program_id' => 9, 'year_level' => 3, 'section' => '3A']));
check('different institution: badge', $offCohort(['institution_id' => 2, 'program_id' => 2, 'year_level' => 3, 'section' => '3A']));
check('an irregular student is never refused', class_cohort_mismatch($student, ['institution_id' => 2, 'program_id' => 9, 'year_level' => 1, 'section' => '1Z']) === '');

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
