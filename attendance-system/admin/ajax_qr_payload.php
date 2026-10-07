<?php
/**
 * admin/ajax_qr_payload.php
 * Polled by qr_management.php so the on-screen QR codes keep rotating (see qr/qr_helper.php).
 * Returns a fresh payload for every session in ?ids=1,2,3 that is still open.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('admin');
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))))));
    if (!$ids || count($ids) > 50) throw new Exception('Invalid request.');
    auto_expire_sessions($pdo);

    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT session_id, qr_token FROM attendance_sessions WHERE is_active = 1 AND session_id IN ($in)");
    $stmt->execute($ids);
    $payloads = [];
    foreach ($stmt->fetchAll() as $s) {
        $payloads[(int) $s['session_id']] = qr_build_session_payload($s['session_id'], $s['qr_token']);
    }
    echo json_encode(['success' => true, 'payloads' => (object) $payloads]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
