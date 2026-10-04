<?php
/**
 * student/dashboard.php
 * Overview of enrolled classes, attendance summary and quick scan link.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');
$pageTitle = 'Dashboard';

$studentId = $_SESSION['profile_id'];

$studentStmt = $pdo->prepare('SELECT student_number, full_name FROM students WHERE student_id = ?');
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();

$totalClasses = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND status = "enrolled"');
$totalClasses->execute([$studentId]);
$totalClasses = (int) $totalClasses->fetchColumn();

$statusCounts = $pdo->prepare('SELECT status, COUNT(*) c FROM attendance_records WHERE student_id = ? GROUP BY status');
$statusCounts->execute([$studentId]);
$counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
foreach ($statusCounts->fetchAll() as $row) $counts[$row['status']] = (int) $row['c'];

// Active sessions the student can currently scan for
$activeClasses = $pdo->prepare('
    SELECT s.session_id, sub.subject_name, lab.lab_name, s.scheduled_start
    FROM attendance_sessions s
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    JOIN enrollments e ON e.teacher_subject_id = ts.teacher_subject_id
    WHERE e.student_id = ? AND e.status = "enrolled" AND s.is_active = 1
    AND NOT EXISTS (SELECT 1 FROM attendance_records ar WHERE ar.session_id = s.session_id AND ar.student_id = ?)
');
$activeClasses->execute([$studentId, $studentId]);
$activeClasses = $activeClasses->fetchAll();

$recent = $pdo->prepare('
    SELECT ar.time_in, ar.status, sub.subject_name, lab.lab_name
    FROM attendance_records ar
    JOIN attendance_sessions s ON s.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE ar.student_id = ? ORDER BY ar.time_in DESC LIMIT 6
');
$recent->execute([$studentId]);
$recent = $recent->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="greeting-header">
    <div>
        <h2>Hello, <?php echo e($_SESSION['full_name']); ?>!</h2>
        <div class="greeting-sub">ID: <?php echo e($student['student_number'] ?? ''); ?></div>
    </div>
    <div class="greeting-avatar"><?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?></div>
</div>

<a href="scanner.php" class="btn btn-primary btn-block" style="padding:14px;font-size:15px;margin-bottom:18px">
    <i class="fa-solid fa-camera"></i> Scan QR Code
    <?php if (!empty($activeClasses)): ?><span class="badge" style="background:rgba(255,255,255,.25);color:#fff;margin-left:6px"><?php echo count($activeClasses); ?> active</span><?php endif; ?>
</a>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-card-top"><span class="stat-label">Enrolled</span><div class="stat-icon blue"><i class="fa-solid fa-book"></i></div></div><div class="stat-value"><?php echo $totalClasses; ?></div></div>
    <div class="stat-card"><div class="stat-card-top"><span class="stat-label">Present</span><div class="stat-icon green"><i class="fa-solid fa-check"></i></div></div><div class="stat-value"><?php echo $counts['Present']; ?></div></div>
    <div class="stat-card"><div class="stat-card-top"><span class="stat-label">Late</span><div class="stat-icon amber"><i class="fa-solid fa-clock"></i></div></div><div class="stat-value warn"><?php echo $counts['Late']; ?></div></div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Recent Attendance History</h3>
        <?php if ($counts['Late'] > 0): ?><span class="badge badge-late"><?php echo $counts['Late']; ?> ⚠</span><?php endif; ?>
    </div>
    <div class="card-body" style="padding:8px 20px">
        <?php if (empty($recent)): ?>
            <p class="text-muted text-center" style="padding:24px 0">No attendance records yet. Scan a QR code to get started!</p>
        <?php else: foreach ($recent as $r): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--slate-100)">
                <div style="flex:1">
                    <div style="font-size:13.5px;font-weight:600;color:var(--slate-900)"><?php echo e($r['subject_name']); ?></div>
                    <div style="font-size:12px;color:var(--slate-500);margin-top:2px"><?php echo format_datetime($r['time_in']); ?> · <?php echo e($r['lab_name']); ?></div>
                </div>
                <span class="badge badge-<?php echo strtolower($r['status']); ?>"><?php echo $r['status']; ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
