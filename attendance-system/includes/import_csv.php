<?php
/**
 * ------------------------------------------------------------
 * import_csv.php
 * Shared helpers for the CSV batch imports (admin student accounts,
 * teacher class enrollment): CSRF token, safe CSV upload parsing,
 * template download.
 *
 * Limits are deliberately small: Vercel functions are short-lived and
 * every new student account needs a bcrypt hash (~70-150 ms each).
 * ------------------------------------------------------------
 */

const IMPORT_MAX_BYTES = 1048576;   // 1 MB

/** The import forms use the app-wide CSRF token (see csrf_token() in functions.php). */
function import_csrf_token(): string { return csrf_token(); }

/** Throws unless the POSTed token matches the session token (require_login() already enforces it too). */
function import_csrf_check(): void {
    if (!csrf_request_is_valid()) {
        throw new Exception('Your session expired. Please reload the page and try again.');
    }
}

/** Normalise a header cell: "Student Number " -> "student_number". */
function import_norm_header($h): string {
    $h = strtolower(trim((string) $h));
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);          // UTF-8 BOM
    return preg_replace('/[^a-z0-9]+/', '_', trim($h, " \t\n\r\0\x0B_"));
}

/**
 * Read an uploaded CSV ($_FILES entry). Returns [headers[], rows[]] where each
 * row is an associative array keyed by normalised header, plus '_line' (1-based
 * file line number). Blank lines are skipped. Throws a user-readable Exception.
 *
 * @param bool $hasHeader false = no header row; rows are keyed by $columns
 */
function import_read_csv(array $file, int $maxRows, array $columns = [], bool $hasHeader = true): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Please choose a CSV file to upload.');
    }
    if (($file['size'] ?? 0) > IMPORT_MAX_BYTES) {
        throw new Exception('The file is too large (max 1 MB).');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        throw new Exception('Only .csv files are accepted. In Excel use File > Save As > "CSV UTF-8".');
    }
    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false || $raw === '') throw new Exception('The file is empty.');
    if (!mb_check_encoding($raw, 'UTF-8')) {
        throw new Exception('The file is not UTF-8. In Excel use File > Save As > "CSV UTF-8".');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    // Excel in some regions saves with ";" instead of ","
    $firstLine = strtok($raw, "\n");
    $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

    $fh = fopen('php://memory', 'w+');
    fwrite($fh, $raw);
    rewind($fh);

    $headers = $columns;
    $line = 0;
    if ($hasHeader) {
        $first = fgetcsv($fh, 0, $delim);
        $line = 1;
        if (!$first) throw new Exception('The file has no header row.');
        $headers = array_map('import_norm_header', $first);
    }

    $rows = [];
    while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
        $line++;
        if (count($cells) === 1 && trim((string) $cells[0]) === '') continue;      // blank line
        if (count(array_filter($cells, fn($c) => trim((string) $c) !== '')) === 0) continue;
        if (count($rows) >= $maxRows) {
            fclose($fh);
            throw new Exception("Too many rows. Import at most $maxRows rows at a time and split the file.");
        }
        $row = ['_line' => $line];
        foreach ($headers as $i => $h) {
            if ($h === '') continue;
            // clean(): trim + strip tags; also stops spreadsheet-formula text from being rendered as HTML
            $row[$h] = clean($cells[$i] ?? '');
        }
        $rows[] = $row;
    }
    fclose($fh);
    if (!$rows) throw new Exception('No data rows found in the file.');
    return [$headers, $rows];
}

/** Stream a CSV template download and exit. */
function import_send_template(string $filename, array $headers, array $sampleRows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";                       // BOM so Excel opens it as UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($sampleRows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}
