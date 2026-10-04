<?php
/**
 * teacher/late_students.php
 * Dedicated view of only "Late" records across the teacher's classes,
 * useful for quickly identifying habitually late students.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
$pageTitle = 'Late Students';

$teacherId = $_SESSION['profile_id'];
$dateFrom = clean($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = clean($_GET['date_to'] ?? date('Y-m-d'));

$stmt = $pdo->prepare('
    SELECT st.student_id, st.full_name, st.student_number, sub.subject_name, COUNT(*) AS late_count, MAX(ar.time_in) AS last_late
    FROM attendance_records ar
    JOIN students st ON st.student_id = ar.student_id
    JOIN attendance_sessions ses ON ses.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = ses.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    WHERE ts.teacher_id = ? AND ar.status = "Late" AND DATE(ar.time_in) BETWEEN ? AND ?
    GROUP BY st.student_id, sub.subject_id
    ORDER BY late_count DESC
');
$stmt->execute([$teacherId, $dateFrom, $dateTo]);
$lateStudents = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>Late Students Summary</h3></div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <input type="date" name="date_from" class="form-control" style="max-width:170px" value="<?php echo e($dateFrom); ?>">
            <input type="date" name="date_to" class="form-control" style="max-width:170px" value="<?php echo e($dateTo); ?>">
            <button class="btn btn-outline btn-sm" type="submit">Filter</button>
        </form>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Student No.</th><th>Subject</th><th>Times Late</th><th>Most Recent</th></tr></thead>
                <tbody>
                <?php if (empty($lateStudents)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No late records for this period. 🎉</td></tr>
                <?php else: foreach ($lateStudents as $l): ?>
                    <tr>
                        <td><?php echo e($l['full_name']); ?></td>
                        <td><?php echo e($l['student_number']); ?></td>
                        <td><?php echo e($l['subject_name']); ?></td>
                        <td><span class="badge badge-late"><?php echo (int) $l['late_count']; ?>x</span></td>
                        <td><?php echo format_datetime($l['last_late']); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
