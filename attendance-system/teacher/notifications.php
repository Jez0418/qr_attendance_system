<?php
/**
 * teacher/notifications.php
 * View notifications sent to this teacher (session activity, admin broadcasts).
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
$pageTitle = 'Notifications';

if (isset($_GET['mark_all_read'])) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$_SESSION['user_id']]);
    redirect('teacher/notifications.php');
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
        <?php else: foreach ($notifications as $n): ?>
            <div class="alert <?php echo $n['is_read'] ? 'alert-info' : 'alert-success'; ?>" style="align-items:flex-start">
                <i class="fa-solid <?php echo $n['is_read'] ? 'fa-envelope-open' : 'fa-envelope'; ?>"></i>
                <div>
                    <strong><?php echo e($n['title']); ?></strong>
                    <div><?php echo e($n['message']); ?></div>
                    <small class="text-muted"><?php echo format_datetime($n['created_at']); ?></small>
                </div>
            </div>
        <?php endforeach; endif; ?>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
