<?php
/**
 * teacher/enrollment.php
 * Enroll/unenroll students into one of the teacher's classes.
 * A student MUST be enrolled to be allowed to scan the QR for that class.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
$pageTitle = 'Student Enrollment';

$teacherId = $_SESSION['profile_id'];

$classes = $pdo->prepare('
    SELECT ts.teacher_subject_id, sub.subject_code, sub.subject_name, ts.section
    FROM teacher_subjects ts JOIN subjects sub ON sub.subject_id = ts.subject_id
    WHERE ts.teacher_id = ? ORDER BY sub.subject_code
');
$classes->execute([$teacherId]);
$classes = $classes->fetchAll();

$selectedClass = (int) ($_GET['class'] ?? ($classes[0]['teacher_subject_id'] ?? 0));

// Verify the selected class actually belongs to this teacher
$belongsToTeacher = false;
foreach ($classes as $c) if ((int) $c['teacher_subject_id'] === $selectedClass) $belongsToTeacher = true;
if (!$belongsToTeacher) $selectedClass = 0;

$enrolled = [];
$notEnrolled = [];
$filterProgram = clean($_GET['filter_program'] ?? '');
$filterYear = clean($_GET['filter_year'] ?? '');

if ($selectedClass) {
    $enrolledStmt = $pdo->prepare('
        SELECT s.student_id, s.student_number, s.full_name, pr.program_name, e.enrollment_id
        FROM enrollments e JOIN students s ON s.student_id = e.student_id
        LEFT JOIN programs pr ON pr.program_id = s.program_id
        WHERE e.teacher_subject_id = ? AND e.status = "enrolled"
        ORDER BY s.full_name
    ');
    $enrolledStmt->execute([$selectedClass]);
    $enrolled = $enrolledStmt->fetchAll();

    $naWhere = ['s.student_id NOT IN (SELECT student_id FROM enrollments WHERE teacher_subject_id = ? AND status = "enrolled")'];
    $naParams = [$selectedClass];
    if ($filterProgram !== '') { $naWhere[] = 's.program_id = ?'; $naParams[] = $filterProgram; }
    if ($filterYear !== '') { $naWhere[] = 's.year_level = ?'; $naParams[] = $filterYear; }

    $sortMap = ['name' => 's.full_name', 'id' => 's.student_number', 'course' => 'pr.program_name', 'year' => 's.year_level'];
    $sortCol = $sortMap[$_GET['sort'] ?? 'name'] ?? 's.full_name';

    $notEnrolledStmt = $pdo->prepare('
        SELECT s.student_id, s.student_number, s.full_name, s.year_level, pr.program_name
        FROM students s
        LEFT JOIN programs pr ON pr.program_id = s.program_id
        WHERE ' . implode(' AND ', $naWhere) . "
        ORDER BY $sortCol ASC
    ");
    $notEnrolledStmt->execute($naParams);
    $notEnrolled = $notEnrolledStmt->fetchAll();
}

$programs = $pdo->query('SELECT program_id, program_code FROM programs ORDER BY program_code')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Select Class</h3>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <select name="class" class="form-control" style="max-width:340px" onchange="this.form.submit()">
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo $c['teacher_subject_id']; ?>" <?php echo $selectedClass == $c['teacher_subject_id'] ? 'selected' : ''; ?>>
                        <?php echo e($c['subject_code'] . ' - ' . $c['subject_name'] . ' (' . $c['section'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if (!$selectedClass): ?>
    <div class="empty-state"><i class="fa-solid fa-book"></i><p>You have no assigned classes yet.</p></div>
<?php else: ?>
<div class="grid-2" style="margin-top:20px">
    <div class="card">
        <div class="card-header"><h3>Enrolled Students (<?php echo count($enrolled); ?>)</h3></div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student No.</th><th>Name</th><th>Program</th><th></th></tr></thead>
                <tbody id="enrolledBody">
                <?php if (empty($enrolled)): ?>
                    <tr><td colspan="4" class="text-center text-muted">No students enrolled yet.</td></tr>
                <?php else: foreach ($enrolled as $s): ?>
                    <tr data-student="<?php echo $s['student_id']; ?>">
                        <td><?php echo e($s['student_number']); ?></td>
                        <td><?php echo e($s['full_name']); ?></td>
                        <td><?php echo e($s['program_name'] ?? '—'); ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="unenroll(<?php echo $s['student_id']; ?>)"><i class="fa-solid fa-user-minus"></i></button></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Available Students (<?php echo count($notEnrolled); ?>)</h3></div>
        <div class="card-body">
            <form method="GET" class="toolbar" style="margin-bottom:12px">
                <input type="hidden" name="class" value="<?php echo (int) $selectedClass; ?>">
                <select name="filter_program" class="form-control" style="max-width:150px" onchange="this.form.submit()">
                    <option value="">All Courses</option>
                    <?php foreach ($programs as $pr): ?><option value="<?php echo $pr['program_id']; ?>" <?php echo $filterProgram == $pr['program_id'] ? 'selected' : ''; ?>><?php echo e($pr['program_code']); ?></option><?php endforeach; ?>
                </select>
                <select name="filter_year" class="form-control" style="max-width:150px" onchange="this.form.submit()">
                    <option value="">All Years</option>
                    <option value="1" <?php echo $filterYear === '1' ? 'selected' : ''; ?>>1st Year</option>
                    <option value="2" <?php echo $filterYear === '2' ? 'selected' : ''; ?>>2nd Year</option>
                    <option value="3" <?php echo $filterYear === '3' ? 'selected' : ''; ?>>3rd Year</option>
                    <option value="4" <?php echo $filterYear === '4' ? 'selected' : ''; ?>>4th Year</option>
                </select>
                <select name="sort" class="form-control" style="max-width:170px" onchange="this.form.submit()">
                    <option value="name" <?php echo ($_GET['sort'] ?? 'name') === 'name' ? 'selected' : ''; ?>>Sort: Name</option>
                    <option value="id" <?php echo ($_GET['sort'] ?? '') === 'id' ? 'selected' : ''; ?>>Sort: Student ID</option>
                    <option value="course" <?php echo ($_GET['sort'] ?? '') === 'course' ? 'selected' : ''; ?>>Sort: Course</option>
                    <option value="year" <?php echo ($_GET['sort'] ?? '') === 'year' ? 'selected' : ''; ?>>Sort: Year</option>
                </select>
                <?php if ($filterProgram || $filterYear): ?><a href="?class=<?php echo (int) $selectedClass; ?>" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
            </form>
            <div class="search-box" style="max-width:100%;margin-bottom:12px">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="availableSearch" class="form-control" placeholder="Search students by name or ID...">
            </div>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student No.</th><th>Name</th><th>Program</th><th></th></tr></thead>
                <tbody id="availableBody">
                <?php if (empty($notEnrolled)): ?>
                    <tr><td colspan="4" class="text-center text-muted">All students are already enrolled.</td></tr>
                <?php else: foreach ($notEnrolled as $s): ?>
                    <tr data-student="<?php echo $s['student_id']; ?>" data-search="<?php echo e(strtolower($s['full_name'] . ' ' . $s['student_number'])); ?>">
                        <td><?php echo e($s['student_number']); ?></td>
                        <td><?php echo e($s['full_name']); ?></td>
                        <td><?php echo e($s['program_name'] ?? '—'); ?></td>
                        <td><button class="btn btn-success btn-sm" onclick="enroll(<?php echo $s['student_id']; ?>)"><i class="fa-solid fa-user-plus"></i></button></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const CLASS_ID = <?php echo (int) $selectedClass; ?>;

document.getElementById('availableSearch')?.addEventListener('input', debounce((e) => {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('#availableBody tr[data-search]').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
}, 200));

async function enroll(studentId) {
    const res = await ajaxPost('ajax_enrollment.php', { action: 'enroll', student_id: studentId, teacher_subject_id: CLASS_ID });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}
async function unenroll(studentId) {
    if (!confirmDelete('Remove this student from the class?')) return;
    const res = await ajaxPost('ajax_enrollment.php', { action: 'unenroll', student_id: studentId, teacher_subject_id: CLASS_ID });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
