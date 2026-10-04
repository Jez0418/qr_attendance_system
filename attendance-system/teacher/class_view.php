<?php
/**
 * teacher/class_view.php
 * The per-class hub: subject information, pending enrollment
 * requests for THIS class, the enrolled student roster, and a
 * search box to enroll a student directly. Reached by clicking a
 * subject in "My Assigned Subjects".
 *
 * Ownership is re-verified server-side — a teacher cannot view
 * another teacher's class by changing ?id= in the URL.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');

$teacherId = $_SESSION['profile_id'];
$classId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('
    SELECT ts.*, sub.subject_code, sub.subject_name, sub.units, lab.lab_name, lab.location,
        pr.program_code, pr.program_name
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    LEFT JOIN programs pr ON pr.program_id = ts.program_id
    WHERE ts.teacher_subject_id = ? AND ts.teacher_id = ?
');
$stmt->execute([$classId, $teacherId]);
$class = $stmt->fetch();

if (!$class) {
    http_response_code(403);
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="empty-state"><i class="fa-solid fa-lock"></i><p>You do not have access to this class, or it does not exist.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = $class['subject_code'];

// Pending requests for THIS class only
$requests = $pdo->prepare('
    SELECT r.*, s.full_name AS student_name, s.student_number, pr.program_code, s.year_level
    FROM enrollment_requests r
    JOIN students s ON s.student_id = r.student_id
    LEFT JOIN programs pr ON pr.program_id = s.program_id
    WHERE r.teacher_subject_id = ? AND r.status = "pending"
    ORDER BY r.requested_at ASC
');
$requests->execute([$classId]);
$requests = $requests->fetchAll();

// Enrolled roster for THIS class only
$roster = $pdo->prepare('
    SELECT s.student_id, s.student_number, s.full_name, s.year_level, pr.program_code, e.enrollment_id, e.enrolled_at,
        (SELECT COUNT(*) FROM attendance_records ar JOIN attendance_sessions ses ON ses.session_id = ar.session_id
            WHERE ses.teacher_subject_id = ? AND ar.student_id = s.student_id) AS attended_count
    FROM enrollments e
    JOIN students s ON s.student_id = e.student_id
    LEFT JOIN programs pr ON pr.program_id = s.program_id
    WHERE e.teacher_subject_id = ? AND e.status = "enrolled"
    ORDER BY s.full_name
');
$roster->execute([$classId, $classId]);
$roster = $roster->fetchAll();

$enrolledCount = count($roster);

require_once __DIR__ . '/../includes/header.php';
?>

<a href="subjects.php" class="text-muted" style="font-size:13px;display:inline-block;margin-bottom:14px"><i class="fa-solid fa-arrow-left"></i> Back to My Assigned Subjects</a>

<div class="card">
    <div class="card-header"><h3>Subject Information</h3></div>
    <div class="card-body">
        <div class="grid-3" style="gap:14px">
            <div><div class="text-muted" style="font-size:12px">Subject Code</div><div style="font-weight:700"><?php echo e($class['subject_code']); ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Subject Name</div><div style="font-weight:700"><?php echo e($class['subject_name']); ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Course</div><div style="font-weight:700"><?php echo e($class['program_code'] ?? 'Any'); ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Year Level</div><div style="font-weight:700"><?php echo $class['year_level'] ? 'Year ' . e($class['year_level']) : 'Any'; ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Section</div><div style="font-weight:700"><?php echo e($class['section']); ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Laboratory Room</div><div style="font-weight:700"><?php echo e($class['lab_name']); ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Day(s)</div><div style="font-weight:700"><?php echo e($class['schedule_day']); ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Start – End Time</div><div style="font-weight:700"><?php echo format_time($class['start_time']); ?> – <?php echo format_time($class['end_time']); ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Enrollment</div><div style="font-weight:700"><?php echo $enrolledCount; ?>/<?php echo (int) $class['max_students']; ?> students</div></div>
        </div>
        <span class="badge badge-<?php echo $class['status'] === 'active' ? 'active' : 'inactive'; ?>" style="margin-top:14px;display:inline-block"><?php echo ucfirst($class['status']); ?></span>
    </div>
</div>

<?php if (!empty($requests)): ?>
<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>Pending Enrollment Requests (<?php echo count($requests); ?>)</h3></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Student ID</th><th>Course/Year</th><th>Requested</th><th>Remarks</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td><?php echo e($r['student_name']); ?></td>
                    <td><?php echo e($r['student_number']); ?></td>
                    <td><?php echo e($r['program_code'] ?? '—'); ?> / Yr <?php echo e($r['year_level']); ?></td>
                    <td><?php echo format_datetime($r['requested_at']); ?></td>
                    <td class="text-muted" style="max-width:200px"><?php echo e($r['remarks'] ?: '—'); ?></td>
                    <td>
                        <button class="btn btn-success btn-sm" onclick="approveRequest(<?php echo $r['request_id']; ?>)"><i class="fa-solid fa-check"></i></button>
                        <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?php echo $r['request_id']; ?>)"><i class="fa-solid fa-xmark"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3>Enrolled Students (<?php echo $enrolledCount; ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openEnrollModal()"><i class="fa-solid fa-user-plus"></i> Enroll Student</button>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>Attended</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($roster)): ?>
                <tr><td colspan="6" class="text-center text-muted">No students enrolled in this class yet.</td></tr>
            <?php else: foreach ($roster as $s): ?>
                <tr>
                    <td><?php echo e($s['student_number']); ?></td>
                    <td><?php echo e($s['full_name']); ?></td>
                    <td><?php echo e($s['program_code'] ?? '—'); ?></td>
                    <td><?php echo e($s['year_level']); ?></td>
                    <td><?php echo (int) $s['attended_count']; ?>x</td>
                    <td>
                        <a href="history.php?class=<?php echo $classId; ?>&search=<?php echo urlencode($s['student_number']); ?>" class="btn btn-outline btn-sm" title="View Attendance"><i class="fa-solid fa-clock-rotate-left"></i></a>
                        <button class="btn btn-danger btn-sm" title="Unenroll" onclick="unenroll(<?php echo $s['student_id']; ?>, '<?php echo e(addslashes($s['full_name'])); ?>')"><i class="fa-solid fa-user-minus"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================== ENROLL STUDENT MODAL ===================== -->
<div class="modal-backdrop" id="enrollModal">
    <div class="modal">
        <div class="modal-header"><h3>Enroll Student</h3><button class="modal-close" onclick="closeModal('enrollModal')">&times;</button></div>
        <div class="modal-body">
            <div class="search-box" style="max-width:100%;margin-bottom:12px">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="enrollSearchInput" class="form-control" placeholder="Search by student ID or name...">
            </div>
            <div id="enrollSearchResults"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('enrollModal')">Close</button></div>
    </div>
</div>

<!-- ===================== REJECT REASON MODAL ===================== -->
<div class="modal-backdrop" id="rejectModal">
    <div class="modal">
        <div class="modal-header"><h3>Reject Enrollment Request</h3><button class="modal-close" onclick="closeModal('rejectModal')">&times;</button></div>
        <div class="modal-body">
            <label style="font-size:13px;font-weight:600;color:var(--slate-700);display:block;margin-bottom:6px">Rejection Reason (required)</label>
            <textarea id="rejectReasonInput" class="form-control" rows="3" placeholder="e.g. The section is already full."></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="submitReject()">Reject Request</button>
        </div>
    </div>
</div>

<script>
const CLASS_ID = <?php echo (int) $classId; ?>;
let rejectingRequestId = null;

function openEnrollModal() {
    document.getElementById('enrollSearchInput').value = '';
    document.getElementById('enrollSearchResults').innerHTML = '<p class="text-muted" style="font-size:13px">Type at least 2 characters to search.</p>';
    openModal('enrollModal');
    document.getElementById('enrollSearchInput').focus();
}

document.getElementById('enrollSearchInput').addEventListener('input', debounce(async (e) => {
    const q = e.target.value.trim();
    const resultsEl = document.getElementById('enrollSearchResults');
    if (q.length < 2) { resultsEl.innerHTML = '<p class="text-muted" style="font-size:13px">Type at least 2 characters to search.</p>'; return; }
    const res = await ajaxGet(`ajax_search_students.php?class_id=${CLASS_ID}&q=${encodeURIComponent(q)}`);
    if (!res.success) { resultsEl.innerHTML = '<p class="text-muted">Search failed.</p>'; return; }
    if (res.students.length === 0) { resultsEl.innerHTML = '<p class="text-muted" style="font-size:13px">No students found.</p>'; return; }
    resultsEl.innerHTML = res.students.map(s => {
        const already = s.enrollment_status === 'enrolled';
        return `<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--slate-100)">
            <div><strong>${s.full_name}</strong><div class="text-muted" style="font-size:12px">${s.student_number} · ${s.program_code || '—'} · Yr ${s.year_level}</div></div>
            ${already
                ? '<span class="badge badge-active">Enrolled</span>'
                : `<button class="btn btn-primary btn-sm" onclick="enrollStudent(${s.student_id}, '${s.full_name.replace(/'/g, "\\'")}')">Enroll</button>`}
        </div>`;
    }).join('');
}, 300));

async function enrollStudent(studentId, name) {
    if (!confirm(`Enroll ${name} in this class?`)) return;
    const res = await ajaxPost('ajax_enrollment.php', { action: 'enroll', student_id: studentId, teacher_subject_id: CLASS_ID });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}

async function unenroll(studentId, name) {
    if (!confirmDelete(`Remove ${name} from this class?`)) return;
    const res = await ajaxPost('ajax_enrollment.php', { action: 'unenroll', student_id: studentId, teacher_subject_id: CLASS_ID });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}

async function approveRequest(requestId) {
    if (!confirm('Approve this enrollment request? The student will be enrolled immediately.')) return;
    const res = await ajaxPost('ajax_requests.php', { action: 'approve', request_id: requestId });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}

function openRejectModal(requestId) {
    rejectingRequestId = requestId;
    document.getElementById('rejectReasonInput').value = '';
    openModal('rejectModal');
}

async function submitReject() {
    const reason = document.getElementById('rejectReasonInput').value.trim();
    if (!reason) { showToast('error', 'Please provide a rejection reason.'); return; }
    const res = await ajaxPost('ajax_requests.php', { action: 'reject', request_id: rejectingRequestId, rejection_reason: reason });
    if (res.success) { showToast('success', res.message); closeModal('rejectModal'); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
