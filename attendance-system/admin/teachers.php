<?php
/**
 * admin/teachers.php
 * Teacher CRUD. List with search/pagination; Add/Edit via modal + AJAX.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Teacher Management';

$search = clean($_GET['search'] ?? '');
$where = ''; $params = [];
if ($search !== '') {
    $where = 'WHERE (t.full_name LIKE ? OR t.employee_number LIKE ? OR u.email LIKE ?)';
    $params = ["%$search%", "%$search%", "%$search%"];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM teachers t JOIN users u ON u.user_id = t.user_id $where");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 10);

$stmt = $pdo->prepare("
    SELECT t.*, u.username, u.email, u.status
    FROM teachers t JOIN users u ON u.user_id = t.user_id
    $where ORDER BY t.full_name ASC LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$teachers = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3>Teachers (<?php echo $totalRows; ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Teacher</button>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="form-control" name="search" placeholder="Search name, employee no., email..." value="<?php echo e($search); ?>">
            </div>
            <button class="btn btn-outline btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Search</button>
            <?php if ($search): ?><a href="teachers.php" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
        </form>

        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Employee No.</th><th>Name</th><th>Department</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($teachers)): ?>
                    <tr><td colspan="6" class="text-center text-muted">No teachers found.</td></tr>
                <?php else: foreach ($teachers as $t): ?>
                    <tr>
                        <td><?php echo e($t['employee_number']); ?></td>
                        <td><?php echo e($t['full_name']); ?></td>
                        <td><?php echo e($t['department']); ?></td>
                        <td><?php echo e($t['email']); ?></td>
                        <td><span class="badge badge-<?php echo $t['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($t['status']); ?></span></td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick='openEditModal(<?php echo json_encode($t); ?>)'><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-danger btn-sm" onclick="deleteTeacher(<?php echo $t['teacher_id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>

<div class="modal-backdrop" id="teacherModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="teacherModalTitle">Add Teacher</h3>
            <button class="modal-close" onclick="closeModal('teacherModal')">&times;</button>
        </div>
        <form id="teacherForm">
            <div class="modal-body">
                <input type="hidden" name="teacher_id" id="teacher_id">
                <div class="form-row">
                    <div class="form-group"><label>Employee Number *</label><input type="text" name="employee_number" id="employee_number" class="form-control" required></div>
                    <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" id="full_name" class="form-control" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Department</label><input type="text" name="department" id="department" class="form-control"></div>
                    <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" id="contact_number" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Email *</label><input type="email" name="email" id="email" class="form-control" required></div>
                    <div class="form-group"><label>Username *</label><input type="text" name="username" id="username" class="form-control" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label id="passwordLabel">Password *</label>
                        <input type="text" name="password" id="password" class="form-control" placeholder="Leave blank to keep unchanged">
                        <small class="text-muted">Tip: use the employee number as the password so teachers can log in with their ID.</small>
                    </div>
                    <div class="form-group">
                        <label>Account Status</label>
                        <select name="status" id="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('teacherModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="teacherSubmitBtn">Save Teacher</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('teacherForm').reset();
    document.getElementById('teacher_id').value = '';
    document.getElementById('teacherModalTitle').textContent = 'Add Teacher';
    document.getElementById('passwordLabel').textContent = 'Password *';
    document.getElementById('password').required = true;
    openModal('teacherModal');
}
document.getElementById('employee_number').addEventListener('input', (e) => {
    if (!document.getElementById('teacher_id').value) {
        document.getElementById('password').value = e.target.value;
    }
});
function openEditModal(t) {
    document.getElementById('teacherForm').reset();
    document.getElementById('teacher_id').value = t.teacher_id;
    document.getElementById('employee_number').value = t.employee_number;
    document.getElementById('full_name').value = t.full_name;
    document.getElementById('department').value = t.department || '';
    document.getElementById('contact_number').value = t.contact_number || '';
    document.getElementById('email').value = t.email;
    document.getElementById('username').value = t.username;
    document.getElementById('status').value = t.status;
    document.getElementById('teacherModalTitle').textContent = 'Edit Teacher';
    document.getElementById('passwordLabel').textContent = 'Password';
    document.getElementById('password').required = false;
    openModal('teacherModal');
}
document.getElementById('teacherForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('teacherSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving...';
    const data = Object.fromEntries(new FormData(e.target));
    data.action = data.teacher_id ? 'update' : 'create';
    try {
        const res = await ajaxPost('ajax_teachers.php', data);
        if (res.success) { showToast('success', res.message); closeModal('teacherModal'); setTimeout(() => location.reload(), 700); }
        else showToast('error', res.message);
    } catch (err) { showToast('error', 'Something went wrong. Please try again.'); }
    btn.disabled = false; btn.innerHTML = 'Save Teacher';
});
async function deleteTeacher(id) {
    if (!confirmDelete('Delete this teacher? Their class assignments will also be removed.')) return;
    const res = await ajaxPost('ajax_teachers.php', { action: 'delete', teacher_id: id });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); }
    else showToast('error', res.message);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
