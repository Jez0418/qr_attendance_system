<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Subject Management';

$search = clean($_GET['search'] ?? '');
$where = ''; $params = [];
if ($search !== '') { $where = 'WHERE s.subject_name LIKE ? OR s.subject_code LIKE ?'; $params = ["%$search%", "%$search%"]; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM subjects s $where");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 10);

$stmt = $pdo->prepare("
    SELECT s.*,
        (SELECT string_agg(DISTINCT t.full_name, ', ' ORDER BY t.full_name)
            FROM teacher_subjects ts JOIN teachers t ON t.teacher_id = ts.teacher_id
            WHERE ts.subject_id = s.subject_id AND ts.status = 'active') AS teachers_assigned,
        (SELECT COUNT(*) FROM teacher_subjects ts WHERE ts.subject_id = s.subject_id) AS class_count
    FROM subjects s
    $where
    ORDER BY s.subject_code ASC
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$subjects = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Subjects (<?php echo $totalRows; ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Subject</button>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar subject-search">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="form-control" name="search" placeholder="Search code or name..." value="<?php echo e($search); ?>"></div>
            <button class="btn btn-outline btn-sm" type="submit">Search</button>
            <?php if ($search): ?><a href="subjects.php" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
        </form>
        <div class="table-wrapper">
            <table class="data-table subjects-table">
                <thead><tr><th>Code</th><th>Subject Name</th><th>Teacher Assigned</th><th>Classes</th><th>Units</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($subjects)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No subjects found.</td></tr>
                <?php else: foreach ($subjects as $s): ?>
                    <tr>
                        <td><?php echo e($s['subject_code']); ?></td>
                        <td><?php echo e($s['subject_name']); ?></td>
                        <td><?php echo $s['teachers_assigned'] ? e($s['teachers_assigned']) : '<span class="badge-unassigned">Unassigned</span>'; ?></td>
                        <td><?php echo (int) $s['class_count']; ?></td>
                        <td><?php echo e($s['units']); ?></td>
                        <td><span class="badge badge-<?php echo $s['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                        <td>
                            <div style="display:flex;gap:8px">
                                <button class="btn btn-outline btn-sm" style="width:40px;height:40px;padding:0" title="Edit subject" aria-label="Edit subject" onclick='openEditModal(<?php echo js_attr_json($s); ?>)'><i class="fa-solid fa-pen"></i></button>
                                <button class="btn btn-danger btn-sm" style="width:40px;height:40px;padding:0" title="Delete subject" aria-label="Delete subject" onclick="deleteSubject(<?php echo $s['subject_id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <?php if ($totalRows > 0): ?>
                <span class="table-count">Showing <?php echo $p['offset'] + 1; ?>-<?php echo $p['offset'] + count($subjects); ?> of <?php echo $totalRows; ?></span>
            <?php endif; ?>
            <?php render_pagination($p['page'], $p['totalPages']); ?>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="subjectModal">
    <div class="modal">
        <div class="modal-header"><h3 id="subjectModalTitle">Add Subject</h3><button class="modal-close" onclick="closeModal('subjectModal')">&times;</button></div>
        <form id="subjectForm">
            <div class="modal-body">
                <input type="hidden" name="subject_id" id="subject_id">
                <div class="form-group"><label>Subject Code *</label><input type="text" name="subject_code" id="subject_code" class="form-control" required></div>
                <div class="form-group"><label>Subject Name *</label><input type="text" name="subject_name" id="subject_name" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label>Units</label><input type="number" step="0.5" name="units" id="units" class="form-control" value="3.0"></div>
                    <div class="form-group"><label>Status</label>
                        <select name="status" id="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('subjectModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="subjectSubmitBtn">Save Subject</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('subjectForm').reset();
    document.getElementById('subject_id').value = '';
    document.getElementById('subjectModalTitle').textContent = 'Add Subject';
    openModal('subjectModal');
}
function openEditModal(s) {
    document.getElementById('subject_id').value = s.subject_id;
    document.getElementById('subject_code').value = s.subject_code;
    document.getElementById('subject_name').value = s.subject_name;
    document.getElementById('units').value = s.units;
    document.getElementById('status').value = s.status;
    document.getElementById('subjectModalTitle').textContent = 'Edit Subject';
    openModal('subjectModal');
}
document.getElementById('subjectForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('subjectSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving...';
    const data = Object.fromEntries(new FormData(e.target));
    data.action = data.subject_id ? 'update' : 'create';
    const res = await ajaxPost('ajax_subjects.php', data);
    if (res.success) { showToast('success', res.message); closeModal('subjectModal'); setTimeout(() => location.reload(), 700); }
    else showToast('error', res.message);
    btn.disabled = false; btn.innerHTML = 'Save Subject';
});
async function deleteSubject(id) {
    if (!confirmDelete('Delete this subject? This cannot be undone.')) return;
    const res = await ajaxPost('ajax_subjects.php', { action: 'delete', subject_id: id });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); } else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
