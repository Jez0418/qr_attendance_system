<?php
/**
 * update_program_departments.php
 * Updates existing programs to their correct departments.
 * Run this via browser: http://localhost/attendance-system/database/update_program_departments.php
 * Or via CLI: php database/update_program_departments.php
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "<pre style='font-family:monospace;padding:20px;'>";

try {
    // Get institution IDs
    $stmt = $pdo->query("SELECT institution_id, institution_code FROM institutions");
    $institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $instMap = [];
    foreach ($institutions as $inst) {
        $instMap[$inst['institution_code']] = $inst['institution_id'];
    }

    $isapId = $instMap['ISAP'];
    $mcnpId = $instMap['MCNP'];

    // Get department IDs
    $stmt = $pdo->query("SELECT department_id, institution_id, department_code, department_name FROM departments");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deptMap = [];
    foreach ($departments as $dept) {
        $key = $dept['institution_id'] . ':' . $dept['department_code'];
        $deptMap[$key] = $dept['department_id'];
    }

    // ISAP program -> department mapping
    $isapProgramDeptMap = [
        // College of Arts, Sciences and Teacher Education (CASTE)
        'BSSW' => 'CASTE',
        'BSPSY' => 'CASTE',
        // BSEd majors are new so they're already in CASTE
        // BSPE is new so it's already in CASTE

        // College of Information Technology and Engineering (CITE)
        'BSIT' => 'CITE',
        'BSCpE' => 'CITE',
        'BSCPE' => 'CITE',  // duplicate code from migration

        // College of Business Education and Management (CBEM)
        'BSCA' => 'CBEM',
        'BSBA' => 'CBEM',
        'BSA' => 'CBEM',
        'BSHM' => 'CBEM',
        'BSTM' => 'CBEM',

        // College of Criminal Justice Education (CCJE)
        'BSCRIM' => 'CCJE',

        // Basic Education (BASICED)
        // SHS and JHS are new

        // TVET Programs (TVET)
        // FBS_NCII, HK_NCII, CSS_NCII are new
    ];

    // MCNP program -> department mapping
    $mcnpProgramDeptMap = [
        // Allied Health & Medical Programs (ALLIED_HEALTH)
        'BSN' => 'ALLIED_HEALTH',
        'BSRT' => 'ALLIED_HEALTH',
        'BSMLS' => 'ALLIED_HEALTH',
        'BSPh' => 'ALLIED_HEALTH',
        'BSPT' => 'ALLIED_HEALTH',

        // Short-Term & Technical Programs (SHORT_TERM)
        'MIDWIFERY' => 'SHORT_TERM',
        'DENTAL_TECH' => 'SHORT_TERM',
        'PHARMACY_AIDE' => 'SHORT_TERM',
        // CAREGIVING_NCII and CSS_NCII_MCNP are new
    ];

    echo "--- Updating ISAP Programs ---\n";
    foreach ($isapProgramDeptMap as $progCode => $deptCode) {
        $deptKey = "$isapId:$deptCode";
        if (!isset($deptMap[$deptKey])) {
            echo "  SKIP: $progCode -> $deptCode (Department not found)\n";
            continue;
        }
        $deptId = $deptMap[$deptKey];

        // Check current department
        $check = $pdo->prepare("SELECT program_id, department_id FROM programs WHERE program_code = ? AND institution_id = ?");
        $check->execute([$progCode, $isapId]);
        $prog = $check->fetch();

        if (!$prog) {
            echo "  SKIP: $progCode (Program not found for ISAP)\n";
            continue;
        }

        if ($prog['department_id'] == $deptId) {
            echo "  OK: $progCode already in $deptCode\n";
            continue;
        }

        $upd = $pdo->prepare("UPDATE programs SET department_id = ? WHERE program_id = ?");
        $upd->execute([$deptId, $prog['program_id']]);
        echo "  UPDATED: $progCode -> $deptCode\n";
    }

    echo "\n--- Updating MCNP Programs ---\n";
    foreach ($mcnpProgramDeptMap as $progCode => $deptCode) {
        $deptKey = "$mcnpId:$deptCode";
        if (!isset($deptMap[$deptKey])) {
            echo "  SKIP: $progCode -> $deptCode (Department not found)\n";
            continue;
        }
        $deptId = $deptMap[$deptKey];

        // Check current department
        $check = $pdo->prepare("SELECT program_id, department_id FROM programs WHERE program_code = ? AND institution_id = ?");
        $check->execute([$progCode, $mcnpId]);
        $prog = $check->fetch();

        if (!$prog) {
            echo "  SKIP: $progCode (Program not found for MCNP)\n";
            continue;
        }

        if ($prog['department_id'] == $deptId) {
            echo "  OK: $progCode already in $deptCode\n";
            continue;
        }

        $upd = $pdo->prepare("UPDATE programs SET department_id = ? WHERE program_id = ?");
        $upd->execute([$deptId, $prog['program_id']]);
        echo "  UPDATED: $progCode -> $deptCode\n";
    }

    // Verify all programs
    echo "\n--- Updated Programs ---\n";
    $stmt = $pdo->query("
        SELECT p.program_id, p.program_code, p.program_name, p.program_type, p.duration_years,
               i.institution_code, d.department_code, d.department_name
        FROM programs p
        LEFT JOIN institutions i ON i.institution_id = p.institution_id
        LEFT JOIN departments d ON d.department_id = p.department_id
        ORDER BY i.institution_code, d.department_code, p.program_code
    ");
    $allProgs = $stmt->fetchAll();

    $currentInst = '';
    $currentDept = '';
    foreach ($allProgs as $p) {
        if ($p['institution_code'] !== $currentInst) {
            $currentInst = $p['institution_code'];
            $currentDept = '';
            echo "\n  === {$p['institution_code']} ===\n";
        }
        if ($p['department_code'] !== $currentDept) {
            $currentDept = $p['department_code'];
            echo "    [{$p['department_code']}] {$p['department_name']}\n";
        }
        echo "      {$p['program_code']} - {$p['program_name']} ({$p['program_type']}, {$p['duration_years']}yrs)\n";
    }

    echo "\n✅ Program departments updated successfully!\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";