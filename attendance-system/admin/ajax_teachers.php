<?php
/**
 * admin/ajax_teachers.php — create / update / delete teacher records.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $empNo    = clean($_POST['employee_number'] ?? '');
        $fullName = clean($_POST['full_name'] ?? '');
        $dept     = clean($_POST['department'] ?? '');
        $contact  = clean($_POST['contact_number'] ?? '');
        $email    = clean($_POST['email'] ?? '');
        $username = clean($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $status   = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if ($empNo === '' || $fullName === '' || $email === '' || $username === '' || $password === '') {
            throw new Exception('Please fill in all required fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Please provide a valid email address.');

        $pdo->beginTransaction();
        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
        $check->execute([$username, $email]);
        if ($check->fetchColumn() > 0) throw new Exception('Username or email already in use.');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ins = $pdo->prepare('INSERT INTO users (username, password, role, email, status) VALUES (?, ?, "teacher", ?, ?)');
        $ins->execute([$username, $hash, $email, $status]);
        $userId = $pdo->lastInsertId();

        $ins2 = $pdo->prepare('INSERT INTO teachers (user_id, employee_number, full_name, department, contact_number) VALUES (?, ?, ?, ?, ?)');
        $ins2->execute([$userId, $empNo, $fullName, $dept, $contact]);

        create_notification($pdo, $userId, 'Welcome!', "Your teacher account has been created. Username: $username");
        log_activity($pdo, $_SESSION['user_id'], "Added teacher: $fullName");
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Teacher added successfully.']);

    } elseif ($action === 'update') {
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        $empNo     = clean($_POST['employee_number'] ?? '');
        $fullName  = clean($_POST['full_name'] ?? '');
        $dept      = clean($_POST['department'] ?? '');
        $contact   = clean($_POST['contact_number'] ?? '');
        $email     = clean($_POST['email'] ?? '');
        $username  = clean($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $status    = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$teacherId || $empNo === '' || $fullName === '' || $email === '' || $username === '') {
            throw new Exception('Please fill in all required fields.');
        }

        $find = $pdo->prepare('SELECT user_id FROM teachers WHERE teacher_id = ?');
        $find->execute([$teacherId]);
        $userId = $find->fetchColumn();
        if (!$userId) throw new Exception('Teacher not found.');

        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?');
        $check->execute([$username, $email, $userId]);
        if ($check->fetchColumn() > 0) throw new Exception('Username or email already used by another account.');

        $pdo->beginTransaction();
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $upd = $pdo->prepare('UPDATE users SET username=?, email=?, status=?, password=? WHERE user_id=?');
            $upd->execute([$username, $email, $status, $hash, $userId]);
        } else {
            $upd = $pdo->prepare('UPDATE users SET username=?, email=?, status=? WHERE user_id=?');
            $upd->execute([$username, $email, $status, $userId]);
        }
        $upd2 = $pdo->prepare('UPDATE teachers SET employee_number=?, full_name=?, department=?, contact_number=? WHERE teacher_id=?');
        $upd2->execute([$empNo, $fullName, $dept, $contact, $teacherId]);

        log_activity($pdo, $_SESSION['user_id'], "Updated teacher: $fullName");
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Teacher updated successfully.']);

    } elseif ($action === 'delete') {
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        if (!$teacherId) throw new Exception('Invalid teacher.');
        $find = $pdo->prepare('SELECT user_id, full_name FROM teachers WHERE teacher_id = ?');
        $find->execute([$teacherId]);
        $row = $find->fetch();
        if (!$row) throw new Exception('Teacher not found.');

        $del = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
        $del->execute([$row['user_id']]);

        log_activity($pdo, $_SESSION['user_id'], "Deleted teacher: {$row['full_name']}");
        echo json_encode(['success' => true, 'message' => 'Teacher deleted successfully.']);
    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
