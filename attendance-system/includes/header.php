<?php
/**
 * ------------------------------------------------------------
 * header.php
 * Shared top navbar for all logged-in pages (admin/teacher/student).
 * Expects $pageTitle to be set before include.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/../qr/qr_helper.php';   // qr_key_is_configured() for the admin warning below
$unreadCount = isset($_SESSION['user_id']) ? unread_notification_count($pdo, $_SESSION['user_id']) : 0;
$flash = get_flash();
$roleHome = [
    'admin' => 'admin/dashboard.php',
    'teacher' => 'teacher/dashboard.php',
    'student' => 'student/dashboard.php',
][$_SESSION['role'] ?? ''] ?? 'index.php';
$notifPage = [
    'admin' => 'admin/notifications.php',
    'teacher' => 'teacher/notifications.php',
    'student' => 'student/notifications.php',
][$_SESSION['role'] ?? ''] ?? 'index.php';

// Short muted line under the page title (a page can set $pageSubtitle itself to override).
$subtitleMap = [
    'admin' => [
        'dashboard.php' => 'Attendance overview across all laboratories',
        'students.php' => 'Create and manage student accounts',
        'teachers.php' => 'Create and manage teacher accounts',
        'subjects.php' => 'Manage the subjects offered',
        'laboratories.php' => 'Manage laboratories and their GPS location',
        'assignments.php' => 'Link subjects, teachers and laboratories to classes',
        'schedule.php' => 'Weekly meetings, cancellations and reschedules',
        'qr_management.php' => 'Open and close attendance sessions',
        'attendance_monitoring.php' => 'Review every attendance record',
        'enrollment_requests.php' => 'Review student enrollment requests',
        'reports.php' => 'Attendance totals by subject, status and date',
        'settings.php' => 'Late grace period and geofencing',
        'notifications.php' => 'Your alerts and announcements',
    ],
    'teacher' => [
        'dashboard.php' => "Your classes and today's attendance",
        'subjects.php' => 'Subjects assigned to you',
        'enrollment.php' => 'Enroll students in your classes',
        'enrollment_requests.php' => 'Review requests to join your classes',
        'session.php' => "Run attendance for today's classes",
        'history.php' => 'Attendance records for your classes',
        'late_students.php' => 'Students who arrived late',
        'notifications.php' => 'Your alerts and announcements',
        'class_view.php' => 'Class details and enrollment',
    ],
    'student' => [
        'dashboard.php' => 'Your classes and recent attendance',
        'browse_subjects.php' => 'Find classes you can join',
        'my_requests.php' => 'Status of your enrollment requests',
        'my_subjects.php' => 'Classes you are enrolled in',
        'scanner.php' => 'Scan the QR code shown in the laboratory',
        'history.php' => 'Your attendance records',
        'profile.php' => 'Your account details',
        'notifications.php' => 'Your alerts and announcements',
    ],
];
$pageSubtitleText = $pageSubtitle ?? ($subtitleMap[$_SESSION['role'] ?? ''][basename($_SERVER['PHP_SELF'])] ?? '');

// Top-right avatar photo. Read from the DB on every request (not cached in $_SESSION) so a photo
// saved or removed on the profile page shows immediately. Admins have no photo column.
$headerPhotoSrc = '';
$photoTable = ['student' => ['students', 'student_id'], 'teacher' => ['teachers', 'teacher_id']][$_SESSION['role'] ?? ''] ?? null;
if ($photoTable && !empty($_SESSION['profile_id'])) {
    $photoStmt = $pdo->prepare("SELECT photo FROM {$photoTable[0]} WHERE {$photoTable[1]} = ?");
    $photoStmt->execute([$_SESSION['profile_id']]);
    $headerPhoto = (string) $photoStmt->fetchColumn();
    if ($headerPhoto !== '') {
        // Same rule as student/profile.php: data URI as-is, older rows hold a filename under uploads/photos/
        $headerPhotoSrc = strpos($headerPhoto, 'data:image/') === 0 ? $headerPhoto : UPLOAD_URL . $headerPhoto;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/img/school-emblem-96.png">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<title><?php echo isset($pageTitle) ? e($pageTitle) . ' - ' . APP_NAME : APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
<?php echo theme_head_tags(); ?>
</head>
<body class="<?php echo ($_SESSION['role'] ?? '') === 'student' ? 'has-bottom-nav' : ''; ?>">

<!-- Toast container (JS pushes toasts here) -->
<div id="toastContainer" class="toast-container"></div>

<?php if ($flash): ?>
<script>window.addEventListener('DOMContentLoaded', () => showToast('<?php echo addslashes($flash['type']); ?>', '<?php echo addslashes($flash['message']); ?>'));</script>
<?php endif; ?>

<div class="app-shell">
    <?php if (is_logged_in()): ?>
    <!-- ================= SIDEBAR ================= -->
    <?php include __DIR__ . '/sidebar.php'; ?>
    <?php endif; ?>

    <div class="main-content">
        <?php if (is_logged_in()): ?>
        <!-- ================= TOP BAR ================= -->
        <header class="topbar">
            <button id="sidebarToggle" class="icon-btn" title="Toggle menu"><i class="fa-solid fa-bars"></i></button>
            <div class="topbar-title-block">
                <h1 class="page-title"><?php echo e($pageTitle ?? ''); ?></h1>
                <?php if ($pageSubtitleText !== ''): ?><span class="page-subtitle"><?php echo e($pageSubtitleText); ?></span><?php endif; ?>
            </div>
            <div class="topbar-right">
                <span class="date-chip"><i class="fa-regular fa-calendar" aria-hidden="true"></i> <?php echo date('D, M j, Y'); ?></span>
                <?php echo theme_switcher(); ?>
                <div class="notif-wrapper">
                    <a href="<?php echo BASE_URL . $notifPage; ?>" class="icon-btn notif-link" id="notifBellBtn">
                        <i class="fa-solid fa-bell"></i>
                        <?php if ($unreadCount > 0): ?><span class="badge-dot"><?php echo $unreadCount; ?></span><?php endif; ?>
                    </a>
                </div>
                <div class="user-chip">
                    <?php if ($headerPhotoSrc !== ''): ?>
                    <img class="avatar avatar-photo" src="<?php echo e($headerPhotoSrc); ?>" alt="<?php echo e($_SESSION['full_name'] ?? ''); ?>">
                    <?php else: ?>
                    <div class="avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></div>
                    <?php endif; ?>
                    <div class="user-meta">
                        <span class="user-name"><?php echo e($_SESSION['full_name'] ?? ''); ?></span>
                        <span class="user-role"><?php echo e(ucfirst($_SESSION['role'] ?? '')); ?></span>
                    </div>
                </div>
            </div>
        </header>
        <?php endif; ?>
        <main class="page-content">
        <?php if (($_SESSION['role'] ?? '') === 'admin' && !qr_key_is_configured()): ?>
        <div class="alert alert-error"><strong>QR_SECRET_KEY is not set.</strong> QR codes are signed with a key derived from the database credentials, so changing the database password would break every QR code. Set <code>QR_SECRET_KEY</code> (a long random string) in Vercel and redeploy. See the README.</div>
        <?php endif; ?>
