<?php
/**
 * admin/export_excel.php
 * Streams the filtered attendance data as an .xls file that Excel
 * opens natively (HTML table wrapped with an Excel MIME type — no
 * external library required).
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

$filename = 'attendance_report_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
?>
<table border="1">
    <tr>
        <th>Student</th><th>Student No.</th><th>Subject</th><th>Laboratory</th><th>Teacher</th><th>Time In</th><th>Status</th>
    </tr>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><?php echo e($r['full_name']); ?></td>
        <td><?php echo e($r['student_number']); ?></td>
        <td><?php echo e($r['subject_name']); ?></td>
        <td><?php echo e($r['lab_name']); ?></td>
        <td><?php echo e($r['teacher_name']); ?></td>
        <td><?php echo format_datetime($r['time_in']); ?></td>
        <td><?php echo e($r['status']); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
