<?php
/**
 * admin/reports.php
 * Report builder: choose filters, preview summary + chart,
 * then export the same filtered data set as PDF (print view) or Excel.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Reports & Analytics';

$labId     = id_param($_GET['lab_id'] ?? '');       // hand-edited junk in the URL is ignored instead of crashing the query
$subjectId = id_param($_GET['subject_id'] ?? '');
$dateFrom  = valid_ymd($_GET['date_from'] ?? date('Y-m-01')) ?: date('Y-m-01');
$dateTo    = valid_ymd($_GET['date_to'] ?? date('Y-m-d')) ?: date('Y-m-d');

$where = ['DATE(ar.time_in) BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];
if ($labId !== '')     { $where[] = 'lab.lab_id = ?'; $params[] = $labId; }
if ($subjectId !== '') { $where[] = 'sub.subject_id = ?'; $params[] = $subjectId; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$baseQuery = "
    FROM attendance_records ar
    JOIN students st ON st.student_id = ar.student_id
    JOIN attendance_sessions ses ON ses.session_id = ar.session_id
    JOIN teacher_subjects ts ON ts.teacher_subject_id = ses.teacher_subject_id
    JOIN teachers tch ON tch.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    $whereSql
";

// Summary counts
$summary = $pdo->prepare("SELECT ar.status, COUNT(*) c $baseQuery GROUP BY ar.status");
$summary->execute($params);
$counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'Excused' => 0];
foreach ($summary->fetchAll() as $row) $counts[$row['status']] = (int) $row['c'];
$totalScans = array_sum($counts);

// Per-subject breakdown
$bySubject = $pdo->prepare("
    SELECT sub.subject_name,
        SUM(ar.status='Present') AS present, SUM(ar.status='Late') AS late, SUM(ar.status='Absent') AS absent, SUM(ar.status='Excused') AS excused
    $baseQuery GROUP BY sub.subject_id ORDER BY sub.subject_name
");
$bySubject->execute($params);
$subjectRows = $bySubject->fetchAll();

// Detail rows (for the table + export)
$detailStmt = $pdo->prepare("
    SELECT ar.time_in, ar.status, ar.marked_by_user_id, st.full_name, st.student_number, sub.subject_name, lab.lab_name, tch.full_name AS teacher_name
    $baseQuery ORDER BY ar.time_in DESC LIMIT 300
");
$detailStmt->execute($params);
$detailRows = $detailStmt->fetchAll();

$labs = $pdo->query('SELECT lab_id, lab_name FROM laboratories ORDER BY lab_name')->fetchAll();
$subjects = $pdo->query('SELECT subject_id, subject_name FROM subjects ORDER BY subject_name')->fetchAll();

$exportQs = http_build_query(['lab_id' => $labId, 'subject_id' => $subjectId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>Report Filters</h3></div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <select name="lab_id" class="form-control" style="max-width:200px">
                <option value="">All Laboratories</option>
                <?php foreach ($labs as $l): ?><option value="<?php echo $l['lab_id']; ?>" <?php echo $labId == $l['lab_id'] ? 'selected' : ''; ?>><?php echo e($l['lab_name']); ?></option><?php endforeach; ?>
            </select>
            <select name="subject_id" class="form-control" style="max-width:200px">
                <option value="">All Subjects</option>
                <?php foreach ($subjects as $s): ?><option value="<?php echo $s['subject_id']; ?>" <?php echo $subjectId == $s['subject_id'] ? 'selected' : ''; ?>><?php echo e($s['subject_name']); ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control" style="max-width:170px" value="<?php echo e($dateFrom); ?>">
            <input type="date" name="date_to" class="form-control" style="max-width:170px" value="<?php echo e($dateTo); ?>">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Generate</button>
            <div class="toolbar-spacer"></div>
            <a class="btn btn-outline btn-sm" target="_blank" href="export_pdf.php?<?php echo $exportQs; ?>"><i class="fa-solid fa-file-pdf"></i> Export PDF</a>
            <a class="btn btn-outline btn-sm" href="export_excel.php?<?php echo $exportQs; ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
        </form>
    </div>
</div>

<div class="stat-grid" style="margin-top:20px">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-list-check"></i></div><div><div class="stat-value"><?php echo $totalScans; ?></div><div class="stat-label">Total Records</div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-check"></i></div><div><div class="stat-value"><?php echo $counts['Present']; ?></div><div class="stat-label">Present</div></div></div>
    <div class="stat-card"><div class="stat-icon amber"><i class="fa-solid fa-clock"></i></div><div><div class="stat-value"><?php echo $counts['Late']; ?></div><div class="stat-label">Late</div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-xmark"></i></div><div><div class="stat-value"><?php echo $counts['Absent']; ?></div><div class="stat-label">Absent</div></div></div>
    <div class="stat-card"><div class="stat-icon excused"><i class="fa-solid fa-file-medical"></i></div><div><div class="stat-value"><?php echo $counts['Excused']; ?></div><div class="stat-label">Excused</div></div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Attendance by Subject</h3></div>
        <div class="card-body"><div class="chart-box"><canvas id="subjectChart"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Status Share</h3></div>
        <div class="card-body">
            <?php
            $shareTotal = (int) array_sum($counts);
            $shareMeta = ['Present' => ['green', 'fa-check'], 'Late' => ['amber', 'fa-clock'], 'Absent' => ['red', 'fa-xmark'], 'Excused' => ['excused', 'fa-file-medical']];
            ?>
            <div class="status-dist" id="shareDist">
                <div class="status-ring">
                    <div class="chart-box"><canvas id="shareChart" role="img" aria-label="Status share"></canvas></div>
                    <div class="status-center"><strong><?php echo number_format($shareTotal); ?></strong><span>records</span></div>
                </div>
                <ul class="status-list">
                    <?php $i = 0; foreach ($shareMeta as $name => [$tone, $icon]):
                        $n = (int) $counts[$name];
                        $pct = $shareTotal > 0 ? round($n / $shareTotal * 100, 1) : 0; ?>
                        <li class="status-row tone-<?php echo $tone; ?>" style="--i:<?php echo $i++; ?>">
                            <span class="status-icon"><i class="fa-solid <?php echo $icon; ?>"></i></span>
                            <div class="status-info">
                                <div class="status-top"><span class="status-name"><?php echo $name; ?></span><span class="status-num"><strong><?php echo number_format($n); ?></strong><em><?php echo $pct + 0; ?>%</em></span></div>
                                <div class="status-bar"><i style="--w:<?php echo $pct; ?>%"></i></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <p class="text-muted text-center chart-empty" hidden>No attendance records yet.</p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>Detailed Records (showing up to 300 most recent)</h3></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Student No.</th><th>Subject</th><th>Lab</th><th>Teacher</th><th>Time In</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (empty($detailRows)): ?>
                <tr><td colspan="7" class="text-center text-muted">No records for the selected filters.</td></tr>
            <?php else: foreach ($detailRows as $r): ?>
                <tr>
                    <td><?php echo e($r['full_name']); ?></td>
                    <td><?php echo e($r['student_number']); ?></td>
                    <td><?php echo e($r['subject_name']); ?></td>
                    <td><?php echo e($r['lab_name']); ?></td>
                    <td><?php echo e($r['teacher_name']); ?></td>
                    <td><?php echo format_record_time($r); ?></td>
                    <td><span class="badge badge-<?php echo strtolower($r['status']); ?>"><?php echo $r['status']; ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const subjectData = {
    labels: <?php echo json_encode(array_column($subjectRows, 'subject_name')); ?>,
    present: <?php echo json_encode(array_map('intval', array_column($subjectRows,'present'))); ?>,
    late: <?php echo json_encode(array_map('intval', array_column($subjectRows,'late'))); ?>,
    absent: <?php echo json_encode(array_map('intval', array_column($subjectRows,'absent'))); ?>,
    excused: <?php echo json_encode(array_map('intval', array_column($subjectRows,'excused'))); ?>
};
const shareData = [<?php echo $counts['Present']; ?>, <?php echo $counts['Late']; ?>, <?php echo $counts['Absent']; ?>, <?php echo $counts['Excused']; ?>];
let charts = [];

// Colours come from the active theme (assets/js/theme.js); the charts are rebuilt when it changes.
function buildCharts() {
    const c = themeChartDefaults();
    charts.forEach(ch => ch.destroy());
    charts = [
        new Chart(document.getElementById('subjectChart'), {
            type: 'bar',
            data: {
                labels: subjectData.labels,
                datasets: [
                    { label:'Present', data: subjectData.present, backgroundColor: c.green },
                    { label:'Late', data: subjectData.late, backgroundColor: c.amber },
                    { label:'Absent', data: subjectData.absent, backgroundColor: c.red },
                    { label:'Excused', data: subjectData.excused, backgroundColor: c.excused }
                ]
            },
            options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom', labels:{usePointStyle:true, pointStyle:'circle', boxWidth:8, boxHeight:8, padding:16}}}, scales:{x:{stacked:true}, y:{stacked:true, beginAtZero:true, ticks:{precision:0}}} }
        }),
        new Chart(document.getElementById('shareChart'), {
            type: 'doughnut',
            data: { labels:['Present','Late','Absent','Excused'], datasets:[{ data: shareData, backgroundColor:[c.green, c.amber, c.red, c.excused], borderColor: c.surface, borderWidth: 2, hoverOffset: 6 }] },
            options: { responsive:true, maintainAspectRatio:false, cutout:'74%', spacing:3, borderRadius:6, plugins:{legend:{display:false}} }
        })
    ];
}
if (shareData.every(n => n === 0)) {
    const box = document.getElementById('shareDist');
    box.hidden = true; box.nextElementSibling.hidden = false;
}
buildCharts();
window.addEventListener('themechange', buildCharts);
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
