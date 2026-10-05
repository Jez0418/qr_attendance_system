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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile') {
    $contact = clean($_POST['contact_number'] ?? '');
    // Photos are resized in the browser and posted as a data URI, then stored in the DB:
    // Vercel has no persistent disk, so files written to uploads/ would be lost.
    $photoData = trim($_POST['photo_data'] ?? '');
    $removePhoto = !empty($_POST['remove_photo']);

    if (mb_strlen($contact) > 20) {
        $errors[] = 'Contact number must be 20 characters or fewer.';
    } elseif ($contact !== '' && !preg_match('/^[0-9+\-() ]+$/', $contact)) {
        $errors[] = 'Contact number may only contain digits, spaces, +, -, ( and ).';
    }

    $newPhoto = null;
    if ($photoData !== '') {
        $bytes = preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $photoData, $m)
            ? base64_decode($m[2], true) : false;
        if ($bytes === false || @getimagesizefromstring($bytes) === false) {
            $errors[] = 'That photo could not be read. Please choose a JPG, PNG or WEBP image.';
        } elseif (strlen($bytes) > 300 * 1024) {
            $errors[] = 'Photo is too large. Please choose a smaller image.';
        } else {
            $newPhoto = $photoData;
        }
    }

    if (empty($errors)) {
        try {
            $pdo->prepare('UPDATE students SET contact_number = ? WHERE student_id = ?')
                ->execute([$contact !== '' ? $contact : null, $studentId]);
            if ($newPhoto !== null || $removePhoto) {
                $pdo->prepare('UPDATE students SET photo = ? WHERE student_id = ?')
                    ->execute([$newPhoto, $studentId]);
            }
            $success = 'Profile updated successfully.';
        } catch (PDOException $ex) {
            error_log('student/profile.php: ' . $ex->getMessage());
            $errors[] = 'Your profile could not be saved. Please try again or contact the administrator.';
        }
    }

    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword === '') {
        $errors[] = 'Please enter a new password.';
    } else {
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
            $success = 'Password updated successfully.';
        }
    }
}

// Stored photos are data URIs; older rows may still hold a filename under uploads/photos/ (local only).
$photoSrc = '';
if (!empty($student['photo'])) {
    $photoSrc = strpos($student['photo'], 'data:image/') === 0 ? $student['photo'] : UPLOAD_URL . $student['photo'];
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
                <img id="photoPreview" src="<?php echo e($photoSrc); ?>" alt="Profile photo" style="width:100px;height:100px;border-radius:50%;object-fit:cover;<?php echo $photoSrc ? '' : 'display:none'; ?>">
                <div id="photoInitial" class="avatar" style="width:100px;height:100px;font-size:36px;margin:0 auto;<?php echo $photoSrc ? 'display:none' : ''; ?>"><?php echo strtoupper(substr($student['full_name'],0,1)); ?></div>
            </div>

            <form method="POST" id="profileForm">
                <input type="hidden" name="action" value="profile">
                <input type="hidden" name="photo_data" id="photoData">
                <input type="hidden" name="remove_photo" id="removePhoto" value="">
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
                    <div class="form-group"><label>Contact Number</label><input type="tel" name="contact_number" class="form-control" maxlength="20" placeholder="e.g. 0917 123 4567" value="<?php echo e($student['contact_number']); ?>"></div>
                </div>
                <div class="form-group">
                    <label>Profile Photo</label>
                    <input type="file" id="photoInput" class="form-control" accept="image/*">
                    <?php if ($photoSrc): ?><button type="button" class="btn btn-outline btn-sm" id="removePhotoBtn" style="margin-top:8px"><i class="fa-solid fa-trash"></i> Remove photo</button><?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary" id="profileSaveBtn">Save Changes</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Change Password</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="password">
                <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control"></div>
                <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control"></div>
                <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control"></div>
                <button type="submit" class="btn btn-primary">Update Password</button>
            </form>
        </div>
    </div>
</div>

<script>
// Resize the chosen photo to a small square JPEG in the browser (keeps the request well under
// Vercel/PHP size limits and converts phone formats like HEIC), then post it as a data URI.
const photoInput = document.getElementById('photoInput');
const photoData = document.getElementById('photoData');
const photoPreview = document.getElementById('photoPreview');
const photoInitial = document.getElementById('photoInitial');
const saveBtn = document.getElementById('profileSaveBtn');

function showPhoto(src) {
    photoPreview.src = src;
    photoPreview.style.display = src ? '' : 'none';
    photoInitial.style.display = src ? 'none' : '';
}

photoInput.addEventListener('change', () => {
    const file = photoInput.files[0];
    photoData.value = '';
    if (!file) return;
    if (!file.type.startsWith('image/')) { showToast('error', 'Please choose an image file.'); photoInput.value = ''; return; }

    saveBtn.disabled = true;
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
        const size = 320, side = Math.min(img.naturalWidth, img.naturalHeight);
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = size;
        // Center-crop to a square so the round avatar isn't stretched
        canvas.getContext('2d').drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, size, size);
        photoData.value = canvas.toDataURL('image/jpeg', 0.85);
        document.getElementById('removePhoto').value = '';
        showPhoto(photoData.value);
        URL.revokeObjectURL(url);
        saveBtn.disabled = false;
    };
    img.onerror = () => {
        URL.revokeObjectURL(url);
        photoInput.value = '';
        saveBtn.disabled = false;
        showToast('error', 'That image could not be read. Please choose a JPG or PNG photo.');
    };
    img.src = url;
});

const removeBtn = document.getElementById('removePhotoBtn');
if (removeBtn) removeBtn.addEventListener('click', () => {
    photoInput.value = '';
    photoData.value = '';
    document.getElementById('removePhoto').value = '1';
    showPhoto('');
    showToast('info', 'Photo will be removed when you tap Save Changes.');
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
