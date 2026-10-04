<?php
/**
 * admin/ajax_subjects.php — create / update / delete subjects.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $code   = clean($_POST['subject_code'] ?? '');
        $name   = clean($_POST['subject_name'] ?? '');
        $units  = (float) ($_POST['units'] ?? 3.0);
        $status = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        if ($code === '' || $name === '') throw new Exception('Subject code and name are required.');

        $check = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE subject_code = ?');
        $check->execute([$code]);
        if ($check->fetchColumn() > 0) throw new Exception('Subject code already exists.');

        $stmt = $pdo->prepare('INSERT INTO subjects (subject_code, subject_name, units, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$code, $name, $units, $status]);
        log_activity($pdo, $_SESSION['user_id'], "Added subject: $name");
        echo json_encode(['success' => true, 'message' => 'Subject added successfully.']);

    } elseif ($action === 'update') {
        $id     = (int) ($_POST['subject_id'] ?? 0);
        $code   = clean($_POST['subject_code'] ?? '');
        $name   = clean($_POST['subject_name'] ?? '');
        $units  = (float) ($_POST['units'] ?? 3.0);
        $status = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        if (!$id || $code === '' || $name === '') throw new Exception('Subject code and name are required.');

        $check = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE subject_code = ? AND subject_id != ?');
        $check->execute([$code, $id]);
        if ($check->fetchColumn() > 0) throw new Exception('Subject code already used by another subject.');

        $stmt = $pdo->prepare('UPDATE subjects SET subject_code=?, subject_name=?, units=?, status=? WHERE subject_id=?');
        $stmt->execute([$code, $name, $units, $status, $id]);
        log_activity($pdo, $_SESSION['user_id'], "Updated subject: $name");
        echo json_encode(['success' => true, 'message' => 'Subject updated successfully.']);

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['subject_id'] ?? 0);
        if (!$id) throw new Exception('Invalid subject.');
        $stmt = $pdo->prepare('DELETE FROM subjects WHERE subject_id = ?');
        $stmt->execute([$id]);
        log_activity($pdo, $_SESSION['user_id'], "Deleted subject ID: $id");
        echo json_encode(['success' => true, 'message' => 'Subject deleted successfully.']);
    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
