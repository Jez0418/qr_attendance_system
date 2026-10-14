<?php
/**
 * student/my_subjects.php
 * All subjects the student is OFFICIALLY enrolled in (via direct
 * teacher enrollment or an approved enrollment request).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('student');
$pageTitle = 'My Subjects';

$studentId = $_SESSION['profile_id'];

$stmt = $pdo->prepare('
    SELECT e.enrolled_at, ts.*, sub.subject_code, sub.subject_name, t.full_name AS teacher_name, lab.lab_name,
        (SELECT COUNT(*) FROM attendance_records ar
            JOIN attendance_sessions s ON s.session_id = ar.session_id
            WHERE s.teacher_subject_id = ts.teacher_subject_id AND ar.student_id = e.student_id AND ar.status IN ("Present", "Late")) AS times_attended
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
// Recurring schedule, computed status and next meeting (includes/schedule.php)
$summaries = get_class_schedule_summaries($pdo, array_column($subjects, 'teacher_subject_id'),
    array_column(array_map(fn($s) => [(int) $s['teacher_subject_id'], $s['status'] === 'active'], $subjects), 1, 0));

require_once __DIR__ . '/../includes/header.php';
?>
<div class="grid-3">
<?php if (empty($subjects)): ?>
    <div class="empty-state" style="grid-column:1/-1"><i class="fa-solid fa-book"></i><p>You're not enrolled in any subjects yet. <a href="browse_subjects.php">Browse available subjects</a> to request enrollment.</p></div>
<?php else: foreach ($subjects as $s): $sum = $summaries[(int) $s['teacher_subject_id']]; ?>
    <div class="card class-card">
        <div class="card-body">
            <div class="class-card-head">
                <div>
                    <h3><?php echo e($s['subject_code']); ?></h3>
                    <p><?php echo e($s['subject_name']); ?></p>
                </div>
                <span class="badge <?php echo $sum['badge'][1]; ?>"><?php echo $sum['badge'][0]; ?></span>
            </div>
            <ul class="class-meta">
                <li><i class="fa-solid fa-chalkboard-user"></i><span><?php echo e($s['teacher_name']); ?></span></li>
                <li><i class="fa-solid fa-flask"></i><span><?php echo e($s['lab_name']); ?></span></li>
                <li><i class="fa-solid fa-calendar-week"></i><span><?php echo $sum['label'] !== '' ? e($sum['label']) : 'No schedule set'; ?></span></li>
                <li><i class="fa-solid fa-clock"></i><span>Next: <?php echo e($sum['next_label']); ?><?php if ($sum['next'] && $sum['next']['is_rescheduled']): ?> · <?php echo e($sum['next']['lab_name']); ?> (rescheduled)<?php endif; ?></span></li>
            </ul>
            <div class="class-foot">
                <span><i class="fa-solid fa-calendar-check"></i> Enrolled <?php echo format_date($s['enrolled_at']); ?></span>
                <span><i class="fa-solid fa-list-check"></i> Attended <?php echo (int) $s['times_attended']; ?>&times;</span>
            </div>
        </div>
    </div>
<?php endforeach; endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
