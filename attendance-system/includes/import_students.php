<?php
/**
 * ------------------------------------------------------------
 * import_students.php
 * Validation + commit for the admin "batch create student accounts"
 * import. Mirrors the rules of admin/ajax_students.php (create).
 *
 *   validate_student_import_rows()  - read-only, returns one result per row
 *   commit_student_import()         - one transaction, all valid rows or nothing
 * ------------------------------------------------------------
 */

const STUDENT_IMPORT_MAX_ROWS = 50;
const STUDENT_IMPORT_COLUMNS = ['student_number', 'full_name', 'email', 'username', 'password',
                                'institution_code', 'program_code', 'year_level', 'section',
                                'student_type', 'contact_number'];
const STUDENT_IMPORT_REQUIRED = ['student_number', 'full_name', 'email', 'institution_code', 'program_code', 'year_level', 'section'];

/**
 * @return array[] one entry per row: ['line','row','errors'=>[], 'ok'=>bool,
 *                 'values'=>[...normalised insert values] when ok]
 */
function validate_student_import_rows(PDO $pdo, array $rows): array {
    $institutions = [];
    foreach ($pdo->query("SELECT institution_id, institution_code FROM institutions WHERE status = 'active'")->fetchAll() as $i) {
        $institutions[strtoupper($i['institution_code'])] = (int) $i['institution_id'];
    }
    $programs = [];   // "INST_ID|CODE" => program
    foreach ($pdo->query("SELECT program_id, institution_id, department_id, program_code, duration_years FROM programs WHERE status = 'active'")->fetchAll() as $p) {
        $programs[(int) $p['institution_id'] . '|' . strtoupper($p['program_code'])] = $p;
    }

    // Existing accounts (case-insensitive) in one round trip each
    $existing = ['username' => [], 'email' => [], 'student_number' => []];
    $want = ['username' => [], 'email' => [], 'student_number' => []];
    foreach ($rows as $r) {
        $want['student_number'][] = strtolower($r['student_number'] ?? '');
        $want['email'][] = strtolower($r['email'] ?? '');
        $want['username'][] = strtolower(student_import_username($r));
    }
    $lookup = ['username' => 'SELECT LOWER(username) FROM users WHERE LOWER(username) IN (%s)',
               'email' => 'SELECT LOWER(email) FROM users WHERE LOWER(email) IN (%s)',
               'student_number' => 'SELECT LOWER(student_number) FROM students WHERE LOWER(student_number) IN (%s)'];
    foreach ($lookup as $key => $sql) {
        $vals = array_values(array_unique(array_filter($want[$key], fn($v) => $v !== '')));
        if (!$vals) continue;
        $st = $pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($vals), '?'))));
        $st->execute($vals);
        $existing[$key] = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    }

    $seen = ['username' => [], 'email' => [], 'student_number' => []];
    $out = [];
    foreach ($rows as $r) {
        $errors = [];
        $num = $r['student_number'] ?? '';
        $name = $r['full_name'] ?? '';
        $email = $r['email'] ?? '';
        $username = student_import_username($r);
        $password = ($r['password'] ?? '') !== '' ? $r['password'] : $num;   // blank = student number (app convention)
        $type = strtolower($r['student_type'] ?? '') ?: 'regular';
        $section = strtoupper($r['section'] ?? '');
        $year = ctype_digit((string) ($r['year_level'] ?? '')) ? (int) $r['year_level'] : 0;

        if ($num === '' || strlen($num) > 30) $errors[] = 'student_number is required (max 30 characters)';
        if ($name === '' || strlen($name) > 150) $errors[] = 'full_name is required (max 150 characters)';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $errors[] = 'email is missing or invalid';
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) $errors[] = 'username must be 3-50 letters, numbers, . _ -';
        if (strlen($password) < 6) $errors[] = 'password is too short (min 6 characters; blank uses the student number)';
        if (!in_array($type, ['regular', 'irregular'], true)) $errors[] = 'student_type must be regular or irregular';
        if (strlen($r['contact_number'] ?? '') > 20) $errors[] = 'contact_number is too long (max 20)';
        if (!preg_match('/^[A-Z][A-Z0-9]{0,4}$/', $section)) $errors[] = 'section must be a letter like A, B, C';

        $instId = $institutions[strtoupper($r['institution_code'] ?? '')] ?? null;
        $prog = null;
        if (!$instId) {
            $errors[] = 'unknown institution_code "' . ($r['institution_code'] ?? '') . '"';
        } else {
            $prog = $programs[$instId . '|' . strtoupper($r['program_code'] ?? '')] ?? null;
            if (!$prog) $errors[] = 'program_code "' . ($r['program_code'] ?? '') . '" not found in ' . $r['institution_code'];
        }
        if ($prog && ($year < 1 || $year > program_max_year($prog['duration_years']))) {
            $errors[] = 'year_level must be 1-' . program_max_year($prog['duration_years']) . ' for this program';
        } elseif (!$prog && $year < 1) {
            $errors[] = 'year_level must be a number';
        }

        // duplicates: already in the database, or earlier in this same file
        foreach (['student_number' => $num, 'email' => $email, 'username' => $username] as $key => $val) {
            $lc = strtolower($val);
            if ($lc === '') continue;
            if (isset($existing[$key][$lc])) $errors[] = "$key \"$val\" already exists";
            elseif (isset($seen[$key][$lc])) $errors[] = "$key \"$val\" is repeated (line {$seen[$key][$lc]})";
            else $seen[$key][$lc] = $r['_line'];
        }

        $entry = ['line' => $r['_line'], 'row' => $r, 'errors' => $errors, 'ok' => !$errors, 'username' => $username];
        if (!$errors) {
            $entry['values'] = [
                'student_number' => $num, 'full_name' => $name, 'email' => $email, 'username' => $username,
                'password' => $password, 'institution_id' => $instId, 'department_id' => (int) $prog['department_id'],
                'program_id' => (int) $prog['program_id'], 'year_level' => $year,
                'section' => $year . $section, 'student_type' => $type, 'contact_number' => $r['contact_number'] ?? '',
            ];
        }
        $out[] = $entry;
    }
    return $out;
}

/** Username column, or the student number with unsafe characters removed. */
function student_import_username(array $r): string {
    $u = $r['username'] ?? '';
    if ($u === '') $u = preg_replace('/[^A-Za-z0-9._-]/', '', strtolower($r['student_number'] ?? ''));
    return $u;
}

/**
 * Insert already-validated rows (with 'password_hash') in ONE transaction.
 * Rolls back everything if any statement fails or the time budget is exceeded.
 * @return int number of students created
 */
function commit_student_import(PDO $pdo, array $validRows, int $adminUserId, float $budgetSeconds = 8.0): int {
    $start = microtime(true);
    $pdo->beginTransaction();
    try {
        $insU = $pdo->prepare("INSERT INTO users (username, password, role, email, status) VALUES (?, ?, 'student', ?, 'active')");
        $insS = $pdo->prepare('INSERT INTO students (user_id, student_number, full_name, program_id, year_level, contact_number, institution_id, department_id, section, student_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $count = 0;
        foreach ($validRows as $v) {
            if (microtime(true) - $start > $budgetSeconds) {
                throw new Exception('The import took too long and was cancelled (nothing was saved). Try fewer rows.');
            }
            $insU->execute([$v['username'], $v['password_hash'], $v['email']]);
            $userId = $pdo->lastInsertId();
            $insS->execute([$userId, $v['student_number'], $v['full_name'], $v['program_id'], $v['year_level'],
                            $v['contact_number'], $v['institution_id'], $v['department_id'], $v['section'], $v['student_type']]);
            create_notification($pdo, $userId, 'Welcome!', 'Your student account has been created. Username: ' . $v['username']);
            $count++;
        }
        log_activity($pdo, $adminUserId, "Batch-imported $count student account(s)");
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // A unique-constraint race (someone created the same username meanwhile) lands here too
        if ($e instanceof PDOException && $e->getCode() === '23505') {
            throw new Exception('A username, email or student number was created by someone else while you were importing. Nothing was saved; upload the file again.');
        }
        throw $e;
    }
}
