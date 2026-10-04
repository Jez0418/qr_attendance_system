<?php
/**
 * ------------------------------------------------------------
 * header.php
 * Shared top navbar for all logged-in pages (admin/teacher/student).
 * Expects $pageTitle to be set before include.
 * ------------------------------------------------------------
 */
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? e($pageTitle) . ' - ' . APP_NAME : APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
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
                <span class="page-subtitle"><?php echo date('l, F j, Y'); ?></span>
            </div>
            <div class="topbar-right">
                <div class="notif-wrapper">
                    <a href="<?php echo BASE_URL . $notifPage; ?>" class="icon-btn notif-link" id="notifBellBtn">
                        <i class="fa-solid fa-bell"></i>
                        <?php if ($unreadCount > 0): ?><span class="badge-dot"><?php echo $unreadCount; ?></span><?php endif; ?>
                    </a>
                </div>
                <div class="user-chip">
                    <div class="avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></div>
                    <div class="user-meta">
                        <span class="user-name"><?php echo e($_SESSION['full_name'] ?? ''); ?></span>
                        <span class="user-role"><?php echo e(ucfirst($_SESSION['role'] ?? '')); ?></span>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>logout.php" class="icon-btn" title="Logout" onclick="return confirm('Log out of your account?');"><i class="fa-solid fa-right-from-bracket"></i></a>
            </div>
        </header>
        <?php endif; ?>
        <main class="page-content">
