<?php
/**
 * admin/qr_management.php
 * "Attendance Session Management" — the administrator can activate
 * or deactivate the attendance-taking window for ANY class in the
 * system, choosing date/start/end/radius, without being able to
 * create an invalid teacher-subject combination (the class picker
 * only ever lists real, existing assignments) and without being
 * able to activate a class that doesn't meet today per its recurring
 * weekly schedule (qr/session_manager.php enforces this server-side
 * either way).
 *
 * QR codes are temporary and session-scoped — a fresh, random token
 * is minted at every activation (qr/qr_helper.php, qr/session_manager.php),
 * never reused, and shown live below for any currently active session.
 *
 * This sits alongside — not instead of — the teacher's own
 * activate/deactivate toggle (teacher/session.php). Either party can
 * activate or close a session; whoever did is recorded and shown.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/qr_helper.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('admin');
$pageTitle = 'Attendance Session Management';

auto_expire_sessions($pdo);

// All active class assignments, each with their current session status (if any)
$classes = $pdo->query('
    SELECT ts.teacher_subject_id, ts.section,
        sub.subject_code, sub.subject_name, t.full_name AS teacher_name, lab.lab_name, lab.allowed_radius_meters,
        lab.latitude, lab.longitude,
        s.session_id, s.is_active, s.created_by_role, s.session_end
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    LEFT JOIN attendance_sessions s ON s.teacher_subject_id = ts.teacher_subject_id
        AND s.session_date = CURDATE() AND s.is_active = 1
    WHERE ts.status = "active"
    ORDER BY sub.subject_code, ts.section
')->fetchAll();
$classes = attach_class_schedules($pdo, $classes);

// Classes meeting today first (in class now, later today, ended), then the rest
$rank = ['active' => 0, 'upcoming' => 1, 'ended' => 2, 'not_today' => 3, 'no_schedule' => 4];
foreach ($classes as &$c) $c['today_state'] = class_schedule_status($c['schedules']);
unset($c);
usort($classes, fn($a, $b) => $rank[$a['today_state']['status']] <=> $rank[$b['today_state']['status']]);

$activeSessions = $pdo->query('
    SELECT s.*, t.full_name AS teacher_name, sub.subject_name, sub.subject_code, lab.lab_name
    FROM attendance_sessions s
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE s.is_active = 1
    ORDER BY s.created_at DESC
')->fetchAll();

$recentSessions = $pdo->query('
    SELECT s.*, t.full_name AS teacher_name, sub.subject_name, lab.lab_name,
        (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = s.session_id) AS scans
    FROM attendance_sessions s
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE s.is_active = 0
    ORDER BY s.deactivated_at DESC LIMIT 10
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fa-solid fa-shield-halved"></i>
    QR codes are temporary — a brand-new code is generated every time attendance
    is opened and stops working the moment it closes. A class can only be
    activated on one of its scheduled weekly meeting days, before or during
    that day's class time.
</div>

<div class="card">
    <div class="card-header"><h3>All Classes — Attendance Status</h3></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Subject</th><th>Teacher</th><th>Laboratory</th><th>Schedule</th><th>Today</th><th>QR Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($classes)): ?>
                <tr><td colspan="7" class="text-center text-muted">No active class assignments yet.</td></tr>
            <?php else: foreach ($classes as $c):
                $hasCoords = $c['latitude'] !== null && $c['longitude'] !== null;
                $blockReason = class_activation_block_reason($c['schedules']);
                $canActivate = $hasCoords && $blockReason === '';
                $statusMeta = class_status_badge($c['today_state']['status']);
                $todaySlot = $c['today_state']['slot'];
            ?>
                <tr>
                    <td><?php echo e($c['subject_code'] . ' - ' . $c['subject_name']); ?><div class="text-muted" style="font-size:11.5px"><?php echo e($c['section']); ?></div></td>
                    <td><?php echo e($c['teacher_name']); ?></td>
                    <td><?php echo e($c['lab_name']); ?><?php if (!$hasCoords): ?><div style="font-size:11px;color:var(--red-600)"><i class="fa-solid fa-triangle-exclamation"></i> No GPS set</div><?php endif; ?></td>
                    <td style="font-size:12.5px"><?php echo e(format_class_schedule($c['schedules'])); ?></td>
                    <td>
                        <span class="badge <?php echo $statusMeta['class']; ?>"><?php echo $statusMeta['label']; ?></span>
                        <?php if ($todaySlot): ?><div class="text-muted" style="font-size:11px"><?php echo format_time($todaySlot['start_time']); ?>–<?php echo format_time($todaySlot['end_time']); ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['session_id']): ?>
                            <span class="badge badge-active"><span class="dot dot-green" style="width:6px;height:6px;border-radius:50%"></span> ACTIVE<?php echo $c['created_by_role'] === 'admin' ? ' (Admin)' : ''; ?></span>
                        <?php else: ?>
                            <span class="badge badge-inactive"><span class="dot dot-red" style="width:6px;height:6px;border-radius:50%"></span> INACTIVE</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['session_id']): ?>
                            <button class="btn btn-danger btn-sm" onclick="deactivateClass(<?php echo $c['teacher_subject_id']; ?>)"><i class="fa-solid fa-stop"></i> Deactivate</button>
                        <?php else: ?>
                            <button class="btn btn-primary btn-sm" <?php echo $canActivate ? '' : 'disabled title="' . e($hasCoords ? $blockReason : 'Set GPS coordinates for this laboratory first') . '"'; ?> onclick='openActivateModal(<?php echo json_encode($c); ?>)'><i class="fa-solid fa-play"></i> Activate</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>Currently Active Sessions (<?php echo count($activeSessions); ?>)</h3></div>
    <div class="card-body">
        <?php if (empty($activeSessions)): ?>
            <div class="empty-state"><i class="fa-solid fa-qrcode"></i><p>No active sessions right now.</p></div>
        <?php else: ?>
        <div class="grid-3">
            <?php foreach ($activeSessions as $s): ?>
            <div class="card">
                <div class="card-body text-center">
                    <div id="qr-<?php echo $s['session_id']; ?>" style="display:inline-block;padding:10px;background:#fff;border-radius:8px;border:1px solid #e2e8f0"></div>
                    <h4 style="margin:14px 0 4px"><?php echo e($s['subject_code'] . ' - ' . $s['subject_name']); ?></h4>
                    <p class="text-muted" style="font-size:13px;margin:0"><?php echo e($s['teacher_name']); ?> · <?php echo e($s['lab_name']); ?></p>
                    <p class="text-muted" style="font-size:12px;margin:4px 0 2px">Started: <?php echo format_datetime($s['created_at']); ?></p>
                    <p class="text-muted" style="font-size:12px;margin:0 0 14px">Radius: <?php echo (int) $s['allowed_radius_meters']; ?>m · Expires: <?php echo format_datetime($s['session_end']); ?></p>
                    <span class="badge badge-inactive" style="margin-bottom:10px;display:inline-block">Activated by <?php echo $s['created_by_role'] === 'admin' ? 'Admin' : 'Teacher'; ?></span><br>
                    <button class="btn btn-danger btn-sm" onclick="forceStop(<?php echo $s['session_id']; ?>)"><i class="fa-solid fa-stop"></i> Force Stop</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>Recently Closed Sessions</h3></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Subject</th><th>Teacher</th><th>Lab</th><th>Date</th><th>Scans</th><th>Closed At</th></tr></thead>
            <tbody>
            <?php if (empty($recentSessions)): ?>
                <tr><td colspan="6" class="text-center text-muted">No closed sessions yet.</td></tr>
            <?php else: foreach ($recentSessions as $s): ?>
                <tr>
                    <td><?php echo e($s['subject_name']); ?></td>
                    <td><?php echo e($s['teacher_name']); ?></td>
                    <td><?php echo e($s['lab_name']); ?></td>
                    <td><?php echo format_date($s['session_date']); ?></td>
                    <td><?php echo (int) $s['scans']; ?></td>
                    <td><?php echo format_datetime($s['deactivated_at']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================== ACTIVATE MODAL ===================== -->
<div class="modal-backdrop" id="activateModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="activateModalTitle">Activate Attendance</h3>
            <button class="modal-close" onclick="closeModal('activateModal')">&times;</button>
        </div>
        <form id="activateForm">
            <div class="modal-body">
                <input type="hidden" name="teacher_subject_id" id="act_class_id">
                <p class="text-muted" style="margin-top:0" id="act_class_label"></p>
                <div class="form-row">
                    <div class="form-group"><label>Date</label><input type="date" name="session_date" id="act_date" class="form-control" required></div>
                    <div class="form-group"><label>Allowed Radius (meters)</label><input type="number" name="radius" id="act_radius" class="form-control" min="5" max="1000" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Start Time</label><input type="time" name="start_time" id="act_start" class="form-control" required></div>
                    <div class="form-group"><label>End Time (session expires)</label><input type="time" name="end_time" id="act_end" class="form-control" required></div>
                </div>
                <div class="alert alert-info" style="margin-bottom:0"><i class="fa-solid fa-circle-info"></i> Attendance is opened for today's scheduled meeting. Start/end default to today's class time and can be adjusted.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('activateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="activateSubmitBtn">Activate Session</button>
            </div>
        </form>
    </div>
</div>

<script>
const ACTIVE_SESSION_PAYLOADS = <?php echo json_encode(array_map(fn($s) => ['id' => (int) $s['session_id'], 'payload' => qr_build_session_payload($s['session_id'], $s['qr_token'])], $activeSessions)); ?>;

document.addEventListener('DOMContentLoaded', () => {
    ACTIVE_SESSION_PAYLOADS.forEach(s => {
        const el = document.getElementById('qr-' + s.id);
        if (el) safeRenderQr(el, s.payload, 160);
    });
});

function openActivateModal(c) {
    document.getElementById('act_class_id').value = c.teacher_subject_id;
    document.getElementById('act_class_label').textContent = c.subject_code + ' - ' + c.subject_name + ' | ' + c.teacher_name + ' | ' + c.lab_name;
    const dateField = document.getElementById('act_date');
    dateField.value = <?php echo json_encode(date('Y-m-d')); ?>;
    dateField.readOnly = true;
    const slot = c.today_state.slot || {};
    document.getElementById('act_start').value = (slot.start_time || '').substring(0, 5);
    document.getElementById('act_end').value = (slot.end_time || '').substring(0, 5);
    document.getElementById('act_radius').value = c.allowed_radius_meters;
    openModal('activateModal');
}

document.getElementById('activateForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('activateSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Activating...';
    const data = Object.fromEntries(new FormData(e.target));
    const res = await ajaxPost('ajax_qr_activate.php', data);
    if (res.success) { showToast('success', res.message); closeModal('activateModal'); setTimeout(() => location.reload(), 600); }
    else showToast('error', res.message);
    btn.disabled = false; btn.innerHTML = 'Activate Session';
});

async function deactivateClass(classId) {
    if (!confirm('Deactivate attendance for this class?')) return;
    const res = await ajaxPost('ajax_qr_deactivate.php', { teacher_subject_id: classId });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 500); }
    else showToast('error', res.message);
}

async function forceStop(sessionId) {
    if (!confirm('Force-stop this QR session? Students will no longer be able to scan it.')) return;
    const res = await ajaxPost('ajax_qr_deactivate.php', { session_id: sessionId });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 500); }
    else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
