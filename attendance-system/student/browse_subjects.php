<?php
/**
 * student/browse_subjects.php
 * "Available Subjects" — students browse open classes and request
 * enrollment. A request goes to PENDING until the owning teacher (or
 * an admin) approves or rejects it.
 *
 * Eligibility (re-checked again server-side on submit in
 * ajax_request_enrollment.php — this page's filtering is a UX
 * convenience, not the security boundary):
 *   - A class tied to a specific institution/program only shows to
 *     students in that same institution/program, regardless of type.
 *   - REGULAR students additionally only see classes matching their
 *     own year level and section.
 *   - IRREGULAR students see every class in their institution/program
 *     regardless of year/section, clearly labeled as such — every
 *     request still requires approval either way.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('student');
$pageTitle = 'Request Enrollment';

$studentId = $_SESSION['profile_id'];

$me = $pdo->prepare('SELECT institution_id, program_id, year_level, section, student_type FROM students WHERE student_id = ?');
$me->execute([$studentId]);
$me = $me->fetch();
$isIrregular = ($me['student_type'] ?? 'regular') === 'irregular';

// Students store their section as year + letter ("3A"); classes store it with the
// program code in front ("BSIT-3A", older rows "BSIT 3A"). Compare only the part
// after the last "-" or space. The program itself is matched separately below.
function section_key($section): string {
    return strtoupper(preg_replace('/^.*[- ]/', '', trim((string) $section)));
}
const SECTION_KEY_SQL = "UPPER(regexp_replace(TRIM(ts.section), '^.*[- ]', ''))";
$mySection = section_key($me['section'] ?? '');

$search = clean($_GET['search'] ?? '');
$where = ['ts.status = "active"'];
$params = [];

// Institution/program boundary applies to every student, regular or not —
// a class scoped to one institution/program simply isn't offered to
// students outside it.
$where[] = '(ts.institution_id IS NULL OR ts.institution_id = ?)';
$params[] = $me['institution_id'];
$where[] = '(ts.program_id IS NULL OR ts.program_id = ?)';
$params[] = $me['program_id'];

if (!$isIrregular) {
    $where[] = '(ts.year_level IS NULL OR ts.year_level = ?)';
    $params[] = $me['year_level'];
    if ($mySection !== '') {
        $where[] = '(ts.section IS NULL OR ' . SECTION_KEY_SQL . ' = ?)';
        $params[] = $mySection;
    }
}

if ($search !== '') {
    $where[] = '(sub.subject_name LIKE ? OR sub.subject_code LIKE ? OR t.full_name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT ts.*, sub.subject_code, sub.subject_name, t.full_name AS teacher_name, lab.lab_name,
        pr.program_code, pr.program_name, inst.institution_code,
        (SELECT COUNT(*) FROM enrollments e WHERE e.teacher_subject_id = ts.teacher_subject_id AND e.status='enrolled') AS enrolled_count,
        (SELECT status FROM enrollments e WHERE e.teacher_subject_id = ts.teacher_subject_id AND e.student_id = ? LIMIT 1) AS my_enrollment_status,
        (SELECT status FROM enrollment_requests r WHERE r.teacher_subject_id = ts.teacher_subject_id AND r.student_id = ? AND r.status = 'pending' LIMIT 1) AS my_pending_request
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    LEFT JOIN programs pr ON pr.program_id = ts.program_id
    LEFT JOIN institutions inst ON inst.institution_id = ts.institution_id
    $whereSql
    ORDER BY sub.subject_code
");
$stmt->execute(array_merge([$studentId, $studentId], $params));
$classes = $stmt->fetchAll();
$summaries = get_class_schedule_summaries($pdo, array_column($classes, 'teacher_subject_id'));
foreach ($classes as &$c) $c['schedule_label'] = $summaries[(int) $c['teacher_subject_id']]['label'] ?: 'No schedule set';
unset($c);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>Available Subjects</h3></div>
    <div class="card-body">
        <p class="text-muted" style="margin:0 0 12px;font-size:13px">
            Requests are reviewed by the subject's teacher, or by an administrator.
            <?php if ($isIrregular): ?>
                <span class="badge badge-late" style="margin-left:6px">Irregular Student</span>
                — you can see subjects from other year levels/sections in your program.
            <?php endif; ?>
        </p>
        <form method="GET" class="toolbar">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="form-control" name="search" placeholder="Search subject or teacher..." value="<?php echo e($search); ?>"></div>
            <button class="btn btn-outline btn-sm" type="submit">Search</button>
            <?php if ($search): ?><a href="browse_subjects.php" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="grid-3" style="margin-top:18px">
<?php if (empty($classes)): ?>
    <div class="empty-state" style="grid-column:1/-1"><i class="fa-solid fa-book"></i><p>No subjects available right now for your course/year/section.</p></div>
<?php else: foreach ($classes as $c):
    $slotsLeft = (int) $c['max_students'] - (int) $c['enrolled_count'];
    $isFull = $slotsLeft <= 0;
    $isEnrolled = $c['my_enrollment_status'] === 'enrolled';
    $isPending = !empty($c['my_pending_request']);
    $offCohort = $isIrregular && ((int) $c['year_level'] !== (int) $me['year_level'] || section_key($c['section']) !== $mySection);
?>
    <div class="card">
        <div class="card-body">
            <div class="flex-between" style="align-items:flex-start">
                <h3 style="margin:0 0 4px"><?php echo e($c['subject_code']); ?></h3>
                <?php if ($offCohort): ?><span class="badge badge-late" style="font-size:10px">Different Year/Section</span><?php endif; ?>
            </div>
            <p style="margin:0 0 12px;color:var(--slate-600)"><?php echo e($c['subject_name']); ?></p>
            <div style="font-size:13.5px;color:var(--slate-700);line-height:1.9">
                <div><i class="fa-solid fa-chalkboard-user" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($c['teacher_name']); ?></div>
                <div><i class="fa-solid fa-building-columns" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($c['institution_code'] ?? 'Any'); ?></div>
                <div><i class="fa-solid fa-flask" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($c['lab_name']); ?></div>
                <div><i class="fa-solid fa-calendar-week" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($c['schedule_label']); ?></div>
                <div><i class="fa-solid fa-graduation-cap" style="width:18px;color:var(--indigo-600)"></i> <?php echo e($c['program_code'] ?? 'Any Course'); ?><?php echo $c['year_level'] ? ' · Year ' . e($c['year_level']) : ''; ?> · <?php echo e($c['section']); ?></div>
                <div><i class="fa-solid fa-users" style="width:18px;color:var(--indigo-600)"></i> <?php echo (int) $c['enrolled_count']; ?>/<?php echo (int) $c['max_students']; ?> slots filled</div>
            </div>
            <div style="margin-top:14px">
                <?php if ($isEnrolled): ?>
                    <span class="badge badge-active" style="width:100%;justify-content:center;padding:9px"><i class="fa-solid fa-check"></i> Already Enrolled</span>
                <?php elseif ($isPending): ?>
                    <span class="badge badge-late" style="width:100%;justify-content:center;padding:9px"><i class="fa-solid fa-hourglass-half"></i> Request Pending</span>
                <?php elseif ($isFull): ?>
                    <button class="btn btn-outline btn-block" disabled>Subject Full</button>
                <?php else: ?>
                    <button class="btn btn-primary btn-block" onclick='openRequestModal(<?php echo json_encode($c); ?>)'><i class="fa-solid fa-paper-plane"></i> Request Enrollment</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; endif; ?>
</div>

<!-- ===================== REQUEST MODAL ===================== -->
<div class="modal-backdrop" id="requestModal">
    <div class="modal">
        <div class="modal-header"><h3>Request Enrollment</h3><button class="modal-close" onclick="closeModal('requestModal')">&times;</button></div>
        <form id="requestForm">
            <div class="modal-body">
                <input type="hidden" name="teacher_subject_id" id="req_class_id">

                <h4 style="margin:0 0 8px;font-size:13px;color:var(--slate-500);text-transform:uppercase;letter-spacing:.03em">Your Information</h4>
                <div class="form-row">
                    <div class="form-group"><label>Student ID</label><input type="text" class="form-control" id="req_student_id" disabled></div>
                    <div class="form-group"><label>Full Name</label><input type="text" class="form-control" value="<?php echo e($_SESSION['full_name']); ?>" disabled></div>
                </div>

                <h4 style="margin:16px 0 8px;font-size:13px;color:var(--slate-500);text-transform:uppercase;letter-spacing:.03em">Requested Subject</h4>
                <div class="form-group"><label>Subject</label><input type="text" class="form-control" id="req_subject_label" disabled></div>
                <div class="form-row">
                    <div class="form-group"><label>Teacher</label><input type="text" class="form-control" id="req_teacher_label" disabled></div>
                    <div class="form-group"><label>Laboratory</label><input type="text" class="form-control" id="req_lab_label" disabled></div>
                </div>
                <div class="form-group"><label>Schedule</label><input type="text" class="form-control" id="req_schedule_label" disabled></div>

                <div class="form-group" style="margin-top:16px">
                    <label>Reason / Remarks (optional)</label>
                    <textarea name="remarks" id="req_remarks" class="form-control" rows="3" placeholder="e.g. This subject is part of my current semester schedule."></textarea>
                </div>

                <div class="alert alert-info" style="margin-bottom:0"><i class="fa-solid fa-circle-info"></i> Please verify that the information above is correct before submitting your enrollment request.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('requestModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="requestSubmitBtn">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRequestModal(c) {
    document.getElementById('req_class_id').value = c.teacher_subject_id;
    document.getElementById('req_student_id').value = <?php echo json_encode($_SESSION['username'] ?? ''); ?>;
    document.getElementById('req_subject_label').value = c.subject_code + ' - ' + c.subject_name;
    document.getElementById('req_teacher_label').value = c.teacher_name;
    document.getElementById('req_lab_label').value = c.lab_name;
    document.getElementById('req_schedule_label').value = c.schedule_label;
    document.getElementById('req_remarks').value = '';
    openModal('requestModal');
}
document.getElementById('requestForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('requestSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Submitting...';
    const data = Object.fromEntries(new FormData(e.target));
    const res = await ajaxPost('ajax_request_enrollment.php', data);
    if (res.success) { showToast('success', res.message); closeModal('requestModal'); setTimeout(() => location.reload(), 700); }
    else showToast('error', res.message);
    btn.disabled = false; btn.innerHTML = 'Submit Request';
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
