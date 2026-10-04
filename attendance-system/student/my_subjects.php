<?php
/**
 * student/my_subjects.php
 * All subjects the student is OFFICIALLY enrolled in (via direct
 * teacher enrollment or an approved enrollment request).
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');
$pageTitle = 'My Subjects';

$studentId = $_SESSION['profile_id'];

$stmt = $pdo->prepare('
    SELECT e.enrolled_at, ts.*, sub.subject_code, sub.subject_name, t.full_name AS teacher_name, lab.lab_name,
        (SELECT COUNT(*) FROM attendance_records ar
            JOIN attendance_sessions s ON s.session_id = ar.session_id
            WHERE s.teacher_subject_id = ts.teacher_subject_id AND ar.student_id = e.student_id) AS times_attended
    FROM enrollments e
    JOIN teacher_subjects ts ON ts.teacher_subject_id = e.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE e.student_id = ? AND e.status = "enrolled"
    ORDER BY sub.subject_code
');
$stmt->execute([$studentId]);
$subjects = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="grid-3">
<?php if (empty($subjects)): ?>
    <div class="empty-state" style="grid-column:1/-1"><i class="fa-solid fa-book"></i><p>You're not enrolled in any subjects yet. <a href="browse_subjects.php">Browse available subjects</a> to request enrollment.</p></div>
<?php else: foreach ($subjects as $s): ?>
    <div class="card">
        <div class="card-body">
            <h3 style="margin:0 0 4px"><?php echo e($s['subject_code']); ?></h3>
            <p style="margin:0 0 12px;color:var(--slate-600)"><?php echo e($s['subject_name']); ?></p>
            <div style="font-size:13.5px;color:var(--slate-700);line-height:1.9">
                <div><i class="fa-solid fa-chalkboard-user" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($s['teacher_name']); ?></div>
                <div><i class="fa-solid fa-flask" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($s['lab_name']); ?></div>
                <div><i class="fa-solid fa-calendar-days" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($s['schedule_day']); ?></div>
                <div><i class="fa-solid fa-clock" style="width:18px;color:var(--indigo-600)"></i> <?php echo format_time($s['start_time']); ?> – <?php echo format_time($s['end_time']); ?></div>
                <div><i class="fa-solid fa-calendar-check" style="width:18px;color:var(--indigo-600)"></i> Enrolled <?php echo format_date($s['enrolled_at']); ?></div>
                <div><i class="fa-solid fa-list-check" style="width:18px;color:var(--indigo-600)"></i> Attended <?php echo (int) $s['times_attended']; ?> time(s)</div>
            </div>
            <span class="badge badge-active" style="margin-top:12px;display:inline-block">Enrolled</span>
        </div>
    </div>
<?php endforeach; endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
