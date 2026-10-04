<?php
/**
 * teacher/history.php
 * Attendance history across all of the teacher's classes, filterable
 * by class and date range, with pagination.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
$pageTitle = 'Attendance History';

$teacherId = $_SESSION['profile_id'];
$classId = clean($_GET['class'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');
$search = clean($_GET['search'] ?? '');

$where = ['ts.teacher_id = ?'];
$params = [$teacherId];
if ($classId !== '') { $where[] = 'ts.teacher_subject_id = ?'; $params[] = $classId; }
if ($dateFrom !== '') { $where[] = 'DATE(ar.time_in) >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(ar.time_in) <= ?'; $params[] = $dateTo; }
if ($search !== '') { $where[] = '(st.full_name LIKE ? OR st.student_number LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$baseQuery = "
    FROM attendance_records ar
    JOIN students st ON st.student_id = ar.student_id
    JOIN attendance_sessions ses ON ses.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = ses.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    $whereSql
";

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseQuery");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 15);

$stmt = $pdo->prepare("
    SELECT ar.*, st.full_name, st.student_number, sub.subject_name, lab.lab_name
    $baseQuery ORDER BY ar.time_in DESC LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$classes = $pdo->prepare('SELECT ts.teacher_subject_id, sub.subject_name, sub.subject_code FROM teacher_subjects ts JOIN subjects sub ON sub.subject_id=ts.subject_id WHERE ts.teacher_id=? ORDER BY sub.subject_code');
$classes->execute([$teacherId]);
$classes = $classes->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>Attendance History (<?php echo $totalRows; ?>)</h3></div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" class="form-control" placeholder="Search student..." value="<?php echo e($search); ?>"></div>
            <select name="class" class="form-control" style="max-width:220px">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?><option value="<?php echo $c['teacher_subject_id']; ?>" <?php echo $classId == $c['teacher_subject_id'] ? 'selected' : ''; ?>><?php echo e($c['subject_code']); ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control" style="max-width:160px" value="<?php echo e($dateFrom); ?>">
            <input type="date" name="date_to" class="form-control" style="max-width:160px" value="<?php echo e($dateTo); ?>">
            <button class="btn btn-outline btn-sm" type="submit">Filter</button>
            <a href="history.php" class="btn btn-outline btn-sm">Reset</a>
        </form>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Student No.</th><th>Subject</th><th>Lab</th><th>Time In</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="6" class="text-center text-muted">No records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo e($r['full_name']); ?></td>
                        <td><?php echo e($r['student_number']); ?></td>
                        <td><?php echo e($r['subject_name']); ?></td>
                        <td><?php echo e($r['lab_name']); ?></td>
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
