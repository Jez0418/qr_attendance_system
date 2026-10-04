<?php
/**
 * teacher/class_view.php
 * The per-class hub: subject information with the recurring schedule,
 * computed status and upcoming meetings (includes/schedule.php),
 * pending enrollment requests for THIS class and the enrolled roster.
 * Reached by clicking a subject in "My Assigned Subjects". (Teachers
 * enroll students from Student Enrollment, not here.)
 *
 * Ownership is re-verified server-side — a teacher cannot view
 * another teacher's class by changing ?id= in the URL.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
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
$now = schedule_now();
$sum = get_class_schedule_summaries($pdo, [$classId], [$classId => $class['status'] === 'active'], $now)[$classId];
// Upcoming meetings (next 4 weeks), including cancelled and rescheduled ones
$upcoming = array_values(array_filter(
    get_occurrences($pdo, $now->format('Y-m-d'), $now->modify('+28 days')->format('Y-m-d'), ['teacher_subject_id' => $classId]),
    fn($o) => get_occurrence_status($o, $now) !== OCCURRENCE_EXPIRED
));

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
            <div><div class="text-muted" style="font-size:12px">Weekly Schedule</div><div style="font-weight:700">
                <?php echo $sum['label'] !== '' ? e($sum['label']) : 'No schedule set'; ?>
                <?php foreach ($sum['rules'] as $r): if ($r['effective_start_date'] || $r['effective_end_date']): ?>
                    <div class="text-muted" style="font-size:11.5px;font-weight:500"><?php echo SCHEDULE_DAYS[$r['day_of_week']]; ?>: <?php echo $r['effective_start_date'] ? format_date($r['effective_start_date']) : '…'; ?> – <?php echo $r['effective_end_date'] ? format_date($r['effective_end_date']) : '…'; ?></div>
                <?php endif; endforeach; ?>
            </div></div>
            <div><div class="text-muted" style="font-size:12px">Next Class</div><div style="font-weight:700"><?php echo e($sum['next_label']); ?><?php if ($sum['next']): ?><div class="text-muted" style="font-size:11.5px;font-weight:500"><?php echo e($sum['next']['lab_name']); ?><?php echo $sum['next']['is_rescheduled'] ? ' · Rescheduled' : ''; ?></div><?php endif; ?></div></div>
            <div><div class="text-muted" style="font-size:12px">Enrollment</div><div style="font-weight:700"><?php echo $enrolledCount; ?>/<?php echo (int) $class['max_students']; ?> students</div></div>
        </div>
        <span class="badge <?php echo $sum['badge'][1]; ?>" style="margin-top:14px;display:inline-block"><?php echo $sum['badge'][0]; ?></span>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>Upcoming Meetings</h3><span class="text-muted" style="font-size:12px">Next 4 weeks</span></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Time</th><th>Laboratory</th><th>Teacher</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$upcoming): ?>
                <tr><td colspan="5" class="text-center text-muted">No upcoming meetings.</td></tr>
            <?php else: foreach ($upcoming as $o): $st = get_occurrence_status($o, $now); [$bl, $bc] = class_status_meta(true, [1], ['status' => $st]); ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo e((new DateTimeImmutable($o['date']))->format('D, M d')); ?></td>
                    <td style="white-space:nowrap"><?php echo e(format_time_range($o['start_time'], $o['end_time'])); ?></td>
                    <td><?php echo e($o['lab_name']); ?></td>
                    <td><?php echo e($o['teacher_name']); ?><?php echo $o['teacher_id'] !== $o['original_teacher_id'] ? ' <span class="text-muted">(substitute)</span>' : ''; ?></td>
                    <td>
                        <span class="badge <?php echo $bc; ?>"><?php echo $bl; ?></span>
                        <?php if ($o['is_rescheduled']): ?><span class="badge badge-rescheduled">Rescheduled</span><div class="text-muted" style="font-size:11px">from <?php echo e((new DateTimeImmutable($o['original_date']))->format('D, M d') . ' ' . format_time_range($o['original_start_time'], $o['original_end_time'])); ?></div><?php endif; ?>
                        <?php if ($o['exception_reason']): ?><div class="text-muted" style="font-size:11px"><?php echo e($o['exception_reason']); ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
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
