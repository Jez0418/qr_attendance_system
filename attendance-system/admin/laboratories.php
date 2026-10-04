<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Laboratory Management';

$labs = $pdo->query('SELECT * FROM laboratories ORDER BY lab_name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Laboratories (<?php echo count($labs); ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Laboratory</button>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Lab Name</th><th>Location</th><th>GPS Coordinates</th><th>Radius</th><th>Capacity</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($labs)): ?>
                    <tr><td colspan="8" class="text-center text-muted">No laboratories found.</td></tr>
                <?php else: foreach ($labs as $l): $hasCoords = $l['latitude'] !== null && $l['longitude'] !== null; ?>
                    <tr>
                        <td><?php echo e($l['lab_code']); ?></td>
                        <td><?php echo e($l['lab_name']); ?></td>
                        <td><?php echo e($l['location']); ?></td>
                        <td>
                            <?php if ($hasCoords): ?>
                                <span style="font-size:12px"><?php echo e($l['latitude']); ?>, <?php echo e($l['longitude']); ?></span>
                            <?php else: ?>
                                <span class="badge badge-late"><i class="fa-solid fa-triangle-exclamation"></i> Not set</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) $l['allowed_radius_meters']; ?>m</td>
                        <td><?php echo e($l['capacity']); ?></td>
                        <td><span class="badge badge-<?php echo $l['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick='openEditModal(<?php echo json_encode($l); ?>)'><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-danger btn-sm" onclick="deleteLab(<?php echo $l['lab_id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="labModal">
    <div class="modal">
        <div class="modal-header"><h3 id="labModalTitle">Add Laboratory</h3><button class="modal-close" onclick="closeModal('labModal')">&times;</button></div>
        <form id="labForm">
            <div class="modal-body">
                <input type="hidden" name="lab_id" id="lab_id">
                <div class="form-row">
                    <div class="form-group"><label>Lab Code *</label><input type="text" name="lab_code" id="lab_code" class="form-control" required></div>
                    <div class="form-group"><label>Lab Name *</label><input type="text" name="lab_name" id="lab_name" class="form-control" required></div>
                </div>
                <div class="form-group"><label>Location</label><input type="text" name="location" id="location" class="form-control"></div>
                <div class="form-row">
                    <div class="form-group"><label>Latitude</label><input type="text" name="latitude" id="latitude" class="form-control" placeholder="e.g. 17.6132000"></div>
                    <div class="form-group"><label>Longitude</label><input type="text" name="longitude" id="longitude" class="form-control" placeholder="e.g. 121.7270000"></div>
                </div>
                <button type="button" class="btn btn-outline btn-sm" style="margin-bottom:16px" onclick="useMyLocation()"><i class="fa-solid fa-location-crosshairs"></i> Use My Current Location</button>
                <div class="form-row">
                    <div class="form-group"><label>Allowed Radius (meters)</label><input type="number" name="allowed_radius_meters" id="allowed_radius_meters" class="form-control" value="50" min="5" max="1000"></div>
                    <div class="form-group"><label>Capacity</label><input type="number" name="capacity" id="capacity" class="form-control" value="40"></div>
                </div>
                <div class="form-group"><label>Status</label>
                    <select name="status" id="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </div>
                <div class="alert alert-info" style="margin-bottom:0"><i class="fa-solid fa-circle-info"></i> GPS coordinates are required before a QR attendance session can be activated for this laboratory.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('labModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="labSubmitBtn">Save Laboratory</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('labForm').reset();
    document.getElementById('lab_id').value = '';
    document.getElementById('labModalTitle').textContent = 'Add Laboratory';
    openModal('labModal');
}
function openEditModal(l) {
    document.getElementById('lab_id').value = l.lab_id;
    document.getElementById('lab_code').value = l.lab_code;
    document.getElementById('lab_name').value = l.lab_name;
    document.getElementById('location').value = l.location || '';
    document.getElementById('latitude').value = l.latitude || '';
    document.getElementById('longitude').value = l.longitude || '';
    document.getElementById('allowed_radius_meters').value = l.allowed_radius_meters || 50;
    document.getElementById('capacity').value = l.capacity;
    document.getElementById('status').value = l.status;
    document.getElementById('labModalTitle').textContent = 'Edit Laboratory';
    openModal('labModal');
}
function useMyLocation() {
    if (!navigator.geolocation) { showToast('error', 'Geolocation is not supported by this browser.'); return; }
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            document.getElementById('latitude').value = pos.coords.latitude.toFixed(7);
            document.getElementById('longitude').value = pos.coords.longitude.toFixed(7);
            showToast('success', 'Current location captured. Review before saving.');
        },
        () => showToast('error', 'Could not get your location. Please enter coordinates manually.')
    );
}
document.getElementById('labForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('labSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving...';
    const data = Object.fromEntries(new FormData(e.target));
    data.action = data.lab_id ? 'update' : 'create';
    const res = await ajaxPost('ajax_laboratories.php', data);
    if (res.success) { showToast('success', res.message); closeModal('labModal'); setTimeout(() => location.reload(), 700); }
    else showToast('error', res.message);
    btn.disabled = false; btn.innerHTML = 'Save Laboratory';
});
async function deleteLab(id) {
    if (!confirmDelete('Delete this laboratory? Related class assignments will also be removed.')) return;
    const res = await ajaxPost('ajax_laboratories.php', { action: 'delete', lab_id: id });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); } else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
