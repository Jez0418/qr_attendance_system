<?php
/**
 * admin/ajax_teachers.php — create / update / delete teacher records.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_delete.php';
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
        check_lengths(['Employee number' => [$empNo, 30], 'Full name' => [$fullName, 150], 'Department' => [$dept, 100],
                       'Contact number' => [$contact, 20], 'Email' => [$email, 100], 'Username' => [$username, 50]]);

        $pdo->beginTransaction();
        $numCheck = $pdo->prepare('SELECT COUNT(*) FROM teachers WHERE employee_number = ?');
        $numCheck->execute([$empNo]);
        if ($numCheck->fetchColumn() > 0) throw new Exception('That employee number is already registered.');
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
        if (!supabase_auth_provision_profile($pdo, (int) $userId)) {
            throw new Exception('The account could not be connected to secure sign-in. Please try again.');
        }
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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Please provide a valid email address.');
        check_lengths(['Employee number' => [$empNo, 30], 'Full name' => [$fullName, 150], 'Department' => [$dept, 100],
                       'Contact number' => [$contact, 20], 'Email' => [$email, 100], 'Username' => [$username, 50]]);

        $find = $pdo->prepare('SELECT u.user_id, u.email, u.auth_user_id FROM teachers t JOIN users u ON u.user_id = t.user_id WHERE t.teacher_id = ?');
        $find->execute([$teacherId]);
        $account = $find->fetch(PDO::FETCH_ASSOC);
        $userId = $account['user_id'] ?? null;
        if (!$userId) throw new Exception('Teacher not found.');

        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?');
        $check->execute([$username, $email, $userId]);
        if ($check->fetchColumn() > 0) throw new Exception('Username or email already used by another account.');
        $numCheck = $pdo->prepare('SELECT COUNT(*) FROM teachers WHERE employee_number = ? AND teacher_id != ?');
        $numCheck->execute([$empNo, $teacherId]);
        if ($numCheck->fetchColumn() > 0) throw new Exception('That employee number belongs to another teacher.');

        $pdo->beginTransaction();
        if (supabase_auth_enabled() && !supabase_auth_sync_admin_changes($account, $email, $password)) {
            throw new Exception('The account could not be updated in secure sign-in. Please try again.');
        }
        $linkedAuth = trim((string) ($account['auth_user_id'] ?? '')) !== '';
        if ($password !== '' && !$linkedAuth) {
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
        // A deactivated account or a new password must end any login that is already open.
        if ($status === 'inactive' || $password !== '') destroy_user_sessions($pdo, (int) $userId);
        echo json_encode(['success' => true, 'message' => 'Teacher updated successfully.']);

    } elseif ($action === 'delete_preview' || $action === 'delete') {
        // Only an Inactive teacher can go; their classes (and those classes' history) go with them (includes/admin_delete.php).
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        if (!$teacherId) throw new Exception('Invalid teacher.');
        if ($action === 'delete_preview') {
            echo json_encode(['success' => true] + admin_delete_preview($pdo, 'teacher', $teacherId));
        } else {
            echo json_encode(['success' => true, 'message' => admin_delete_perform($pdo, 'teacher', $teacherId, (int) $_SESSION['user_id'])]);
        }
    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
