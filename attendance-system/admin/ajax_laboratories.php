<?php
/**
 * admin/ajax_laboratories.php — create / update / delete laboratories.
 * Now also manages the GPS coordinates + geofence radius each lab
 * needs before a QR attendance session can be activated for it.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

/** Validate & normalize latitude/longitude/radius from POST. Returns [lat, lon, radius] or throws. */
function parse_geo_fields() {
    $latRaw = trim($_POST['latitude'] ?? '');
    $lonRaw = trim($_POST['longitude'] ?? '');
    $radius = (int) ($_POST['allowed_radius_meters'] ?? 50);

    if ($radius < 5 || $radius > 1000) throw new Exception('Allowed radius must be between 5 and 1000 meters.');

    if ($latRaw === '' && $lonRaw === '') {
        return [null, null, $radius]; // both blank is fine — just means "not configured yet"
    }
    if ($latRaw === '' || $lonRaw === '') {
        throw new Exception('Please provide both latitude and longitude, or leave both blank.');
    }
    if (!is_numeric($latRaw) || !is_numeric($lonRaw)) {
        throw new Exception('Latitude and longitude must be numeric.');
    }
    $lat = (float) $latRaw;
    $lon = (float) $lonRaw;
    if ($lat < -90 || $lat > 90) throw new Exception('Latitude must be between -90 and 90.');
    if ($lon < -180 || $lon > 180) throw new Exception('Longitude must be between -180 and 180.');

    return [$lat, $lon, $radius];
}

try {
    if ($action === 'create') {
        $code = clean($_POST['lab_code'] ?? '');
        $name = clean($_POST['lab_name'] ?? '');
        $loc  = clean($_POST['location'] ?? '');
        $cap  = (int) ($_POST['capacity'] ?? 40);
        $status = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        if ($code === '' || $name === '') throw new Exception('Lab code and name are required.');
        [$lat, $lon, $radius] = parse_geo_fields();

        $check = $pdo->prepare('SELECT COUNT(*) FROM laboratories WHERE lab_code = ?');
        $check->execute([$code]);
        if ($check->fetchColumn() > 0) throw new Exception('Lab code already exists.');

        $stmt = $pdo->prepare('INSERT INTO laboratories (lab_code, lab_name, location, latitude, longitude, allowed_radius_meters, capacity, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$code, $name, $loc, $lat, $lon, $radius, $cap, $status]);
        log_activity($pdo, $_SESSION['user_id'], "Added laboratory: $name");
        echo json_encode(['success' => true, 'message' => 'Laboratory added successfully.']);

    } elseif ($action === 'update') {
        $id   = (int) ($_POST['lab_id'] ?? 0);
        $code = clean($_POST['lab_code'] ?? '');
        $name = clean($_POST['lab_name'] ?? '');
        $loc  = clean($_POST['location'] ?? '');
        $cap  = (int) ($_POST['capacity'] ?? 40);
        $status = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        if (!$id || $code === '' || $name === '') throw new Exception('Lab code and name are required.');
        [$lat, $lon, $radius] = parse_geo_fields();

        $check = $pdo->prepare('SELECT COUNT(*) FROM laboratories WHERE lab_code = ? AND lab_id != ?');
        $check->execute([$code, $id]);
        if ($check->fetchColumn() > 0) throw new Exception('Lab code already used by another laboratory.');

        $stmt = $pdo->prepare('UPDATE laboratories SET lab_code=?, lab_name=?, location=?, latitude=?, longitude=?, allowed_radius_meters=?, capacity=?, status=? WHERE lab_id=?');
        $stmt->execute([$code, $name, $loc, $lat, $lon, $radius, $cap, $status, $id]);
        log_activity($pdo, $_SESSION['user_id'], "Updated laboratory: $name");
        echo json_encode(['success' => true, 'message' => 'Laboratory updated successfully.']);

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['lab_id'] ?? 0);
        if (!$id) throw new Exception('Invalid laboratory.');
        $stmt = $pdo->prepare('DELETE FROM laboratories WHERE lab_id = ?');
        $stmt->execute([$id]);
        log_activity($pdo, $_SESSION['user_id'], "Deleted laboratory ID: $id");
        echo json_encode(['success' => true, 'message' => 'Laboratory deleted successfully.']);
    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
