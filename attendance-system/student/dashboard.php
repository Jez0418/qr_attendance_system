<?php
/**
 * student/dashboard.php
 * Overview of enrolled classes and attendance summary.
 * Scanning lives on student/scanner.php (sidebar "Scan Attendance").
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_stats.php';
require_once __DIR__ . '/../includes/avatar.php';
require_once __DIR__ . '/../includes/pwa.php';
require_role('student');
$pageTitle = 'Dashboard';

$studentId = $_SESSION['profile_id'];

$studentStmt = $pdo->prepare('SELECT student_number, full_name FROM students WHERE student_id = ?');
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();

$statusCounts = $pdo->prepare('SELECT status, COUNT(*) c FROM attendance_records WHERE student_id = ? GROUP BY status');
$statusCounts->execute([$studentId]);
$counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
foreach ($statusCounts->fetchAll() as $row) $counts[$row['status']] = (int) $row['c'];
$meetings = $counts['Present'] + $counts['Late'] + $counts['Absent'];
$overallRate = $meetings > 0 ? (int) floor(($counts['Present'] + $counts['Late']) * 100 / $meetings) : null;

// Attendance per enrolled class, worst standing first (includes/attendance_stats.php)
$limits = attendance_limits($pdo);
$standings = student_class_standings($pdo, (int) $studentId, $limits);
$overClasses = array_values(array_filter($standings, fn($c) => $c['standing']['level'] === STANDING_OVER));
$warnClasses = array_values(array_filter($standings, fn($c) => $c['standing']['level'] === STANDING_WARNING));
$codes = fn(array $list) => implode(', ', array_map(fn($c) => $c['subject_code'], $list));
$photoUrl = current_photo_url($pdo);

$recent = $pdo->prepare('
    SELECT ar.time_in, ar.status, ar.marked_by_user_id, sub.subject_name, lab.lab_name
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
    <?php if ($photoUrl !== ''): ?>
    <img class="greeting-avatar greeting-avatar-photo" src="<?php echo e($photoUrl); ?>" alt="">
    <?php else: ?>
    <div class="greeting-avatar"><?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?></div>
    <?php endif; ?>
</div>

<?php if ($overClasses): ?>
<div class="alert alert-error" role="status">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <span>Your attendance in <strong><?php echo e($codes($overClasses)); ?></strong> is past the allowed limit. Talk to your teacher about how to make it up.</span>
</div>
<?php elseif ($warnClasses): ?>
<div class="alert alert-warning" role="status">
    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
    <span>One more absence in <strong><?php echo e($codes($warnClasses)); ?></strong> reaches the absence limit.</span>
</div>
<?php endif; ?>

<?php echo pwa_install_card(); ?>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-card-top"><span class="stat-label">Attendance</span><div class="stat-icon blue"><i class="fa-solid fa-chart-simple"></i></div></div><div class="stat-value"><?php echo $overallRate === null ? '—' : $overallRate . '%'; ?></div></div>
    <div class="stat-card"><div class="stat-card-top"><span class="stat-label">Late</span><div class="stat-icon amber"><i class="fa-solid fa-clock"></i></div></div><div class="stat-value warn"><?php echo $counts['Late']; ?></div></div>
    <div class="stat-card"><div class="stat-card-top"><span class="stat-label">Absent</span><div class="stat-icon red"><i class="fa-solid fa-user-xmark"></i></div></div><div class="stat-value<?php echo $counts['Absent'] ? ' bad' : ''; ?>"><?php echo $counts['Absent']; ?></div></div>
</div>

<div class="grid-2 dashboard-split">
<div class="card">
    <div class="card-header">
        <h3>Attendance by Class</h3>
        <span class="text-muted card-header-note"><?php echo count($standings); ?> enrolled</span>
    </div>
    <div class="card-body standing-body">
        <?php if (empty($standings)): ?>
            <p class="text-muted text-center" style="padding:24px 0">You're not enrolled in any class yet. Find one in <a href="browse_subjects.php">Browse Subjects</a>.</p>
        <?php else: ?>
        <ul class="standing-list">
            <?php foreach ($standings as $c): $st = $c['standing']; ?>
            <li class="standing-row lvl-<?php echo $st['level']; ?>">
                <div class="standing-main">
                    <div class="standing-title"><?php echo e($c['subject_code']); ?> <span class="standing-name"><?php echo e($c['subject_name']); ?></span></div>
                    <div class="standing-meta">
                        <?php if ($st['meetings'] === 0): ?>
                            No recorded meetings yet
                        <?php else: ?>
                            <?php echo $st['attended']; ?> of <?php echo $st['meetings']; ?> attended<?php echo $c['late'] ? ' (' . $c['late'] . ' late)' : ''; ?> · <?php echo e(absence_count_label($st['absent'], $limits)); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($st['rate'] !== null): ?>
                    <div class="standing-bar" aria-hidden="true"><span style="width:<?php echo $st['rate']; ?>%"></span></div>
                    <?php endif; ?>
                </div>
                <div class="standing-side">
                    <span class="standing-rate"><?php echo $st['rate'] === null ? '—' : $st['rate'] . '%'; ?></span>
                    <?php if ($st['label'] !== ''): ?><span class="badge <?php echo $st['badge']; ?>"><?php echo e($st['label']); ?></span><?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Recent Attendance</h3>
        <a href="history.php" class="btn btn-outline btn-sm">View all</a>
    </div>
    <div class="card-body" style="padding:8px 20px">
        <?php if (empty($recent)): ?>
            <p class="text-muted text-center" style="padding:24px 0">No attendance records yet. Open <strong>Scan Attendance</strong> in the menu to get started!</p>
        <?php else: foreach ($recent as $r): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--slate-100)">
                <div style="flex:1;min-width:0;overflow-wrap:anywhere">
                    <div style="font-size:13.5px;font-weight:600;color:var(--slate-900)"><?php echo e($r['subject_name']); ?></div>
                    <div style="font-size:12px;color:var(--slate-500);margin-top:2px"><?php echo format_record_time($r); ?> · <?php echo e($r['lab_name']); ?></div>
                </div>
                <span class="badge badge-<?php echo strtolower($r['status']); ?>"><?php echo $r['status']; ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
