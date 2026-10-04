<?php
/**
 * insert_programs.php
 * Inserts all programs for ISAP and MCNP institutions, mapped to their departments.
 * Run this via browser: http://localhost/attendance-system/database/insert_programs.php
 * Or via CLI: php database/insert_programs.php
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

    if (!isset($instMap['ISAP']) || !isset($instMap['MCNP'])) {
        throw new Exception("Required institutions (ISAP, MCNP) not found!");
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
        echo "Dept: [{$dept['institution_id']}] {$dept['department_code']} - {$dept['department_name']} (ID: {$dept['department_id']})\n";
    }

    // ============================================================
    // ISAP PROGRAMS
    // ============================================================
    $isapPrograms = [
        // College of Arts, Sciences and Teacher Education (CASTE)
        ['BSSW', 'BS in Social Work', 'Bachelor\'s Degree', 4.0, 'CASTE'],
        ['BSED_ENG', 'BSEd Major in English', 'Bachelor\'s Degree', 4.0, 'CASTE'],
        ['BSED_MATH', 'BSEd Major in Mathematics', 'Bachelor\'s Degree', 4.0, 'CASTE'],
        ['BSED_SOCSCI', 'BSEd Major in Social Science', 'Bachelor\'s Degree', 4.0, 'CASTE'],
        ['BSED_SCI', 'BSEd Major in Science', 'Bachelor\'s Degree', 4.0, 'CASTE'],
        ['BSED_FIL', 'BSEd Major in Filipino', 'Bachelor\'s Degree', 4.0, 'CASTE'],
        ['BSPSY', 'BS in Psychology', 'Bachelor\'s Degree', 4.0, 'CASTE'],
        ['BSPE', 'BS in Physical Education', 'Bachelor\'s Degree', 4.0, 'CASTE'],

        // College of Information Technology and Engineering (CITE)
        ['BSIT', 'BS in Information Technology', 'Bachelor\'s Degree', 4.0, 'CITE'],
        ['BSCPE', 'BS in Computer Engineering', 'Bachelor\'s Degree', 4.0, 'CITE'],

        // College of Business Education and Management (CBEM)
        ['BSCA', 'BS in Customs Administration', 'Bachelor\'s Degree', 4.0, 'CBEM'],
        ['BSBA', 'BS in Business Administration', 'Bachelor\'s Degree', 4.0, 'CBEM'],
        ['BSA', 'BS in Accountancy', 'Bachelor\'s Degree', 4.0, 'CBEM'],
        ['BSHM', 'BS in Hospitality Management', 'Bachelor\'s Degree', 4.0, 'CBEM'],
        ['BSTM', 'BS in Tourism Management', 'Bachelor\'s Degree', 4.0, 'CBEM'],

        // College of Criminal Justice Education (CCJE)
        ['BSCRIM', 'BS in Criminology', 'Bachelor\'s Degree', 4.0, 'CCJE'],

        // Basic Education (BASICED)
        ['SHS', 'Senior High School', 'Diploma', 2.0, 'BASICED'],
        ['JHS', 'Junior High School (K-12 Special Science Curriculum)', 'Diploma', 4.0, 'BASICED'],

        // TVET Programs (TESDA) (TVET)
        ['FBS_NCII', 'Food and Beverages NCII', 'Diploma', 1.0, 'TVET'],
        ['HK_NCII', 'Housekeeping NCII', 'Diploma', 1.0, 'TVET'],
        ['CSS_NCII', 'Computer Systems Servicing NCII', 'Diploma', 1.0, 'TVET'],
    ];

    // ============================================================
    // MCNP PROGRAMS
    // ============================================================
    $mcnpPrograms = [
        // Allied Health & Medical Programs (ALLIED_HEALTH)
        ['BSN', 'BS in Nursing', 'Bachelor\'s Degree', 4.0, 'ALLIED_HEALTH'],
        ['BSRT', 'BS in Radiologic Technology', 'Bachelor\'s Degree', 4.0, 'ALLIED_HEALTH'],
        ['BSMLS', 'BS in Medical Laboratory Science', 'Bachelor\'s Degree', 4.0, 'ALLIED_HEALTH'],
        ['BSPH', 'BS in Pharmacy', 'Bachelor\'s Degree', 4.0, 'ALLIED_HEALTH'],
        ['BSPT', 'BS in Physical Therapy', 'Bachelor\'s Degree', 4.0, 'ALLIED_HEALTH'],

        // Short-Term & Technical Programs (SHORT_TERM)
        ['MIDWIFERY', 'Diploma in Midwifery', 'Diploma', 2.0, 'SHORT_TERM'],
        ['DENTAL_TECH', 'Diploma in Dental Technology', 'Diploma', 2.0, 'SHORT_TERM'],
        ['PHARMACY_AIDE', 'Diploma in Pharmacy Aide', 'Diploma', 2.0, 'SHORT_TERM'],
        ['CAREGIVING_NCII', 'Caregiving NC II', 'Diploma', 1.0, 'SHORT_TERM'],
        ['CSS_NCII_MCNP', 'Computer Systems Servicing NC II', 'Diploma', 1.0, 'SHORT_TERM'],
    ];

    echo "\n--- Inserting ISAP Programs ---\n";
    foreach ($isapPrograms as $prog) {
        $code = $prog[0];
        $name = $prog[1];
        $type = $prog[2];
        $duration = $prog[3];
        $deptCode = $prog[4];

        $deptKey = "$isapId:$deptCode";
        if (!isset($deptMap[$deptKey])) {
            echo "  SKIP: $code - $name (Department $deptCode not found for ISAP)\n";
            continue;
        }
        $deptId = $deptMap[$deptKey];

        // Check if already exists
        $check = $pdo->prepare("SELECT program_id FROM programs WHERE program_code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            echo "  SKIP: $code - $name (already exists)\n";
            continue;
        }

        $ins = $pdo->prepare("INSERT INTO programs (program_code, program_name, program_type, duration_years, institution_id, department_id) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$code, $name, $type, $duration, $isapId, $deptId]);
        echo "  ADDED: $code - $name ($type, {$duration}yrs) -> $deptCode\n";
    }

    echo "\n--- Inserting MCNP Programs ---\n";
    foreach ($mcnpPrograms as $prog) {
        $code = $prog[0];
        $name = $prog[1];
        $type = $prog[2];
        $duration = $prog[3];
        $deptCode = $prog[4];

        $deptKey = "$mcnpId:$deptCode";
        if (!isset($deptMap[$deptKey])) {
            echo "  SKIP: $code - $name (Department $deptCode not found for MCNP)\n";
            continue;
        }
        $deptId = $deptMap[$deptKey];

        // Check if already exists
        $check = $pdo->prepare("SELECT program_id FROM programs WHERE program_code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            echo "  SKIP: $code - $name (already exists)\n";
            continue;
        }

        $ins = $pdo->prepare("INSERT INTO programs (program_code, program_name, program_type, duration_years, institution_id, department_id) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$code, $name, $type, $duration, $mcnpId, $deptId]);
        echo "  ADDED: $code - $name ($type, {$duration}yrs) -> $deptCode\n";
    }

    // Verify all programs
    echo "\n--- Current Programs ---\n";
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

    echo "\n✅ All programs inserted successfully!\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";