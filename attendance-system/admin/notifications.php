<?php
/**
 * admin/notifications.php
 * Admin broadcasts a new notification to all Teachers, all Students, or everyone.
 * (Nothing ever sends a notification to an admin account, so there is no "My Notifications" list here.)
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

require_once __DIR__ . '/../includes/header.php';
?>
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
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
