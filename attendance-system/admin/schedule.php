<?php
/**
 * admin/schedule.php — "Class Schedule"
 * Day / week (default) / month calendar of every class meeting, generated
 * from the weekly rules + exceptions by includes/schedule.php (nothing here
 * computes meetings itself). Filters: program, section, teacher, laboratory,
 * subject. Clicking a meeting opens its details, where the admin can cancel
 * or reschedule that one date (admin/ajax_schedule_exceptions.php) or undo a
 * previous change.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('admin');
$pageTitle = 'Class Schedule';

$now = schedule_now();
$today = $now->format('Y-m-d');

$view = in_array($_GET['view'] ?? '', ['day', 'week', 'month'], true) ? $_GET['view'] : 'week';
try {
    $anchor = schedule_date($_GET['date'] ?? $today);
} catch (InvalidArgumentException $e) {
    $anchor = schedule_date($today);
}

$filters = [
    'program_id' => (int) ($_GET['program_id'] ?? 0),
    'section'    => trim(clean($_GET['section'] ?? '')),
    'teacher_id' => (int) ($_GET['teacher_id'] ?? 0),
    'lab_id'     => (int) ($_GET['lab_id'] ?? 0),
    'subject_id' => (int) ($_GET['subject_id'] ?? 0),
];
$hasFilters = (bool) array_filter($filters);

// ---- Visible range for the chosen view ----
if ($view === 'day') {
    $from = $to = $anchor;
    $prev = $anchor->modify('-1 day');
    $next = $anchor->modify('+1 day');
    $title = $anchor->format('l, F j, Y');
} elseif ($view === 'week') {
    $from = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
    $to = $from->modify('+6 days');
    $prev = $anchor->modify('-7 days');
    $next = $anchor->modify('+7 days');
    $title = $from->format('Y') !== $to->format('Y') ? $from->format('M j, Y') . ' – ' . $to->format('M j, Y')
        : ($from->format('M') !== $to->format('M') ? $from->format('M j') . ' – ' . $to->format('M j, Y') : $from->format('M j') . ' – ' . $to->format('j, Y'));
} else {
    $first = $anchor->modify('first day of this month');
    $last = $anchor->modify('last day of this month');
    $from = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');   // grid starts on Monday
    $to = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');      // and ends on Sunday
    $prev = $first->modify('-1 month');
    $next = $first->modify('+1 month');
    $title = $anchor->format('F Y');
}

$occurrences = get_occurrences($pdo, $from->format('Y-m-d'), $to->format('Y-m-d'), $filters);
$byDate = [];
foreach ($occurrences as &$o) {
    $o['status'] = get_occurrence_status($o, $now);
    $byDate[$o['date']][] = $o;
}
unset($o);

$programs = $pdo->query("SELECT program_id, program_code FROM programs WHERE status = 'active' ORDER BY program_code")->fetchAll();
$sections = $pdo->query("SELECT DISTINCT section FROM teacher_subjects WHERE status = 'active' AND section IS NOT NULL ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
$teachers = $pdo->query('SELECT teacher_id, full_name FROM teachers ORDER BY full_name')->fetchAll();
$labs = $pdo->query("SELECT lab_id, lab_name FROM laboratories WHERE status = 'active' ORDER BY lab_name")->fetchAll();
$subjects = $pdo->query("SELECT subject_id, subject_code, subject_name FROM subjects WHERE status = 'active' ORDER BY subject_code")->fetchAll();

/** This page's URL with some parameters changed (filters, view and date are kept). */
function schedule_url(array $changes = []) {
    global $view, $anchor, $filters;
    $q = array_merge(['view' => $view, 'date' => $anchor->format('Y-m-d')], array_filter($filters), $changes);
    return 'schedule.php?' . http_build_query(array_filter($q, fn($v) => $v !== null && $v !== '' && $v !== 0));
}

const STATUS_BADGES = [
    OCCURRENCE_UPCOMING  => ['Upcoming',  'badge-upcoming'],
    OCCURRENCE_ACTIVE    => ['Active',    'badge-active'],
    OCCURRENCE_EXPIRED   => ['Expired',   'badge-inactive'],
    OCCURRENCE_CANCELLED => ['Cancelled', 'badge-absent'],
];

/** Status badge, plus a RESCHEDULED badge for a moved meeting. */
function occurrence_badges(array $o) {
    [$label, $class] = STATUS_BADGES[$o['status']];
    $html = '<span class="badge ' . $class . '">' . $label . '</span>';
    if ($o['is_rescheduled']) $html .= ' <span class="badge badge-rescheduled">Rescheduled</span>';
    return $html;
}

/** A clickable meeting card ($compact for the month grid). */
function render_event(array $o, $compact = false) {
    $time = format_time_range($o['start_time'], $o['end_time']);
    $cls = 'cal-event status-' . strtolower($o['status']) . ($o['is_rescheduled'] ? ' is-rescheduled' : '');
    $attr = 'type="button" class="' . $cls . '" data-occ="' . e(json_encode($o)) . '" onclick="openMeeting(this)"';
    if ($compact) {
        return '<button ' . $attr . ' title="' . e($o['subject_code'] . ' · ' . $o['section'] . ' · ' . $time) . '">'
            . '<span class="cal-event-time">' . e(DateTimeImmutable::createFromFormat('H:i:s', $o['start_time'])->format('g:i A')) . '</span> ' . e($o['subject_code']) . '</button>';
    }
    return '<button ' . $attr . '>'
        . '<div class="cal-event-time">' . e($time) . '</div>'
        . '<div class="cal-event-title">' . e($o['subject_code'] . ' · ' . $o['section']) . '</div>'
        . '<div class="cal-event-meta">' . e($o['lab_name']) . '</div>'
        . '<div class="cal-event-meta">' . e($o['teacher_name']) . '</div>'
        . '<div class="cal-event-badges">' . occurrence_badges($o) . '</div>'
        . '</button>';
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header cal-header">
        <div class="cal-nav">
            <a class="btn btn-outline btn-sm" href="<?php echo e(schedule_url(['date' => $prev->format('Y-m-d')])); ?>" title="Previous" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></a>
            <a class="btn btn-outline btn-sm" href="<?php echo e(schedule_url(['date' => $today])); ?>">Today</a>
            <a class="btn btn-outline btn-sm" href="<?php echo e(schedule_url(['date' => $next->format('Y-m-d')])); ?>" title="Next" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></a>
            <h3><?php echo e($title); ?></h3>
        </div>
        <div class="seg" role="group" aria-label="Calendar view">
            <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $v => $label): ?>
                <a href="<?php echo e(schedule_url(['view' => $v])); ?>" class="<?php echo $view === $v ? 'active' : ''; ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar cal-filters">
            <input type="hidden" name="view" value="<?php echo e($view); ?>">
            <input type="hidden" name="date" value="<?php echo e($anchor->format('Y-m-d')); ?>">
            <select name="program_id" class="form-control" onchange="this.form.submit()" aria-label="Program">
                <option value="">All programs</option>
                <?php foreach ($programs as $pr): ?><option value="<?php echo $pr['program_id']; ?>" <?php echo $filters['program_id'] === (int) $pr['program_id'] ? 'selected' : ''; ?>><?php echo e($pr['program_code']); ?></option><?php endforeach; ?>
            </select>
            <select name="section" class="form-control" onchange="this.form.submit()" aria-label="Section">
                <option value="">All sections</option>
                <?php foreach ($sections as $sec): ?><option value="<?php echo e($sec); ?>" <?php echo strcasecmp($filters['section'], $sec) === 0 ? 'selected' : ''; ?>><?php echo e($sec); ?></option><?php endforeach; ?>
            </select>
            <select name="teacher_id" class="form-control" onchange="this.form.submit()" aria-label="Teacher">
                <option value="">All teachers</option>
                <?php foreach ($teachers as $t): ?><option value="<?php echo $t['teacher_id']; ?>" <?php echo $filters['teacher_id'] === (int) $t['teacher_id'] ? 'selected' : ''; ?>><?php echo e($t['full_name']); ?></option><?php endforeach; ?>
            </select>
            <select name="lab_id" class="form-control" onchange="this.form.submit()" aria-label="Laboratory">
                <option value="">All laboratories</option>
                <?php foreach ($labs as $l): ?><option value="<?php echo $l['lab_id']; ?>" <?php echo $filters['lab_id'] === (int) $l['lab_id'] ? 'selected' : ''; ?>><?php echo e($l['lab_name']); ?></option><?php endforeach; ?>
            </select>
            <select name="subject_id" class="form-control" onchange="this.form.submit()" aria-label="Subject">
                <option value="">All subjects</option>
                <?php foreach ($subjects as $s): ?><option value="<?php echo $s['subject_id']; ?>" <?php echo $filters['subject_id'] === (int) $s['subject_id'] ? 'selected' : ''; ?>><?php echo e($s['subject_code']); ?></option><?php endforeach; ?>
            </select>
            <?php if ($hasFilters): ?><a href="schedule.php?<?php echo e(http_build_query(['view' => $view, 'date' => $anchor->format('Y-m-d')])); ?>" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
            <noscript><button class="btn btn-outline btn-sm" type="submit">Apply</button></noscript>
        </form>

        <div class="cal-legend">
            <?php foreach (STATUS_BADGES as [$label, $class]): ?><span class="badge <?php echo $class; ?>"><?php echo $label; ?></span><?php endforeach; ?>
            <span class="badge badge-rescheduled">Rescheduled</span>
            <span class="text-muted" style="font-size:12px">Click a class to cancel or reschedule that date.</span>
        </div>

        <?php if (!$occurrences): ?>
            <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i><p>No classes <?php echo $view === 'day' ? 'on this day' : 'in this ' . $view; ?><?php echo $hasFilters ? ' for the selected filters' : ''; ?>.</p></div>

        <?php elseif ($view === 'day'): ?>
            <div class="cal-day">
                <?php foreach ($byDate[$from->format('Y-m-d')] ?? [] as $o) echo render_event($o); ?>
            </div>

        <?php elseif ($view === 'week'): ?>
            <div class="cal-week">
                <?php for ($d = $from; $d <= $to; $d = $d->modify('+1 day')): $ds = $d->format('Y-m-d'); ?>
                    <div class="cal-col<?php echo $ds === $today ? ' is-today' : ''; ?>">
                        <a class="cal-col-head" href="<?php echo e(schedule_url(['view' => 'day', 'date' => $ds])); ?>">
                            <span><?php echo $d->format('D'); ?></span> <strong><?php echo $d->format('j'); ?></strong>
                        </a>
                        <?php if (empty($byDate[$ds])): ?><div class="cal-none">No classes</div><?php endif; ?>
                        <?php foreach ($byDate[$ds] ?? [] as $o) echo render_event($o); ?>
                    </div>
                <?php endfor; ?>
            </div>

        <?php else: ?>
            <div class="cal-month">
                <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $wd): ?><div class="cal-month-head"><?php echo $wd; ?></div><?php endforeach; ?>
                <?php for ($d = $from; $d <= $to; $d = $d->modify('+1 day')):
                    $ds = $d->format('Y-m-d');
                    $events = $byDate[$ds] ?? [];
                    $outside = $d->format('m') !== $anchor->format('m');
                ?>
                    <div class="cal-cell<?php echo $outside ? ' is-outside' : ''; ?><?php echo $ds === $today ? ' is-today' : ''; ?>">
                        <a class="cal-cell-day" href="<?php echo e(schedule_url(['view' => 'day', 'date' => $ds])); ?>"><?php echo $d->format('j'); ?></a>
                        <?php foreach (array_slice($events, 0, 3) as $o) echo render_event($o, true); ?>
                        <?php if (count($events) > 3): ?><a class="cal-more" href="<?php echo e(schedule_url(['view' => 'day', 'date' => $ds])); ?>">+<?php echo count($events) - 3; ?> more</a><?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===================== MEETING MODAL ===================== -->
<div class="modal-backdrop" id="meetingModal">
    <div class="modal">
        <div class="modal-header"><h3 id="mTitle">Class Meeting</h3><button class="modal-close" onclick="closeModal('meetingModal')" aria-label="Close">&times;</button></div>
        <div class="modal-body">
            <div id="mBadges" style="margin-bottom:10px"></div>
            <dl class="meeting-details">
                <dt>Subject</dt><dd id="mSubject"></dd>
                <dt>Class</dt><dd id="mClass"></dd>
                <dt>Date &amp; time</dt><dd id="mWhen"></dd>
                <dt>Laboratory</dt><dd id="mLab"></dd>
                <dt>Teacher</dt><dd id="mTeacher"></dd>
                <dt id="mOrigLabel">Originally</dt><dd id="mOrig"></dd>
                <dt id="mReasonLabel">Reason</dt><dd id="mReason"></dd>
            </dl>

            <div class="meeting-actions" id="mActions">
                <button type="button" class="btn btn-outline btn-sm" id="btnShowCancel" onclick="showPanel('cancel')"><i class="fa-solid fa-ban"></i> Cancel this date</button>
                <button type="button" class="btn btn-outline btn-sm" id="btnShowReschedule" onclick="showPanel('reschedule')"><i class="fa-solid fa-calendar-plus"></i> Reschedule</button>
                <button type="button" class="btn btn-outline btn-sm" id="btnRestore" onclick="restoreMeeting()"><i class="fa-solid fa-rotate-left"></i> Restore usual schedule</button>
            </div>

            <form id="cancelPanel" class="meeting-panel" onsubmit="submitCancel(event)">
                <div class="form-group"><label for="cReason">Reason for cancelling *</label>
                    <textarea id="cReason" class="form-control" rows="2" maxlength="500" required placeholder="e.g. Holiday, teacher on leave"></textarea></div>
                <button type="submit" class="btn btn-danger btn-sm" id="cSubmit">Cancel meeting</button>
            </form>

            <form id="reschedulePanel" class="meeting-panel" onsubmit="submitReschedule(event)">
                <div class="form-group"><label for="rDate">New date *</label><input type="date" id="rDate" class="form-control" required min="<?php echo e($today); ?>"></div>
                <div class="form-row">
                    <div class="form-group"><label for="rStart">Start time *</label><input type="time" id="rStart" class="form-control" required></div>
                    <div class="form-group"><label for="rEnd">End time *</label><input type="time" id="rEnd" class="form-control" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="rLab">Laboratory</label>
                        <select id="rLab" class="form-control"><option value="">Usual laboratory</option>
                            <?php foreach ($labs as $l): ?><option value="<?php echo $l['lab_id']; ?>"><?php echo e($l['lab_name']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label for="rTeacher">Teacher</label>
                        <select id="rTeacher" class="form-control"><option value="">Usual teacher</option>
                            <?php foreach ($teachers as $t): ?><option value="<?php echo $t['teacher_id']; ?>"><?php echo e($t['full_name']); ?></option><?php endforeach; ?>
                        </select></div>
                </div>
                <div class="form-group"><label for="rReason">Reason (optional)</label><input type="text" id="rReason" class="form-control" maxlength="500" placeholder="e.g. Make-up class"></div>
                <button type="submit" class="btn btn-primary btn-sm" id="rSubmit">Reschedule meeting</button>
            </form>
        </div>
    </div>
</div>

<script>
let currentMeeting = null;

function fmtDate(ymd) {
    return new Date(ymd + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}
function fmtTime(hms) {
    const [h, m] = hms.split(':').map(Number);
    return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM');
}
function meetingLabel(o) {
    return o.subject_code + ' (' + o.section + ') on ' + fmtDate(o.date) + ', ' + fmtTime(o.start_time) + '–' + fmtTime(o.end_time);
}
function badge(text, cls) {
    const b = document.createElement('span');
    b.className = 'badge ' + cls; b.textContent = text; b.style.marginRight = '4px';
    return b;
}
const STATUS_BADGES = <?php echo json_encode(STATUS_BADGES); ?>;

function openMeeting(el) {
    const o = JSON.parse(el.dataset.occ);
    currentMeeting = o;
    document.getElementById('mTitle').textContent = o.subject_code + ' · ' + o.section;
    const badges = document.getElementById('mBadges');
    badges.innerHTML = '';
    badges.appendChild(badge(STATUS_BADGES[o.status][0], STATUS_BADGES[o.status][1]));
    if (o.is_rescheduled) badges.appendChild(badge('Rescheduled', 'badge-rescheduled'));
    document.getElementById('mSubject').textContent = o.subject_code + ' – ' + o.subject_name;
    document.getElementById('mClass').textContent = [o.program_code, o.year_level ? 'Year ' + o.year_level : null, o.section].filter(Boolean).join(' · ');
    document.getElementById('mWhen').textContent = fmtDate(o.date) + ', ' + fmtTime(o.start_time) + '–' + fmtTime(o.end_time);
    document.getElementById('mLab').textContent = o.lab_name;
    document.getElementById('mTeacher').textContent = o.teacher_name + (o.teacher_id !== o.original_teacher_id ? ' (substitute)' : '');

    const moved = o.is_rescheduled;
    document.getElementById('mOrig').textContent = moved ? fmtDate(o.original_date) + ', ' + fmtTime(o.original_start_time) + '–' + fmtTime(o.original_end_time) : '';
    document.getElementById('mOrig').hidden = document.getElementById('mOrigLabel').hidden = !moved;
    document.getElementById('mReason').textContent = o.exception_reason || '';
    document.getElementById('mReason').hidden = document.getElementById('mReasonLabel').hidden = !o.exception_reason;

    // Past meetings can still get a make-up (reschedule) or be restored, but not cancelled.
    const changed = o.is_cancelled || o.is_rescheduled;
    document.getElementById('btnShowCancel').hidden = o.is_cancelled || o.status === 'EXPIRED';
    document.getElementById('btnShowReschedule').hidden = false;
    document.getElementById('btnRestore').hidden = !changed;

    document.getElementById('cReason').value = '';
    document.getElementById('rDate').value = o.date >= '<?php echo e($today); ?>' ? o.date : '';
    document.getElementById('rStart').value = o.start_time.substring(0, 5);
    document.getElementById('rEnd').value = o.end_time.substring(0, 5);
    document.getElementById('rLab').value = o.lab_id !== o.original_lab_id ? o.lab_id : '';
    document.getElementById('rTeacher').value = o.teacher_id !== o.original_teacher_id ? o.teacher_id : '';
    document.getElementById('rReason').value = o.is_rescheduled ? (o.exception_reason || '') : '';
    showPanel(null);
    openModal('meetingModal');
}
function showPanel(which) {
    document.getElementById('cancelPanel').style.display = which === 'cancel' ? 'block' : 'none';
    document.getElementById('reschedulePanel').style.display = which === 'reschedule' ? 'block' : 'none';
}
async function sendException(data, btn, busyText) {
    const original = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> ' + busyText;
    data.teacher_subject_id = currentMeeting.teacher_subject_id;
    data.original_date = currentMeeting.original_date;
    let res;
    try { res = await ajaxPost('ajax_schedule_exceptions.php', data); }
    catch (err) { res = { success: false, message: err.message }; }
    if (res.success) { showToast('success', res.message); closeModal('meetingModal'); setTimeout(() => location.reload(), 700); }
    else { showToast('error', res.message); btn.disabled = false; btn.innerHTML = original; }
}
function submitCancel(e) {
    e.preventDefault();
    const reason = document.getElementById('cReason').value.trim();
    if (!reason) { showToast('error', 'Please give a reason for the cancellation.'); return; }
    if (!confirm('Cancel ' + meetingLabel(currentMeeting) + '?\n\nReason: ' + reason + '\n\nEnrolled students and the teacher will be notified. The weekly schedule is not changed.')) return;
    sendException({ action: 'cancel', reason }, document.getElementById('cSubmit'), 'Cancelling...');
}
function submitReschedule(e) {
    e.preventDefault();
    const d = document.getElementById('rDate').value, s = document.getElementById('rStart').value, en = document.getElementById('rEnd').value;
    if (!d || !s || !en) { showToast('error', 'Enter the new date, start time and end time.'); return; }
    if (en <= s) { showToast('error', 'End time must be after start time.'); return; }
    const lab = document.getElementById('rLab'), teacher = document.getElementById('rTeacher');
    const extras = [lab.value ? 'in ' + lab.options[lab.selectedIndex].text : '', teacher.value ? 'with ' + teacher.options[teacher.selectedIndex].text : ''].filter(Boolean).join(' ');
    if (!confirm('Move ' + meetingLabel(currentMeeting) + '\nto ' + fmtDate(d) + ', ' + fmtTime(s + ':00') + '–' + fmtTime(en + ':00') + (extras ? ' ' + extras : '') + '?\n\nEnrolled students and the teacher will be notified. The weekly schedule is not changed.')) return;
    sendException({ action: 'reschedule', new_date: d, new_start_time: s, new_end_time: en, new_lab_id: lab.value, new_teacher_id: teacher.value,
        reason: document.getElementById('rReason').value.trim() }, document.getElementById('rSubmit'), 'Saving...');
}
function restoreMeeting() {
    const o = currentMeeting;
    if (!confirm('Undo the change to ' + o.subject_code + ' (' + o.section + ') and restore its usual meeting on ' + fmtDate(o.original_date) + ', '
        + fmtTime(o.original_start_time) + '–' + fmtTime(o.original_end_time) + '?')) return;
    sendException({ action: 'restore' }, document.getElementById('btnRestore'), 'Restoring...');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
