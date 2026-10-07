<?php
/**
 * admin/qr_management.php — "Attendance Sessions"
 * Today's class meetings and their attendance sessions. Sessions open
 * automatically when a meeting becomes ACTIVE (ensure_session_for_occurrence()
 * in qr/session_manager.php, called here for every meeting today) and expire
 * at the meeting's end, each with a fresh one-time QR token. The admin can
 * close a session early and reopen it (same QR code) while its meeting is
 * still in progress. The page reloads itself at the next start/end time.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/session_manager.php';
require_role('admin');
$pageTitle = 'Attendance Session Management';

auto_expire_sessions($pdo);

$now = schedule_now();
$meetings = ensure_sessions_for_today($pdo, [], $now);
$reloadIn = seconds_until_next_change(array_column($meetings, 'occurrence'), $now);
$openMeetings = array_values(array_filter($meetings, fn($m) => $m['session'] && (int) $m['session']['is_active'] === 1));

$labCoords = [];
foreach ($pdo->query('SELECT lab_id, CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END AS has_coords FROM laboratories')->fetchAll() as $l) {
    $labCoords[(int) $l['lab_id']] = (bool) $l['has_coords'];
}

$recentSessions = $pdo->query('
    SELECT s.*, t.full_name AS teacher_name, sub.subject_name, sub.subject_code,
        (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = s.session_id AND ar.status <> "Absent") AS scans
    FROM attendance_sessions s
    JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
    JOIN teachers t ON t.teacher_id = s.activated_by
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    WHERE s.is_active = 0
    ORDER BY s.deactivated_at DESC NULLS LAST LIMIT 10
')->fetchAll();
foreach ($recentSessions as &$rs) {
    // Only sessions whose end time hasn't passed are worth checking against the schedule.
    $rs['can_reopen'] = new DateTimeImmutable($rs['session_end'], schedule_tz()) > $now && can_reopen_session($pdo, $rs, $now);
}
unset($rs);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fa-solid fa-shield-halved"></i>
    Attendance opens automatically when a class starts and closes when it ends, each time with a new one-time QR code.
    You can close a session early and reopen it while the class is still in progress (the same QR code works again); cancelled classes never open.
</div>

<div class="card">
    <div class="card-header">
        <h3>Today's Classes — Attendance</h3>
        <span class="text-muted" style="font-size:12.5px"><?php echo $now->format('l, F j, Y'); ?></span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Time</th><th>Subject</th><th>Teacher</th><th>Laboratory</th><th>Status</th><th>Attendance</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$meetings): ?>
                <tr><td colspan="7" class="text-center text-muted">No classes scheduled today.</td></tr>
            <?php else: foreach ($meetings as $m):
                $o = $m['occurrence'];
                [$label, $cls] = OCCURRENCE_BADGES[$m['status']];
                $open = $m['session'] && (int) $m['session']['is_active'] === 1;
                $reopenable = $m['session'] && !$open && $m['status'] === OCCURRENCE_ACTIVE;
            ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo e(format_time_range($o['start_time'], $o['end_time'])); ?></td>
                    <td><?php echo e($o['subject_code'] . ' - ' . $o['subject_name']); ?><div class="text-muted" style="font-size:11.5px"><?php echo e($o['section']); ?></div></td>
                    <td><?php echo e($o['teacher_name']); ?><?php if ($o['teacher_id'] !== $o['original_teacher_id']): ?><div class="text-muted" style="font-size:11px">Substitute</div><?php endif; ?></td>
                    <td><?php echo e($o['lab_name']); ?><?php if (empty($labCoords[$o['lab_id']])): ?><div style="font-size:11px;color:var(--red-600)"><i class="fa-solid fa-triangle-exclamation"></i> No GPS set</div><?php endif; ?></td>
                    <td><span class="badge <?php echo $cls; ?>"><?php echo $label; ?></span><?php if ($o['is_rescheduled']): ?> <span class="badge badge-rescheduled">Rescheduled</span><?php endif; ?></td>
                    <td style="font-size:12.5px"><?php echo e(attendance_state_label($m)); ?></td>
                    <td>
                        <?php if ($open): ?>
                            <button class="btn btn-danger btn-sm" onclick="closeSession(<?php echo (int) $m['session']['session_id']; ?>, <?php echo e(json_encode($o['subject_code'] . ' (' . $o['section'] . ')')); ?>)"><i class="fa-solid fa-stop"></i> Close</button>
                        <?php elseif ($reopenable): ?>
                            <button class="btn btn-success btn-sm" onclick="reopenSession(<?php echo (int) $m['session']['session_id']; ?>, <?php echo e(json_encode($o['subject_code'] . ' (' . $o['section'] . ')')); ?>)"><i class="fa-solid fa-play"></i> Reopen</button>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>Open Sessions (<?php echo count($openMeetings); ?>)</h3></div>
    <div class="card-body">
        <?php if (!$openMeetings): ?>
            <div class="empty-state"><i class="fa-solid fa-qrcode"></i><p>No attendance is open right now.</p></div>
        <?php else: ?>
        <div class="grid-3">
            <?php foreach ($openMeetings as $m): $o = $m['occurrence']; $s = $m['session']; ?>
            <div class="card">
                <div class="card-body text-center">
                    <div id="qr-<?php echo (int) $s['session_id']; ?>" style="display:inline-block;padding:10px;background:#fff;border-radius:8px;border:1px solid #e2e8f0"></div>
                    <h4 style="margin:14px 0 4px"><?php echo e($o['subject_code'] . ' - ' . $o['subject_name']); ?></h4>
                    <p class="text-muted" style="font-size:13px;margin:0"><?php echo e($o['teacher_name']); ?> · <?php echo e($o['lab_name']); ?></p>
                    <p class="text-muted" style="font-size:12px;margin:4px 0 14px">Radius: <?php echo (int) $s['allowed_radius_meters']; ?>m · Expires: <?php echo date('g:i A', strtotime($s['session_end'])); ?></p>
                    <button class="btn btn-danger btn-sm" onclick="closeSession(<?php echo (int) $s['session_id']; ?>, <?php echo e(json_encode($o['subject_code'] . ' (' . $o['section'] . ')')); ?>)"><i class="fa-solid fa-stop"></i> Close</button>
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
            <thead><tr><th>Subject</th><th>Teacher</th><th>Date</th><th>Time</th><th>Scans</th><th>Closed At</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($recentSessions)): ?>
                <tr><td colspan="7" class="text-center text-muted">No closed sessions yet.</td></tr>
            <?php else: foreach ($recentSessions as $s): ?>
                <tr>
                    <td><?php echo e($s['subject_code'] . ' - ' . $s['subject_name']); ?></td>
                    <td><?php echo e($s['teacher_name']); ?></td>
                    <td><?php echo format_date($s['session_date']); ?></td>
                    <td><?php echo e(format_time_range(substr($s['scheduled_start'], 11, 8), substr($s['session_end'], 11, 8))); ?></td>
                    <td><?php echo (int) $s['scans']; ?></td>
                    <td><?php echo format_datetime($s['deactivated_at']); ?></td>
                    <td>
                        <?php if ($s['can_reopen']): ?>
                            <button class="btn btn-success btn-sm" onclick="reopenSession(<?php echo (int) $s['session_id']; ?>, <?php echo e(json_encode($s['subject_code'])); ?>)"><i class="fa-solid fa-play"></i> Reopen</button>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const OPEN_SESSION_QRS = <?php echo json_encode(array_map(fn($m) => ['id' => (int) $m['session']['session_id'], 'payload' => qr_build_session_payload($m['session']['session_id'], $m['session']['qr_token'])], $openMeetings)); ?>;
const RELOAD_IN = <?php echo $reloadIn !== null ? (int) $reloadIn : 'null'; ?>; // seconds until the next class starts or ends

document.addEventListener('DOMContentLoaded', () => {
    OPEN_SESSION_QRS.forEach(s => {
        const el = document.getElementById('qr-' + s.id);
        if (el) safeRenderQr(el, s.payload, 160);
    });
    if (RELOAD_IN !== null && RELOAD_IN < 86400) setTimeout(() => location.reload(), (RELOAD_IN + 2) * 1000);
});

async function closeSession(sessionId, label) {
    if (!confirm('Close attendance for ' + label + ' now? Students will no longer be able to check in until it is reopened.')) return;
    const res = await ajaxPost('ajax_qr_deactivate.php', { session_id: sessionId });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 500); }
    else showToast('error', res.message);
}

async function reopenSession(sessionId, label) {
    if (!confirm('Reopen attendance for ' + label + '? The same QR code will work again until the class ends.')) return;
    const res = await ajaxPost('ajax_qr_reactivate.php', { session_id: sessionId });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 500); }
    else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
