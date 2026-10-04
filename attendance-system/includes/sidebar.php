<?php
/**
 * ------------------------------------------------------------
 * sidebar.php
 * Role-aware sidebar navigation. Highlights the active page
 * using the current script filename. $pdo is available here
 * because this file is include()'d from header.php, which shares
 * the calling page's variable scope.
 * ------------------------------------------------------------
 */
$current = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';

function nav_active($file, $current, $alsoMatch = []) {
    return ($file === $current || in_array($current, $alsoMatch, true)) ? 'active' : '';
}

$pendingRequestsCount = 0;
if ($role === 'teacher' && isset($pdo)) {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM enrollment_requests r
        JOIN teacher_subjects ts ON ts.teacher_subject_id = r.teacher_subject_id
        WHERE ts.teacher_id = ? AND r.status = "pending"
    ');
    $stmt->execute([$_SESSION['profile_id'] ?? 0]);
    $pendingRequestsCount = (int) $stmt->fetchColumn();
} elseif ($role === 'admin' && isset($pdo)) {
    $pendingRequestsCount = (int) $pdo->query('SELECT COUNT(*) FROM enrollment_requests WHERE status = "pending"')->fetchColumn();
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-badge"><i class="fa-solid fa-graduation-cap"></i></div>
        <span>MCNP</span>
    </div>

    <nav class="sidebar-nav">
        <?php if ($role === 'admin'): ?>
            <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="<?php echo nav_active('dashboard.php', $current); ?>"><i class="fa-solid fa-gauge"></i><span>Dashboard</span></a>
            <a href="<?php echo BASE_URL; ?>admin/students.php" class="<?php echo nav_active('students.php', $current); ?>"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a>
            <a href="<?php echo BASE_URL; ?>admin/teachers.php" class="<?php echo nav_active('teachers.php', $current); ?>"><i class="fa-solid fa-chalkboard-user"></i><span>Teachers</span></a>
            <a href="<?php echo BASE_URL; ?>admin/subjects.php" class="<?php echo nav_active('subjects.php', $current); ?>"><i class="fa-solid fa-book"></i><span>Subjects</span></a>
            <a href="<?php echo BASE_URL; ?>admin/laboratories.php" class="<?php echo nav_active('laboratories.php', $current); ?>"><i class="fa-solid fa-flask"></i><span>Laboratories</span></a>
            <a href="<?php echo BASE_URL; ?>admin/assignments.php" class="<?php echo nav_active('assignments.php', $current); ?>"><i class="fa-solid fa-diagram-project"></i><span>Class Assignments</span></a>
            <a href="<?php echo BASE_URL; ?>admin/qr_management.php" class="<?php echo nav_active('qr_management.php', $current); ?>"><i class="fa-solid fa-toggle-on"></i><span>Attendance Sessions</span></a>
            <a href="<?php echo BASE_URL; ?>admin/attendance_monitoring.php" class="<?php echo nav_active('attendance_monitoring.php', $current); ?>"><i class="fa-solid fa-list-check"></i><span>Attendance Monitoring</span></a>
            <a href="<?php echo BASE_URL; ?>admin/enrollment_requests.php" class="<?php echo nav_active('enrollment_requests.php', $current); ?>"><i class="fa-solid fa-user-clock"></i><span>Enrollment Requests</span><?php if ($pendingRequestsCount > 0): ?><span class="nav-badge"><?php echo $pendingRequestsCount; ?></span><?php endif; ?></a>
            <a href="<?php echo BASE_URL; ?>admin/reports.php" class="<?php echo nav_active('reports.php', $current); ?>"><i class="fa-solid fa-chart-column"></i><span>Reports &amp; Analytics</span></a>
            <a href="<?php echo BASE_URL; ?>admin/settings.php" class="<?php echo nav_active('settings.php', $current); ?>"><i class="fa-solid fa-sliders"></i><span>Settings</span></a>
            <a href="<?php echo BASE_URL; ?>admin/notifications.php" class="<?php echo nav_active('notifications.php', $current); ?>"><i class="fa-solid fa-bell"></i><span>Notifications</span></a>

        <?php elseif ($role === 'teacher'): ?>
            <a href="<?php echo BASE_URL; ?>teacher/dashboard.php" class="<?php echo nav_active('dashboard.php', $current); ?>"><i class="fa-solid fa-gauge"></i><span>Dashboard</span></a>
            <a href="<?php echo BASE_URL; ?>teacher/subjects.php" class="<?php echo nav_active('subjects.php', $current, ['class_view.php']); ?>"><i class="fa-solid fa-book"></i><span>My Assigned Subjects</span></a>
            <a href="<?php echo BASE_URL; ?>teacher/enrollment.php" class="<?php echo nav_active('enrollment.php', $current); ?>"><i class="fa-solid fa-user-plus"></i><span>Student Enrollment</span></a>
            <a href="<?php echo BASE_URL; ?>teacher/enrollment_requests.php" class="<?php echo nav_active('enrollment_requests.php', $current); ?>"><i class="fa-solid fa-envelope-open-text"></i><span>Enrollment Requests</span><?php if ($pendingRequestsCount > 0): ?><span class="nav-badge"><?php echo $pendingRequestsCount; ?></span><?php endif; ?></a>
            <a href="<?php echo BASE_URL; ?>teacher/session.php" class="<?php echo nav_active('session.php', $current); ?>"><i class="fa-solid fa-toggle-on"></i><span>Attendance Session</span></a>
            <a href="<?php echo BASE_URL; ?>teacher/history.php" class="<?php echo nav_active('history.php', $current); ?>"><i class="fa-solid fa-clock-rotate-left"></i><span>Attendance History</span></a>
            <a href="<?php echo BASE_URL; ?>teacher/late_students.php" class="<?php echo nav_active('late_students.php', $current); ?>"><i class="fa-solid fa-user-clock"></i><span>Late Students</span></a>
            <a href="<?php echo BASE_URL; ?>teacher/notifications.php" class="<?php echo nav_active('notifications.php', $current); ?>"><i class="fa-solid fa-bell"></i><span>Notifications</span></a>

        <?php elseif ($role === 'student'): ?>
            <a href="<?php echo BASE_URL; ?>student/dashboard.php" class="<?php echo nav_active('dashboard.php', $current); ?>"><i class="fa-solid fa-gauge"></i><span>Dashboard</span></a>
            <a href="<?php echo BASE_URL; ?>student/browse_subjects.php" class="<?php echo nav_active('browse_subjects.php', $current); ?>"><i class="fa-solid fa-user-plus"></i><span>Request Enrollment</span></a>
            <a href="<?php echo BASE_URL; ?>student/my_requests.php" class="<?php echo nav_active('my_requests.php', $current); ?>"><i class="fa-solid fa-envelope-open-text"></i><span>My Enrollment Requests</span></a>
            <a href="<?php echo BASE_URL; ?>student/my_subjects.php" class="<?php echo nav_active('my_subjects.php', $current); ?>"><i class="fa-solid fa-book"></i><span>My Subjects</span></a>
            <a href="<?php echo BASE_URL; ?>student/scanner.php" class="<?php echo nav_active('scanner.php', $current); ?>"><i class="fa-solid fa-qrcode"></i><span>Scan Attendance</span></a>
            <a href="<?php echo BASE_URL; ?>student/history.php" class="<?php echo nav_active('history.php', $current); ?>"><i class="fa-solid fa-clock-rotate-left"></i><span>Attendance History</span></a>
            <a href="<?php echo BASE_URL; ?>student/profile.php" class="<?php echo nav_active('profile.php', $current); ?>"><i class="fa-solid fa-id-card"></i><span>My Profile</span></a>
            <a href="<?php echo BASE_URL; ?>student/notifications.php" class="<?php echo nav_active('notifications.php', $current); ?>"><i class="fa-solid fa-bell"></i><span>Notifications</span></a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="<?php echo BASE_URL; ?>logout.php" onclick="return confirm('Log out of your account?');">
            <i class="fa-solid fa-right-from-bracket"></i><span>Logout</span>
        </a>
    </div>
</aside>
