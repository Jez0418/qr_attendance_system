<?php
/**
 * includes/export.php
 * Shared PDF / Excel export for attendance tables (no external library,
 * same approach as admin/export_pdf.php and admin/export_excel.php):
 *   'pdf'   -> print-ready page; the user saves it with the browser's
 *              Print dialog (Ctrl+P -> Save as PDF)
 *   'excel' -> HTML table sent as an .xls download that Excel opens
 *
 * $meta    = ['Label' => 'value', ...] shown above the table (PDF only)
 * $headers = column titles; $rows = list of rows of plain-text cells
 * (escaped here). Cells reading Present / Late / Absent are colour-coded.
 */

/** The requested export format from ?export=, or null. */
function requested_export_format(): ?string {
    $f = $_GET['export'] ?? '';
    return in_array($f, ['pdf', 'excel'], true) ? $f : null;
}

/** Query string for an export link: current filters minus page, plus export=$format. */
function export_query(string $format): string {
    $q = $_GET;
    unset($q['page'], $q['export']);
    $q['export'] = $format;
    return http_build_query($q);
}

/** Send the export and stop. */
function send_attendance_export(string $format, string $title, array $meta, array $headers, array $rows, string $filenameBase): void {
    $meta['Generated'] = date('M d, Y h:i A');
    $meta['Records'] = (string) count($rows);
    $cell = function ($v, $tag = 'td') {
        $v = (string) $v;
        $cls = in_array($v, ['Present', 'Late', 'Absent'], true) ? ' class="status-' . $v . '"' : '';
        return "<$tag$cls>" . e($v) . "</$tag>";
    };

    if ($format === 'excel') {
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $filenameBase) . '_' . date('Ymd_His') . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF<html><head><meta charset=\"UTF-8\"></head><body><table border=\"1\"><tr>";
        foreach ($headers as $h) echo $cell($h, 'th');
        echo '</tr>';
        foreach ($rows as $r) { echo '<tr>'; foreach ($r as $v) echo $cell($v); echo '</tr>'; }
        echo '</table></body></html>';
        exit;
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> - <?php echo e(APP_NAME); ?></title>
<style>
    body{font-family:Arial,sans-serif;color:#111;padding:30px;font-size:13px}
    h1{font-size:20px;margin:0 0 4px}
    .subtitle{color:#555;margin:0 0 20px;font-size:13px}
    .meta{display:flex;flex-wrap:wrap;gap:8px 30px;margin-bottom:18px;font-size:12.5px;color:#333}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{border:1px solid #ccc;padding:7px 9px;text-align:left;font-size:12px}
    th{background:#f1f1f1}
    .status-Present{color:#166534;font-weight:bold}
    .status-Late{color:#92400e;font-weight:bold}
    .status-Absent{color:#991b1b;font-weight:bold}
    .print-btn{margin-bottom:20px;padding:10px 18px;background:#4338ca;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:14px}
    @media print{ .print-btn{display:none} body{padding:0} }
</style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <h1><?php echo e(APP_NAME); ?></h1>
    <p class="subtitle"><?php echo e($title); ?></p>
    <div class="meta">
        <?php foreach ($meta as $k => $v): ?><div><strong><?php echo e($k); ?>:</strong> <?php echo e($v); ?></div><?php endforeach; ?>
    </div>
    <table>
        <thead><tr><th>#</th><?php foreach ($headers as $h) echo $cell($h, 'th'); ?></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="<?php echo count($headers) + 1; ?>" style="text-align:center">No records found.</td></tr>
        <?php else: foreach ($rows as $i => $r): ?>
            <tr><td><?php echo $i + 1; ?></td><?php foreach ($r as $v) echo $cell($v); ?></tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</body>
</html>
    <?php
    exit;
}
