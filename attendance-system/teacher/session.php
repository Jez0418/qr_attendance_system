<?php
/**
 * teacher/session.php
 * The core teacher workflow:
 *   1. Pick one of your assigned classes (each has an explicit
 *      meeting_date — the single source of truth for "is this
 *      today's class?", never day-of-week alone)
 *   2. Flip the switch to open attendance — this mints a brand-new,
 *      random, one-time QR token (never reused across sessions)
 *   3. Watch students appear in the live "Scanned" list (AJAX polling)
 *   4. Flip it off to close the window
 *
 * A class whose meeting_date isn't today cannot be activated at all
 * (qr/session_manager.php enforces this server-side) — this page
 * reflects that by disabling the toggle and explaining why.
 *
 * Late logic: scheduled_start = the class's meeting_date + start_time.
 * A scan more than LATE_THRESHOLD_MINUTES after that is marked "Late".
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/qr_helper.php';
require_role('teacher');
$pageTitle = 'Attendance Session';

auto_expire_sessions($pdo);

$teacherId = $_SESSION['profile_id'];

$classes = $pdo->prepare('
    SELECT ts.teacher_subject_id, sub.subject_code, sub.subject_name, ts.section, ts.start_time, ts.end_time,
        ts.schedule_day, ts.meeting_date, ts.max_students,
        lab.lab_id, lab.lab_name
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE ts.teacher_id = ? AND ts.status = "active"
    ORDER BY ts.meeting_date IS NULL, ts.meeting_date DESC, sub.subject_code
');
$classes->execute([$teacherId]);
$classes = $classes->fetchAll();

$selectedClass = (int) ($_GET['class'] ?? ($classes[0]['teacher_subject_id'] ?? 0));
$belongsToTeacher = false;
$classInfo = null;
foreach ($classes as $c) {
    if ((int) $c['teacher_subject_id'] === $selectedClass) { $belongsToTeacher = true; $classInfo = $c; }
}
if (!$belongsToTeacher) { $selectedClass = 0; $classInfo = null; }

$classStatus = $classInfo ? compute_class_status($classInfo['meeting_date'], $classInfo['start_time'], $classInfo['end_time']) : 'no_date';
$isTodaysMeeting = $classInfo ? is_meeting_today($classInfo['meeting_date']) : false;
$canActivate = $classInfo && (!$classInfo['meeting_date'] || ($isTodaysMeeting && $classStatus !== 'expired'));

// Check for an existing active session today for this class
$activeSession = null;
if ($selectedClass) {
    $stmt = $pdo->prepare('SELECT * FROM attendance_sessions WHERE teacher_subject_id = ? AND session_date = CURDATE() AND is_active = 1 LIMIT 1');
    $stmt->execute([$selectedClass]);
    $activeSession = $stmt->fetch();
}

require_once __DIR__ . '/../includes/header.php';
$statusMeta = class_status_badge($classStatus);
?>

<div class="card">
    <div class="card-header"><h3>Select Class</h3></div>
    <div class="card-body">
        <?php if (empty($classes)): ?>
            <div class="empty-state"><i class="fa-solid fa-book"></i><p>You have no active class assignments. Contact the administrator.</p></div>
        <?php else: ?>
        <form method="GET" class="toolbar">
            <select name="class" class="form-control" style="max-width:420px" onchange="this.form.submit()">
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo $c['teacher_subject_id']; ?>" <?php echo $selectedClass == $c['teacher_subject_id'] ? 'selected' : ''; ?>>
                        <?php echo e($c['subject_code'] . ' - ' . $c['subject_name'] . ' (' . $c['section'] . ')' . ($c['meeting_date'] ? ' — ' . format_date($c['meeting_date']) : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($classInfo): ?>
<div class="card" style="margin-top:20px">
    <div class="card-body">
        <div class="toggle-row">
            <div>
                <div style="font-size:12.5px;color:var(--slate-500);font-weight:600;margin-bottom:3px">Assigned Lab</div>
                <div style="font-size:16px;font-weight:700;color:var(--slate-900)"><?php echo e($classInfo['lab_name']); ?> — <?php echo e($classInfo['subject_code']); ?></div>
                <div style="font-size:12.5px;color:var(--slate-500);margin-top:2px">
                    <?php if ($classInfo['meeting_date']): ?>
                        <?php echo e($classInfo['schedule_day']); ?>, <?php echo format_date($classInfo['meeting_date']); ?> · <?php echo format_time($classInfo['start_time']); ?>–<?php echo format_time($classInfo['end_time']); ?>
                        <span class="badge <?php echo $statusMeta['class']; ?>" style="margin-left:6px"><?php echo $statusMeta['label']; ?></span>
                    <?php else: ?>
                        <?php echo e($classInfo['schedule_day']); ?> · <?php echo format_time($classInfo['start_time']); ?>–<?php echo format_time($classInfo['end_time']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <span id="toggleLabel" style="font-size:13px;font-weight:600;color:<?php echo $activeSession ? 'var(--green-600)' : 'var(--slate-500)'; ?>"><?php echo $activeSession ? 'Attendance Open' : 'Open Attendance'; ?></span>
                <label class="toggle-switch">
                    <input type="checkbox" id="sessionToggle" <?php echo $activeSession ? 'checked' : ''; ?> <?php echo $canActivate ? '' : 'disabled'; ?> onchange="onToggleChange(this)">
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        <?php if (!$canActivate && !$activeSession): ?>
            <div class="alert alert-error" style="margin-top:14px;margin-bottom:0">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo $isTodaysMeeting ? 'Today\'s scheduled time window for this class has already ended.' : 'This class is not scheduled for today (meeting date: ' . format_date($classInfo['meeting_date']) . ').'; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2" style="margin-top:20px">
    <div class="card">
        <div class="card-header">
            <h3>Attendance QR Code</h3>
        </div>
        <div class="card-body qr-display">
            <?php if ($activeSession): ?>
                <div id="qrcodeCanvas"></div>
                <div class="qr-session-meta">
                    <div>This code is <strong>temporary</strong> — generated fresh for this session only. It stops working the moment attendance closes.</div>
                    <div style="margin-top:8px">Late after: <?php echo date('h:i A', strtotime($activeSession['scheduled_start']) + LATE_THRESHOLD_MINUTES * 60); ?> · Expires: <?php echo date('h:i A', strtotime($activeSession['session_end'])); ?></div>
                    <div>Geofence radius: <?php echo (int) $activeSession['allowed_radius_meters']; ?>m
                        <?php if ($activeSession['created_by_role'] === 'admin'): ?><span class="badge badge-inactive" style="margin-left:6px">Activated by Admin</span><?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-qrcode"></i><p>No QR code yet — flip the switch above to open attendance and generate one.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Session Summary</h3>
        </div>
        <div class="card-body">
            <div style="display:flex;gap:16px">
                <div style="flex:1;text-align:center;padding:16px;background:var(--slate-50);border-radius:var(--radius-md)">
                    <div style="font-size:24px;font-weight:800;color:var(--indigo-600)" id="scannedCountVal">0</div>
                    <div style="font-size:12px;color:var(--slate-500);margin-top:2px">Scanned</div>
                </div>
                <div style="flex:1;text-align:center;padding:16px;background:var(--slate-50);border-radius:var(--radius-md)">
                    <div style="font-size:24px;font-weight:800;color:var(--slate-700)" id="totalCountVal">0</div>
                    <div style="font-size:12px;color:var(--slate-500);margin-top:2px">Enrolled</div>
                </div>
            </div>
            <p class="text-muted" style="font-size:13px;margin-top:16px">Students appear below the moment they scan. This panel refreshes automatically every few seconds while attendance is open.</p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3><?php echo e($classInfo['subject_code'] . ' - ' . $classInfo['subject_name']); ?></h3>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Student Name</th><th>ID</th><th>Check-in Time</th><th>Status</th></tr></thead>
            <tbody id="liveScanBody">
                <tr><td colspan="4" class="text-center text-muted">Open attendance to load the class roster.</td></tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
const CLASS_ID = <?php echo (int) $selectedClass; ?>;
let ACTIVE_SESSION_ID = <?php echo $activeSession ? (int) $activeSession['session_id'] : 'null'; ?>;
let pollTimer = null;

// Wait for the full page (including app.js, loaded near the bottom) before
// doing anything that depends on it — safeRenderQr/ajaxGet/showToast all live there.
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($activeSession): ?>
    // Temporary, session-scoped QR — a brand-new token minted at activation,
    // never reused. Rendered straight from this page load's data.
    safeRenderQr(document.getElementById('qrcodeCanvas'), <?php echo json_encode(qr_build_session_payload($activeSession['session_id'], $activeSession['qr_token'])); ?>, 200);
    startPolling();
    <?php endif; ?>
});

// Toggle switch drives activate/deactivate — flips back if the request fails
async function onToggleChange(checkbox) {
    checkbox.disabled = true;
    if (checkbox.checked) {
        const res = await ajaxPost('ajax_session.php', { action: 'activate', teacher_subject_id: CLASS_ID });
        if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 400); }
        else { showToast('error', res.message); checkbox.checked = false; checkbox.disabled = false; }
    } else {
        if (!confirm('Close attendance for this class? Students will no longer be able to check in.')) {
            checkbox.checked = true; checkbox.disabled = false; return;
        }
        const res = await ajaxPost('ajax_session.php', { action: 'deactivate', teacher_subject_id: CLASS_ID });
        if (res.success) { showToast('success', res.message); clearInterval(pollTimer); setTimeout(() => location.reload(), 400); }
        else { showToast('error', res.message); checkbox.checked = true; checkbox.disabled = false; }
    }
}

function startPolling() {
    fetchLiveScans();
    pollTimer = setInterval(fetchLiveScans, 4000);
}

function initials(name) {
    return name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
}

async function fetchLiveScans() {
    if (!ACTIVE_SESSION_ID) return;
    try {
        const res = await ajaxGet('ajax_session_status.php?session_id=' + ACTIVE_SESSION_ID);
        if (!res.success) return;
        document.getElementById('scannedCountVal').textContent = res.scanned_count;
        document.getElementById('totalCountVal').textContent = res.total_count;
        const body = document.getElementById('liveScanBody');
        if (res.records.length === 0) {
            body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No students enrolled in this class yet.</td></tr>';
            return;
        }
        body.innerHTML = res.records.map(r => `
            <tr>
                <td><div class="roster-name-cell"><span class="avatar-sm">${initials(r.full_name)}</span> ${r.full_name}</div></td>
                <td>${r.student_number}</td>
                <td>${r.time_in}</td>
                <td><span class="badge badge-${r.status.toLowerCase()}">${r.status}</span></td>
            </tr>
        `).join('');
    } catch (err) { /* silent fail, will retry next poll */ }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
