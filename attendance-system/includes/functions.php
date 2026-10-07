<?php
/**
 * ------------------------------------------------------------
 * functions.php
 * Reusable helper functions: sanitization, redirects, flash
 * messages, notifications, QR token generation, formatting.
 * ------------------------------------------------------------
 */

/**
 * Error text that is safe to show a user. PDOExceptions carry SQL, table/column and
 * host details, so they are written to the server log (Vercel runtime logs) and the
 * user sees a generic message. Our own validation Exceptions are shown as written.
 */
function safe_error_message(Throwable $e): string {
    if ($e instanceof PDOException) {
        error_log(get_class($e) . ' [' . $e->getCode() . '] ' . $e->getMessage());
        return $e->getCode() === '23505'
            ? 'That record already exists.'
            : 'Something went wrong while saving. Please try again.';
    }
    return $e->getMessage();
}

/* ------------------------------------------------------------
 * CSRF protection
 * One token per login session. Every POST (or other unsafe method) to a page that calls
 * require_login()/require_role() must carry it: ajaxPost() in assets/js/app.js adds it
 * automatically, and every <form method="POST"> must contain <?php echo csrf_field(); ?>.
 * ------------------------------------------------------------ */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_request_is_valid(): bool {
    $sent = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $sent !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}

/** Stops the request (403) when an unsafe-method request has no valid CSRF token. */
function csrf_guard(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || csrf_request_is_valid()) return;
    http_response_code(403);
    $msg = 'Your session expired or the request was not allowed. Please reload the page and try again.';
    $wantsJson = strpos(basename($_SERVER['PHP_SELF'] ?? ''), 'ajax_') === 0
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if ($wantsJson) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => $msg]));
    }
    die('<div style="font-family:sans-serif;padding:60px;text-align:center"><h1>Request blocked</h1><p>' . $msg . '</p><a href="javascript:history.back()">Go back</a></div>');
}

/**
 * JSON for an inline handler such as onclick='fn(<?php echo js_attr_json($data); ?>)'. Plain json_encode()
 * leaves ' and " alone, so a name like O'Brien ends the attribute early and breaks the button (or worse).
 */
function js_attr_json($data): string {
    return json_encode($data, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

/** A real calendar date as 'YYYY-MM-DD', or '' (so a hand-edited ?date_from=abc can't reach the database and crash the page). */
function valid_ymd($value): string {
    $v = trim((string) $value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return '';
    return $v;
}

/** Escape output to prevent XSS */
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Trim + sanitize a raw input string */
function clean($value) {
    return trim(strip_tags($value ?? ''));
}

/** Redirect helper */
function redirect($path) {
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

/** Store a one-time flash message in session */
function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Retrieve + clear the flash message (used by header.php to show a toast) */
function get_flash() {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/** Generate a cryptographically random token for QR codes / sessions */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/** Insert a notification for a given user_id */
function create_notification(PDO $pdo, $userId, $title, $message) {
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $title, $message]);
}

/** Count unread notifications for the currently logged-in user */
function unread_notification_count(PDO $pdo, $userId) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** Log an action to activity_logs (simple audit trail) */
function log_activity(PDO $pdo, $userId, $action) {
    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action) VALUES (?, ?)');
    $stmt->execute([$userId, $action]);
}

/** Format a MySQL datetime nicely for display */
function format_datetime($datetime) {
    if (!$datetime) return '—';
    return date('M d, Y h:i A', strtotime($datetime));
}

/** Time shown for an attendance record: the check-in time, or "no check-in" for an Absent record
  * (its time_in is just the end of the meeting, see includes/absences.php). */
function format_record_time(array $r) {
    if (($r['status'] ?? '') === 'Absent') return format_date($r['time_in']) . ' · no check-in';
    if (!empty($r['marked_by_user_id'])) return format_date($r['time_in']) . ' · marked by teacher';   // a teacher override, not a scan
    return format_datetime($r['time_in']);
}

function format_date($date) {
    if (!$date) return '—';
    return date('M d, Y', strtotime($date));
}

function format_time($time) {
    if (!$time) return '—';
    return date('h:i A', strtotime($time));
}

/** Simple pagination helper: returns [offset, limit, page] */
function paginate($totalRows, $perPage = 10) {
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    return ['offset' => $offset, 'limit' => $perPage, 'page' => $page, 'totalPages' => $totalPages];
}

/** Render pagination links (keeps existing query string filters) */
function render_pagination($page, $totalPages) {
    if ($totalPages <= 1) return;
    $params = $_GET;
    echo '<nav><ul class="pagination">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $params['page'] = $i;
        $qs = http_build_query($params);
        $active = $i === $page ? 'active' : '';
        echo "<li class=\"page-item $active\"><a class=\"page-link\" href=\"?$qs\">$i</a></li>";
    }
    echo '</ul></nav>';
}

/* ------------------------------------------------------------
 * SYSTEM SETTINGS (Admin > Settings)
 * Configurable values stored in the `settings` table instead of
 * being hardcoded, per-request cached in a static array.
 * ------------------------------------------------------------ */

/** Get a setting value (string) with a fallback default if unset. */
function get_setting(PDO $pdo, $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        foreach ($rows as $row) $cache[$row['setting_key']] = $row['setting_value'];
    }
    return $cache[$key] ?? $default;
}

/** Get a setting as an integer (common case: meters, seconds, etc). */
function get_setting_int(PDO $pdo, $key, $default = 0) {
    return (int) get_setting($pdo, $key, $default);
}

/** Create or update a setting value. */
function set_setting(PDO $pdo, $key, $value) {
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()');
    $stmt->execute([$key, $value]);
}

/* ------------------------------------------------------------
 * ATTENDANCE SESSION AUTO-EXPIRATION
 * There's no cron job in a typical XAMPP setup, so sessions are
 * "lazily" expired: any page/endpoint that cares about active
 * sessions calls this first, which closes out anything whose
 * session_end has already passed.
 * ------------------------------------------------------------ */
function auto_expire_sessions(PDO $pdo) {
    $pdo->exec("
        UPDATE attendance_sessions
        SET is_active = 0, deactivated_at = NOW()
        WHERE is_active = 1
          AND session_end IS NOT NULL
          AND NOW() > session_end
    ");
}

/* ------------------------------------------------------------
 * RECURRING CLASS SCHEDULES
 * A class assignment (teacher_subjects row) says who teaches what,
 * to which class, and where. WHEN it meets (class_schedules +
 * schedule_exceptions) is read ONLY through includes/schedule.php.
 * day_of_week is ISO: 1 = Monday ... 7 = Sunday, same as date('N').
 * ------------------------------------------------------------ */
const SCHEDULE_DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/** Normalise 'H:i' / 'H:i:s' to 'H:i:s' (so times compare correctly as strings); null if invalid. */
function normalize_time($time) {
    $time = trim((string) $time);
    foreach (['H:i:s', 'H:i'] as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $time);
        if ($dt && $dt->format($fmt) === $time) return $dt->format('H:i:s');
    }
    return null;
}

/* ------------------------------------------------------------
 * STUDENT ELIGIBILITY FOR A CLASS (server-side, never client-trusted)
 *
 * Regular students may only request classes matching their own
 *   institution + program + year level + section.
 * Irregular students may request ANY active class: other year levels,
 * sections, programs and institutions (e.g. an MCNP student who also
 * takes ISAP subjects). Every request still requires teacher/admin approval.
 *
 * Fields a class leaves empty (legacy rows) are treated as "no
 * restriction" so older data keeps working.
 * Returns [bool eligible, string reason].
 * ------------------------------------------------------------ */
/**
 * Reduce a section label to its comparable part. Students store "3A"; classes store
 * "BSIT-3A" (older rows "BSIT 3A"). Compare only what follows the last "-" or space.
 */
function section_key($section): string {
    return strtoupper(preg_replace('/^.*[- ]/', '', trim((string) $section)));
}

/**
 * THE section format, used for students AND classes: year level + letter(s), e.g. "3A".
 * The program is never part of the section; it is stored/displayed separately.
 */
const SECTION_LETTERS_PATTERN = '/^[A-Z][A-Z0-9]{0,4}$/';

function is_valid_section_letters($letters): bool {
    return (bool) preg_match(SECTION_LETTERS_PATTERN, strtoupper(trim((string) $letters)));
}

function build_section($yearLevel, $letters): string {
    return (int) $yearLevel . strtoupper(trim((string) $letters));
}

/**
 * Which part of a class's cohort a REGULAR student does not match:
 * '' (match, or irregular student), 'institution', 'program', 'year_level' or 'section'.
 * $student needs institution_id, program_id, year_level, section, student_type;
 * $class needs institution_id, program_id, year_level, section.
 */
function class_cohort_mismatch(array $student, array $class): string {
    if (($student['student_type'] ?? 'regular') === 'irregular') return '';
    if (!empty($class['institution_id']) && (int) $class['institution_id'] !== (int) $student['institution_id']) return 'institution';
    if (!empty($class['program_id']) && (int) $class['program_id'] !== (int) $student['program_id']) return 'program';
    if (!empty($class['year_level']) && (int) $class['year_level'] !== (int) $student['year_level']) return 'year_level';
    if (!empty($class['section']) && !empty($student['section'])
        && section_key($class['section']) !== section_key($student['section'])) return 'section';
    return '';
}

/** Student-facing check used when a student requests enrollment. */
function student_eligible_for_class(array $student, array $class) {
    $messages = [
        'institution' => 'This subject belongs to a different institution. Only irregular students may request it.',
        'program' => 'This subject is not offered for your course/program. Only irregular students may request it.',
        'year_level' => 'This subject is for a different year level. Only irregular students may request it.',
        'section' => 'This subject is for a different section. Only irregular students may request it.',
    ];
    $mismatch = class_cohort_mismatch($student, $class);
    return $mismatch === '' ? [true, ''] : [false, $messages[$mismatch]];
}

/**
 * Teacher-facing check for direct enrollment (one-by-one and CSV import): a regular student may
 * only be enrolled in a class of their own institution, program, year level and section.
 * Returns '' when allowed, otherwise the reason to show.
 */
function enrollment_block_reason(array $student, array $class): string {
    $messages = [
        'institution' => 'Student is from a different institution than this class. Only irregular students can be enrolled.',
        'program' => 'Student is in a different course/program than this class. Only irregular students can be enrolled.',
        'year_level' => 'Student is in a different year level than this class. Only irregular students can be enrolled.',
        'section' => 'Student is in a different section than this class. Only irregular students can be enrolled.',
    ];
    $mismatch = class_cohort_mismatch($student, $class);
    return $mismatch === '' ? '' : $messages[$mismatch];
}

/** Maximum valid year level for a program (2-year diplomas => 2, degrees => 4). */
function program_max_year($durationYears) {
    return max(1, (int) ceil((float) $durationYears));
}
