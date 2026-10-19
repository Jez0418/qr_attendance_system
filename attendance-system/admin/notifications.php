<?php
/**
 * admin/notifications.php
 * Admin sees their own notifications and can broadcast a new
 * notification to all Teachers, all Students, or everyone.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'broadcast') {
    $title = clean($_POST['title'] ?? '');
    $message = clean($_POST['message'] ?? '');
    $target = $_POST['target'] ?? 'all';

    if ($title === '' || $message === '') {
        set_flash('error', 'Title and message are required.');
    } elseif (mb_strlen($title) > 150 || mb_strlen($message) > 500) {
        set_flash('error', 'The title can have at most 150 characters and the message at most 500.');
    } else {
        $roleFilter = match ($target) {
            'teachers' => "role = 'teacher'",
            'students' => "role = 'student'",
            default => "role IN ('teacher','student')",
        };
        try {
            // One statement for everybody: all-or-nothing, and one round trip instead of one per user.
            $send = $pdo->prepare("INSERT INTO notifications (user_id, title, message) SELECT user_id, CAST(? AS varchar), CAST(? AS varchar) FROM users WHERE $roleFilter AND status = 'active'");
            $send->execute([$title, $message]);
            log_activity($pdo, $_SESSION['user_id'], "Broadcast notification to $target: $title");
            set_flash('success', 'Notification sent to ' . $send->rowCount() . ' user(s).');
        } catch (PDOException $e) {
            error_log('broadcast: ' . $e->getMessage());
            set_flash('error', safe_error_message($e));
        }
    }
    redirect('admin/notifications.php');
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$_SESSION['user_id']]);
    redirect('admin/notifications.php');
}

if (isset($_GET['mark_read']) && ($id = id_param($_GET['mark_read']))) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')->execute([$id, $_SESSION['user_id']]);
    redirect('admin/notifications.php' . (isset($_GET['page']) ? '?page=' . (int) $_GET['page'] : ''));
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
<div class="grid-2">
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

    <div class="card">
        <div class="card-header"><h3>Broadcast Notification</h3></div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="broadcast">
                <div class="form-group">
                    <label>Send To</label>
                    <select name="target" class="form-control">
                        <option value="all">All Teachers & Students</option>
                        <option value="teachers">Teachers Only</option>
                        <option value="students">Students Only</option>
                    </select>
                </div>
                <div class="form-group"><label>Title *</label><input type="text" name="title" class="form-control" required></div>
                <div class="form-group"><label>Message *</label><textarea name="message" class="form-control" rows="4" required></textarea></div>
                <button type="submit" class="btn btn-primary btn-block"><i class="fa-solid fa-paper-plane"></i> Send Notification</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
