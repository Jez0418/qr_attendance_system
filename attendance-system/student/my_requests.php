<?php
/**
 * student/my_requests.php
 * Shows the student's own enrollment requests and their current
 * status (pending/approved/rejected), including the teacher's
 * rejection reason when applicable.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('student');
$pageTitle = 'My Enrollment Requests';

$studentId = $_SESSION['profile_id'];

$stmt = $pdo->prepare('
    SELECT r.*, sub.subject_code, sub.subject_name, t.full_name AS teacher_name, lab.lab_name
    FROM enrollment_requests r
    JOIN teacher_subjects ts ON ts.teacher_subject_id = r.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE r.student_id = ?
    ORDER BY r.requested_at DESC
');
$stmt->execute([$studentId]);
$requests = $stmt->fetchAll();
$rules = get_schedule_rules($pdo, array_column($requests, 'teacher_subject_id'));

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>My Enrollment Requests</h3></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Subject</th><th>Teacher</th><th>Laboratory</th><th>Schedule</th><th>Date Requested</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="7" class="text-center text-muted">You haven't requested any subjects yet. <a href="browse_subjects.php">Browse available subjects</a>.</td></tr>
            <?php else: foreach ($requests as $r): ?>
                <tr>
                    <td><?php echo e($r['subject_code'] . ' - ' . $r['subject_name']); ?></td>
                    <td><?php echo e($r['teacher_name']); ?></td>
                    <td><?php echo e($r['lab_name']); ?></td>
                    <td style="white-space:nowrap"><?php echo e(format_schedule_label($rules[(int) $r['teacher_subject_id']] ?? []) ?: '—'); ?></td>
                    <td><?php echo format_datetime($r['requested_at']); ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                            <span class="badge badge-late"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                        <?php elseif ($r['status'] === 'approved'): ?>
                            <span class="badge badge-active"><i class="fa-solid fa-check"></i> Approved</span>
                            <?php if ($r['reviewed_by_role']): ?><div class="text-muted" style="font-size:11px;margin-top:4px">by <?php echo $r['reviewed_by_role'] === 'admin' ? 'Administrator' : 'Teacher'; ?></div><?php endif; ?>
                        <?php elseif ($r['status'] === 'rejected'): ?>
                            <span class="badge badge-absent"><i class="fa-solid fa-xmark"></i> Rejected</span>
                            <?php if ($r['reviewed_by_role']): ?><div class="text-muted" style="font-size:11px;margin-top:4px">by <?php echo $r['reviewed_by_role'] === 'admin' ? 'Administrator' : 'Teacher'; ?></div><?php endif; ?>
                            <?php if ($r['rejection_reason']): ?><div class="text-muted" style="font-size:11.5px;margin-top:4px;max-width:220px">Reason: <?php echo e($r['rejection_reason']); ?></div><?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-inactive"><i class="fa-solid fa-ban"></i> Cancelled</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                            <button class="btn btn-outline btn-sm" onclick="cancelRequest(<?php echo $r['request_id']; ?>)"><i class="fa-solid fa-ban"></i> Cancel</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
async function cancelRequest(requestId) {
    if (!confirm('Cancel this enrollment request?')) return;
    const res = await ajaxPost('ajax_cancel_request.php', { request_id: requestId });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
