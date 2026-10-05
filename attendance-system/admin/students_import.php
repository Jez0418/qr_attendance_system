<?php
/**
 * admin/students_import.php
 * Batch-create student accounts from a CSV file.
 *   1. Upload  -> file is parsed and every row validated (nothing is saved)
 *   2. Preview -> admin sees which rows are OK / have errors
 *   3. Confirm -> valid rows are saved in a single transaction
 * Admin only. POSTs are CSRF-protected. Max STUDENT_IMPORT_MAX_ROWS rows per file.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/import_csv.php';
require_once __DIR__ . '/../includes/import_students.php';
require_role('admin');
$pageTitle = 'Import Students';

if (isset($_GET['template'])) {
    import_send_template('student_import_template.csv', STUDENT_IMPORT_COLUMNS, [
        ['2024-00123', 'Juan Dela Cruz', 'juan.delacruz@example.com', '', '', 'MCNP', 'BSN', '1', 'A', 'regular', '09171234567'],
        ['2024-00124', 'Maria Santos', 'maria.santos@example.com', 'msantos', 'ChangeMe123', 'ISAP', 'BSIT', '2', 'B', 'irregular', ''],
    ]);
}

$errorMsg = '';
$preview = null;      // array of validated rows while previewing
$importId = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        import_csrf_check();
        $action = $_POST['action'] ?? '';

        if ($action === 'preview') {
            [$headers, $rows] = import_read_csv($_FILES['file'] ?? [], STUDENT_IMPORT_MAX_ROWS);
            $missing = array_diff(STUDENT_IMPORT_REQUIRED, $headers);
            if ($missing) throw new Exception('Missing column(s): ' . implode(', ', $missing) . '. Download the template to see the expected columns.');

            $preview = validate_student_import_rows($pdo, $rows);
            // Hash passwords NOW (not at confirm time) so plain passwords are never kept in the session,
            // and the slow bcrypt work is split across the two requests.
            $stash = [];
            $t0 = microtime(true);
            foreach ($preview as $p) {
                if (microtime(true) - $t0 > 8) throw new Exception('Checking the file took too long. Please import fewer rows at a time.');
                if ($p['ok']) { $v = $p['values']; $v['password_hash'] = password_hash($v['password'], PASSWORD_BCRYPT); unset($v['password']); $stash[] = $v; }
            }
            $importId = bin2hex(random_bytes(16));
            $_SESSION['student_import'] = ['id' => $importId, 'rows' => $stash, 'created' => time()];

        } elseif ($action === 'confirm') {
            $stash = $_SESSION['student_import'] ?? null;
            if (!$stash || !hash_equals($stash['id'], (string) ($_POST['import_id'] ?? '')) || time() - $stash['created'] > 900) {
                throw new Exception('This import expired. Please upload the file again.');
            }
            unset($_SESSION['student_import']);          // one-shot: a double click can't import twice
            // Duplicates created meanwhile are caught by the database unique constraints: the whole import rolls back.
            $created = commit_student_import($pdo, $stash['rows'], (int) $_SESSION['user_id']);
            set_flash('success', "$created student account(s) created. Their first password is the password column of the file, or their student number if it was blank.");
            redirect('admin/students.php');
        }
    }
} catch (Exception $e) {
    $errorMsg = safe_error_message($e);
    $preview = null;
}

$okCount = $preview ? count(array_filter($preview, fn($p) => $p['ok'])) : 0;
$badCount = $preview ? count($preview) - $okCount : 0;

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Import Students from CSV</h3>
        <a href="students.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Students</a>
    </div>
    <div class="card-body">
        <?php if ($errorMsg): ?><div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo e($errorMsg); ?></div><?php endif; ?>

<?php if ($preview === null): ?>
        <ol style="margin:0 0 16px 18px;line-height:1.8;color:var(--slate-700)">
            <li>Download the <a href="?template=1"><strong>CSV template</strong></a> and fill in one student per row (up to <?php echo STUDENT_IMPORT_MAX_ROWS; ?> rows).</li>
            <li>Save it as <strong>CSV UTF-8</strong> and upload it below. Nothing is saved yet &mdash; you will see a preview first.</li>
        </ol>
        <p class="text-muted" style="font-size:13px;margin:0 0 16px">
            Required: <code>student_number, full_name, email, institution_code, program_code, year_level, section</code>.
            Optional: <code>username</code> (blank = student number), <code>password</code> (blank = student number),
            <code>student_type</code> (regular/irregular, blank = regular), <code>contact_number</code>.
            Use institution codes like <code>MCNP</code> / <code>ISAP</code> and program codes like <code>BSN</code> / <code>BSIT</code>; section is a letter (<code>A</code>).
        </p>
        <form method="POST" enctype="multipart/form-data" class="toolbar">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="preview">
            <input type="file" name="file" accept=".csv,text/csv" class="form-control" style="max-width:360px" required aria-label="CSV file">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Check file</button>
        </form>

<?php else: ?>
        <p style="margin:0 0 14px;font-size:14px">
            <span class="badge badge-active"><?php echo $okCount; ?> ready</span>
            <?php if ($badCount): ?><span class="badge badge-absent" style="margin-left:6px"><?php echo $badCount; ?> with errors (will be skipped)</span><?php endif; ?>
        </p>
        <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Line</th><th>Student No.</th><th>Name</th><th>Username</th><th>Program</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($preview as $p): $r = $p['row']; ?>
                <tr>
                    <td><?php echo (int) $p['line']; ?></td>
                    <td><?php echo e($r['student_number'] ?? ''); ?></td>
                    <td><?php echo e($r['full_name'] ?? ''); ?></td>
                    <td><?php echo e($p['username']); ?></td>
                    <td><?php echo e(($r['institution_code'] ?? '') . ' ' . ($r['program_code'] ?? '') . ' Y' . ($r['year_level'] ?? '') . ' ' . ($r['section'] ?? '')); ?></td>
                    <td><?php if ($p['ok']): ?><span class="badge badge-active">OK</span><?php else: ?><span class="badge badge-absent">Error</span> <span style="font-size:12.5px;color:#991b1b"><?php echo e(implode('; ', $p['errors'])); ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="POST" class="toolbar" style="margin-top:18px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="import_id" value="<?php echo e($importId); ?>">
            <?php if ($okCount): ?>
                <button class="btn btn-primary btn-sm" type="submit" onclick="this.disabled=true;this.form.submit();"><i class="fa-solid fa-check"></i> Create <?php echo $okCount; ?> account(s)</button>
            <?php endif; ?>
            <a href="students_import.php" class="btn btn-outline btn-sm">Start over</a>
        </form>
<?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
