<?php
/**
 * admin/assignments.php
 * Defines a class (teacher_subjects row): who teaches what, to which
 * class (institution/program/year/section), where (laboratory), and
 * its recurring weekly schedule (class_schedules: the checked weekdays
 * at one start/end time) — one assignment for the whole term, never one
 * per meeting. Students get enrolled into it.
 *
 * The admin only enables/disables an assignment. Whether a class is in
 * session right now (Status) and its Next Class are computed from the
 * schedule by includes/schedule.php, never set by hand.
 *
 * Institution determines which programs are selectable (MCNP programs
 * only show for MCNP, ISAP programs only show for ISAP) via a client-side
 * cascade backed by data this page embeds; the server independently
 * re-validates the combination on save.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('admin');
$pageTitle = 'Class Assignments';

$search = clean($_GET['search'] ?? '');
$filterProgram = (int) ($_GET['program_id'] ?? 0);
$filterTeacher = (int) ($_GET['teacher_id'] ?? 0);
$filterLab     = (int) ($_GET['lab_id'] ?? 0);

$conds = []; $params = [];
if ($search !== '') {
    $conds[] = '(t.full_name LIKE ? OR sub.subject_name LIKE ? OR ts.section LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if ($filterProgram) { $conds[] = 'ts.program_id = ?'; $params[] = $filterProgram; }
if ($filterTeacher) { $conds[] = 'ts.teacher_id = ?'; $params[] = $filterTeacher; }
if ($filterLab)     { $conds[] = 'ts.lab_id = ?';     $params[] = $filterLab; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$isFiltered = (bool) $conds;

$allRows = (int) $pdo->query('SELECT COUNT(*) FROM teacher_subjects')->fetchColumn();
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
        pr.program_code, inst.institution_code,
        (SELECT COUNT(*) FROM enrollments en WHERE en.teacher_subject_id = ts.teacher_subject_id AND en.status = 'enrolled') AS enrolled_count
    FROM teacher_subjects ts
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    LEFT JOIN programs pr ON pr.program_id = ts.program_id
    LEFT JOIN institutions inst ON inst.institution_id = COALESCE(ts.institution_id, pr.institution_id)
    $where
    ORDER BY ts.created_at DESC, ts.teacher_subject_id DESC
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Weekly rules, schedule label, computed status and next meeting for this page's classes
$now = schedule_now();
$summaries = get_class_schedule_summaries($pdo, array_column($assignments, 'teacher_subject_id'),
    array_column(array_map(fn($a) => [(int) $a['teacher_subject_id'], $a['status'] === 'active'], $assignments), 1, 0), $now);

$teachers = $pdo->query('SELECT teacher_id, full_name FROM teachers ORDER BY full_name')->fetchAll();
$subjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM subjects WHERE status="active" ORDER BY subject_code')->fetchAll();
$labs = $pdo->query('SELECT lab_id, lab_name FROM laboratories WHERE status="active" ORDER BY lab_name')->fetchAll();
$institutions = $pdo->query('SELECT institution_id, institution_code, institution_name FROM institutions WHERE status="active" ORDER BY institution_name')->fetchAll();
$departments = $pdo->query('SELECT department_id, institution_id, department_name FROM departments WHERE status="active" ORDER BY department_name')->fetchAll();
$programs = $pdo->query('SELECT program_id, institution_id, department_id, program_code, program_name, duration_years FROM programs WHERE status="active" ORDER BY program_code')->fetchAll();
// Filter dropdowns list everything a class can point at, including inactive labs.
$filterLabs = $pdo->query('SELECT lab_id, lab_name FROM laboratories ORDER BY lab_name')->fetchAll();

/** "Mon, Wed · 3:00-5:00 PM"; slots at different times are joined with "; ". Ended rules are left out. */
function assignment_schedule_text(array $rules, DateTimeImmutable $now): string {
    $today = $now->format('Y-m-d');
    $groups = [];
    foreach ($rules as $r) {
        if (!empty($r['effective_end_date']) && $r['effective_end_date'] < $today) continue;
        $groups[schedule_time($r['start_time']) . '|' . schedule_time($r['end_time'])][(int) $r['day_of_week']] = true;
    }
    foreach ($groups as &$days) ksort($days);
    unset($days);
    uasort($groups, fn($x, $y) => array_key_first($x) <=> array_key_first($y)); // earliest weekday first
    $parts = [];
    foreach ($groups as $range => $days) {
        [$start, $end] = explode('|', $range);
        $names = array_map(fn($d) => substr(SCHEDULE_DAYS[$d], 0, 3), array_keys($days));
        $parts[] = implode(', ', $names) . ' · ' . format_time_range($start, $end);
    }
    return implode('; ', $parts);
}

/** "Now · until 5:00 PM", "Today · 3:00 PM", "Tomorrow · 3:00 PM", "Wed, Oct 7 · 3:00 PM" or "—". */
function assignment_next_text(?array $occ, DateTimeImmutable $now): string {
    if (!$occ) return '—';
    $tz = schedule_tz();
    $start = new DateTimeImmutable($occ['starts_at'], $tz);
    if (get_occurrence_status($occ, $now) === OCCURRENCE_ACTIVE) {
        return 'Now · until ' . (new DateTimeImmutable($occ['ends_at'], $tz))->format('g:i A');
    }
    $day = match ($occ['date']) {
        $now->format('Y-m-d') => 'Today',
        $now->modify('+1 day')->format('Y-m-d') => 'Tomorrow',
        default => $start->format('D, M j'),
    };
    return $day . ' · ' . $start->format('g:i A');
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Class Assignments (<?php echo $totalRows; ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> New Assignment</button>
    </div>
    <div class="card-body">
        <form method="GET" class="list-filters">
            <div class="search-row">
                <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" class="form-control" name="search" placeholder="Search teacher, subject, section..." aria-label="Search teacher, subject or section" value="<?php echo e($search); ?>"></div>
                <button class="btn btn-outline" type="submit">Search</button>
                <?php if ($isFiltered): ?><a href="assignments.php" class="btn btn-outline">Reset</a><?php endif; ?>
            </div>
            <div class="filter-row">
                <select name="program_id" class="form-control" aria-label="Filter by program" onchange="this.form.submit()">
                    <option value="">All programs</option>
                    <?php foreach ($programs as $pr): ?><option value="<?php echo $pr['program_id']; ?>" <?php echo $filterProgram === (int) $pr['program_id'] ? 'selected' : ''; ?>><?php echo e($pr['program_code']); ?></option><?php endforeach; ?>
                </select>
                <select name="teacher_id" class="form-control" aria-label="Filter by teacher" onchange="this.form.submit()">
                    <option value="">All teachers</option>
                    <?php foreach ($teachers as $t): ?><option value="<?php echo $t['teacher_id']; ?>" <?php echo $filterTeacher === (int) $t['teacher_id'] ? 'selected' : ''; ?>><?php echo e($t['full_name']); ?></option><?php endforeach; ?>
                </select>
                <select name="lab_id" class="form-control" aria-label="Filter by laboratory" onchange="this.form.submit()">
                    <option value="">All laboratories</option>
                    <?php foreach ($filterLabs as $l): ?><option value="<?php echo $l['lab_id']; ?>" <?php echo $filterLab === (int) $l['lab_id'] ? 'selected' : ''; ?>><?php echo e($l['lab_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
        </form>
        <div class="table-wrapper">
            <table class="data-table assignments-table">
                <thead><tr><th>Teacher</th><th>Subject</th><th>Program</th><th>Year/Section</th><th>Laboratory</th><th>Schedule</th><th>Next Class</th><th>Enrolled</th><th>Next class status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($assignments)): ?>
                    <tr><td colspan="10" class="text-center text-muted"><?php echo $isFiltered ? 'No class assignments match these filters.' : 'No class assignments yet.'; ?></td></tr>
                <?php else: foreach ($assignments as $a):
                    $id = (int) $a['teacher_subject_id'];
                    $sum = $summaries[$id];
                    $rules = $sum['rules'];
                    [$statusLabel, $statusClass] = $sum['badge'];
                    $next = $sum['next'];
                    $scheduleLabel = assignment_schedule_text($rules, $now);
                    $a['rules'] = $rules;
                ?>
                    <tr>
                        <td class="nowrap"><?php echo e($a['teacher_name']); ?></td>
                        <td class="col-subject"><?php echo e($a['subject_code'] . ' - ' . $a['subject_name']); ?></td>
                        <td class="nowrap"><?php echo e($a['program_code'] ?? 'Any'); ?><div class="cell-sub"><?php echo e($a['institution_code'] ?? '—'); ?></div></td>
                        <td class="nowrap"><?php echo $a['year_level'] ? 'Yr ' . e($a['year_level']) : 'Any'; ?> / <?php echo e($a['section']); ?></td>
                        <td class="nowrap"><?php echo e($a['lab_name']); ?></td>
                        <td class="nowrap"><?php echo $scheduleLabel !== '' ? e($scheduleLabel) : '<span class="text-muted">Not set</span>'; ?></td>
                        <td class="nowrap">
                            <?php echo e(assignment_next_text($next, $now)); ?>
                            <?php if ($next && $next['is_rescheduled']): ?>
                                <div class="text-muted" style="font-size:11px">Rescheduled<?php echo $next['lab_id'] !== (int) $a['lab_id'] ? ' · ' . e($next['lab_name']) : ''; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?php echo (int) $a['enrolled_count']; ?>/<?php echo (int) $a['max_students']; ?></td>
                        <td><span class="badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                        <td>
                            <div style="display:flex;gap:8px">
                                <button class="btn btn-outline btn-sm" style="width:40px;height:40px;padding:0" title="Edit assignment" aria-label="Edit assignment" onclick="openEditModal(<?php echo e(json_encode($a)); ?>)"><i class="fa-solid fa-pen"></i></button>
                                <button class="btn btn-danger btn-sm" style="width:40px;height:40px;padding:0" title="Delete assignment" aria-label="Delete assignment" onclick="deleteAssignment(<?php echo $id; ?>)"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span class="table-count">Showing <?php echo count($assignments); ?> of <?php echo $totalRows; ?><?php echo $isFiltered ? ' (filtered from ' . $allRows . ')' : ''; ?></span>
            <?php render_pagination($p['page'], $p['totalPages']); ?>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="assignModal">
    <div class="modal modal-lg modal-sticky">
        <div class="modal-header"><h3 id="assignModalTitle">New Assignment</h3><button class="modal-close" onclick="closeModal('assignModal')">&times;</button></div>
        <form id="assignForm" class="assign-form" novalidate>
            <div class="modal-body">
                <input type="hidden" name="teacher_subject_id" id="teacher_subject_id">

                <h4 class="form-section-title form-section-first">Academic Information</h4>
                <div class="form-row">
                    <div class="form-group"><label for="institution_id">Institution *</label>
                        <select name="institution_id" id="institution_id" class="form-control" required onchange="onInstitutionChange()">
                            <option value="">Select institution</option>
                            <?php foreach ($institutions as $i): ?><option value="<?php echo $i['institution_id']; ?>"><?php echo e($i['institution_code'] . ' - ' . $i['institution_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label for="department_id">Department *</label>
                        <select name="department_id" id="department_id" class="form-control" required disabled onchange="onDepartmentChange()">
                            <option value="">Select institution first</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="program_id">Course/Program *</label>
                        <select name="program_id" id="program_id" class="form-control" required disabled onchange="onProgramChange()">
                            <option value="">Select department first</option>
                        </select>
                    </div>
                    <div class="form-group"><label for="year_level">Year Level *</label>
                        <select name="year_level" id="year_level" class="form-control" required disabled>
                            <option value="">Select program first</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="section_letter">Section *</label>
                        <div class="input-prefix">
                            <span class="input-prefix-label" id="section_prefix" title="Year level">–</span>
                            <input type="text" name="section_letter" id="section_letter" class="form-control" placeholder="e.g. A" maxlength="5" pattern="[A-Za-z][A-Za-z0-9]*" aria-describedby="err_section_letter" required>
                        </div>
                        <p class="field-error" id="err_section_letter" hidden></p>
                    </div>
                    <div class="form-group"><label>Subject *</label>
                        <select name="subject_id" id="subject_id" class="form-control" required>
                            <option value="">Select subject</option>
                            <?php foreach ($subjects as $s): ?><option value="<?php echo $s['subject_id']; ?>"><?php echo e($s['subject_code'] . ' - ' . $s['subject_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h4 class="form-section-title">Class Information</h4>
                <div class="form-row">
                    <div class="form-group"><label>Teacher *</label>
                        <select name="teacher_id" id="teacher_id" class="form-control" required>
                            <option value="">Select teacher</option>
                            <?php foreach ($teachers as $t): ?><option value="<?php echo $t['teacher_id']; ?>"><?php echo e($t['full_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Laboratory *</label>
                        <select name="lab_id" id="lab_id" class="form-control" required>
                            <option value="">Select laboratory</option>
                            <?php foreach ($labs as $l): ?><option value="<?php echo $l['lab_id']; ?>"><?php echo e($l['lab_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Maximum Students *</label><input type="number" name="max_students" id="max_students" class="form-control" value="40" min="1" max="200" required></div>
                    <div class="form-group"><label for="enabled">Status</label>
                        <div class="toggle-row" style="justify-content:flex-start;min-height:38px">
                            <label class="toggle-switch"><input type="checkbox" id="enabled" checked aria-describedby="enabledLabel" onchange="updateEnabledLabel()"><span class="toggle-slider"></span></label>
                            <span id="enabledLabel" style="font-size:13px;font-weight:600">Enabled</span>
                        </div>
                    </div>
                </div>

                <h4 class="form-section-title">Recurring Schedule</h4>
                <div class="form-group">
                    <label>Days *</label>
                    <div class="day-picker">
                        <?php foreach (SCHEDULE_DAYS as $num => $name): ?>
                            <label><input type="checkbox" name="days" value="<?php echo $num; ?>"><span><?php echo substr($name, 0, 3); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="day-presets" role="group" aria-label="Day presets">
                        <button type="button" class="btn btn-outline btn-sm" onclick="setDays([1,2,3,4,5])">Mon-Fri</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="setDays([1,3,5])">Mon/Wed/Fri</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="setDays([2,4])">Tue/Thu</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="setDays([])">Clear</button>
                    </div>
                    <p class="field-error" id="err_days" hidden></p>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="start_time">Start Time *</label><input type="time" name="start_time" id="start_time" class="form-control" aria-describedby="err_start_time" required>
                        <p class="field-error" id="err_start_time" hidden></p></div>
                    <div class="form-group"><label for="end_time">End Time *</label><input type="time" name="end_time" id="end_time" class="form-control" aria-describedby="err_end_time" required>
                        <p class="field-error" id="err_end_time" hidden></p></div>
                </div>
                <p class="class-length" id="classLength" aria-live="polite" hidden></p>
                <div class="form-row">
                    <div class="form-group"><label for="starts_on">Starts on</label><input type="date" name="starts_on" id="starts_on" class="form-control" aria-describedby="err_starts_on">
                        <p class="field-error" id="err_starts_on" hidden></p></div>
                    <div class="form-group"><label for="ends_on">Ends on</label><input type="date" name="ends_on" id="ends_on" class="form-control" aria-describedby="err_ends_on">
                        <p class="field-error" id="err_ends_on" hidden></p></div>
                </div>
                <p class="text-muted" style="font-size:12px;margin:-8px 0 14px">Optional term dates. Leave blank for no start or end limit; no meetings are generated outside them.</p>
                <div class="alert alert-error" id="mixedTimesWarning" style="display:none"></div>
                <div class="alert alert-info" style="margin-bottom:0"><i class="fa-solid fa-circle-info"></i> The class meets every week on the checked days at this time. Single-date changes (cancellations, make-up classes) are handled as schedule exceptions, not new assignments.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="assignSubmitBtn">Save assignment</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Inline field errors for the assignment form (red text under the field). */
#assignForm .field-error{margin:6px 0 0;font-size:12.5px;font-weight:500;color:var(--error-text)}
#assignForm [aria-invalid="true"]{border-color:var(--red-600)}
</style>
<script>
const DEPARTMENTS = <?php echo json_encode($departments); ?>;
const PROGRAMS = <?php echo json_encode($programs); ?>;
const SCHEDULE_DAYS = <?php echo json_encode(SCHEDULE_DAYS); ?>;
const TODAY = <?php echo json_encode($now->format('Y-m-d')); ?>; // Asia/Manila

/** Dependent dropdowns stay disabled (greyed) until their parent has a value. */
function resetSelect(id, placeholder) {
    const sel = document.getElementById(id);
    sel.innerHTML = '<option value="">' + placeholder + '</option>';
    sel.disabled = true;
}
function populateDepartments(institutionId, selectedDeptId) {
    const sel = document.getElementById('department_id');
    sel.disabled = !institutionId;
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
    sel.disabled = !departmentId;
    sel.innerHTML = '<option value="">Select program</option>';
    PROGRAMS.filter(p => String(p.department_id) === String(departmentId)).forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.program_id; opt.textContent = p.program_code + ' - ' + p.program_name;
        opt.dataset.duration = p.duration_years;
        opt.dataset.code = p.program_code;
        if (selectedProgramId && String(p.program_id) === String(selectedProgramId)) opt.selected = true;
        sel.appendChild(opt);
    });
}
function populateYearLevels(maxYear, selectedYear) {
    const sel = document.getElementById('year_level');
    sel.innerHTML = '';
    sel.disabled = false;
    const years = Math.max(1, Math.ceil(parseFloat(maxYear) || 4));
    const labels = ['1st Year','2nd Year','3rd Year','4th Year','5th Year'];
    for (let y = 1; y <= years; y++) {
        const opt = document.createElement('option');
        opt.value = y; opt.textContent = labels[y-1] || (y + 'th Year');
        if (selectedYear && String(y) === String(selectedYear)) opt.selected = true;
        sel.appendChild(opt);
    }
    updateSectionPrefix();
}
// Section is saved as year level + letter (e.g. 3A), exactly like a student's section; the form only asks for the letter.
function updateSectionPrefix() {
    document.getElementById('section_prefix').textContent = document.getElementById('year_level').value || '–';
}
document.getElementById('year_level').addEventListener('change', updateSectionPrefix);
document.getElementById('section_letter').addEventListener('input', (e) => {
    e.target.value = e.target.value.replace(/[^a-z0-9]/gi, '').toUpperCase();
});
function onInstitutionChange(selectedDeptId, selectedProgramId, selectedYear) {
    const institutionId = document.getElementById('institution_id').value;
    if (institutionId) populateDepartments(institutionId, selectedDeptId);
    else resetSelect('department_id', 'Select institution first');
    resetSelect('program_id', 'Select department first');
    resetSelect('year_level', 'Select program first');
    updateSectionPrefix();
    if (selectedDeptId) onDepartmentChange(selectedProgramId, selectedYear);
}
function onDepartmentChange(selectedProgramId, selectedYear) {
    const departmentId = document.getElementById('department_id').value;
    if (departmentId) populatePrograms(departmentId, selectedProgramId);
    else resetSelect('program_id', 'Select department first');
    resetSelect('year_level', 'Select program first');
    updateSectionPrefix();
    if (selectedProgramId) onProgramChange(selectedYear);
}
function onProgramChange(selectedYear) {
    const sel = document.getElementById('program_id');
    const opt = sel.options[sel.selectedIndex];
    if (!sel.value) { resetSelect('year_level', 'Select program first'); updateSectionPrefix(); return; }
    populateYearLevels(opt ? opt.dataset.duration : 4, selectedYear);
}
function updateEnabledLabel() {
    const on = document.getElementById('enabled').checked;
    const label = document.getElementById('enabledLabel');
    label.textContent = on ? 'Enabled' : 'Disabled';
    label.style.color = on ? 'var(--green-600)' : 'var(--slate-500)';
}
function dayBoxes() { return Array.from(document.querySelectorAll('#assignForm input[name="days"]')); }
/** Day preset buttons: check exactly these ISO days (1 = Mon ... 7 = Sun). */
function setDays(days) {
    dayBoxes().forEach(cb => cb.checked = days.includes(Number(cb.value)));
    document.getElementById('err_days').hidden = true;
}
/** "2 hours", "1 hour 30 minutes", "45 minutes" under the time fields once both are set. */
function updateClassLength() {
    const out = document.getElementById('classLength');
    const start = document.getElementById('start_time').value;
    const end = document.getElementById('end_time').value;
    if (!start || !end) { out.hidden = true; return; }
    const toMin = t => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
    const mins = toMin(end) - toMin(start);
    if (mins <= 0) { out.hidden = true; return; }
    const h = Math.floor(mins / 60), m = mins % 60;
    const parts = [];
    if (h) parts.push(h + (h === 1 ? ' hour' : ' hours'));
    if (m) parts.push(m + (m === 1 ? ' minute' : ' minutes'));
    out.innerHTML = '<i class="fa-regular fa-clock"></i> Class length: ' + parts.join(' ');
    out.hidden = false;
}
['start_time', 'end_time'].forEach(id => document.getElementById(id).addEventListener('input', updateClassLength));

/** Fill the schedule fields from a class's weekly rules (current ones, or all of them once the term has ended). */
function fillSchedule(rules) {
    rules = rules || [];
    let current = rules.filter(r => !r.effective_end_date || r.effective_end_date >= TODAY);
    if (!current.length) current = rules;
    const days = current.map(r => String(r.day_of_week));
    dayBoxes().forEach(cb => cb.checked = days.includes(cb.value));
    document.getElementById('start_time').value = current.length ? current[0].start_time.substring(0, 5) : '';
    document.getElementById('end_time').value = current.length ? current[0].end_time.substring(0, 5) : '';
    // Term dates: shown when every slot shares them (the form saves one range for all days).
    const terms = [...new Set(current.map(r => (r.effective_start_date || '') + '|' + (r.effective_end_date || '')))];
    const [startsOn, endsOn] = terms.length === 1 ? terms[0].split('|') : ['', ''];
    document.getElementById('starts_on').value = startsOn;
    document.getElementById('ends_on').value = endsOn;
    updateClassLength();

    const warning = document.getElementById('mixedTimesWarning');
    const ranges = [...new Set(current.map(r => r.start_time.substring(0, 5) + '–' + r.end_time.substring(0, 5)))];
    if (ranges.length > 1) {
        warning.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> This class currently meets at different times on different days ('
            + current.map(r => SCHEDULE_DAYS[r.day_of_week].substring(0, 3) + ' ' + r.start_time.substring(0, 5) + '–' + r.end_time.substring(0, 5)).join(', ')
            + '). Saving applies one start and end time to every checked day.';
        warning.style.display = '';
    } else {
        warning.style.display = 'none';
    }
}

function openAddModal() {
    document.getElementById('assignForm').reset();
    document.getElementById('teacher_subject_id').value = '';
    resetSelect('department_id', 'Select institution first');
    resetSelect('program_id', 'Select department first');
    resetSelect('year_level', 'Select program first');
    document.getElementById('enabled').checked = true;
    updateEnabledLabel();
    updateSectionPrefix();
    clearFieldErrors();
    fillSchedule([]);
    document.getElementById('assignModalTitle').textContent = 'New Assignment';
    openModal('assignModal');
}
function openEditModal(a) {
    document.getElementById('assignForm').reset();
    document.getElementById('teacher_subject_id').value = a.teacher_subject_id;
    document.getElementById('institution_id').value = a.institution_id || '';
    onInstitutionChange(a.department_id, a.program_id, a.year_level);
    document.getElementById('teacher_id').value = a.teacher_id;
    document.getElementById('subject_id').value = a.subject_id;
    document.getElementById('lab_id').value = a.lab_id;
    // Drop the program prefix and year digit from a stored section ("BSIT-3A", "BSIT 3A" or "3A" -> "A").
    document.getElementById('section_letter').value = (a.section || '').replace(/^.*[- ]/, '').replace(/^\s*\d+/, '').toUpperCase();
    document.getElementById('max_students').value = a.max_students || 40;
    document.getElementById('enabled').checked = a.status === 'active';
    updateEnabledLabel();
    clearFieldErrors();
    fillSchedule(a.rules);
    document.getElementById('assignModalTitle').textContent = 'Edit Assignment';
    openModal('assignModal');
}
/* Field errors: red text under the field (ids err_<field>), mirrored by the server's "field" key. */
const FIELD_INPUT = { days: null, section_letter: 'section_letter', start_time: 'start_time', end_time: 'end_time', starts_on: 'starts_on', ends_on: 'ends_on' };
function showFieldError(field, msg) {
    const el = document.getElementById('err_' + field);
    if (!el) return false;
    el.textContent = msg; el.hidden = false;
    const input = FIELD_INPUT[field] && document.getElementById(FIELD_INPUT[field]);
    if (input) input.setAttribute('aria-invalid', 'true');
    return true;
}
function clearFieldErrors() {
    document.querySelectorAll('#assignForm .field-error').forEach(el => { el.hidden = true; el.textContent = ''; });
    document.querySelectorAll('#assignForm [aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
}
/** Same rules as the server: a day, a section letter, end after start, ends-on not before starts-on. */
function validateScheduleFields(days) {
    const errors = {};
    const start = document.getElementById('start_time').value;
    const end = document.getElementById('end_time').value;
    const startsOn = document.getElementById('starts_on').value;
    const endsOn = document.getElementById('ends_on').value;
    const letter = document.getElementById('section_letter').value.trim();
    if (!letter) errors.section_letter = 'Enter the section letter, e.g. A.';
    else if (!/^[A-Z][A-Z0-9]{0,4}$/i.test(letter)) errors.section_letter = 'Section must start with a letter, e.g. A, B or C (max 5 characters).';
    if (!days.length) errors.days = 'Select at least one day for the recurring schedule.';
    if (!start) errors.start_time = 'Enter the class start time.';
    if (!end) errors.end_time = 'Enter the class end time.';
    else if (start && end <= start) errors.end_time = 'End time must be after start time.';
    if (startsOn && endsOn && endsOn < startsOn) errors.ends_on = '"Ends on" must be on or after "Starts on".';
    return errors;
}

document.getElementById('assignForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('assignSubmitBtn');
    const days = dayBoxes().filter(cb => cb.checked).map(cb => cb.value);
    clearFieldErrors();
    const errors = validateScheduleFields(days);
    Object.entries(errors).forEach(([field, msg]) => showFieldError(field, msg));
    if (Object.keys(errors).length) {
        const first = document.getElementById('err_' + Object.keys(errors)[0]);
        if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        return;
    }
    // Remaining required selects (teacher, subject, lab...) use the browser's own messages.
    if (!form.checkValidity()) { form.reportValidity(); return; }
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving...';
    const data = Object.fromEntries(new FormData(e.target));
    data.days = days.join(',');
    data.enabled = document.getElementById('enabled').checked ? '1' : '0';
    data.action = data.teacher_subject_id ? 'update' : 'create';
    let res;
    try { res = await ajaxPost('ajax_assignments.php', data); }
    catch (err) { res = { success: false, message: err.message }; }
    if (res.success) { showToast('success', res.message); closeModal('assignModal'); setTimeout(() => location.reload(), 700); }
    else if (!(res.field && showFieldError(res.field, res.message))) showToast('error', res.message);
    btn.disabled = false; btn.innerHTML = 'Save assignment';
});
async function deleteAssignment(id) {
    if (!confirmDelete('Delete this class assignment? This cannot be undone.')) return;
    const res = await ajaxPost('ajax_assignments.php', { action: 'delete', teacher_subject_id: id });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); } else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
