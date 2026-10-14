<?php
/**
 * teacher/history.php
 * Attendance history across all of the teacher's classes, filterable
 * by class and date range, with pagination. ?export=pdf|excel exports
 * every row matching the current filters (includes/export.php).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/export.php';
require_role('teacher');
$pageTitle = 'Attendance History';

$teacherId = $_SESSION['profile_id'];
$classId = ctype_digit((string) ($_GET['class'] ?? '')) ? (string) (int) $_GET['class'] : '';   // a non-number would make Postgres fail
$dateFrom = valid_ymd($_GET['date_from'] ?? '');
$dateTo = valid_ymd($_GET['date_to'] ?? '');
$search = clean($_GET['search'] ?? '');

$where = ['ts.teacher_id = ?'];
$params = [$teacherId];
if ($classId !== '') { $where[] = 'ts.teacher_subject_id = ?'; $params[] = $classId; }
if ($dateFrom !== '') { $where[] = 'DATE(ar.time_in) >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(ar.time_in) <= ?'; $params[] = $dateTo; }
if ($search !== '') { $where[] = '(st.full_name LIKE ? OR st.student_number LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$baseQuery = "
    FROM attendance_records ar
    JOIN students st ON st.student_id = ar.student_id
    JOIN attendance_sessions ses ON ses.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = ses.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    $whereSql
";

if ($format = requested_export_format()) {
    $stmt = $pdo->prepare("
        SELECT ar.time_in, ar.status, ar.marked_by_user_id, st.full_name, st.student_number, sub.subject_code, sub.subject_name, ts.section, lab.lab_name
        $baseQuery ORDER BY ar.time_in DESC
    ");
    $stmt->execute($params);
    $rows = array_map(fn($r) => [$r['full_name'], $r['student_number'], $r['subject_code'] . ' - ' . $r['subject_name'], $r['section'], $r['lab_name'], format_record_time($r), $r['status']], $stmt->fetchAll());

    $classLabel = 'All Classes';
    if ($classId !== '') {
        $c = $pdo->prepare('SELECT sub.subject_code, sub.subject_name, ts.section FROM teacher_subjects ts JOIN subjects sub ON sub.subject_id = ts.subject_id WHERE ts.teacher_subject_id = ? AND ts.teacher_id = ?');
        $c->execute([$classId, $teacherId]);
        if ($c = $c->fetch()) $classLabel = $c['subject_code'] . ' - ' . $c['subject_name'] . ' (' . $c['section'] . ')';
    }
    $meta = ['Teacher' => $_SESSION['full_name'] ?? '', 'Class' => $classLabel,
             'Period' => ($dateFrom ?: 'Start') . ' to ' . ($dateTo ?: 'Today')];
    if ($search !== '') $meta['Search'] = $search;
    send_attendance_export($format, 'Attendance History', $meta,
        ['Student', 'Student No.', 'Subject', 'Section', 'Laboratory', 'Time In', 'Status'], $rows, 'attendance_history');
}

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseQuery");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 15);

$stmt = $pdo->prepare("
    SELECT ar.*, st.full_name, st.student_number, sub.subject_name, lab.lab_name
    $baseQuery ORDER BY ar.time_in DESC, ar.record_id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$classes = $pdo->prepare('SELECT ts.teacher_subject_id, sub.subject_name, sub.subject_code FROM teacher_subjects ts JOIN subjects sub ON sub.subject_id=ts.subject_id WHERE ts.teacher_id=? ORDER BY sub.subject_code');
$classes->execute([$teacherId]);
$classes = $classes->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>Attendance History (<?php echo $totalRows; ?>)</h3></div>
    <div class="card-body">
        <form method="GET" class="toolbar" data-live-filter>
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" class="form-control" placeholder="Search student..." value="<?php echo e($search); ?>"></div>
            <select name="class" class="form-control" style="max-width:220px">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?><option value="<?php echo $c['teacher_subject_id']; ?>" <?php echo $classId == $c['teacher_subject_id'] ? 'selected' : ''; ?>><?php echo e($c['subject_code']); ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control" style="max-width:160px" value="<?php echo e($dateFrom); ?>">
            <input type="date" name="date_to" class="form-control" style="max-width:160px" value="<?php echo e($dateTo); ?>">
            <a href="history.php" class="btn btn-outline btn-sm" data-live-reset<?php echo ($search !== '' || $classId !== '' || $dateFrom !== '' || $dateTo !== '') ? '' : ' hidden'; ?>>Reset</a>
            <div class="toolbar-spacer"></div>
            <span class="toolbar-actions">
                <a class="btn btn-outline btn-sm" data-live-sync target="_blank" href="history.php?<?php echo e(export_query('pdf')); ?>"><i class="fa-solid fa-file-pdf"></i> Export PDF</a>
                <a class="btn btn-outline btn-sm" data-live-sync href="history.php?<?php echo e(export_query('excel')); ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
            </span>
        </form>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Student No.</th><th>Subject</th><th>Lab</th><th>Time In</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr data-record="<?php echo (int) $r['record_id']; ?>">
                        <td><?php echo e($r['full_name']); ?></td>
                        <td><?php echo e($r['student_number']); ?></td>
                        <td><?php echo e($r['subject_name']); ?></td>
                        <td><?php echo e($r['lab_name']); ?></td>
                        <td><?php echo format_record_time($r); ?></td>
                        <td><span class="badge badge-<?php echo strtolower($r['status']); ?>"<?php if (!empty($r['marked_by_user_id'])): ?> title="<?php echo e('Marked by teacher: ' . $r['override_reason']); ?>"<?php endif; ?>><?php echo $r['status']; ?></span></td>
                        <td>
                            <?php if ($r['status'] === 'Absent'): $who = $r['full_name'] . ' - ' . $r['subject_name'] . ', ' . date('M d, Y', strtotime($r['time_in'])); ?>
                                <div class="row-menu">
                                    <button type="button" class="btn btn-outline btn-sm" aria-haspopup="menu" aria-expanded="false" onclick="toggleRowMenu(this)">Actions <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
                                    <div class="row-menu-list" role="menu" hidden>
                                        <button type="button" role="menuitem" onclick='openOverride(<?php echo (int) $r['record_id']; ?>, <?php echo js_attr_json($who); ?>, "Present")'><i class="fa-solid fa-check"></i> Mark present</button>
                                        <button type="button" role="menuitem" onclick='openOverride(<?php echo (int) $r['record_id']; ?>, <?php echo js_attr_json($who); ?>, "Excused")'><i class="fa-solid fa-file-medical"></i> Excuse</button>
                                    </div>
                                </div>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>

<div class="modal-backdrop" id="overrideModal">
    <div class="modal">
        <div class="modal-header"><h3 id="overrideTitle">Mark present</h3><button type="button" class="modal-close" onclick="closeModal('overrideModal')">&times;</button></div>
        <div class="modal-body">
            <p id="overrideWho" style="margin-top:0;font-weight:600"></p>
            <p class="text-muted" style="font-size:13px" id="overrideText"></p>
            <div class="form-group">
                <label for="overrideReason">Reason *</label>
                <textarea id="overrideReason" class="form-control" rows="3" maxlength="255" placeholder="e.g. Scanner problem, student was present in class"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px">
                <button type="button" class="btn btn-outline" onclick="closeModal('overrideModal')">Cancel</button>
                <button type="button" class="btn btn-primary" id="overrideSubmit" onclick="submitOverride()">Mark present</button>
            </div>
        </div>
    </div>
</div>
<script>
// One modal for both changes of an Absent record (includes/attendance_override.php): to Present or to Excused.
const OVERRIDE_MODES = {
    Present: {
        title: 'Mark present', button: 'Mark present',
        text: "This changes the record from Absent to Present, tells the student, and is saved in the activity log with your name and this reason. This can't be undone.",
        placeholder: 'e.g. Scanner problem, student was present in class'
    },
    Excused: {
        title: 'Excuse absence', button: 'Excuse',
        text: "This changes the record from Absent to Excused. It won't count as an absence or against the attendance rate. The student is notified, and it is saved in the activity log with your name and this reason. This can't be undone.",
        placeholder: 'e.g. Medical certificate shown'
    }
};
// The "Actions" menu of an Absent row: one open at a time, placed under its button (position:fixed, so the
// scrolling table can't clip it), closed by an outside click, Esc, scrolling or resizing.
function closeRowMenus() {
    document.querySelectorAll('.row-menu-list:not([hidden])').forEach(list => {
        list.hidden = true;
        const toggle = list.parentElement.querySelector('[aria-haspopup]');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    });
}
function toggleRowMenu(button) {
    const list = button.parentElement.querySelector('.row-menu-list');
    const wasOpen = !list.hidden;
    closeRowMenus();
    if (wasOpen) return;
    list.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    const rect = button.getBoundingClientRect();
    const height = list.offsetHeight, width = list.offsetWidth;
    const below = rect.bottom + 6;
    list.style.top = (below + height > window.innerHeight && rect.top - 6 - height > 0 ? rect.top - 6 - height : below) + 'px';
    list.style.left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)) + 'px';
    const first = list.querySelector('button');
    if (first) first.focus({ preventScroll: true });
}
document.addEventListener('click', e => { if (!e.target.closest('.row-menu')) closeRowMenus(); });
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const open = document.querySelector('.row-menu-list:not([hidden])');
    const toggle = open && open.parentElement.querySelector('[aria-haspopup]');
    closeRowMenus();
    if (toggle) toggle.focus();
});
window.addEventListener('resize', closeRowMenus);
window.addEventListener('scroll', closeRowMenus, true);

// Show the stored result in the row straight away (status badge, time text, no more Actions) instead of waiting
// for a reload; the row was changed in the database before this is called.
function showOverrideResult(recordId, status, reason) {
    const row = document.querySelector('tr[data-record="' + recordId + '"]');
    if (!row) { location.reload(); return; }
    const cells = row.children;
    const badge = document.createElement('span');
    badge.className = 'badge badge-' + String(status).toLowerCase();
    badge.textContent = status;
    badge.title = 'Marked by teacher: ' + reason;
    cells[5].replaceChildren(badge);
    const day = cells[4].textContent.split(' · ')[0];
    cells[4].textContent = day + (status === 'Excused' ? ' · excused' : ' · marked by teacher');
    cells[6].innerHTML = '<span class="text-muted">\u2014</span>';
    row.classList.add('row-updated');
}

let overrideRecordId = null;
let overrideTarget = 'Present';
function openOverride(recordId, who, target) {
    closeRowMenus();
    overrideRecordId = recordId;
    overrideTarget = OVERRIDE_MODES[target] ? target : 'Present';
    const mode = OVERRIDE_MODES[overrideTarget];
    document.getElementById('overrideTitle').textContent = mode.title;
    document.getElementById('overrideText').textContent = mode.text;
    document.getElementById('overrideReason').placeholder = mode.placeholder;
    document.getElementById('overrideSubmit').textContent = mode.button;
    document.getElementById('overrideWho').textContent = who;
    document.getElementById('overrideReason').value = '';
    openModal('overrideModal');
    document.getElementById('overrideReason').focus();
}
async function submitOverride() {
    const reason = document.getElementById('overrideReason').value.trim();
    if (reason.length < 3) { showToast('error', 'Please enter a reason for the change.'); return; }
    const btn = document.getElementById('overrideSubmit');
    btn.disabled = true;
    try {
        const target = overrideTarget;
        const res = await ajaxPost('ajax_attendance_override.php', { record_id: overrideRecordId, reason, target });
        if (res.success) {
            showToast('success', res.message);
            closeModal('overrideModal');
            btn.disabled = false;
            showOverrideResult(res.record_id, res.status, reason);
        } else { showToast('error', res.message); btn.disabled = false; }
    } catch (err) { showToast('error', 'Something went wrong. Please try again.'); btn.disabled = false; }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
