<?php
/**
 * insert_departments.php
 * Inserts all departments for ISAP and MCNP institutions.
 * Run this via browser: http://localhost/attendance-system/database/insert_departments.php
 * Or via CLI: php database/insert_departments.php
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "<pre style='font-family:monospace;padding:20px;'>";

try {
    // Get institution IDs
    $stmt = $pdo->query("SELECT institution_id, institution_code, institution_name FROM institutions");
    $institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $instMap = [];
    foreach ($institutions as $inst) {
        $instMap[$inst['institution_code']] = $inst['institution_id'];
        echo "Found institution: {$inst['institution_code']} - {$inst['institution_name']} (ID: {$inst['institution_id']})\n";
    }

    if (!isset($instMap['ISAP']) || !isset($instMap['MCNP'])) {
        throw new Exception("Required institutions (ISAP, MCNP) not found in database!");
    }

    $isapId = $instMap['ISAP'];
    $mcnpId = $instMap['MCNP'];

    // Departments for ISAP
    $isapDepartments = [
        ['CASTE', 'College of Arts, Sciences and Teacher Education'],
        ['CITE', 'College of Information Technology and Engineering'],
        ['CBEM', 'College of Business Education and Management'],
        ['CCJE', 'College of Criminal Justice Education'],
        ['BASICED', 'Basic Education'],
        ['TVET', 'TVET Programs (TESDA)'],
    ];

    // Departments for MCNP
    $mcnpDepartments = [
        ['ALLIED_HEALTH', 'Allied Health & Medical Programs'],
        ['SHORT_TERM', 'Short-Term & Technical Programs'],
    ];

    echo "\n--- Inserting ISAP Departments ---\n";
    foreach ($isapDepartments as $dept) {
        $code = $dept[0];
        $name = $dept[1];

        // Check if already exists
        $check = $pdo->prepare("SELECT department_id FROM departments WHERE institution_id = ? AND department_code = ?");
        $check->execute([$isapId, $code]);
        if ($check->fetch()) {
            echo "  SKIP: $code - $name (already exists)\n";
            continue;
        }

        $ins = $pdo->prepare("INSERT INTO departments (institution_id, department_code, department_name) VALUES (?, ?, ?)");
        $ins->execute([$isapId, $code, $name]);
        echo "  ADDED: $code - $name\n";
    }

    echo "\n--- Inserting MCNP Departments ---\n";
    foreach ($mcnpDepartments as $dept) {
        $code = $dept[0];
        $name = $dept[1];

        // Check if already exists
        $check = $pdo->prepare("SELECT department_id FROM departments WHERE institution_id = ? AND department_code = ?");
        $check->execute([$mcnpId, $code]);
        if ($check->fetch()) {
            echo "  SKIP: $code - $name (already exists)\n";
            continue;
        }

        $ins = $pdo->prepare("INSERT INTO departments (institution_id, department_code, department_name) VALUES (?, ?, ?)");
        $ins->execute([$mcnpId, $code, $name]);
        echo "  ADDED: $code - $name\n";
    }

    // Verify all departments
    echo "\n--- Current Departments ---\n";
    $stmt = $pdo->query("
        SELECT d.department_id, d.institution_id, i.institution_code, d.department_code, d.department_name
        FROM departments d
        JOIN institutions i ON i.institution_id = d.institution_id
        ORDER BY i.institution_code, d.department_code
    ");
    $allDepts = $stmt->fetchAll();

    foreach ($allDepts as $d) {
        echo "  [{$d['institution_code']}] {$d['department_code']} - {$d['department_name']}\n";
    }

    echo "\n✅ All departments inserted successfully!\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";