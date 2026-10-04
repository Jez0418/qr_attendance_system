<?php
/**
 * admin/assignments.php
 * Defines a class (teacher_subjects row): who teaches what, to which
 * class (institution/program/year/section), where (laboratory), and
 * its recurring weekly schedule (class_schedules) — one assignment
 * for the whole term, never one per meeting. Students get enrolled
 * into it. Institution determines which programs
 * are selectable (MCNP programs only show for MCNP, ISAP programs
 * only show for ISAP) via a client-side cascade backed by data this
 * page embeds; the server independently re-validates the combination
 * on save.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Class Assignments';

$search = clean($_GET['search'] ?? '');
$where = ''; $params = [];
if ($search !== '') {
    $where = 'WHERE (t.full_name LIKE ? OR sub.subject_name LIKE ? OR ts.section LIKE ?)';
    $params = ["%$search%", "%$search%", "%$search%"];
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM teacher_subjects ts
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    $where
");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 10);

$stmt = $pdo->prepare("
    SELECT ts.*, t.full_name AS teacher_name, sub.subject_name, sub.subject_code, lab.lab_name,
        pr.program_code, inst.institution_code, dept.department_name,
        (SELECT COUNT(*) FROM enrollments e WHERE e.teacher_subject_id = ts.teacher_subject_id AND e.status='enrolled') AS enrolled_count
    FROM teacher_subjects ts
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    LEFT JOIN programs pr ON pr.program_id = ts.program_id
    LEFT JOIN institutions inst ON inst.institution_id = ts.institution_id
    LEFT JOIN departments dept ON dept.department_id = ts.department_id
    $where
    ORDER BY ts.created_at DESC, ts.teacher_subject_id DESC
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$assignments = attach_class_schedules($pdo, $stmt->fetchAll());

$teachers = $pdo->query('SELECT teacher_id, full_name FROM teachers ORDER BY full_name')->fetchAll();
$subjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM subjects WHERE status="active" ORDER BY subject_code')->fetchAll();
$labs = $pdo->query('SELECT lab_id, lab_name FROM laboratories WHERE status="active" ORDER BY lab_name')->fetchAll();
$institutions = $pdo->query('SELECT institution_id, institution_code, institution_name FROM institutions WHERE status="active" ORDER BY institution_name')->fetchAll();
$departments = $pdo->query('SELECT department_id, institution_id, department_name FROM departments WHERE status="active" ORDER BY department_name')->fetchAll();
$programs = $pdo->query('SELECT program_id, institution_id, department_id, program_code, program_name, duration_years FROM programs WHERE status="active" ORDER BY program_code')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Class Assignments (<?php echo $totalRows; ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> New Assignment</button>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="form-control" name="search" placeholder="Search teacher, subject, section..." value="<?php echo e($search); ?>"></div>
            <button class="btn btn-outline btn-sm" type="submit">Search</button>
            <?php if ($search): ?><a href="assignments.php" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
        </form>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Teacher</th><th>Subject</th><th>Institution/Program</th><th>Year/Section</th><th>Lab</th><th>Recurring Schedule</th><th>Enrolled</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($assignments)): ?>
                    <tr><td colspan="9" class="text-center text-muted">No class assignments yet.</td></tr>
                <?php else: foreach ($assignments as $a):
                    $cState = class_schedule_status($a['schedules']);
                    $cMeta = class_status_badge($cState['status']);
                ?>
                    <tr>
                        <td><?php echo e($a['teacher_name']); ?></td>
                        <td><?php echo e($a['subject_code'] . ' - ' . $a['subject_name']); ?></td>
                        <td><?php echo e($a['institution_code'] ?? '—'); ?> / <?php echo e($a['program_code'] ?? 'Any'); ?></td>
                        <td><?php echo $a['year_level'] ? 'Yr ' . e($a['year_level']) : 'Any'; ?> / <?php echo e($a['section']); ?></td>
                        <td><?php echo e($a['lab_name']); ?></td>
                        <td>
                            <?php if ($a['schedules']): ?>
                                <?php foreach ($a['schedules'] as $slot): ?>
                                    <div style="white-space:nowrap;font-size:12.5px"><?php echo substr(SCHEDULE_DAYS[$slot['day_of_week']], 0, 3); ?> · <?php echo format_time($slot['start_time']); ?>–<?php echo format_time($slot['end_time']); ?></div>
                                <?php endforeach; ?>
                                <?php if ($a['status'] === 'active' && in_array($cState['status'], ['upcoming', 'active'], true)): ?><span class="badge <?php echo $cMeta['class']; ?>" style="margin-top:3px"><?php echo $cMeta['label']; ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) $a['enrolled_count']; ?>/<?php echo (int) $a['max_students']; ?></td>
                        <td><span class="badge badge-<?php echo $a['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick='openEditModal(<?php echo json_encode($a); ?>)'><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-danger btn-sm" onclick="deleteAssignment(<?php echo $a['teacher_subject_id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>

<div class="modal-backdrop" id="assignModal">
    <div class="modal modal-lg">
        <div class="modal-header"><h3 id="assignModalTitle">New Assignment</h3><button class="modal-close" onclick="closeModal('assignModal')">&times;</button></div>
        <form id="assignForm">
            <div class="modal-body">
                <input type="hidden" name="teacher_subject_id" id="teacher_subject_id">

                <h4 style="margin:0 0 10px;font-size:12px;color:var(--slate-500);text-transform:uppercase;letter-spacing:.03em">Academic Placement</h4>
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
                    <div class="form-group"><label>Section *</label><input type="text" name="section" id="section" class="form-control" placeholder="e.g. 3A" required></div>
                    <div class="form-group"><label>Subject *</label>
                        <select name="subject_id" id="subject_id" class="form-control" required>
                            <option value="">Select subject</option>
                            <?php foreach ($subjects as $s): ?><option value="<?php echo $s['subject_id']; ?>"><?php echo e($s['subject_code'] . ' - ' . $s['subject_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h4 style="margin:18px 0 10px;font-size:12px;color:var(--slate-500);text-transform:uppercase;letter-spacing:.03em">Class Details</h4>
                <div class="form-row">
                    <div class="form-group"><label>Teacher *</label>
                        <select name="teacher_id" id="teacher_id" class="form-control" required>
                            <option value="">Select teacher</option>
                            <?php foreach ($teachers as $t): ?><option value="<?php echo $t['teacher_id']; ?>"><?php echo e($t['full_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Laboratory/Room *</label>
                        <select name="lab_id" id="lab_id" class="form-control" required>
                            <option value="">Select laboratory</option>
                            <?php foreach ($labs as $l): ?><option value="<?php echo $l['lab_id']; ?>"><?php echo e($l['lab_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Maximum Students *</label><input type="number" name="max_students" id="max_students" class="form-control" value="40" min="1" max="200" required></div>
                    <div class="form-group"><label>Status</label>
                        <select name="status" id="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                </div>

                <h4 style="margin:18px 0 10px;font-size:12px;color:var(--slate-500);text-transform:uppercase;letter-spacing:.03em">Recurring Schedule</h4>
                <div id="scheduleRows"></div>
                <button type="button" class="btn btn-outline btn-sm" onclick="addScheduleRow()"><i class="fa-solid fa-plus"></i> Add Meeting Day</button>
                <div class="alert alert-info" style="margin:14px 0 0"><i class="fa-solid fa-circle-info"></i> The class repeats every week on these days for as long as the assignment is active. Attendance can be opened on a scheduled day, before or during that day's class time — no need to create an assignment for each meeting.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="assignSubmitBtn">Save Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
const DEPARTMENTS = <?php echo json_encode($departments); ?>;
const PROGRAMS = <?php echo json_encode($programs); ?>;
const SCHEDULE_DAYS = <?php echo json_encode(SCHEDULE_DAYS); ?>;

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
}
function onInstitutionChange(selectedDeptId, selectedProgramId, selectedYear) {
    populateDepartments(document.getElementById('institution_id').value, selectedDeptId);
    document.getElementById('program_id').innerHTML = '<option value="">Select department first</option>';
    document.getElementById('year_level').innerHTML = '<option value="">Select program first</option>';
    if (selectedDeptId) onDepartmentChange(selectedProgramId, selectedYear);
}
function onDepartmentChange(selectedProgramId, selectedYear) {
    populatePrograms(document.getElementById('department_id').value, selectedProgramId);
    document.getElementById('year_level').innerHTML = '<option value="">Select program first</option>';
    if (selectedProgramId) onProgramChange(selectedYear);
}
function onProgramChange(selectedYear) {
    const sel = document.getElementById('program_id');
    const opt = sel.options[sel.selectedIndex];
    populateYearLevels(opt ? opt.dataset.duration : 4, selectedYear);
}
function addScheduleRow(slot) {
    slot = slot || {};
    const row = document.createElement('div');
    row.className = 'schedule-row';
    let dayOptions = '<option value="">Select day</option>';
    for (const [num, name] of Object.entries(SCHEDULE_DAYS)) {
        dayOptions += '<option value="' + num + '"' + (String(slot.day_of_week) === num ? ' selected' : '') + '>' + name + '</option>';
    }
    row.innerHTML =
        '<div><label>Day *</label><select class="form-control sched-day" required>' + dayOptions + '</select></div>' +
        '<div><label>Start *</label><input type="time" class="form-control sched-start" required></div>' +
        '<div><label>End *</label><input type="time" class="form-control sched-end" required></div>' +
        '<button type="button" class="btn btn-outline btn-sm" title="Remove" onclick="removeScheduleRow(this)"><i class="fa-solid fa-xmark"></i></button>';
    row.querySelector('.sched-start').value = (slot.start_time || '').substring(0, 5);
    row.querySelector('.sched-end').value = (slot.end_time || '').substring(0, 5);
    document.getElementById('scheduleRows').appendChild(row);
}
function removeScheduleRow(btn) {
    if (document.querySelectorAll('#scheduleRows .schedule-row').length <= 1) {
        showToast('error', 'A class needs at least one meeting day.'); return;
    }
    btn.closest('.schedule-row').remove();
}
function resetScheduleRows(slots) {
    document.getElementById('scheduleRows').innerHTML = '';
    (slots && slots.length ? slots : [{}]).forEach(addScheduleRow);
}
function collectSchedules() {
    return Array.from(document.querySelectorAll('#scheduleRows .schedule-row')).map(r => ({
        day_of_week: r.querySelector('.sched-day').value,
        start_time: r.querySelector('.sched-start').value,
        end_time: r.querySelector('.sched-end').value,
    }));
}

function openAddModal() {
    document.getElementById('assignForm').reset();
    document.getElementById('teacher_subject_id').value = '';
    document.getElementById('department_id').innerHTML = '<option value="">Select institution first</option>';
    document.getElementById('program_id').innerHTML = '<option value="">Select department first</option>';
    document.getElementById('year_level').innerHTML = '<option value="">Select program first</option>';
    resetScheduleRows([]);
    document.getElementById('assignModalTitle').textContent = 'New Assignment';
    openModal('assignModal');
}
function openEditModal(a) {
    document.getElementById('teacher_subject_id').value = a.teacher_subject_id;
    document.getElementById('institution_id').value = a.institution_id || '';
    onInstitutionChange(a.department_id, a.program_id, a.year_level);
    document.getElementById('teacher_id').value = a.teacher_id;
    document.getElementById('subject_id').value = a.subject_id;
    document.getElementById('lab_id').value = a.lab_id;
    document.getElementById('section').value = a.section;
    document.getElementById('max_students').value = a.max_students || 40;
    resetScheduleRows(a.schedules);
    document.getElementById('status').value = a.status;
    document.getElementById('assignModalTitle').textContent = 'Edit Assignment';
    openModal('assignModal');
}
document.getElementById('assignForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('assignSubmitBtn');
    const schedules = collectSchedules();
    const bad = schedules.find(s => s.end_time <= s.start_time);
    if (bad) { showToast('error', 'End time must be after start time (' + SCHEDULE_DAYS[bad.day_of_week] + ').'); return; }
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving...';
    const data = Object.fromEntries(new FormData(e.target));
    data.schedules = JSON.stringify(schedules);
    data.action = data.teacher_subject_id ? 'update' : 'create';
    const res = await ajaxPost('ajax_assignments.php', data);
    if (res.success) { showToast('success', res.message); closeModal('assignModal'); setTimeout(() => location.reload(), 700); }
    else showToast('error', res.message);
    btn.disabled = false; btn.innerHTML = 'Save Assignment';
});
async function deleteAssignment(id) {
    if (!confirmDelete('Delete this class assignment? Enrollments and attendance history for it will also be removed.')) return;
    const res = await ajaxPost('ajax_assignments.php', { action: 'delete', teacher_subject_id: id });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); } else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
