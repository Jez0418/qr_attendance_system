<?php
/**
 * admin/ajax_todays_classes.php — GET, JSON.
 * Today's class meetings with their live status, polled by the
 * "Today's Classes" card on admin/dashboard.php every 30 seconds.
 * The date is always the server's current Asia/Manila date
 * (includes/schedule.php), never taken from the client.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('admin');
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    echo json_encode(['success' => true] + todays_classes_payload($pdo));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load today\'s classes.']);
    error_log('ajax_todays_classes: ' . $e->getMessage());
}
