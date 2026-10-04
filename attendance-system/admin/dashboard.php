<?php
/**
 * admin/dashboard.php
 * Overview stats + attendance analytics charts for the administrator.
 */
require_once __DIR__ . '/../includes/auth.php';
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
$todayTotal = $pdo->query('SELECT COUNT(*) FROM attendance_records WHERE DATE(time_in) = CURDATE()')->fetchColumn();
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
           SUM(status = "Late") AS late
    FROM attendance_records
    WHERE time_in >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(time_in)
    ORDER BY d ASC
');
$trend = $trendStmt->fetchAll();
$trendLabels = []; $trendPresent = []; $trendLate = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $trendLabels[] = date('M d', strtotime($d));
    $found = null;
    foreach ($trend as $row) if ($row['d'] === $d) $found = $row;
    $trendPresent[] = $found ? (int) $found['present'] : 0;
    $trendLate[] = $found ? (int) $found['late'] : 0;
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
    LEFT JOIN attendance_records ar ON ar.session_id = s.session_id
    GROUP BY l.lab_id ORDER BY l.lab_name
')->fetchAll();

// ---- Recent activity ----
$recent = $pdo->query('
    SELECT ar.time_in, ar.status, st.full_name, sub.subject_name, lab.lab_name
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

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Attendance Graph</h3></div>
        <div class="card-body"><canvas id="trendChart" height="120"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Recent Attendance Activity</h3></div>
        <div class="card-body" style="padding:10px 22px">
            <?php if (empty($recent)): ?>
                <p class="text-muted text-center" style="padding:20px 0">No attendance records yet.</p>
            <?php else: foreach ($recent as $r): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--slate-100);font-size:13px">
                    <span class="dot <?php echo $r['status'] === 'Late' ? 'dot-red' : 'dot-green'; ?>" style="width:8px;height:8px;border-radius:50%;flex-shrink:0"></span>
                    <span style="flex:1"><?php echo e($r['full_name']); ?> checked in at <?php echo e($r['lab_name']); ?> · <?php echo date('h:i A', strtotime($r['time_in'])); ?></span>
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
        <div class="card-body"><canvas id="statusChart" height="140"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Laboratory Usage Today</h3></div>
        <div class="card-body"><canvas id="labChart" height="140"></canvas></div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const trendCtx = document.getElementById('trendChart');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($trendLabels); ?>,
        datasets: [
            { label: 'Present', data: <?php echo json_encode($trendPresent); ?>, borderColor:'#16a34a', backgroundColor:'rgba(22,163,74,.1)', tension:.35, fill:true },
            { label: 'Late', data: <?php echo json_encode($trendLate); ?>, borderColor:'#d97706', backgroundColor:'rgba(217,119,6,.1)', tension:.35, fill:true }
        ]
    },
    options: { responsive:true, plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
});

const statusCtx = document.getElementById('statusChart');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Present','Late','Absent'],
        datasets: [{ data: [<?php echo $statusCounts['Present']; ?>, <?php echo $statusCounts['Late']; ?>, <?php echo $statusCounts['Absent']; ?>], backgroundColor:['#16a34a','#d97706','#dc2626'] }]
    },
    options: { responsive:true, plugins:{legend:{position:'bottom'}} }
});

const labCtx = document.getElementById('labChart');
new Chart(labCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($labUsage, 'lab_name')); ?>,
        datasets: [{ label:'Scans Today', data: <?php echo json_encode(array_map('intval', array_column($labUsage, 'cnt'))); ?>, backgroundColor:'#4f46e5', borderRadius:6 }]
    },
    options: { indexAxis:'y', responsive:true, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true, ticks:{precision:0}}} }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
