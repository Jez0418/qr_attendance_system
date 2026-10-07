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
    $baseQuery ORDER BY ar.time_in DESC LIMIT {$p['limit']} OFFSET {$p['offset']}
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
        <form method="GET" class="toolbar">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" class="form-control" placeholder="Search student..." value="<?php echo e($search); ?>"></div>
            <select name="class" class="form-control" style="max-width:220px">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?><option value="<?php echo $c['teacher_subject_id']; ?>" <?php echo $classId == $c['teacher_subject_id'] ? 'selected' : ''; ?>><?php echo e($c['subject_code']); ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control" style="max-width:160px" value="<?php echo e($dateFrom); ?>">
            <input type="date" name="date_to" class="form-control" style="max-width:160px" value="<?php echo e($dateTo); ?>">
            <button class="btn btn-outline btn-sm" type="submit">Filter</button>
            <a href="history.php" class="btn btn-outline btn-sm">Reset</a>
            <div class="toolbar-spacer"></div>
            <a class="btn btn-outline btn-sm" target="_blank" href="history.php?<?php echo e(export_query('pdf')); ?>"><i class="fa-solid fa-file-pdf"></i> Export PDF</a>
            <a class="btn btn-outline btn-sm" href="history.php?<?php echo e(export_query('excel')); ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
        </form>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Student No.</th><th>Subject</th><th>Lab</th><th>Time In</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo e($r['full_name']); ?></td>
                        <td><?php echo e($r['student_number']); ?></td>
                        <td><?php echo e($r['subject_name']); ?></td>
                        <td><?php echo e($r['lab_name']); ?></td>
                        <td><?php echo format_record_time($r); ?></td>
                        <td><span class="badge badge-<?php echo strtolower($r['status']); ?>"<?php if (!empty($r['marked_by_user_id'])): ?> title="<?php echo e('Marked by teacher: ' . $r['override_reason']); ?>"<?php endif; ?>><?php echo $r['status']; ?></span></td>
                        <td>
                            <?php if ($r['status'] === 'Absent'): ?>
                                <button type="button" class="btn btn-outline btn-sm" onclick="openOverride(<?php echo (int) $r['record_id']; ?>, <?php echo e(json_encode($r['full_name'] . ' - ' . $r['subject_name'] . ', ' . date('M d, Y', strtotime($r['time_in'])))); ?>)"><i class="fa-solid fa-check"></i> Mark present</button>
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
        <div class="modal-header"><h3>Mark present</h3><button type="button" class="modal-close" onclick="closeModal('overrideModal')">&times;</button></div>
        <div class="modal-body">
            <p id="overrideWho" style="margin-top:0;font-weight:600"></p>
            <p class="text-muted" style="font-size:13px">This changes the record from Absent to Present, tells the student, and is saved in the activity log with your name and this reason.</p>
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
let overrideRecordId = null;
function openOverride(recordId, who) {
    overrideRecordId = recordId;
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
        const res = await ajaxPost('ajax_attendance_override.php', { record_id: overrideRecordId, reason });
        if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 600); }
        else { showToast('error', res.message); btn.disabled = false; }
    } catch (err) { showToast('error', 'Something went wrong. Please try again.'); btn.disabled = false; }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
