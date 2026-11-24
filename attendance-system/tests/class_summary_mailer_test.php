<?php
/**
 * Run: php attendance-system/tests/class_summary_mailer_test.php
 * Unit tests for end-of-class attendance summary email generation and configuration.
 * Pure logic only (no database connection required).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/class_summary_mailer.php';

$fail = 0;
function check(string $name, bool $ok): void {
    global $fail;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fail++;
}

// ------------------------------------------------------------------
// Fixtures
// ------------------------------------------------------------------
$session = [
    'session_id'         => 42,
    'teacher_subject_id' => 101,
    'subject_code'       => 'CS301',
    'subject_name'       => 'Computer Networks',
    'section'            => '3A',
    'program'            => 'BSCS',
    'year_level'         => 3,
    'lab_name'           => 'Networking Lab',
    'scheduled_start'    => '2026-11-24 13:00:00',
    'session_end'        => '2026-11-24 15:00:00',
    'session_date'       => '2026-11-24',
    'teacher_name'       => 'Prof. Juan Dela Cruz',
    'teacher_email'      => 'juan.delacruz@example.com',
];

$students = [
    ['student_id' => 1, 'student_number' => '2024-00101', 'full_name' => 'Alice Abbott',   'status' => 'Present', 'time_in' => '2026-11-24 13:04:12'],
    ['student_id' => 2, 'student_number' => '2024-00102', 'full_name' => 'Bob Brown',      'status' => 'Present', 'time_in' => '2026-11-24 13:08:45'],
    ['student_id' => 3, 'student_number' => '2024-00103', 'full_name' => 'Charlie Clark',  'status' => 'Late',    'time_in' => '2026-11-24 13:22:10'],
    ['student_id' => 4, 'student_number' => '2024-00104', 'full_name' => 'David Davis',    'status' => 'Absent',  'time_in' => null],
    ['student_id' => 5, 'student_number' => '2024-00105', 'full_name' => 'Emma Evans',     'status' => 'Absent',  'time_in' => null],
    ['student_id' => 6, 'student_number' => '2024-00106', 'full_name' => 'Frank Franklin', 'status' => 'Excused', 'time_in' => null],
];

// ------------------------------------------------------------------
// Tests
// ------------------------------------------------------------------
$res = build_class_summary_email($session, $students);

check('counts total is 6', $res['counts']['total'] === 6);
check('counts present is 2', $res['counts']['present'] === 2);
check('counts late is 1', $res['counts']['late'] === 1);
check('counts absent is 2', $res['counts']['absent'] === 2);
check('counts excused is 1', $res['counts']['excused'] === 1);

check('subject contains subject code', strpos($res['subject'], 'CS301') !== false);
check('subject contains section', strpos($res['subject'], '3A') !== false);
check('subject contains date', strpos($res['subject'], 'November 24, 2026') !== false);
check('subject mentions [iLABQ]', strpos($res['subject'], '[iLABQ]') !== false);

// HTML checks
$html = $res['html'];
check('HTML contains subject code and name', strpos($html, 'CS301') !== false && strpos($html, 'Computer Networks') !== false);
check('HTML contains section', strpos($html, '3A') !== false);
check('HTML contains lab name', strpos($html, 'Networking Lab') !== false);
check('HTML contains teacher name', strpos($html, 'Prof. Juan Dela Cruz') !== false);
check('HTML contains scheduled time', strpos($html, '1:00 PM') !== false && strpos($html, '3:00 PM') !== false);
check('HTML contains Present student Alice Abbott', strpos($html, 'Alice Abbott') !== false && strpos($html, '2024-00101') !== false);
check('HTML contains Late student Charlie Clark', strpos($html, 'Charlie Clark') !== false && strpos($html, '2024-00103') !== false);
check('HTML contains Absent student David Davis', strpos($html, 'David Davis') !== false && strpos($html, '2024-00104') !== false);
check('HTML contains Excused student Frank Franklin', strpos($html, 'Frank Franklin') !== false);
check('HTML mentions iLABQ brand', strpos($html, 'iLABQ') !== false);

// Plain text checks
$text = $res['text'];
check('Plain text contains subject code', strpos($text, 'CS301 - Computer Networks') !== false);
check('Plain text contains counts', strpos($text, 'Present:        2') !== false && strpos($text, 'Absent:         2') !== false);
check('Plain text lists Alice Abbott under Present', strpos($text, 'Alice Abbott (2024-00101)') !== false);
check('Plain text lists David Davis under Absent', strpos($text, 'David Davis (2024-00104)') !== false);

// Edge case: 100% attendance (0 absent)
$perfectRoster = [
    ['student_id' => 1, 'student_number' => '2024-00101', 'full_name' => 'Alice Abbott', 'status' => 'Present', 'time_in' => '2026-11-24 13:04:12'],
];
$perfectRes = build_class_summary_email($session, $perfectRoster);
check('perfect attendance reports 0 absent', $perfectRes['counts']['absent'] === 0);
check('perfect attendance HTML notes perfect attendance', strpos($perfectRes['html'], 'Perfect attendance') !== false);
check('perfect attendance text notes perfect attendance', strpos($perfectRes['text'], 'Perfect attendance') !== false);

// Verification of files and integration
$absencesCode = file_get_contents(__DIR__ . '/../includes/absences.php');
check('absences.php calls send_pending_class_summary_emails', strpos($absencesCode, 'send_pending_class_summary_emails') !== false);

$functionsCode = file_get_contents(__DIR__ . '/../includes/functions.php');
check('functions.php auto_expire_sessions triggers mark_absent_for_ended_sessions', strpos($functionsCode, 'mark_absent_for_ended_sessions') !== false);

$authPageCode = file_get_contents(__DIR__ . '/../includes/auth_page.php');
check('auth_page.php has updated subtitle', strpos($authPageCode, 'Interactive Laboratory QR Code-Based Attendance and Monitoring System') !== false);

$migrationFile = __DIR__ . '/../database/supabase_session_summary_email.sql';
check('migration file exists', file_exists($migrationFile));

exit($fail > 0 ? 1 : 0);
