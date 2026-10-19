<?php
/**
 * admin/ajax_notification_read.php
 * Mark one of the current admin's own notifications as read (clicked from the notifications list).
 * POST: notification_id.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new Exception('Invalid request.');
    $id = (int) ($_POST['notification_id'] ?? 0);
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')
        ->execute([$id, $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
