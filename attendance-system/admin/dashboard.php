<?php
/**
 * admin/dashboard.php
 * Overview stats + attendance analytics charts for the administrator,
 * plus a live "Today's Classes" card (refreshed every 30 seconds from
 * admin/ajax_todays_classes.php; both read includes/schedule.php).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule.php';
require_role('admin');

$pageTitle = 'Dashboard';

auto_expire_sessions($pdo);

// ---- Stat cards (row 1: totals, row 2: today's activity) ----
$totalStudents = $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$totalTeachers = $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$totalLabs = $pdo->query('SELECT COUNT(*) FROM laboratories')->fetchColumn();
$activeSessions = $pdo->query('SELECT COUNT(*) FROM attendance_sessions WHERE is_active = 1')->fetchColumn();
$todayPresent = $pdo->query('SELECT COUNT(*) FROM attendance_records WHERE DATE(time_in) = CURDATE() AND status = "Present"')->fetchColumn();
$todayLate = $pdo->query('SELECT COUNT(*) FROM attendance_records WHERE DATE(time_in) = CURDATE() AND status = "Late"')->fetchColumn();
$todayTotal = $pdo->query('SELECT COUNT(*) FROM attendance_records WHERE DATE(time_in) = CURDATE() AND status <> "Absent"')->fetchColumn();
$pendingRequests = $pdo->query('SELECT COUNT(*) FROM enrollment_requests WHERE status = "pending"')->fetchColumn();
$missingGeoLabs = $pdo->query('SELECT COUNT(*) FROM laboratories WHERE latitude IS NULL OR longitude IS NULL')->fetchColumn();

// ---- Laboratory live status (Active = has a session running right now) ----
$labStatus = $pdo->query('
    SELECT l.lab_id, l.lab_name,
        EXISTS(
            SELECT 1 FROM attendance_sessions s
            JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
            WHERE ts.lab_id = l.lab_id AND s.is_active = 1
        ) AS is_live
    FROM laboratories l ORDER BY l.lab_name
')->fetchAll();

// ---- Attendance trend (last 7 days) ----
$trendStmt = $pdo->query('
    SELECT DATE(time_in) AS d,
           SUM(status = "Present") AS present,
           SUM(status = "Late") AS late,
           SUM(status = "Absent") AS absent
    FROM attendance_records
    WHERE time_in >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(time_in)
    ORDER BY d ASC
');
$trend = $trendStmt->fetchAll();
$trendLabels = []; $trendPresent = []; $trendLate = []; $trendAbsent = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $trendLabels[] = date('M d', strtotime($d));
    $found = null;
    foreach ($trend as $row) if ($row['d'] === $d) $found = $row;
    $trendPresent[] = $found ? (int) $found['present'] : 0;
    $trendLate[] = $found ? (int) $found['late'] : 0;
    $trendAbsent[] = $found ? (int) $found['absent'] : 0;
}

// ---- Status distribution (all-time) ----
$statusStmt = $pdo->query('SELECT status, COUNT(*) c FROM attendance_records GROUP BY status');
$statusCounts = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
foreach ($statusStmt->fetchAll() as $row) $statusCounts[$row['status']] = (int) $row['c'];

// ---- Laboratory usage (today) ----
$labUsage = $pdo->query('
    SELECT l.lab_name, COUNT(ar.record_id) AS cnt
    FROM laboratories l
    LEFT JOIN teacher_subjects ts ON ts.lab_id = l.lab_id
    LEFT JOIN attendance_sessions s ON s.teacher_subject_id = ts.teacher_subject_id AND DATE(s.session_date) = CURDATE()
    LEFT JOIN attendance_records ar ON ar.session_id = s.session_id AND ar.status <> "Absent"
    GROUP BY l.lab_id ORDER BY l.lab_name
')->fetchAll();

// ---- Today's classes (initial data; the card then polls ajax_todays_classes.php) ----
$todaysClasses = todays_classes_payload($pdo);

// ---- Recent activity ----
$recent = $pdo->query('
    SELECT ar.time_in, ar.status, ar.marked_by_user_id, st.full_name, sub.subject_name, lab.lab_name
    FROM attendance_records ar
    JOIN students st ON st.student_id = ar.student_id
    JOIN attendance_sessions ses ON ses.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = ses.teacher_subject_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    ORDER BY ar.time_in DESC LIMIT 8
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($pendingRequests > 0 || $missingGeoLabs > 0): ?>
<div class="grid-2" style="margin-bottom:20px">
    <?php if ($pendingRequests > 0): ?>
    <a href="enrollment_requests.php" class="alert alert-info" style="margin-bottom:0;text-decoration:none">
        <i class="fa-solid fa-envelope-open-text"></i> <?php echo (int) $pendingRequests; ?> enrollment request(s) awaiting teacher review.
    </a>
    <?php endif; ?>
    <?php if ($missingGeoLabs > 0): ?>
    <a href="laboratories.php" class="alert alert-error" style="margin-bottom:0;text-decoration:none">
        <i class="fa-solid fa-location-crosshairs"></i> <?php echo (int) $missingGeoLabs; ?> laboratory(ies) missing GPS coordinates — QR sessions can't be activated for them yet.
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<h3 style="margin:0 0 12px;font-size:14px;color:var(--slate-600)">Dashboard Cards</h3>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-label">Total Students</span><div class="stat-icon blue"><i class="fa-solid fa-user-graduate"></i></div></div>
        <div class="stat-value"><?php echo number_format($totalStudents); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-label">Total Teachers</span><div class="stat-icon blue"><i class="fa-solid fa-chalkboard-user"></i></div></div>
        <div class="stat-value"><?php echo number_format($totalTeachers); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-label">Total Laboratories</span><div class="stat-icon blue"><i class="fa-solid fa-flask"></i></div></div>
        <div class="stat-value"><?php echo number_format($totalLabs); ?></div>
    </div>
</div>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-label">Students Present Today</span><div class="stat-icon green"><i class="fa-solid fa-user-check"></i></div></div>
        <div class="stat-value"><?php echo number_format($todayPresent); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-label">Students Late Today</span><div class="stat-icon amber"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
        <div class="stat-value warn"><?php echo number_format($todayLate); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-label">Total Attendance Today</span><div class="stat-icon blue"><i class="fa-solid fa-list-check"></i></div></div>
        <div class="stat-value"><?php echo number_format($todayTotal); ?></div>
    </div>
</div>

<div class="card" id="todaysClassesCard" style="margin-bottom:20px">
    <div class="card-header">
        <div>
            <h3>Today's Classes</h3>
            <div class="text-muted" style="font-size:12px;margin-top:2px"><span id="tcDate"></span> · <span id="tcSummary"></span></div>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <span class="text-muted" id="tcUpdated" style="font-size:11.5px" aria-live="polite"></span>
            <a href="schedule.php?view=day" class="btn btn-outline btn-sm"><i class="fa-solid fa-calendar-day"></i> Schedule</a>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Time</th><th>Subject</th><th>Teacher</th><th>Section</th><th>Laboratory</th><th>Status</th></tr></thead>
            <tbody id="tcBody"></tbody>
        </table>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Attendance Graph</h3></div>
        <div class="card-body"><div class="chart-box"><canvas id="trendChart"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Recent Attendance Activity</h3></div>
        <div class="card-body" style="padding:10px 22px">
            <?php if (empty($recent)): ?>
                <p class="text-muted text-center" style="padding:20px 0">No attendance records yet.</p>
            <?php else: foreach ($recent as $r): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--slate-100);font-size:13px">
                    <span class="dot <?php echo ['Late' => 'dot-amber', 'Absent' => 'dot-red'][$r['status']] ?? 'dot-green'; ?>" style="width:8px;height:8px;border-radius:50%;flex-shrink:0"></span>
                    <?php if ($r['status'] === 'Absent'): ?>
                    <span style="flex:1"><?php echo e($r['full_name']); ?> was absent from <?php echo e($r['subject_name']); ?> · <?php echo date('M d', strtotime($r['time_in'])); ?></span>
                    <?php else: ?>
                    <span style="flex:1"><?php echo e($r['full_name']); ?> <?php echo !empty($r['marked_by_user_id']) ? 'was marked present by the teacher at' : 'checked in at'; ?> <?php echo e($r['lab_name']); ?> · <?php echo date('h:i A', strtotime($r['time_in'])); ?></span>
                    <?php endif; ?>
                    <span class="badge badge-<?php echo strtolower($r['status']); ?>"><?php echo $r['status']; ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>Laboratory Status</h3></div>
    <div class="card-body">
        <div class="lab-legend">
            <span><span class="dot dot-green"></span> Active (session running)</span>
            <span><span class="dot dot-red"></span> Inactive</span>
        </div>
        <div class="lab-status-wrap">
            <?php foreach ($labStatus as $l): ?>
                <div class="lab-status-pill">
                    <div class="lab-pill-name"><?php echo e($l['lab_name']); ?></div>
                    <span class="badge badge-<?php echo $l['is_live'] ? 'active' : 'inactive'; ?>">
                        <span class="dot <?php echo $l['is_live'] ? 'dot-green' : 'dot-red'; ?>" style="width:6px;height:6px;border-radius:50%"></span>
                        <?php echo $l['is_live'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-top:20px">
    <div class="card">
        <div class="card-header"><h3>Overall Status Distribution</h3></div>
        <div class="card-body"><div class="chart-box"><canvas id="statusChart"></canvas></div><p class="text-muted text-center chart-empty" hidden>No attendance records yet.</p></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Laboratory Usage Today</h3></div>
        <div class="card-body"><div class="chart-box"><canvas id="labChart"></canvas></div></div>
    </div>
</div>

<script>
// ---- Today's Classes: render, then re-fetch every 30 s so statuses update without a reload ----
(function () {
    const REFRESH_MS = 30000;
    const BADGES = {
        UPCOMING:  ['Upcoming',  'badge-upcoming'],
        ACTIVE:    ['Active',    'badge-active'],
        EXPIRED:   ['Expired',   'badge-inactive'],
        CANCELLED: ['Cancelled', 'badge-absent'],
    };
    const body = document.getElementById('tcBody');

    function cell(text, sub) {
        const td = document.createElement('td');
        td.textContent = text;
        if (sub) {
            const small = document.createElement('div');
            small.className = 'text-muted'; small.style.fontSize = '11px'; small.textContent = sub;
            td.appendChild(small);
        }
        return td;
    }
    function badge(label, cls) {
        const b = document.createElement('span');
        b.className = 'badge ' + cls; b.textContent = label; b.style.marginRight = '4px';
        return b;
    }

    function render(data) {
        document.getElementById('tcDate').textContent = data.date_label;
        const c = data.counts, total = data.classes.length;
        document.getElementById('tcSummary').textContent = total === 0 ? 'no classes scheduled'
            : total + (total === 1 ? ' class' : ' classes') + ' · ' + c.ACTIVE + ' active · ' + c.UPCOMING + ' upcoming'
              + (c.CANCELLED ? ' · ' + c.CANCELLED + ' cancelled' : '');

        body.replaceChildren();
        if (total === 0) {
            const tr = document.createElement('tr'), td = document.createElement('td');
            td.colSpan = 6; td.className = 'text-center text-muted'; td.textContent = 'No classes scheduled today.';
            tr.appendChild(td); body.appendChild(tr);
            return;
        }
        for (const o of data.classes) {
            const tr = document.createElement('tr');
            if (o.status === 'CANCELLED') tr.className = 'tc-cancelled';
            if (o.status === 'ACTIVE') tr.className = 'tc-active';
            tr.append(
                cell(o.time),
                cell(o.subject_code, o.subject_name),
                cell(o.teacher, o.substitute ? 'Substitute' : ''),
                cell(o.section),
                cell(o.lab)
            );
            const st = document.createElement('td');
            st.appendChild(badge(...BADGES[o.status]));
            if (o.rescheduled) st.appendChild(badge('Rescheduled', 'badge-rescheduled'));
            if (o.reason && (o.status === 'CANCELLED' || o.rescheduled)) st.title = o.reason;
            tr.appendChild(st);
            body.appendChild(tr);
        }
    }

    function stamp(ok) {
        const t = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        document.getElementById('tcUpdated').textContent = ok ? 'Updated ' + t : 'Could not refresh (' + t + ') — retrying';
    }

    async function refresh() {
        try {
            const res = await fetch('ajax_todays_classes.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const data = await res.json();          // throws if the session expired (login page HTML)
            if (!data.success) throw new Error(data.message);
            render(data); stamp(true);
        } catch (err) {
            stamp(false);                            // keep showing the last good data
        }
    }

    render(<?php echo json_encode($todaysClasses); ?>);
    stamp(true);
    setInterval(() => { if (!document.hidden) refresh(); }, REFRESH_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// Charts take their colours from the active theme (assets/js/theme.js) and are rebuilt when it changes.
const trendData = {
    labels: <?php echo json_encode($trendLabels); ?>,
    present: <?php echo json_encode($trendPresent); ?>,
    late: <?php echo json_encode($trendLate); ?>,
    absent: <?php echo json_encode($trendAbsent); ?>
};
const statusData = [<?php echo $statusCounts['Present']; ?>, <?php echo $statusCounts['Late']; ?>, <?php echo $statusCounts['Absent']; ?>];
const labData = {
    labels: <?php echo json_encode(array_column($labUsage, 'lab_name')); ?>,
    counts: <?php echo json_encode(array_map('intval', array_column($labUsage, 'cnt'))); ?>
};
let charts = [];

function buildCharts() {
    const c = themeChartDefaults();
    charts.forEach(ch => ch.destroy());
    charts = [
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: trendData.labels,
                datasets: [
                    { label: 'Present', data: trendData.present, borderColor: c.green, backgroundColor: c.greenFill, tension:.35, fill:true },
                    { label: 'Late', data: trendData.late, borderColor: c.amber, backgroundColor: c.amberFill, tension:.35, fill:true },
                    { label: 'Absent', data: trendData.absent, borderColor: c.red, backgroundColor: c.redFill, tension:.35, fill:true }
                ]
            },
            options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom', labels:{usePointStyle:true, pointStyle:'circle', boxWidth:8, boxHeight:8, padding:16}}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
        }),
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Present','Late','Absent'],
                datasets: [{ data: statusData, backgroundColor: [c.green, c.amber, c.red], borderColor: c.surface }]
            },
            options: { responsive:true, maintainAspectRatio:false, cutout:'68%', plugins:{legend:{position:'bottom', labels:{usePointStyle:true, pointStyle:'circle', boxWidth:8, boxHeight:8, padding:16}}} }
        }),
        new Chart(document.getElementById('labChart'), {
            type: 'bar',
            data: { labels: labData.labels, datasets: [{ label:'Scans Today', data: labData.counts, backgroundColor: c.accent, borderRadius:6 }] },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, layout:{padding:{left:6}}, plugins:{legend:{display:false}},
                scales:{x:{beginAtZero:true, ticks:{precision:0}}, y:{ticks:{callback(v){ const l = this.getLabelForValue(v).replace(/ ?Laborator(y|ies)/, ''); return l.length > 18 ? l.slice(0, 17) + '\u2026' : l; }}}} }
        })
    ];
}
// Nothing recorded yet: say so instead of drawing an empty ring.
if (statusData.every(n => n === 0)) {
    const box = document.getElementById('statusChart').closest('.chart-box');
    box.hidden = true; box.nextElementSibling.hidden = false;
}
buildCharts();
window.addEventListener('themechange', buildCharts);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
