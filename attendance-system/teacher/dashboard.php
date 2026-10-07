<?php
/**
 * teacher/dashboard.php
 * Overview of the teacher's assigned classes, active session status,
 * and recent attendance activity.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/attendance_stats.php';
require_role('teacher');
$pageTitle = 'Dashboard';

$teacherId = $_SESSION['profile_id'];

$totalClasses = $pdo->prepare('SELECT COUNT(*) FROM teacher_subjects WHERE teacher_id = ? AND status="active"');
$totalClasses->execute([$teacherId]);
$totalClasses = (int) $totalClasses->fetchColumn();

$totalStudents = $pdo->prepare('
    SELECT COUNT(DISTINCT e.student_id) FROM enrollments e
    JOIN teacher_subjects ts ON ts.teacher_subject_id = e.teacher_subject_id
    WHERE ts.teacher_id = ? AND e.status="enrolled"
');
$totalStudents->execute([$teacherId]);
$totalStudents = (int) $totalStudents->fetchColumn();

$activeSession = $pdo->prepare('
    SELECT s.*, sub.subject_name, lab.lab_name FROM attendance_sessions s
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE ts.teacher_id = ? AND s.is_active = 1 LIMIT 1
');
$activeSession->execute([$teacherId]);
$activeSession = $activeSession->fetch();

$todayScans = $pdo->prepare('
    SELECT COUNT(*) FROM attendance_records ar
    JOIN attendance_sessions s ON s.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    WHERE ts.teacher_id = ? AND DATE(ar.time_in) = CURDATE() AND ar.status <> "Absent"
');
$todayScans->execute([$teacherId]);
$todayScans = (int) $todayScans->fetchColumn();

$todayLate = $pdo->prepare('
    SELECT COUNT(*) FROM attendance_records ar
    JOIN attendance_sessions s ON s.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    WHERE ts.teacher_id = ? AND DATE(ar.time_in) = CURDATE() AND ar.status="Late"
');
$todayLate->execute([$teacherId]);
$todayLate = (int) $todayLate->fetchColumn();

$myClasses = $pdo->prepare('
    SELECT ts.*, sub.subject_name, sub.subject_code, lab.lab_name,
        (SELECT COUNT(*) FROM enrollments e WHERE e.teacher_subject_id = ts.teacher_subject_id AND e.status="enrolled") AS enrolled_count
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE ts.teacher_id = ? ORDER BY sub.subject_code, ts.section
');
$myClasses->execute([$teacherId]);
$myClasses = $myClasses->fetchAll();
// Recurring schedule, computed status and next meeting (includes/schedule.php)
$summaries = get_class_schedule_summaries($pdo, array_column($myClasses, 'teacher_subject_id'),
    array_column(array_map(fn($c) => [(int) $c['teacher_subject_id'], $c['status'] === 'active'], $myClasses), 1, 0));

// Students at or one away from the absence limit, or below the minimum rate (includes/attendance_stats.php)
$limits = attendance_limits($pdo);
$atRisk = teacher_students_at_risk($pdo, (int) $teacherId, $limits);

// Fetch department for the greeting subtitle
$deptStmt = $pdo->prepare('SELECT department FROM teachers WHERE teacher_id = ?');
$deptStmt->execute([$teacherId]);
$dept = $deptStmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="greeting-header">
    <div>
        <h2>Welcome, <?php echo e($_SESSION['full_name']); ?><?php echo $dept ? ' [' . e($dept) . ']' : ''; ?></h2>
        <div class="greeting-sub"><?php echo date('l, F j, Y'); ?></div>
    </div>
    <div class="greeting-avatar"><?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?></div>
</div>

<?php if ($activeSession): ?>
<div class="alert alert-success" style="margin-bottom:20px">
    <i class="fa-solid fa-circle-play"></i>
    You have an <strong>active QR session</strong> right now for <strong><?php echo e($activeSession['subject_name']); ?></strong> in <?php echo e($activeSession['lab_name']); ?>.
    <a href="session.php" class="btn btn-primary btn-sm" style="margin-left:auto">Manage Session</a>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-book"></i></div><div><div class="stat-value"><?php echo $totalClasses; ?></div><div class="stat-label">Assigned Classes</div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-user-graduate"></i></div><div><div class="stat-value"><?php echo $totalStudents; ?></div><div class="stat-label">Total Students</div></div></div>
    <div class="stat-card"><div class="stat-icon amber"><i class="fa-solid fa-qrcode"></i></div><div><div class="stat-value"><?php echo $todayScans; ?></div><div class="stat-label">Scans Today</div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-user-clock"></i></div><div><div class="stat-value"><?php echo $todayLate; ?></div><div class="stat-label">Late Today</div></div></div>
</div>

<?php if ($atRisk): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3>Attendance Watchlist</h3>
        <span class="text-muted card-header-note"><?php echo count($atRisk); ?> <?php echo count($atRisk) === 1 ? 'student' : 'students'; ?> at or near the limit<?php echo $limits['absence_limit'] ? ' of ' . $limits['absence_limit'] . ' absences' : ''; ?></span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Class</th><th>Absences</th><th>Attendance</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($atRisk as $r): $st = $r['standing']; ?>
                <tr>
                    <td><div style="font-weight:600"><?php echo e($r['full_name']); ?></div><div class="text-muted" style="font-size:12px"><?php echo e($r['student_number']); ?></div></td>
                    <td><?php echo e($r['subject_code']); ?> <span class="text-muted">· <?php echo e($r['section']); ?></span></td>
                    <td class="rate-cell"><?php echo $st['absent']; ?><?php echo $limits['absence_limit'] ? ' / ' . $limits['absence_limit'] : ''; ?></td>
                    <td class="rate-cell"><?php echo $st['rate']; ?>%<span class="badge <?php echo $st['badge']; ?>"><?php echo e($st['label']); ?></span></td>
                    <td><a href="history.php?class=<?php echo (int) $r['teacher_subject_id']; ?>&amp;search=<?php echo urlencode($r['student_number']); ?>" class="btn btn-outline btn-sm" title="View attendance records" aria-label="View attendance records for <?php echo e($r['full_name']); ?>"><i class="fa-solid fa-clock-rotate-left"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>My Assigned Classes</h3>
        <a href="session.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-qrcode"></i> Start Attendance Session</a>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Subject</th><th>Section</th><th>Laboratory</th><th>Schedule</th><th>Next Class</th><th>Enrolled</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (empty($myClasses)): ?>
                <tr><td colspan="7" class="text-center text-muted">No classes assigned yet. Contact the administrator.</td></tr>
            <?php else: foreach ($myClasses as $c): $sum = $summaries[(int) $c['teacher_subject_id']]; ?>
                <tr>
                    <td><?php echo e($c['subject_code'] . ' - ' . $c['subject_name']); ?></td>
                    <td><?php echo e($c['section']); ?></td>
                    <td><?php echo e($c['lab_name']); ?></td>
                    <td style="white-space:nowrap"><?php echo $sum['label'] !== '' ? e($sum['label']) : '<span class="text-muted">Not set</span>'; ?></td>
                    <td style="white-space:nowrap"><?php echo e($sum['next_label']); ?><?php if ($sum['next'] && $sum['next']['is_rescheduled']): ?><div class="text-muted" style="font-size:11px">Rescheduled</div><?php endif; ?></td>
                    <td><?php echo (int) $c['enrolled_count']; ?></td>
                    <td><span class="badge <?php echo $sum['badge'][1]; ?>"><?php echo $sum['badge'][0]; ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
