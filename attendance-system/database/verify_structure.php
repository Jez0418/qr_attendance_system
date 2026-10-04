<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$stmt = $pdo->query("
    SELECT i.institution_code, d.department_code, d.department_name, p.program_code, p.program_name, p.program_type, p.duration_years
    FROM institutions i
    LEFT JOIN departments d ON d.institution_id = i.institution_id
    LEFT JOIN programs p ON p.institution_id = i.institution_id AND p.department_id = d.department_id
    ORDER BY i.institution_code, d.department_code, p.program_code
");
$results = $stmt->fetchAll();

$currentInst = '';
$currentDept = '';
foreach ($results as $r) {
    if ($r['institution_code'] !== $currentInst) {
        $currentInst = $r['institution_code'];
        $currentDept = '';
        echo "\n=== $currentInst ===\n";
    }
    if ($r['department_code'] !== $currentDept) {
        $currentDept = $r['department_code'];
        echo "  [$currentDept] $r[department_name]\n";
    }
    if ($r['program_code']) {
        echo "    $r[program_code] - $r[program_name] ($r[program_type], $r[duration_years]yrs)\n";
    }
}