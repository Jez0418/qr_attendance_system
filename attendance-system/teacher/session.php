<?php
/**
 * teacher/session.php
 * Today's meetings for this teacher (own classes, plus any meeting they
 * substitute for). Attendance opens by itself: whenever a meeting is
 * ACTIVE, ensure_session_for_occurrence() (qr/session_manager.php) makes
 * sure it has a session with a fresh one-time QR token that expires at
 * the meeting's end. The teacher can close it early and reopen it (same
 * QR code) while the meeting is still in progress. The page reloads itself at the next start/end time so the QR
 * appears and disappears on schedule.
 *
 * Late logic: a scan more than late_grace_minutes (Admin > Settings)
 * after the meeting's start is marked "Late".
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('teacher');
$pageTitle = 'Attendance Session';

auto_expire_sessions($pdo);

$teacherId = (int) $_SESSION['profile_id'];
$now = schedule_now();
$meetings = ensure_sessions_for_today($pdo, ['teacher_id' => $teacherId], $now);
$graceMinutes = get_late_grace_minutes($pdo);

// Selected meeting: ?occ=<key>, else the one in progress, else the next one, else the first.
$selected = null;
foreach ($meetings as $m) if ($m['occurrence']['occurrence_key'] === ($_GET['occ'] ?? '')) $selected = $m;
if (!$selected) foreach ($meetings as $m) if ($m['status'] === OCCURRENCE_ACTIVE) { $selected = $m; break; }
if (!$selected) foreach ($meetings as $m) if ($m['status'] === OCCURRENCE_UPCOMING) { $selected = $m; break; }
if (!$selected && $meetings) $selected = $meetings[0];

$occ = $selected['occurrence'] ?? null;
$session = $selected['session'] ?? null;
$sessionOpen = $session && (int) $session['is_active'] === 1;
$canReopen = $session && !$sessionOpen && $selected['status'] === OCCURRENCE_ACTIVE;

$reloadIn = seconds_until_next_change(array_column($meetings, 'occurrence'), $now);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3>Today's Classes</h3>
        <span class="text-muted" style="font-size:12.5px"><?php echo $now->format('l, F j, Y'); ?></span>
    </div>
    <?php if (!$meetings): ?>
        <div class="card-body"><div class="empty-state"><i class="fa-solid fa-calendar-check"></i><p>You have no classes scheduled today.</p></div></div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Time</th><th>Subject</th><th>Section</th><th>Laboratory</th><th>Status</th><th>Attendance</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($meetings as $m): $o = $m['occurrence']; [$label, $cls] = OCCURRENCE_BADGES[$m['status']]; $isSel = $occ && $o['occurrence_key'] === $occ['occurrence_key']; ?>
                <tr<?php echo $isSel ? ' style="background:var(--indigo-50)"' : ''; ?>>
                    <td style="white-space:nowrap"><?php echo e(format_time_range($o['start_time'], $o['end_time'])); ?></td>
                    <td><?php echo e($o['subject_code'] . ' - ' . $o['subject_name']); ?><?php if ($o['teacher_id'] !== $o['original_teacher_id']): ?><div class="text-muted" style="font-size:11px">You are substituting</div><?php endif; ?></td>
                    <td><?php echo e($o['section']); ?></td>
                    <td><?php echo e($o['lab_name']); ?></td>
                    <td><span class="badge <?php echo $cls; ?>"><?php echo $label; ?></span><?php if ($o['is_rescheduled']): ?> <span class="badge badge-rescheduled">Rescheduled</span><?php endif; ?></td>
                    <td style="font-size:12.5px"><?php echo e(attendance_state_label($m)); ?></td>
                    <td><?php if (!$isSel): ?><a class="btn btn-outline btn-sm" href="session.php?occ=<?php echo urlencode($o['occurrence_key']); ?>">View</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($occ): [$label, $cls] = OCCURRENCE_BADGES[$selected['status']]; ?>
<div class="card" style="margin-top:20px">
    <div class="card-body">
        <div class="toggle-row">
            <div>
                <div style="font-size:12.5px;color:var(--slate-500);font-weight:600;margin-bottom:3px"><?php echo e($occ['lab_name']); ?></div>
                <div style="font-size:16px;font-weight:700;color:var(--slate-900)"><?php echo e($occ['subject_code'] . ' - ' . $occ['subject_name'] . ' (' . $occ['section'] . ')'); ?></div>
                <div style="font-size:12.5px;color:var(--slate-500);margin-top:2px">
                    Today · <?php echo e(format_time_range($occ['start_time'], $occ['end_time'])); ?>
                    <span class="badge <?php echo $cls; ?>" style="margin-left:6px"><?php echo $label; ?></span>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span style="font-size:13px;font-weight:600;color:<?php echo $sessionOpen ? 'var(--green-600)' : 'var(--slate-500)'; ?>"><?php echo e(attendance_state_label($selected)); ?></span>
                <?php if ($sessionOpen): ?>
                    <button class="btn btn-danger btn-sm" id="closeBtn" onclick="closeAttendance()"><i class="fa-solid fa-stop"></i> Close attendance</button>
                <?php elseif ($canReopen): ?>
                    <button class="btn btn-success btn-sm" id="reopenBtn" onclick="reopenAttendance()"><i class="fa-solid fa-play"></i> Reopen attendance</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-top:20px">
    <div class="card">
        <div class="card-header"><h3>Attendance QR Code</h3></div>
        <div class="card-body qr-display">
            <?php if ($sessionOpen): ?>
                <div id="qrcodeCanvas"></div>
                <div class="qr-session-meta">
                    <div>This code is <strong>temporary</strong> — generated for this meeting only. It stops working when the class ends or attendance is closed.</div>
                    <div style="margin-top:8px">Late after: <?php echo date('g:i A', strtotime($session['scheduled_start']) + $graceMinutes * 60); ?> · Expires: <?php echo date('g:i A', strtotime($session['session_end'])); ?></div>
                    <div>Geofence radius: <?php echo (int) $session['allowed_radius_meters']; ?>m</div>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-qrcode"></i><p><?php echo e(attendance_state_label($selected)); ?>.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Session Summary</h3></div>
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
            <p class="text-muted" style="font-size:13px;margin-top:16px">Students appear below the moment they scan. This panel refreshes every few seconds while attendance is open.</p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header"><h3><?php echo e($occ['subject_code'] . ' - ' . $occ['subject_name']); ?></h3></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Student Name</th><th>ID</th><th>Check-in Time</th><th>Distance</th><th>Status</th></tr></thead>
            <tbody id="liveScanBody">
                <tr><td colspan="5" class="text-center text-muted"><?php echo $session ? 'Loading roster…' : 'The roster appears once attendance opens.'; ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php if ($session): ?>
<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>Rejected Scans</h3></div>
    <div class="card-body" style="padding-bottom:0"><p class="text-muted" style="font-size:13px;margin:0">Scans that were refused, such as a student outside the laboratory radius, with how far away they were.</p></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Student Name</th><th>ID</th><th>Time</th><th>Distance</th><th>Reason</th></tr></thead>
            <tbody id="rejectedScanBody">
                <tr><td colspan="5" class="text-center text-muted">No rejected scans.</td></tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
const SESSION_ID = <?php echo $session ? (int) $session['session_id'] : 'null'; ?>;
const SESSION_OPEN = <?php echo $sessionOpen ? 'true' : 'false'; ?>;
const RELOAD_IN = <?php echo $reloadIn !== null ? (int) $reloadIn : 'null'; ?>; // seconds until the next class starts or ends
let pollTimer = null;

document.addEventListener('DOMContentLoaded', () => {
    <?php if ($sessionOpen): ?>
    safeRenderQr(document.getElementById('qrcodeCanvas'), <?php echo json_encode(qr_build_session_payload($session['session_id'], $session['qr_token'])); ?>, 200);
    <?php endif; ?>
    if (SESSION_ID) {
        fetchLiveScans();
        if (SESSION_OPEN) pollTimer = setInterval(fetchLiveScans, 4000);
    }
    // Reload a moment after the next start/end so attendance opens and closes on schedule.
    if (RELOAD_IN !== null && RELOAD_IN < 86400) setTimeout(() => location.reload(), (RELOAD_IN + 2) * 1000);
});

async function closeAttendance() {
    if (!confirm('Close attendance now? Students will no longer be able to check in until you reopen it.')) return;
    const btn = document.getElementById('closeBtn');
    btn.disabled = true;
    const res = await ajaxPost('ajax_session.php', { action: 'close', session_id: SESSION_ID });
    if (res.success) { showToast('success', res.message); clearInterval(pollTimer); setTimeout(() => location.reload(), 400); }
    else { showToast('error', res.message); btn.disabled = false; }
}

async function reopenAttendance() {
    if (!confirm('Reopen attendance? The same QR code will work again until the class ends.')) return;
    const btn = document.getElementById('reopenBtn');
    btn.disabled = true;
    const res = await ajaxPost('ajax_session.php', { action: 'reopen', session_id: SESSION_ID });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 400); }
    else { showToast('error', res.message); btn.disabled = false; }
}

function initials(name) {
    return name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
}
function fmtDistance(m) { return m === null || m === undefined ? '—' : (m >= 1000 ? (m / 1000).toFixed(1) + ' km' : m + ' m'); }
function td(text) { const c = document.createElement('td'); c.textContent = text; return c; }

function renderRejected(attempts) {
    const body = document.getElementById('rejectedScanBody');
    if (!body) return;
    body.replaceChildren();
    if (attempts.length === 0) {
        const tr = document.createElement('tr'), c = td('No rejected scans.');
        c.colSpan = 5; c.className = 'text-center text-muted'; tr.appendChild(c); body.appendChild(tr);
        return;
    }
    for (const a of attempts) {
        const tr = document.createElement('tr');
        tr.append(td(a.full_name), td(a.student_number), td(a.time), td(fmtDistance(a.distance)), td(a.reason));
        body.appendChild(tr);
    }
}

async function fetchLiveScans() {
    try {
        const res = await ajaxGet('ajax_session_status.php?session_id=' + SESSION_ID);
        if (!res.success) return;
        document.getElementById('scannedCountVal').textContent = res.scanned_count;
        document.getElementById('totalCountVal').textContent = res.total_count;
        const body = document.getElementById('liveScanBody');
        body.replaceChildren();
        if (res.records.length === 0) {
            const tr = document.createElement('tr'), c = td('No students enrolled in this class yet.');
            c.colSpan = 5; c.className = 'text-center text-muted'; tr.appendChild(c); body.appendChild(tr);
            renderRejected(res.attempts || []);
            return;
        }
        for (const r of res.records) {
            const tr = document.createElement('tr');
            const nameCell = document.createElement('td'), wrap = document.createElement('div'), av = document.createElement('span');
            wrap.className = 'roster-name-cell'; av.className = 'avatar-sm'; av.textContent = initials(r.full_name);
            wrap.append(av, ' ' + r.full_name); nameCell.appendChild(wrap);
            const st = document.createElement('td'), b = document.createElement('span');
            b.className = 'badge badge-' + r.status.toLowerCase(); b.textContent = r.status; st.appendChild(b);
            tr.append(nameCell, td(r.student_number), td(r.time_in), td(fmtDistance(r.distance)), st);
            body.appendChild(tr);
        }
        renderRejected(res.attempts || []);
    } catch (err) { /* will retry on the next poll */ }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
