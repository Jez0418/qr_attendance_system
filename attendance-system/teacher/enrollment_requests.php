<?php
/**
 * teacher/enrollment_requests.php
 * All enrollment requests for classes owned by the logged-in teacher,
 * across every subject they teach. Approve/Reject actions here call
 * ajax_requests.php, which re-verifies ownership server-side.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
$pageTitle = 'Enrollment Requests';

$teacherId = $_SESSION['profile_id'];

$statusFilter = clean($_GET['status'] ?? 'pending');
$where = ['ts.teacher_id = ?'];
$params = [$teacherId];
if ($statusFilter !== '' && $statusFilter !== 'all') {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT r.*, s.full_name AS student_name, s.student_number, pr.program_code, s.year_level,
        sub.subject_code, sub.subject_name, ts.section
    FROM enrollment_requests r
    JOIN students s ON s.student_id = r.student_id
    LEFT JOIN programs pr ON pr.program_id = s.program_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = r.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    $whereSql
    ORDER BY r.requested_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Enrollment Requests</h3>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <select name="status" class="form-control" style="max-width:200px" onchange="this.form.submit()">
                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
            </select>
        </form>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Student ID</th><th>Subject</th><th>Course/Year</th><th>Date Requested</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No <?php echo $statusFilter !== 'all' ? e($statusFilter) . ' ' : ''; ?>requests.</td></tr>
                <?php else: foreach ($requests as $r): ?>
                    <tr>
                        <td><?php echo e($r['student_name']); ?></td>
                        <td><?php echo e($r['student_number']); ?></td>
                        <td><?php echo e($r['subject_code'] . ' - ' . $r['subject_name']); ?><div class="text-muted" style="font-size:11px"><?php echo e($r['section']); ?></div></td>
                        <td><?php echo e($r['program_code'] ?? '—'); ?> / Yr <?php echo e($r['year_level']); ?></td>
                        <td><?php echo format_datetime($r['requested_at']); ?></td>
                        <td>
                            <?php if ($r['status'] === 'pending'): ?><span class="badge badge-late">Pending</span>
                            <?php elseif ($r['status'] === 'approved'): ?><span class="badge badge-active">Approved</span>
                            <?php else: ?><span class="badge badge-absent">Rejected</span><?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick='viewRequest(<?php echo json_encode($r); ?>)'><i class="fa-solid fa-eye"></i> View</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===================== REQUEST DETAIL MODAL ===================== -->
<div class="modal-backdrop" id="viewModal">
    <div class="modal">
        <div class="modal-header"><h3>Enrollment Request</h3><button class="modal-close" onclick="closeModal('viewModal')">&times;</button></div>
        <div class="modal-body">
            <h4 style="margin:0 0 8px;font-size:13px;color:var(--slate-500);text-transform:uppercase">Student</h4>
            <p id="v_student" style="margin:0 0 4px"></p>
            <p id="v_student_meta" class="text-muted" style="margin:0 0 16px;font-size:13px"></p>

            <h4 style="margin:0 0 8px;font-size:13px;color:var(--slate-500);text-transform:uppercase">Subject</h4>
            <p id="v_subject" style="margin:0 0 16px"></p>

            <h4 style="margin:0 0 8px;font-size:13px;color:var(--slate-500);text-transform:uppercase">Request Info</h4>
            <p style="margin:0">Requested: <span id="v_date"></span></p>
            <p style="margin:6px 0 0">Remarks: <span id="v_remarks" class="text-muted"></span></p>
            <p style="margin:6px 0 0">Status: <span id="v_status"></span></p>
            <div id="v_reviewed_wrap" style="display:none;margin-top:6px">Reviewed by: <span id="v_reviewed_by" class="text-muted"></span></div>
            <div id="v_rejection_wrap" style="display:none;margin-top:6px">Rejection reason: <span id="v_rejection" class="text-muted"></span></div>

            <div id="v_reject_form" style="margin-top:16px;display:none">
                <label style="font-size:13px;font-weight:600;color:var(--slate-700);display:block;margin-bottom:6px">Rejection Reason (required)</label>
                <textarea id="v_reject_reason" class="form-control" rows="2" placeholder="e.g. The section is already full."></textarea>
            </div>
        </div>
        <div class="modal-footer" id="v_footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
            <button type="button" class="btn btn-danger" id="v_reject_btn" onclick="confirmReject()">Reject Request</button>
            <button type="button" class="btn btn-success" id="v_approve_btn" onclick="approveRequest()">Approve Request</button>
        </div>
    </div>
</div>

<script>
let currentRequestId = null;

function viewRequest(r) {
    currentRequestId = r.request_id;
    document.getElementById('v_student').textContent = r.student_name;
    document.getElementById('v_student_meta').textContent = r.student_number + ' · ' + (r.program_code || '—') + ' · Year ' + r.year_level;
    document.getElementById('v_subject').textContent = r.subject_code + ' - ' + r.subject_name + ' (' + r.section + ')';
    document.getElementById('v_date').textContent = r.requested_at;
    document.getElementById('v_remarks').textContent = r.remarks || 'None provided';

    const statusEl = document.getElementById('v_status');
    statusEl.innerHTML = r.status === 'pending' ? '<span class="badge badge-late">Pending</span>'
        : r.status === 'approved' ? '<span class="badge badge-active">Approved</span>'
        : '<span class="badge badge-absent">Rejected</span>';

    const rejWrap = document.getElementById('v_rejection_wrap');
    if (r.status === 'rejected' && r.rejection_reason) {
        rejWrap.style.display = 'block';
        document.getElementById('v_rejection').textContent = r.rejection_reason;
    } else {
        rejWrap.style.display = 'none';
    }

    const reviewedWrap = document.getElementById('v_reviewed_wrap');
    if (r.status !== 'pending' && r.reviewed_by_role) {
        reviewedWrap.style.display = 'block';
        document.getElementById('v_reviewed_by').textContent = r.reviewed_by_role === 'admin' ? 'Administrator' : 'You';
    } else {
        reviewedWrap.style.display = 'none';
    }

    const isPending = r.status === 'pending';
    document.getElementById('v_approve_btn').style.display = isPending ? 'inline-flex' : 'none';
    document.getElementById('v_reject_btn').style.display = isPending ? 'inline-flex' : 'none';
    document.getElementById('v_reject_btn').textContent = 'Reject Request';
    document.getElementById('v_reject_btn').onclick = confirmReject;
    document.getElementById('v_reject_form').style.display = 'none';
    document.getElementById('v_reject_reason').value = '';

    openModal('viewModal');
}

function confirmReject() {
    document.getElementById('v_reject_form').style.display = 'block';
    document.getElementById('v_reject_btn').textContent = 'Confirm Rejection';
    document.getElementById('v_reject_btn').onclick = submitReject;
}

async function submitReject() {
    const reason = document.getElementById('v_reject_reason').value.trim();
    if (!reason) { showToast('error', 'Please provide a rejection reason.'); return; }
    const res = await ajaxPost('ajax_requests.php', { action: 'reject', request_id: currentRequestId, rejection_reason: reason });
    if (res.success) { showToast('success', res.message); closeModal('viewModal'); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}

async function approveRequest() {
    if (!confirm('Approve this enrollment request? The student will be enrolled immediately.')) return;
    const res = await ajaxPost('ajax_requests.php', { action: 'approve', request_id: currentRequestId });
    if (res.success) { showToast('success', res.message); closeModal('viewModal'); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
