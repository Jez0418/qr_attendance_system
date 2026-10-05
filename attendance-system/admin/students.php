<?php
/**
 * admin/students.php
 * Student CRUD. List with search/filter/pagination; Add/Edit via
 * modal + AJAX to ajax_students.php; Delete via AJAX confirm.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Student Management';

// ---- Filters ----
$search = clean($_GET['search'] ?? '');
$programFilter = clean($_GET['program'] ?? '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(s.full_name LIKE ? OR s.student_number LIKE ? OR u.email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($programFilter !== '') {
    $where[] = 's.program_id = ?';
    $params[] = $programFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s JOIN users u ON u.user_id = s.user_id $whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 10);

$stmt = $pdo->prepare("
    SELECT s.*, u.username, u.email, u.status, pr.program_name, pr.program_code, inst.institution_code
    FROM students s
    JOIN users u ON u.user_id = s.user_id
    LEFT JOIN programs pr ON pr.program_id = s.program_id
    LEFT JOIN institutions inst ON inst.institution_id = s.institution_id
    $whereSql
    ORDER BY s.full_name ASC
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

$programs = $pdo->query('SELECT * FROM programs WHERE status = "active" ORDER BY program_name')->fetchAll();
$institutions = $pdo->query('SELECT institution_id, institution_code, institution_name FROM institutions WHERE status="active" ORDER BY institution_name')->fetchAll();
$departments = $pdo->query('SELECT department_id, institution_id, department_name FROM departments WHERE status="active" ORDER BY department_name')->fetchAll();
$allPrograms = $pdo->query('SELECT program_id, institution_id, department_id, program_code, program_name, duration_years FROM programs WHERE status="active" ORDER BY program_code')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3>Students (<?php echo $totalRows; ?>)</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="students_import.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-file-csv"></i> Import CSV</a>
            <button class="btn btn-primary btn-sm" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Student</button>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="form-control" name="search" placeholder="Search name, ID, email..." value="<?php echo e($search); ?>">
            </div>
            <select name="program" class="form-control" style="max-width:200px" onchange="this.form.submit()">
                <option value="">All Programs</option>
                <?php foreach ($programs as $pr): ?>
                    <option value="<?php echo $pr['program_id']; ?>" <?php echo $programFilter == $pr['program_id'] ? 'selected' : ''; ?>><?php echo e($pr['program_code']); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
            <?php if ($search || $programFilter): ?><a href="students.php" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
        </form>

        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr><th>Student No.</th><th>Name</th><th>Institution/Program</th><th>Year/Section</th><th>Type</th><th>Email</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="8" class="text-center text-muted">No students found.</td></tr>
                <?php else: foreach ($students as $s): ?>
                    <tr>
                        <td><?php echo e($s['student_number']); ?></td>
                        <td><?php echo e($s['full_name']); ?></td>
                        <td><?php echo e($s['institution_code'] ?? '—'); ?> / <?php echo e($s['program_code'] ?? '—'); ?></td>
                        <td>Yr <?php echo e($s['year_level']); ?> / <?php echo e($s['section'] ?? '—'); ?></td>
                        <td><span class="badge <?php echo $s['student_type'] === 'irregular' ? 'badge-late' : 'badge-active'; ?>"><?php echo ucfirst($s['student_type'] ?? 'regular'); ?></span></td>
                        <td><?php echo e($s['email']); ?></td>
                        <td><span class="badge badge-<?php echo $s['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick='openEditModal(<?php echo json_encode($s); ?>)'><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-danger btn-sm" onclick="deleteStudent(<?php echo $s['student_id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>

<!-- ===================== ADD/EDIT MODAL ===================== -->
<div class="modal-backdrop" id="studentModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 id="studentModalTitle">Add Student</h3>
            <button class="modal-close" onclick="closeModal('studentModal')">&times;</button>
        </div>
        <form id="studentForm">
            <div class="modal-body">
                <input type="hidden" name="student_id" id="student_id">
                <div class="form-row">
                    <div class="form-group">
                        <label>Student Number *</label>
                        <input type="text" name="student_number" id="student_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" id="full_name" class="form-control" required>
                    </div>
                </div>

                <h4 style="margin:14px 0 10px;font-size:12px;color:var(--slate-500);text-transform:uppercase;letter-spacing:.03em">Academic Placement</h4>
                <div class="form-row">
                    <div class="form-group"><label>Institution *</label>
                        <select name="institution_id" id="institution_id" class="form-control" required onchange="onInstitutionChange()">
                            <option value="">Select institution</option>
                            <?php foreach ($institutions as $i): ?><option value="<?php echo $i['institution_id']; ?>"><?php echo e($i['institution_code'] . ' - ' . $i['institution_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Department *</label>
                        <select name="department_id" id="department_id" class="form-control" required onchange="onDepartmentChange()">
                            <option value="">Select institution first</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Course/Program *</label>
                        <select name="program_id" id="program_id" class="form-control" required onchange="onProgramChange()">
                            <option value="">Select department first</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Year Level *</label>
                        <select name="year_level" id="year_level" class="form-control" required>
                            <option value="">Select program first</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Section *</label>
                        <div class="input-prefix">
                            <span class="input-prefix-label" id="section_year" title="Year level">–</span>
                            <input type="text" name="section_letter" id="section_letter" class="form-control" placeholder="e.g. A" maxlength="5" pattern="[A-Za-z][A-Za-z0-9]*" title="Section letter, e.g. A, B, C" required>
                        </div>
                        <small class="text-muted">The year level is added automatically (e.g. 2 + A = 2A).</small>
                    </div>
                    <div class="form-group"><label>Student Type *</label>
                        <select name="student_type" id="student_type" class="form-control" required>
                            <option value="regular">Regular</option>
                            <option value="irregular">Irregular</option>
                        </select>
                    </div>
                </div>

                <h4 style="margin:14px 0 10px;font-size:12px;color:var(--slate-500);text-transform:uppercase;letter-spacing:.03em">Account</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" id="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" id="contact_number" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" id="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label id="passwordLabel">Password *</label>
                        <input type="text" name="password" id="password" class="form-control" placeholder="Leave blank to keep unchanged">
                        <small class="text-muted">Tip: use the student number as the password so students can log in with their ID.</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Account Status</label>
                    <select name="status" id="status" class="form-control">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('studentModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="studentSubmitBtn">Save Student</button>
            </div>
        </form>
    </div>
</div>

<script>
const DEPARTMENTS = <?php echo json_encode($departments); ?>;
const PROGRAMS = <?php echo json_encode($allPrograms); ?>;

function populateDepartments(institutionId, selectedDeptId) {
    const sel = document.getElementById('department_id');
    sel.innerHTML = '<option value="">Select department</option>';
    DEPARTMENTS.filter(d => String(d.institution_id) === String(institutionId)).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.department_id; opt.textContent = d.department_name;
        if (selectedDeptId && String(d.department_id) === String(selectedDeptId)) opt.selected = true;
        sel.appendChild(opt);
    });
}
function populatePrograms(departmentId, selectedProgramId) {
    const sel = document.getElementById('program_id');
    sel.innerHTML = '<option value="">Select program</option>';
    PROGRAMS.filter(p => String(p.department_id) === String(departmentId)).forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.program_id; opt.textContent = p.program_code + ' - ' + p.program_name;
        opt.dataset.duration = p.duration_years;
        if (selectedProgramId && String(p.program_id) === String(selectedProgramId)) opt.selected = true;
        sel.appendChild(opt);
    });
}
function populateYearLevels(maxYear, selectedYear) {
    const sel = document.getElementById('year_level');
    sel.innerHTML = '';
    const years = Math.max(1, Math.ceil(parseFloat(maxYear) || 4));
    const labels = ['1st Year','2nd Year','3rd Year','4th Year','5th Year'];
    for (let y = 1; y <= years; y++) {
        const opt = document.createElement('option');
        opt.value = y; opt.textContent = labels[y-1] || (y + 'th Year');
        if (selectedYear && String(y) === String(selectedYear)) opt.selected = true;
        sel.appendChild(opt);
    }
    updateSectionYear();
}
// Section is stored as year level + letter ("2A"); the form only asks for the letter.
function updateSectionYear() {
    document.getElementById('section_year').textContent = document.getElementById('year_level').value || '–';
}
document.getElementById('year_level').addEventListener('change', updateSectionYear);
document.getElementById('section_letter').addEventListener('input', (e) => {
    e.target.value = e.target.value.toUpperCase();
});
function onInstitutionChange(selectedDeptId, selectedProgramId, selectedYear) {
    populateDepartments(document.getElementById('institution_id').value, selectedDeptId);
    document.getElementById('program_id').innerHTML = '<option value="">Select department first</option>';
    document.getElementById('year_level').innerHTML = '<option value="">Select program first</option>';
    updateSectionYear();
    if (selectedDeptId) onDepartmentChange(selectedProgramId, selectedYear);
}
function onDepartmentChange(selectedProgramId, selectedYear) {
    populatePrograms(document.getElementById('department_id').value, selectedProgramId);
    document.getElementById('year_level').innerHTML = '<option value="">Select program first</option>';
    updateSectionYear();
    if (selectedProgramId) onProgramChange(selectedYear);
}
function onProgramChange(selectedYear) {
    const sel = document.getElementById('program_id');
    const opt = sel.options[sel.selectedIndex];
    populateYearLevels(opt ? opt.dataset.duration : 4, selectedYear);
}

function openAddModal() {
    document.getElementById('studentForm').reset();
    document.getElementById('student_id').value = '';
    document.getElementById('department_id').innerHTML = '<option value="">Select institution first</option>';
    document.getElementById('program_id').innerHTML = '<option value="">Select department first</option>';
    document.getElementById('year_level').innerHTML = '<option value="">Select program first</option>';
    updateSectionYear();
    document.getElementById('studentModalTitle').textContent = 'Add Student';
    document.getElementById('passwordLabel').textContent = 'Password *';
    document.getElementById('password').required = true;
    openModal('studentModal');
}
// Convenience: auto-fill password with the student number as it's typed (new students only)
document.getElementById('student_number').addEventListener('input', (e) => {
    if (!document.getElementById('student_id').value) {
        document.getElementById('password').value = e.target.value;
    }
});
function openEditModal(s) {
    document.getElementById('studentForm').reset();
    document.getElementById('student_id').value = s.student_id;
    document.getElementById('student_number').value = s.student_number;
    document.getElementById('full_name').value = s.full_name;
    document.getElementById('institution_id').value = s.institution_id || '';
    onInstitutionChange(s.department_id, s.program_id, s.year_level);
    // Drop the leading year number from a stored "2A" so only the letter is edited.
    document.getElementById('section_letter').value = (s.section || '').replace(/^\s*\d+\s*-?\s*/, '');
    updateSectionYear();
    document.getElementById('student_type').value = s.student_type || 'regular';
    document.getElementById('email').value = s.email;
    document.getElementById('contact_number').value = s.contact_number || '';
    document.getElementById('username').value = s.username;
    document.getElementById('status').value = s.status;
    document.getElementById('studentModalTitle').textContent = 'Edit Student';
    document.getElementById('passwordLabel').textContent = 'Password';
    document.getElementById('password').required = false;
    openModal('studentModal');
}

document.getElementById('studentForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('studentSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving...';
    const data = Object.fromEntries(new FormData(e.target));
    data.action = data.student_id ? 'update' : 'create';
    try {
        const res = await ajaxPost('ajax_students.php', data);
        if (res.success) {
            showToast('success', res.message);
            closeModal('studentModal');
            setTimeout(() => location.reload(), 700);
        } else {
            showToast('error', res.message);
        }
    } catch (err) {
        showToast('error', 'Something went wrong. Please try again.');
    }
    btn.disabled = false; btn.innerHTML = 'Save Student';
});

async function deleteStudent(id) {
    if (!confirmDelete('Delete this student? This cannot be undone.')) return;
    const res = await ajaxPost('ajax_students.php', { action: 'delete', student_id: id });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); }
    else showToast('error', res.message);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
