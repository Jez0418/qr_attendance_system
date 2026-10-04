<?php
/**
 * student/history.php
 * The student's own attendance history, filterable by subject/date.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');
$pageTitle = 'Attendance History';

$studentId = $_SESSION['profile_id'];
$subjectId = clean($_GET['subject_id'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');

$where = ['ar.student_id = ?'];
$params = [$studentId];
if ($subjectId !== '') { $where[] = 'sub.subject_id = ?'; $params[] = $subjectId; }
if ($dateFrom !== '') { $where[] = 'DATE(ar.time_in) >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(ar.time_in) <= ?'; $params[] = $dateTo; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$baseQuery = "
    FROM attendance_records ar
    JOIN attendance_sessions s ON s.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    JOIN teachers tch ON tch.teacher_id = ts.teacher_id
    $whereSql
";

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseQuery");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 15);

$stmt = $pdo->prepare("
    SELECT ar.*, sub.subject_name, lab.lab_name, tch.full_name AS teacher_name
    $baseQuery ORDER BY ar.time_in DESC LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$subjects = $pdo->prepare('
    SELECT DISTINCT sub.subject_id, sub.subject_name FROM enrollments e
    JOIN teacher_subjects ts ON ts.teacher_subject_id = e.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    WHERE e.student_id = ? ORDER BY sub.subject_name
');
$subjects->execute([$studentId]);
$subjects = $subjects->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>My Attendance History (<?php echo $totalRows; ?>)</h3></div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <select name="subject_id" class="form-control" style="max-width:220px">
                <option value="">All Subjects</option>
                <?php foreach ($subjects as $s): ?><option value="<?php echo $s['subject_id']; ?>" <?php echo $subjectId == $s['subject_id'] ? 'selected' : ''; ?>><?php echo e($s['subject_name']); ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control" style="max-width:160px" value="<?php echo e($dateFrom); ?>">
            <input type="date" name="date_to" class="form-control" style="max-width:160px" value="<?php echo e($dateTo); ?>">
            <button class="btn btn-outline btn-sm" type="submit">Filter</button>
            <a href="history.php" class="btn btn-outline btn-sm">Reset</a>
        </form>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Subject</th><th>Laboratory</th><th>Teacher</th><th>Time In</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No attendance records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr>
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
