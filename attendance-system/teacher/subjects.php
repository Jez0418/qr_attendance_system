<?php
/**
 * teacher/subjects.php
 * "My Assigned Subjects" — every subject clicks through to
 * class_view.php, which shows subject info + the enrolled roster
 * + pending enrollment requests for that specific class.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('teacher');
$pageTitle = 'My Assigned Subjects';

$teacherId = $_SESSION['profile_id'];

$stmt = $pdo->prepare('
    SELECT ts.*, sub.subject_name, sub.subject_code, sub.units, lab.lab_name, lab.location,
        (SELECT COUNT(*) FROM enrollments e WHERE e.teacher_subject_id = ts.teacher_subject_id AND e.status="enrolled") AS enrolled_count,
        (SELECT COUNT(*) FROM enrollment_requests r WHERE r.teacher_subject_id = ts.teacher_subject_id AND r.status="pending") AS pending_count
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE ts.teacher_id = ?
    ORDER BY sub.subject_code, ts.section
');
$stmt->execute([$teacherId]);
$classes = $stmt->fetchAll();
// Recurring schedule, computed status and next meeting (includes/schedule.php)
$summaries = get_class_schedule_summaries($pdo, array_column($classes, 'teacher_subject_id'),
    array_column(array_map(fn($c) => [(int) $c['teacher_subject_id'], $c['status'] === 'active'], $classes), 1, 0));

require_once __DIR__ . '/../includes/header.php';
?>
<div class="grid-3">
<?php if (empty($classes)): ?>
    <div class="empty-state" style="grid-column:1/-1"><i class="fa-solid fa-book"></i><p>You have no assigned subjects yet. Please contact the administrator.</p></div>
<?php else: foreach ($classes as $c): ?>
    <?php $sum = $summaries[(int) $c['teacher_subject_id']]; ?>
    <a href="class_view.php?id=<?php echo $c['teacher_subject_id']; ?>" class="card class-card is-link">
        <div class="card-body">
            <div class="class-card-head">
                <div>
                    <h3><?php echo e($c['subject_code']); ?></h3>
                    <p><?php echo e($c['subject_name']); ?></p>
                </div>
                <?php if ($c['pending_count'] > 0): ?><span class="badge badge-late"><?php echo (int) $c['pending_count']; ?> pending</span><?php endif; ?>
            </div>
            <ul class="class-meta">
                <li><i class="fa-solid fa-flask"></i><span><?php echo e($c['lab_name']); ?></span></li>
                <li><i class="fa-solid fa-location-dot"></i><span><?php echo e($c['location']); ?></span></li>
                <li><i class="fa-solid fa-calendar-week"></i><span><?php echo $sum['label'] !== '' ? e($sum['label']) : 'No schedule set'; ?></span></li>
                <li><i class="fa-solid fa-clock"></i><span>Next: <?php echo e($sum['next_label']); ?><?php echo $sum['next'] && $sum['next']['is_rescheduled'] ? ' (rescheduled)' : ''; ?></span></li>
                <li><i class="fa-solid fa-users"></i><span><?php echo e($c['section']); ?> (<?php echo (int) $c['enrolled_count']; ?>/<?php echo (int) $c['max_students']; ?> enrolled)</span></li>
            </ul>
            <div class="class-foot">
                <span class="badge <?php echo $sum['badge'][1]; ?>"><?php echo $sum['badge'][0]; ?></span>
                <span class="class-go">View details <i class="fa-solid fa-arrow-right"></i></span>
            </div>
        </div>
    </a>
<?php endforeach; endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
