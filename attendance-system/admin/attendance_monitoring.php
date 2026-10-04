<?php
/**
 * admin/attendance_monitoring.php
 * School-wide attendance record browser with search, filters
 * (date range, laboratory, subject, status) and pagination.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Attendance Monitoring';

$search    = clean($_GET['search'] ?? '');
$labId     = clean($_GET['lab_id'] ?? '');
$subjectId = clean($_GET['subject_id'] ?? '');
$status    = clean($_GET['status'] ?? '');
$dateFrom  = clean($_GET['date_from'] ?? '');
$dateTo    = clean($_GET['date_to'] ?? '');

$where = [];
$params = [];
if ($search !== '') { $where[] = '(st.full_name LIKE ? OR st.student_number LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($labId !== '')     { $where[] = 'lab.lab_id = ?'; $params[] = $labId; }
if ($subjectId !== '') { $where[] = 'sub.subject_id = ?'; $params[] = $subjectId; }
if ($status !== '')    { $where[] = 'ar.status = ?'; $params[] = $status; }
if ($dateFrom !== '')  { $where[] = 'DATE(ar.time_in) >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '')    { $where[] = 'DATE(ar.time_in) <= ?'; $params[] = $dateTo; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$baseQuery = "
    FROM attendance_records ar
    JOIN students st ON st.student_id = ar.student_id
    JOIN attendance_sessions ses ON ses.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = ses.teacher_subject_id
    JOIN teachers tch ON tch.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    $whereSql
";

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseQuery");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 15);

$stmt = $pdo->prepare("
    SELECT ar.*, st.full_name, st.student_number, sub.subject_name, lab.lab_name, tch.full_name AS teacher_name
    $baseQuery
    ORDER BY ar.time_in DESC
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$labs = $pdo->query('SELECT lab_id, lab_name FROM laboratories ORDER BY lab_name')->fetchAll();
$subjects = $pdo->query('SELECT subject_id, subject_name FROM subjects ORDER BY subject_name')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Attendance Records (<?php echo $totalRows; ?>)</h3>
        <a class="btn btn-outline btn-sm" href="reports.php"><i class="fa-solid fa-chart-column"></i> Go to Reports</a>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="form-control" name="search" placeholder="Search student..." value="<?php echo e($search); ?>"></div>
            <select name="lab_id" class="form-control" style="max-width:180px">
                <option value="">All Labs</option>
                <?php foreach ($labs as $l): ?><option value="<?php echo $l['lab_id']; ?>" <?php echo $labId == $l['lab_id'] ? 'selected' : ''; ?>><?php echo e($l['lab_name']); ?></option><?php endforeach; ?>
            </select>
            <select name="subject_id" class="form-control" style="max-width:180px">
                <option value="">All Subjects</option>
                <?php foreach ($subjects as $s): ?><option value="<?php echo $s['subject_id']; ?>" <?php echo $subjectId == $s['subject_id'] ? 'selected' : ''; ?>><?php echo e($s['subject_name']); ?></option><?php endforeach; ?>
            </select>
            <select name="status" class="form-control" style="max-width:150px">
                <option value="">All Status</option>
                <option value="Present" <?php echo $status === 'Present' ? 'selected' : ''; ?>>Present</option>
                <option value="Late" <?php echo $status === 'Late' ? 'selected' : ''; ?>>Late</option>
                <option value="Absent" <?php echo $status === 'Absent' ? 'selected' : ''; ?>>Absent</option>
            </select>
            <input type="date" name="date_from" class="form-control" style="max-width:160px" value="<?php echo e($dateFrom); ?>">
            <input type="date" name="date_to" class="form-control" style="max-width:160px" value="<?php echo e($dateTo); ?>">
            <button class="btn btn-outline btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="attendance_monitoring.php" class="btn btn-outline btn-sm">Reset</a>
        </form>

        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Student No.</th><th>Subject</th><th>Lab</th><th>Teacher</th><th>Time In</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No attendance records match your filters.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo e($r['full_name']); ?></td>
                        <td><?php echo e($r['student_number']); ?></td>
                        <td><?php echo e($r['subject_name']); ?></td>
                        <td><?php echo e($r['lab_name']); ?></td>
                        <td><?php echo e($r['teacher_name']); ?></td>
                        <td><?php echo format_datetime($r['time_in']); ?></td>
                        <td><span class="badge badge-<?php echo strtolower($r['status']); ?>"><?php echo $r['status']; ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
