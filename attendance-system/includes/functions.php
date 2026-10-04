<?php
/**
 * ------------------------------------------------------------
 * functions.php
 * Reusable helper functions: sanitization, redirects, flash
 * messages, notifications, QR token generation, formatting.
 * ------------------------------------------------------------
 */

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
 * Irregular students may request classes from other year levels or
 * sections, but still only within their own institution + program,
 * and every request still requires teacher/admin approval.
 *
 * Fields a class leaves empty (legacy rows) are treated as "no
 * restriction" so older data keeps working.
 * Returns [bool eligible, string reason].
 * ------------------------------------------------------------ */
function student_eligible_for_class(array $student, array $class) {
    if (!empty($class['institution_id']) && (int) $class['institution_id'] !== (int) $student['institution_id']) {
        return [false, 'This subject belongs to a different institution.'];
    }
    if (!empty($class['program_id']) && (int) $class['program_id'] !== (int) $student['program_id']) {
        return [false, 'This subject is not offered for your course/program.'];
    }
    if (($student['student_type'] ?? 'regular') === 'irregular') {
        return [true, ''];
    }
    if (!empty($class['year_level']) && (int) $class['year_level'] !== (int) $student['year_level']) {
        return [false, 'This subject is for a different year level. Only irregular students may request it.'];
    }
    if (!empty($class['section']) && !empty($student['section'])
        && strcasecmp(trim($class['section']), trim($student['section'])) !== 0) {
        return [false, 'This subject is for a different section. Only irregular students may request it.'];
    }
    return [true, ''];
}

/** Maximum valid year level for a program (2-year diplomas => 2, degrees => 4). */
function program_max_year($durationYears) {
    return max(1, (int) ceil((float) $durationYears));
}
