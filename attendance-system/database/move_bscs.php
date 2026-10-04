<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

try {
    // Move BSCS to CITE department for ISAP
    $stmt = $pdo->prepare("
        UPDATE programs p
        JOIN departments d ON d.institution_id = p.institution_id AND d.department_code = 'CITE'
        SET p.department_id = d.department_id
        WHERE p.program_code = 'BSCS' AND p.institution_id = (SELECT institution_id FROM institutions WHERE institution_code = 'ISAP')
    ");
    $stmt->execute();
    echo "Moved BSCS to CITE department. Rows affected: " . $stmt->rowCount() . "\n";

    // Verify
    $stmt = $pdo->query("
        SELECT p.program_code, p.program_name, d.department_code, d.department_name
        FROM programs p
        LEFT JOIN departments d ON d.department_id = p.department_id
        WHERE p.program_code = 'BSCS'
    ");
    $result = $stmt->fetch();
    echo "BSCS is now in: " . $result['department_code'] . " - " . $result['department_name'] . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}