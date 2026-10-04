<?php
/**
 * teacher/dashboard.php
 * Overview of the teacher's assigned classes, active session status,
 * and recent attendance activity.
 */
require_once __DIR__ . '/../includes/auth.php';
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
    WHERE ts.teacher_id = ? AND DATE(ar.time_in) = CURDATE()
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
    WHERE ts.teacher_id = ? ORDER BY ts.schedule_day, ts.start_time
');
$myClasses->execute([$teacherId]);
$myClasses = $myClasses->fetchAll();

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

<div class="card">
    <div class="card-header">
        <h3>My Assigned Classes</h3>
        <a href="session.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-qrcode"></i> Start Attendance Session</a>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Subject</th><th>Section</th><th>Laboratory</th><th>Schedule</th><th>Enrolled</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (empty($myClasses)): ?>
                <tr><td colspan="6" class="text-center text-muted">No classes assigned yet. Contact the administrator.</td></tr>
            <?php else: foreach ($myClasses as $c): ?>
                <tr>
                    <td><?php echo e($c['subject_code'] . ' - ' . $c['subject_name']); ?></td>
                    <td><?php echo e($c['section']); ?></td>
                    <td><?php echo e($c['lab_name']); ?></td>
                    <td><?php echo e($c['schedule_day']); ?> · <?php echo format_time($c['start_time']); ?>–<?php echo format_time($c['end_time']); ?></td>
                    <td><?php echo (int) $c['enrolled_count']; ?></td>
                    <td><span class="badge badge-<?php echo $c['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
