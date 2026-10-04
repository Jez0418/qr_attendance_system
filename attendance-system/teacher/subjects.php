<?php
/**
 * teacher/subjects.php
 * "My Assigned Subjects" — every subject clicks through to
 * class_view.php, which shows subject info + the enrolled roster
 * + pending enrollment requests for that specific class.
 */
require_once __DIR__ . '/../includes/auth.php';
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
$classes = attach_class_schedules($pdo, $stmt->fetchAll());

require_once __DIR__ . '/../includes/header.php';
?>
<div class="grid-3">
<?php if (empty($classes)): ?>
    <div class="empty-state" style="grid-column:1/-1"><i class="fa-solid fa-book"></i><p>You have no assigned subjects yet. Please contact the administrator.</p></div>
<?php else: foreach ($classes as $c): ?>
    <?php $cState = class_schedule_status($c['schedules']); $cMeta = class_status_badge($cState['status']); ?>
    <a href="class_view.php?id=<?php echo $c['teacher_subject_id']; ?>" class="card" style="display:block;color:inherit;transition:box-shadow .15s,transform .15s" onmouseover="this.style.boxShadow='var(--shadow-md)'" onmouseout="this.style.boxShadow='var(--shadow-sm)'">
        <div class="card-body">
            <div class="flex-between" style="align-items:flex-start">
                <h3 style="margin:0 0 4px"><?php echo e($c['subject_code']); ?></h3>
                <?php if ($c['pending_count'] > 0): ?><span class="badge badge-late"><?php echo (int) $c['pending_count']; ?> pending</span><?php endif; ?>
            </div>
            <p style="margin:0 0 12px;color:var(--slate-600)"><?php echo e($c['subject_name']); ?></p>
            <div style="font-size:13.5px;color:var(--slate-700);line-height:1.9">
                <div><i class="fa-solid fa-flask" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($c['lab_name']); ?></div>
                <div><i class="fa-solid fa-location-dot" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($c['location']); ?></div>
                <div><i class="fa-solid fa-calendar-week" style="width:18px;color:var(--indigo-600)"></i> <?php echo e(format_class_schedule($c['schedules'])); ?></div>
                <div><i class="fa-solid fa-users" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($c['section']); ?> (<?php echo (int) $c['enrolled_count']; ?>/<?php echo (int) $c['max_students']; ?> enrolled)</div>
            </div>
            <div class="flex-between" style="margin-top:12px">
                <span class="badge badge-<?php echo $c['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($c['status']); ?></span>
                <?php if (in_array($cState['status'], ['upcoming', 'active'], true)): ?><span class="badge <?php echo $cMeta['class']; ?>"><?php echo $cMeta['label']; ?></span><?php endif; ?>
                <span class="text-muted" style="font-size:12.5px">View details <i class="fa-solid fa-arrow-right"></i></span>
            </div>
        </div>
    </a>
<?php endforeach; endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
