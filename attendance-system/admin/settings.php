<?php
/**
 * admin/settings.php
 * System-wide configurable values (Admin > Settings), backed by the
 * `settings` table so these don't need to be hardcoded or require a
 * code change to adjust.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $maxAccuracy = (int) ($_POST['max_gps_accuracy_meters'] ?? 100);
    $defaultRadius = (int) ($_POST['default_allowed_radius_meters'] ?? 50);
    $lateGrace = (int) ($_POST['late_grace_minutes'] ?? 15);

    $errors = [];
    if ($maxAccuracy < 10 || $maxAccuracy > 1000) $errors[] = 'Maximum GPS accuracy must be between 10 and 1000 meters.';
    if ($defaultRadius < 5 || $defaultRadius > 1000) $errors[] = 'Default allowed radius must be between 5 and 1000 meters.';
    if ($lateGrace < 0 || $lateGrace > 180) $errors[] = 'Late grace period must be between 0 and 180 minutes.';

    if (empty($errors)) {
        set_setting($pdo, 'max_gps_accuracy_meters', $maxAccuracy);
        set_setting($pdo, 'default_allowed_radius_meters', $defaultRadius);
        set_setting($pdo, 'late_grace_minutes', $lateGrace);
        log_activity($pdo, $_SESSION['user_id'], 'Updated system settings');
        set_flash('success', 'Settings updated successfully.');
    } else {
        set_flash('error', implode(' ', $errors));
    }
    redirect('admin/settings.php');
}

$maxAccuracy = get_setting_int($pdo, 'max_gps_accuracy_meters', 100);
$defaultRadius = get_setting_int($pdo, 'default_allowed_radius_meters', 50);
$lateGrace = get_setting_int($pdo, 'late_grace_minutes', 15);

$missingGeoCount = $pdo->query('SELECT COUNT(*) FROM laboratories WHERE latitude IS NULL OR longitude IS NULL')->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Attendance Settings</h3></div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Maximum GPS Accuracy (meters)</label>
                    <input type="number" name="max_gps_accuracy_meters" class="form-control" value="<?php echo e($maxAccuracy); ?>" min="10" max="1000" required>
                    <small class="text-muted">Scans with a worse (higher) reported GPS accuracy than this are rejected — the device isn't confident enough about its own location.</small>
                </div>
                <div class="form-group">
                    <label>Default Allowed Radius (meters)</label>
                    <input type="number" name="default_allowed_radius_meters" class="form-control" value="<?php echo e($defaultRadius); ?>" min="5" max="1000" required>
                    <small class="text-muted">Used as the starting geofence radius when a new laboratory is created (each lab can still be customized individually).</small>
                </div>
                <div class="form-group">
                    <label>Late Grace Period (minutes)</label>
                    <input type="number" name="late_grace_minutes" class="form-control" value="<?php echo e($lateGrace); ?>" min="0" max="180" required>
                    <small class="text-muted">Students who scan more than this many minutes after a class starts are marked <strong>Late</strong>; earlier scans are <strong>Present</strong>. Attendance opens automatically at the start time and closes at the end time.</small>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Geofencing Status</h3></div>
        <div class="card-body">
            <?php if ($missingGeoCount > 0): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <?php echo (int) $missingGeoCount; ?> laboratory(ies) still have no GPS coordinates set. Attendance sessions cannot be activated for them until an admin configures coordinates in <a href="laboratories.php">Laboratories</a>.
                </div>
            <?php else: ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> All laboratories have GPS coordinates configured.</div>
            <?php endif; ?>
            <p class="text-muted" style="font-size:13px">
                Every laboratory needs its own latitude, longitude, and allowed radius before attendance
                can be geofenced there. Set these per-lab from <a href="laboratories.php">Admin &gt; Laboratories</a> —
                use "Use My Current Location" while standing in the lab for the most accurate setup.
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
