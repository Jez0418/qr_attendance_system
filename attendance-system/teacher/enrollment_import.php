<?php
/**
 * teacher/enrollment_import.php
 * Batch-enroll students into ONE of the logged-in teacher's classes from a CSV
 * that lists student numbers (single column "student_number").
 *   1. Upload  -> every row is matched against existing students (nothing saved)
 *   2. Preview -> OK / already enrolled / not found / class full
 *   3. Confirm -> valid rows are enrolled in one transaction
 * Teacher only; the class must belong to the teacher; POSTs are CSRF-protected.
 * Same rules as the one-by-one teacher enrollment (regular students must match the class's
 * institution/program/year/section; irregular students may join any class), plus the class capacity.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/import_csv.php';
require_role('teacher');
$pageTitle = 'Import Enrollment';

const ENROLL_IMPORT_MAX_ROWS = 100;
$teacherId = (int) $_SESSION['profile_id'];

$classesStmt = $pdo->prepare('
    SELECT ts.teacher_subject_id, ts.section, ts.max_students, ts.institution_id, ts.program_id, ts.year_level,
        sub.subject_code, sub.subject_name
    FROM teacher_subjects ts JOIN subjects sub ON sub.subject_id = ts.subject_id
    WHERE ts.teacher_id = ? ORDER BY sub.subject_code');
$classesStmt->execute([$teacherId]);
$classes = $classesStmt->fetchAll();
$classById = [];
foreach ($classes as $c) $classById[(int) $c['teacher_subject_id']] = $c;

if (isset($_GET['template'])) {
    import_send_template('enrollment_import_template.csv', ['student_number'], [['2024-00123'], ['2024-00124']]);
}

$requestedClass = (int) ($_POST['class_id'] ?? $_GET['class'] ?? 0);
$selectedClass = $requestedClass;
$notYourClass = $requestedClass && !isset($classById[$requestedClass]);      // someone else's class id
if (!isset($classById[$selectedClass])) $selectedClass = $classes ? (int) $classes[0]['teacher_subject_id'] : 0;

/** Look the student numbers up and classify each row. */
function classify_enrollment_rows(PDO $pdo, int $classId, array $class, array $rows): array {
    $nums = array_values(array_unique(array_map(fn($r) => strtolower($r['student_number']), array_filter($rows, fn($r) => $r['student_number'] !== ''))));
    $students = [];
    if ($nums) {
        $st = $pdo->prepare('SELECT s.student_id, s.student_number, s.full_name, s.user_id, u.status,
            s.institution_id, s.program_id, s.year_level, s.section, s.student_type FROM students s JOIN users u ON u.user_id = s.user_id WHERE LOWER(s.student_number) IN (' . implode(',', array_fill(0, count($nums), '?')) . ')');
        $st->execute($nums);
        foreach ($st->fetchAll() as $s) $students[strtolower($s['student_number'])] = $s;
    }
    $enrolledStmt = $pdo->prepare("SELECT student_id FROM enrollments WHERE teacher_subject_id = ? AND status = 'enrolled'");
    $enrolledStmt->execute([$classId]);
    $already = array_flip($enrolledStmt->fetchAll(PDO::FETCH_COLUMN));
    $free = (int) $class['max_students'] - count($already);

    $out = []; $seen = [];
    foreach ($rows as $r) {
        $num = $r['student_number']; $key = strtolower($num);
        $e = ['line' => $r['_line'], 'number' => $num, 'name' => '', 'student_id' => 0, 'user_id' => 0, 'status' => 'ok', 'note' => ''];
        if ($num === '') { $e['status'] = 'error'; $e['note'] = 'Empty student number'; }
        elseif (!isset($students[$key])) { $e['status'] = 'error'; $e['note'] = 'No student with this number'; }
        else {
            $s = $students[$key];
            $e['name'] = $s['full_name']; $e['student_id'] = (int) $s['student_id']; $e['user_id'] = (int) $s['user_id'];
            if ($s['status'] !== 'active') { $e['status'] = 'error'; $e['note'] = 'Account is inactive'; }
            elseif (isset($seen[$key])) { $e['status'] = 'skip'; $e['note'] = 'Repeated in the file'; }
            elseif (isset($already[$s['student_id']])) { $e['status'] = 'skip'; $e['note'] = 'Already enrolled'; }
            elseif (($blocked = enrollment_block_reason($s, $class)) !== '') { $e['status'] = 'error'; $e['note'] = $blocked; }
            elseif ($free <= 0) { $e['status'] = 'error'; $e['note'] = 'Class is full'; }
            else { $free--; }
            $seen[$key] = true;
        }
        $out[] = $e;
    }
    return $out;
}

$errorMsg = '';
$preview = null;
$importId = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        import_csrf_check();
        if (!$selectedClass) throw new Exception('You have no classes to enroll students into.');
        if ($notYourClass) throw new Exception('You do not have access to this class.');
        $class = $classById[$selectedClass];               // ownership: only the teacher's own classes are in this map
        $action = $_POST['action'] ?? '';

        if ($action === 'preview') {
            // Accept a header row "student_number" or just a plain column of numbers.
            $file = $_FILES['file'] ?? [];
            [, $rows] = import_read_csv($file, ENROLL_IMPORT_MAX_ROWS, ['student_number'], false);
            if ($rows && strtolower($rows[0]['student_number']) === 'student_number') array_shift($rows);
            if (!$rows) throw new Exception('No student numbers found in the file.');
            $preview = classify_enrollment_rows($pdo, $selectedClass, $class, $rows);
            $_SESSION['enroll_import'] = ['id' => $importId = bin2hex(random_bytes(16)), 'class' => $selectedClass,
                'teacher' => $teacherId, 'rows' => array_values(array_map(fn($p) => ['student_id' => $p['student_id'], 'user_id' => $p['user_id']], array_filter($preview, fn($p) => $p['status'] === 'ok'))),
                'created' => time()];

        } elseif ($action === 'confirm') {
            $stash = $_SESSION['enroll_import'] ?? null;
            if (!$stash || !hash_equals($stash['id'], (string) ($_POST['import_id'] ?? '')) || time() - $stash['created'] > 900
                || (int) $stash['teacher'] !== $teacherId || (int) $stash['class'] !== $selectedClass) {
                throw new Exception('This import expired. Please upload the file again.');
            }
            unset($_SESSION['enroll_import']);
            $class = $classById[(int) $stash['class']];

            $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE teacher_subject_id = ? AND status = 'enrolled'");
                $lock->execute([$selectedClass]);
                $free = (int) $class['max_students'] - (int) $lock->fetchColumn();
                if (count($stash['rows']) > $free) throw new Exception('The class does not have enough free seats any more. Nothing was saved.');

                // Re-check the cohort rule: a student's program/section/type may have changed since the preview.
                $cohort = $pdo->prepare('SELECT institution_id, program_id, year_level, section, student_type FROM students WHERE student_id = ?');
                foreach ($stash['rows'] as $row) {
                    $cohort->execute([$row['student_id']]);
                    $s = $cohort->fetch();
                    if (!$s || enrollment_block_reason($s, $class) !== '') {
                        throw new Exception('A student in this file can no longer be enrolled in this class (program, section or student type changed). Nothing was saved; please upload the file again.');
                    }
                }

                $find = $pdo->prepare('SELECT enrollment_id, status FROM enrollments WHERE student_id = ? AND teacher_subject_id = ?');
                $upd = $pdo->prepare("UPDATE enrollments SET status = 'enrolled' WHERE enrollment_id = ?");
                $ins = $pdo->prepare('INSERT INTO enrollments (student_id, teacher_subject_id) VALUES (?, ?)');
                $n = 0;
                foreach ($stash['rows'] as $row) {
                    $find->execute([$row['student_id'], $selectedClass]);
                    $ex = $find->fetch();
                    if ($ex && $ex['status'] === 'enrolled') continue;
                    if ($ex) $upd->execute([$ex['enrollment_id']]); else $ins->execute([$row['student_id'], $selectedClass]);
                    create_notification($pdo, $row['user_id'], 'Enrolled in a class', 'You have been enrolled in ' . $class['subject_code'] . ' (' . $class['section'] . '). Check your schedule for details.');
                    $n++;
                }
                log_activity($pdo, (int) $_SESSION['user_id'], "Batch-enrolled $n student(s) into class #$selectedClass");
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw ($t instanceof PDOException) ? new Exception('Could not save the enrollment. Nothing was saved; please try again.') : $t;
            }
            set_flash('success', "$n student(s) enrolled in {$class['subject_code']} ({$class['section']}).");
            redirect('teacher/enrollment.php?class=' . $selectedClass);
        }
    }
} catch (Exception $e) {
    $errorMsg = safe_error_message($e);
    $preview = null;
}

$cnt = ['ok' => 0, 'skip' => 0, 'error' => 0];
if ($preview) foreach ($preview as $p) $cnt[$p['status']]++;

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Import Enrollment from CSV</h3>
        <a href="enrollment.php<?php echo $selectedClass ? '?class=' . $selectedClass : ''; ?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Enrollment</a>
    </div>
    <div class="card-body">
        <?php if ($errorMsg): ?><div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo e($errorMsg); ?></div><?php endif; ?>

<?php if (!$classes): ?>
        <p class="text-muted">You have no classes yet, so there is nothing to enroll students into.</p>
<?php elseif ($preview === null): ?>
        <p class="text-muted" style="font-size:13.5px;margin:0 0 14px">
            Pick one of your classes and upload a CSV with a single column of <strong>student numbers</strong>
            (<a href="?template=1&amp;class=<?php echo $selectedClass; ?>">download the template</a>). Up to <?php echo ENROLL_IMPORT_MAX_ROWS; ?> students per file.
            Nothing is saved until you confirm the preview.
        </p>
        <form method="POST" enctype="multipart/form-data" class="toolbar">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="preview">
            <select name="class_id" class="form-control" style="max-width:340px" aria-label="Class">
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['teacher_subject_id']; ?>" <?php echo $selectedClass == $c['teacher_subject_id'] ? 'selected' : ''; ?>><?php echo e($c['subject_code'] . ' - ' . $c['subject_name'] . ' (' . $c['section'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="file" name="file" accept=".csv,text/csv" class="form-control" style="max-width:300px" required aria-label="CSV file">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Check file</button>
        </form>
<?php else: $cls = $classById[$selectedClass]; ?>
        <p style="margin:0 0 6px;font-size:14px"><strong><?php echo e($cls['subject_code'] . ' - ' . $cls['subject_name'] . ' (' . $cls['section'] . ')'); ?></strong></p>
        <p style="margin:0 0 14px;font-size:14px">
            <span class="badge badge-active"><?php echo $cnt['ok']; ?> will be enrolled</span>
            <?php if ($cnt['skip']): ?><span class="badge badge-inactive" style="margin-left:6px"><?php echo $cnt['skip']; ?> skipped</span><?php endif; ?>
            <?php if ($cnt['error']): ?><span class="badge badge-absent" style="margin-left:6px"><?php echo $cnt['error']; ?> with errors</span><?php endif; ?>
        </p>
        <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Line</th><th>Student No.</th><th>Name</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($preview as $p): ?>
                <tr>
                    <td><?php echo (int) $p['line']; ?></td>
                    <td><?php echo e($p['number']); ?></td>
                    <td><?php echo e($p['name'] ?: '—'); ?></td>
                    <td><?php
                        if ($p['status'] === 'ok') echo '<span class="badge badge-active">Enroll</span>';
                        elseif ($p['status'] === 'skip') echo '<span class="badge badge-inactive">Skip</span> <span style="font-size:12.5px">' . e($p['note']) . '</span>';
                        else echo '<span class="badge badge-absent">Error</span> <span style="font-size:12.5px;color:#991b1b">' . e($p['note']) . '</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="POST" class="toolbar" style="margin-top:18px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="class_id" value="<?php echo $selectedClass; ?>">
            <input type="hidden" name="import_id" value="<?php echo e($importId); ?>">
            <?php if ($cnt['ok']): ?>
                <button class="btn btn-primary btn-sm" type="submit" onclick="this.disabled=true;this.form.submit();"><i class="fa-solid fa-check"></i> Enroll <?php echo $cnt['ok']; ?> student(s)</button>
            <?php endif; ?>
            <a href="enrollment_import.php?class=<?php echo $selectedClass; ?>" class="btn btn-outline btn-sm">Start over</a>
        </form>
<?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
