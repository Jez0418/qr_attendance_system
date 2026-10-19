<?php
/**
 * student/notifications.php
 * View notifications sent to this student (session alerts, admin broadcasts).
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');
$pageTitle = 'Notifications';

if (isset($_GET['mark_all_read'])) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$_SESSION['user_id']]);
    redirect('student/notifications.php');
}

if (isset($_GET['mark_read']) && ($id = id_param($_GET['mark_read']))) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')->execute([$id, $_SESSION['user_id']]);
    redirect('student/notifications.php' . (isset($_GET['page']) ? '?page=' . (int) $_GET['page'] : ''));
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
$countStmt->execute([$_SESSION['user_id']]);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 10);

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT {$p['limit']} OFFSET {$p['offset']}");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>My Notifications</h3>
        <a href="?mark_all_read=1" class="btn btn-outline btn-sm">Mark all as read</a>
    </div>
    <div class="card-body">
        <?php if (empty($notifications)): ?>
            <div class="empty-state"><i class="fa-solid fa-bell"></i><p>No notifications yet.</p></div>
        <?php else: ?>
            <ul class="notif-list">
            <?php foreach ($notifications as $k => $n): ?>
                <li style="--i:<?php echo min($k, 8); ?>">
                    <a class="notif<?php echo $n['is_read'] ? '' : ' is-unread'; ?>" href="?mark_read=<?php echo (int) $n['notification_id']; ?>&page=<?php echo $p['page']; ?>">
                        <span class="notif-icon"><i class="fa-solid <?php echo $n['is_read'] ? 'fa-envelope-open' : 'fa-envelope'; ?>"></i></span>
                        <div class="notif-main">
                            <div class="notif-top"><strong><?php echo e($n['title']); ?></strong><?php if (!$n['is_read']): ?><span class="notif-new">New</span><?php endif; ?></div>
                            <div class="notif-msg"><?php echo e($n['message']); ?></div>
                            <time class="notif-time"><?php echo format_datetime($n['created_at']); ?></time>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
