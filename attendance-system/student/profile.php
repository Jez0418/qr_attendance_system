<?php
/**
 * student/profile.php
 * Lets the student view their info and update contact number,
 * photo, and password. Student number/name/program are managed by admin.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');
$pageTitle = 'My Profile';

$studentId = $_SESSION['profile_id'];

$stmt = $pdo->prepare('
    SELECT s.*, u.username, u.email, pr.program_name
    FROM students s JOIN users u ON u.user_id = s.user_id
    LEFT JOIN programs pr ON pr.program_id = s.program_id
    WHERE s.student_id = ?
');
$stmt->execute([$studentId]);
$student = $stmt->fetch();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact = clean($_POST['contact_number'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $upd = $pdo->prepare('UPDATE students SET contact_number = ? WHERE student_id = ?');
    $upd->execute([$contact, $studentId]);

    // Handle photo upload
    if (!empty($_FILES['photo']['name'])) {
        $allowed = ['jpg','jpeg','png','webp'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
            $filename = 'student_' . $studentId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $filename)) {
                $pdo->prepare('UPDATE students SET photo = ? WHERE student_id = ?')->execute([$filename, $studentId]);
            }
        } else {
            $errors[] = 'Photo must be JPG, PNG, or WEBP.';
        }
    }

    if ($newPassword !== '') {
        $pwStmt = $pdo->prepare('SELECT password FROM users WHERE user_id = ?');
        $pwStmt->execute([$_SESSION['user_id']]);
        $currentHash = $pwStmt->fetchColumn();
        if (!password_verify($currentPassword, $currentHash)) {
            $errors[] = 'Current password is incorrect.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        } elseif (strlen($newPassword) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([$hash, $_SESSION['user_id']]);
            $success = 'Profile and password updated successfully.';
        }
    }

    if (empty($errors) && $success === '') $success = 'Profile updated successfully.';

    // Refresh data
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Profile Information</h3></div>
        <div class="card-body">
            <?php foreach ($errors as $err): ?><div class="alert alert-error"><?php echo e($err); ?></div><?php endforeach; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

            <div class="text-center" style="margin-bottom:20px">
                <?php if ($student['photo']): ?>
                    <img src="<?php echo UPLOAD_URL . e($student['photo']); ?>" style="width:100px;height:100px;border-radius:50%;object-fit:cover">
                <?php else: ?>
                    <div class="avatar" style="width:100px;height:100px;font-size:36px;margin:0 auto"><?php echo strtoupper(substr($student['full_name'],0,1)); ?></div>
                <?php endif; ?>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group"><label>Student Number</label><input type="text" class="form-control" value="<?php echo e($student['student_number']); ?>" disabled></div>
                    <div class="form-group"><label>Full Name</label><input type="text" class="form-control" value="<?php echo e($student['full_name']); ?>" disabled></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Program</label><input type="text" class="form-control" value="<?php echo e($student['program_name'] ?? '—'); ?>" disabled></div>
                    <div class="form-group"><label>Year Level</label><input type="text" class="form-control" value="Year <?php echo e($student['year_level']); ?>" disabled></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Email</label><input type="text" class="form-control" value="<?php echo e($student['email']); ?>" disabled></div>
                    <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" class="form-control" value="<?php echo e($student['contact_number']); ?>"></div>
                </div>
                <div class="form-group"><label>Profile Photo</label><input type="file" name="photo" class="form-control" accept="image/*"></div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Change Password</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="contact_number" value="<?php echo e($student['contact_number']); ?>">
                <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control"></div>
                <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control"></div>
                <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control"></div>
                <button type="submit" class="btn btn-primary">Update Password</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
