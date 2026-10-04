<?php
/**
 * admin/export_pdf.php
 * Renders a clean, print-ready attendance report using the same
 * filters as reports.php. The user prints/saves it as PDF using
 * the browser's native print dialog (Ctrl+P -> Save as PDF), which
 * avoids requiring any external PDF library.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$labId     = clean($_GET['lab_id'] ?? '');
$subjectId = clean($_GET['subject_id'] ?? '');
$dateFrom  = clean($_GET['date_from'] ?? date('Y-m-01'));
$dateTo    = clean($_GET['date_to'] ?? date('Y-m-d'));

$where = ['DATE(ar.time_in) BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];
if ($labId !== '')     { $where[] = 'lab.lab_id = ?'; $params[] = $labId; }
if ($subjectId !== '') { $where[] = 'sub.subject_id = ?'; $params[] = $subjectId; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT ar.time_in, ar.status, st.full_name, st.student_number, sub.subject_name, lab.lab_name, tch.full_name AS teacher_name
    FROM attendance_records ar
    JOIN students st ON st.student_id = ar.student_id
    JOIN attendance_sessions ses ON ses.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = ses.teacher_subject_id
    JOIN teachers tch ON tch.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    $whereSql
    ORDER BY ar.time_in DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$labName = 'All Laboratories';
if ($labId) { $l = $pdo->prepare('SELECT lab_name FROM laboratories WHERE lab_id=?'); $l->execute([$labId]); $labName = $l->fetchColumn(); }
$subjectName = 'All Subjects';
if ($subjectId) { $s = $pdo->prepare('SELECT subject_name FROM subjects WHERE subject_id=?'); $s->execute([$subjectId]); $subjectName = $s->fetchColumn(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Report - <?php echo APP_NAME; ?></title>
<style>
    body{font-family:Arial,sans-serif;color:#111;padding:30px;font-size:13px}
    h1{font-size:20px;margin:0 0 4px}
    .subtitle{color:#555;margin:0 0 20px;font-size:13px}
    .meta{display:flex;gap:30px;margin-bottom:18px;font-size:12.5px;color:#333}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{border:1px solid #ccc;padding:7px 9px;text-align:left;font-size:12px}
    th{background:#f1f1f1}
    .status-Present{color:#166534;font-weight:bold}
    .status-Late{color:#92400e;font-weight:bold}
    .status-Absent{color:#991b1b;font-weight:bold}
    .print-btn{margin-bottom:20px;padding:10px 18px;background:#4338ca;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:14px}
    @media print{ .print-btn{display:none} }
</style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <h1><?php echo APP_NAME; ?></h1>
    <p class="subtitle">Attendance Report</p>
    <div class="meta">
        <div><strong>Laboratory:</strong> <?php echo e($labName); ?></div>
        <div><strong>Subject:</strong> <?php echo e($subjectName); ?></div>
        <div><strong>Period:</strong> <?php echo e($dateFrom); ?> to <?php echo e($dateTo); ?></div>
        <div><strong>Generated:</strong> <?php echo date('M d, Y h:i A'); ?></div>
    </div>
    <table>
        <thead><tr><th>#</th><th>Student</th><th>Student No.</th><th>Subject</th><th>Laboratory</th><th>Teacher</th><th>Time In</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="8" style="text-align:center">No records found.</td></tr>
        <?php else: $i = 1; foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo e($r['full_name']); ?></td>
                <td><?php echo e($r['student_number']); ?></td>
                <td><?php echo e($r['subject_name']); ?></td>
                <td><?php echo e($r['lab_name']); ?></td>
                <td><?php echo e($r['teacher_name']); ?></td>
                <td><?php echo format_datetime($r['time_in']); ?></td>
                <td class="status-<?php echo $r['status']; ?>"><?php echo $r['status']; ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</body>
</html>
