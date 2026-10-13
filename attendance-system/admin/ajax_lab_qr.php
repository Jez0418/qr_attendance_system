<?php
/**
 * admin/ajax_lab_qr.php
 * Administrator regenerates a laboratory's wall QR code (bumps its version),
 * which revokes every printout of the old one. See qr/qr_helper.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('admin');
header('Content-Type: application/json');

try {
    $labId = (int) ($_POST['lab_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT lab_id FROM laboratories WHERE lab_id = ? AND status = 'active'");
    $stmt->execute([$labId]);
    if (!$labId || !$stmt->fetchColumn()) throw new Exception('Laboratory not found.');
    $version = rotate_lab_qr_version($pdo, $labId);
    log_activity($pdo, $_SESSION['user_id'], "Admin regenerated the QR code of laboratory #$labId (version $version)");
    echo json_encode(['success' => true, 'message' => 'New QR code generated. Print it and replace the one on the wall.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
